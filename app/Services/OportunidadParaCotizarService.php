<?php

namespace App\Services;

use App\Models\Nota;
use App\Models\OportunidadEncontrada;
use App\Models\OportunidadPalabraClave;
use App\Models\OportunidadTomada;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class OportunidadParaCotizarService
{
    private const CACHE_SEGUNDOS = 60;

    private const REGION_TAMANO_PAGINA = 50;

    private const REGION_MAX_PAGINAS = 20;

    public function __construct(
        protected CompraAgilApiService $api,
        protected CompraAgilOportunidadService $oportunidad,
        protected CompraAgilPayloadMapper $mapper,
        protected OportunidadEncontradaRelayService $encontradaRelay,
    ) {}

    public function apiConfigurada(): bool
    {
        return $this->api->isConfigured();
    }

    /**
     * @return list<string>
     */
    public function palabrasClave(): array
    {
        return OportunidadPalabraClave::query()
            ->orderBy('orden')
            ->orderBy('id')
            ->pluck('frase')
            ->map(fn ($f) => trim((string) $f))
            ->filter(fn ($f) => $f !== '')
            ->values()
            ->all();
    }

    public function fechaBusquedaHoy(): string
    {
        return now()->timezone(config('app.timezone'))->toDateString();
    }

    /**
     * Normaliza una fecha de búsqueda (Y-m-d). Si viene vacía, usa hoy.
     */
    public function normalizarFechaBusqueda(mixed $fecha = null): string
    {
        $texto = trim((string) ($fecha ?? ''));
        if ($texto === '') {
            return $this->fechaBusquedaHoy();
        }

        try {
            return Carbon::parse($texto)
                ->timezone(config('app.timezone'))
                ->toDateString();
        } catch (\Throwable) {
            return $this->fechaBusquedaHoy();
        }
    }

    /**
     * Oportunidades ya grabadas hoy (para mostrar al abrir la pantalla).
     *
     * @return list<array<string, mixed>>
     */
    public function listarGuardadasHoy(): array
    {
        return $this->listarGuardadasEn($this->fechaBusquedaHoy());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listarGuardadasEn(mixed $fechaBusqueda = null): array
    {
        $dia = $this->normalizarFechaBusqueda($fechaBusqueda);

        $codigosTomados = OportunidadTomada::query()
            ->pluck('codigo')
            ->merge(
                Nota::query()
                    ->whereRaw("trim(coalesce(encargado, '')) <> ''")
                    ->pluck('encargado'),
            )
            ->map(fn ($codigo) => strtoupper(trim((string) $codigo)))
            ->filter(fn ($codigo) => $codigo !== '')
            ->unique()
            ->values()
            ->all();

        $items = OportunidadEncontrada::query()
            ->whereDate('fecha_busqueda', $dia)
            ->where(function ($query) {
                $query->whereNull('fecha_cierre')
                    ->orWhere('fecha_cierre', '>', now());
            })
            ->when(
                $codigosTomados !== [],
                fn ($query) => $query->whereNotIn('codigo', $codigosTomados),
            )
            ->orderByDesc('monto_presupuesto_clp')
            ->orderByRaw('cantidad_productos ASC NULLS LAST')
            ->orderBy('codigo')
            ->get()
            ->map(fn (OportunidadEncontrada $row) => $row->toResumen())
            ->all();

        usort($items, [$this, 'compararOportunidades']);

        return $items;
    }

    /**
     * @return list<string>
     */
    public function codigosGuardadosHoy(): array
    {
        return $this->codigosGuardadosEn($this->fechaBusquedaHoy());
    }

    /**
     * @return list<string>
     */
    public function codigosGuardadosEn(mixed $fechaBusqueda = null): array
    {
        $dia = $this->normalizarFechaBusqueda($fechaBusqueda);

        return OportunidadEncontrada::query()
            ->whereDate('fecha_busqueda', $dia)
            ->pluck('codigo')
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->filter(fn ($c) => $c !== '')
            ->values()
            ->all();
    }

    /**
     * Persiste oportunidades encontradas (upsert por código + día).
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function guardarEncontradas(array $items, ?int $userId = null, mixed $fechaBusqueda = null): int
    {
        $dia = $this->normalizarFechaBusqueda($fechaBusqueda);
        $guardadas = 0;
        $paraSync = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
            if ($codigo === '') {
                continue;
            }

            if (! $this->estaVigente($item['fecha_cierre'] ?? null) || $this->estaTomada($codigo)) {
                continue;
            }

            $palabras = array_values(array_unique(array_filter(array_map(
                static fn ($p) => trim((string) $p),
                is_array($item['palabras_coinciden'] ?? null) ? $item['palabras_coinciden'] : [],
            ))));

            $existente = OportunidadEncontrada::query()
                ->where('codigo', $codigo)
                ->whereDate('fecha_busqueda', $dia)
                ->first();

            if ($existente !== null) {
                $prev = is_array($existente->palabras_coinciden) ? $existente->palabras_coinciden : [];
                $merged = array_values(array_unique(array_merge($prev, $palabras)));
                $attrs = $this->atributosDesdeResumen($item, $dia, $userId, $merged);
                // No borrar cantidad ya obtenida del detalle si el resumen no la trae.
                if (($attrs['cantidad_productos'] ?? null) === null && $existente->cantidad_productos !== null) {
                    unset($attrs['cantidad_productos']);
                }
                $existente->fill($attrs);
                $existente->save();
                $guardadas++;
                $paraSync[] = $existente->toResumen() + ['fecha_busqueda' => $dia];

                continue;
            }

            $creada = OportunidadEncontrada::query()->create(
                $this->atributosDesdeResumen($item, $dia, $userId, $palabras),
            );
            $guardadas++;
            $paraSync[] = $creada->toResumen() + ['fecha_busqueda' => $dia];
        }

        if ($paraSync !== []) {
            $this->encontradaRelay->replicarItems($paraSync);
        }

        return $guardadas;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $palabras
     * @return array<string, mixed>
     */
    private function atributosDesdeResumen(array $item, string $dia, ?int $userId, array $palabras): array
    {
        $region = isset($item['region']) ? (int) $item['region'] : null;

        return [
            'codigo' => strtoupper(trim((string) ($item['codigo'] ?? ''))),
            'nombre' => mb_substr(trim((string) ($item['nombre'] ?? '')), 0, 500) ?: null,
            'organismo' => mb_substr(trim((string) ($item['organismo'] ?? '')), 0, 500) ?: null,
            'rut_organismo' => mb_substr(trim((string) ($item['rut_organismo'] ?? '')), 0, 20) ?: null,
            'region' => $region,
            'nombre_region' => mb_substr(trim((string) ($item['nombre_region'] ?? '')), 0, 100) ?: null,
            'comuna' => mb_substr(trim((string) ($item['comuna'] ?? '')), 0, 120) ?: null,
            'monto_presupuesto_clp' => isset($item['monto_presupuesto_clp'])
                ? (int) $item['monto_presupuesto_clp']
                : null,
            'moneda' => mb_substr(trim((string) ($item['moneda'] ?? 'CLP')), 0, 10) ?: 'CLP',
            'fecha_publicacion' => $this->parseFechaNullable($item['fecha_publicacion'] ?? null),
            'fecha_cierre' => $this->parseFechaNullable($item['fecha_cierre'] ?? null),
            'estado_codigo' => mb_substr(trim((string) ($item['estado_codigo'] ?? '')), 0, 40) ?: null,
            'estado_glosa' => mb_substr(trim((string) ($item['estado_glosa'] ?? '')), 0, 120) ?: null,
            'palabras_coinciden' => $palabras,
            'cantidad_productos' => isset($item['cantidad_productos'])
                ? (int) $item['cantidad_productos']
                : null,
            'fecha_busqueda' => $dia,
            'indice_region_config' => (int) ($item['indice_region_config']
                ?? CompraAgilRegionScope::indiceEnConfig($region)),
            'found_by' => $userId,
        ];
    }

    private function parseFechaNullable(mixed $valor): ?Carbon
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor);
        } catch (\Throwable) {
            return null;
        }
    }

    private function estaVigente(mixed $fechaCierre): bool
    {
        $fecha = $this->parseFechaNullable($fechaCierre);

        return $fecha === null || $fecha->isAfter(now());
    }

    private function estaTomada(string $codigo): bool
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return false;
        }

        return OportunidadTomada::query()->where('codigo', $codigo)->exists()
            || Nota::query()
                ->whereRaw('upper(trim(encargado)) = ?', [$codigo])
                ->exists();
    }

    private function agregarPalabraAGuardada(string $codigo, string $frase, mixed $fechaBusqueda = null): void
    {
        $frase = trim($frase);
        if ($codigo === '' || $frase === '') {
            return;
        }

        $dia = $this->normalizarFechaBusqueda($fechaBusqueda);

        $row = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->whereDate('fecha_busqueda', $dia)
            ->first();

        if ($row === null) {
            return;
        }

        if (! $this->fraseApareceEnTexto($frase, $row->toResumen())) {
            return;
        }

        $prev = is_array($row->palabras_coinciden) ? $row->palabras_coinciden : [];
        if (in_array($frase, $prev, true)) {
            return;
        }

        $prev[] = $frase;
        $row->palabras_coinciden = array_values($prev);
        $row->save();

        $this->encontradaRelay->replicarItems([
            $row->toResumen() + ['fecha_busqueda' => $dia],
        ]);
    }

    private function completarCantidadProductosGuardada(string $codigo, mixed $fechaBusqueda = null): ?int
    {
        $dia = $this->normalizarFechaBusqueda($fechaBusqueda);

        $row = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->whereDate('fecha_busqueda', $dia)
            ->first();

        if ($row === null) {
            return null;
        }

        if ($row->cantidad_productos !== null) {
            return (int) $row->cantidad_productos;
        }

        $cantidad = $this->obtenerCantidadProductosReal($codigo);
        if ($cantidad !== null) {
            $row->cantidad_productos = $cantidad;
            $row->save();
        }

        return $cantidad;
    }

    private function obtenerCantidadProductosReal(string $codigo): ?int
    {
        try {
            // detalle() usa cache: como máximo una consulta real por código durante su TTL.
            $detalle = $this->api->detalle($codigo);

            return $this->mapper->cantidadProductosDetalle($detalle);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * La frase (o todas sus palabras significativas) debe aparecer en nombre/organismo.
     *
     * @param  array<string, mixed>  $resumen
     * @param  array<string, mixed>|null  $crudo  ítem crudo API (opcional)
     */
    public function fraseApareceEnTexto(string $frase, array $resumen, ?array $crudo = null): bool
    {
        $frase = trim($frase);
        if ($frase === '') {
            return false;
        }

        $partes = [
            (string) ($resumen['nombre'] ?? ''),
            (string) ($resumen['organismo'] ?? ''),
            (string) ($resumen['comuna'] ?? ''),
            (string) ($resumen['nombre_region'] ?? ''),
        ];

        if (is_array($crudo)) {
            $partes[] = (string) ($crudo['nombre'] ?? '');
            $institucion = is_array($crudo['institucion'] ?? null) ? $crudo['institucion'] : [];
            $partes[] = (string) ($institucion['organismo_comprador'] ?? '');
            $partes[] = (string) ($institucion['comuna'] ?? $institucion['nombre_comuna'] ?? '');
        }

        $haystack = $this->normalizarTextoBusqueda(implode(' ', $partes));
        if ($haystack === '') {
            return false;
        }

        $needle = $this->normalizarTextoBusqueda($frase);
        if ($needle === '') {
            return false;
        }

        // Frase completa (ej. "servicio de aseo").
        if (str_contains($haystack, $needle)) {
            return true;
        }

        // Frase multi-palabra: todas las palabras ≥3 chars deben aparecer.
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', $needle) ?: [],
            static fn (string $t) => mb_strlen($t) >= 3,
        ));

        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! str_contains($haystack, $token)) {
                return false;
            }
        }

        return true;
    }

    private function normalizarTextoBusqueda(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ]);
        $texto = preg_replace('/[^a-z0-9\s]+/u', ' ', $texto) ?? $texto;
        $texto = preg_replace('/\s+/u', ' ', $texto) ?? $texto;

        return trim($texto);
    }

    /**
     * Plan de consulta: un paso por región (orden MERCADOPUBLICO_REGIONES).
     * Las frases se cruzan en local al leer cada cotización (no van en la query a MP).
     *
     * @return array{
     *   palabras: list<string>,
     *   pasos: list<array{frase: string, region: int, region_nombre: string}>,
     *   total_pasos: int,
     *   fecha: string,
     *   api_configurada: bool,
     *   error: ?string
     * }
     */
    public function planBusqueda(): array
    {
        $palabras = $this->palabrasClave();
        $regiones = CompraAgilRegionScope::regionesIncluidas();
        $fecha = now()->timezone(config('app.timezone'))->toDateString();

        if ($palabras === []) {
            return [
                'palabras' => [],
                'pasos' => [],
                'total_pasos' => 0,
                'fecha' => $fecha,
                'api_configurada' => $this->api->isConfigured(),
                'error' => 'No hay palabras clave configuradas.',
            ];
        }

        if (! $this->api->isConfigured()) {
            return [
                'palabras' => $palabras,
                'pasos' => [],
                'total_pasos' => 0,
                'fecha' => $fecha,
                'api_configurada' => false,
                'error' => 'API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.',
            ];
        }

        $pasos = [];
        foreach ($regiones as $region) {
            $pasos[] = [
                'frase' => '(todas)',
                'region' => (int) $region,
                'region_nombre' => CompraAgilRegionScope::nombreRegion((int) $region),
            ];
        }

        return [
            'palabras' => $palabras,
            'pasos' => $pasos,
            'total_pasos' => count($pasos),
            'fecha' => $fecha,
            'api_configurada' => true,
            'error' => null,
        ];
    }

    /**
     * Parámetros de listado por región (sin q): el match de frases se hace en local.
     *
     * @return array<string, mixed>
     */
    public function parametrosConsultaRegion(int $region, int $numeroPagina = 1): array
    {
        return [
            'estado' => 'publicada',
            'numero_pagina' => max(1, $numeroPagina),
            'ordenar_por' => 'FechaPublicacion',
            'region' => max(1, $region),
            'tamano_pagina' => self::REGION_TAMANO_PAGINA,
        ];
    }

    /**
     * Compatibilidad con endpoint /paso y debug: consulta con q=frase.
     *
     * @return array<string, mixed>
     */
    public function parametrosConsultaPaso(string $frase, int $region): array
    {
        $frase = trim($frase);
        $params = $this->parametrosConsultaRegion($region, 1);
        if ($frase !== '' && $frase !== '(todas)') {
            $params['q'] = $frase;
        }

        return $params;
    }

    /**
     * Ejecuta un paso de región: lista publicadas (paginado), filtra por día
     * y hace match local contra todas las palabras clave.
     *
     * @param  list<string>  $codigosExcluidos
     * @return array{
     *   items: list<array<string, mixed>>,
     *   consulta: array<string, mixed>,
     *   guardadas: int
     * }
     */
    public function ejecutarPaso(
        string $frase,
        int $region,
        array $codigosExcluidos = [],
        ?int $userId = null,
        mixed $fechaBusqueda = null,
    ): array {
        $frase = trim($frase);
        // Plan nuevo: un paso = región completa. Frase vacía/"(todas)" → match local de todas.
        if ($frase === '' || $frase === '(todas)') {
            return $this->ejecutarPasoRegion($region, $codigosExcluidos, $userId, $fechaBusqueda);
        }

        return $this->ejecutarPasoConFrase($frase, $region, $codigosExcluidos, $userId, $fechaBusqueda);
    }

    /**
     * @param  list<string>  $codigosExcluidos
     * @return array{
     *   items: list<array<string, mixed>>,
     *   consulta: array<string, mixed>,
     *   guardadas: int
     * }
     */
    public function ejecutarPasoRegion(
        int $region,
        array $codigosExcluidos = [],
        ?int $userId = null,
        mixed $fechaBusqueda = null,
    ): array {
        $dia = $this->normalizarFechaBusqueda($fechaBusqueda);
        $palabras = $this->palabrasClave();
        if ($region < 1 || $palabras === []) {
            return [
                'items' => [],
                'consulta' => $this->metaConsultaPaso('(todas)', $region, 0, 0, $dia),
                'guardadas' => 0,
            ];
        }

        if (! $this->api->isConfigured()) {
            throw new RuntimeException(
                'API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.'
            );
        }

        $yaVistos = [];
        foreach (array_merge($codigosExcluidos, $this->codigosGuardadosEn($dia)) as $codigo) {
            $norm = strtoupper(trim((string) $codigo));
            if ($norm !== '') {
                $yaVistos[$norm] = true;
            }
        }

        $crudos = [];
        $items = [];
        for ($pagina = 1; $pagina <= self::REGION_MAX_PAGINAS; $pagina++) {
            $params = $this->parametrosConsultaRegion($region, $pagina);
            $cacheKey = 'oportunidad_para_cotizar:'.$dia.':region:'.$region.':p'.$pagina;
            $lote = Cache::remember($cacheKey, self::CACHE_SEGUNDOS, function () use ($params) {
                $resultado = $this->api->listar($params);

                return is_array($resultado['items'] ?? null) ? $resultado['items'] : [];
            });

            if ($lote === []) {
                break;
            }

            $crudos = array_merge($crudos, $lote);
            $todasAnterioresAlDia = true;

            foreach ($lote as $item) {
                if (! is_array($item) || CompraAgilRegionScope::debeExcluirItem($item)) {
                    continue;
                }

                $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
                if ($codigo === '') {
                    continue;
                }

                $resumen = $this->oportunidad->enriquecerResumen(
                    $this->mapper->resumenListadoItem($item),
                );

                $fechaPub = trim((string) ($resumen['fecha_publicacion'] ?? ''));
                $fechaPubDia = null;
                if ($fechaPub !== '') {
                    try {
                        $fechaPubDia = Carbon::parse($fechaPub)
                            ->timezone(config('app.timezone'))
                            ->toDateString();
                    } catch (\Throwable) {
                        $fechaPubDia = null;
                    }
                }

                if ($fechaPubDia !== null && $fechaPubDia >= $dia) {
                    $todasAnterioresAlDia = false;
                }

                if (! $this->esPublicadaEnFecha($resumen['fecha_publicacion'] ?? null, $dia)) {
                    continue;
                }

                $coinciden = $this->frasesQueCoinciden($palabras, $resumen, $item);
                if ($coinciden === []) {
                    continue;
                }

                if (! $this->estaVigente($resumen['fecha_cierre'] ?? null) || $this->estaTomada($codigo)) {
                    continue;
                }

                if (isset($yaVistos[$codigo])) {
                    foreach ($coinciden as $fraseOk) {
                        $this->agregarPalabraAGuardada($codigo, $fraseOk, $dia);
                    }
                    $this->completarCantidadProductosGuardada($codigo, $dia);

                    continue;
                }

                $regionItem = isset($resumen['region']) ? (int) $resumen['region'] : null;
                $resumen['palabras_coinciden'] = $coinciden;
                $resumen['cantidad_productos'] = $this->obtenerCantidadProductosReal($codigo);
                $resumen['indice_region_config'] = CompraAgilRegionScope::indiceEnConfig($regionItem);
                $resumen['distancia_santiago'] = CompraAgilRegionScope::distanciaASantiago($regionItem);
                $resumen['guardada'] = true;
                $items[] = $resumen;
                $yaVistos[$codigo] = true;
            }

            if ($todasAnterioresAlDia || count($lote) < self::REGION_TAMANO_PAGINA) {
                break;
            }
        }

        $guardadas = $this->guardarEncontradas($items, $userId, $dia);

        return [
            'items' => $items,
            'consulta' => $this->metaConsultaPaso('(todas)', $region, count($crudos), count($items), $dia),
            'guardadas' => $guardadas,
        ];
    }

    /**
     * @param  list<string>  $codigosExcluidos
     * @return array{
     *   items: list<array<string, mixed>>,
     *   consulta: array<string, mixed>,
     *   guardadas: int
     * }
     */
    private function ejecutarPasoConFrase(
        string $frase,
        int $region,
        array $codigosExcluidos = [],
        ?int $userId = null,
        mixed $fechaBusqueda = null,
    ): array {
        $frase = trim($frase);
        $dia = $this->normalizarFechaBusqueda($fechaBusqueda);
        if ($frase === '' || $region < 1) {
            return [
                'items' => [],
                'consulta' => $this->metaConsultaPaso($frase, $region, 0, 0, $dia),
                'guardadas' => 0,
            ];
        }

        if (! $this->api->isConfigured()) {
            throw new RuntimeException(
                'API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.'
            );
        }

        $yaVistos = [];
        foreach (array_merge($codigosExcluidos, $this->codigosGuardadosEn($dia)) as $codigo) {
            $norm = strtoupper(trim((string) $codigo));
            if ($norm !== '') {
                $yaVistos[$norm] = true;
            }
        }

        $cacheKey = 'oportunidad_para_cotizar:'.$dia.':'.md5(mb_strtolower($frase).'|'.$region);
        $params = $this->parametrosConsultaPaso($frase, $region);

        $crudos = Cache::remember($cacheKey, self::CACHE_SEGUNDOS, function () use ($params) {
            $resultado = $this->api->listar($params);

            return $resultado['items'] ?? [];
        });

        $items = [];
        foreach ($crudos as $item) {
            if (! is_array($item) || CompraAgilRegionScope::debeExcluirItem($item)) {
                continue;
            }

            $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
            if ($codigo === '') {
                continue;
            }

            $resumen = $this->oportunidad->enriquecerResumen(
                $this->mapper->resumenListadoItem($item),
            );

            if (! $this->fraseApareceEnTexto($frase, $resumen, $item)) {
                continue;
            }

            if (! $this->esPublicadaEnFecha($resumen['fecha_publicacion'] ?? null, $dia)) {
                continue;
            }

            if (! $this->estaVigente($resumen['fecha_cierre'] ?? null) || $this->estaTomada($codigo)) {
                continue;
            }

            if (isset($yaVistos[$codigo])) {
                $this->agregarPalabraAGuardada($codigo, $frase, $dia);
                $this->completarCantidadProductosGuardada($codigo, $dia);

                continue;
            }

            $regionItem = isset($resumen['region']) ? (int) $resumen['region'] : null;
            $resumen['palabras_coinciden'] = [$frase];
            $resumen['cantidad_productos'] = $this->obtenerCantidadProductosReal($codigo);
            $resumen['indice_region_config'] = CompraAgilRegionScope::indiceEnConfig($regionItem);
            $resumen['distancia_santiago'] = CompraAgilRegionScope::distanciaASantiago($regionItem);
            $resumen['guardada'] = true;
            $items[] = $resumen;
            $yaVistos[$codigo] = true;
        }

        $guardadas = $this->guardarEncontradas($items, $userId, $dia);

        return [
            'items' => $items,
            'consulta' => $this->metaConsultaPaso($frase, $region, count($crudos), count($items), $dia),
            'guardadas' => $guardadas,
        ];
    }

    /**
     * @param  list<string>  $palabras
     * @param  array<string, mixed>  $resumen
     * @param  array<string, mixed>|null  $crudo
     * @return list<string>
     */
    public function frasesQueCoinciden(array $palabras, array $resumen, ?array $crudo = null): array
    {
        $out = [];
        foreach ($palabras as $frase) {
            $frase = trim((string) $frase);
            if ($frase === '') {
                continue;
            }
            if ($this->fraseApareceEnTexto($frase, $resumen, $crudo)) {
                $out[] = $frase;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Metadatos de depuración de la consulta MP (sin llamar a la API).
     *
     * @return array<string, mixed>
     */
    public function consultaDebugPaso(
        string $frase,
        int $region,
        ?int $totalApi = null,
        ?int $totalHoy = null,
        mixed $fechaBusqueda = null,
    ): array {
        return $this->metaConsultaPaso(
            $frase,
            $region,
            $totalApi ?? 0,
            $totalHoy ?? 0,
            $fechaBusqueda,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metaConsultaPaso(
        string $frase,
        int $region,
        int $totalApi,
        int $totalHoy,
        mixed $fechaBusqueda = null,
    ): array {
        $params = $this->parametrosConsultaPaso($frase, $region);
        ksort($params);

        $baseUrl = rtrim((string) config('cotiz.mercadopublico.base_url'), '/');
        $path = '/v2/compra-agil';
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $dia = $this->normalizarFechaBusqueda($fechaBusqueda);

        $paraJson = [
            'endpoint' => $baseUrl.$path,
            'filtro_fecha' => $dia,
            'header_ticket' => '(configurado)',
            'metodo' => 'GET',
            'parametros' => $params,
            'total_api' => $totalApi,
            'total_publicadas_hoy' => $totalHoy,
            'url_completa' => $baseUrl.$path.'?'.$query,
        ];
        ksort($paraJson);

        return array_merge($paraJson, [
            'json' => json_encode(
                $paraJson,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: '{}',
        ]);
    }

    /**
     * Compatibilidad: búsqueda completa (sincrono). Solo publicadas hoy.
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   palabras: list<string>,
     *   error: ?string,
     *   api_configurada: bool
     * }
     */
    public function listar(): array
    {
        $plan = $this->planBusqueda();

        if ($plan['error'] !== null) {
            return [
                'items' => [],
                'palabras' => $plan['palabras'],
                'error' => $plan['error'],
                'api_configurada' => $plan['api_configurada'],
            ];
        }

        try {
            $porCodigo = [];
            foreach ($plan['pasos'] as $paso) {
                $excluidos = array_keys($porCodigo);
                foreach ($this->ejecutarPaso($paso['frase'], $paso['region'], $excluidos)['items'] as $item) {
                    $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
                    if ($codigo === '' || isset($porCodigo[$codigo])) {
                        continue;
                    }
                    $porCodigo[$codigo] = $item;
                }
            }

            $items = array_values($porCodigo);
            usort($items, [$this, 'compararOportunidades']);

            return [
                'items' => $items,
                'palabras' => $plan['palabras'],
                'error' => null,
                'api_configurada' => true,
            ];
        } catch (RuntimeException $e) {
            return [
                'items' => [],
                'palabras' => $plan['palabras'],
                'error' => $e->getMessage(),
                'api_configurada' => true,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public function compararOportunidades(array $a, array $b): int
    {
        $montoA = (int) ($a['monto_presupuesto_clp'] ?? 0);
        $montoB = (int) ($b['monto_presupuesto_clp'] ?? 0);
        if ($montoA !== $montoB) {
            return $montoB <=> $montoA;
        }

        $prodA = $a['cantidad_productos'] ?? null;
        $prodB = $b['cantidad_productos'] ?? null;
        $prodA = $prodA === null || $prodA === '' ? PHP_INT_MAX : (int) $prodA;
        $prodB = $prodB === null || $prodB === '' ? PHP_INT_MAX : (int) $prodB;
        if ($prodA !== $prodB) {
            return $prodA <=> $prodB;
        }

        return strcmp(
            strtoupper(trim((string) ($a['codigo'] ?? ''))),
            strtoupper(trim((string) ($b['codigo'] ?? ''))),
        );
    }

    public function esPublicadaHoy(mixed $fecha): bool
    {
        return $this->esPublicadaEnFecha($fecha, $this->fechaBusquedaHoy());
    }

    /**
     * Verifica si la fecha de publicación corresponde al día de búsqueda indicado.
     */
    public function esPublicadaEnFecha(mixed $fecha, mixed $fechaBusqueda = null): bool
    {
        $fecha = trim((string) $fecha);
        if ($fecha === '') {
            return false;
        }

        $dia = $this->normalizarFechaBusqueda($fechaBusqueda);

        try {
            return Carbon::parse($fecha)
                ->timezone(config('app.timezone'))
                ->toDateString() === $dia;
        } catch (\Throwable) {
            return false;
        }
    }
}
