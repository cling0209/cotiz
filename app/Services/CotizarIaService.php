<?php

namespace App\Services;

use App\Enums\VinculoOrigen;
use App\Exceptions\GeminiCuotaAgotadaException;
use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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

    /** @var list<string> */
    private array $avisos = [];

    private bool $iaSinCuota = false;

    private const TOTAL_ETAPAS = 6;

    private ?string $progresoKey = null;

    /** @var array{paso: int, total: int, etapa: string, detalle: string} */
    private array $progreso = ['paso' => 0, 'total' => self::TOTAL_ETAPAS, 'etapa' => '', 'detalle' => ''];

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

        try {
            return $this->ejecutarPreview($nota, $usuario, $codigo);
        } finally {
            $this->gemini->observar(null);
            if ($this->progresoKey !== null) {
                Cache::forget($this->progresoKey);
            }
        }
    }

    /**
     * @return array{paso: int, total: int, etapa: string, detalle: string}|null
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

        $this->etapa(4, 'Vinculando '.count($items).' línea(s) con frases y aprendidos');
        $items = $this->vincular($items);
        $this->etapa(6, 'Buscando referencias en Mercado Libre / Sodimac');
        $items = $this->buscarReferenciasWeb($items);

        $token = Str::random(32);
        Cache::put($this->cacheKey($usuario, $token), [
            'codigo' => $codigo,
            'items' => $items,
            'region' => $regionMp,
            'cabecera' => $cabeceraMp,
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
            'lineas_actuales' => $lineasActuales,
            'lineas_actuales_agile' => $lineasActualesAgile,
        ];
    }

    /**
     * @param  list<int>  $rechazados  índices cuyo vínculo/referencia el usuario descartó (quedan pendientes)
     * @return array{agregadas: int, vinculadas: int, referencias_web: int, pendientes: int, eliminadas: int, aprendidas: int}
     */
    public function aplicar(Nota $nota, string $usuario, string $token, array $rechazados, bool $reemplazar): array
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

        $lote = [];
        $conteo = ['vinculadas' => 0, 'referencias_web' => 0, 'pendientes' => 0];
        $paraAprender = [];

        foreach ($items as $i => $item) {
            $base = [
                'cantidad' => (int) $item['cantidad'],
                'prod_item_agile' => $item['id_agile'],
                'prod_descripcion_agile' => $item['descripcion'],
            ];
            $usar = ! isset($rechazados[$i]);

            if ($usar && $item['estado'] === self::ESTADO_VINCULADO && $maeprods->has($item['producto']['prod_item'])) {
                /** @var Maeprod $mae */
                $mae = $maeprods->get($item['producto']['prod_item']);
                $lote[] = $base + [
                    'prod_item' => (string) $mae->prod_item,
                    'prod_valor' => (int) ($mae->prod_valor ?? 0),
                    'prod_valor_costo' => (int) ($mae->prod_valor_costo ?? 0),
                    'prod_nombre' => (string) $mae->prod_nombre,
                ];
                $conteo['vinculadas']++;
                if ($item['origen'] === self::ORIGEN_IA) {
                    $paraAprender[] = $item;
                }

                continue;
            }

            if ($usar && $item['estado'] === self::ESTADO_REFERENCIA_WEB && is_array($item['referencia'] ?? null)) {
                $lote[] = $base + [
                    'pendiente' => true,
                    'prod_valor_costo' => (int) $item['referencia']['neto_unitario'],
                    'observacion' => $this->observacionReferencia($item['referencia']),
                ];
                $conteo['referencias_web']++;

                continue;
            }

            $lote[] = $base + ['pendiente' => true];
            $conteo['pendientes']++;
        }

        $eliminadas = $reemplazar ? $this->detalleService->eliminarTodasLineasAgile($nota) : 0;
        $agregadas = $this->detalleService->agregarLineasImportacionLote($nota, $lote);

        $region = (int) ($nota->region ?: ($guardado['region'] ?? 0));
        $factor = CompraAgilRegionScope::factorPrecioVentaPorRegion($region > 0 ? $region : null)
            ?? (float) ($nota->factor_precio_venta ?: config('cotiz.factor_precio_venta', 1.22));
        $this->detalleService->aplicarFactorPrecioVenta($nota->fresh(), $factor, $usuario);

        $aprendidas = 0;
        foreach ($paraAprender as $item) {
            try {
                $this->aprendizaje->guardarAprendizaje(
                    $item['descripcion'],
                    $item['producto']['prod_item'],
                    null,
                    $item['id_agile'],
                    $usuario,
                    VinculoOrigen::IA,
                    (int) $nota->nronota,
                );
                $aprendidas++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        Cache::forget($key);

        return $conteo + [
            'agregadas' => $agregadas,
            'eliminadas' => $eliminadas,
            'aprendidas' => $aprendidas,
        ];
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

Responde SOLO JSON:
{"fuente":"adjunto|cotizacion|ambos","motivo":"explicación breve en español","adjuntos_usados":["nombre archivo"],"lineas":[{"descripcion":"...","cantidad":1}]}
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
            ];
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
        ];
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
        ];
    }

    /**
     * @param  array{fuente: string, lineas_adjunto: list<array{descripcion: string, cantidad: int}>}  $decision
     * @param  list<array{id_agile: string, descripcion: string, cantidad: int}>  $lineasMp
     * @return list<array<string, mixed>>
     */
    private function armarItems(array $decision, array $lineasMp): array
    {
        $items = [];
        if ($decision['fuente'] !== self::FUENTE_ADJUNTO) {
            foreach ($lineasMp as $linea) {
                $items[] = $linea + ['fuente' => self::FUENTE_COTIZACION];
            }
        }
        foreach ($decision['lineas_adjunto'] as $linea) {
            $items[] = [
                'id_agile' => $this->idAgileAdjunto($linea['descripcion']),
                'descripcion' => $linea['descripcion'],
                'cantidad' => $linea['cantidad'],
                'fuente' => self::FUENTE_ADJUNTO,
            ];
        }

        foreach ($items as $i => $item) {
            $items[$i] += [
                'estado' => self::ESTADO_PENDIENTE,
                'origen' => null,
                'producto' => null,
                'referencia' => null,
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
                if (! $this->busqueda->pasaFiltrosAtributos($descripcion, $producto['prod_nombre'])) {
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
        $bloques = array_chunk(array_keys($candidatos), self::LINEAS_POR_LLAMADA);
        foreach ($bloques as $nBloque => $bloque) {
            if ($this->iaSinCuota) {
                break;
            }
            if (count($bloques) > 1) {
                $this->detalle('Lote '.($nBloque + 1).' de '.count($bloques));
            }

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
                break;
            } catch (RuntimeException $e) {
                Log::warning('CotizarIa: fallo en equivalencias', ['message' => $e->getMessage()]);
                $this->avisos[] = 'La IA no respondió para algunas líneas: '.$e->getMessage();

                continue;
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
        }

        return $resultado;
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
            $this->avisos[] = 'Búsqueda en Mercado Libre / Sodimac sin cuota gratuita por ahora; las líneas sin vínculo quedaron pendientes.';

            return $items;
        }

        $max = (int) config('cotiz.gemini.max_lineas_web', 10);
        if ($max <= 0) {
            return $items;
        }
        if (count($pendientes) > $max) {
            $this->avisos[] = "Se buscó referencia web solo para {$max} de ".count($pendientes).' líneas sin vínculo.';
            $pendientes = array_slice($pendientes, 0, $max);
        }

        $entrada = array_map(static fn (int $i) => [
            'i' => $i,
            'solicitado' => $items[$i]['descripcion'],
        ], $pendientes);

        $prompt = "Busca en Google cada producto SOLO en mercadolibre.cl y sodimac.cl (Chile).\n"
            ."Para cada uno devuelve hasta 3 publicaciones del mismo producto (mismo tipo, medida y formato) con su precio actual en pesos chilenos IVA incluido.\n"
            ."Si la publicación vende un pack o caja, indica cuántas unidades trae en unidades_por_pack (si es unitario, 1).\n"
            ."Usa solo URLs reales de páginas encontradas en la búsqueda; no inventes URLs ni precios. Si no encuentras, deja opciones vacío.\n\n"
            .'Productos: '.json_encode($entrada, JSON_UNESCAPED_UNICODE)."\n\n"
            .'Responde SOLO JSON: {"resultados":[{"i":0,"opciones":[{"sitio":"mercadolibre|sodimac","titulo":"","precio_clp":0,"unidades_por_pack":1,"url":""}]}]}';

        try {
            $respuesta = $this->gemini->generar([['text' => $prompt]], ['json' => true, 'google_search' => true]);
        } catch (GeminiCuotaAgotadaException) {
            Cache::put(self::CACHE_WEB_SIN_CUOTA, true, now()->addHour());
            $this->avisos[] = 'Búsqueda en Mercado Libre / Sodimac sin cuota gratuita por ahora; las líneas sin vínculo quedaron pendientes.';

            return $items;
        } catch (RuntimeException $e) {
            Log::warning('CotizarIa: fallo en búsqueda web', ['message' => $e->getMessage()]);
            $this->avisos[] = 'No se pudo buscar referencias web: '.$e->getMessage();

            return $items;
        }

        $json = is_array($respuesta['json']) ? $respuesta['json'] : [];
        $pendientesSet = array_fill_keys($pendientes, true);
        foreach ((array) ($json['resultados'] ?? []) as $fila) {
            if (! is_array($fila) || ! isset($fila['i'])) {
                continue;
            }
            $i = (int) $fila['i'];
            if (! isset($pendientesSet[$i])) {
                continue;
            }
            $mejor = $this->mejorReferencia($items[$i]['descripcion'], (array) ($fila['opciones'] ?? []));
            if ($mejor !== null) {
                $items[$i]['estado'] = self::ESTADO_REFERENCIA_WEB;
                $items[$i]['origen'] = self::ORIGEN_WEB;
                $items[$i]['referencia'] = $mejor;
            }
        }

        return $items;
    }

    /**
     * @param  list<mixed>  $opciones
     * @return ?array{sitio: string, titulo: string, precio_clp: int, unidades_por_pack: int, neto_unitario: int, url: string, fecha: string}
     */
    private function mejorReferencia(string $descripcion, array $opciones): ?array
    {
        $mejor = null;
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
            if (! $this->busqueda->pasaFiltrosAtributos($descripcion, $titulo)) {
                continue;
            }
            $unidades = max(1, (int) ($opcion['unidades_por_pack'] ?? 1));
            $neto = (int) round($precio / self::IVA / $unidades);
            if ($neto <= 0) {
                continue;
            }
            if ($mejor === null || $neto < $mejor['neto_unitario']) {
                $mejor = [
                    'sitio' => $sitio,
                    'titulo' => $titulo,
                    'precio_clp' => $precio,
                    'unidades_por_pack' => $unidades,
                    'neto_unitario' => $neto,
                    'url' => mb_substr($url, 0, 1000),
                    'fecha' => now()->format('d-m-Y'),
                ];
            }
        }

        return $mejor;
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

        return "Ref. {$ref['sitio']} {$ref['fecha']}: {$ref['titulo']} - {$precio} c/IVA{$pack} ({$netoTxt}) - {$ref['url']}";
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
            'estado' => $item['estado'],
            'origen' => $item['origen'],
            'producto' => $producto === null ? null : [
                'prod_item' => $producto['prod_item'],
                'prod_nombre' => $producto['prod_nombre'],
                'costo' => $costo === PHP_INT_MAX ? 0 : $costo,
            ],
            'referencia' => $referencia,
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

    private function instruccionSistema(): string
    {
        return 'Eres el asistente de cotizaciones de una comercializadora chilena que responde compras ágiles de Mercado Público '
            .'(insumos de aseo, oficina, ferretería, alimentos, etc.). Respondes siempre en español y solo con el JSON pedido.';
    }

    private function idAgileAdjunto(string $descripcion): string
    {
        $norm = $this->busqueda->normalizarTexto($descripcion);

        return 'ia:'.substr(md5($norm !== '' ? $norm : $descripcion), 0, 46);
    }

    private function etapa(int $paso, string $texto): void
    {
        $this->progreso = ['paso' => $paso, 'total' => self::TOTAL_ETAPAS, 'etapa' => $texto, 'detalle' => ''];
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
            Cache::put($this->progresoKey, $this->progreso, now()->addMinutes(10));
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
