<?php

namespace App\Services;

use App\Models\OportunidadPalabraClave;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class OportunidadParaCotizarService
{
    private const CACHE_SEGUNDOS = 300;

    public function __construct(
        protected CompraAgilApiService $api,
        protected CompraAgilOportunidadService $oportunidad,
        protected CompraAgilPayloadMapper $mapper,
    ) {}

    /**
     * Publicadas que coinciden con alguna palabra clave, ordenadas por presupuesto DESC
     * y luego por cercanía a Santiago.
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   palabras: list<string>,
     *   error: ?string,
     *   api_configurada: bool
     * }
     */
    public function listar(): array
    {
        $palabras = OportunidadPalabraClave::query()
            ->orderBy('frase')
            ->pluck('frase')
            ->map(fn ($f) => trim((string) $f))
            ->filter(fn ($f) => $f !== '')
            ->values()
            ->all();

        if ($palabras === []) {
            return [
                'items' => [],
                'palabras' => [],
                'error' => null,
                'api_configurada' => $this->api->isConfigured(),
            ];
        }

        if (! $this->api->isConfigured()) {
            return [
                'items' => [],
                'palabras' => $palabras,
                'error' => 'API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.',
                'api_configurada' => false,
            ];
        }

        try {
            $porCodigo = [];

            foreach ($palabras as $frase) {
                foreach ($this->buscarPublicadasPorFrase($frase) as $item) {
                    $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
                    if ($codigo === '') {
                        continue;
                    }

                    if (! isset($porCodigo[$codigo])) {
                        $item['palabras_coinciden'] = [$frase];
                        $porCodigo[$codigo] = $item;
                    } elseif (! in_array($frase, $porCodigo[$codigo]['palabras_coinciden'], true)) {
                        $porCodigo[$codigo]['palabras_coinciden'][] = $frase;
                    }
                }
            }

            $items = array_values($porCodigo);
            usort($items, function (array $a, array $b): int {
                $montoA = (int) ($a['monto_presupuesto_clp'] ?? 0);
                $montoB = (int) ($b['monto_presupuesto_clp'] ?? 0);
                if ($montoA !== $montoB) {
                    return $montoB <=> $montoA;
                }

                $distA = CompraAgilRegionScope::distanciaASantiago(
                    isset($a['region']) ? (int) $a['region'] : null,
                );
                $distB = CompraAgilRegionScope::distanciaASantiago(
                    isset($b['region']) ? (int) $b['region'] : null,
                );

                return $distA <=> $distB;
            });

            return [
                'items' => $items,
                'palabras' => $palabras,
                'error' => null,
                'api_configurada' => true,
            ];
        } catch (RuntimeException $e) {
            return [
                'items' => [],
                'palabras' => $palabras,
                'error' => $e->getMessage(),
                'api_configurada' => true,
            ];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buscarPublicadasPorFrase(string $frase): array
    {
        $regiones = CompraAgilRegionScope::regionesIncluidas();
        if ($regiones === []) {
            return [];
        }

        $itemsPorCodigo = [];

        foreach ($regiones as $region) {
            $cacheKey = 'oportunidad_para_cotizar:'.md5(mb_strtolower($frase).'|'.$region);
            $crudos = Cache::remember($cacheKey, self::CACHE_SEGUNDOS, function () use ($frase, $region) {
                $resultado = $this->api->listar([
                    'estado' => 'publicada',
                    'q' => $frase,
                    'region' => (int) $region,
                    'tamano_pagina' => 50,
                    'numero_pagina' => 1,
                    'ordenar_por' => 'FechaPublicacion',
                ]);

                return $resultado['items'] ?? [];
            });

            foreach ($crudos as $item) {
                if (! is_array($item) || CompraAgilRegionScope::debeExcluirItem($item)) {
                    continue;
                }
                $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
                if ($codigo === '') {
                    continue;
                }
                $itemsPorCodigo[$codigo] = $this->oportunidad->enriquecerResumen(
                    $this->mapper->resumenListadoItem($item),
                );
            }
        }

        return array_values($itemsPorCodigo);
    }
}
