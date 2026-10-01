<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Resuelve el código alfanumérico de OC (ej. 1411-2423-AG26) vía API clásica v1.
 * Compra Ágil v2 solo entrega id_orden_compra numérico; el código AG está en v1.
 *
 * Orden: listados OC v1 por fecha (+ CodigoProveedor) matcheando COT / nombre del proceso.
 * Detalle: ordenesdecompra.json?codigo=…-AGxx (FechaEnvio, etc.).
 * Nota: ?codigo={id_numerico} responde inválido (HTTP 500 Codigo 10300) — no usarlo.
 */
class MercadoPublicoOrdenCompraService
{
    /** MP redondea el IVA por línea: el total de la OC puede diferir en $1 del monto ganado. */
    public const TOLERANCIA_MONTO_OC = 1.5;

    /** @var array<string, array{total: ?float, total_neto: ?float}|null> */
    private array $totalesOcMemo = [];

    public function __construct(
        protected CompraAgilTextoParserService $parser,
    ) {}

    public function isConfigured(): bool
    {
        return trim((string) config('cotiz.mercadopublico.ticket', '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $payload  Detalle Compra Ágil v2
     */
    public function idOrdenCompraDesdePayload(array $payload): ?int
    {
        if (isset($payload['id_orden_compra']) && $payload['id_orden_compra'] !== null && $payload['id_orden_compra'] !== '') {
            return (int) $payload['id_orden_compra'];
        }

        $orden = $payload['orden_compra'] ?? null;
        if (is_array($orden) && isset($orden['id_orden_compra']) && $orden['id_orden_compra'] !== null && $orden['id_orden_compra'] !== '') {
            return (int) $orden['id_orden_compra'];
        }

        $proveedores = is_array($payload['proveedores_cotizando'] ?? null) ? $payload['proveedores_cotizando'] : [];
        foreach ($proveedores as $prov) {
            if (! is_array($prov)) {
                continue;
            }
            $esGanador = ! empty($prov['seleccion']['proveedor_seleccionado'])
                || (int) ($prov['proveedor_seleccionado'] ?? 0) === 1;
            if (! $esGanador) {
                continue;
            }
            if (isset($prov['id_oc']) && $prov['id_oc'] !== null && $prov['id_oc'] !== '') {
                return (int) $prov['id_oc'];
            }
        }

        return null;
    }

    /**
     * Título del proceso Compra Ágil (suele coincidir con Nombre de la OC cuando no trae el COT).
     *
     * @param  array<string, mixed>  $payload
     */
    public function nombreProcesoDesdePayload(array $payload): string
    {
        foreach (['nombre', 'descripcion', 'titulo', 'objeto'] as $key) {
            $valor = trim((string) ($payload[$key] ?? ''));
            if ($valor !== '') {
                // Primera línea / hasta salto si descripcion es larga.
                $primera = preg_split('/\R/u', $valor, 2)[0] ?? $valor;

                return trim((string) $primera);
            }
        }

        return '';
    }

    /**
     * Busca en API OC v1 el código AG vinculado al COT o al nombre del proceso.
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolverCodigoPorCotizacion(
        string $codigoCot,
        array $payload,
        ?string $rutGanador,
        ?int $maxLlamadas = null,
    ): ?string {
        $codigoCot = strtoupper(trim($codigoCot));
        if ($codigoCot === '' || ! $this->isConfigured()) {
            return null;
        }

        $idOrdenCompra = $this->idOrdenCompraDesdePayload($payload);
        if ($idOrdenCompra === null) {
            return null;
        }

        // MP v1 no acepta id numérico en ?codigo= (HTTP 500 "parámetros no válidos").
        // Resolver solo por listados de fecha + match COT / nombre.
        return $this->resolverCodigoDesdeReferencia(
            $codigoCot,
            $this->referenciaBusquedaDesdePayload($payload),
            $rutGanador,
            $this->nombreProcesoDesdePayload($payload),
            $this->montoGanadorDesdePayload($payload),
            $maxLlamadas,
        );
    }

    /**
     * Busca el código AG desde la fecha de referencia (adjudicación/último cambio) hasta hoy,
     * sin depender del detalle Compra Ágil v2 (sirve cuando v2 responde 502/503/504).
     */
    public function resolverCodigoDesdeReferencia(
        string $codigoCot,
        ?Carbon $referencia,
        ?string $rutGanador,
        ?string $nombreProceso = null,
        ?float $montoGanador = null,
        ?int $maxLlamadas = null,
        int $margenDias = 1,
    ): ?string {
        $codigoCot = strtoupper(trim($codigoCot));
        if ($codigoCot === '' || ! $this->isConfigured()) {
            return null;
        }

        return $this->buscarCodigoEnFechas(
            $codigoCot,
            $this->fechasBusquedaDesdeReferencia($referencia, $margenDias),
            $this->codigoProveedorMpParaRut($rutGanador),
            $nombreProceso !== '' ? $nombreProceso : null,
            $montoGanador,
            $this->fechasVentanaCorta($referencia, $margenDias),
            max(0, (int) config('cotiz.mercadopublico.oc_busqueda_dias_sin_proveedor', 2)),
            $maxLlamadas ?? max(1, (int) config('cotiz.mercadopublico.oc_busqueda_max_llamadas', 40)),
        );
    }

    /**
     * Pasada 1: COT exacto en todas las fechas (recientes primero).
     * Pasada 2: nombre/prefijo solo en la ventana corta y con el total de la OC
     * igual al monto ganado, para no tomar otra OC del mismo organismo.
     *
     * @param  list<string>  $fechas
     * @param  list<string>  $fechasSimilitud
     */
    private function buscarCodigoEnFechas(
        string $codigoCot,
        array $fechas,
        ?string $codigoProveedor,
        ?string $nombreProceso,
        ?float $montoGanador,
        array $fechasSimilitud,
        int $diasSinProveedor,
        ?int $maxLlamadas,
    ): ?string {
        $this->totalesOcMemo = [];
        $conProveedor = $codigoProveedor !== null && $codigoProveedor !== '';
        $permiteSimilitud = array_flip($fechasSimilitud);
        $listadosSimilitud = [];
        $llamadas = 0;
        $huboCuotaAgotada = false;

        foreach (array_values($fechas) as $i => $fechaDdmmaaaa) {
            $proveedores = [];
            if ($conProveedor) {
                $proveedores[] = $codigoProveedor;
            }
            if (! $conProveedor || $i < $diasSinProveedor) {
                $proveedores[] = null;
            }

            foreach ($proveedores as $proveedor) {
                try {
                    $listado = $this->listarOrdenesPorFechaConCache($fechaDdmmaaaa, $proveedor, $llamadas, $maxLlamadas);
                } catch (RuntimeException $e) {
                    $huboCuotaAgotada = true;
                    Log::warning('MercadoPublicoOrdenCompra: cuota/listado OC, se prueba otra fecha', [
                        'fecha' => $fechaDdmmaaaa,
                        'CodigoProveedor' => $proveedor,
                        'error' => mb_substr($e->getMessage(), 0, 160),
                    ]);

                    continue;
                }

                if ($listado === null) {
                    continue;
                }

                $codigo = $this->buscarCodigoPorTextoCot($listado, $codigoCot);
                if ($codigo !== null) {
                    return $codigo;
                }

                if (isset($permiteSimilitud[$fechaDdmmaaaa]) && $listado !== []) {
                    $listadosSimilitud[] = $listado;
                }
            }
        }

        foreach ($listadosSimilitud as $listado) {
            $codigo = $this->buscarCodigoEnListado($listado, $codigoCot, $nombreProceso, $montoGanador);
            if ($codigo !== null) {
                return $codigo;
            }
        }

        if ($maxLlamadas !== null && $llamadas >= $maxLlamadas) {
            Log::info('MercadoPublicoOrdenCompra: límite de llamadas OC alcanzado; se retoma en la próxima consulta', [
                'codigo_cot' => $codigoCot,
                'fechas' => count($fechas),
                'llamadas' => $llamadas,
            ]);
        }

        if ($huboCuotaAgotada) {
            throw new RuntimeException('Cuota diaria de Mercado Público agotada consultando órdenes de compra.');
        }

        return null;
    }

    /**
     * Ventana corta de fechas (dmY) centrada en una referencia (± radio), sin pasar de hoy.
     *
     * @return list<string>
     */
    public function fechasBusquedaVentana(?Carbon $referencia, int $radioDias = 3): array
    {
        $tz = (string) config('app.timezone', 'America/Santiago');
        $hoy = now()->timezone($tz)->startOfDay();
        $radio = max(0, min(7, $radioDias));
        $centro = ($referencia ?? $hoy)->copy()->timezone($tz)->startOfDay();

        $out = [];
        for ($d = -$radio; $d <= $radio; $d++) {
            $dia = $centro->copy()->addDays($d);
            if ($dia->greaterThan($hoy)) {
                continue;
            }
            $out[] = $dia->format('dmY');
        }

        // Preferir el día central y luego los más recientes.
        $out = array_values(array_unique($out));
        usort($out, static function (string $a, string $b) use ($centro): int {
            $da = Carbon::createFromFormat('dmY', $a, $centro->timezoneName)->startOfDay();
            $db = Carbon::createFromFormat('dmY', $b, $centro->timezoneName)->startOfDay();
            $cmp = $da->diffInDays($centro) <=> $db->diffInDays($centro);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $db->timestamp <=> $da->timestamp;
        });

        return $out;
    }

    /**
     * Busca código AG en listados OC v1 solo en las fechas dadas (p. ej. backfill ±3 días).
     *
     * @param  list<string>  $fechasDdmmaaaa
     */
    public function resolverCodigoEnFechas(
        string $codigoCot,
        array $fechasDdmmaaaa,
        ?string $rutGanador,
        ?string $nombreProceso = null,
        bool $omitirListadoSinProveedor = true,
        ?float $montoGanador = null,
    ): ?string {
        $codigoCot = strtoupper(trim($codigoCot));
        if ($codigoCot === '' || ! $this->isConfigured() || $fechasDdmmaaaa === []) {
            return null;
        }

        $fechas = array_values(array_filter(
            array_map(static fn ($f): string => trim((string) $f), $fechasDdmmaaaa),
            static fn (string $f): bool => $f !== '',
        ));

        return $this->buscarCodigoEnFechas(
            $codigoCot,
            $fechas,
            $this->codigoProveedorMpParaRut($rutGanador),
            $nombreProceso,
            $montoGanador,
            $fechas,
            $omitirListadoSinProveedor ? 0 : PHP_INT_MAX,
            null,
        );
    }

    /**
     * La API OC v1 no acepta id numérico en ?codigo= (responde 500 / parámetros inválidos).
     * Se mantiene por compatibilidad; siempre retorna null sin llamar a MP.
     */
    public function resolverCodigoAgPorIdOrdenCompra(int $idOrdenCompra): ?string
    {
        return null;
    }

    /**
     * Detalle OC v1 por código AG (`?codigo=`). Incluye Fechas.FechaEnvio.
     *
     * @return array{
     *     codigo: string,
     *     nombre: string,
     *     descripcion: string,
     *     fecha_envio: ?Carbon,
     *     fecha_creacion: ?Carbon,
     *     fecha_aceptacion: ?Carbon,
     *     estado: ?string,
     *     total: ?float,
     *     total_neto: ?float
     * }|null
     */
    public function obtenerDetallePorCodigo(string $codigoOc): ?array
    {
        $codigoOc = strtoupper(trim($codigoOc));
        if ($codigoOc === '' || ! $this->isConfigured()) {
            return null;
        }

        $ticket = trim((string) config('cotiz.mercadopublico.ticket'));
        $baseUrl = rtrim((string) config('cotiz.mercadopublico.oc_v1_base_url'), '/');

        try {
            $response = Http::connectTimeout(10)
                ->timeout(max(15, (int) config('cotiz.mercadopublico.api_timeout_segundos', 45)))
                ->acceptJson()
                ->get($baseUrl.'/ordenesdecompra.json', [
                    'codigo' => $codigoOc,
                    'ticket' => $ticket,
                ]);
        } catch (\Throwable $e) {
            Log::debug('MercadoPublicoOrdenCompra: error detalle OC v1', [
                'codigo' => $codigoOc,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return null;
        }

        if ($response->status() === 429) {
            throw new RuntimeException('Cuota diaria de Mercado Público agotada consultando detalle de orden de compra.');
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $listado = $json['Listado'] ?? [];
        if (! is_array($listado) || $listado === []) {
            return null;
        }

        $item = $listado[0];
        if (! is_array($item)) {
            return null;
        }

        $fechas = is_array($item['Fechas'] ?? null) ? $item['Fechas'] : [];

        return [
            'codigo' => strtoupper(trim((string) ($item['Codigo'] ?? $codigoOc))),
            'nombre' => trim((string) ($item['Nombre'] ?? '')),
            'descripcion' => trim((string) ($item['Descripcion'] ?? '')),
            'fecha_envio' => $this->parsearFechaMp((string) ($fechas['FechaEnvio'] ?? '')),
            'fecha_creacion' => $this->parsearFechaMp((string) ($fechas['FechaCreacion'] ?? '')),
            'fecha_aceptacion' => $this->parsearFechaMp((string) ($fechas['FechaAceptacion'] ?? '')),
            'estado' => ($e = trim((string) ($item['Estado'] ?? ''))) !== '' ? $e : null,
            'total' => isset($item['Total']) && is_numeric($item['Total']) ? (float) $item['Total'] : null,
            'total_neto' => isset($item['TotalNeto']) && is_numeric($item['TotalNeto']) ? (float) $item['TotalNeto'] : null,
        ];
    }

    /**
     * El total (o neto) de la OC coincide con el monto ganado. Sin monto no se puede confirmar.
     * Puede lanzar RuntimeException si se agota la cuota.
     */
    public function ocCoincideConMonto(string $codigoOc, ?float $montoGanador): bool
    {
        if ($montoGanador === null || $montoGanador <= 0) {
            return false;
        }

        $codigoOc = strtoupper(trim($codigoOc));
        if (! array_key_exists($codigoOc, $this->totalesOcMemo)) {
            $detalle = $this->obtenerDetallePorCodigo($codigoOc);
            $this->totalesOcMemo[$codigoOc] = $detalle === null ? null : [
                'total' => $detalle['total'],
                'total_neto' => $detalle['total_neto'],
            ];
        }

        $totales = $this->totalesOcMemo[$codigoOc];
        if ($totales === null) {
            return false;
        }

        foreach ([$totales['total'], $totales['total_neto']] as $valor) {
            if ($valor !== null && abs($valor - $montoGanador) <= self::TOLERANCIA_MONTO_OC) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> fechas ddmmaaaa
     *
     * @param  array<string, mixed>  $payload
     */
    public function fechasBusquedaDesdePayload(array $payload): array
    {
        return $this->fechasBusquedaDesdeReferencia($this->referenciaBusquedaDesdePayload($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function referenciaBusquedaDesdePayload(array $payload): ?Carbon
    {
        $fechas = is_array($payload['fechas'] ?? null) ? $payload['fechas'] : [];

        return $this->parsearFechaMp(
            (string) ($fechas['fecha_ultimo_cambio'] ?? $fechas['fecha_cierre'] ?? ''),
        );
    }

    /**
     * Todos los días desde (referencia − margen) hasta hoy, recientes primero,
     * acotado a oc_busqueda_max_dias_atras.
     *
     * @return list<string> fechas ddmmaaaa
     */
    public function fechasBusquedaDesdeReferencia(?Carbon $referencia, int $margenDias = 1): array
    {
        $tz = (string) config('app.timezone', 'America/Santiago');
        $hoy = now()->timezone($tz)->startOfDay();

        if ($referencia === null) {
            return [$hoy->format('dmY')];
        }

        $maxAtras = max(7, (int) config('cotiz.mercadopublico.oc_busqueda_max_dias_atras', 180));
        $limite = $hoy->copy()->subDays($maxAtras - 1);

        $inicio = $referencia->copy()->timezone($tz)->startOfDay()->subDays(max(0, $margenDias));
        if ($inicio->greaterThan($hoy)) {
            $inicio = $hoy->copy();
        }
        if ($inicio->lessThan($limite)) {
            $inicio = $limite;
        }

        $out = [];
        $cursor = $hoy->copy();
        while ($cursor->greaterThanOrEqualTo($inicio)) {
            $out[] = $cursor->format('dmY');
            $cursor->subDay();
        }

        return $out;
    }

    /**
     * Ventana corta (tramo post adjudicación + días recientes) donde se acepta
     * match por nombre/prefijo/monto además del COT exacto.
     *
     * @return list<string> fechas ddmmaaaa
     */
    private function fechasVentanaCorta(?Carbon $referencia, int $margenDias = 1): array
    {
        $tz = (string) config('app.timezone', 'America/Santiago');
        $hoy = now()->timezone($tz)->startOfDay();
        $maxDias = max(4, min(31, (int) config('cotiz.mercadopublico.oc_busqueda_max_dias', 31)));

        if ($referencia === null) {
            return [$hoy->format('dmY')];
        }

        $inicio = $referencia->copy()->timezone($tz)->startOfDay()->subDays(max(0, $margenDias));
        if ($inicio->greaterThan($hoy)) {
            $inicio = $hoy->copy();
        }

        $span = $inicio->diffInDays($hoy) + 1;
        $out = [];

        if ($span <= $maxDias) {
            $cursor = $inicio->copy();
            while ($cursor->lessThanOrEqualTo($hoy)) {
                $out[] = $cursor->format('dmY');
                $cursor->addDay();
            }

            // Preferir días recientes primero (la OC suele indexarse días después del envío).
            return array_values(array_reverse(array_unique($out)));
        }

        // OC puede emitirse semanas después del último cambio: tramo desde adjudicación + días recientes.
        $diasAdjudicacion = min(28, max(1, $maxDias - 1));
        $cursor = $inicio->copy();
        for ($i = 0; $i < $diasAdjudicacion && $cursor->lessThanOrEqualTo($hoy); $i++) {
            $out[] = $cursor->format('dmY');
            $cursor->addDay();
        }

        $diasRecientes = max(0, $maxDias - count($out));
        $cursor = $hoy->copy();
        for ($i = 0; $i < $diasRecientes; $i++) {
            $out[] = $cursor->format('dmY');
            $cursor->subDay();
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<array<string, mixed>>  $listado
     */
    public function buscarCodigoEnListado(
        array $listado,
        string $codigoCot,
        ?string $nombreProceso = null,
        ?float $montoGanador = null,
    ): ?string {
        $codigoCot = strtoupper(trim($codigoCot));

        $porCot = $this->buscarCodigoPorTextoCot($listado, $codigoCot);
        if ($porCot !== null) {
            return $porCot;
        }

        // Sin el COT en la OC, el nombre o el prefijo del organismo pueden apuntar a otra
        // compra del mismo organismo: solo se acepta si el total de la OC calza con el monto ganado.
        $porNombre = $this->buscarCodigoPorNombreProceso($listado, $codigoCot, $nombreProceso, $montoGanador);
        if ($porNombre !== null && $this->ocCoincideConMontoSinCuota($porNombre, $montoGanador)) {
            return $porNombre;
        }

        // Casos como COT «Materiales pedagogicos utp» ↔ OC «ARTICULOS PEDAGOGICOS UTP».
        $porPrefijo = $this->buscarCodigoPorPrefijoYSimilitud($listado, $codigoCot, $nombreProceso, $montoGanador);
        if ($porPrefijo !== null && $this->ocCoincideConMontoSinCuota($porPrefijo, $montoGanador)) {
            return $porPrefijo;
        }

        return null;
    }

    private function ocCoincideConMontoSinCuota(string $codigoOc, ?float $montoGanador): bool
    {
        try {
            return $this->ocCoincideConMonto($codigoOc, $montoGanador);
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * OC cuyo Nombre/Descripción contiene el código COT (ej. «compra ágil: 3482-95-COT26»).
     *
     * @param  list<array<string, mixed>>  $listado
     */
    public function buscarCodigoPorTextoCot(array $listado, string $codigoCot): ?string
    {
        $codigoCot = mb_strtolower(trim($codigoCot));
        if ($codigoCot === '') {
            return null;
        }

        foreach ($listado as $item) {
            if (! is_array($item)) {
                continue;
            }
            $texto = mb_strtolower(
                trim((string) ($item['Nombre'] ?? '')).' '.trim((string) ($item['Descripcion'] ?? '')),
            );
            if (str_contains($texto, $codigoCot)) {
                $codigo = $this->codigoAgDesdeItem($item);
                if ($codigo !== null) {
                    return $codigo;
                }
            }
        }

        return null;
    }

    /**
     * Fallback: Nombre de la OC igual o que contiene el nombre del proceso CA
     * (casos sin «compra ágil: COT» en el listado).
     *
     * @param  list<array<string, mixed>>  $listado
     */
    public function buscarCodigoPorNombreProceso(
        array $listado,
        string $codigoCot,
        ?string $nombreProceso,
        ?float $montoGanador = null,
    ): ?string {
        $nombreNorm = $this->normalizarNombreOc((string) ($nombreProceso ?? ''));
        if ($nombreNorm === '') {
            return null;
        }

        $matches = [];
        foreach ($listado as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemNombre = $this->normalizarNombreOc((string) ($item['Nombre'] ?? ''));
            if ($itemNombre === '') {
                continue;
            }
            $igual = $itemNombre === $nombreNorm
                || str_contains($itemNombre, $nombreNorm)
                || str_contains($nombreNorm, $itemNombre);
            if (! $igual) {
                continue;
            }
            $codigo = $this->codigoAgDesdeItem($item);
            if ($codigo !== null) {
                $matches[] = $codigo;
            }
        }

        $matches = array_values(array_unique($matches));
        if ($matches === []) {
            return null;
        }
        if (count($matches) === 1) {
            return $matches[0];
        }

        // Desambiguar por prefijo numérico compartido (4034-452-COT26 ↔ 4034-510-AG26).
        $prefix = explode('-', strtoupper(trim($codigoCot)))[0] ?? '';
        if ($prefix !== '' && preg_match('/^\d+$/', $prefix)) {
            $porPrefijo = array_values(array_filter(
                $matches,
                static fn (string $c): bool => str_starts_with($c, $prefix.'-'),
            ));
            if (count($porPrefijo) === 1) {
                return $porPrefijo[0];
            }
            if (count($porPrefijo) > 1 && $montoGanador !== null && $montoGanador > 0) {
                return $this->desambiguarCandidatosPorMonto($porPrefijo, $montoGanador);
            }
        }

        if ($montoGanador !== null && $montoGanador > 0) {
            return $this->desambiguarCandidatosPorMonto($matches, $montoGanador);
        }

        return null;
    }

    /**
     * Prefijo org del COT + tokens del nombre del proceso (y opcionalmente monto del ganador).
     *
     * @param  list<array<string, mixed>>  $listado
     */
    public function buscarCodigoPorPrefijoYSimilitud(
        array $listado,
        string $codigoCot,
        ?string $nombreProceso = null,
        ?float $montoGanador = null,
    ): ?string {
        $prefix = explode('-', strtoupper(trim($codigoCot)))[0] ?? '';
        if ($prefix === '' || ! preg_match('/^\d+$/', $prefix)) {
            return null;
        }

        $candidatos = [];
        foreach ($listado as $item) {
            if (! is_array($item)) {
                continue;
            }
            $codigo = $this->codigoAgDesdeItem($item);
            if ($codigo === null || ! str_starts_with($codigo, $prefix.'-')) {
                continue;
            }
            $candidatos[$codigo] = $item;
        }

        if ($candidatos === []) {
            return null;
        }

        $tokensProceso = $this->tokensNombreSignificativos((string) ($nombreProceso ?? ''));
        if ($tokensProceso !== []) {
            $mejores = [];
            $mejorScore = 0;
            foreach ($candidatos as $codigo => $item) {
                $tokensOc = $this->tokensNombreSignificativos((string) ($item['Nombre'] ?? ''));
                $score = count(array_intersect($tokensProceso, $tokensOc));
                if ($score > $mejorScore) {
                    $mejorScore = $score;
                    $mejores = [$codigo];
                } elseif ($score === $mejorScore && $score > 0) {
                    $mejores[] = $codigo;
                }
            }

            // Exige al menos 2 tokens en común (ej. pedagogicos + utp).
            if ($mejorScore >= 2 && count($mejores) === 1) {
                return $mejores[0];
            }

            if ($mejorScore >= 2 && count($mejores) > 1) {
                $candidatos = array_intersect_key($candidatos, array_flip($mejores));
            }
        }

        if ($montoGanador === null || $montoGanador <= 0) {
            return null;
        }

        return $this->desambiguarCandidatosPorMonto(array_keys($candidatos), $montoGanador);
    }

    /**
     * Monto total del proveedor seleccionado en payload Compra Ágil v2.
     *
     * @param  array<string, mixed>  $payload
     */
    public function montoGanadorDesdePayload(array $payload): ?float
    {
        $proveedores = is_array($payload['proveedores_cotizando'] ?? null)
            ? $payload['proveedores_cotizando']
            : [];

        foreach ($proveedores as $prov) {
            if (! is_array($prov)) {
                continue;
            }
            $esGanador = ! empty($prov['seleccion']['proveedor_seleccionado'])
                || (int) ($prov['proveedor_seleccionado'] ?? 0) === 1;
            if (! $esGanador) {
                continue;
            }
            if (isset($prov['monto_total']) && is_numeric($prov['monto_total'])) {
                return (float) $prov['monto_total'];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function tokensNombreSignificativos(string $nombre): array
    {
        $norm = $this->normalizarNombreOc($nombre);
        if ($norm === '') {
            return [];
        }

        $stop = [
            'de', 'del', 'la', 'las', 'los', 'el', 'y', 'e', 'o', 'u', 'a', 'en', 'para', 'por',
            'con', 'sin', 'al', 'un', 'una', 'unos', 'unas', 'orden', 'compra', 'generada',
            'invitacion', 'compra', 'agil', 'segun', 'detalle', 'anexo', 'adjunto',
        ];

        $parts = preg_split('/[^a-z0-9]+/u', $norm) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if (strlen($part) < 3 || in_array($part, $stop, true)) {
                continue;
            }
            $out[] = $part;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $codigosAg
     */
    private function desambiguarCandidatosPorMonto(array $codigosAg, float $montoGanador): ?string
    {
        $codigosAg = array_values(array_unique(array_filter($codigosAg)));
        if ($codigosAg === []) {
            return null;
        }

        // Evitar quemar cuota: máximo 5 detalles.
        $codigosAg = array_slice($codigosAg, 0, 5);
        $matches = [];
        foreach ($codigosAg as $codigo) {
            if ($this->ocCoincideConMontoSinCuota($codigo, $montoGanador)) {
                $matches[] = $codigo;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function normalizarNombreOc(string $nombre): string
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            return '';
        }

        $nombre = mb_strtolower($nombre);
        $nombre = strtr($nombre, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'Ü' => 'u', 'Ñ' => 'n',
        ]);
        $nombre = preg_replace('/\s+/u', ' ', $nombre) ?? $nombre;

        return trim($nombre);
    }

    public function codigoProveedorMpParaRut(?string $rut): ?string
    {
        if ($rut === null || trim($rut) === '') {
            return null;
        }

        $normalizado = preg_replace('/[^0-9kK]/', '', $this->parser->normalizarRut($rut)) ?? '';
        if ($normalizado === '') {
            return null;
        }

        $mapa = config('cotiz.mercadopublico.codigo_proveedor_por_rut', []);
        if (! is_array($mapa)) {
            return null;
        }

        foreach ($mapa as $rutConfig => $codigo) {
            $rutConfigNorm = preg_replace('/[^0-9kK]/', '', $this->parser->normalizarRut((string) $rutConfig)) ?? '';
            if ($rutConfigNorm !== '' && strcasecmp($rutConfigNorm, $normalizado) === 0) {
                $codigo = trim((string) $codigo);

                return $codigo !== '' ? $codigo : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function codigoAgDesdeItem(array $item): ?string
    {
        return $this->codigoAgSiValido((string) ($item['Codigo'] ?? ''));
    }

    private function codigoAgSiValido(string $codigo): ?string
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo !== '' && preg_match('/-\d+-AG\d+$/i', $codigo)) {
            return $codigo;
        }

        return null;
    }

    /**
     * Un día cerrado solo puede perder OC (pasan al día de su nuevo estado), nunca ganarlas:
     * su listado por proveedor se cachea y las consultas siguientes no gastan cuota.
     *
     * @return list<array<string, mixed>>|null null = sin caché y sin llamadas disponibles
     */
    private function listarOrdenesPorFechaConCache(
        string $fechaDdmmaaaa,
        ?string $codigoProveedor,
        int &$llamadas,
        ?int $maxLlamadas,
    ): ?array {
        $cacheKey = $this->cacheKeyListado($fechaDdmmaaaa, $codigoProveedor);
        if ($cacheKey !== null) {
            $cacheado = Cache::get($cacheKey);
            if (is_array($cacheado)) {
                return $cacheado;
            }
        }

        if ($maxLlamadas !== null && $llamadas >= $maxLlamadas) {
            return null;
        }

        $llamadas++;
        $listado = $this->listarOrdenesPorFecha($fechaDdmmaaaa, $codigoProveedor);
        if ($listado === null) {
            return [];
        }

        if ($cacheKey !== null) {
            $dias = (int) config('cotiz.mercadopublico.oc_listado_cache_dias', 30);
            Cache::put($cacheKey, $listado, now()->addDays($dias));
        }

        return $listado;
    }

    private function cacheKeyListado(string $fechaDdmmaaaa, ?string $codigoProveedor): ?string
    {
        if ($codigoProveedor === null || $codigoProveedor === ''
            || (int) config('cotiz.mercadopublico.oc_listado_cache_dias', 30) <= 0) {
            return null;
        }

        $tz = (string) config('app.timezone', 'America/Santiago');
        try {
            $fecha = Carbon::createFromFormat('dmY', $fechaDdmmaaaa, $tz)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        // Hoy y ayer pueden seguir recibiendo OC (indexación tardía en MP).
        if ($fecha->greaterThanOrEqualTo(now()->timezone($tz)->startOfDay()->subDay())) {
            return null;
        }

        return 'mp_oc_v1_listado:'.$fechaDdmmaaaa.':'.$codigoProveedor;
    }

    /**
     * @return list<array<string, mixed>>|null null = error HTTP / respuesta inválida
     */
    private function listarOrdenesPorFecha(string $fechaDdmmaaaa, ?string $codigoProveedor = null): ?array
    {
        $ticket = trim((string) config('cotiz.mercadopublico.ticket'));
        $baseUrl = rtrim((string) config('cotiz.mercadopublico.oc_v1_base_url'), '/');
        $query = [
            'fecha' => $fechaDdmmaaaa,
            'ticket' => $ticket,
        ];
        if ($codigoProveedor !== null && $codigoProveedor !== '') {
            $query['CodigoProveedor'] = $codigoProveedor;
        }

        try {
            $response = Http::connectTimeout(10)
                ->timeout(max(15, (int) config('cotiz.mercadopublico.api_timeout_segundos', 45)))
                ->acceptJson()
                ->get($baseUrl.'/ordenesdecompra.json', $query);
        } catch (\Throwable $e) {
            Log::debug('MercadoPublicoOrdenCompra: error listando OC v1', [
                'fecha' => $fechaDdmmaaaa,
                'CodigoProveedor' => $codigoProveedor,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return null;
        }

        if ($response->status() === 429) {
            throw new RuntimeException('Cuota diaria de Mercado Público agotada consultando órdenes de compra.');
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $listado = $json['Listado'] ?? [];

        return is_array($listado) ? $listado : [];
    }

    private function parsearFechaMp(string $valor): ?Carbon
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor, (string) config('app.timezone', 'America/Santiago'));
        } catch (\Throwable) {
            return null;
        }
    }
}
