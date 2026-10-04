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
use Illuminate\Support\Collection;
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

    /** Ven el consumo de tokens y su costo en la vista previa (subconjunto de USUARIOS_PERMITIDOS). */
    public const USUARIOS_VEN_CONSUMO = ['admin', 'pame'];

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

    public const ORIGEN_FOTO = 'ia_foto';

    public const ORIGEN_PRISA = 'prisa_busqueda';

    public const ORIGEN_PRISA_REFERENCIA = 'prisa_referencia';

    /** Dominio permitido => nombre visible. Incluye subdominios (articulo.mercadolibre.cl). */
    private const SITIOS_WEB = [
        'mercadolibre.cl' => 'Mercado Libre',
        'sodimac.cl' => 'Sodimac',
        'prisa.cl' => 'Prisa',
    ];

    private const IVA = 1.19;

    private const MAX_LINEAS = 120;

    private const CANDIDATOS_POR_LINEA = 20;

    private const MAX_UNIDADES_POR_SOLICITADO = 1000;

    private const FOTOS_POR_LINEA = 4;

    /** Fotos por línea sin equivalente que se revisan antes de buscar en la web. */
    private const FOTOS_RESCATE_POR_LINEA = 2;

    private const MAX_FOTO_BYTES = 2 * 1024 * 1024;

    // Request inline de Gemini ≤ 20 MB y base64 infla ~33%.
    private const PRESUPUESTO_FOTOS_BYTES = 12 * 1024 * 1024;

    private const LINEAS_POR_LLAMADA = 20;

    private const CACHE_TTL_MINUTOS = 60;

    private const CACHE_WEB_SIN_CUOTA = 'cotizar_ia:web_sin_cuota';

    private const SIN_SOLICITANTE = 'Sin solicitante indicado';

    /** @var list<string> */
    private array $avisos = [];

    private bool $iaSinCuota = false;

    private int $urlsWebDescartadas = 0;

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
        protected MercadoLibreApiService $mercadolibre,
        protected ImagenReferenciaWebService $imagenesWeb,
    ) {}

    public static function usuarioPermitido(?User $user): bool
    {
        $username = mb_strtolower(trim((string) $user?->username));

        return $username !== '' && in_array($username, self::USUARIOS_PERMITIDOS, true);
    }

    public static function usuarioVeConsumo(string $usuario): bool
    {
        return in_array(mb_strtolower(trim($usuario)), self::USUARIOS_VEN_CONSUMO, true);
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
        $this->gemini->reiniciarUso();

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
        $items = $this->vincularDesdeBusquedaPrisa($items);
        $this->etapa(7, $this->mercadolibre->configurado() && ! $this->busquedaWebSodimacHabilitada()
            ? 'Buscando referencias en Mercado Libre'
            : 'Buscando referencias en Mercado Libre / Sodimac');
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

        $venta = $this->factorVenta($nota, $regionMp);
        if ($venta['region'] === null) {
            $this->avisos[] = 'No se pudo determinar la región del organismo: el precio de venta usa el factor '
                .number_format($venta['factor'], 2, ',', '.').'. Revíselo antes de aplicar.';
        }

        $lineasActuales = NotaDetalle::query()->where('nronota', $nota->nronota)->count();
        $lineasActualesAgile = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->whereNotNull('prod_item_agile')
            ->where('prod_item_agile', '!=', '')
            ->count();

        $metricasIa = ['cotizar_ia_veces' => 0, 'cotizar_ia_aplicadas_veces' => 0];
        try {
            $metricasIa = app(OportunidadParaCotizarService::class)->registrarCotizarIaEjecucion($codigo);
        } catch (Throwable $e) {
            report($e);
        }

        return [
            'token' => $token,
            'codigo' => $codigo,
            'cotizar_ia_veces' => (int) ($metricasIa['cotizar_ia_veces'] ?? 0),
            'cotizar_ia_aplicadas_veces' => (int) ($metricasIa['cotizar_ia_aplicadas_veces'] ?? 0),
            'fuente' => $decision['fuente'],
            'fuente_motivo' => $decision['motivo'],
            'adjuntos_usados' => $decision['adjuntos_usados'],
            'adjuntos_disponibles' => array_map(static fn (array $a) => $a['nombre'], $adjuntos),
            'avisos' => array_values(array_unique($this->avisos)),
            'venta' => $venta,
            'lineas' => array_map(fn (array $item, int $i) => $this->itemParaRespuesta($item, $i, $venta['factor']), $items, array_keys($items)),
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
            'uso_ia' => self::usuarioVeConsumo($usuario) ? $this->gemini->resumenUso() : null,
        ];
    }

    /**
     * Con $separar y varios solicitantes, el primero queda en $nota y cada uno de los demás
     * en una copia (mismo código MP), con el solicitante en la observación del ejecutivo.
     *
     * @param  list<int>  $rechazados  índices cuyo vínculo/referencia el usuario descartó (quedan pendientes)
     * @return array{agregadas: int, vinculadas: int, referencias_web: int, pendientes: int, eliminadas: int, aprendidas: int, cotizaciones: list<array{nronota: int, solicitante: string, agregadas: int}>}
     */
    public function aplicar(Nota $nota, string $usuario, string $token, array $rechazados, bool $reemplazar, bool $separar = false, ?float $factorManual = null, array $prorratear = []): array
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
        $prorratear = array_fill_keys(array_map('intval', $prorratear), true);
        $items = $this->copiarImagenesReferencia($guardado['items'], $rechazados);

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

        $factor = $factorManual !== null && $factorManual > 0
            ? round($factorManual, 2)
            : $this->factorVenta($nota, $guardado['region'] ?? null)['factor'];

        $conteo = ['vinculadas' => 0, 'referencias_web' => 0, 'pendientes' => 0, 'agregadas' => 0, 'eliminadas' => 0];
        $paraAprender = [];
        $cotizaciones = [];

        DB::transaction(function () use ($nota, $usuario, $items, $grupos, $rechazados, $prorratear, $maeprods, $reemplazar, $factor, &$conteo, &$paraAprender, &$cotizaciones) {
            $n = 0;
            foreach ($grupos as $solicitante => $indices) {
                $solicitante = (string) $solicitante;
                $destino = $n === 0 ? $nota : $this->notaService->duplicar($nota, $usuario, false);

                $lote = [];
                foreach ($indices as $i) {
                    $lote[] = $this->lineaLote($items[$i], isset($rechazados[$i]), $maeprods, $factor, $conteo, isset($prorratear[$i]));
                    if (! isset($rechazados[$i]) && $items[$i]['estado'] === self::ESTADO_VINCULADO
                        && $items[$i]['origen'] === self::ORIGEN_IA && $maeprods->has($items[$i]['producto']['prod_item'])
                        && (int) ($items[$i]['producto']['unidades'] ?? 1) === 1) {
                        $paraAprender[] = [$items[$i], (int) $destino->nronota];
                    }
                }

                if ($n === 0 && $reemplazar) {
                    $conteo['eliminadas'] = $this->detalleService->eliminarTodasLineasAgile($destino);
                }
                $ordenInicial = max(1, (int) NotaDetalle::query()->where('nronota', $destino->nronota)->max('orden') + 1);
                $agregadas = $this->detalleService->agregarLineasImportacionLote($destino, $lote);
                $conteo['agregadas'] += $agregadas;
                $this->detalleService->aplicarFactorPrecioVenta($destino->fresh(), $factor, $usuario);
                $this->fijarPrecioMaestro($destino, $lote, $ordenInicial, $factor);

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

        $codigoNorm = strtoupper(trim($codigo));
        $metricasIa = [
            'cotizar_ia_veces' => 0,
            'cotizar_ia_aplicadas_veces' => 0,
        ];
        if ($codigoNorm !== '') {
            $oportunidades = app(OportunidadParaCotizarService::class);
            foreach ($cotizaciones as $c) {
                $nronotaAplic = (int) ($c['nronota'] ?? 0);
                if ($nronotaAplic <= 0) {
                    continue;
                }
                try {
                    $metricasIa = $oportunidades->registrarCotizarIaAplicacion(
                        $codigoNorm,
                        $nronotaAplic,
                        $usuario,
                        (int) ($c['agregadas'] ?? 0),
                    );
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $conteo + [
            'aprendidas' => $aprendidas,
            'cotizaciones' => $cotizaciones,
            'codigo' => $codigoNorm,
            'cotizar_ia_veces' => (int) ($metricasIa['cotizar_ia_veces'] ?? 0),
            'cotizar_ia_aplicadas_veces' => (int) ($metricasIa['cotizar_ia_aplicadas_veces'] ?? 0),
        ];
    }

    /**
     * Factor de precio de venta según la región del organismo (Metropolitana u otras); sin región,
     * el factor de la nota.
     *
     * @return array{factor: float, region: ?int, nombre_region: string}
     */
    private function factorVenta(Nota $nota, mixed $regionMp): array
    {
        $region = (int) ($nota->region ?: (is_numeric($regionMp) ? $regionMp : 0));
        $porRegion = CompraAgilRegionScope::factorPrecioVentaPorRegion($region > 0 ? $region : null);

        return [
            'factor' => $porRegion ?? round((float) ($nota->factor_precio_venta ?: config('cotiz.factor_precio_venta', 1.22)), 2),
            'region' => $porRegion !== null ? $region : null,
            'nombre_region' => $porRegion !== null ? CompraAgilRegionScope::nombreRegion($region) : '',
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<string, Maeprod>  $maeprods
     * @param  array<string, int>  $conteo
     * @return array<string, mixed>
     */
    private function lineaLote(array $item, bool $rechazado, $maeprods, float $factor, array &$conteo, bool $prorratearPack = false): array
    {
        $cantidadAgile = (int) $item['cantidad'];
        $base = [
            'cantidad' => $cantidadAgile,
            'prod_item_agile' => $item['id_agile'],
            'prod_descripcion_agile' => $item['descripcion'],
        ];

        if (! $rechazado && $item['estado'] === self::ESTADO_VINCULADO && $maeprods->has($item['producto']['prod_item'])) {
            /** @var Maeprod $mae */
            $mae = $maeprods->get($item['producto']['prod_item']);
            $conteo['vinculadas']++;
            $unidades = max(1, (int) ($item['producto']['unidades'] ?? 1));
            $valor = (int) ($mae->prod_valor ?? 0) * $unidades;
            $costoMaestro = (int) ($mae->prod_valor_costo ?? 0) * $unidades;
            $pack = (int) ($item['producto']['pack_maestro'] ?? 0);
            $solicitadas = max(1, (int) ($item['producto']['unidades_solicitud'] ?? 1));
            $notaPack = '';
            if ($pack > $solicitadas) {
                if ($prorratearPack) {
                    $valor = self::prorrateo($valor, $solicitadas, $pack);
                    $costoMaestro = self::prorrateo($costoMaestro, $solicitadas, $pack);
                } else {
                    $notaPack = " Sin prorrateo: precio del pack de {$pack} un. por cada unidad de la cantidad solicitada.";
                }
            }
            $precios = $this->preciosMaestro($valor, $costoMaestro, $factor);
            $observacion = trim(
                ($unidades > 1 ? NotaDetalleService::observacionPack($unidades, (string) $mae->prod_item) : '')
                .($pack > $solicitadas && $prorratearPack
                    ? 'Precio prorrateado: '.trim((string) $mae->prod_item)." viene en pack de {$pack}; se cotiza "
                        .($solicitadas > 1 ? "el pack de {$solicitadas} solicitado." : 'por unidad solicitada.')
                    : '')
                .$notaPack
                .(($item['producto']['foto'] ?? '') !== ''
                    ? ' Elegido por foto: se ve '.$item['producto']['foto'].' en la imagen de '.trim((string) $mae->prod_item).'.'
                    : ''),
            );

            return $base + [
                'prod_item' => (string) $mae->prod_item,
                'prod_valor' => $precios['precio_venta'],
                'prod_valor_costo' => $precios['costo'],
                'prod_nombre' => (string) $mae->prod_nombre,
            ] + ($observacion !== '' ? ['observacion' => $observacion] : []);
        }

        if (! $rechazado && $item['estado'] === self::ESTADO_REFERENCIA_WEB && is_array($item['referencia'] ?? null)) {
            $conteo['referencias_web']++;
            $ref = $item['referencia'];
            $pack = max(1, (int) ($ref['unidades_por_pack'] ?? 1));
            $solicitadas = max(1, (int) ($ref['unidades_solicitud'] ?? 1));
            $costo = (int) $ref['neto_unitario'];
            $notaPack = '';
            if ($pack > $solicitadas && ! $prorratearPack) {
                $costo = (int) round($costo * $pack / $solicitadas);
                $notaPack = " Sin prorrateo: precio del pack de {$pack} un. por cada unidad de la cantidad solicitada.";
            }

            return $base + [
                'pendiente' => true,
                'prod_valor_costo' => $costo,
                'observacion' => trim($this->observacionReferencia($ref).$notaPack),
            ] + (($ref['imagen_ref'] ?? '') !== '' ? ['imagen_ref' => $ref['imagen_ref']] : []);
        }

        $conteo['pendientes']++;

        return $base + ['pendiente' => true];
    }

    /**
     * Copia al bucket la foto de cada referencia web aceptada (Mercado Libre o Prisa).
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, true>  $rechazados
     * @return list<array<string, mixed>>
     */
    private function copiarImagenesReferencia(array $items, array $rechazados): array
    {
        foreach ($items as $i => $item) {
            if (isset($rechazados[$i]) || $item['estado'] !== self::ESTADO_REFERENCIA_WEB || ! is_array($item['referencia'] ?? null)) {
                continue;
            }
            $ref = $item['referencia'];
            $url = trim((string) ($ref['imagen_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $sitio = (string) ($ref['sitio'] ?? '');
            try {
                if ($sitio === self::SITIOS_WEB['prisa.cl']) {
                    $sku = trim((string) ($ref['sku'] ?? ''));
                    if ($sku === '' || ! ImagenReferenciaWebService::urlPermitidaPrisa($url)) {
                        continue;
                    }
                    $items[$i]['referencia']['imagen_ref'] = $this->imagenesWeb->guardarPrisa($url, $sku);
                } elseif ($sitio === self::SITIOS_WEB['mercadolibre.cl']
                    && preg_match('~/p/([A-Z]{3}\d+)~', (string) ($ref['url'] ?? ''), $id) === 1
                    && ImagenReferenciaWebService::urlPermitida($url)) {
                    $items[$i]['referencia']['imagen_ref'] = $this->imagenesWeb->guardar($url, $id[1]);
                }
            } catch (Throwable $e) {
                Log::warning('CotizarIa: no se copió la imagen de referencia web', [
                    'sitio' => $sitio,
                    'url' => $url,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $items;
    }

    /**
     * Con costo en el maestro coherente con su precio (entre la mitad del precio y el precio), venta =
     * costo × factor. Si el costo falta o está mal cargado (ej. $8 para un precio de $8.400), manda el
     * precio: costo = precio / factor Metropolitana; en la Metropolitana se cobra el precio del maestro
     * y en otras regiones costo × factor.
     *
     * @return array{costo: int, precio_venta: int, precio_rm: int}
     */
    private function preciosMaestro(int $valor, int $costoMaestro, float $factor): array
    {
        if ($costoMaestro > 0 && ($valor <= 0 || ($costoMaestro * 2 >= $valor && $costoMaestro <= $valor))) {
            return ['costo' => $costoMaestro, 'precio_venta' => (int) round($costoMaestro * $factor), 'precio_rm' => 0];
        }
        if ($valor > 0) {
            $costo = NotaDetalleService::costoDesdePrecioRm($valor);

            return [
                'costo' => $costo,
                'precio_venta' => $this->esFactorMetropolitana($factor) ? $valor : (int) round($costo * $factor),
                'precio_rm' => $valor,
            ];
        }

        return ['costo' => 0, 'precio_venta' => 0, 'precio_rm' => 0];
    }

    private function esFactorMetropolitana(float $factor): bool
    {
        return abs(round($factor, 2) - round((float) config('cotiz.factor_precio_venta_rm', 1.22), 2)) < 0.001;
    }

    /**
     * aplicarFactorPrecioVenta deja costo × factor; si ningún costo entero da exacto el precio del
     * maestro (ej. $330 / 1,22), se restituye el precio del maestro en las líneas vinculadas.
     *
     * @param  list<array<string, mixed>>  $lote
     */
    private function fijarPrecioMaestro(Nota $nota, array $lote, int $ordenInicial, float $factor): void
    {
        foreach ($lote as $k => $linea) {
            if (! empty($linea['pendiente']) || ! isset($linea['prod_item'])) {
                continue;
            }
            $precio = (int) $linea['prod_valor'];
            if ($precio <= 0 || (int) round((int) $linea['prod_valor_costo'] * round($factor, 2)) === $precio) {
                continue;
            }
            NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->where('orden', $ordenInicial + $k)
                ->where('prod_item', $linea['prod_item'])
                ->update(['prod_valor' => $precio]);
        }
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
            $respuesta = $this->gemini->generar($parts, ['json' => true, 'system' => $this->instruccionSistema(), 'etapa' => 'Lectura de adjuntos']);
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
                'generico' => '',
                'stock_prisa' => null,
                'stock_nota' => null,
                'medida_nota' => null,
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
            // Un vínculo guardado a un producto sin precio ni costo no sirve para cotizar: se busca otro.
            $porFrase = $this->conPrecioOCosto($this->aprendizaje->resolverProductoPorFrase($item['descripcion']));
            if ($porFrase !== null) {
                $items[$i] = $this->marcarVinculado($item, $this->prorratearPackMaestro($porFrase, $item['descripcion']), self::ORIGEN_FRASE);

                continue;
            }
            $exacto = $this->conPrecioOCosto($this->aprendizaje->buscarAprendidoExacto($item['descripcion']));
            if ($exacto !== null) {
                $items[$i] = $this->marcarVinculado($item, $this->prorratearPackMaestro($exacto, $item['descripcion']), self::ORIGEN_APRENDIDO);

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
            $candidatos[$i] = $this->candidatosMaeprod(
                $items[$i]['descripcion'],
                $this->terminosBusquedaMaestro($items[$i]['descripcion']),
            );
        }

        $resultado = $this->equivalenciasIa($items, $candidatos, true);
        $terminos2 = [];
        $equivalentesPorLinea = [];
        $porFoto = [];
        foreach ($paraIa as $i) {
            $generico = (string) ($resultado[$i]['generico'] ?? '');
            $items[$i]['generico'] = $generico;
            $equivalentesPorLinea[$i] = $this->equivalentesPorUnidades($candidatos[$i], $resultado[$i] ?? [], $items[$i]['descripcion']);
            $porFoto[$i] = $this->candidatosParaFoto($candidatos[$i], $resultado[$i] ?? [], $items[$i]['descripcion']);

            // La marca no importa: con el nombre genérico aparecen candidatos de otras marcas que pueden ser más baratos.
            $terminos = $this->esGenericoDistinto($generico, $items[$i]['descripcion']) ? [$generico] : [];
            if ($equivalentesPorLinea[$i] === []) {
                $terminos = array_merge($terminos, $resultado[$i]['busqueda'] ?? []);
            }
            if ($terminos !== []) {
                $terminos2[$i] = $terminos;
            }
        }

        $candidatos2 = [];
        if ($terminos2 !== [] && ! $this->iaSinCuota) {
            // Segunda pasada: nombre genérico sin marca y términos alternativos que sugirió la IA.
            $this->detalle('Segunda pasada con términos alternativos para '.count($terminos2).' línea(s)');
            $candidatos2 = [];
            foreach ($terminos2 as $i => $terminos) {
                $yaEnviados = array_fill_keys(array_keys($candidatos[$i]), true);
                $nuevos = array_diff_key(
                    $this->candidatosMaeprod($items[$i]['descripcion'], $terminos),
                    $yaEnviados,
                );
                if ($nuevos !== []) {
                    $candidatos2[$i] = $nuevos;
                }
            }

            $resultado2 = $candidatos2 === [] ? [] : $this->equivalenciasIa($items, $candidatos2, false);
            foreach ($candidatos2 as $i => $lista) {
                $equivalentesPorLinea[$i] += $this->equivalentesPorUnidades($lista, $resultado2[$i] ?? [], $items[$i]['descripcion']);
                $porFoto[$i] = ($porFoto[$i] ?? []) + $this->candidatosParaFoto($lista, $resultado2[$i] ?? [], $items[$i]['descripcion']);
            }
        }

        foreach ($equivalentesPorLinea as $i => $equivalentes) {
            $elegido = $this->elegirEquivalente($items[$i]['descripcion'], array_values($equivalentes));
            if ($elegido !== null) {
                $items[$i] = $this->marcarVinculado($items[$i], $elegido, self::ORIGEN_IA);
                $items[$i]['alternativas'] = $this->alternativas($equivalentes, $elegido);
            }
        }

        // Con equivalente ya elegido, solo vale la pena mirar la foto de los dudosos más baratos.
        foreach ($porFoto as $i => $lista) {
            if ($items[$i]['estado'] === self::ESTADO_VINCULADO) {
                $tope = $this->precioComparable($items[$i]['producto']);
                $porFoto[$i] = array_filter($lista, fn (array $p) => $this->precioComparable($p) < $tope);
            }
        }

        // Sin equivalente ni dudosos, el nombre del maestro puede ser ambiguo («25 UNIDADES» = 25 hojas):
        // se revisa la foto de los mejores candidatos antes de buscar fuera. Van al final para no quitar
        // cupo de fotos a los dudosos que marcó la IA.
        $rescate = [];
        foreach ($paraIa as $i) {
            if ($items[$i]['estado'] === self::ESTADO_VINCULADO || ($porFoto[$i] ?? []) !== []) {
                continue;
            }
            $lista = $this->candidatosRescateFoto(
                ($candidatos[$i] ?? []) + ($candidatos2[$i] ?? []),
                (string) $items[$i]['descripcion'],
            );
            if ($lista !== []) {
                $rescate[$i] = $lista;
            }
        }

        return $this->vincularPorFoto($items, array_filter($porFoto) + $rescate);
    }

    /**
     * Candidatos que la IA dejó para confirmar en la foto (el nombre no dice si trae lo exigido).
     *
     * @param  array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}>  $candidatos
     * @param  array{revisar_foto?: array<string, array{unidades: int, falta: string}>}  $resultado
     * @return array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int, unidades: int, falta: string}>
     */
    private function candidatosParaFoto(array $candidatos, array $resultado, string $descripcion): array
    {
        $revisar = $resultado['revisar_foto'] ?? [];
        $equivalentes = $this->equivalentesPorUnidades($candidatos, [
            'equivalentes' => array_map('strval', array_keys($revisar)),
            'unidades' => array_map(static fn (array $r) => $r['unidades'], $revisar),
        ], $descripcion);
        foreach ($equivalentes as $codigo => $producto) {
            $equivalentes[$codigo]['falta'] = $revisar[$codigo]['falta'];
        }

        return array_slice($equivalentes, 0, self::FOTOS_POR_LINEA, true);
    }

    /**
     * Mejores candidatos del buscador (de medida exacta o sin medida) para confirmar por foto
     * cuando la IA no encontró equivalente solo con los nombres.
     *
     * @param  array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}>  $candidatos
     * @return array<string, array<string, mixed>>
     */
    private function candidatosRescateFoto(array $candidatos, string $descripcion): array
    {
        $out = [];
        foreach ($candidatos as $codigo => $producto) {
            if ($this->conPrecioOCosto($producto) === null || $this->diferenciaMedida($descripcion, $producto['prod_nombre']) > 1e-9) {
                continue;
            }
            $out[$codigo] = $this->prorratearPackMaestro(['unidades' => 1] + $producto, $descripcion)
                + ['falta' => 'que sea el producto solicitado (tipo, medida, cantidad y color)'];
            if (count($out) >= self::FOTOS_RESCATE_POR_LINEA) {
                break;
            }
        }

        return $out;
    }

    /**
     * La IA mira la foto del maestro de cada candidato dudoso y confirma si trae lo que exige el
     * solicitado (ej. el cordón de un porta credencial). Los confirmados se vinculan con lo que se ve.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int, unidades: int, falta: string}>>  $porFoto
     * @return list<array<string, mixed>>
     */
    private function vincularPorFoto(array $items, array $porFoto): array
    {
        $maxFotos = (int) config('cotiz.gemini.max_fotos', 24);
        if ($porFoto === [] || $this->iaSinCuota || $maxFotos <= 0) {
            return $items;
        }

        $this->detalle('IA revisando fotos del maestro para '.count($porFoto).' línea(s)');
        $fotos = $this->descargarFotos($porFoto, $maxFotos);
        if ($fotos === []) {
            return $items;
        }

        $parts = [['text' => "Para cada producto solicitado se muestran fotos de productos del catálogo cuyo nombre no confirma lo que el solicitado exige.\n"
            .'Mirando SOLO la foto, indica si el producto trae lo indicado en «confirmar» y describe brevemente lo que se ve que lo demuestra. '
            .'Si la foto no es del producto (imagen genérica, logo, «sin imagen») o no se aprecia, coincide = false.'."\n\n"
            .'Responde SOLO JSON: {"resultados":[{"i":0,"codigo":"CODIGO","coincide":true,"se_ve":"cordón negro con mosquetón"}]}']];
        foreach ($fotos as $i => $porCodigo) {
            $parts[] = ['text' => "\nSolicitado i={$i}: {$items[$i]['descripcion']}"];
            foreach ($porCodigo as $codigo => $foto) {
                $parts[] = ['text' => "Candidato codigo={$codigo}: {$porFoto[$i][$codigo]['prod_nombre']}. Confirmar: {$porFoto[$i][$codigo]['falta']}"];
                $parts[] = ['inline_data' => ['mime_type' => $foto['mime'], 'data' => base64_encode($foto['body'])]];
            }
        }

        try {
            $respuesta = $this->gemini->generar($parts, ['json' => true, 'system' => $this->instruccionSistema(), 'etapa' => 'Revisión de fotos']);
        } catch (GeminiCuotaAgotadaException $e) {
            $this->iaSinCuota = true;
            $this->avisos[] = $e->getMessage().' No se revisaron las fotos del maestro.';

            return $items;
        } catch (RuntimeException $e) {
            Log::warning('CotizarIa: fallo revisando fotos', ['message' => $e->getMessage()]);
            $this->avisos[] = 'No se pudieron revisar las fotos del maestro: '.$e->getMessage();

            return $items;
        }

        $confirmados = [];
        $json = is_array($respuesta['json']) ? $respuesta['json'] : [];
        foreach ((array) ($json['resultados'] ?? []) as $fila) {
            if (! is_array($fila) || ! isset($fila['i']) || ($fila['coincide'] ?? false) !== true) {
                continue;
            }
            $i = (int) $fila['i'];
            $codigo = trim((string) ($fila['codigo'] ?? ''));
            if (! isset($fotos[$i][$codigo])) {
                continue;
            }
            $seVe = mb_substr(trim((string) ($fila['se_ve'] ?? '')), 0, 120);
            $producto = $porFoto[$i][$codigo];
            unset($producto['falta']);
            $confirmados[$i][$codigo] = $producto + ['foto' => $seVe !== '' ? $seVe : $porFoto[$i][$codigo]['falta']];
        }

        foreach ($confirmados as $i => $equivalentes) {
            $descripcion = (string) $items[$i]['descripcion'];
            $elegido = $this->elegirEquivalente($descripcion, array_values($equivalentes));
            if ($elegido === null) {
                continue;
            }
            $actual = $items[$i]['estado'] === self::ESTADO_VINCULADO ? $items[$i]['producto'] : null;
            if ($actual !== null) {
                $medidaElegido = $this->diferenciaMedida($descripcion, (string) $elegido['prod_nombre']);
                $medidaActual = $this->diferenciaMedida($descripcion, (string) $actual['prod_nombre']);
                if ($medidaElegido > $medidaActual + 1e-9
                    || ($medidaElegido >= $medidaActual - 1e-9 && $this->precioComparable($elegido) >= $this->precioComparable($actual))) {
                    continue;
                }
            }
            $alternativas = array_merge(
                $actual !== null ? [$actual] : [],
                $actual !== null ? $items[$i]['alternativas'] : [],
                $this->alternativas($equivalentes, $elegido),
            );
            $items[$i] = $this->marcarVinculado($items[$i], $elegido, self::ORIGEN_FOTO);
            $items[$i]['alternativas'] = $alternativas;
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, array<string, mixed>>>  $porFoto
     * @return array<int, array<string, array{mime: string, body: string}>>
     */
    private function descargarFotos(array $porFoto, int $maxFotos): array
    {
        $codigos = [];
        foreach ($porFoto as $porCodigo) {
            foreach (array_keys($porCodigo) as $codigo) {
                $codigos[(string) $codigo] = true;
            }
        }
        $maeprods = Maeprod::query()->whereIn('prod_item', array_keys($codigos))->get()
            ->keyBy(static fn (Maeprod $m) => trim((string) $m->prod_item));

        $urls = [];
        foreach ($porFoto as $i => $porCodigo) {
            foreach (array_keys($porCodigo) as $codigo) {
                $codigo = (string) $codigo;
                if (count($urls) >= $maxFotos) {
                    break 2;
                }
                if (isset($urls[$codigo]) || ! $maeprods->has($codigo)) {
                    continue;
                }
                $candidatas = array_map(
                    static fn (string $url) => str_replace(' ', '%20', $url),
                    $maeprods->get($codigo)->imageUrlCandidates(),
                );
                if ($candidatas !== []) {
                    $urls[$codigo] = $candidatas;
                }
            }
        }
        if ($urls === []) {
            return [];
        }

        $descargadas = [];
        try {
            $respuestas = Http::pool(function (Pool $pool) use ($urls) {
                $out = [];
                foreach ($urls as $codigo => $candidatas) {
                    foreach ($candidatas as $n => $url) {
                        $out[] = $pool->as($codigo.'|'.$n)->timeout(10)->get($url);
                    }
                }

                return $out;
            });
        } catch (Throwable $e) {
            report($e);

            return [];
        }
        foreach ($urls as $codigo => $candidatas) {
            foreach (array_keys($candidatas) as $n) {
                $resp = $respuestas[$codigo.'|'.$n] ?? null;
                if (! $resp instanceof Response || ! $resp->successful()) {
                    continue;
                }
                $mime = strtolower(trim(explode(';', (string) $resp->header('Content-Type'))[0]));
                $body = $resp->body();
                if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) && $body !== '' && strlen($body) <= self::MAX_FOTO_BYTES) {
                    $descargadas[$codigo] = ['mime' => $mime, 'body' => $body];
                    break;
                }
            }
        }

        $presupuesto = self::PRESUPUESTO_FOTOS_BYTES;
        $out = [];
        foreach ($porFoto as $i => $porCodigo) {
            foreach (array_keys($porCodigo) as $codigo) {
                $foto = $descargadas[(string) $codigo] ?? null;
                if ($foto === null || strlen($foto['body']) > $presupuesto) {
                    continue;
                }
                $presupuesto -= strlen($foto['body']);
                $out[$i][(string) $codigo] = $foto;
            }
        }

        return $out;
    }

    /**
     * Candidatos del maestro por búsqueda de similitud + filtros de atributos (medida, color, marca, familia).
     *
     * @param  list<string>  $terminos
     * @return array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}>
     */
    private function terminosBusquedaMaestro(string $descripcion): array
    {
        $descripcion = trim($descripcion);
        $vistos = [];
        $out = [];
        // Términos cortos primero: la descripción MP larga no debe agotar el cupo de candidatos.
        foreach (array_merge(
            $this->busqueda->terminosBusquedaVendedor($descripcion),
            $this->busqueda->terminosSinonimos($descripcion),
            $descripcion !== '' ? [$descripcion] : [],
        ) as $termino) {
            $termino = trim($termino);
            if ($termino === '') {
                continue;
            }
            $clave = mb_strtolower($termino);
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $out[] = $termino;
        }

        $maxTerminos = max(3, (int) config('cotiz.cotizar_ia.busqueda_max_terminos', 6));

        return array_slice($out, 0, $maxTerminos);
    }

    /**
     * @param  list<string>  $terminos
     * @return array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}>
     */
    private function candidatosMaeprod(string $descripcion, array $terminos): array
    {
        $maxTerminos = max(3, (int) config('cotiz.cotizar_ia.busqueda_max_terminos', 6));
        $porTermino = max(5, (int) config('cotiz.cotizar_ia.candidatos_por_termino', 12));
        $maxTotal = max(10, (int) config('cotiz.cotizar_ia.candidatos_por_linea', self::CANDIDATOS_POR_LINEA));

        $out = [];
        // null = productos conjunto (SET/JUEGO/KIT), que la similitud no encuentra porque SET es stopword.
        foreach (array_merge([null], array_slice($terminos, 0, $maxTerminos)) as $termino) {
            $termino = $termino === null ? null : trim((string) $termino);
            if ($termino === '') {
                continue;
            }
            try {
                $filas = $termino === null
                    ? $this->busqueda->buscarConjunto($descripcion, $porTermino)
                    : $this->busqueda->buscar($termino, null, $porTermino);
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
            }
        }

        $out = $this->ordenarCandidatosMaestro($descripcion, $out);
        if (count($out) <= $maxTotal) {
            return $out;
        }

        return array_slice($out, 0, $maxTotal, true);
    }

    /**
     * @param  array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}>  $candidatos
     * @return array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}>
     */
    private function ordenarCandidatosMaestro(string $descripcion, array $candidatos): array
    {
        if ($candidatos === []) {
            return [];
        }
        $conjunto = $this->busqueda->esConjunto($descripcion);
        uasort(
            $candidatos,
            fn (array $a, array $b) => [
                $conjunto && $this->busqueda->esConjunto($b['prod_nombre']),
                $this->busqueda->scoreSimilitudFila($descripcion, $b['prod_item'], $b['prod_nombre']),
            ] <=> [
                $conjunto && $this->busqueda->esConjunto($a['prod_nombre']),
                $this->busqueda->scoreSimilitudFila($descripcion, $a['prod_item'], $a['prod_nombre']),
            ],
        );

        return $candidatos;
    }

    /**
     * Familia, medida y color del filtro del maestro, sin marca: se cotiza el más barato de cualquier
     * marca. El color no distingue género (BLANCO = BLANCA): el filtro compartido compara literal y
     * descartaba «CARTULINA … BLANCA» para «CARTULINA BLANCO».
     */
    private function pasaFiltros(string $descripcion, string $nombreProducto): bool
    {
        $descripcion = $this->colorMasculino($descripcion);
        $nombreProducto = $this->colorMasculino($nombreProducto);

        return ! $this->busqueda->hayConflictoFamilia($descripcion, $nombreProducto)
            && $this->diferenciaMedida($descripcion, $nombreProducto) <= $this->toleranciaMedida()
            && $this->busqueda->coloresCompatibles($descripcion, $nombreProducto);
    }

    /**
     * Diferencia relativa de medida entre lo solicitado y el producto; 0 si coinciden o alguno no la indica.
     */
    private function diferenciaMedida(string $descripcion, string $nombreProducto): float
    {
        return $this->busqueda->diferenciaMedida($descripcion, $nombreProducto) ?? 0.0;
    }

    /**
     * Hasta cuánto puede diferir la medida (0,2 = 20 %) para cotizar la más cercana con aviso.
     */
    private function toleranciaMedida(): float
    {
        return max(0.0, (float) config('cotiz.tolerancia_medida', 0.2)) + 1e-9;
    }

    /**
     * Entre los equivalentes, los de medida exacta (o sin medida declarada); si ninguno, los de medida más cercana.
     * De ellos, el más barato.
     *
     * @template T of array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     *
     * @param  list<T>  $productos
     * @return ?T
     */
    private function elegirEquivalente(string $descripcion, array $productos): ?array
    {
        if ($productos === []) {
            return null;
        }
        $diferencias = array_map(fn (array $p) => $this->diferenciaMedida($descripcion, (string) $p['prod_nombre']), $productos);
        $menor = min($diferencias);

        return $this->elegirPorPrecio(array_values(array_filter(
            $productos,
            static fn (int $n) => $diferencias[$n] <= $menor + 1e-9,
            ARRAY_FILTER_USE_KEY,
        )));
    }

    /**
     * Aviso cuando el producto elegido tiene una medida distinta a la pedida.
     */
    private function notaMedida(string $descripcion, string $nombreProducto): ?string
    {
        if ($this->diferenciaMedida($descripcion, $nombreProducto) <= 1e-9) {
            return null;
        }
        $pedida = implode(' / ', $this->busqueda->medidasTexto($descripcion));
        $producto = implode(' / ', $this->busqueda->medidasTexto($nombreProducto));

        return "Medida distinta: se pidió {$pedida} y el producto es de {$producto}.";
    }

    private function esGenericoDistinto(string $generico, string $descripcion): bool
    {
        $generico = $this->busqueda->normalizarTexto($generico);

        return $generico !== '' && $generico !== $this->busqueda->normalizarTexto($descripcion);
    }

    /** Término para buscar la línea fuera del maestro: el nombre genérico sin marca si la IA lo dio. */
    private function terminoBusqueda(array $item): string
    {
        $terminos = $this->terminosBusquedaLinea($item);

        return $terminos[0] ?? (string) ($item['descripcion'] ?? '');
    }

    /**
     * Descripción, genérico (IA) y alternativas de cotiz.busqueda_equivalencias (Prisa antes que ML).
     *
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function terminosBusquedaLinea(array $item): array
    {
        $vistos = [];
        $out = [];
        foreach ([trim((string) ($item['descripcion'] ?? '')), trim((string) ($item['generico'] ?? ''))] as $base) {
            if ($base === '') {
                continue;
            }
            foreach (array_merge([$base], $this->busqueda->terminosSinonimos($base)) as $termino) {
                $termino = trim($termino);
                if ($termino === '') {
                    continue;
                }
                $clave = mb_strtolower($termino);
                if (! isset($vistos[$clave])) {
                    $vistos[$clave] = true;
                    $out[] = $termino;
                }
            }
        }

        return $out;
    }

    /**
     * Consultas para Mercado Libre: mismos términos que Prisa (descripción, genérico, equivalencias).
     *
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function terminosMercadoLibre(array $item): array
    {
        return $this->terminosBusquedaLinea($item);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function opcionesMercadoLibreDeLinea(array $item): array
    {
        $opciones = [];
        $urls = [];
        foreach ($this->terminosMercadoLibre($item) as $termino) {
            foreach ($this->mercadolibre->buscar($termino) as $opcion) {
                $url = is_array($opcion) ? trim((string) ($opcion['url'] ?? '')) : '';
                if ($url === '' || isset($urls[$url])) {
                    continue;
                }
                $urls[$url] = true;
                $opciones[] = $opcion;
                if (count($opciones) >= 5) {
                    return $opciones;
                }
            }
        }

        return $opciones;
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
     * @return array<int, array{equivalentes: list<string>, unidades: array<string, int>, revisar_foto: array<string, array{unidades: int, falta: string}>, busqueda: list<string>, generico: string}>
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
     * @param  array<int, array{equivalentes: list<string>, unidades: array<string, int>, revisar_foto: array<string, array{unidades: int, falta: string}>, busqueda: list<string>, generico: string}>  $resultado
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

        $prompt = "Actúas como un vendedor del catálogo Romulo/Prisa: tu trabajo es elegir códigos del maestro listados en candidatos, "
            .'no productos de otra categoría aunque la búsqueda textual comparta palabras. '
            .'Ejemplos de categorías distintas: pintura acrílica en set o tubo ≠ destacador/resaltador, '
            .'≠ marcador acrílico de pintar, ≠ regla/vaso acrílico; «neón» o «pastel» en la licitación '
            .'no convierte un set de pintura en destacadores. '
            .'Si piden set acrílicos con neón/pastel y hay en candidatos un set 6+6 o equivalente, preferirlo; '
            .'si no, un set estándar de la misma cantidad de colores y formato (ej. 12×12 ml) suele servir al comprador.\n'
            ."Para cada producto solicitado indica qué candidatos del catálogo sirven para cotizarlo, es decir, que cumplen la misma función para el comprador.\n"
            .'Criterio principal: misma función y mismo uso. Un producto de nombre o tipo distinto que cumple esa función es equivalente '
            .'(ej. para «cera en aerosol para auto» sirve «silicona de auto en aerosol»; para «renovador de neumáticos» sirve «renovador de goma»). '
            .'La marca NO importa: el producto de otra marca es equivalente aunque el solicitado nombre una marca. '
            .'Lo que SÍ diferencia son las dimensiones: tamaño, medida, capacidad, volumen, peso o gramaje. '
            .'Una medida algo distinta (ej. 65 mm por 70 mm, 360 cc por 400 cc) no descarta el candidato: inclúyelo, el sistema prefiere la medida exacta y avisa la diferencia. '
            .'Una medida claramente distinta (ej. 1 litro por 5 litros, carta por oficio, 10 cm por 1 m) no es equivalente. '
            .'No es equivalente si sirve para otra cosa (ej. limpiavidrios por desengrasante) o si el solicitado exige expresamente color o material y el candidato no lo cumple. '
            .'Packs y cajas: si el solicitado pide un pack de N unidades (ej. «pack 2U», «caja 100 unidades») y el catálogo tiene el mismo producto '
            .'en pack menor o por unidad, marca equivalente con unidades = redondeo hacia arriba de N / M (ej. piden caja 100: pack de 50 → unidades 2). '
            .'Si el solicitado es producto suelto (la cantidad «300» de la licitación no define el pack; ej. «pastilla inodoro») y el catálogo lo vende '
            .'en pack o caja de M unidades del mismo producto, también es equivalente con unidades = 1: el sistema prorratea precio y costo por unidad '
            .'(ej. «pastilla para inodoro» y catálogo «pastilla estanque 3 unidades»). En producto suelto sin pack en el catálogo, unidades = 1. '
            .'Un kit, set o combo del catálogo que trae el producto solicitado junto con otros artículos también es equivalente '
            .'(ej. para «lanyard porta credencial» sirven «pack 100 porta credenciales incluye 100 lanyard» y «lanyard + porta credencial»), '
            .'con las unidades calculadas por la cantidad del producto solicitado que trae el kit. '
            .'Si el solicitado y el candidato usan nombres distintos pero es el mismo artículo '
            .'(misma función, medida y formato), márcalo equivalente aunque no repitan las mismas palabras. '
            .'En papelería «N unidades» del catálogo suele contar hojas o pliegos, no la pieza menor: '
            .'«etiqueta 14 por hoja 25 unidades» son 25 hojas y equivale a «etiquetas 25 hojas» (unidades = 1). '
            .'Si el nombre no permite saber si coincide la cantidad o el color pedido, ponlo en "revisar_foto" en vez de descartarlo. '
            ."No consideres precio. Puedes marcar varios equivalentes.\n"
            .'Si un candidato es el mismo producto pero su nombre no dice si trae un accesorio o característica que el solicitado exige '
            .'(ej. cordón o lanyard, mosquetón, tapa, estuche, pilas, color), no lo marques como equivalente: ponlo en "revisar_foto" '
            .'con lo que falta confirmar (máximo '.self::FOTOS_POR_LINEA." por producto); se revisará en la foto del catálogo.\n"
            .$instruccionBusqueda."\n"
            .'En "generico" escribe el producto solicitado como nombre genérico para buscarlo en cualquier tienda: '
            .'sin marca, modelo ni nombre comercial, conservando tipo, medida, capacidad, formato y pack '
            ."(ej. «Lápiz grafito Faber-Castell HB caja 12» → «lápiz grafito HB caja 12 unidades»).\n"
            ."Usa solo códigos que aparezcan en los candidatos de ese producto.\n\n"
            .'Productos: '.json_encode($entrada, JSON_UNESCAPED_UNICODE)."\n\n"
            .'Responde SOLO JSON: {"resultados":[{"i":0,"equivalentes":[{"codigo":"CODIGO","unidades":1}],'
            .'"revisar_foto":[{"codigo":"CODIGO","unidades":1,"falta":"cordón"}],"busqueda":["termino"],"generico":"nombre genérico"}]}';

        try {
            $respuesta = $this->gemini->generar([['text' => $prompt]], ['json' => true, 'system' => $this->instruccionSistemaVendedor(), 'etapa' => 'Equivalencias con el maestro']);
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
            $unidades = [];
            foreach ((array) ($fila['equivalentes'] ?? []) as $equivalente) {
                $codigo = trim((string) (is_array($equivalente) ? ($equivalente['codigo'] ?? '') : $equivalente));
                $n = is_array($equivalente) ? (int) ($equivalente['unidades'] ?? 1) : 1;
                if (isset($candidatos[$i][$codigo]) && $n <= self::MAX_UNIDADES_POR_SOLICITADO) {
                    $unidades[$codigo] = max(1, $n);
                }
            }
            $revisarFoto = [];
            foreach ((array) ($fila['revisar_foto'] ?? []) as $dudoso) {
                if (! is_array($dudoso)) {
                    continue;
                }
                $codigo = trim((string) ($dudoso['codigo'] ?? ''));
                $n = (int) ($dudoso['unidades'] ?? 1);
                $falta = mb_substr(trim((string) ($dudoso['falta'] ?? '')), 0, 80);
                if (isset($candidatos[$i][$codigo]) && ! isset($unidades[$codigo]) && $n <= self::MAX_UNIDADES_POR_SOLICITADO && $falta !== '') {
                    $revisarFoto[$codigo] = ['unidades' => max(1, $n), 'falta' => $falta];
                }
            }
            $resultado[$i] = [
                'equivalentes' => array_map('strval', array_keys($unidades)),
                'unidades' => $unidades,
                'revisar_foto' => $revisarFoto,
                'busqueda' => array_values(array_filter(
                    array_map(static fn ($t) => mb_substr(trim((string) $t), 0, 80), (array) ($fila['busqueda'] ?? [])),
                    static fn (string $t) => $t !== '',
                )),
                'generico' => is_string($fila['generico'] ?? null) ? mb_substr(trim($fila['generico']), 0, 200) : '',
            ];
        }

        return null;
    }

    /**
     * Equivalentes marcados por la IA con el precio por unidad solicitada: si el solicitado es un pack
     * de N y el producto del maestro va por unidad, valor y costo se multiplican por N; si el maestro
     * es un pack mayor que lo solicitado, se prorratean (ver prorratearPackMaestro).
     *
     * @param  array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}>  $candidatos
     * @param  array{equivalentes?: list<string>, unidades?: array<string, int>}  $resultado
     * @return array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int, unidades: int}>
     */
    private function equivalentesPorUnidades(array $candidatos, array $resultado, string $descripcion): array
    {
        $out = [];
        foreach ($resultado['equivalentes'] ?? [] as $codigo) {
            if (! isset($candidatos[$codigo])) {
                continue;
            }
            $n = max(1, (int) ($resultado['unidades'][$codigo] ?? 1));
            $producto = $candidatos[$codigo];
            $out[$codigo] = $n > 1
                ? [
                    'prod_valor' => $producto['prod_valor'] * $n,
                    'prod_valor_costo' => $producto['prod_valor_costo'] * $n,
                    'unidades' => $n,
                ] + $producto
                : $this->prorratearPackMaestro(['unidades' => 1] + $producto, $descripcion);
        }

        return $out;
    }

    /**
     * Si el producto del maestro es un pack mayor que lo solicitado (ej. «(100 UNIDADES)» cuando se
     * piden bolsas sueltas), valor y costo quedan por las unidades solicitadas: valor × S / M.
     *
     * @param  array<string, mixed>  $producto
     * @return array<string, mixed>
     */
    private function prorratearPackMaestro(array $producto, string $descripcion): array
    {
        if (max(1, (int) ($producto['unidades'] ?? 1)) > 1) {
            return $producto;
        }
        $pack = $this->mercadolibre->unidadesPorPack((string) ($producto['prod_nombre'] ?? ''));
        $solicitadas = $this->mercadolibre->unidadesPorPack($descripcion);
        if ($pack <= $solicitadas || self::pideMismoPackEnHojas($descripcion, $pack)) {
            return $producto;
        }

        $valorListado = (int) ($producto['prod_valor'] ?? 0);
        $costoListado = (int) ($producto['prod_valor_costo'] ?? 0);

        return [
            'prod_valor_listado' => $valorListado,
            'prod_valor_costo_listado' => $costoListado,
            'prod_valor' => self::prorrateo($valorListado, $solicitadas, $pack),
            'prod_valor_costo' => self::prorrateo($costoListado, $solicitadas, $pack),
            'pack_maestro' => $pack,
            'unidades_solicitud' => $solicitadas,
        ] + $producto;
    }

    /**
     * @param  ?array<string, mixed>  $producto
     * @return ?array<string, mixed>
     */
    private function conPrecioOCosto(?array $producto): ?array
    {
        return $producto !== null && ((int) ($producto['prod_valor'] ?? 0) > 0 || (int) ($producto['prod_valor_costo'] ?? 0) > 0)
            ? $producto
            : null;
    }

    /**
     * «25 hojas» pedido y maestro «… 25 UNIDADES»: las unidades del pack son las hojas, no se prorratea.
     */
    private static function pideMismoPackEnHojas(string $descripcion, int $pack): bool
    {
        return $pack > 1
            && preg_match('/\b'.$pack.'\s*(?:hojas?|pliegos?|l[áa]minas?|folios?)\b/iu', $descripcion) === 1;
    }

    private static function prorrateo(int $valor, int $solicitadas, int $pack): int
    {
        return $valor > 0 ? (int) ceil($valor * $solicitadas / $pack) : 0;
    }

    /**
     * El equivalente de menor precio del maestro (ya multiplicado por las unidades del pack). El costo
     * del maestro es solo referencia (ver precioComparable).
     *
     * @template T of array{prod_item: string, prod_valor: int, prod_valor_costo: int}
     *
     * @param  list<T>  $productos
     * @return ?T
     */
    private function elegirPorPrecio(array $productos): ?array
    {
        $mejor = null;
        $mejorPrecio = PHP_INT_MAX;
        foreach ($productos as $producto) {
            $precio = $this->precioComparable($producto);
            if ($precio < $mejorPrecio) {
                $mejorPrecio = $precio;
                $mejor = $producto;
            }
        }

        return $mejor;
    }

    /**
     * Orden para elegir: los productos con precio de venta van antes que cualquiera sin precio; entre
     * los sin precio se compara costo × factor Metropolitana; sin precio ni costo, nunca se eligen.
     *
     * @param  array{prod_valor?: int, prod_valor_costo?: int}  $producto
     */
    private function precioComparable(array $producto): int
    {
        $precio = (int) ($producto['prod_valor'] ?? 0);
        if ($precio > 0) {
            return $precio;
        }
        $costo = (int) ($producto['prod_valor_costo'] ?? 0);

        return $costo > 0
            ? intdiv(PHP_INT_MAX, 2) + (int) round($costo * round((float) config('cotiz.factor_precio_venta_rm', 1.22), 2))
            : PHP_INT_MAX;
    }

    /**
     * Otros equivalentes que marcó la IA, por si el elegido no tiene stock en Prisa.
     *
     * @param  array<string, array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int, unidades: int}>  $equivalentes
     * @param  array{prod_item: string}  $elegido
     * @return list<array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int, unidades: int}>
     */
    private function alternativas(array $equivalentes, array $elegido): array
    {
        return array_values(array_filter(
            $equivalentes,
            static fn (array $p) => $p['prod_item'] !== $elegido['prod_item'],
        ));
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
            $reemplazo = $this->elegirEquivalente((string) $items[$i]['descripcion'], $opciones);
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
            $items[$i]['medida_nota'] = null;
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
     * Líneas pendientes: busca en Prisa por descripción y vincula si el SKU existe en maeprod con stock.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function vincularDesdeBusquedaPrisa(array $items): array
    {
        if (! $this->prisa->busquedaTextoHabilitada()) {
            return $items;
        }

        $pendientes = array_keys(array_filter(
            $items,
            static fn (array $item) => $item['estado'] === self::ESTADO_PENDIENTE,
        ));
        if ($pendientes === []) {
            return $items;
        }

        $max = (int) config('cotiz.prisa.busqueda_texto_max_lineas', 40);
        if ($max <= 0) {
            return $items;
        }
        if (count($pendientes) > $max) {
            $this->avisos[] = "Prisa: se buscó por descripción solo en {$max} de ".count($pendientes).' línea(s) sin vínculo.';
            $pendientes = array_slice($pendientes, 0, $max);
        }

        $this->detalle('Buscando en Prisa por descripción para '.count($pendientes).' línea(s) sin vínculo al maestro');
        $conStock = [PrisaStockService::ESTADO_DISPONIBLE, PrisaStockService::ESTADO_ULTIMAS_UNIDADES];
        $vinculados = 0;
        $referencias = 0;
        $fallos = 0;

        foreach ($pendientes as $i) {
            $errorLinea = false;
            $vinculoLinea = false;
            $porSku = [];
            $terminosPrisa = array_values(array_unique(array_merge(
                $this->busqueda->terminosConjunto((string) ($items[$i]['descripcion'] ?? '')),
                $this->terminosBusquedaLinea($items[$i]),
            )));
            foreach ($terminosPrisa as $termino) {
                $resultados = $this->prisa->buscarPorTexto($termino);
                if ($resultados === false) {
                    $errorLinea = true;

                    continue;
                }
                foreach ($resultados as $fila) {
                    $porSku[$fila['sku']] = $fila;
                }
            }
            $resultados = array_values($porSku);
            if ($resultados !== []) {
                $skus = array_keys($porSku);
                $maeprods = Maeprod::query()->whereIn('prod_item', $skus)->get()->keyBy('prod_item');
                $stockPorSku = [];
                $candidatos = [];
                foreach ($resultados as $fila) {
                    if (! in_array($fila['stock_prisa']['estado'], $conStock, true)) {
                        continue;
                    }
                    if (! $maeprods->has($fila['sku'])) {
                        continue;
                    }
                    $producto = $this->conPrecioOCosto($this->productoDesdeFila($maeprods->get($fila['sku'])));
                    if ($producto === null || ! $this->pasaFiltros((string) $items[$i]['descripcion'], $producto['prod_nombre'])) {
                        continue;
                    }
                    $candidatos[] = $producto;
                    $stockPorSku[$producto['prod_item']] = $fila['stock_prisa'];
                }

                $elegido = $this->elegirEquivalente((string) $items[$i]['descripcion'], $candidatos);
                if ($elegido !== null) {
                    $items[$i] = $this->marcarVinculado(
                        $items[$i],
                        $this->prorratearPackMaestro($elegido, (string) $items[$i]['descripcion']),
                        self::ORIGEN_PRISA,
                    );
                    $items[$i]['stock_prisa'] = $stockPorSku[$elegido['prod_item']] ?? null;
                    $items[$i]['stock_nota'] = 'Prisa: vinculado por búsqueda ('.$elegido['prod_item'].').';
                    $vinculados++;
                    $vinculoLinea = true;
                }
            }

            if (! $vinculoLinea && $this->prisa->referenciaSinMaestroHabilitada() && $resultados !== []) {
                $opciones = $this->opcionesReferenciaPrisa($items[$i], $resultados);
                if ($opciones !== []) {
                    $cantidad = max(1, (int) $items[$i]['cantidad']);
                    $unidadesSolicitud = min(
                        self::MAX_UNIDADES_POR_SOLICITADO,
                        $this->mercadolibre->unidadesPorPack((string) $items[$i]['descripcion']),
                    );
                    [$mejor] = $this->mejorReferencia((string) $items[$i]['descripcion'], $cantidad, $opciones, $unidadesSolicitud);
                    if ($mejor !== null) {
                        $items[$i]['estado'] = self::ESTADO_REFERENCIA_WEB;
                        $items[$i]['origen'] = self::ORIGEN_PRISA_REFERENCIA;
                        $items[$i]['referencia'] = $mejor;
                        $items[$i]['producto'] = null;
                        $items[$i]['medida_nota'] = $this->notaMedida((string) $items[$i]['descripcion'], $mejor['titulo']);
                        $items[$i]['stock_nota'] = 'Prisa: referencia sin producto en maestro (precio público).';
                        foreach ($resultados as $fila) {
                            if (($fila['stock_prisa']['url'] ?? '') === $mejor['url']) {
                                $items[$i]['stock_prisa'] = $fila['stock_prisa'];
                                break;
                            }
                        }
                        $referencias++;
                        $vinculoLinea = true;
                    }
                }
            }

            if ($errorLinea && ! $vinculoLinea) {
                $fallos++;
            }
        }

        if ($vinculados > 0) {
            $this->avisos[] = "Prisa: {$vinculados} línea(s) vinculadas al maestro por búsqueda por descripción.";
        }
        if ($referencias > 0) {
            $this->avisos[] = "Prisa: {$referencias} línea(s) con referencia web (sin maestro) antes de Mercado Libre.";
        }
        if ($fallos > 0) {
            $this->avisos[] = "No se pudo buscar en Prisa por descripción en {$fallos} línea(s).";
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array{sku: string, nombre: string, precio_clp: int, imagen_url: string, stock_prisa: array{estado: string, etiqueta: string, url: string}}>  $resultados
     * @return list<array<string, mixed>>
     */
    private function opcionesReferenciaPrisa(array $item, array $resultados): array
    {
        $conStock = [PrisaStockService::ESTADO_DISPONIBLE, PrisaStockService::ESTADO_ULTIMAS_UNIDADES];
        $opciones = [];
        $urls = [];
        foreach ($resultados as $fila) {
            if (! in_array($fila['stock_prisa']['estado'], $conStock, true)) {
                continue;
            }
            $precio = (int) ($fila['precio_clp'] ?? 0);
            $titulo = trim((string) ($fila['nombre'] ?? ''));
            if ($titulo === '') {
                $titulo = 'SKU '.$fila['sku'];
            }
            $url = trim((string) ($fila['stock_prisa']['url'] ?? ''));
            if ($precio <= 0 || $url === '' || isset($urls[$url])) {
                continue;
            }
            if (! $this->pasaFiltros((string) $item['descripcion'], $titulo)) {
                continue;
            }
            $urls[$url] = true;
            $opciones[] = [
                'titulo' => $titulo,
                'precio_clp' => $precio,
                'url' => $url,
                'sku' => $fila['sku'],
                'unidades_por_pack' => max(1, $this->mercadolibre->unidadesPorPack($titulo)),
                'stock_disponible' => null,
                'imagen_url' => trim((string) ($fila['imagen_url'] ?? '')),
            ];
        }

        return $opciones;
    }

    /**
     * Sin equivalente en el maestro: Mercado Libre por su API y, si queda pendiente,
     * publicaciones de Mercado Libre (y Sodimac si está habilitado) vía Google Search.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function buscarReferenciasWeb(array $items): array
    {
        $items = $this->medidaExactaEnMercadoLibre($items);
        $pendientes = [];
        foreach ($items as $i => $item) {
            if ($item['estado'] === self::ESTADO_PENDIENTE) {
                $pendientes[] = $i;
            }
        }
        $buscarGeminiWeb = ! $this->iaSinCuota
            && (bool) config('cotiz.gemini.busqueda_web', true)
            && ! Cache::has(self::CACHE_WEB_SIN_CUOTA);
        $buscarMl = $this->mercadolibre->configurado();
        $ejecutarGeminiGrounding = $buscarGeminiWeb;
        if ($pendientes === [] || (! $buscarMl && ! $ejecutarGeminiGrounding)) {
            if ($pendientes !== [] && ! $buscarMl && ! $this->iaSinCuota && config('cotiz.gemini.busqueda_web', true) && Cache::has(self::CACHE_WEB_SIN_CUOTA)) {
                $this->avisos[] = 'Búsqueda web en Mercado Libre sin cuota disponible por ahora; las líneas sin vínculo quedaron pendientes.';
            }

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

        $sinStockSuficiente = 0;
        $this->urlsWebDescartadas = 0;
        if ($buscarMl) {
            $items = $this->referenciasMercadoLibre($items, $pendientes, $sinStockSuficiente, $ejecutarGeminiGrounding);
            $pendientes = array_values(array_filter(
                $pendientes,
                static fn (int $i) => $items[$i]['estado'] === self::ESTADO_PENDIENTE,
            ));
        }
        if (! $ejecutarGeminiGrounding || $pendientes === []) {
            if ($pendientes !== [] && ! $ejecutarGeminiGrounding && Cache::has(self::CACHE_WEB_SIN_CUOTA)) {
                $this->avisos[] = 'Búsqueda web sin cuota disponible por ahora; las líneas sin vínculo quedaron pendientes.';
            }
            $this->avisarReferenciasWeb($items, $sinStockSuficiente, 0, 0);

            return $items;
        }

        $lotes = array_chunk($pendientes, max(1, (int) config('cotiz.gemini.lote_web', 10)));
        $lotesIlegibles = 0;
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
                $this->avisos[] = 'Búsqueda web sin cuota disponible por ahora; las líneas sin vínculo restantes quedaron pendientes.';

                break;
            } catch (RuntimeException $e) {
                Log::warning('CotizarIa: fallo en búsqueda web', ['message' => $e->getMessage(), 'tanda' => $n + 1]);
                $this->avisos[] = 'No se pudo buscar referencias web: '.$e->getMessage();

                break;
            }
        }

        $this->avisarReferenciasWeb($items, $sinStockSuficiente, $lotesIlegibles, count($lotes));

        return $items;
    }

    /**
     * Líneas vinculadas al maestro con otra medida: si Mercado Libre tiene el producto con la medida
     * pedida (declarada en el título), se cotiza ese en vez del maestro aproximado.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function medidaExactaEnMercadoLibre(array $items): array
    {
        $aproximados = array_keys(array_filter(
            $items,
            static fn (array $item) => $item['estado'] === self::ESTADO_VINCULADO && ($item['medida_nota'] ?? null) !== null,
        ));
        if ($aproximados === [] || ! $this->mercadolibre->configurado()) {
            return $items;
        }

        $this->detalle('Buscando en Mercado Libre la medida exacta para '.count($aproximados).' línea(s)');
        try {
            $opcionesPorLinea = [];
            foreach ($aproximados as $i) {
                $opcionesPorLinea[$i] = $this->opcionesMercadoLibreDeLinea($items[$i]);
            }
            $opcionesPorLinea = $this->descartarOtrosProductosWeb($items, $opcionesPorLinea);

            foreach ($aproximados as $i) {
                $descripcion = (string) $items[$i]['descripcion'];
                $exactas = array_values(array_filter(
                    $opcionesPorLinea[$i],
                    fn ($o) => is_array($o) && $this->busqueda->diferenciaMedida($descripcion, (string) ($o['titulo'] ?? '')) === 0.0,
                ));
                if ($exactas === []) {
                    continue;
                }
                $cantidad = max(1, (int) $items[$i]['cantidad']);
                $unidadesSolicitud = min(self::MAX_UNIDADES_POR_SOLICITADO, $this->mercadolibre->unidadesPorPack($descripcion));
                [$mejor] = $this->mejorReferencia($descripcion, $cantidad, $exactas, $unidadesSolicitud);
                if ($mejor === null) {
                    continue;
                }

                $maestro = $items[$i]['producto'];
                $items[$i]['estado'] = self::ESTADO_REFERENCIA_WEB;
                $items[$i]['origen'] = self::ORIGEN_WEB;
                $items[$i]['referencia'] = $mejor;
                $items[$i]['producto'] = null;
                $items[$i]['stock_prisa'] = null;
                $items[$i]['medida_nota'] = null;
                $nota = 'Maestro '.$maestro['prod_item'].' '.$maestro['prod_nombre'].' es de otra medida; se usó Mercado Libre con la medida pedida.';
                $previa = trim((string) ($items[$i]['stock_nota'] ?? ''));
                $items[$i]['stock_nota'] = $previa === '' ? $nota : $previa.' '.$nota;
            }
        } catch (RuntimeException $e) {
            Log::warning('CotizarIa: fallo buscando medida exacta en Mercado Libre', ['message' => $e->getMessage()]);
            $this->avisos[] = 'No se pudo buscar en Mercado Libre la medida exacta: '.$e->getMessage();
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $pendientes
     * @return list<array<string, mixed>>
     */
    private function referenciasMercadoLibre(array $items, array $pendientes, int &$sinStockSuficiente, bool $buscarGemini): array
    {
        $this->detalle('Buscando en Mercado Libre…');
        try {
            $opcionesPorLinea = [];
            foreach ($pendientes as $i) {
                $opcionesPorLinea[$i] = $this->opcionesMercadoLibreDeLinea($items[$i]);
            }
            $opcionesPorLinea = $this->descartarOtrosProductosWeb($items, $opcionesPorLinea);

            foreach ($pendientes as $i) {
                $cantidad = max(1, (int) $items[$i]['cantidad']);
                $unidadesSolicitud = min(self::MAX_UNIDADES_POR_SOLICITADO, $this->mercadolibre->unidadesPorPack((string) $items[$i]['descripcion']));
                [$mejor, $todasSinStock] = $this->mejorReferencia($items[$i]['descripcion'], $cantidad, $opcionesPorLinea[$i], $unidadesSolicitud);
                if ($mejor !== null) {
                    $items[$i]['estado'] = self::ESTADO_REFERENCIA_WEB;
                    $items[$i]['origen'] = self::ORIGEN_WEB;
                    $items[$i]['referencia'] = $mejor;
                    $items[$i]['medida_nota'] = $this->notaMedida((string) $items[$i]['descripcion'], $mejor['titulo']);
                } elseif ($todasSinStock && ! $buscarGemini) {
                    $sinStockSuficiente++;
                    $nota = "Mercado Libre: ninguna publicación tiene stock suficiente para {$cantidad} unidad(es).";
                    $previa = trim((string) ($items[$i]['stock_nota'] ?? ''));
                    $items[$i]['stock_nota'] = $previa === '' ? $nota : $previa.' '.$nota;
                }
            }
        } catch (RuntimeException $e) {
            Log::warning('CotizarIa: fallo en Mercado Libre', ['message' => $e->getMessage()]);
            $this->avisos[] = 'No se pudo buscar en Mercado Libre: '.$e->getMessage();
        }

        return $items;
    }

    /**
     * La IA marca qué publicaciones no son el mismo producto (otro tipo, función, medida o formato; la
     * marca no cuenta), para que el más barato no sea, por ejemplo, un repuesto o un producto distinto
     * con nombre parecido. Sin IA o sin respuesta legible se conservan todas.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<int, list<mixed>>  $opcionesPorLinea
     * @return array<int, list<mixed>>
     */
    private function descartarOtrosProductosWeb(array $items, array $opcionesPorLinea): array
    {
        $conOpciones = array_keys(array_filter($opcionesPorLinea, static fn (array $o) => $o !== []));
        if ($conOpciones === [] || $this->iaSinCuota) {
            return $opcionesPorLinea;
        }

        $this->detalle('IA revisando publicaciones de Mercado Libre para '.count($conOpciones).' línea(s)');
        foreach (array_chunk($conOpciones, self::LINEAS_POR_LLAMADA) as $bloque) {
            $entrada = [];
            foreach ($bloque as $i) {
                $publicaciones = [];
                foreach ($opcionesPorLinea[$i] as $n => $opcion) {
                    $publicaciones[] = ['n' => $n, 'titulo' => mb_substr(trim((string) (is_array($opcion) ? ($opcion['titulo'] ?? '') : '')), 0, 200)];
                }
                $entrada[] = ['i' => $i, 'solicitado' => $items[$i]['descripcion'], 'publicaciones' => $publicaciones];
            }

            $prompt = 'Para cada producto solicitado indica qué publicaciones NO son el mismo producto: otro tipo de producto, otra función, '
                .'medida o formato incompatible, o un repuesto/accesorio del producto. La marca NO importa: el mismo producto de otra marca sirve. '
                ."Un pack con otra cantidad de unidades del mismo producto sirve (el precio se ajusta por unidades).\n\n"
                .'Productos: '.json_encode($entrada, JSON_UNESCAPED_UNICODE)."\n\n"
                .'Responde SOLO JSON: {"resultados":[{"i":0,"descartar":[1,3]}]}';

            try {
                $respuesta = $this->gemini->generar([['text' => $prompt]], ['json' => true, 'system' => $this->instruccionSistema(), 'etapa' => 'Revisión Mercado Libre']);
            } catch (GeminiCuotaAgotadaException $e) {
                $this->iaSinCuota = true;
                $this->avisos[] = $e->getMessage().' No se revisaron con IA las publicaciones de Mercado Libre.';

                return $opcionesPorLinea;
            } catch (RuntimeException $e) {
                Log::warning('CotizarIa: fallo revisando publicaciones web', ['message' => $e->getMessage()]);

                continue;
            }

            $json = is_array($respuesta['json']) ? $respuesta['json'] : [];
            $enBloque = array_fill_keys($bloque, true);
            foreach ((array) ($json['resultados'] ?? []) as $fila) {
                if (! is_array($fila) || ! isset($fila['i'], $enBloque[(int) $fila['i']])) {
                    continue;
                }
                $i = (int) $fila['i'];
                $descartar = array_fill_keys(array_map('intval', array_filter((array) ($fila['descartar'] ?? []), 'is_numeric')), true);
                $opcionesPorLinea[$i] = array_values(array_filter(
                    $opcionesPorLinea[$i],
                    static fn (int $n) => ! isset($descartar[$n]),
                    ARRAY_FILTER_USE_KEY,
                ));
            }
        }

        return $opcionesPorLinea;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function avisarReferenciasWeb(array $items, int $sinStockSuficiente, int $lotesIlegibles, int $lotes): void
    {
        if ($lotesIlegibles > 0) {
            $donde = $this->busquedaWebSodimacHabilitada() ? 'Mercado Libre / Sodimac' : 'Mercado Libre';
            $this->avisos[] = $lotes > 1
                ? "La búsqueda en {$donde} no devolvió un resultado legible en {$lotesIlegibles} de {$lotes} tanda(s) (se reintentó); esas líneas quedaron pendientes."
                : "La búsqueda en {$donde} no devolvió un resultado legible (se reintentó); las líneas sin vínculo quedaron pendientes.";
        }
        if ($sinStockSuficiente > 0) {
            $this->avisos[] = "Mercado Libre / Sodimac: {$sinStockSuficiente} línea(s) sin publicaciones con stock suficiente para la cantidad pedida; quedaron pendientes.";
        }
        if ($this->urlsWebDescartadas > 0) {
            $this->avisos[] = "Se descartaron {$this->urlsWebDescartadas} publicación(es) web cuyo enlace no venía de la búsqueda de Google o no era la página de un producto.";
        }
        $noVerificadas = count(array_filter(
            $items,
            static fn (array $item) => $item['estado'] === self::ESTADO_REFERENCIA_WEB && ($item['referencia']['stock_verificado'] ?? true) === false,
        ));
        if ($noVerificadas > 0) {
            $this->avisos[] = "{$noVerificadas} referencia(s) web con stock no verificado; revíselas con «ver» antes de cotizar.";
        }
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
        $entrada = array_map(fn (int $i) => [
            'i' => $i,
            'solicitado' => $this->terminosMercadoLibre($items[$i])[0] ?? $this->terminoBusqueda($items[$i]),
            'cantidad' => (int) $items[$i]['cantidad'],
        ], $pendientes);

        $dominios = $this->busquedaWebSodimacHabilitada() ? 'mercadolibre.cl y sodimac.cl' : 'mercadolibre.cl';
        $prompt = 'Busca en Google cada producto SOLO en '.$dominios." (Chile).\n"
            .'Para cada uno devuelve hasta 5 publicaciones del mismo producto (mismo tipo, función, medida y formato; de cualquier marca), '
            ."priorizando las más baratas, con su precio actual en pesos chilenos IVA incluido.\n"
            ."Si la publicación vende un pack o caja, indica cuántas unidades trae en unidades_por_pack (si es unitario, 1).\n"
            ."En unidades_solicitud indica cuántas unidades trae UNO de los productos solicitados: si pide un pack de N (ej. «pack 2U», «set de 3», «caja de 12») es N; si pide un producto suelto, 1.\n"
            .'En stock_disponible indica cuántas unidades de la publicación (packs, si vende packs) muestra disponibles la página: '
            ."\"+50 disponibles\" = 50, \"Últimas 3\" = 3, agotado o sin stock = 0; si la página no lo muestra, null. No lo inventes.\n"
            ."Se necesita al menos la cantidad indicada: prioriza publicaciones con stock suficiente para esa cantidad.\n"
            ."En url copia exactamente el enlace del resultado de búsqueda de esa publicación (página del producto, no un listado ni una búsqueda); no armes ni inventes URLs ni precios. Si no encuentras, deja opciones vacío.\n\n"
            .'Productos: '.json_encode($entrada, JSON_UNESCAPED_UNICODE)."\n\n"
            .'Responde SOLO JSON: {"resultados":[{"i":0,"unidades_solicitud":1,"opciones":[{"sitio":"mercadolibre|sodimac","titulo":"","precio_clp":0,"unidades_por_pack":1,"stock_disponible":null,"url":""}]}]}';

        $opciones = [
            'json' => true,
            'google_search' => true,
            'modelo' => (string) config('cotiz.gemini.modelo_web', ''),
            'thinking_level' => (string) config('cotiz.gemini.thinking_web', ''),
            'etapa' => $this->busquedaWebSodimacHabilitada() ? 'Búsqueda web (Mercado Libre / Sodimac)' : 'Búsqueda web (Mercado Libre)',
        ];
        try {
            $respuesta = $this->gemini->generar([['text' => $prompt]], $opciones);
        } catch (GeminiRespuestaInvalidaException) {
            $respuesta = $this->gemini->generar([['text' => $prompt
                ."\n\nIMPORTANTE: tu respuesta anterior no era JSON válido. Responde únicamente el objeto JSON, sin texto antes ni después y sin bloques ```."]],
                $opciones);
        }

        $json = is_array($respuesta['json']) ? $respuesta['json'] : [];
        $json['resultados'] = $this->urlsDeBusquedaReal((array) ($json['resultados'] ?? []));
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
            $unidadesSolicitud = max(1, min(self::MAX_UNIDADES_POR_SOLICITADO, (int) ($fila['unidades_solicitud'] ?? 1)));
            [$mejor, $todasSinStock] = $this->mejorReferencia($items[$i]['descripcion'], $cantidad, (array) ($fila['opciones'] ?? []), $unidadesSolicitud);
            if ($mejor !== null) {
                $items[$i]['estado'] = self::ESTADO_REFERENCIA_WEB;
                $items[$i]['origen'] = self::ORIGEN_WEB;
                $items[$i]['referencia'] = $mejor;
                $items[$i]['medida_nota'] = $this->notaMedida((string) $items[$i]['descripcion'], $mejor['titulo']);
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
     * neto_unitario es el costo de UNO de los solicitados (con IVA si es Mercado Libre): si la línea pide un pack de
     * $unidadesSolicitud, es el costo de esas unidades.
     *
     * @param  list<mixed>  $opciones
     * @return array{0: ?array{sitio: string, titulo: string, precio_clp: int, unidades_por_pack: int, unidades_solicitud: int, neto_unitario: int, url: string, fecha: string, stock: ?int, stock_verificado: bool, imagen_url?: string}, 1: bool}
     */
    private function mejorReferencia(string $descripcion, int $cantidad, array $opciones, int $unidadesSolicitud = 1): array
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
            // En Mercado Libre el costo es el precio publicado, con IVA; en Sodimac, el neto.
            $conIva = $sitio === self::SITIOS_WEB['mercadolibre.cl'] || $sitio === self::SITIOS_WEB['prisa.cl'];
            $neto = (int) round($precio / ($conIva ? 1 : self::IVA) / $unidades * $unidadesSolicitud);
            if ($neto <= 0) {
                continue;
            }
            $validas++;

            $stockBruto = $opcion['stock_disponible'] ?? null;
            $stock = is_numeric($stockBruto) && (float) $stockBruto >= 0 ? (int) floor((float) $stockBruto) : null;
            if ($stock !== null && $stock < (int) ceil($cantidad * $unidadesSolicitud / $unidades)) {
                $insuficientes++;

                continue;
            }

            $candidata = [
                'sitio' => $sitio,
                'titulo' => $titulo,
                'precio_clp' => $precio,
                'unidades_por_pack' => $unidades,
                'unidades_solicitud' => $unidadesSolicitud,
                'neto_unitario' => $neto,
                'costo_con_iva' => $conIva,
                'url' => mb_substr($url, 0, 1000),
                'fecha' => now()->format('d-m-Y'),
                'stock' => $stock,
                'stock_verificado' => $stock !== null,
            ];
            $imagen = trim((string) ($opcion['imagen_url'] ?? ''));
            if (ImagenReferenciaWebService::urlReferenciaPermitida($imagen)) {
                $candidata['imagen_url'] = $imagen;
            }
            $sku = trim((string) ($opcion['sku'] ?? ''));
            if ($sku !== '') {
                $candidata['sku'] = $sku;
            }
            if ($stock !== null) {
                if ($this->referenciaMejor($descripcion, $candidata, $confirmada)) {
                    $confirmada = $candidata;
                }
            } elseif ($this->referenciaMejor($descripcion, $candidata, $desconocida)) {
                $desconocida = $candidata;
            }
        }

        // Con stock verificado gana, salvo que la otra tenga una medida más cercana a la pedida.
        $mejor = $confirmada ?? $desconocida;
        if ($confirmada !== null && $desconocida !== null
            && $this->diferenciaMedida($descripcion, $desconocida['titulo']) < $this->diferenciaMedida($descripcion, $confirmada['titulo']) - 1e-9) {
            $mejor = $desconocida;
        }

        return [$mejor, $validas > 0 && $insuficientes === $validas];
    }

    /**
     * Primero la medida más cercana a la pedida; a igual medida, el menor costo.
     *
     * @param  array{titulo: string, neto_unitario: int}  $candidata
     * @param  ?array{titulo: string, neto_unitario: int}  $actual
     */
    private function referenciaMejor(string $descripcion, array $candidata, ?array $actual): bool
    {
        if ($actual === null) {
            return true;
        }
        $medidaCandidata = $this->diferenciaMedida($descripcion, $candidata['titulo']);
        $medidaActual = $this->diferenciaMedida($descripcion, $actual['titulo']);
        if (abs($medidaCandidata - $medidaActual) > 1e-9) {
            return $medidaCandidata < $medidaActual;
        }

        return $candidata['neto_unitario'] < $actual['neto_unitario'];
    }

    /**
     * Solo se aceptan enlaces que vienen de la búsqueda de Google (vertexaisearch…/grounding-api-redirect/…),
     * reemplazados por su destino, y que sean la página de un producto. Las URLs que escribe el modelo
     * suelen ser inventadas o de publicaciones terminadas (Mercado Libre las manda a un listado), y el
     * servidor no puede comprobarlas: Mercado Libre y Sodimac bloquean las consultas automáticas.
     *
     * @param  list<mixed>  $resultados
     * @return list<mixed>
     */
    private function urlsDeBusquedaReal(array $resultados): array
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

        $destinos = [];
        if ($redirecciones !== []) {
            $urls = array_keys($redirecciones);
            try {
                $respuestas = Http::pool(fn (Pool $pool) => array_map(
                    static fn (string $url) => $pool->timeout(8)->withOptions(['allow_redirects' => false])->get($url),
                    $urls,
                ));
                foreach ($urls as $n => $url) {
                    $respuesta = $respuestas[$n] ?? null;
                    $destino = $respuesta instanceof Response ? trim((string) $respuesta->header('Location')) : '';
                    if ($destino !== '' && $this->esPaginaProducto($destino)) {
                        $destinos[$url] = $destino;
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        foreach ($resultados as $f => $fila) {
            if (! is_array($fila) || ! is_array($fila['opciones'] ?? null)) {
                continue;
            }
            foreach ($fila['opciones'] as $o => $opcion) {
                if (! is_array($opcion)) {
                    continue;
                }
                $url = trim((string) ($opcion['url'] ?? ''));
                if (isset($destinos[$url])) {
                    $resultados[$f]['opciones'][$o]['url'] = $destinos[$url];

                    continue;
                }
                if ($url !== '') {
                    $this->urlsWebDescartadas++;
                }
                $resultados[$f]['opciones'][$o]['url'] = '';
            }
        }

        return $resultados;
    }

    /**
     * Publicación con ID: Mercado Libre (articulo…/MLC-123…, …/p/MLC123, …/up/MLCU123) o Sodimac (…/product/123…).
     * Descarta listados, búsquedas y URLs de catálogo sin ID, que Mercado Libre convierte en una búsqueda.
     */
    public function esPaginaProducto(string $url): bool
    {
        $sitio = $this->sitioPermitido($url);
        $partes = parse_url($url);
        $host = strtolower((string) ($partes['host'] ?? ''));
        $path = (string) ($partes['path'] ?? '');

        return match ($sitio) {
            'Mercado Libre' => ($host === 'articulo.mercadolibre.cl' && preg_match('#^/MLC-?\d+#i', $path) === 1)
                || (in_array($host, ['mercadolibre.cl', 'www.mercadolibre.cl'], true) && preg_match('#/(p|up)/MLCU?\d+#i', $path) === 1),
            'Sodimac' => preg_match('#/product/\d+#i', $path) === 1,
            default => false,
        };
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
        if ($this->hostEsPrisa($host)) {
            return self::SITIOS_WEB['prisa.cl'];
        }

        return null;
    }

    private function hostEsPrisa(string $host): bool
    {
        $configHost = strtolower((string) parse_url((string) config('cotiz.prisa.base_url'), PHP_URL_HOST));
        if ($configHost === '') {
            return false;
        }

        return $host === $configHost || str_ends_with($host, '.'.$configHost);
    }

    /**
     * @param  array{sitio: string, titulo: string, precio_clp: int, unidades_por_pack: int, neto_unitario: int, url: string, fecha: string}  $ref
     */
    public function observacionReferencia(array $ref): string
    {
        $precio = '$'.number_format($ref['precio_clp'], 0, ',', '.');
        $neto = '$'.number_format($ref['neto_unitario'], 0, ',', '.');
        $pack = $ref['unidades_por_pack'] > 1 ? ' pack '.$ref['unidades_por_pack'].' un.' : '';
        $solicitud = (int) ($ref['unidades_solicitud'] ?? 1);
        $tipo = ($ref['costo_con_iva'] ?? false) ? 'costo c/IVA' : 'neto';
        $netoTxt = match (true) {
            $solicitud > 1 => $neto.' '.$tipo.' por pack de '.$solicitud,
            $ref['unidades_por_pack'] > 1 => $neto.' '.$tipo.' c/u',
            default => $neto.' '.$tipo,
        };
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
        $item['medida_nota'] = $this->notaMedida((string) $item['descripcion'], (string) $producto['prod_nombre']);

        return $item;
    }

    /**
     * costo y precio_venta por unidad solicitada, calculados igual que al aplicar (ver preciosMaestro).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function itemParaRespuesta(array $item, int $indice, float $factor): array
    {
        $producto = $item['producto'];
        $referencia = $item['referencia'];
        $costo = 0;
        $precioVenta = 0;
        $precioRm = 0;
        $puedeProrratear = false;
        $packTamano = 0;
        $unidadesSolicitud = 1;
        $costoProrrateado = 0;
        $costoPack = 0;
        $precioRmProrrateado = 0;
        $precioRmPack = 0;
        $precioListado = 0;
        $costoListado = 0;

        if ($producto !== null) {
            $unidadesSolicitud = max(1, (int) ($producto['unidades_solicitud'] ?? 1));
            $packTamano = (int) ($producto['pack_maestro'] ?? 0);
            $unidades = max(1, (int) ($producto['unidades'] ?? 1));
            $valorListado = (int) ($producto['prod_valor_listado'] ?? $producto['prod_valor']);
            $costoListadoMae = (int) ($producto['prod_valor_costo_listado'] ?? $producto['prod_valor_costo']);
            if ($packTamano > $unidadesSolicitud && $unidades === 1) {
                $puedeProrratear = true;
                $precioListado = $valorListado;
                $costoListado = $costoListadoMae;
                $valorUnit = self::prorrateo($valorListado, $unidadesSolicitud, $packTamano);
                $costoUnitMae = self::prorrateo($costoListadoMae, $unidadesSolicitud, $packTamano);
                $prorrateado = $this->preciosMaestro($valorUnit, $costoUnitMae, $factor);
                $porPack = $this->preciosMaestro($valorListado, $costoListadoMae, $factor);
                $costoProrrateado = $prorrateado['costo'];
                $precioRmProrrateado = $prorrateado['precio_rm'];
                $costoPack = $porPack['costo'];
                $precioRmPack = $porPack['precio_rm'];
            } else {
                $prorrateado = $this->preciosMaestro((int) $producto['prod_valor'], (int) $producto['prod_valor_costo'], $factor);
                $costoProrrateado = $prorrateado['costo'];
                $precioRmProrrateado = $prorrateado['precio_rm'];
                $costoPack = $costoProrrateado;
                $precioRmPack = $precioRmProrrateado;
            }
            // Vista previa por defecto (sin prorratear): precio/costo del pack completo × cantidad Agile.
            $costo = $puedeProrratear ? $costoPack : $costoProrrateado;
            $precioRm = $puedeProrratear ? $precioRmPack : $precioRmProrrateado;
            $precioVenta = $precioRm > 0 && $this->esFactorMetropolitana($factor)
                ? $precioRm
                : (int) round($costo * $factor);
        } elseif (is_array($referencia)) {
            $packTamano = max(1, (int) ($referencia['unidades_por_pack'] ?? 1));
            $unidadesSolicitud = max(1, (int) ($referencia['unidades_solicitud'] ?? 1));
            $costoProrrateado = (int) $referencia['neto_unitario'];
            $precioListado = (int) ($referencia['precio_clp'] ?? 0);
            $costoPack = $packTamano > $unidadesSolicitud
                ? (int) round($costoProrrateado * $packTamano / $unidadesSolicitud)
                : $costoProrrateado;
            $puedeProrratear = $packTamano > $unidadesSolicitud;
            $costoListado = $costoPack;
            $costo = $costoPack;
            $precioVenta = (int) round($costoPack * $factor);
            $referencia = $this->referenciaParaRespuesta($referencia);
        }

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
                'unidades' => max(1, (int) ($producto['unidades'] ?? 1)),
                'pack_maestro' => (int) ($producto['pack_maestro'] ?? 0),
                'unidades_solicitud' => max(1, (int) ($producto['unidades_solicitud'] ?? 1)),
                'foto' => (string) ($producto['foto'] ?? ''),
            ],
            'puede_prorratear' => $puedeProrratear,
            'pack_tamano' => $packTamano,
            'unidades_solicitud' => $unidadesSolicitud,
            'costo_prorrateado' => $costoProrrateado,
            'costo_pack' => $costoPack,
            'precio_rm_prorrateado' => $precioRmProrrateado,
            'precio_rm_pack' => $precioRmPack,
            'precio_listado' => $precioListado,
            'costo_listado' => $costoListado,
            'costo' => $costo,
            'costo_estimado' => $precioRm > 0,
            'precio_venta' => $precioVenta,
            'precio_rm' => $precioRm,
            'referencia' => $referencia,
            'stock_prisa' => $item['stock_prisa'] ?? null,
            'stock_nota' => $item['stock_nota'] ?? null,
            'medida_nota' => $item['medida_nota'] ?? null,
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

    private function instruccionSistemaVendedor(): string
    {
        return $this->instruccionSistema().' Al vincular con el maestro, razona como un vendedor con experiencia: '
            .'prioriza siempre un código del catálogo interno cuando cumpla la función; '
            .'descarta candidatos de otra familia de producto aunque su nombre repita «acrílico», «neón», «12 colores» u otras palabras genéricas.';
    }

    /**
     * @param  array<string, mixed>  $referencia
     * @return array<string, mixed>
     */
    private function referenciaParaRespuesta(array $referencia): array
    {
        $imagenRef = trim((string) ($referencia['imagen_ref'] ?? ''));
        $imagenUrl = trim((string) ($referencia['imagen_url'] ?? ''));
        $base = rtrim((string) config('products.image_base_url'), '/');
        if ($imagenRef !== '' && $base !== '') {
            $referencia['imagen_mostrar'] = $base.'/'.ltrim($imagenRef, '/');
        } elseif ($imagenUrl !== '') {
            $referencia['imagen_mostrar'] = $imagenUrl;
        }

        return $referencia;
    }

    private function busquedaWebSodimacHabilitada(): bool
    {
        return (bool) config('cotiz.gemini.busqueda_web_sodimac', false);
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
