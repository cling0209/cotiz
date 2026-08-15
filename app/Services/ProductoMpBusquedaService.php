<?php

namespace App\Services;

use App\Jobs\ProcessProductoMpBusquedaJob;
use App\Models\Maeprod;
use App\Models\MaeprodFraseBusqueda;
use App\Models\OportunidadEncontrada;
use App\Models\ProductoMpBusquedaCorrida;
use App\Models\ProductoMpEncontrado;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProductoMpBusquedaService
{
    public const ESTADO_RUNNING = 'running';

    public const ESTADO_COMPLETED = 'completed';

    public const ESTADO_CANCELLED = 'cancelled';

    public const ESTADO_ERROR = 'error';

    public const TAMANO_PAGINA = 50;

    private const ITEMS_POR_JOB = 8;

    /** @var list<object{prod_item: string, prod_nombre: string, frase: string, frase_norm: string, aplica_todas: bool, regiones: list<int>}>|null */
    private ?array $frasesCache = null;

    public function __construct(
        protected CompraAgilApiService $api,
        protected CompraAgilPayloadMapper $mapper,
        protected MaeprodBusquedaSimilitudService $busquedaSimilitud,
    ) {}

    public function habilitada(): bool
    {
        return (bool) config('cotiz.mercadopublico.analisis_admin_habilitado', false);
    }

    public function corridaEnCurso(): ?ProductoMpBusquedaCorrida
    {
        return ProductoMpBusquedaCorrida::query()
            ->where('estado', self::ESTADO_RUNNING)
            ->latest('id')
            ->first();
    }

    public function ultimaCorrida(): ?ProductoMpBusquedaCorrida
    {
        return ProductoMpBusquedaCorrida::query()->latest('id')->first();
    }

    public function iniciar(string $usuario = 'sistema', mixed $fechaBusqueda = null): ProductoMpBusquedaCorrida
    {
        if (! $this->habilitada()) {
            throw new RuntimeException('La búsqueda de productos MP no está habilitada en este sitio.');
        }

        if (! $this->api->isConfigured()) {
            throw new RuntimeException('API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.');
        }

        $existente = $this->corridaEnCurso();
        if ($existente !== null) {
            return $existente;
        }

        if (MaeprodFraseBusqueda::query()->doesntExist()) {
            throw new RuntimeException('No hay palabras clave producto. Agréguelas en el mantenedor.');
        }

        $regiones = CompraAgilRegionScope::regionesIncluidas();
        if ($regiones === []) {
            throw new RuntimeException('No hay regiones configuradas (MERCADOPUBLICO_REGIONES).');
        }

        $dia = $this->normalizarFecha($fechaBusqueda);
        $pasos = [];
        foreach ($regiones as $region) {
            $region = (int) $region;
            $pasos[] = $this->pasoInicial($region);
        }

        $corrida = ProductoMpBusquedaCorrida::query()->create([
            'usuario' => mb_substr(trim($usuario) ?: 'sistema', 0, 50),
            'fecha_busqueda' => $dia,
            'inicio' => now(),
            'estado' => self::ESTADO_RUNNING,
            'total_pasos' => count($pasos),
            'pasos_procesados' => 0,
            'pasos_fallidos' => 0,
            'matches_encontrados' => 0,
            'cas_revisadas' => 0,
            'plan_json' => $pasos,
            'errores_json' => [],
            'mensaje' => 'Búsqueda de productos MP encolada.',
        ]);

        ProcessProductoMpBusquedaJob::dispatch($corrida->id);

        return $corrida;
    }

    public function cancelar(string $usuario = 'sistema'): ?ProductoMpBusquedaCorrida
    {
        $corrida = $this->corridaEnCurso();
        if ($corrida === null) {
            return $this->ultimaCorrida();
        }

        $corrida->fill([
            'estado' => self::ESTADO_CANCELLED,
            'fin' => now(),
            'mensaje' => 'Búsqueda cancelada por '.$usuario.'.',
        ])->save();

        return $corrida;
    }

    /**
     * @return array<string, mixed>
     */
    public function estado(?ProductoMpBusquedaCorrida $corrida = null): array
    {
        $corrida ??= $this->corridaEnCurso() ?? $this->ultimaCorrida();
        if ($corrida === null) {
            return [
                'hay_corrida' => false,
                'estado' => null,
                'mensaje' => 'Aún no hay corridas de productos MP.',
                'matches_encontrados' => ProductoMpEncontrado::query()->count(),
            ];
        }

        $total = max(1, (int) $corrida->total_pasos);
        $hechos = (int) $corrida->pasos_procesados;
        $pct = (int) min(100, round(($hechos / $total) * 100));

        return [
            'hay_corrida' => true,
            'id' => $corrida->id,
            'estado' => $corrida->estado,
            'running' => $corrida->estado === self::ESTADO_RUNNING,
            'mensaje' => (string) ($corrida->mensaje ?? ''),
            'usuario' => (string) $corrida->usuario,
            'fecha_busqueda' => $corrida->fecha_busqueda?->toDateString(),
            'inicio' => $corrida->inicio?->toIso8601String(),
            'fin' => $corrida->fin?->toIso8601String(),
            'total_pasos' => (int) $corrida->total_pasos,
            'pasos_procesados' => $hechos,
            'pasos_fallidos' => (int) $corrida->pasos_fallidos,
            'matches_encontrados' => (int) $corrida->matches_encontrados,
            'cas_revisadas' => (int) $corrida->cas_revisadas,
            'porcentaje' => $pct,
        ];
    }

    public function procesarPaso(ProductoMpBusquedaCorrida $corrida): bool
    {
        $corrida->refresh();
        if ($corrida->estado !== self::ESTADO_RUNNING) {
            return false;
        }

        $plan = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $indice = (int) $corrida->pasos_procesados;
        if ($indice >= count($plan)) {
            $this->completar($corrida);

            return false;
        }

        $paso = is_array($plan[$indice] ?? null) ? $plan[$indice] : [];
        $region = (int) ($paso['region'] ?? 0);
        if ($region < 1) {
            $plan[$indice]['estado'] = 'failed';
            $corrida->fill([
                'plan_json' => $plan,
                'pasos_procesados' => $indice + 1,
                'pasos_fallidos' => (int) $corrida->pasos_fallidos + 1,
                'mensaje' => 'Paso inválido (región).',
            ])->save();

            return true;
        }

        try {
            $codigos = is_array($paso['codigos_pagina'] ?? null) ? $paso['codigos_pagina'] : [];
            $listadoCargado = (bool) ($paso['listado_cargado'] ?? false);
            if (! $listadoCargado) {
                $pagina = max(1, (int) ($paso['pagina'] ?? 1));
                $listado = $this->api->listar($this->parametrosListado($region, $pagina, $corrida->fecha_busqueda));
                $items = is_array($listado['items'] ?? null) ? $listado['items'] : [];
                $paginacion = is_array($listado['paginacion'] ?? null) ? $listado['paginacion'] : [];
                $totalPaginas = max(1, (int) ($paginacion['total_paginas'] ?? 1));

                $resumenes = [];
                $codigosPagina = [];
                foreach ($items as $item) {
                    if (! is_array($item) || CompraAgilRegionScope::debeExcluirItem($item)) {
                        continue;
                    }
                    $resumen = $this->mapper->resumenListadoItem($item);
                    $codigo = strtoupper(trim((string) ($resumen['codigo'] ?? '')));
                    if ($codigo === '') {
                        continue;
                    }
                    if (! $this->estaVigente($resumen['fecha_cierre'] ?? null)) {
                        continue;
                    }
                    $codigosPagina[] = $codigo;
                    $resumenes[$codigo] = $resumen;
                }

                $plan[$indice]['codigos_pagina'] = $codigosPagina;
                $plan[$indice]['resumenes'] = $resumenes;
                $plan[$indice]['total_paginas'] = $totalPaginas;
                $plan[$indice]['offset'] = 0;
                $plan[$indice]['listado_cargado'] = true;
                $plan[$indice]['estado'] = 'running';
                $corrida->fill([
                    'plan_json' => $plan,
                    'mensaje' => sprintf(
                        'Región %s · página %d/%d · %d CA a revisar.',
                        CompraAgilRegionScope::nombreRegion($region),
                        $pagina,
                        $totalPaginas,
                        count($codigosPagina),
                    ),
                ])->save();

                return true;
            }

            $offset = max(0, (int) ($paso['offset'] ?? 0));
            $lote = array_slice($codigos, $offset, self::ITEMS_POR_JOB);
            $resumenes = is_array($paso['resumenes'] ?? null) ? $paso['resumenes'] : [];
            $matchesNuevos = 0;
            $revisadas = 0;

            foreach ($lote as $codigo) {
                $codigo = strtoupper(trim((string) $codigo));
                if ($codigo === '') {
                    continue;
                }
                $resumen = is_array($resumenes[$codigo] ?? null) ? $resumenes[$codigo] : ['codigo' => $codigo];
                $matchesNuevos += $this->procesarCodigo($codigo, $resumen, (string) $corrida->fecha_busqueda?->toDateString());
                $revisadas++;
            }

            $nuevoOffset = $offset + count($lote);
            $plan[$indice]['offset'] = $nuevoOffset;
            $corrida->matches_encontrados = (int) $corrida->matches_encontrados + $matchesNuevos;
            $corrida->cas_revisadas = (int) $corrida->cas_revisadas + $revisadas;

            if ($nuevoOffset < count($codigos)) {
                $plan[$indice]['estado'] = 'running';
                $corrida->fill([
                    'plan_json' => $plan,
                    'mensaje' => sprintf(
                        'Región %s · página %d · %d/%d CA.',
                        CompraAgilRegionScope::nombreRegion($region),
                        (int) ($paso['pagina'] ?? 1),
                        $nuevoOffset,
                        count($codigos),
                    ),
                ])->save();

                return true;
            }

            $pagina = max(1, (int) ($paso['pagina'] ?? 1));
            $totalPaginas = max(1, (int) ($paso['total_paginas'] ?? 1));
            if ($pagina < $totalPaginas && $pagina < 30) {
                $plan[$indice] = array_merge($this->pasoInicial($region), [
                    'pagina' => $pagina + 1,
                    'total_paginas' => $totalPaginas,
                ]);
                $corrida->fill([
                    'plan_json' => $plan,
                    'mensaje' => sprintf(
                        'Región %s · pasando a página %d/%d.',
                        CompraAgilRegionScope::nombreRegion($region),
                        $pagina + 1,
                        $totalPaginas,
                    ),
                ])->save();

                return true;
            }

            $plan[$indice]['estado'] = 'ok';
            $plan[$indice]['codigos_pagina'] = [];
            $plan[$indice]['resumenes'] = [];
            $corrida->fill([
                'plan_json' => $plan,
                'pasos_procesados' => $indice + 1,
                'mensaje' => sprintf(
                    'Región %s lista. Matches acumulados: %d.',
                    CompraAgilRegionScope::nombreRegion($region),
                    (int) $corrida->matches_encontrados,
                ),
            ])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('ProductoMpBusquedaService: fallo de paso', [
                'corrida_id' => $corrida->id,
                'region' => $region,
                'message' => $e->getMessage(),
            ]);
            $errores = is_array($corrida->errores_json) ? $corrida->errores_json : [];
            $errores[] = [
                'region' => $region,
                'mensaje' => mb_substr($e->getMessage(), 0, 400),
                'at' => now()->toIso8601String(),
            ];
            $plan[$indice]['estado'] = 'failed';
            $corrida->fill([
                'plan_json' => $plan,
                'errores_json' => $errores,
                'pasos_procesados' => $indice + 1,
                'pasos_fallidos' => (int) $corrida->pasos_fallidos + 1,
                'mensaje' => 'Error en región '.CompraAgilRegionScope::nombreRegion($region).': '.$e->getMessage(),
            ])->save();

            return true;
        }
    }

    public function registrarInterrupcionWorker(ProductoMpBusquedaCorrida $corrida, ?string $detalle = null): void
    {
        if ($corrida->estado !== self::ESTADO_RUNNING) {
            return;
        }

        $txt = trim((string) $detalle);
        $corrida->fill([
            'mensaje' => 'Worker interrumpido; se reanudará. '.($txt !== '' ? mb_substr($txt, 0, 160) : ''),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $resumen
     */
    public function procesarCodigo(string $codigo, array $resumen, string $fechaBusqueda): int
    {
        $codigo = strtoupper(trim($codigo));
        $origen = 'mp';
        $lineas = $this->lineasParaCodigo($codigo, $origen);
        ProductoMpEncontrado::query()
            ->where('codigo', $codigo)
            ->whereDate('fecha_busqueda', $fechaBusqueda)
            ->delete();

        if ($lineas === []) {
            return 0;
        }

        $guardados = 0;
        foreach ($lineas as $linea) {
            $texto = $this->textoLineaParaMatch($linea);
            if ($texto === '') {
                continue;
            }
            $descripcion = trim((string) ($linea['descripcion'] ?? ''));
            if ($descripcion === '') {
                $descripcion = $texto;
            }
            $codigoMp = trim((string) ($linea['id_agile'] ?? $linea['codigo_producto'] ?? ''));
            if ($codigoMp === '') {
                $codigoMp = substr(md5($texto), 0, 32);
            }

            $regionCa = isset($resumen['region']) ? (int) $resumen['region'] : null;
            $match = $this->mejorFraseParaDescripcion($texto, $regionCa);
            if ($match === null) {
                continue;
            }

            ProductoMpEncontrado::query()->updateOrCreate(
                [
                    'codigo' => $codigo,
                    'codigo_producto_mp' => mb_substr($codigoMp, 0, 80),
                    'prod_item' => $match['prod_item'] !== '' ? $match['prod_item'] : '',
                    'frase_norm' => $match['frase_norm'],
                ],
                [
                    'nombre_ca' => mb_substr((string) ($resumen['nombre'] ?? ''), 0, 500),
                    'organismo' => mb_substr((string) ($resumen['organismo'] ?? ''), 0, 200),
                    'region' => isset($resumen['region']) ? (int) $resumen['region'] : null,
                    'nombre_region' => mb_substr((string) ($resumen['nombre_region'] ?? ''), 0, 80),
                    'descripcion_mp' => mb_substr($descripcion, 0, 500),
                    'prod_nombre' => mb_substr($match['prod_nombre'], 0, 200),
                    'frase' => mb_substr($match['frase'], 0, 200),
                    'origen_detalle' => $origen,
                    'fecha_publicacion' => $this->parseFechaNullable($resumen['fecha_publicacion'] ?? null),
                    'fecha_cierre' => $this->parseFechaNullable($resumen['fecha_cierre'] ?? null),
                    'fecha_busqueda' => $fechaBusqueda,
                ],
            );
            $guardados++;
        }

        return $guardados;
    }

    /**
     * Texto de la línea MP para el match: nombre + descripción. Nunca el código de producto.
     *
     * @param  array<string, mixed>  $linea
     */
    public function textoLineaParaMatch(array $linea): string
    {
        $partes = [
            trim((string) ($linea['descripcion'] ?? '')),
            trim((string) ($linea['categoria'] ?? '')),
            trim((string) ($linea['nombre'] ?? '')),
        ];

        $vistos = [];
        $out = [];
        foreach ($partes as $parte) {
            if ($parte === '') {
                continue;
            }
            $clave = mb_strtolower($parte, 'UTF-8');
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $out[] = $parte;
        }

        return trim(implode(' ', $out));
    }

    /**
     * Match público para tests: todas las palabras de la frase en el texto (no en el código).
     *
     * @return array{prod_item: string, prod_nombre: string, frase: string, frase_norm: string}|null
     */
    public function mejorFraseParaDescripcion(string $descripcion, ?int $region = null): ?array
    {
        $descNorm = $this->busquedaSimilitud->normalizarTexto($descripcion);
        if ($descNorm === '') {
            return null;
        }

        $palabrasDesc = preg_split('/\s+/u', $descNorm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($palabrasDesc === []) {
            return null;
        }
        $setDesc = array_fill_keys($palabrasDesc, true);

        $mejor = null;
        $mejorLen = -1;
        foreach ($this->frasesBusqueda() as $frase) {
            if ($region !== null && ! $frase->aplica_todas && ! in_array($region, $frase->regiones, true)) {
                continue;
            }
            $norm = (string) $frase->frase_norm;
            if ($norm === '' || ! $this->frasePalabrasEnDescripcion($norm, $setDesc)) {
                continue;
            }
            $len = mb_strlen($norm, 'UTF-8');
            if ($len > $mejorLen) {
                $mejorLen = $len;
                $mejor = [
                    'prod_item' => (string) $frase->prod_item,
                    'prod_nombre' => (string) ($frase->prod_nombre ?? ''),
                    'frase' => (string) $frase->frase,
                    'frase_norm' => $norm,
                ];
            }
        }

        return $mejor;
    }

    /**
     * @param  array<string, true>  $setDesc
     */
    private function frasePalabrasEnDescripcion(string $fraseNorm, array $setDesc): bool
    {
        $palabras = preg_split('/\s+/u', $fraseNorm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($palabras === []) {
            return false;
        }

        foreach ($palabras as $palabra) {
            if (! isset($setDesc[$palabra])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<object{prod_item: string, prod_nombre: string, frase: string, frase_norm: string, aplica_todas: bool, regiones: list<int>}>
     */
    private function frasesBusqueda(): array
    {
        if ($this->frasesCache !== null) {
            return $this->frasesCache;
        }

        $rows = MaeprodFraseBusqueda::query()
            ->with('regiones')
            ->where('frase_norm', '!=', '')
            ->orderByRaw('LENGTH(frase_norm) DESC')
            ->orderBy('id')
            ->get();

        $prodItems = $rows->pluck('prod_item')->filter()->unique()->values()->all();
        $nombres = $prodItems === []
            ? collect()
            : Maeprod::query()
                ->whereIn('prod_item', $prodItems)
                ->pluck('prod_nombre', 'prod_item');

        $out = [];
        foreach ($rows as $row) {
            $prodItem = (string) ($row->prod_item ?? '');
            $out[] = (object) [
                'prod_item' => $prodItem,
                'prod_nombre' => (string) ($nombres[$prodItem] ?? ''),
                'frase' => (string) $row->frase,
                'frase_norm' => (string) $row->frase_norm,
                'aplica_todas' => $row->aplicaATodasLasRegiones(),
                'regiones' => $row->codigosRegion(),
            ];
        }

        return $this->frasesCache = $out;
    }

    /**
     * @return list<array{id_agile: string, descripcion: string}>
     */
    private function lineasParaCodigo(string $codigo, ?string &$origen = null): array
    {
        $preview = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->whereNotNull('vinculo_preview_json')
            ->orderByDesc('fecha_busqueda')
            ->orderByDesc('id')
            ->first();

        if ($preview !== null && $preview->tieneVinculoPreview()) {
            $lineas = $preview->vinculo_preview_json['lineas'] ?? [];
            $origen = 'preview';

            return is_array($lineas) ? array_values($lineas) : [];
        }

        $payload = $this->api->detalle($codigo);
        $mapeado = $this->mapper->fromDetalle($payload);
        $origen = 'mp';
        $lineas = $mapeado['lineas'] ?? [];

        return is_array($lineas) ? array_values($lineas) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function parametrosListado(int $region, int $pagina, mixed $fechaBusqueda): array
    {
        $params = [
            'estado' => 'publicada',
            'numero_pagina' => max(1, $pagina),
            'ordenar_por' => 'FechaPublicacion',
            'region' => max(1, $region),
            'tamano_pagina' => self::TAMANO_PAGINA,
        ];

        $dia = $this->normalizarFecha($fechaBusqueda);
        try {
            $inicio = Carbon::parse($dia, config('app.timezone'))->startOfDay();
            $fin = Carbon::parse($dia, config('app.timezone'))->endOfDay();
            $params['cambio_desde'] = $inicio->toIso8601String();
            $params['cambio_hasta'] = $fin->toIso8601String();
        } catch (Throwable) {
            // Sin ventana: listado completo del estado publicada.
        }

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    private function pasoInicial(int $region): array
    {
        return [
            'region' => $region,
            'region_nombre' => CompraAgilRegionScope::nombreRegion($region),
            'pagina' => 1,
            'offset' => 0,
            'codigos_pagina' => [],
            'resumenes' => [],
            'total_paginas' => 1,
            'listado_cargado' => false,
            'estado' => 'pending',
        ];
    }

    private function completar(ProductoMpBusquedaCorrida $corrida): void
    {
        $corrida->fill([
            'estado' => self::ESTADO_COMPLETED,
            'fin' => now(),
            'mensaje' => sprintf(
                'Búsqueda terminada. %d CA revisadas, %d productos MP encontrados.',
                (int) $corrida->cas_revisadas,
                (int) $corrida->matches_encontrados,
            ),
        ])->save();
    }

    private function estaVigente(mixed $fechaCierre): bool
    {
        $fecha = $this->parseFechaNullable($fechaCierre);

        return $fecha === null || $fecha->isAfter(now());
    }

    private function parseFechaNullable(mixed $valor): ?Carbon
    {
        $texto = trim((string) ($valor ?? ''));
        if ($texto === '') {
            return null;
        }

        try {
            return Carbon::parse($texto)->timezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    public function normalizarFecha(mixed $fecha = null): string
    {
        $texto = trim((string) ($fecha ?? ''));
        if ($texto === '') {
            return now()->timezone(config('app.timezone'))->toDateString();
        }

        try {
            return Carbon::parse($texto)->timezone(config('app.timezone'))->toDateString();
        } catch (Throwable) {
            return now()->timezone(config('app.timezone'))->toDateString();
        }
    }
}
