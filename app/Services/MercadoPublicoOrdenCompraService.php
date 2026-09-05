<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Resuelve el código alfanumérico de OC (ej. 1411-2423-AG26) vía API clásica v1.
 * Compra Ágil v2 solo entrega id_orden_compra numérico; el código AG está en v1.
 *
 * Match: (1) texto COT en Nombre/Descripcion, (2) igualdad de nombre del proceso CA.
 * Detalle: ordenesdecompra.json?codigo=… (FechaEnvio, etc.). Path /{codigo}.json responde 404.
 */
class MercadoPublicoOrdenCompraService
{
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
    public function resolverCodigoPorCotizacion(string $codigoCot, array $payload, ?string $rutGanador): ?string
    {
        $codigoCot = strtoupper(trim($codigoCot));
        if ($codigoCot === '' || ! $this->isConfigured()) {
            return null;
        }

        if ($this->idOrdenCompraDesdePayload($payload) === null) {
            return null;
        }

        $codigoProveedor = $this->codigoProveedorMpParaRut($rutGanador);
        $nombreProceso = $this->nombreProcesoDesdePayload($payload);
        $huboCuotaAgotada = false;

        foreach ($this->fechasBusquedaDesdePayload($payload) as $fechaDdmmaaaa) {
            if ($codigoProveedor !== null && $codigoProveedor !== '') {
                try {
                    $listado = $this->listarOrdenesPorFecha($fechaDdmmaaaa, $codigoProveedor);
                } catch (RuntimeException $e) {
                    $huboCuotaAgotada = true;
                    Log::warning('MercadoPublicoOrdenCompra: cuota/listado OC, se prueba otra fecha', [
                        'fecha' => $fechaDdmmaaaa,
                        'CodigoProveedor' => $codigoProveedor,
                        'error' => mb_substr($e->getMessage(), 0, 160),
                    ]);
                    $listado = null;
                }
                if (is_array($listado)) {
                    $codigo = $this->buscarCodigoEnListado($listado, $codigoCot, $nombreProceso);
                    if ($codigo !== null) {
                        return $codigo;
                    }
                }
            }

            try {
                $listadoSinProveedor = $this->listarOrdenesPorFecha($fechaDdmmaaaa);
            } catch (RuntimeException $e) {
                $huboCuotaAgotada = true;
                Log::warning('MercadoPublicoOrdenCompra: cuota/listado OC sin proveedor, se prueba otra fecha', [
                    'fecha' => $fechaDdmmaaaa,
                    'error' => mb_substr($e->getMessage(), 0, 160),
                ]);

                continue;
            }

            $codigo = $this->buscarCodigoEnListado($listadoSinProveedor, $codigoCot, $nombreProceso);
            if ($codigo !== null) {
                return $codigo;
            }
        }

        if ($huboCuotaAgotada) {
            throw new RuntimeException('Cuota diaria de Mercado Público agotada consultando órdenes de compra.');
        }

        return null;
    }

    /**
     * Detalle OC v1 por código AG (`?codigo=`). Incluye Fechas.FechaEnvio.
     *
     * @return array{
     *     codigo: string,
     *     fecha_envio: ?Carbon,
     *     fecha_creacion: ?Carbon,
     *     fecha_aceptacion: ?Carbon,
     *     estado: ?string,
     *     total: ?float
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
            'fecha_envio' => $this->parsearFechaMp((string) ($fechas['FechaEnvio'] ?? '')),
            'fecha_creacion' => $this->parsearFechaMp((string) ($fechas['FechaCreacion'] ?? '')),
            'fecha_aceptacion' => $this->parsearFechaMp((string) ($fechas['FechaAceptacion'] ?? '')),
            'estado' => ($e = trim((string) ($item['Estado'] ?? ''))) !== '' ? $e : null,
            'total' => isset($item['Total']) && is_numeric($item['Total']) ? (float) $item['Total'] : null,
        ];
    }

    /**
     * @return list<string> fechas ddmmaaaa
     *
     * @param  array<string, mixed>  $payload
     */
    public function fechasBusquedaDesdePayload(array $payload): array
    {
        $fechas = is_array($payload['fechas'] ?? null) ? $payload['fechas'] : [];
        $referencia = $this->parsearFechaMp(
            (string) ($fechas['fecha_ultimo_cambio'] ?? $fechas['fecha_cierre'] ?? ''),
        );

        $tz = (string) config('app.timezone', 'America/Santiago');
        $hoy = now()->timezone($tz)->startOfDay();
        $maxDias = max(4, min(31, (int) config('cotiz.mercadopublico.oc_busqueda_max_dias', 31)));

        if ($referencia === null) {
            return [$hoy->format('dmY')];
        }

        $inicio = $referencia->copy()->timezone($tz)->startOfDay()->subDay();
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
    public function buscarCodigoEnListado(array $listado, string $codigoCot, ?string $nombreProceso = null): ?string
    {
        $codigoCot = strtoupper(trim($codigoCot));

        foreach ($listado as $item) {
            if (! is_array($item)) {
                continue;
            }
            $texto = mb_strtolower(
                trim((string) ($item['Nombre'] ?? '')).' '.trim((string) ($item['Descripcion'] ?? '')),
            );
            if ($codigoCot !== '' && str_contains($texto, mb_strtolower($codigoCot))) {
                $codigo = $this->codigoAgDesdeItem($item);
                if ($codigo !== null) {
                    return $codigo;
                }
            }
        }

        return $this->buscarCodigoPorNombreProceso($listado, $codigoCot, $nombreProceso);
    }

    /**
     * Fallback: Nombre de la OC igual al nombre del proceso CA (casos sin «compra ágil: COT» en el listado).
     *
     * @param  list<array<string, mixed>>  $listado
     */
    public function buscarCodigoPorNombreProceso(array $listado, string $codigoCot, ?string $nombreProceso): ?string
    {
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
            if ($itemNombre === '' || $itemNombre !== $nombreNorm) {
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
        }

        return null;
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
        $codigo = strtoupper(trim((string) ($item['Codigo'] ?? '')));
        if ($codigo !== '' && preg_match('/-\d+-AG\d+$/i', $codigo)) {
            return $codigo;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listarOrdenesPorFecha(string $fechaDdmmaaaa, ?string $codigoProveedor = null): array
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

            return [];
        }

        if ($response->status() === 429) {
            throw new RuntimeException('Cuota diaria de Mercado Público agotada consultando órdenes de compra.');
        }

        if (! $response->successful()) {
            return [];
        }

        $json = $response->json();
        if (! is_array($json)) {
            return [];
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
