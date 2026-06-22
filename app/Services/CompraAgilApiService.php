<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
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
    public function detalle(string $codigo): array
    {
        $codigo = trim($codigo);
        if ($codigo === '') {
            throw new RuntimeException('Debe indicar el código de Compra Ágil.');
        }

        return $this->request('GET', '/v2/compra-agil/'.rawurlencode($codigo));
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

        try {
            $response = Http::timeout(30)
                ->withHeaders(['ticket' => $ticket])
                ->acceptJson()
                ->send($method, $baseUrl.$path, $method === 'GET' ? ['query' => $params] : ['json' => $params]);
        } catch (RequestException $e) {
            throw new RuntimeException($this->mensajeDesdeRespuesta($e->response?->status(), $e->response?->json()), $e->getCode(), $e);
        }

        $status = $response->status();
        $json = $response->json();

        if ($status === 429) {
            throw new RuntimeException('Cuota diaria de Mercado Público agotada. Intente mañana o use pegar texto.');
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

            throw new RuntimeException($mensaje ?: 'Respuesta inválida de Mercado Público.');
        }

        $payload = $json['payload'] ?? null;
        if (! is_array($payload)) {
            throw new RuntimeException('Respuesta vacía de Mercado Público.');
        }

        return $payload;
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
}
