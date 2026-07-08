<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\PromiseInterface;
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
     * Disparo escalonado: no espera respuesta para programar la siguiente (cuando concurrencia > 1).
     * Con concurrencia 1 usa HTTP síncrono (fiable en Render) + pausa stagger entre notas.
     *
     * @param  list<string>  $codigos
     * @param  (callable(string, array<string, mixed>|\RuntimeException): void)|null  $onDone
     * @param  (callable(string): void)|null  $onLaunch
     * @return array<string, array<string, mixed>|\RuntimeException>
     */
    public function detalleVariosEscalonado(
        array $codigos,
        int $maxInFlight = 5,
        int $staggerMs = 2000,
        ?callable $onDone = null,
        ?callable $shouldContinue = null,
        ?callable $onLaunch = null,
    ): array {
        if (! $this->isConfigured()) {
            throw new RuntimeException('API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.');
        }

        $pendientes = [];
        foreach ($codigos as $codigo) {
            $codigo = strtoupper(trim((string) $codigo));
            if ($codigo !== '' && ! isset($pendientes[$codigo])) {
                $pendientes[$codigo] = $codigo;
            }
        }
        $cola = array_values($pendientes);
        if ($cola === []) {
            return [];
        }

        $maxInFlight = max(1, min(10, $maxInFlight));
        $staggerMs = max(0, $staggerMs);

        if ($maxInFlight <= 1) {
            return $this->detalleVariosSecuencial($cola, $staggerMs, $onDone, $shouldContinue, $onLaunch);
        }

        return $this->detalleVariosEachPromise($cola, $maxInFlight, $staggerMs, $onDone, $shouldContinue, $onLaunch);
    }

    /**
     * @param  list<string>  $cola
     * @return array<string, array<string, mixed>|\RuntimeException>
     */
    private function detalleVariosSecuencial(
        array $cola,
        int $staggerMs,
        ?callable $onDone,
        ?callable $shouldContinue,
        ?callable $onLaunch,
    ): array {
        $resultados = [];
        $first = true;

        foreach ($cola as $codigo) {
            if ($shouldContinue !== null && ! $shouldContinue()) {
                $err = new RuntimeException('Consulta cancelada.');
                $resultados[$codigo] = $err;
                if ($onDone !== null) {
                    $onDone($codigo, $err);
                }
                continue;
            }

            if (! $first && $staggerMs > 0) {
                usleep($staggerMs * 1000);
            }
            $first = false;

            if ($onLaunch !== null) {
                $onLaunch($codigo);
            }

            try {
                $resultado = $this->detalle($codigo, false, null);
            } catch (RuntimeException $e) {
                $resultado = $e;
            }

            $resultados[$codigo] = $resultado;
            if ($onDone !== null) {
                $onDone($codigo, $resultado);
            }
        }

        return $resultados;
    }

    /**
     * @param  list<string>  $cola
     * @return array<string, array<string, mixed>|\RuntimeException>
     */
    private function detalleVariosEachPromise(
        array $cola,
        int $maxInFlight,
        int $staggerMs,
        ?callable $onDone,
        ?callable $shouldContinue,
        ?callable $onLaunch,
    ): array {
        $resultados = [];

        $interpretar = function ($value, string $codigo) {
            if ($value instanceof RuntimeException) {
                $resultado = $value;
            } elseif ($value instanceof \Illuminate\Http\Client\Response) {
                try {
                    $resultado = $this->interpretarRespuestaHttp($value);
                } catch (RuntimeException $e) {
                    $resultado = $e;
                }
            } elseif (is_array($value)) {
                $resultado = $value;
            } else {
                $resultado = new RuntimeException('Respuesta vacía de Mercado Público.', 1);
            }

            if ($resultado instanceof RuntimeException) {
                $msg = $resultado->getMessage();
                if (
                    ! self::esErrorDefinitivoMp($msg)
                    && ! $this->esErrorDeadlineNota($msg)
                    && $resultado->getCode() === 1
                ) {
                    try {
                        $resultado = $this->detalle($codigo, false, null);
                    } catch (RuntimeException $e) {
                        $resultado = $e;
                    }
                }
            }

            return $resultado;
        };

        $generator = function () use ($cola, $staggerMs, $onLaunch, $shouldContinue) {
            $i = 0;
            foreach ($cola as $codigo) {
                if ($shouldContinue !== null && ! $shouldContinue()) {
                    break;
                }
                if ($onLaunch !== null) {
                    $onLaunch($codigo);
                }
                // delay Guzzle no bloquea CurlMulti (stagger acumulado desde el inicio del wait).
                yield $codigo => $this->launchAsyncDetalle($codigo, $i * $staggerMs);
                $i++;
            }
        };

        $each = new EachPromise($generator(), [
            'concurrency' => $maxInFlight,
            'fulfilled' => function ($value, $codigo) use (&$resultados, $interpretar, $onDone) {
                $codigo = strtoupper((string) $codigo);
                $resultado = $interpretar($value, $codigo);
                $resultados[$codigo] = $resultado;
                if ($onDone !== null) {
                    $onDone($codigo, $resultado);
                }
            },
            'rejected' => function ($reason, $codigo) use (&$resultados, $interpretar, $onDone) {
                $codigo = strtoupper((string) $codigo);
                if ($reason instanceof ConnectionException) {
                    $value = new RuntimeException($this->mensajeErrorConexion($reason), 1, $reason);
                } elseif ($reason instanceof RuntimeException) {
                    $value = $reason;
                } elseif ($reason instanceof \Throwable) {
                    $value = new RuntimeException(
                        'Error inesperado consultando Mercado Público: '.mb_substr($reason->getMessage(), 0, 200),
                        0,
                        $reason,
                    );
                } else {
                    $value = new RuntimeException('Error inesperado consultando Mercado Público.', 1);
                }
                $resultado = $interpretar($value, $codigo);
                $resultados[$codigo] = $resultado;
                if ($onDone !== null) {
                    $onDone($codigo, $resultado);
                }
            },
        ]);

        $each->promise()->wait();

        return $resultados;
    }

    /**
     * @return PromiseInterface
     */
    private function launchAsyncDetalle(string $codigo, int $delayMs = 0): PromiseInterface
    {
        $baseUrl = rtrim((string) config('cotiz.mercadopublico.base_url'), '/');
        $ticket = trim((string) config('cotiz.mercadopublico.ticket'));
        [$timeoutSeg, $connectTimeoutSeg, $curlOpts] = $this->opcionesHttpMp();

        $options = ['curl' => $curlOpts];
        if ($delayMs > 0) {
            $options['delay'] = $delayMs;
        }

        return Http::async()
            ->connectTimeout($connectTimeoutSeg)
            ->timeout($timeoutSeg)
            ->withOptions($options)
            ->withHeaders(['ticket' => $ticket])
            ->acceptJson()
            ->get($baseUrl.'/v2/compra-agil/'.rawurlencode($codigo))
            ->then(
                static fn ($response) => $response,
                function ($reason) {
                    if ($reason instanceof ConnectionException) {
                        return new RuntimeException($this->mensajeErrorConexion($reason), 1, $reason);
                    }
                    if ($reason instanceof \Throwable) {
                        return new RuntimeException(
                            'Error inesperado consultando Mercado Público: '.mb_substr($reason->getMessage(), 0, 200),
                            0,
                            $reason,
                        );
                    }

                    return new RuntimeException('Error inesperado consultando Mercado Público.', 1);
                },
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestDetalle(string $codigo, ?float $deadlineMicrotime = null): array
    {
        return $this->request('GET', '/v2/compra-agil/'.rawurlencode($codigo), [], $deadlineMicrotime);
    }

    /**
     * @param  list<string>  $codigos
     * @return array<string, array<string, mixed>|\RuntimeException>
     */
    private function requestDetallePool(array $codigos): array
    {
        $baseUrl = rtrim((string) config('cotiz.mercadopublico.base_url'), '/');
        $ticket = trim((string) config('cotiz.mercadopublico.ticket'));
        [$timeoutSeg, $connectTimeoutSeg, $curlOpts] = $this->opcionesHttpMp();

        $responses = Http::pool(function ($pool) use ($codigos, $baseUrl, $ticket, $timeoutSeg, $connectTimeoutSeg, $curlOpts) {
            foreach ($codigos as $codigo) {
                $pool->as($codigo)
                    ->connectTimeout($connectTimeoutSeg)
                    ->timeout($timeoutSeg)
                    ->withOptions(['curl' => $curlOpts])
                    ->withHeaders(['ticket' => $ticket])
                    ->acceptJson()
                    ->get($baseUrl.'/v2/compra-agil/'.rawurlencode($codigo));
            }
        }, count($codigos));

        $out = [];
        foreach ($codigos as $codigo) {
            $response = $responses[$codigo] ?? null;
            if ($response instanceof \Throwable) {
                $out[$codigo] = new RuntimeException(
                    $response instanceof ConnectionException
                        ? $this->mensajeErrorConexion($response)
                        : ('Error inesperado consultando Mercado Público: '.mb_substr($response->getMessage(), 0, 200)),
                    $response instanceof ConnectionException ? 1 : 0,
                    $response,
                );

                continue;
            }

            if (! $response instanceof \Illuminate\Http\Client\Response) {
                $out[$codigo] = new RuntimeException('Respuesta vacía de Mercado Público.', 1);

                continue;
            }

            try {
                $out[$codigo] = $this->interpretarRespuestaHttp($response);
            } catch (RuntimeException $e) {
                $out[$codigo] = $e;
            }
        }

        return $out;
    }

    /**
     * @return array{0: int, 1: int, 2: array<int, mixed>}
     */
    private function opcionesHttpMp(?float $deadlineMicrotime = null): array
    {
        $baseTimeoutSeg = max(15, (int) config('cotiz.mercadopublico.api_timeout_segundos', 45));
        $baseConnectTimeoutSeg = max(5, (int) config('cotiz.mercadopublico.api_connect_timeout_segundos', 15));

        if ($deadlineMicrotime !== null) {
            $restante = $deadlineMicrotime - microtime(true);
            if ($restante <= 0) {
                throw new RuntimeException(NotaMpResultadosService::mensajeTiempoMaximoNota());
            }
            $restanteSeg = max(5, (int) floor($restante));
            $timeoutSeg = max(5, min($baseTimeoutSeg, $restanteSeg));
            $connectTimeoutSeg = max(3, min($baseConnectTimeoutSeg, $timeoutSeg));
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

        return [$timeoutSeg, $connectTimeoutSeg, $curlOpts];
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
                if ($this->esErrorDeadlineNota($ultimoError) || self::esErrorDefinitivoMp($ultimoError)) {
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
        [$timeoutSeg, $connectTimeoutSeg, $curlOpts] = $this->opcionesHttpMp($deadlineMicrotime);

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
            $mensaje = $this->mensajeDesdeRespuesta($status, $json);
            if (self::esErrorDefinitivoMp($mensaje)) {
                throw new RuntimeException($mensaje);
            }

            throw new RuntimeException($mensaje, 1);
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
            $mensaje = $this->mensajeDesdeRespuesta($status, $json);
            throw new RuntimeException($mensaje);
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

    /**
     * Errores de validación de MP que no mejoran con reintentos HTTP (p. ej. código CA mal formado).
     */
    public static function esErrorDefinitivoMp(string $mensaje): bool
    {
        return self::esCodigoRutaInvalidoMp($mensaje);
    }

    public static function esCodigoRutaInvalidoMp(string $mensaje): bool
    {
        $texto = mb_strtolower($mensaje);
        $texto = str_replace(
            [
                'á', 'é', 'í', 'ó', 'ú', 'ñ',
                "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}",
            ],
            [
                'a', 'e', 'i', 'o', 'u', 'n',
                "'", "'", '"', '"',
            ],
            $texto,
        );
        $texto = preg_replace('/\s+/u', ' ', trim($texto)) ?? trim($texto);

        if (str_contains($texto, "parametro de ruta 'codigo' invalido")) {
            return true;
        }

        return str_contains($texto, 'ruta')
            && str_contains($texto, 'codigo')
            && str_contains($texto, 'invalido');
    }
}
