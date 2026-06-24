<?php

namespace App\Services;

use App\Models\AgileMaeprod;
use App\Models\CompraAgilBenchmark;
use App\Models\CompraAgilLineaMercado;
use App\Models\CompraAgilProceso;
use App\Models\CompraAgilSyncLog;
use App\Models\Maeprod;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CompraAgilSyncService
{
    /** Estado de listado MP v2 para procesos cerrados/adjudicados (proveedor_seleccionado devuelve vacío). */
    private const ESTADO_LISTADO_ADJUDICADAS = 'cerrada';

    public function __construct(
        protected CompraAgilApiService $api,
        protected CompraAgilPayloadMapper $mapper,
        protected CompraAgilBenchmarkService $benchmarkService,
    ) {}

    /**
     * @return array{listados: int, detalles: int, procesos_nuevos: int, codigos_encontrados: int, error: ?string}
     */
    public function sincronizarAdjudicadas(?Carbon $desde = null, ?string $usuario = null): array
    {
        $log = CompraAgilSyncLog::query()->create([
            'inicio' => now(),
            'usuario' => $usuario !== null && trim($usuario) !== '' ? trim($usuario) : null,
            'estado' => 'running',
        ]);

        $listados = 0;
        $detalles = 0;
        $procesosNuevos = 0;
        $codigosEncontrados = 0;
        $error = null;

        try {
            if (! $this->api->isConfigured()) {
                throw new \RuntimeException('MERCADOPUBLICO_TICKET no configurado.');
            }

            $maxDetalle = max(1, (int) config('cotiz.mercadopublico.sync_max_detalle', 50));
            $regiones = CompraAgilRegionScope::regionesIncluidas();

            if ($desde === null) {
                $dias = CompraAgilProceso::query()->exists()
                    ? (int) config('cotiz.mercadopublico.sync_dias', 30)
                    : (int) config('cotiz.mercadopublico.sync_dias_inicial', 180);
                $desde = now()->subDays($dias);
            }

            [$codigos, $listados] = $this->listarCodigosAdjudicadas($regiones, $desde, now(), $listados);

            if ($codigos === [] && ! CompraAgilProceso::query()->exists()) {
                $diasAmplio = max(365, (int) config('cotiz.mercadopublico.sync_dias_inicial', 180));
                [$codigos, $listados] = $this->listarCodigosAdjudicadas(
                    $regiones,
                    now()->subDays($diasAmplio),
                    now(),
                    $listados,
                );
            }

            $codigosEncontrados = count($codigos);

            $procesados = 0;
            foreach ($codigos as $codigo) {
                if ($procesados >= $maxDetalle) {
                    break;
                }

                $existente = CompraAgilProceso::query()->find($codigo);
                $payload = $this->api->detalle($codigo, usarCache: false);
                $detalles++;

                if (CompraAgilRegionScope::debeExcluirItem($payload)) {
                    continue;
                }

                if (! $this->tieneProveedorAdjudicado($payload)) {
                    continue;
                }

                $fechaCambio = $this->parseFecha(data_get($payload, 'fechas.fecha_ultimo_cambio'));
                if ($existente && $fechaCambio && $existente->fecha_ultimo_cambio?->equalTo($fechaCambio)) {
                    continue;
                }

                $this->persistirDetalle($payload);
                if (! $existente) {
                    $procesosNuevos++;
                }
                $procesados++;
            }

            $this->benchmarkService->recalcularTodos();

            $log->update([
                'fin' => now(),
                'listados' => $listados,
                'detalles' => $detalles,
                'procesos_nuevos' => $procesosNuevos,
                'estado' => 'ok',
                'mensaje' => $codigosEncontrados === 0
                    ? 'Sin adjudicadas en el período consultado.'
                    : null,
            ]);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $log->update([
                'fin' => now(),
                'listados' => $listados,
                'detalles' => $detalles,
                'procesos_nuevos' => $procesosNuevos,
                'estado' => 'error',
                'mensaje' => $error,
            ]);
        }

        return [
            'listados' => $listados,
            'detalles' => $detalles,
            'procesos_nuevos' => $procesosNuevos,
            'codigos_encontrados' => $codigosEncontrados,
            'error' => $error,
        ];
    }

    /**
     * @param  list<int>  $regiones
     * @return array{0: list<string>, 1: int}
     */
    private function listarCodigosAdjudicadas(array $regiones, ?Carbon $desde, ?Carbon $hasta, int $listadosInicial): array
    {
        $paramsBase = [
            'estado' => self::ESTADO_LISTADO_ADJUDICADAS,
            'tamano_pagina' => 50,
            'numero_pagina' => 1,
        ];

        if ($desde !== null && $hasta !== null) {
            $paramsBase['cambio_desde'] = $desde->utc()->format('Y-m-d\TH:i:s\Z');
            $paramsBase['cambio_hasta'] = $hasta->utc()->format('Y-m-d\TH:i:s\Z');
        } else {
            throw new \RuntimeException('La API Compra Ágil requiere cambio_desde y cambio_hasta para listar adjudicadas.');
        }

        $codigos = [];
        $listados = $listadosInicial;

        foreach ($regiones as $region) {
            $params = array_merge($paramsBase, ['region' => $region, 'numero_pagina' => 1]);
            $paginasRegion = 0;
            do {
                $resultado = $this->api->listar($params);
                $listados++;
                $paginasRegion++;
                foreach ($resultado['items'] as $item) {
                    if (! is_array($item) || CompraAgilRegionScope::debeExcluirItem($item)) {
                        continue;
                    }
                    $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
                    if ($codigo !== '') {
                        $codigos[$codigo] = true;
                    }
                }
                $pag = $resultado['paginacion'];
                if (($pag['numero_pagina'] ?? 1) >= ($pag['total_paginas'] ?? 1)) {
                    break;
                }
                $params['numero_pagina'] = ((int) ($pag['numero_pagina'] ?? 1)) + 1;
            } while ($paginasRegion < 5);
        }

        return [array_keys($codigos), $listados];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function persistirDetalle(array $payload): CompraAgilProceso
    {
        $codigo = strtoupper(trim((string) ($payload['codigo'] ?? '')));
        $institucion = is_array($payload['institucion'] ?? null) ? $payload['institucion'] : [];
        $presupuesto = is_array($payload['presupuesto'] ?? null) ? $payload['presupuesto'] : [];
        $fechas = is_array($payload['fechas'] ?? null) ? $payload['fechas'] : [];
        $estado = is_array($payload['estado'] ?? null) ? $payload['estado'] : [];
        $resumen = is_array($payload['resumen'] ?? null) ? $payload['resumen'] : [];

        $rutGanador = $this->extraerRutGanador($payload);

        $proceso = CompraAgilProceso::query()->updateOrCreate(
            ['codigo' => $codigo],
            [
                'nombre' => mb_substr(trim((string) ($payload['nombre'] ?? '')), 0, 500),
                'estado_codigo' => trim((string) ($estado['codigo'] ?? '')),
                'estado_glosa' => trim((string) ($estado['glosa'] ?? '')),
                'organismo' => mb_substr(trim((string) ($institucion['organismo_comprador'] ?? '')), 0, 200),
                'rut_organismo' => isset($institucion['rut'])
                    ? (new CompraAgilTextoParserService)->normalizarRut((string) $institucion['rut'])
                    : null,
                'region' => isset($institucion['region']) ? (int) $institucion['region'] : null,
                'monto_presupuesto_clp' => isset($presupuesto['monto_disponible_clp'])
                    ? (int) round((float) $presupuesto['monto_disponible_clp'])
                    : null,
                'fecha_publicacion' => $this->parseFecha($fechas['fecha_publicacion'] ?? null),
                'fecha_cierre' => $this->parseFecha($fechas['fecha_cierre'] ?? null),
                'fecha_ultimo_cambio' => $this->parseFecha($fechas['fecha_ultimo_cambio'] ?? null),
                'cantidad_productos' => $this->mapper->cantidadProductosDetalle($payload),
                'total_ofertas' => (int) ($resumen['total_ofertas_recibidas'] ?? 0),
                'rut_ganador' => $rutGanador,
                'sincronizado_en' => now(),
            ],
        );

        CompraAgilLineaMercado::query()->where('codigo_proceso', $codigo)->delete();

        $productos = is_array($payload['productos_solicitados'] ?? null) ? $payload['productos_solicitados'] : [];
        $preciosGanador = $this->preciosGanadorPorProducto($payload);

        foreach ($productos as $producto) {
            if (! is_array($producto)) {
                continue;
            }
            $codigoProd = trim((string) ($producto['codigo_producto'] ?? ''));
            $precio = $codigoProd !== '' ? ($preciosGanador[$codigoProd] ?? null) : null;
            $prodItem = $codigoProd !== ''
                ? AgileMaeprod::query()->find($codigoProd)?->prod_item
                : null;

            CompraAgilLineaMercado::query()->create([
                'codigo_proceso' => $codigo,
                'codigo_producto_mp' => $codigoProd !== '' ? $codigoProd : null,
                'nombre_producto' => mb_substr(trim((string) ($producto['nombre'] ?? '')), 0, 500),
                'cantidad' => (float) ($producto['cantidad'] ?? 0),
                'unidad_medida' => trim((string) ($producto['unidad_medida'] ?? '')),
                'precio_ganador_unitario' => $precio !== null ? (int) round($precio) : null,
                'prod_item' => $prodItem && trim((string) $prodItem) !== '' ? trim((string) $prodItem) : null,
                'fecha_proceso' => $proceso->fecha_publicacion,
            ]);
        }

        return $proceso;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, float>
     */
    private function preciosGanadorPorProducto(array $payload): array
    {
        $out = [];
        $proveedores = is_array($payload['proveedores_cotizando'] ?? null) ? $payload['proveedores_cotizando'] : [];

        foreach ($this->proveedoresAdjudicados($proveedores) as $prov) {
            $productos = is_array($prov['productos_cotizados'] ?? null) ? $prov['productos_cotizados'] : [];
            foreach ($productos as $linea) {
                if (! is_array($linea)) {
                    continue;
                }
                $cod = trim((string) ($linea['codigo_producto'] ?? ''));
                $precio = $linea['precio_unitario'] ?? null;
                if ($cod !== '' && $precio !== null && ! isset($out[$cod])) {
                    $out[$cod] = (float) $precio;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $proveedores
     * @return list<array<string, mixed>>
     */
    private function proveedoresAdjudicados(array $proveedores): array
    {
        $adjudicados = [];
        foreach ($proveedores as $prov) {
            if (! is_array($prov)) {
                continue;
            }
            if (! empty($prov['seleccion']['proveedor_seleccionado']) || ! empty($prov['activo'])) {
                $adjudicados[] = $prov;
            }
        }

        if ($adjudicados !== []) {
            return $adjudicados;
        }

        if (count($proveedores) === 1 && is_array($proveedores[0])) {
            return [$proveedores[0]];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function tieneProveedorAdjudicado(array $payload): bool
    {
        $proveedores = is_array($payload['proveedores_cotizando'] ?? null) ? $payload['proveedores_cotizando'] : [];

        return $this->proveedoresAdjudicados($proveedores) !== [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extraerRutGanador(array $payload): ?string
    {
        foreach ($this->proveedoresAdjudicados(
            is_array($payload['proveedores_cotizando'] ?? null) ? $payload['proveedores_cotizando'] : []
        ) as $prov) {
            $rut = trim((string) ($prov['rut_proveedor'] ?? ''));
            if ($rut !== '') {
                return (new CompraAgilTextoParserService)->normalizarRut($rut);
            }
        }

        return null;
    }

    private function parseFecha(mixed $valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
