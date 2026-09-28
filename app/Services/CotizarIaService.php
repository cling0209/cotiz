<?php

namespace App\Services;

use App\Enums\VinculoOrigen;
use App\Exceptions\GeminiCuotaAgotadaException;
use App\Exceptions\GeminiRespuestaInvalidaException;
use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * «Cotizar con IA»: Gemini decide si los productos salen de la cotización MP o de sus
 * adjuntos, y vincula cada línea a maeprod. Frases y aprendizaje exacto mandan; la IA
 * solo marca equivalencias (el precio lo elige el código) y, sin equivalente, busca
 * una referencia en Mercado Libre / Sodimac.
 */
class CotizarIaService
{
    /** Por ahora solo estos usuarios ven y usan el botón. */
    public const USUARIOS_PERMITIDOS = ['admin', 'pame'];

    public const FUENTE_COTIZACION = 'cotizacion';

    public const FUENTE_ADJUNTO = 'adjunto';

    public const FUENTE_AMBOS = 'ambos';

    public const ESTADO_VINCULADO = 'vinculado';

    public const ESTADO_REFERENCIA_WEB = 'referencia_web';

    public const ESTADO_PENDIENTE = 'pendiente';

    public const ORIGEN_FRASE = 'frase_maeprod';

    public const ORIGEN_APRENDIDO = 'aprendido_exacto';

    public const ORIGEN_IA = 'ia';

    public const ORIGEN_WEB = 'web';

    /** Dominio permitido => nombre visible. Incluye subdominios (articulo.mercadolibre.cl). */
    private const SITIOS_WEB = [
        'mercadolibre.cl' => 'Mercado Libre',
        'sodimac.cl' => 'Sodimac',
    ];

    private const IVA = 1.19;

    private const MAX_LINEAS = 120;

    private const CANDIDATOS_POR_LINEA = 20;

    private const LINEAS_POR_LLAMADA = 20;

    private const CACHE_TTL_MINUTOS = 60;

    private const CACHE_WEB_SIN_CUOTA = 'cotizar_ia:web_sin_cuota';

    private const SIN_SOLICITANTE = 'Sin solicitante indicado';

    /** @var list<string> */
    private array $avisos = [];

    private bool $iaSinCuota = false;

    private const TOTAL_ETAPAS = 7;

    public const PROGRESO_EN_CURSO = 'en_curso';

    public const PROGRESO_LISTO = 'listo';

    public const PROGRESO_ERROR = 'error';

    private const PROGRESO_TTL_MINUTOS = 30;

    private ?string $progresoKey = null;

    /** @var array{paso: int, total: int, etapa: string, detalle: string, estado: string} */
    private array $progreso = ['paso' => 0, 'total' => self::TOTAL_ETAPAS, 'etapa' => '', 'detalle' => '', 'estado' => self::PROGRESO_EN_CURSO];

    public function __construct(
        protected GeminiClientService $gemini,
        protected AgileVinculoAprendizajeService $aprendizaje,
        protected MaeprodBusquedaSimilitudService $busqueda,
        protected OportunidadAdjuntoService $adjuntos,
        protected OportunidadVinculoService $oportunidadVinculo,
        protected CompraAgilApiService $compraAgilApi,
        protected CompraAgilPayloadMapper $payloadMapper,
        protected NotaDetalleService $detalleService,
        protected NotaService $notaService,
        protected CompraAgilImportService $compraAgilImport,
        protected PrisaStockService $prisa,
    ) {}

    public static function usuarioPermitido(?User $user): bool
    {
        $username = mb_strtolower(trim((string) $user?->username));

        return $username !== '' && in_array($username, self::USUARIOS_PERMITIDOS, true);
    }

    /**
     * Solo lectura: no graba nada en la nota hasta aplicar().
     *
     * @param  string|null  $codigo  código MP cuando la nota aún no lo tiene guardado (borrador)
     * @param  string|null  $progresoId  id del cliente para consultar la etapa en curso (leerProgreso)
     * @return array<string, mixed>
     */
    public function preview(Nota $nota, string $usuario, ?string $codigo = null, ?string $progresoId = null): array
    {
        $this->avisos = [];
        $this->iaSinCuota = false;
        $this->progresoKey = $progresoId !== null && preg_match('/^[A-Za-z0-9]{16,64}$/', $progresoId) === 1
            ? $this->progresoKey($usuario, $progresoId)
            : null;
        $this->gemini->observar(fn (string $mensaje) => $this->detalle($mensaje));
        $this->gemini->reiniciarUsoPago();

        try {
            return $this->ejecutarPreview($nota, $usuario, $codigo);
        } finally {
            $this->gemini->observar(null);
            if ($this->progresoKey !== null) {
                Cache::forget($this->progresoKey);
            }
        }
    }

    public function marcarPreviewEnCurso(string $usuario, string $progresoId): void
    {
        Cache::put(
            $this->progresoKey($usuario, $progresoId),
            ['paso' => 0, 'total' => self::TOTAL_ETAPAS, 'etapa' => 'Iniciando…', 'detalle' => '', 'estado' => self::PROGRESO_EN_CURSO],
            now()->addMinutes(self::PROGRESO_TTL_MINUTOS),
        );
    }

    /**
     * preview() para correr después de responder: una cotización grande supera el timeout del
     * proxy (600 s), así que el resultado o el error quedan en el progreso y el navegador los recoge.
     */
    public function previewEnSegundoPlano(Nota $nota, string $usuario, ?string $codigo, string $progresoId): void
    {
        try {
            $final = ['estado' => self::PROGRESO_LISTO, 'resultado' => $this->preview($nota, $usuario, $codigo, $progresoId)];
        } catch (RuntimeException $e) {
            $final = ['estado' => self::PROGRESO_ERROR, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);
            $final = ['estado' => self::PROGRESO_ERROR, 'error' => 'No se pudo cotizar con IA. Intente nuevamente.'];
        }

        Cache::put(
            $this->progresoKey($usuario, $progresoId),
            $final + $this->progreso,
            now()->addMinutes(self::PROGRESO_TTL_MINUTOS),
        );
    }

    /**
     * @return array{paso: int, total: int, etapa: string, detalle: string, estado: string, resultado?: array<string, mixed>, error?: string}|null
     */
    public function leerProgreso(string $usuario, string $progresoId): ?array
    {
        $valor = Cache::get($this->progresoKey($usuario, $progresoId));

        return is_array($valor) ? $valor : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function ejecutarPreview(Nota $nota, string $usuario, ?string $codigo): array
    {
        $codigo = strtoupper(trim((string) ($nota->requiereNumeroCotizacion() ? $codigo : $nota->encargado)));
        if ($codigo === '') {
            throw new RuntimeException('Ingrese el número de cotización de Mercado Público.');
        }
        if (! $this->gemini->isConfigured()) {
            throw new RuntimeException('Gemini no está configurado. Defina GEMINI_API_KEY en el servidor.');
        }

        $this->etapa(1, 'Leyendo productos de Mercado Público');
        [$lineasMp, $regionMp, $cabeceraMp] = $this->lineasCotizacion($nota, $codigo);
        $this->etapa(2, 'Descargando adjuntos de la cotización');
        $adjuntos = $this->cargarAdjuntos($codigo);

        $this->etapa(3, $adjuntos === []
            ? 'Sin adjuntos legibles; se usan los productos de Mercado Público'
            : 'IA leyendo '.count($adjuntos).' adjunto(s) para decidir los productos');
        $decision = $this->decidirFuente($lineasMp, $adjuntos);
        $items = $this->armarItems($decision, $lineasMp);
        if ($items === []) {
            throw new RuntimeException('No se encontraron productos en la cotización ni en sus adjuntos.');
        }
        if (count($items) > self::MAX_LINEAS) {
            $this->avisos[] = 'Se procesaron solo las primeras '.self::MAX_LINEAS.' líneas.';
            $items = array_slice($items, 0, self::MAX_LINEAS);
        }
        $separar = count(array_filter(array_keys($this->grupos($items)), static fn (string $s) => $s !== self::SIN_SOLICITANTE)) >= 2;
        if (! $separar) {
            foreach ($items as $i => $item) {
                $items[$i]['solicitante'] = '';
            }
        }

        $this->etapa(4, 'Vinculando '.count($items).' línea(s) con frases y aprendidos');
        $items = $this->vincular($items);
        $items = $this->revisarStockPrisa($items);
        $this->etapa(7, 'Buscando referencias en Mercado Libre / Sodimac');
        $items = $this->buscarReferenciasWeb($items);
        if ($this->gemini->llamadasPago() > 0) {
            $this->avisos[] = 'Se usó la cuenta pagada de Gemini en '.$this->gemini->llamadasPago().' llamada(s).';
        }

        $token = Str::random(32);
        Cache::put($this->cacheKey($usuario, $token), [
            'codigo' => $codigo,
            'items' => $items,
            'region' => $regionMp,
            'cabecera' => $cabeceraMp,
            'separar' => $separar,
        ], now()->addMinutes(self::CACHE_TTL_MINUTOS));

        $lineasActuales = NotaDetalle::query()->where('nronota', $nota->nronota)->count();
        $lineasActualesAgile = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->whereNotNull('prod_item_agile')
            ->where('prod_item_agile', '!=', '')
            ->count();

        return [
            'token' => $token,
            'codigo' => $codigo,
            'fuente' => $decision['fuente'],
            'fuente_motivo' => $decision['motivo'],
            'adjuntos_usados' => $decision['adjuntos_usados'],
            'adjuntos_disponibles' => array_map(static fn (array $a) => $a['nombre'], $adjuntos),
            'avisos' => array_values(array_unique($this->avisos)),
            'lineas' => array_map(fn (array $item, int $i) => $this->itemParaRespuesta($item, $i), $items, array_keys($items)),
            'resumen' => $this->resumen($items),
            'separar' => $separar,
            'grupos' => $separar
                ? array_map(
                    static fn (string $solicitante, array $indices) => ['solicitante' => $solicitante, 'indices' => $indices],
                    array_keys($this->grupos($items)),
                    array_values($this->grupos($items)),
                )
                : [],
            'lineas_actuales' => $lineasActuales,
            'lineas_actuales_agile' => $lineasActualesAgile,
        ];
    }

    /**
     * Con $separar y varios solicitantes, el primero queda en $nota y cada uno de los demás
     * en una copia (mismo código MP), con el solicitante en la observación del ejecutivo.
     *
     * @param  list<int>  $rechazados  índices cuyo vínculo/referencia el usuario descartó (quedan pendientes)
     * @return array{agregadas: int, vinculadas: int, referencias_web: int, pendientes: int, eliminadas: int, aprendidas: int, cotizaciones: list<array{nronota: int, solicitante: string, agregadas: int}>}
     */
    public function aplicar(Nota $nota, string $usuario, string $token, array $rechazados, bool $reemplazar, bool $separar = false): array
    {
        $key = $this->cacheKey($usuario, $token);
        $guardado = $this->previewGuardado($usuario, $token);
        $codigo = (string) $guardado['codigo'];

        if ($nota->requiereNumeroCotizacion()) {
            $this->notaService->modificarCabecera(
                $nota,
                $this->datosCabeceraNueva($codigo, (array) ($guardado['cabecera'] ?? [])),
                $usuario,
            );
            $nota = $nota->fresh();
        } elseif (strtoupper(trim((string) $nota->encargado)) !== $codigo) {
            throw new RuntimeException('La vista previa corresponde a otra cotización ('.$codigo.'). Vuelva a presionar «Cotizar con IA».');
        }

        $rechazados = array_fill_keys(array_map('intval', $rechazados), true);
        $items = $guardado['items'];

        $codigosVinculados = [];
        foreach ($items as $i => $item) {
            if (! isset($rechazados[$i]) && $item['estado'] === self::ESTADO_VINCULADO) {
                $codigosVinculados[$item['producto']['prod_item']] = true;
            }
        }
        $maeprods = $codigosVinculados === []
            ? collect()
            : Maeprod::query()->whereIn('prod_item', array_keys($codigosVinculados))->get()->keyBy(fn (Maeprod $m) => (string) $m->prod_item);

        $grupos = $separar && ($guardado['separar'] ?? false) ? $this->grupos($items) : [];
        if (count($grupos) < 2) {
            $grupos = ['' => array_keys($items)];
        }

        $region = (int) ($nota->region ?: ($guardado['region'] ?? 0));
        $factor = CompraAgilRegionScope::factorPrecioVentaPorRegion($region > 0 ? $region : null)
            ?? (float) ($nota->factor_precio_venta ?: config('cotiz.factor_precio_venta', 1.22));

        $conteo = ['vinculadas' => 0, 'referencias_web' => 0, 'pendientes' => 0, 'agregadas' => 0, 'eliminadas' => 0];
        $paraAprender = [];
        $cotizaciones = [];

        DB::transaction(function () use ($nota, $usuario, $items, $grupos, $rechazados, $maeprods, $reemplazar, $factor, &$conteo, &$paraAprender, &$cotizaciones) {
            $n = 0;
            foreach ($grupos as $solicitante => $indices) {
                $solicitante = (string) $solicitante;
                $destino = $n === 0 ? $nota : $this->notaService->duplicar($nota, $usuario, false);

                $lote = [];
                foreach ($indices as $i) {
                    $lote[] = $this->lineaLote($items[$i], isset($rechazados[$i]), $maeprods, $conteo);
                    if (! isset($rechazados[$i]) && $items[$i]['estado'] === self::ESTADO_VINCULADO
                        && $items[$i]['origen'] === self::ORIGEN_IA && $maeprods->has($items[$i]['producto']['prod_item'])) {
                        $paraAprender[] = [$items[$i], (int) $destino->nronota];
                    }
                }

                if ($n === 0 && $reemplazar) {
                    $conteo['eliminadas'] = $this->detalleService->eliminarTodasLineasAgile($destino);
                }
                $agregadas = $this->detalleService->agregarLineasImportacionLote($destino, $lote);
                $conteo['agregadas'] += $agregadas;
                $this->detalleService->aplicarFactorPrecioVenta($destino->fresh(), $factor, $usuario);

                if ($solicitante !== '') {
                    $obsActual = $n === 0 ? trim((string) $destino->fresh()->observacion_ejecutivo) : '';
                    $obs = 'Requerimiento de: '.$solicitante;
                    $this->notaService->modificarCabecera($destino->fresh(), [
                        'observacion_ejecutivo' => $obsActual === '' ? $obs : $obsActual."\n".$obs,
                    ], $usuario);
                }

                $cotizaciones[] = [
                    'nronota' => (int) $destino->nronota,
                    'solicitante' => $solicitante,
                    'agregadas' => $agregadas,
                ];
                $n++;
            }
        });

        $aprendidas = 0;
        foreach ($paraAprender as [$item, $nronota]) {
            try {
                $this->aprendizaje->guardarAprendizaje(
                    $item['descripcion'],
                    $item['producto']['prod_item'],
                    null,
                    $item['id_agile'],
                    $usuario,
                    VinculoOrigen::IA,
                    $nronota,
                );
                $aprendidas++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        Cache::forget($key);

        return $conteo + [
            'aprendidas' => $aprendidas,
            'cotizaciones' => $cotizaciones,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  \Illuminate\Support\Collection<string, Maeprod>  $maeprods
     * @param  array<string, int>  $conteo
     * @return array<string, mixed>
     */
    private function lineaLote(array $item, bool $rechazado, $maeprods, array &$conteo): array
    {
        $base = [
            'cantidad' => (int) $item['cantidad'],
            'prod_item_agile' => $item['id_agile'],
            'prod_descripcion_agile' => $item['descripcion'],
        ];

        if (! $rechazado && $item['estado'] === self::ESTADO_VINCULADO && $maeprods->has($item['producto']['prod_item'])) {
            /** @var Maeprod $mae */
            $mae = $maeprods->get($item['producto']['prod_item']);
            $conteo['vinculadas']++;

            return $base + [
                'prod_item' => (string) $mae->prod_item,
                'prod_valor' => (int) ($mae->prod_valor ?? 0),
                'prod_valor_costo' => (int) ($mae->prod_valor_costo ?? 0),
                'prod_nombre' => (string) $mae->prod_nombre,
            ];
        }

        if (! $rechazado && $item['estado'] === self::ESTADO_REFERENCIA_WEB && is_array($item['referencia'] ?? null)) {
            $conteo['referencias_web']++;

            return $base + [
                'pendiente' => true,
                'prod_valor_costo' => (int) $item['referencia']['neto_unitario'],
                'observacion' => $this->observacionReferencia($item['referencia']),
            ];
        }

        $conteo['pendientes']++;

        return $base + ['pendiente' => true];
    }

    /**
     * @return array{codigo: string, items: array<int, array<string, mixed>>, region: ?int, cabecera: array<string, mixed>}
     */
    public function previewGuardado(string $usuario, string $token): array
    {
        $guardado = Cache::get($this->cacheKey($usuario, $token));
        if (! is_array($guardado) || ! is_array($guardado['items'] ?? null) || trim((string) ($guardado['codigo'] ?? '')) === '') {
            throw new RuntimeException('La vista previa expiró. Vuelva a presionar «Cotizar con IA».');
        }

        return $guardado;
    }

    /**
     * Cabecera para una nota sin número, con los mismos campos que la importación de Compra Ágil.
     *
     * @param  array<string, mixed>  $cabecera
     * @return array<string, mixed>
     */
    private function datosCabeceraNueva(string $codigo, array $cabecera): array
    {
        $cabecera['codigo_cotizacion'] = $codigo;
        $cab = $this->compraAgilImport->enriquecerCabeceraDesdeOportunidad(['cabecera' => $cabecera, 'lineas' => []])['cabecera'];

        $datos = ['encargado' => $codigo];
        foreach (['empresa' => 'empresa', 'rutempresa' => 'rutempresa', 'nombre' => 'descripcion'] as $origen => $destino) {
            $valor = trim((string) ($cab[$origen] ?? ''));
            if ($valor !== '') {
                $datos[$destino] = $valor;
            }
        }

        $region = isset($cab['region']) && is_numeric($cab['region']) ? (int) $cab['region'] : 0;
        if ($region > 0) {
            $datos['region'] = $region;
            $nombreRegion = trim((string) ($cab['nombre_region'] ?? ''));
            $datos['nombre_region'] = $nombreRegion !== '' ? $nombreRegion : CompraAgilRegionScope::nombreRegion($region);
            if (($factor = CompraAgilRegionScope::factorPrecioVentaPorRegion($region)) !== null) {
                $datos['factor_precio_venta'] = $factor;
            }
            if (($dias = CompraAgilRegionScope::diasHabilesPorRegion($region)) !== null) {
                $datos['diashabiles'] = $dias;
            }
        }
        if (($comuna = trim((string) ($cab['comuna'] ?? ''))) !== '') {
            $datos['comuna'] = mb_substr($comuna, 0, 120);
        }
        if (($direccion = trim((string) ($cab['direccion_entrega'] ?? ''))) !== '') {
            $datos['direccion_entrega'] = mb_substr($direccion, 0, 255);
        }

        return $datos;
    }

    /**
     * Líneas solicitadas en Mercado Público: caché de Oportunidades → API → líneas actuales de la nota.
     *
     * @return array{0: list<array{id_agile: string, descripcion: string, cantidad: int}>, 1: ?int, 2: array<string, mixed>}
     */
    private function lineasCotizacion(Nota $nota, string $codigo): array
    {
        try {
            $preview = $this->oportunidadVinculo->previewGuardado($codigo);
            if (is_array($preview) && ($preview['lineas'] ?? []) !== []) {
                $cabecera = is_array($preview['cabecera'] ?? null) ? $preview['cabecera'] : [];
                $region = isset($cabecera['region']) ? (int) $cabecera['region'] : null;

                return [$this->normalizarLineasMp($preview['lineas']), $region ?: null, $cabecera];
            }
        } catch (Throwable $e) {
            report($e);
        }

        if ($this->compraAgilApi->isConfigured()) {
            try {
                $mapeado = $this->payloadMapper->fromDetalle($this->compraAgilApi->detalle($codigo));
                if ($mapeado['lineas'] !== []) {
                    $cabecera = is_array($mapeado['cabecera'] ?? null) ? $mapeado['cabecera'] : [];

                    return [$this->normalizarLineasMp($mapeado['lineas']), $cabecera['region'] ?? null, $cabecera];
                }
            } catch (Throwable $e) {
                $this->avisos[] = 'No se pudo consultar Mercado Público: '.$e->getMessage();
            }
        }

        $desdeNota = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->whereNotNull('prod_descripcion_agile')
            ->where('prod_descripcion_agile', '!=', '')
            ->orderBy('orden')
            ->get()
            ->map(fn (NotaDetalle $l) => [
                'id_agile' => trim((string) $l->prod_item_agile),
                'descripcion' => trim((string) $l->prod_descripcion_agile),
                'cantidad' => max(1, (int) $l->cantidad),
            ])
            ->all();

        return [$this->normalizarLineasMp($desdeNota), null, []];
    }

    /**
     * @param  list<array<string, mixed>>  $lineas
     * @return list<array{id_agile: string, descripcion: string, cantidad: int}>
     */
    private function normalizarLineasMp(array $lineas): array
    {
        $out = [];
        foreach ($lineas as $linea) {
            if (! is_array($linea)) {
                continue;
            }
            $descripcion = trim((string) ($linea['descripcion'] ?? ''));
            if ($descripcion === '') {
                continue;
            }
            $idAgile = trim((string) ($linea['id_agile'] ?? ''));
            $out[] = [
                'id_agile' => $idAgile !== '' ? mb_substr($idAgile, 0, 50) : $this->idAgileAdjunto($descripcion),
                'descripcion' => mb_substr($descripcion, 0, 500),
                'cantidad' => max(1, (int) ($linea['cantidad'] ?? 1)),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{nombre: string, parts: list<array<string, mixed>>}>
     */
    private function cargarAdjuntos(string $codigo): array
    {
        if (! $this->adjuntos->isConfigured()) {
            return [];
        }

        try {
            $this->adjuntos->buscarSiPendiente($codigo);
            $lista = $this->adjuntos->listar($codigo);
        } catch (Throwable $e) {
            $this->avisos[] = 'No se pudieron leer los adjuntos: '.$e->getMessage();

            return [];
        }

        $maxAdjuntos = (int) config('cotiz.gemini.max_adjuntos', 4);
        $maxBytes = (int) config('cotiz.gemini.max_adjunto_mb', 15) * 1024 * 1024;
        // Request inline de Gemini ≤ 20 MB y base64 infla ~33%: ~14 MB de binario en total.
        $presupuestoInline = 14 * 1024 * 1024;
        $out = [];

        foreach ($lista as $archivo) {
            if (count($out) >= $maxAdjuntos) {
                $this->avisos[] = 'Solo se analizaron '.$maxAdjuntos.' adjuntos.';
                break;
            }
            $nombre = (string) $archivo['nombre'];
            $mimeImagen = $this->mimeImagen($nombre);
            $soportado = $this->adjuntos->esPdf($nombre) || $this->adjuntos->esWord($nombre)
                || $this->adjuntos->esExcel($nombre) || $mimeImagen !== null;
            if (! $soportado) {
                continue;
            }
            if ((int) $archivo['bytes'] > $maxBytes) {
                $this->avisos[] = "Adjunto «{$nombre}» omitido por tamaño.";

                continue;
            }

            try {
                $binario = $this->adjuntos->contenido($codigo, $nombre);
                $parts = $this->partesAdjunto($codigo, $nombre, $binario, $mimeImagen, $presupuestoInline);
            } catch (Throwable $e) {
                $this->avisos[] = "No se pudo leer «{$nombre}»: ".$e->getMessage();

                continue;
            }
            if ($parts !== []) {
                $out[] = ['nombre' => $nombre, 'parts' => $parts];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function partesAdjunto(string $codigo, string $nombre, string $binario, ?string $mimeImagen, int &$presupuestoInline): array
    {
        if ($this->adjuntos->esExcel($nombre)) {
            $texto = $this->adjuntos->textoExcel($binario);

            return $texto === '' ? [] : [['text' => $texto]];
        }

        if ($this->adjuntos->esWord($nombre) && ! $this->adjuntos->esDocAntiguo($nombre)) {
            $texto = mb_substr($this->adjuntos->textoDocx($binario), 0, 60000);

            return $texto === '' ? [] : [['text' => $texto]];
        }

        if ($mimeImagen !== null) {
            $body = $binario;
            $mime = $mimeImagen;
        } else {
            $analisis = $this->adjuntos->contenidoParaAnalisis($codigo, $nombre, $binario);
            $body = $analisis['body'];
            $mime = 'application/pdf';
        }

        if (strlen($body) > $presupuestoInline) {
            $this->avisos[] = "Adjunto «{$nombre}» omitido: se superó el tamaño total enviable a la IA.";

            return [];
        }
        $presupuestoInline -= strlen($body);

        return [['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($body)]]];
    }

    private function mimeImagen(string $nombre): ?string
    {
        return match (strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };
    }

    /**
     * @param  list<array{id_agile: string, descripcion: string, cantidad: int}>  $lineasMp
     * @param  list<array{nombre: string, parts: list<array<string, mixed>>}>  $adjuntos
     * @return array{fuente: string, motivo: string, adjuntos_usados: list<string>, lineas_adjunto: list<array{descripcion: string, cantidad: int}>}
     */
    private function decidirFuente(array $lineasMp, array $adjuntos): array
    {
        if ($adjuntos === []) {
            return [
                'fuente' => self::FUENTE_COTIZACION,
                'motivo' => 'La cotización no tiene adjuntos legibles; se usan los productos de Mercado Público.',
                'adjuntos_usados' => [],
                'lineas_adjunto' => [],
                'separar' => false,
                'solicitantes_ficha' => [],
            ];
        }

        $listado = $lineasMp === []
            ? '(la cotización no trae productos en Mercado Público)'
            : implode("\n", array_map(
                static fn (array $l, int $i) => ($i + 1).'. ['.$l['cantidad'].'] '.$l['descripcion'],
                $lineasMp,
                array_keys($lineasMp),
            ));

        $parts = [[
            'text' => "Productos solicitados en la ficha de Mercado Público (cantidad entre corchetes):\n"
                .$listado
                ."\n\nA continuación vienen los adjuntos de la misma cotización.",
        ]];
        foreach ($adjuntos as $n => $adjunto) {
            $parts[] = ['text' => "\n--- Adjunto ".($n + 1).': '.$adjunto['nombre'].' ---'];
            foreach ($adjunto['parts'] as $part) {
                $parts[] = $part;
            }
        }
        $parts[] = ['text' => <<<'TXT'

Decide de dónde salen los productos a cotizar:
- "adjunto": la ficha es genérica (ej. "materiales de aseo", "según adjunto", una sola línea con cantidad 1) y el detalle real está en un adjunto.
- "cotizacion": los productos de la ficha ya son específicos y los adjuntos no agregan productos (bases, términos, formularios).
- "ambos": la ficha es válida pero un adjunto agrega productos que no están en la ficha.

Extrae en "lineas" los productos del adjunto que se deben cotizar:
- Con "adjunto": todos los productos del adjunto.
- Con "ambos": solo los que no están ya en la ficha.
- Con "cotizacion": lista vacía.
Cada línea: descripción completa tal como la pide el comprador (tipo de producto, medida, capacidad, formato, color, marca si la exige) y cantidad entera (mínimo 1). Ignora totales, subtotales, encabezados, firmas y condiciones.
No agrupes ni sumes productos repetidos: si el mismo producto aparece varias veces (por establecimiento, sección, lote o destino), devuelve una línea por cada aparición con su propia cantidad.

Cotizaciones separadas: si el comprador pide cotizar u ofertar por separado para cada solicitante (ej. una cotización por escuela, jardín, establecimiento, departamento o sucursal), responde "separar": true e indica en cada línea el "solicitante" (nombre corto de quien hace ese requerimiento, igual para todas sus líneas). Con "cotizacion" o "ambos" indica además en "grupos_ficha" el solicitante de cada producto de la ficha según su número. Si no pide cotizaciones separadas: "separar": false, "solicitante" vacío y "grupos_ficha" vacío; en ese caso, si el adjunto indica establecimiento o sección de un producto repetido, agrégalo entre paréntesis en la descripción.

Responde SOLO JSON:
{"fuente":"adjunto|cotizacion|ambos","motivo":"explicación breve en español","adjuntos_usados":["nombre archivo"],"separar":false,"grupos_ficha":[{"n":1,"solicitante":""}],"lineas":[{"descripcion":"...","cantidad":1,"solicitante":""}]}
TXT];

        try {
            $respuesta = $this->gemini->generar($parts, ['json' => true, 'system' => $this->instruccionSistema()]);
        } catch (GeminiCuotaAgotadaException $e) {
            $this->iaSinCuota = true;
            $this->avisos[] = $e->getMessage().' Se vinculó solo con reglas.';

            return $this->fuenteSinIa($lineasMp);
        } catch (RuntimeException $e) {
            Log::warning('CotizarIa: fallo al decidir fuente', ['message' => $e->getMessage()]);
            $this->avisos[] = 'La IA no pudo analizar los adjuntos: '.$e->getMessage();

            return $this->fuenteSinIa($lineasMp);
        }

        $json = is_array($respuesta['json']) ? $respuesta['json'] : [];
        $fuente = (string) ($json['fuente'] ?? '');
        if (! in_array($fuente, [self::FUENTE_COTIZACION, self::FUENTE_ADJUNTO, self::FUENTE_AMBOS], true)) {
            $fuente = self::FUENTE_COTIZACION;
        }

        $lineasAdjunto = [];
        foreach ((array) ($json['lineas'] ?? []) as $linea) {
            if (! is_array($linea)) {
                continue;
            }
            $descripcion = trim((string) ($linea['descripcion'] ?? ''));
            if ($descripcion === '') {
                continue;
            }
            $lineasAdjunto[] = [
                'descripcion' => mb_substr($descripcion, 0, 500),
                'cantidad' => max(1, min(1_000_000, (int) round((float) ($linea['cantidad'] ?? 1)))),
                'solicitante' => $this->limpiarSolicitante($linea['solicitante'] ?? ''),
            ];
        }

        $solicitantesFicha = [];
        foreach ((array) ($json['grupos_ficha'] ?? []) as $grupo) {
            $n = is_array($grupo) ? (int) ($grupo['n'] ?? 0) : 0;
            if ($n >= 1 && $n <= count($lineasMp)) {
                $solicitantesFicha[$n - 1] = $this->limpiarSolicitante($grupo['solicitante'] ?? '');
            }
        }

        if ($fuente !== self::FUENTE_COTIZACION && $lineasAdjunto === []) {
            $fuente = self::FUENTE_COTIZACION;
        }
        if ($fuente === self::FUENTE_COTIZACION && $lineasMp === [] && $lineasAdjunto !== []) {
            $fuente = self::FUENTE_ADJUNTO;
        }

        $nombres = array_map(static fn (array $a) => $a['nombre'], $adjuntos);
        $usados = array_values(array_intersect(
            array_map('strval', (array) ($json['adjuntos_usados'] ?? [])),
            $nombres,
        ));

        return [
            'fuente' => $fuente,
            'motivo' => mb_substr(trim((string) ($json['motivo'] ?? '')), 0, 500),
            'adjuntos_usados' => $fuente === self::FUENTE_COTIZACION ? [] : $usados,
            'lineas_adjunto' => $fuente === self::FUENTE_COTIZACION ? [] : $lineasAdjunto,
            'separar' => ($json['separar'] ?? false) === true,
            'solicitantes_ficha' => $fuente === self::FUENTE_ADJUNTO ? [] : $solicitantesFicha,
        ];
    }

    private function limpiarSolicitante(mixed $valor): string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', is_scalar($valor) ? (string) $valor : ''));

        return mb_substr($texto, 0, 120);
    }

    /**
     * @param  list<array{id_agile: string, descripcion: string, cantidad: int}>  $lineasMp
     * @return array{fuente: string, motivo: string, adjuntos_usados: list<string>, lineas_adjunto: list<array{descripcion: string, cantidad: int}>}
     */
    private function fuenteSinIa(array $lineasMp): array
    {
        if ($lineasMp === []) {
            throw new RuntimeException('La cotización no trae productos en Mercado Público y la IA no pudo leer los adjuntos. Intente más tarde.');
        }

        $remitenAlAdjunto = array_filter(
            $lineasMp,
            static fn (array $l) => preg_match('/ADJUNT|SEG[UÚ]N\s+(EL\s+|LAS?\s+)?(REQUERIMIENTO|DETALLE|BASES|ANEXO)/iu', $l['descripcion']) === 1,
        );
        if (count($remitenAlAdjunto) === count($lineasMp)) {
            throw new RuntimeException(
                'Los productos de Mercado Público solo remiten al adjunto y la IA no pudo leerlo en este momento. '
                .'Intente nuevamente en unos minutos.',
            );
        }

        return [
            'fuente' => self::FUENTE_COTIZACION,
            'motivo' => 'Sin análisis de IA: se usan los productos de Mercado Público.',
            'adjuntos_usados' => [],
            'lineas_adjunto' => [],
            'separar' => false,
            'solicitantes_ficha' => [],
        ];
    }

    /**
     * @param  array{fuente: string, lineas_adjunto: list<array{descripcion: string, cantidad: int}>}  $decision
     * @param  list<array{id_agile: string, descripcion: string, cantidad: int}>  $lineasMp
     * @return list<array<string, mixed>>
     */
    private function armarItems(array $decision, array $lineasMp): array
    {
        $separar = (bool) ($decision['separar'] ?? false);
        $items = [];
        if ($decision['fuente'] !== self::FUENTE_ADJUNTO) {
            foreach ($lineasMp as $n => $linea) {
                $items[] = $linea + [
                    'fuente' => self::FUENTE_COTIZACION,
                    'solicitante' => $separar ? ($decision['solicitantes_ficha'][$n] ?? '') : '',
                ];
            }
        }
        foreach ($decision['lineas_adjunto'] as $linea) {
            $items[] = [
                'id_agile' => $this->idAgileAdjunto($linea['descripcion']),
                'descripcion' => $linea['descripcion'],
                'cantidad' => $linea['cantidad'],
                'fuente' => self::FUENTE_ADJUNTO,
                'solicitante' => $separar ? ($linea['solicitante'] ?? '') : '',
            ];
        }

        foreach ($items as $i => $item) {
            // Líneas iguales se mantienen separadas: cada una con su propio ID Agile.
            if (str_starts_with((string) $item['id_agile'], 'ia:')) {
                $items[$i]['id_agile'] = $this->idAgileAdjunto($item['descripcion'], $i);
            }
            $items[$i] += [
                'estado' => self::ESTADO_PENDIENTE,
                'origen' => null,
                'producto' => null,
                'referencia' => null,
                'alternativas' => [],
                'stock_prisa' => null,
                'stock_nota' => null,
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function vincular(array $items): array
    {
        $this->aprendizaje->precalentarParaImportacion();
        $paraIa = [];

        foreach ($items as $i => $item) {
            $porFrase = $this->aprendizaje->resolverProductoPorFrase($item['descripcion']);
            if ($porFrase !== null) {
                $items[$i] = $this->marcarVinculado($item, $porFrase, self::ORIGEN_FRASE);

                continue;
            }
            $exacto = $this->aprendizaje->buscarAprendidoExacto($item['descripcion']);
            if ($exacto !== null) {
                $items[$i] = $this->marcarVinculado($item, $exacto, self::ORIGEN_APRENDIDO);

                continue;
            }
            $paraIa[] = $i;
        }

        if ($paraIa === [] || $this->iaSinCuota) {
            return $items;
        }

        $this->etapa(5, 'IA buscando equivalencias en el maestro para '.count($paraIa).' línea(s)');
        $candidatos = [];
        foreach ($paraIa as $i) {
            $candidatos[$i] = $this->candidatosMaeprod($items[$i]['descripcion'], [$items[$i]['descripcion']]);
        }

        $resultado = $this->equivalenciasIa($items, $candidatos, true);
        $sinEquivalente = [];
        foreach ($paraIa as $i) {
            $elegido = $this->masEconomico($candidatos[$i], $resultado[$i]['equivalentes'] ?? []);
            if ($elegido !== null) {
                $items[$i] = $this->marcarVinculado($items[$i], $elegido, self::ORIGEN_IA);
                $items[$i]['alternativas'] = $this->alternativas($candidatos[$i], $resultado[$i]['equivalentes'], $elegido);
            } elseif (($resultado[$i]['busqueda'] ?? []) !== []) {
                $sinEquivalente[$i] = $resultado[$i]['busqueda'];
            }
        }

        if ($sinEquivalente === [] || $this->iaSinCuota) {
            return $items;
        }

        // Segunda pasada: términos alternativos que sugirió la IA (sinónimos / nombre comercial).
        $this->detalle('Segunda pasada con términos alternativos para '.count($sinEquivalente).' línea(s)');
        $candidatos2 = [];
        foreach ($sinEquivalente as $i => $terminos) {
            $yaEnviados = array_fill_keys(array_keys($candidatos[$i]), true);
            $nuevos = array_diff_key(
                $this->candidatosMaeprod($items[$i]['descripcion'], $terminos),
                $yaEnviados,
            );
            if ($nuevos !== []) {
                $candidatos2[$i] = $nuevos;
            }
        }
        if ($candidatos2 === []) {
            return $items;
        }

        $resultado2 = $this->equivalenciasIa($items, $candidatos2, false);
        foreach ($candidatos2 as $i => $lista) {
            $elegido = $this->masEconomico($lista, $resultado2[$i]['equivalentes'] ?? []);
            if ($elegido !== null) {
                $items[$i] = $this->marcarVinculado($items[$i], $elegido, self::ORIGEN_IA);
                $items[$i]['alternativas'] = $this->alternativas($lista, $resultado2[$i]['equivalentes'], $elegido);
            }
        }

        return $items;
    }

    /**
     * Candidatos del maestro por búsqueda de similitud + filtros de atributos (medida, color, marca, familia).
     *
     * @param  list<string>  $terminos
     * @return array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}>
     */
    private function candidatosMaeprod(string $descripcion, array $terminos): array
    {
        $out = [];
        foreach (array_slice($terminos, 0, 4) as $termino) {
            $termino = trim((string) $termino);
            if ($termino === '') {
                continue;
            }
            try {
                $filas = $this->busqueda->buscar($termino, null, self::CANDIDATOS_POR_LINEA);
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            foreach ($filas as $fila) {
                $producto = $this->productoDesdeFila($fila);
                if ($producto === null || isset($out[$producto['prod_item']])) {
                    continue;
                }
                if (! $this->pasaFiltros($descripcion, $producto['prod_nombre'])) {
                    continue;
                }
                $out[$producto['prod_item']] = $producto;
                if (count($out) >= self::CANDIDATOS_POR_LINEA) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * Filtros del maestro, pero sin distinguir género del color (BLANCO = BLANCA): el filtro
     * compartido compara literal y descartaba «CARTULINA … BLANCA» para «CARTULINA BLANCO».
     */
    private function pasaFiltros(string $descripcion, string $nombreProducto): bool
    {
        return $this->busqueda->pasaFiltrosAtributos(
            $this->colorMasculino($descripcion),
            $this->colorMasculino($nombreProducto),
        );
    }

    private function colorMasculino(string $texto): string
    {
        return (string) preg_replace_callback(
            '/\b(ROJ|NEGR|BLANC|AMARILL|ROSAD|MORAD)A\b/iu',
            static fn (array $m) => $m[1].(ctype_upper(substr($m[0], -1)) ? 'O' : 'o'),
            $texto,
        );
    }

    /**
     * @return ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    private function productoDesdeFila(mixed $fila): ?array
    {
        if (! is_object($fila)) {
            return null;
        }
        $item = trim((string) ($fila->prod_item ?? ''));
        $nombre = trim((string) ($fila->prod_nombre ?? ''));
        if ($item === '' || $nombre === '') {
            return null;
        }

        return [
            'prod_item' => $item,
            'prod_nombre' => $nombre,
            'prod_valor' => (int) ($fila->prod_valor ?? 0),
            'prod_valor_costo' => (int) ($fila->prod_valor_costo ?? 0),
        ];
    }

    /**
     * Solo se envían descripción solicitada y código + nombre de candidatos (nunca precios).
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, array<string, array{prod_item: string, prod_nombre: string}>>  $candidatos
     * @return array<int, array{equivalentes: list<string>, busqueda: list<string>}>
     */
    private function equivalenciasIa(array $items, array $candidatos, bool $pedirBusqueda): array
    {
        $resultado = [];
        $pendientes = array_chunk(array_keys($candidatos), self::LINEAS_POR_LLAMADA);
        $totalBloques = count($pendientes);
        for ($ronda = 1; $ronda <= 2 && $pendientes !== [] && ! $this->iaSinCuota; $ronda++) {
            if ($ronda === 2) {
                // Los 503 de Gemini suelen durar segundos: una pausa y un segundo intento recuperan el lote.
                $this->detalle('Reintentando '.count($pendientes).' lote(s) que no respondieron');
                usleep(4 * (int) config('cotiz.gemini.reintento_espera_ms', 2500) * 1000);
            }
            $fallidos = [];
            foreach ($pendientes as $nBloque => $bloque) {
                if ($this->iaSinCuota) {
                    break;
                }
                if ($ronda === 1 && $totalBloques > 1) {
                    $this->detalle('Lote '.($nBloque + 1).' de '.$totalBloques);
                }
                $error = $this->equivalenciasBloque($items, $candidatos, $bloque, $pedirBusqueda, $resultado);
                if ($error !== null) {
                    $fallidos[] = $bloque;
                    if ($ronda === 2) {
                        $this->avisos[] = 'La IA no respondió para '.count($bloque).' línea(s): '.$error;
                    }
                }
            }
            $pendientes = $fallidos;
        }

        return $resultado;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, array<string, array{prod_item: string, prod_nombre: string}>>  $candidatos
     * @param  list<int>  $bloque
     * @param  array<int, array{equivalentes: list<string>, busqueda: list<string>}>  $resultado
     * @return string|null mensaje de error si Gemini no respondió (el bloque se puede reintentar)
     */
    private function equivalenciasBloque(array $items, array $candidatos, array $bloque, bool $pedirBusqueda, array &$resultado): ?string
    {
        $entrada = [];
        foreach ($bloque as $i) {
            $entrada[] = [
                'i' => $i,
                'solicitado' => $items[$i]['descripcion'],
                'candidatos' => array_values(array_map(
                    static fn (array $c) => ['codigo' => $c['prod_item'], 'nombre' => $c['prod_nombre']],
                    $candidatos[$i],
                )),
            ];
        }

        $instruccionBusqueda = $pedirBusqueda
            ? 'Si ninguno es equivalente (o no hay candidatos), devuelve "equivalentes": [] y en "busqueda" 2 o 3 términos cortos alternativos para buscar ese producto en el catálogo (nombre genérico o comercial usado en Chile, sin cantidades).'
            : 'Si ninguno es equivalente devuelve "equivalentes": [] y "busqueda": [].';

        $prompt = "Para cada producto solicitado indica qué candidatos del catálogo son el MISMO producto y sirven para cotizarlo.\n"
            ."Criterios: mismo tipo de producto; medida, capacidad, gramaje y formato compatibles; si el solicitado exige color, marca o material, deben coincidir; "
            ."un pack o caja solo es equivalente si el solicitado pide ese formato. No consideres precio. Puedes marcar varios equivalentes.\n"
            .$instruccionBusqueda."\n"
            ."Usa solo códigos que aparezcan en los candidatos de ese producto.\n\n"
            .'Productos: '.json_encode($entrada, JSON_UNESCAPED_UNICODE)."\n\n"
            .'Responde SOLO JSON: {"resultados":[{"i":0,"equivalentes":["CODIGO"],"busqueda":["termino"]}]}';

        try {
            $respuesta = $this->gemini->generar([['text' => $prompt]], ['json' => true, 'system' => $this->instruccionSistema()]);
        } catch (GeminiCuotaAgotadaException $e) {
            $this->iaSinCuota = true;
            $this->avisos[] = $e->getMessage().' Las líneas restantes quedaron sin vincular por IA.';

            return null;
        } catch (RuntimeException $e) {
            Log::warning('CotizarIa: fallo en equivalencias', ['message' => $e->getMessage()]);

            return $e->getMessage();
        }

        $json = is_array($respuesta['json']) ? $respuesta['json'] : [];
        foreach ((array) ($json['resultados'] ?? []) as $fila) {
            if (! is_array($fila) || ! isset($fila['i'])) {
                continue;
            }
            $i = (int) $fila['i'];
            if (! isset($candidatos[$i])) {
                continue;
            }
            $resultado[$i] = [
                'equivalentes' => array_values(array_filter(
                    array_map(static fn ($c) => trim((string) $c), (array) ($fila['equivalentes'] ?? [])),
                    static fn (string $c) => isset($candidatos[$i][$c]),
                )),
                'busqueda' => array_values(array_filter(
                    array_map(static fn ($t) => mb_substr(trim((string) $t), 0, 80), (array) ($fila['busqueda'] ?? [])),
                    static fn (string $t) => $t !== '',
                )),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}>  $candidatos
     * @param  list<string>  $equivalentes
     * @return ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    private function masEconomico(array $candidatos, array $equivalentes): ?array
    {
        $productos = [];
        foreach ($equivalentes as $codigo) {
            if (isset($candidatos[$codigo])) {
                $productos[] = $candidatos[$codigo];
            }
        }

        return $this->busqueda->elegirMasEconomico($productos);
    }

    /**
     * Otros equivalentes que marcó la IA, por si el elegido no tiene stock en Prisa.
     *
     * @param  array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}>  $candidatos
     * @param  list<string>  $equivalentes
     * @param  array{prod_item: string}  $elegido
     * @return list<array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}>
     */
    private function alternativas(array $candidatos, array $equivalentes, array $elegido): array
    {
        $out = [];
        foreach ($equivalentes as $codigo) {
            if (isset($candidatos[$codigo]) && $codigo !== $elegido['prod_item']) {
                $out[] = $candidatos[$codigo];
            }
        }

        return $out;
    }

    /**
     * Revisa en Prisa el producto vinculado (código = prod_item). Sin stock (agotado, a pedido,
     * descontinuado…) se cambia por el equivalente más económico con stock o, si no hay, la línea
     * queda pendiente y pasa a la búsqueda en Mercado Libre / Sodimac. Si Prisa no tiene el código,
     * no se revisa el stock.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function revisarStockPrisa(array $items): array
    {
        $vinculados = array_keys(array_filter($items, static fn (array $item) => $item['estado'] === self::ESTADO_VINCULADO));
        if ($vinculados === [] || ! $this->prisa->habilitado()) {
            return $items;
        }

        $this->etapa(6, 'Revisando stock en Prisa para '.count($vinculados).' producto(s)');
        $codigos = [];
        foreach ($vinculados as $i) {
            $codigos[] = $items[$i]['producto']['prod_item'];
            foreach ($items[$i]['alternativas'] as $alternativa) {
                $codigos[] = $alternativa['prod_item'];
            }
        }
        $estados = $this->prisa->consultar($codigos);
        $conStock = [PrisaStockService::ESTADO_DISPONIBLE, PrisaStockService::ESTADO_ULTIMAS_UNIDADES];

        $noVerificados = 0;
        $sinStock = 0;
        $reemplazados = 0;
        foreach ($vinculados as $i) {
            $original = $items[$i]['producto'];
            if (! array_key_exists($original['prod_item'], $estados)) {
                $noVerificados++;

                continue;
            }
            $estado = $estados[$original['prod_item']];
            $items[$i]['stock_prisa'] = $estado;
            if ($estado === null || $estado['estado'] !== PrisaStockService::ESTADO_SIN_STOCK) {
                continue;
            }

            $sinStock++;
            $opciones = array_values(array_filter(
                $items[$i]['alternativas'],
                static fn (array $p) => in_array($estados[$p['prod_item']]['estado'] ?? null, $conStock, true),
            ));
            $reemplazo = $opciones === [] ? null : $this->busqueda->elegirMasEconomico($opciones);
            if ($reemplazo !== null) {
                $items[$i] = $this->marcarVinculado($items[$i], $reemplazo, (string) $items[$i]['origen']);
                $items[$i]['stock_prisa'] = $estados[$reemplazo['prod_item']];
                $items[$i]['stock_nota'] = 'Prisa: '.$original['prod_item'].' '.$estado['etiqueta'].'; se usó '.$reemplazo['prod_item'].'.';
                $reemplazados++;

                continue;
            }

            $items[$i]['estado'] = self::ESTADO_PENDIENTE;
            $items[$i]['origen'] = null;
            $items[$i]['producto'] = null;
            $items[$i]['stock_nota'] = 'Prisa: '.$original['prod_item'].' '.$original['prod_nombre'].' '.$estado['etiqueta'].'.';
        }

        if ($noVerificados === count($vinculados)) {
            $this->avisos[] = 'No se pudo revisar el stock en Prisa; los vínculos quedaron sin verificar.';
        } elseif ($noVerificados > 0) {
            $this->avisos[] = "No se pudo revisar el stock en Prisa de {$noVerificados} producto(s).";
        }
        if ($sinStock > 0) {
            $this->avisos[] = "Prisa: {$sinStock} producto(s) sin stock (agotado, a pedido o descontinuado). "
                ."{$reemplazados} se cambiaron por otro equivalente con stock y "
                .($sinStock - $reemplazados).' pasan a buscar en Mercado Libre / Sodimac.';
        }

        return $items;
    }

    /**
     * Sin equivalente en el maestro: referencia en Mercado Libre / Sodimac vía Google Search.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function buscarReferenciasWeb(array $items): array
    {
        $pendientes = [];
        foreach ($items as $i => $item) {
            if ($item['estado'] === self::ESTADO_PENDIENTE) {
                $pendientes[] = $i;
            }
        }
        if ($pendientes === [] || $this->iaSinCuota || ! config('cotiz.gemini.busqueda_web', true)) {
            return $items;
        }
        if (Cache::has(self::CACHE_WEB_SIN_CUOTA)) {
            $this->avisos[] = 'Búsqueda en Mercado Libre / Sodimac sin cuota disponible por ahora; las líneas sin vínculo quedaron pendientes.';

            return $items;
        }

        $max = (int) config('cotiz.gemini.max_lineas_web', 50);
        if ($max <= 0) {
            return $items;
        }
        if (count($pendientes) > $max) {
            $this->avisos[] = "Se buscó referencia web solo para {$max} de ".count($pendientes).' líneas sin vínculo.';
            $pendientes = array_slice($pendientes, 0, $max);
        }

        $lotes = array_chunk($pendientes, max(1, (int) config('cotiz.gemini.lote_web', 10)));
        $lotesIlegibles = 0;
        $sinStockSuficiente = 0;
        foreach ($lotes as $n => $lote) {
            if (count($lotes) > 1) {
                $this->detalle('Tanda '.($n + 1).' de '.count($lotes).' ('.count($lote).' línea(s))');
            }
            try {
                $items = $this->buscarLoteWeb($items, $lote, $sinStockSuficiente);
            } catch (GeminiRespuestaInvalidaException $e) {
                Log::warning('CotizarIa: búsqueda web sin JSON legible tras reintento', ['message' => $e->getMessage(), 'tanda' => $n + 1]);
                $lotesIlegibles++;
            } catch (GeminiCuotaAgotadaException) {
                Cache::put(self::CACHE_WEB_SIN_CUOTA, true, now()->addHour());
                $this->avisos[] = 'Búsqueda en Mercado Libre / Sodimac sin cuota disponible por ahora; las líneas sin vínculo restantes quedaron pendientes.';

                break;
            } catch (RuntimeException $e) {
                Log::warning('CotizarIa: fallo en búsqueda web', ['message' => $e->getMessage(), 'tanda' => $n + 1]);
                $this->avisos[] = 'No se pudo buscar referencias web: '.$e->getMessage();

                break;
            }
        }

        if ($lotesIlegibles > 0) {
            $this->avisos[] = count($lotes) > 1
                ? "La búsqueda en Mercado Libre / Sodimac no devolvió un resultado legible en {$lotesIlegibles} de ".count($lotes).' tanda(s) (se reintentó); esas líneas quedaron pendientes.'
                : 'La búsqueda en Mercado Libre / Sodimac no devolvió un resultado legible (se reintentó); las líneas sin vínculo quedaron pendientes.';
        }
        if ($sinStockSuficiente > 0) {
            $this->avisos[] = "Mercado Libre / Sodimac: {$sinStockSuficiente} línea(s) sin publicaciones con stock suficiente para la cantidad pedida; quedaron pendientes.";
        }
        $noVerificadas = count(array_filter(
            $items,
            static fn (array $item) => $item['estado'] === self::ESTADO_REFERENCIA_WEB && ($item['referencia']['stock_verificado'] ?? true) === false,
        ));
        if ($noVerificadas > 0) {
            $this->avisos[] = "{$noVerificadas} referencia(s) web con stock no verificado; revíselas con «ver» antes de cotizar.";
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $pendientes
     * @return list<array<string, mixed>>
     *
     * @throws GeminiCuotaAgotadaException
     * @throws GeminiRespuestaInvalidaException
     * @throws RuntimeException
     */
    private function buscarLoteWeb(array $items, array $pendientes, int &$sinStockSuficiente): array
    {
        $entrada = array_map(static fn (int $i) => [
            'i' => $i,
            'solicitado' => $items[$i]['descripcion'],
            'cantidad' => (int) $items[$i]['cantidad'],
        ], $pendientes);

        $prompt = "Busca en Google cada producto SOLO en mercadolibre.cl y sodimac.cl (Chile).\n"
            ."Para cada uno devuelve hasta 5 publicaciones del mismo producto (mismo tipo, medida y formato) con su precio actual en pesos chilenos IVA incluido.\n"
            ."Si la publicación vende un pack o caja, indica cuántas unidades trae en unidades_por_pack (si es unitario, 1).\n"
            ."En stock_disponible indica cuántas unidades de la publicación (packs, si vende packs) muestra disponibles la página: "
            ."\"+50 disponibles\" = 50, \"Últimas 3\" = 3, agotado o sin stock = 0; si la página no lo muestra, null. No lo inventes.\n"
            ."Se necesita al menos la cantidad indicada: prioriza publicaciones con stock suficiente para esa cantidad.\n"
            ."Usa solo URLs reales de páginas encontradas en la búsqueda; no inventes URLs ni precios. Si no encuentras, deja opciones vacío.\n\n"
            .'Productos: '.json_encode($entrada, JSON_UNESCAPED_UNICODE)."\n\n"
            .'Responde SOLO JSON: {"resultados":[{"i":0,"opciones":[{"sitio":"mercadolibre|sodimac","titulo":"","precio_clp":0,"unidades_por_pack":1,"stock_disponible":null,"url":""}]}]}';

        $opciones = ['json' => true, 'google_search' => true, 'thinking_level' => (string) config('cotiz.gemini.thinking_web', 'low')];
        try {
            $respuesta = $this->gemini->generar([['text' => $prompt]], $opciones);
        } catch (GeminiRespuestaInvalidaException) {
            $respuesta = $this->gemini->generar([['text' => $prompt
                ."\n\nIMPORTANTE: tu respuesta anterior no era JSON válido. Responde únicamente el objeto JSON, sin texto antes ni después y sin bloques ```."]],
                $opciones);
        }

        $json = is_array($respuesta['json']) ? $respuesta['json'] : [];
        $json['resultados'] = $this->resolverUrlsGrounding((array) ($json['resultados'] ?? []));
        $pendientesSet = array_fill_keys($pendientes, true);
        foreach ((array) ($json['resultados'] ?? []) as $fila) {
            if (! is_array($fila) || ! isset($fila['i'])) {
                continue;
            }
            $i = (int) $fila['i'];
            if (! isset($pendientesSet[$i])) {
                continue;
            }
            $cantidad = max(1, (int) $items[$i]['cantidad']);
            [$mejor, $todasSinStock] = $this->mejorReferencia($items[$i]['descripcion'], $cantidad, (array) ($fila['opciones'] ?? []));
            if ($mejor !== null) {
                $items[$i]['estado'] = self::ESTADO_REFERENCIA_WEB;
                $items[$i]['origen'] = self::ORIGEN_WEB;
                $items[$i]['referencia'] = $mejor;
            } elseif ($todasSinStock) {
                $sinStockSuficiente++;
                $nota = "Mercado Libre / Sodimac: ninguna publicación tiene stock suficiente para {$cantidad} unidad(es).";
                $previa = trim((string) ($items[$i]['stock_nota'] ?? ''));
                $items[$i]['stock_nota'] = $previa === '' ? $nota : $previa.' '.$nota;
            }
        }

        return $items;
    }

    /**
     * Elige la opción más económica con stock suficiente para la cantidad pedida; si ninguna lo confirma,
     * la más económica con stock desconocido (queda marcada como no verificada). El segundo valor indica
     * que todas las opciones válidas tienen stock insuficiente.
     *
     * @param  list<mixed>  $opciones
     * @return array{0: ?array{sitio: string, titulo: string, precio_clp: int, unidades_por_pack: int, neto_unitario: int, url: string, fecha: string, stock: ?int, stock_verificado: bool}, 1: bool}
     */
    private function mejorReferencia(string $descripcion, int $cantidad, array $opciones): array
    {
        $confirmada = null;
        $desconocida = null;
        $insuficientes = 0;
        $validas = 0;
        foreach ($opciones as $opcion) {
            if (! is_array($opcion)) {
                continue;
            }
            $url = trim((string) ($opcion['url'] ?? ''));
            $sitio = $this->sitioPermitido($url);
            $precio = (int) round((float) ($opcion['precio_clp'] ?? 0));
            $titulo = mb_substr(trim((string) ($opcion['titulo'] ?? '')), 0, 200);
            if ($sitio === null || $precio <= 0 || $titulo === '') {
                continue;
            }
            if (! $this->pasaFiltros($descripcion, $titulo)) {
                continue;
            }
            $unidades = max(1, (int) ($opcion['unidades_por_pack'] ?? 1));
            $neto = (int) round($precio / self::IVA / $unidades);
            if ($neto <= 0) {
                continue;
            }
            $validas++;

            $stockBruto = $opcion['stock_disponible'] ?? null;
            $stock = is_numeric($stockBruto) && (float) $stockBruto >= 0 ? (int) floor((float) $stockBruto) : null;
            if ($stock !== null && $stock < (int) ceil($cantidad / $unidades)) {
                $insuficientes++;

                continue;
            }

            $candidata = [
                'sitio' => $sitio,
                'titulo' => $titulo,
                'precio_clp' => $precio,
                'unidades_por_pack' => $unidades,
                'neto_unitario' => $neto,
                'url' => mb_substr($url, 0, 1000),
                'fecha' => now()->format('d-m-Y'),
                'stock' => $stock,
                'stock_verificado' => $stock !== null,
            ];
            if ($stock !== null) {
                if ($confirmada === null || $neto < $confirmada['neto_unitario']) {
                    $confirmada = $candidata;
                }
            } elseif ($desconocida === null || $neto < $desconocida['neto_unitario']) {
                $desconocida = $candidata;
            }
        }

        return [$confirmada ?? $desconocida, $validas > 0 && $insuficientes === $validas];
    }

    /**
     * Algunos modelos devuelven la URL de redirección de Google (vertexaisearch…/grounding-api-redirect/…)
     * en vez de la publicación: se reemplaza por su Location para que pase el filtro de sitios.
     *
     * @param  list<mixed>  $resultados
     * @return list<mixed>
     */
    private function resolverUrlsGrounding(array $resultados): array
    {
        $redirecciones = [];
        foreach ($resultados as $fila) {
            foreach ((array) (is_array($fila) ? ($fila['opciones'] ?? []) : []) as $opcion) {
                $url = is_array($opcion) ? trim((string) ($opcion['url'] ?? '')) : '';
                if ($this->esRedireccionGrounding($url)) {
                    $redirecciones[$url] = true;
                }
            }
        }
        if ($redirecciones === []) {
            return $resultados;
        }

        $urls = array_keys($redirecciones);
        try {
            $respuestas = Http::pool(fn (Pool $pool) => array_map(
                static fn (string $url) => $pool->timeout(8)->withOptions(['allow_redirects' => false])->get($url),
                $urls,
            ));
        } catch (Throwable $e) {
            report($e);

            return $resultados;
        }

        $destinos = [];
        foreach ($urls as $n => $url) {
            $respuesta = $respuestas[$n] ?? null;
            $destino = $respuesta instanceof Response ? trim((string) $respuesta->header('Location')) : '';
            if ($destino !== '') {
                $destinos[$url] = $destino;
            }
        }

        foreach ($resultados as $f => $fila) {
            if (! is_array($fila) || ! is_array($fila['opciones'] ?? null)) {
                continue;
            }
            foreach ($fila['opciones'] as $o => $opcion) {
                $url = is_array($opcion) ? trim((string) ($opcion['url'] ?? '')) : '';
                if (isset($destinos[$url])) {
                    $resultados[$f]['opciones'][$o]['url'] = $destinos[$url];
                }
            }
        }

        return $resultados;
    }

    private function esRedireccionGrounding(string $url): bool
    {
        $partes = parse_url($url);

        return strtolower((string) ($partes['host'] ?? '')) === 'vertexaisearch.cloud.google.com'
            && str_starts_with((string) ($partes['path'] ?? ''), '/grounding-api-redirect/');
    }

    public function sitioPermitido(string $url): ?string
    {
        $partes = parse_url($url);
        $esquema = strtolower((string) ($partes['scheme'] ?? ''));
        $host = strtolower((string) ($partes['host'] ?? ''));
        if (! in_array($esquema, ['http', 'https'], true) || $host === '') {
            return null;
        }

        foreach (self::SITIOS_WEB as $dominio => $nombre) {
            if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                return $nombre;
            }
        }

        return null;
    }

    /**
     * @param  array{sitio: string, titulo: string, precio_clp: int, unidades_por_pack: int, neto_unitario: int, url: string, fecha: string}  $ref
     */
    public function observacionReferencia(array $ref): string
    {
        $precio = '$'.number_format($ref['precio_clp'], 0, ',', '.');
        $neto = '$'.number_format($ref['neto_unitario'], 0, ',', '.');
        $pack = $ref['unidades_por_pack'] > 1 ? ' pack '.$ref['unidades_por_pack'].' un.' : '';
        $netoTxt = $ref['unidades_por_pack'] > 1 ? $neto.' neto c/u' : $neto.' neto';
        $stock = '';
        if (array_key_exists('stock_verificado', $ref)) {
            $stock = $ref['stock_verificado']
                ? ' - stock '.$ref['stock'].($ref['unidades_por_pack'] > 1 ? ' packs' : '')
                : ' - stock no verificado';
        }

        return "Ref. {$ref['sitio']} {$ref['fecha']}: {$ref['titulo']} - {$precio} c/IVA{$pack} ({$netoTxt}){$stock} - {$ref['url']}";
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}  $producto
     * @return array<string, mixed>
     */
    private function marcarVinculado(array $item, array $producto, string $origen): array
    {
        $item['estado'] = self::ESTADO_VINCULADO;
        $item['origen'] = $origen;
        $item['producto'] = $producto;
        $item['referencia'] = null;

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function itemParaRespuesta(array $item, int $indice): array
    {
        $producto = $item['producto'];
        $referencia = $item['referencia'];
        $costo = $producto === null
            ? 0
            : $this->busqueda->costoPropuesta($producto['prod_valor'], $producto['prod_valor_costo']);

        return [
            'indice' => $indice,
            'descripcion' => $item['descripcion'],
            'cantidad' => $item['cantidad'],
            'fuente' => $item['fuente'],
            'solicitante' => (string) ($item['solicitante'] ?? ''),
            'estado' => $item['estado'],
            'origen' => $item['origen'],
            'producto' => $producto === null ? null : [
                'prod_item' => $producto['prod_item'],
                'prod_nombre' => $producto['prod_nombre'],
                'costo' => $costo === PHP_INT_MAX ? 0 : $costo,
            ],
            'referencia' => $referencia,
            'stock_prisa' => $item['stock_prisa'] ?? null,
            'stock_nota' => $item['stock_nota'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{total: int, vinculados: int, por_ia: int, referencias_web: int, pendientes: int}
     */
    private function resumen(array $items): array
    {
        $r = ['total' => count($items), 'vinculados' => 0, 'por_ia' => 0, 'referencias_web' => 0, 'pendientes' => 0];
        foreach ($items as $item) {
            match ($item['estado']) {
                self::ESTADO_VINCULADO => $r['vinculados']++,
                self::ESTADO_REFERENCIA_WEB => $r['referencias_web']++,
                default => $r['pendientes']++,
            };
            if ($item['origen'] === self::ORIGEN_IA) {
                $r['por_ia']++;
            }
        }

        return $r;
    }

    /**
     * Índices de línea por solicitante, en el orden en que aparece cada uno.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, list<int>>
     */
    private function grupos(array $items): array
    {
        $grupos = [];
        foreach ($items as $i => $item) {
            $solicitante = trim((string) ($item['solicitante'] ?? ''));
            $grupos[$solicitante !== '' ? $solicitante : self::SIN_SOLICITANTE][] = $i;
        }

        return $grupos;
    }

    private function instruccionSistema(): string
    {
        return 'Eres el asistente de cotizaciones de una comercializadora chilena que responde compras ágiles de Mercado Público '
            .'(insumos de aseo, oficina, ferretería, alimentos, etc.). Respondes siempre en español y solo con el JSON pedido.';
    }

    private function idAgileAdjunto(string $descripcion, ?int $posicion = null): string
    {
        $norm = $this->busqueda->normalizarTexto($descripcion);
        $hash = md5($norm !== '' ? $norm : $descripcion);

        return $posicion === null
            ? 'ia:'.substr($hash, 0, 46)
            : 'ia:'.substr($hash, 0, 40).'-'.$posicion;
    }

    private function etapa(int $paso, string $texto): void
    {
        $this->progreso = ['paso' => $paso, 'total' => self::TOTAL_ETAPAS, 'etapa' => $texto, 'detalle' => '', 'estado' => self::PROGRESO_EN_CURSO];
        $this->guardarProgreso();
    }

    private function detalle(string $texto): void
    {
        $this->progreso['detalle'] = $texto;
        $this->guardarProgreso();
    }

    private function guardarProgreso(): void
    {
        if ($this->progresoKey === null) {
            return;
        }
        try {
            Cache::put($this->progresoKey, $this->progreso, now()->addMinutes(self::PROGRESO_TTL_MINUTOS));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function progresoKey(string $usuario, string $progresoId): string
    {
        return 'cotizar_ia:progreso:'.md5(mb_strtolower($usuario)).':'.$progresoId;
    }

    private function cacheKey(string $usuario, string $token): string
    {
        return 'cotizar_ia:'.md5(mb_strtolower($usuario)).':'.$token;
    }
}
