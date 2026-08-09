<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Resuelve el código alfanumérico de OC (ej. 1411-2423-AG26) vía API clásica v1.
 * Compra Ágil v2 solo entrega id_orden_compra numérico; el código AG está en v1.
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

        return null;
    }

    /**
     * Busca en API OC v1 el código AG vinculado al COT (texto «compra ágil: {COT}»).
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
        if ($codigoProveedor === null || $codigoProveedor === '') {
            return null;
        }

        foreach ($this->fechasBusquedaDesdePayload($payload) as $fechaDdmmaaaa) {
            $listado = $this->listarOrdenesPorFechaYProveedor($fechaDdmmaaaa, $codigoProveedor);
            $codigo = $this->buscarCodigoEnListado($listado, $codigoCot);
            if ($codigo !== null) {
                return $codigo;
            }
        }

        return null;
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

        if ($referencia === null) {
            return [];
        }

        $tz = (string) config('app.timezone', 'America/Santiago');
        $out = [];
        foreach ([0, -1, 1, 2] as $offsetDias) {
            $out[] = $referencia->copy()->timezone($tz)->addDays($offsetDias)->format('dmY');
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<array<string, mixed>>  $listado
     */
    public function buscarCodigoEnListado(array $listado, string $codigoCot): ?string
    {
        foreach ($listado as $item) {
            if (! is_array($item)) {
                continue;
            }
            $texto = mb_strtolower(
                trim((string) ($item['Nombre'] ?? '')).' '.trim((string) ($item['Descripcion'] ?? '')),
            );
            if (! str_contains($texto, mb_strtolower($codigoCot))) {
                continue;
            }
            $codigo = strtoupper(trim((string) ($item['Codigo'] ?? '')));
            if ($codigo !== '' && preg_match('/-\d+-AG\d+$/i', $codigo)) {
                return $codigo;
            }
        }

        return null;
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
     * @return list<array<string, mixed>>
     */
    private function listarOrdenesPorFechaYProveedor(string $fechaDdmmaaaa, string $codigoProveedor): array
    {
        $ticket = trim((string) config('cotiz.mercadopublico.ticket'));
        $baseUrl = rtrim((string) config('cotiz.mercadopublico.oc_v1_base_url'), '/');

        try {
            $response = Http::connectTimeout(10)
                ->timeout(max(15, (int) config('cotiz.mercadopublico.api_timeout_segundos', 45)))
                ->acceptJson()
                ->get($baseUrl.'/ordenesdecompra.json', [
                    'fecha' => $fechaDdmmaaaa,
                    'CodigoProveedor' => $codigoProveedor,
                    'ticket' => $ticket,
                ]);
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
