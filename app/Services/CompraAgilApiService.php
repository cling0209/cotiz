<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CompraAgilApiService
{
    public function isConfigured(): bool
    {
        return trim((string) config('cotiz.mercadopublico.ticket', '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{items: array<int, array<string, mixed>>, paginacion: array<string, mixed>}
     */
    public function listarEnRegiones(array $paramsBase, ?array $regiones = null): array
    {
        $regiones = $regiones ?? CompraAgilRegionScope::regionesIncluidas();
        if ($regiones === []) {
            return ['items' => [], 'paginacion' => ['total_resultados' => 0, 'numero_pagina' => 1, 'total_paginas' => 1]];
        }

        $pageSize = min(50, max(1, (int) ($paramsBase['tamano_pagina'] ?? 15)));
        $pageNum = max(1, (int) ($paramsBase['numero_pagina'] ?? 1));
        $porRegion = max(2, (int) ceil($pageSize / count($regiones)) + 1);

        $itemsPorCodigo = [];
        $totalResultados = 0;

        foreach ($regiones as $region) {
            $params = array_merge($paramsBase, [
                'region' => (int) $region,
                'tamano_pagina' => $porRegion,
                'numero_pagina' => $pageNum,
            ]);
            unset($params['regiones']);

            $resultado = $this->listar($params);
            $pag = $resultado['paginacion'];
            $totalResultados += (int) ($pag['total_resultados'] ?? count($resultado['items']));

            foreach ($resultado['items'] as $item) {
                if (! is_array($item) || CompraAgilRegionScope::debeExcluirItem($item)) {
                    continue;
                }
                $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
                if ($codigo === '') {
                    continue;
                }
                $itemsPorCodigo[$codigo] = $item;
            }
        }

        $items = array_values($itemsPorCodigo);

        return [
            'items' => $items,
            'paginacion' => [
                'numero_pagina' => $pageNum,
                'tamano_pagina' => $pageSize,
                'total_resultados' => max(count($items), $totalResultados),
                'total_paginas' => max(1, (int) ceil(max(1, $totalResultados) / $pageSize)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{items: array<int, array<string, mixed>>, paginacion: array<string, mixed>}
     */
    public function listar(array $params = []): array
    {
        $payload = $this->request('GET', '/v2/compra-agil', $params);

        return [
            'items' => $payload['items'] ?? [],
            'paginacion' => $payload['paginacion'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detalle(string $codigo, bool $usarCache = true): array
    {
        $codigo = trim($codigo);
        if ($codigo === '') {
            throw new RuntimeException('Debe indicar el código de Compra Ágil.');
        }

        if (! $usarCache) {
            return $this->requestDetalle($codigo);
        }

        return Cache::remember(
            $this->detalleCacheKey($codigo),
            $this->detalleCacheTtl(),
            fn () => $this->requestDetalle($codigo),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestDetalle(string $codigo): array
    {
        return $this->request('GET', '/v2/compra-agil/'.rawurlencode($codigo));
    }

    private function detalleCacheKey(string $codigo): string
    {
        return 'compra_agil_detalle:'.strtoupper($codigo);
    }

    private function detalleCacheTtl(): int
    {
        return max(60, (int) config('cotiz.mercadopublico.detalle_cache_segundos', 3600));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $params = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.');
        }

        $baseUrl = rtrim((string) config('cotiz.mercadopublico.base_url'), '/');
        $ticket = trim((string) config('cotiz.mercadopublico.ticket'));
        $timeoutSeg = max(15, (int) config('cotiz.mercadopublico.api_timeout_segundos', 45));
        $connectTimeoutSeg = max(5, (int) config('cotiz.mercadopublico.api_connect_timeout_segundos', 15));

        try {
            $response = Http::connectTimeout($connectTimeoutSeg)
                ->timeout($timeoutSeg)
                ->withOptions([
                    'curl' => [
                        CURLOPT_TIMEOUT => $timeoutSeg,
                        CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeg,
                    ],
                ])
                ->withHeaders(['ticket' => $ticket])
                ->acceptJson()
                ->send($method, $baseUrl.$path, $method === 'GET' ? ['query' => $params] : ['json' => $params]);
        } catch (ConnectionException $e) {
            throw new RuntimeException($this->mensajeErrorConexion($e), 0, $e);
        } catch (RequestException $e) {
            throw new RuntimeException($this->mensajeErrorRequest($e), $e->getCode(), $e);
        } catch (\Throwable $e) {
            throw new RuntimeException('Error inesperado consultando Mercado Público: ' . mb_substr($e->getMessage(), 0, 200), 0, $e);
        }

        $status = $response->status();
        $json = $response->json();

        if ($status === 429) {
            throw new RuntimeException(
                'Cuota diaria de Mercado Público agotada desde el servidor (la sincronización u otras consultas pueden haberla consumido). Intente mañana o use pegar texto.',
            );
        }

        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Ticket de Mercado Público inválido o sin permisos.');
        }

        if ($status === 404) {
            throw new RuntimeException('No existe Compra Ágil con el código indicado.');
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->mensajeDesdeRespuesta($status, $json));
        }

        if (! is_array($json) || ($json['success'] ?? '') !== 'OK') {
            $mensaje = $this->extraerErrores($json);
            if ($this->mensajeIndicaCuotaAgotada($mensaje)) {
                throw new RuntimeException(
                    'Cuota diaria de Mercado Público agotada desde el servidor (la sincronización u otras consultas pueden haberla consumido). Intente mañana o use pegar texto.',
                );
            }

            throw new RuntimeException($mensaje ?: 'Respuesta inválida de Mercado Público.');
        }

        $payload = $json['payload'] ?? null;
        if (! is_array($payload)) {
            throw new RuntimeException('Respuesta vacía de Mercado Público.');
        }

        return $payload;
    }

    private function mensajeErrorConexion(ConnectionException $e): string
    {
        $detalle = trim($e->getMessage());
        $base = 'Timeout o error de conexión con Mercado Público.';

        if ($detalle !== '') {
            return $base.' Detalle: '.mb_substr($detalle, 0, 240).'. Reintente.';
        }

        return $base.' Reintente.';
    }

    private function mensajeErrorRequest(RequestException $e): string
    {
        $status = $e->response?->status();
        $msg = $this->mensajeDesdeRespuesta($status, $e->response?->json());
        $curl = trim($e->getMessage());

        if ($curl !== '' && ! str_contains($msg, $curl)) {
            $msg .= ' Detalle: '.mb_substr($curl, 0, 180);
        }

        return $msg;
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function mensajeDesdeRespuesta(?int $status, ?array $json): string
    {
        $msg = $this->extraerErrores($json);
        if ($msg !== '') {
            return $msg;
        }

        return match ($status) {
            400 => 'Parámetros inválidos para Mercado Público.',
            503 => 'Mercado Público no disponible temporalmente.',
            default => 'Error al consultar Mercado Público (HTTP '.($status ?? '?').').',
        };
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function extraerErrores(?array $json): string
    {
        if (! is_array($json)) {
            return '';
        }

        $errors = $json['errors'] ?? null;
        if (! is_array($errors)) {
            return '';
        }

        $partes = [];
        foreach ($errors as $err) {
            if (is_array($err) && ! empty($err['mensaje'])) {
                $partes[] = (string) $err['mensaje'];
            }
        }

        return implode(' ', $partes);
    }

    private function mensajeIndicaCuotaAgotada(string $mensaje): bool
    {
        $texto = mb_strtolower($mensaje);

        return str_contains($texto, 'cuota')
            || str_contains($texto, 'limite')
            || str_contains($texto, 'límite')
            || str_contains($texto, 'too many')
            || str_contains($texto, 'rate limit');
    }
}
