<?php

namespace App\Services;

use App\Models\AgileMaeprod;
use App\Models\CompraAgilBenchmark;
use App\Models\CompraAgilLineaMercado;
use App\Models\CompraAgilSyncLog;
use App\Models\Maeprod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CompraAgilBenchmarkService
{
    public function ultimaSync(): ?CompraAgilSyncLog
    {
        return CompraAgilSyncLog::query()->orderByDesc('id')->first();
    }

    public function recalcularTodos(): void
    {
        $filas = CompraAgilLineaMercado::query()
            ->whereNotNull('prod_item')
            ->where('prod_item', '<>', '')
            ->whereNotNull('precio_ganador_unitario')
            ->where('precio_ganador_unitario', '>', 0)
            ->select([
                'prod_item',
                DB::raw('COUNT(*) as observaciones'),
                DB::raw('PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY precio_ganador_unitario) as mediana'),
                DB::raw('MIN(precio_ganador_unitario) as precio_min'),
                DB::raw('MAX(precio_ganador_unitario) as precio_max'),
                DB::raw('MAX(fecha_proceso) as ultima_observacion'),
            ])
            ->groupBy('prod_item')
            ->get();

        foreach ($filas as $fila) {
            CompraAgilBenchmark::query()->updateOrCreate(
                ['prod_item' => (string) $fila->prod_item],
                [
                    'observaciones' => (int) $fila->observaciones,
                    'precio_mercado_mediana' => (int) round((float) $fila->mediana),
                    'precio_mercado_min' => (int) $fila->precio_min,
                    'precio_mercado_max' => (int) $fila->precio_max,
                    'ultima_observacion' => $fila->ultima_observacion,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function listadoAdmin(array $filtros): LengthAwarePaginator
    {
        $query = Maeprod::query()
            ->leftJoin('compra_agil_benchmarks as cab', 'cab.prod_item', '=', 'maeprod.prod_item')
            ->select([
                'maeprod.prod_item',
                'maeprod.prod_nombre',
                'maeprod.prod_valor',
                'maeprod.prod_valor_costo',
                'maeprod.prod_familia',
                'cab.observaciones',
                'cab.precio_mercado_mediana',
                'cab.precio_mercado_min',
                'cab.precio_mercado_max',
                'cab.ultima_observacion',
            ]);

        if (! empty($filtros['buscar'])) {
            $term = '%'.trim((string) $filtros['buscar']).'%';
            $query->where(function ($q) use ($term) {
                $q->where('maeprod.prod_item', 'ilike', $term)
                    ->orWhere('maeprod.prod_nombre', 'ilike', $term)
                    ->orWhereExists(function ($sub) use ($term) {
                        $sub->select(DB::raw(1))
                            ->from('agilemaeprod as am')
                            ->whereColumn('am.prod_item', 'maeprod.prod_item')
                            ->where(function ($w) use ($term) {
                                $w->where('am.prod_item_agile', 'ilike', $term)
                                    ->orWhere('am.prod_descripcion_agile', 'ilike', $term);
                            });
                    });
            });
        }

        if (! empty($filtros['solo_alertas'])) {
            $umbral = (float) config('cotiz.mercadopublico.alerta_desvio_pct', 15);
            $query->whereNotNull('cab.precio_mercado_mediana')
                ->where('cab.precio_mercado_mediana', '>', 0)
                ->whereRaw(
                    'ABS(maeprod.prod_valor - cab.precio_mercado_mediana)::float / cab.precio_mercado_mediana * 100 >= ?',
                    [$umbral],
                );
        }

        if (! empty($filtros['solo_con_datos'])) {
            $query->whereNotNull('cab.observaciones')->where('cab.observaciones', '>', 0);
        }

        $orden = $filtros['orden'] ?? 'desvio_desc';
        match ($orden) {
            'nombre' => $query->orderBy('maeprod.prod_nombre'),
            'observaciones' => $query->orderByDesc('cab.observaciones'),
            default => $query->orderByRaw(
                'CASE WHEN cab.precio_mercado_mediana > 0 THEN (maeprod.prod_valor - cab.precio_mercado_mediana)::float / cab.precio_mercado_mediana ELSE -999 END DESC',
            ),
        };

        $pagina = max(1, (int) ($filtros['page'] ?? 1));
        $porPagina = max(10, min(100, (int) ($filtros['por_pagina'] ?? 25)));

        $paginator = $query->paginate($porPagina, ['*'], 'page', $pagina);
        $prodItems = collect($paginator->items())
            ->pluck('prod_item')
            ->map(fn ($v) => (string) $v)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $vinculosPorProd = $this->vinculosAgilePorProdItems($prodItems);

        return $paginator->through(function ($row) use ($vinculosPorProd) {
            $mapped = $this->mapFilaListadoAdmin($row);
            $vinculos = $vinculosPorProd[$mapped['prod_item']] ?? [];
            $mapped['agile_vinculos'] = $vinculos;
            $mapped['agile_codigo'] = $vinculos[0]['codigo'] ?? null;
            $mapped['agile_nombre'] = $vinculos[0]['nombre'] ?? null;
            $mapped['agile_codigos_extra'] = max(0, count($vinculos) - 1);

            return $mapped;
        });
    }

    /**
     * Productos MP adjudicados sin vínculo a catálogo (últimos N días).
     *
     * @param  array<string, mixed>  $filtros
     */
    public function listadoSinVinculo(array $filtros): LengthAwarePaginator
    {
        $desde = now()->subDays((int) config('cotiz.mercadopublico.sync_dias', 30));

        $query = CompraAgilLineaMercado::query()
            ->whereNotNull('codigo_producto_mp')
            ->where('codigo_producto_mp', '<>', '')
            ->where('fecha_proceso', '>=', $desde)
            ->where(function ($q) {
                $q->whereNull('prod_item')->orWhere('prod_item', '');
            })
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('agilemaeprod as am')
                    ->whereColumn('am.prod_item_agile', 'compra_agil_lineas_mercado.codigo_producto_mp')
                    ->whereNotNull('am.prod_item')
                    ->where('am.prod_item', '<>', '')
                    ->where('am.prod_item', '<>', '0');
            });

        if (! empty($filtros['buscar'])) {
            $term = '%'.trim((string) $filtros['buscar']).'%';
            $query->where(function ($q) use ($term) {
                $q->where('codigo_producto_mp', 'ilike', $term)
                    ->orWhere('nombre_producto', 'ilike', $term);
            });
        }

        $query->select([
                'codigo_producto_mp',
                DB::raw('MAX(nombre_producto) as nombre_producto'),
                DB::raw('COUNT(DISTINCT codigo_proceso) as procesos'),
                DB::raw('COALESCE(SUM(cantidad), 0) as unidades'),
                DB::raw('MAX(fecha_proceso) as ultima_observacion'),
            ])
            ->groupBy('codigo_producto_mp');

        $orden = $filtros['orden'] ?? 'procesos_desc';
        match ($orden) {
            'unidades' => $query->orderByDesc('unidades'),
            'nombre' => $query->orderBy('nombre_producto'),
            default => $query->orderByDesc('procesos'),
        };

        $pagina = max(1, (int) ($filtros['page'] ?? 1));
        $porPagina = max(10, min(100, (int) ($filtros['por_pagina'] ?? 25)));

        $busqueda = app(MaeprodBusquedaSimilitudService::class);

        return $query->paginate($porPagina, ['*'], 'page', $pagina)->through(function ($row) use ($busqueda) {
            $nombreMp = trim((string) ($row->nombre_producto ?? ''));
            $sugerencia = $nombreMp !== ''
                ? $busqueda->buscar($nombreMp, null, 1)->first()
                : null;

            return [
                'codigo_producto_mp' => (string) $row->codigo_producto_mp,
                'nombre_producto' => $nombreMp,
                'procesos' => (int) $row->procesos,
                'unidades' => (float) $row->unidades,
                'ultima_observacion' => $row->ultima_observacion,
                'sugerencia_prod_item' => $sugerencia ? (string) $sugerencia->prod_item : null,
                'sugerencia_prod_nombre' => $sugerencia ? (string) $sugerencia->prod_nombre : null,
            ];
        });
    }

    /**
     * @param  list<string>  $prodItems
     * @return array<string, list<array{codigo: string, nombre: string}>>
     */
    public function vinculosAgilePorProdItems(array $prodItems): array
    {
        if ($prodItems === []) {
            return [];
        }

        $rows = AgileMaeprod::query()
            ->whereIn('prod_item', $prodItems)
            ->whereNotNull('prod_item')
            ->where('prod_item', '<>', '')
            ->where('prod_item', '<>', '0')
            ->orderBy('prod_item_agile')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row->prod_item;
            $out[$key] ??= [];
            $out[$key][] = [
                'codigo' => (string) $row->prod_item_agile,
                'nombre' => trim((string) ($row->prod_descripcion_agile ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapFilaListadoAdmin(object $row): array
    {
        $tuPrecio = (int) ($row->prod_valor ?? 0);
        $mediana = (int) ($row->precio_mercado_mediana ?? 0);
        $desvio = ($mediana > 0) ? round((($tuPrecio - $mediana) / $mediana) * 100, 1) : null;

        return [
            'prod_item' => (string) $row->prod_item,
            'prod_nombre' => (string) $row->prod_nombre,
            'prod_familia' => (string) ($row->prod_familia ?? ''),
            'prod_valor' => $tuPrecio,
            'prod_valor_costo' => (int) ($row->prod_valor_costo ?? 0),
            'observaciones' => (int) ($row->observaciones ?? 0),
            'precio_mercado_mediana' => $mediana ?: null,
            'precio_mercado_min' => $row->precio_mercado_min ? (int) $row->precio_mercado_min : null,
            'precio_mercado_max' => $row->precio_mercado_max ? (int) $row->precio_mercado_max : null,
            'desvio_pct' => $desvio,
            'ultima_observacion' => $row->ultima_observacion,
        ];
    }

    /**
     * @return array{total: int, alertas: int, con_datos: int, sin_datos: int}
     */
    public function resumenKpi(): array
    {
        $conDatos = CompraAgilBenchmark::query()->where('observaciones', '>', 0)->count();
        $totalMaeprod = Maeprod::query()->count();
        $umbral = (float) config('cotiz.mercadopublico.alerta_desvio_pct', 15);

        $alertas = Maeprod::query()
            ->join('compra_agil_benchmarks as cab', 'cab.prod_item', '=', 'maeprod.prod_item')
            ->where('cab.observaciones', '>', 0)
            ->where('cab.precio_mercado_mediana', '>', 0)
            ->whereRaw(
                'ABS(maeprod.prod_valor - cab.precio_mercado_mediana)::float / cab.precio_mercado_mediana * 100 >= ?',
                [$umbral],
            )
            ->count();

        return [
            'total' => $totalMaeprod,
            'con_datos' => $conDatos,
            'sin_datos' => max(0, $totalMaeprod - $conDatos),
            'alertas' => $alertas,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function lineasMercadoProducto(string $prodItem, int $limit = 20): Collection
    {
        return CompraAgilLineaMercado::query()
            ->with('proceso')
            ->where('prod_item', $prodItem)
            ->whereNotNull('precio_ganador_unitario')
            ->orderBy('precio_ganador_unitario')
            ->limit($limit)
            ->get()
            ->map(fn (CompraAgilLineaMercado $l) => [
                'codigo_proceso' => $l->codigo_proceso,
                'codigo_producto_mp' => $l->codigo_producto_mp,
                'organismo' => $l->proceso?->organismo,
                'nombre_producto' => $l->nombre_producto,
                'cantidad' => $l->cantidad,
                'precio_ganador_unitario' => (int) $l->precio_ganador_unitario,
                'fecha_proceso' => $l->fecha_proceso?->format('d/m/Y'),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function similaresCatalogo(string $prodItem, int $limit = 10): Collection
    {
        $producto = Maeprod::query()->find($prodItem);
        if (! $producto) {
            return collect();
        }

        $busqueda = app(MaeprodBusquedaSimilitudService::class);

        return $busqueda->buscar((string) $producto->prod_nombre, null, $limit + 1)
            ->filter(fn (Maeprod $p) => (string) $p->prod_item !== $prodItem)
            ->take($limit)
            ->map(fn (Maeprod $p) => [
                'prod_item' => (string) $p->prod_item,
                'prod_nombre' => (string) $p->prod_nombre,
                'prod_valor' => (int) ($p->prod_valor ?? 0),
            ])
            ->sortBy('prod_valor')
            ->values();
    }
}
