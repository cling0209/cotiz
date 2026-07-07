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
    public function detalle(string $codigo, bool $usarCache = true, ?float $deadlineMicrotime = null): array
    {
        $codigo = trim($codigo);
        if ($codigo === '') {
            throw new RuntimeException('Debe indicar el código de Compra Ágil.');
        }

        if (! $usarCache) {
            return $this->requestDetalle($codigo, $deadlineMicrotime);
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
    private function requestDetalle(string $codigo, ?float $deadlineMicrotime = null): array
    {
        return $this->request('GET', '/v2/compra-agil/'.rawurlencode($codigo), [], $deadlineMicrotime);
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
    private function request(string $method, string $path, array $params = [], ?float $deadlineMicrotime = null): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.');
        }

        $maxReintentos = $deadlineMicrotime !== null
            ? 1
            : max(1, (int) config('cotiz.mercadopublico.api_reintentos_http', 3));
        $esperaSeg = max(1, (int) config('cotiz.mercadopublico.api_espera_reintento_seg', 5));
        $ultimoError = null;

        for ($intento = 1; $intento <= $maxReintentos; $intento++) {
            $this->assertAntesDeDeadlineHttp($deadlineMicrotime);

            try {
                $response = $this->enviarRequestHttp($method, $path, $params, $deadlineMicrotime);

                return $this->interpretarRespuestaHttp($response);
            } catch (RuntimeException $e) {
                $ultimoError = $e->getMessage();
                if ($this->esErrorDeadlineNota($ultimoError)) {
                    throw $e;
                }

                $recuperable = $e->getCode() === 1;

                if ($recuperable && $intento < $maxReintentos) {
                    if ($deadlineMicrotime !== null) {
                        $restante = $deadlineMicrotime - microtime(true);
                        if ($restante <= $esperaSeg) {
                            throw new RuntimeException(NotaMpResultadosService::mensajeTiempoMaximoNota());
                        }
                    }
                    sleep($esperaSeg);

                    continue;
                }

                throw $e;
            }
        }

        throw new RuntimeException($ultimoError ?? 'Error al consultar Mercado Público.');
    }

    private function assertAntesDeDeadlineHttp(?float $deadlineMicrotime): void
    {
        if ($deadlineMicrotime !== null && microtime(true) >= $deadlineMicrotime) {
            throw new RuntimeException(NotaMpResultadosService::mensajeTiempoMaximoNota());
        }
    }

    private function esErrorDeadlineNota(string $mensaje): bool
    {
        return str_contains($mensaje, NotaMpResultadosService::mensajeTiempoMaximoNota());
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function enviarRequestHttp(string $method, string $path, array $params = [], ?float $deadlineMicrotime = null): \Illuminate\Http\Client\Response
    {
        $baseUrl = rtrim((string) config('cotiz.mercadopublico.base_url'), '/');
        $ticket = trim((string) config('cotiz.mercadopublico.ticket'));
        $baseTimeoutSeg = max(15, (int) config('cotiz.mercadopublico.api_timeout_segundos', 45));
        $baseConnectTimeoutSeg = max(5, (int) config('cotiz.mercadopublico.api_connect_timeout_segundos', 15));

        if ($deadlineMicrotime !== null) {
            $restante = $deadlineMicrotime - microtime(true);
            if ($restante <= 0) {
                throw new RuntimeException(NotaMpResultadosService::mensajeTiempoMaximoNota());
            }
            $timeoutSeg = max(5, min($baseTimeoutSeg, 30, (int) floor($restante)));
            $connectTimeoutSeg = max(3, min($baseConnectTimeoutSeg, 10, $timeoutSeg));
        } else {
            $timeoutSeg = $baseTimeoutSeg;
            $connectTimeoutSeg = $baseConnectTimeoutSeg;
        }

        $lowSpeedTimeSeg = min(
            max(5, (int) config('cotiz.mercadopublico.api_low_speed_time_segundos', 20)),
            max(5, $timeoutSeg - 5),
        );
        $lowSpeedLimitBytes = max(1, (int) config('cotiz.mercadopublico.api_low_speed_limit_bytes', 10));

        $curlOpts = [
            CURLOPT_TIMEOUT => $timeoutSeg,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeg,
            CURLOPT_LOW_SPEED_TIME => $lowSpeedTimeSeg,
            CURLOPT_LOW_SPEED_LIMIT => $lowSpeedLimitBytes,
        ];

        try {
            return Http::connectTimeout($connectTimeoutSeg)
                ->timeout($timeoutSeg)
                ->withOptions(['curl' => $curlOpts])
                ->withHeaders(['ticket' => $ticket])
                ->acceptJson()
                ->send($method, $baseUrl.$path, $method === 'GET' ? ['query' => $params] : ['json' => $params]);
        } catch (ConnectionException $e) {
            throw new RuntimeException($this->mensajeErrorConexion($e), 1, $e);
        } catch (RequestException $e) {
            throw new RuntimeException($this->mensajeErrorRequest($e), 0, $e);
        } catch (\Throwable $e) {
            throw new RuntimeException('Error inesperado consultando Mercado Público: ' . mb_substr($e->getMessage(), 0, 200), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function interpretarRespuestaHttp(\Illuminate\Http\Client\Response $response): array
    {
        $status = $response->status();
        $json = $response->json();

        if ($this->esHttpRecuperable($status)) {
            throw new RuntimeException($this->mensajeDesdeRespuesta($status, $json), 1);
        }

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

    private function esHttpRecuperable(?int $status): bool
    {
        return in_array($status, [502, 503, 504], true);
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
            502 => 'Mercado Público no disponible temporalmente (HTTP 502).',
            503 => 'Mercado Público no disponible temporalmente (HTTP 503).',
            504 => 'Mercado Público no respondió a tiempo (HTTP 504 Gateway Timeout).',
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
