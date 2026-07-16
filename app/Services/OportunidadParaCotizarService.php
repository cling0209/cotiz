<?php

namespace App\Services;

use App\Models\OportunidadPalabraClave;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class OportunidadParaCotizarService
{
    private const CACHE_SEGUNDOS = 60;

    public function __construct(
        protected CompraAgilApiService $api,
        protected CompraAgilOportunidadService $oportunidad,
        protected CompraAgilPayloadMapper $mapper,
    ) {}

    /**
     * @return list<string>
     */
    public function palabrasClave(): array
    {
        return OportunidadPalabraClave::query()
            ->orderBy('frase')
            ->pluck('frase')
            ->map(fn ($f) => trim((string) $f))
            ->filter(fn ($f) => $f !== '')
            ->values()
            ->all();
    }

    /**
     * Plan de consulta: una pareja frase × región por paso (para ir mostrando resultados).
     *
     * @return array{
     *   palabras: list<string>,
     *   pasos: list<array{frase: string, region: int, region_nombre: string}>,
     *   total_pasos: int,
     *   fecha: string,
     *   api_configurada: bool,
     *   error: ?string
     * }
     */
    public function planBusqueda(): array
    {
        $palabras = $this->palabrasClave();
        $regiones = CompraAgilRegionScope::regionesIncluidas();
        $fecha = now()->timezone(config('app.timezone'))->toDateString();

        if ($palabras === []) {
            return [
                'palabras' => [],
                'pasos' => [],
                'total_pasos' => 0,
                'fecha' => $fecha,
                'api_configurada' => $this->api->isConfigured(),
                'error' => 'No hay palabras clave configuradas.',
            ];
        }

        if (! $this->api->isConfigured()) {
            return [
                'palabras' => $palabras,
                'pasos' => [],
                'total_pasos' => 0,
                'fecha' => $fecha,
                'api_configurada' => false,
                'error' => 'API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.',
            ];
        }

        $pasos = [];
        foreach ($palabras as $frase) {
            foreach ($regiones as $region) {
                $pasos[] = [
                    'frase' => $frase,
                    'region' => (int) $region,
                    'region_nombre' => CompraAgilRegionScope::nombreRegion((int) $region),
                ];
            }
        }

        return [
            'palabras' => $palabras,
            'pasos' => $pasos,
            'total_pasos' => count($pasos),
            'fecha' => $fecha,
            'api_configurada' => true,
            'error' => null,
        ];
    }

    /**
     * Parámetros de consulta a Mercado Público para un paso (frase × región).
     *
     * @return array<string, mixed>
     */
    public function parametrosConsultaPaso(string $frase, int $region): array
    {
        $frase = trim($frase);

        return [
            'estado' => 'publicada',
            'numero_pagina' => 1,
            'ordenar_por' => 'FechaPublicacion',
            'q' => $frase,
            'region' => max(1, $region),
            'tamano_pagina' => 50,
        ];
    }

    /**
     * Ejecuta un paso (frase × región) y devuelve solo publicadas hoy.
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   consulta: array<string, mixed>
     * }
     */
    public function ejecutarPaso(string $frase, int $region): array
    {
        $frase = trim($frase);
        if ($frase === '' || $region < 1) {
            return [
                'items' => [],
                'consulta' => $this->metaConsultaPaso($frase, $region, 0, 0),
            ];
        }

        if (! $this->api->isConfigured()) {
            throw new RuntimeException(
                'API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.'
            );
        }

        $dia = now()->timezone(config('app.timezone'))->toDateString();
        $cacheKey = 'oportunidad_para_cotizar:'.$dia.':'.md5(mb_strtolower($frase).'|'.$region);
        $params = $this->parametrosConsultaPaso($frase, $region);

        $crudos = Cache::remember($cacheKey, self::CACHE_SEGUNDOS, function () use ($params) {
            $resultado = $this->api->listar($params);

            return $resultado['items'] ?? [];
        });

        $items = [];
        foreach ($crudos as $item) {
            if (! is_array($item) || CompraAgilRegionScope::debeExcluirItem($item)) {
                continue;
            }

            $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
            if ($codigo === '') {
                continue;
            }

            $resumen = $this->oportunidad->enriquecerResumen(
                $this->mapper->resumenListadoItem($item),
            );

            if (! $this->esPublicadaHoy($resumen['fecha_publicacion'] ?? null)) {
                continue;
            }

            $resumen['palabras_coinciden'] = [$frase];
            $resumen['distancia_santiago'] = CompraAgilRegionScope::distanciaASantiago(
                isset($resumen['region']) ? (int) $resumen['region'] : null,
            );
            $items[] = $resumen;
        }

        return [
            'items' => $items,
            'consulta' => $this->metaConsultaPaso($frase, $region, count($crudos), count($items)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metaConsultaPaso(string $frase, int $region, int $totalApi, int $totalHoy): array
    {
        $params = $this->parametrosConsultaPaso($frase, $region);
        ksort($params);

        $baseUrl = rtrim((string) config('cotiz.mercadopublico.base_url'), '/');
        $path = '/v2/compra-agil';

        $paraJson = [
            'endpoint' => $baseUrl.$path,
            'filtro_fecha' => now()->timezone(config('app.timezone'))->toDateString(),
            'header_ticket' => '(configurado)',
            'metodo' => 'GET',
            'parametros' => $params,
            'total_api' => $totalApi,
            'total_publicadas_hoy' => $totalHoy,
        ];
        ksort($paraJson);

        return array_merge($paraJson, [
            'json' => json_encode(
                $paraJson,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: '{}',
        ]);
    }

    /**
     * Compatibilidad: búsqueda completa (sincrono). Solo publicadas hoy.
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
        $plan = $this->planBusqueda();

        if ($plan['error'] !== null) {
            return [
                'items' => [],
                'palabras' => $plan['palabras'],
                'error' => $plan['error'],
                'api_configurada' => $plan['api_configurada'],
            ];
        }

        try {
            $porCodigo = [];
            foreach ($plan['pasos'] as $paso) {
                foreach ($this->ejecutarPaso($paso['frase'], $paso['region'])['items'] as $item) {
                    $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
                    if ($codigo === '') {
                        continue;
                    }
                    if (! isset($porCodigo[$codigo])) {
                        $porCodigo[$codigo] = $item;
                    } elseif (! in_array($paso['frase'], $porCodigo[$codigo]['palabras_coinciden'], true)) {
                        $porCodigo[$codigo]['palabras_coinciden'][] = $paso['frase'];
                    }
                }
            }

            $items = array_values($porCodigo);
            usort($items, [$this, 'compararOportunidades']);

            return [
                'items' => $items,
                'palabras' => $plan['palabras'],
                'error' => null,
                'api_configurada' => true,
            ];
        } catch (RuntimeException $e) {
            return [
                'items' => [],
                'palabras' => $plan['palabras'],
                'error' => $e->getMessage(),
                'api_configurada' => true,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public function compararOportunidades(array $a, array $b): int
    {
        $montoA = (int) ($a['monto_presupuesto_clp'] ?? 0);
        $montoB = (int) ($b['monto_presupuesto_clp'] ?? 0);
        if ($montoA !== $montoB) {
            return $montoB <=> $montoA;
        }

        $distA = (int) ($a['distancia_santiago'] ?? CompraAgilRegionScope::distanciaASantiago(
            isset($a['region']) ? (int) $a['region'] : null,
        ));
        $distB = (int) ($b['distancia_santiago'] ?? CompraAgilRegionScope::distanciaASantiago(
            isset($b['region']) ? (int) $b['region'] : null,
        ));

        return $distA <=> $distB;
    }

    public function esPublicadaHoy(mixed $fecha): bool
    {
        $fecha = trim((string) $fecha);
        if ($fecha === '') {
            return false;
        }

        try {
            return Carbon::parse($fecha)
                ->timezone(config('app.timezone'))
                ->isToday();
        } catch (\Throwable) {
            return false;
        }
    }
}
