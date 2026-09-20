<?php

namespace App\Services;

use App\Support\ListadoPorPagina;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Precios y cantidades cotizadas por código propio.
 * Cada línea de la nota se cruza con las ofertas de Mercado Público en la misma posición.
 */
class CompraAgilCompetenciaService
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $agrupadasCache = [];

    /**
     * @param  array<string, mixed>  $filtros
     * @return array{productos: int, unidades_propias: float, unidades_otros: float, mas_caro: int}
     */
    public function resumen(array $filtros): array
    {
        $filas = $this->filasAgrupadas($filtros);

        $masCaro = 0;
        foreach ($filas as $fila) {
            if ($fila['tu_precio'] !== null && $fila['precio_min'] !== null && $fila['tu_precio'] > $fila['precio_min']) {
                $masCaro++;
            }
        }

        return [
            'productos' => count($filas),
            'unidades_propias' => array_sum(array_column($filas, 'cant_propia')),
            'unidades_otros' => array_sum(array_column($filas, 'cant_otros')),
            'mas_caro' => $masCaro,
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function listado(array $filtros): LengthAwarePaginator
    {
        $filas = $this->ordenar($this->filasAgrupadas($filtros), $filtros);
        $porPagina = ListadoPorPagina::normalizar((int) ($filtros['por_pagina'] ?? ListadoPorPagina::DEFAULT));
        $pagina = max(1, (int) ($filtros['page'] ?? 1));
        $total = count($filas);
        $slice = array_slice($filas, ($pagina - 1) * $porPagina, $porPagina);

        return new Paginator(
            $slice,
            $total,
            $porPagina,
            $pagina,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * Todas las filas del filtro, en el mismo orden de la tabla.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function exportarExcel(array $filtros): StreamedResponse
    {
        $filas = $this->ordenar($this->filasAgrupadas($filtros), $filtros);
        $filename = 'precios_competencia_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($filas) {
            $writer = new Xlsx($this->libroExcel($filas));
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return ?array<string, mixed>
     */
    public function detalle(string $prodItem, array $filtros): ?array
    {
        $prodItem = trim($prodItem);
        if ($prodItem === '') {
            return null;
        }

        $agrupadas = $this->filasAgrupadas($filtros, $prodItem);
        $resumen = $agrupadas[0] ?? null;
        if ($resumen === null) {
            return null;
        }

        return [
            'prod_item' => $resumen['prod_item'],
            'prod_nombre' => $resumen['prod_nombre'],
            'cant_propia' => $resumen['cant_propia'],
            'cant_otros' => $resumen['cant_otros'],
            'cant_total' => $resumen['cant_total'],
            'adjudicada_propia' => $resumen['adjudicada_propia'],
            'adjudicada_otros' => $resumen['adjudicada_otros'],
            'nadie_gano' => $resumen['nadie_gano'],
            'nronota_ultima' => $resumen['nronota_ultima'],
            'tu_precio' => $resumen['tu_precio'],
            'precio_min' => $resumen['precio_min'],
            'precio_max' => $resumen['precio_max'],
            'lineas' => $this->lineasDetalle($prodItem, $filtros),
        ];
    }

    /**
     * Nota usada para más barato y más caro, con el precio propio y el de cada empresa.
     *
     * @param  array<string, mixed>  $filtros
     * @return ?array<string, mixed>
     */
    public function detallePrecios(string $prodItem, array $filtros): ?array
    {
        $prodItem = trim($prodItem);
        if ($prodItem === '') {
            return null;
        }

        $resumen = $this->filasAgrupadas($filtros, $prodItem)[0] ?? null;
        if ($resumen === null) {
            return null;
        }

        $nronota = $resumen['nronota_mercado'] ?? $resumen['nronota_ultima'];
        if ($nronota === null) {
            return null;
        }

        $fecha = $this->sqlFechaCierre('s');
        $filas = $this->queryBase($filtros, $prodItem)
            ->where('d.nronota', $nronota)
            ->select([
                'd.nronota',
                'd.orden',
                'd.prod_valor',
                'd.prod_descripcion_maestro',
                DB::raw("{$fecha} as fecha_cierre"),
                'op.razon_social',
                'op.rut_proveedor',
                'op.precio_unitario',
                'op.es_propio',
            ])
            ->orderBy('d.orden')
            ->orderBy('op.precio_unitario')
            ->get();

        if ($filas->isEmpty()) {
            return null;
        }

        $primera = $filas->first();
        $precioPropio = [];
        foreach ($filas as $fila) {
            $clave = $fila->nronota.'|'.$fila->orden;
            if (isset($precioPropio[$clave]) || $fila->precio_unitario === null || ! $this->ofertaEsPropia($fila)) {
                continue;
            }
            $precioPropio[$clave] = (int) $fila->precio_unitario;
        }

        $vistas = [];
        $lineas = [];
        foreach ($filas as $fila) {
            $clavePropia = $fila->nronota.'|'.$fila->orden;
            if (! isset($vistas[$clavePropia])) {
                $vistas[$clavePropia] = true;
                if (isset($precioPropio[$clavePropia])) {
                    $lineas[] = [
                        'proveedor' => 'Tú',
                        'es_propio' => true,
                        'precio_unitario' => $precioPropio[$clavePropia],
                    ];
                }
            }
            if ($fila->precio_unitario === null || $this->ofertaEsPropia($fila)) {
                continue;
            }
            $lineas[] = [
                'proveedor' => trim((string) ($fila->razon_social ?: $fila->rut_proveedor ?: '—')),
                'es_propio' => false,
                'precio_unitario' => (int) $fila->precio_unitario,
            ];
        }

        return [
            'prod_item' => $resumen['prod_item'],
            'prod_nombre' => $resumen['prod_nombre'] !== ''
                ? $resumen['prod_nombre']
                : trim((string) $primera->prod_descripcion_maestro),
            'nronota' => (int) $primera->nronota,
            'fecha_cierre' => $this->formatearFecha($primera->fecha_cierre),
            'precio_catalogo' => $primera->prod_valor !== null ? (int) $primera->prod_valor : null,
            'lineas' => $lineas,
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return list<array<string, mixed>>
     */
    private function filasAgrupadas(array $filtros, ?string $prodItem = null): array
    {
        $cacheKey = md5((string) json_encode([$filtros, $prodItem]));
        if (isset($this->agrupadasCache[$cacheKey])) {
            return $this->agrupadasCache[$cacheKey];
        }

        $query = $this->queryBase($filtros, $prodItem);
        $nombre = $this->sqlNombre();
        $propio = $this->sqlEsPropio('op');
        $seleccionado = 'op.proveedor_seleccionado IS TRUE';
        $ruts = $this->rutsPropiosCompactos();

        $porLinea = $query
            ->leftJoinSub($this->preciosUltimos($filtros, $prodItem), 'tu', 'tu.prod_item', '=', 'd.prod_item')
            ->leftJoinSub($this->preciosMercado($filtros, $prodItem), 'mk', 'mk.prod_item', '=', 'd.prod_item')
            ->leftJoinSub($this->preciosOfertados($filtros, $prodItem), 'po', function ($join) {
                $join->on('po.prod_item', '=', 'd.prod_item')
                    ->on('po.nronota', '=', 'mk.nronota');
            })
            ->groupBy('d.prod_item', 'd.nronota', 'd.orden', 'tu.nronota')
            ->select([
                'd.prod_item',
                DB::raw("MAX({$nombre}) as prod_nombre"),
                DB::raw('MAX(COALESCE(d.cantidad, 0)) as cant_propia'),
                DB::raw("SUM(CASE WHEN op.rut_proveedor IS NULL OR ({$propio}) THEN 0 ELSE COALESCE(op.cantidad, 0) END) as cant_otros"),
                DB::raw("MAX(CASE WHEN {$seleccionado} AND ({$propio}) THEN COALESCE(d.cantidad, 0) ELSE 0 END) as adj_propia"),
                DB::raw("SUM(CASE WHEN {$seleccionado} AND op.rut_proveedor IS NOT NULL AND NOT ({$propio}) THEN COALESCE(op.cantidad, 0) ELSE 0 END) as adj_otros"),
                DB::raw('MAX(mk.precio_min) as precio_min'),
                DB::raw('MAX(mk.precio_max) as precio_max'),
                DB::raw('COALESCE(MAX(po.precio_ofertado), MAX(tu.prod_valor)) as tu_precio'),
                DB::raw('MAX(tu.nronota) as nronota_ultima'),
                DB::raw('MAX(mk.nronota) as nronota_mercado'),
            ])
            ->addBinding(array_merge($ruts, $ruts, $ruts), 'select');

        $filas = DB::query()
            ->fromSub($porLinea, 'lin')
            ->groupBy('prod_item')
            ->select([
                'prod_item',
                DB::raw('MAX(prod_nombre) as prod_nombre'),
                DB::raw('SUM(cant_propia) as cant_propia'),
                DB::raw('SUM(cant_otros) as cant_otros'),
                DB::raw('SUM(adj_propia) as adj_propia'),
                DB::raw('SUM(adj_otros) as adj_otros'),
                DB::raw('MIN(precio_min) as precio_min'),
                DB::raw('MAX(precio_max) as precio_max'),
                DB::raw('MAX(tu_precio) as tu_precio'),
                DB::raw('MAX(nronota_ultima) as nronota_ultima'),
                DB::raw('MAX(nronota_mercado) as nronota_mercado'),
            ])
            ->get();

        $out = [];
        foreach ($filas as $fila) {
            $propia = (float) ($fila->cant_propia ?? 0);
            $otros = (float) ($fila->cant_otros ?? 0);
            $total = $propia + $otros;
            $adjudicadaPropia = (float) ($fila->adj_propia ?? 0);
            $adjudicadaOtros = (float) ($fila->adj_otros ?? 0);
            $out[] = [
                'prod_item' => (string) $fila->prod_item,
                'prod_nombre' => trim((string) $fila->prod_nombre),
                'cant_propia' => $propia,
                'cant_otros' => $otros,
                'cant_total' => $total,
                'adjudicada_propia' => $adjudicadaPropia,
                'adjudicada_otros' => $adjudicadaOtros,
                'nadie_gano' => $total - $adjudicadaPropia - $adjudicadaOtros,
                'nronota_ultima' => $fila->nronota_ultima !== null ? (int) $fila->nronota_ultima : null,
                'nronota_mercado' => $fila->nronota_mercado !== null ? (int) $fila->nronota_mercado : null,
                'tu_precio' => $fila->tu_precio !== null ? (int) $fila->tu_precio : null,
                'precio_min' => $fila->precio_min !== null ? (int) $fila->precio_min : null,
                'precio_max' => $fila->precio_max !== null ? (int) $fila->precio_max : null,
            ];
        }

        return $this->agrupadasCache[$cacheKey] = $out;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return list<array<string, mixed>>
     */
    private function lineasDetalle(string $prodItem, array $filtros): array
    {
        $fecha = $this->sqlFechaCierre('s');
        $filas = $this->queryBase($filtros, $prodItem)
            ->select([
                'd.nronota',
                'd.orden',
                'd.prod_valor',
                'd.cantidad as cantidad_nota',
                DB::raw("{$fecha} as fecha_cierre"),
                'op.razon_social',
                'op.rut_proveedor',
                'op.precio_unitario',
                'op.cantidad as cantidad_oferta',
                'op.proveedor_seleccionado',
                'op.es_propio',
            ])
            ->orderByRaw($fecha.' DESC NULLS LAST')
            ->orderByDesc('d.nronota')
            ->orderBy('op.precio_unitario')
            ->get();

        $porNota = [];
        foreach ($filas as $fila) {
            $clave = $fila->nronota.'|'.$fila->orden;
            if (! isset($porNota[$clave])) {
                $porNota[$clave] = [
                    'nronota' => (int) $fila->nronota,
                    'fecha_cierre' => $fila->fecha_cierre,
                    'prod_valor' => (int) $fila->prod_valor,
                    'cantidad_nota' => (float) $fila->cantidad_nota,
                    'ofertas' => [],
                ];
            }
            if ($fila->precio_unitario === null && $fila->razon_social === null && $fila->rut_proveedor === null) {
                continue;
            }
            $porNota[$clave]['ofertas'][] = $fila;
        }

        $porEmpresa = [];
        foreach ($porNota as $bloque) {
            foreach ($bloque['ofertas'] as $oferta) {
                if (! $this->esSeleccionado($oferta->proveedor_seleccionado) || $this->ofertaEsPropia($oferta)) {
                    continue;
                }
                $empresa = trim((string) ($oferta->razon_social ?: $oferta->rut_proveedor ?: '—'));
                $porEmpresa[$empresa] = ($porEmpresa[$empresa] ?? 0) + (float) ($oferta->cantidad_oferta ?? 0);
            }
        }

        arsort($porEmpresa);
        $lineas = [];
        foreach ($porEmpresa as $empresa => $cantidad) {
            $lineas[] = [
                'proveedor' => $empresa,
                'cantidad_adjudicada' => $cantidad,
            ];
        }

        return $lineas;
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    private function queryBase(array $filtros, ?string $prodItem)
    {
        $ofertas = DB::table('nota_mp_ofertas as o')
            ->join('nota_mp_oferta_lineas as l', 'l.oferta_id', '=', 'o.id')
            ->whereRaw('NOT (o.inadmisible IS TRUE)')
            ->whereIn('o.nronota', $this->nronotasFiltradas($filtros, $prodItem))
            ->select([
                'o.nronota',
                'o.rut_proveedor',
                'o.razon_social',
                'o.proveedor_seleccionado',
                'o.es_propio',
                'l.cantidad',
                'l.precio_unitario',
                DB::raw('ROW_NUMBER() OVER (PARTITION BY o.id ORDER BY l.id) as pos'),
            ]);

        $query = DB::table('notasdetalle as d')
            ->join('nota_mp_seguimientos as s', 's.nronota', '=', 'd.nronota')
            ->leftJoin('maeprod as mp', 'mp.prod_item', '=', 'd.prod_item')
            ->leftJoinSub($ofertas, 'op', function ($join) {
                $join->on('op.nronota', '=', 'd.nronota')
                    ->on('op.pos', '=', 'd.orden');
            })
            ->whereRaw("trim(coalesce(d.prod_item, '')) <> ''")
            ->where('d.prod_item', '!=', '0')
            ->whereRaw("upper(trim(d.prod_item)) NOT LIKE 'NOK-%'");

        if ($prodItem !== null) {
            $query->where('d.prod_item', $prodItem);
        }

        $this->aplicarFecha($query, $filtros, 's');
        $this->aplicarBusqueda($query, $filtros);

        return $query;
    }

    /**
     * Solo las notas del filtro. El ROW_NUMBER de ofertas no recorre el historial completo.
     *
     * @param  array<string, mixed>  $filtros
     */
    private function nronotasFiltradas(array $filtros, ?string $prodItem)
    {
        $query = DB::table('nota_mp_seguimientos as sf')->select('sf.nronota');
        $this->aplicarFecha($query, $filtros, 'sf');
        if ($prodItem !== null) {
            $query->whereExists(function ($sub) use ($prodItem) {
                $sub->select(DB::raw('1'))
                    ->from('notasdetalle as df')
                    ->whereColumn('df.nronota', 'sf.nronota')
                    ->where('df.prod_item', $prodItem);
            });
        }

        return $query;
    }

    /**
     * Precio ofertado propio de la última nota en la que sí hubo oferta. Respaldo si la nota de mercado no tiene precio propio.
     *
     * @param  array<string, mixed>  $filtros
     */
    private function preciosUltimos(array $filtros, ?string $prodItem)
    {
        $fecha = $this->sqlFechaCierre('s');
        $propio = $this->sqlEsPropio('op');
        $ranked = $this->queryBase($filtros, $prodItem)
            ->whereNotNull('op.precio_unitario')
            ->whereRaw("({$propio})")
            ->select([
                'd.prod_item',
                'op.precio_unitario as prod_valor',
                'd.nronota',
                DB::raw("ROW_NUMBER() OVER (PARTITION BY d.prod_item ORDER BY {$fecha} DESC NULLS LAST, d.nronota DESC) as rn"),
            ])
            ->addBinding($this->rutsPropiosCompactos(), 'where');

        return DB::query()
            ->fromSub($ranked, 'px')
            ->where('rn', 1)
            ->select(['prod_item', 'prod_valor', 'nronota']);
    }

    /**
     * Precio ofertado propio por nota, para la misma nota de más barato y más caro.
     *
     * @param  array<string, mixed>  $filtros
     */
    private function preciosOfertados(array $filtros, ?string $prodItem)
    {
        $propio = $this->sqlEsPropio('op');

        return $this->queryBase($filtros, $prodItem)
            ->whereNotNull('op.precio_unitario')
            ->whereRaw("({$propio})")
            ->groupBy('d.prod_item', 'd.nronota')
            ->select([
                'd.prod_item',
                'd.nronota',
                DB::raw('MAX(op.precio_unitario) as precio_ofertado'),
            ])
            ->addBinding($this->rutsPropiosCompactos(), 'where');
    }

    /**
     * Más barato y más caro: última nota en la que cotizó al menos otra empresa.
     *
     * @param  array<string, mixed>  $filtros
     */
    private function preciosMercado(array $filtros, ?string $prodItem)
    {
        $fecha = $this->sqlFechaCierre('s');
        $propio = $this->sqlEsPropio('op');
        $lineas = $this->queryBase($filtros, $prodItem)
            ->whereNotNull('op.rut_proveedor')
            ->whereNotNull('op.precio_unitario')
            ->whereRaw("NOT ({$propio})")
            ->select([
                'd.prod_item',
                'd.nronota',
                'op.precio_unitario',
                DB::raw("DENSE_RANK() OVER (PARTITION BY d.prod_item ORDER BY {$fecha} DESC NULLS LAST, d.nronota DESC) as rk"),
            ])
            ->addBinding($this->rutsPropiosCompactos(), 'where');

        return DB::query()
            ->fromSub($lineas, 'mk')
            ->where('rk', 1)
            ->groupBy('prod_item', 'nronota')
            ->select([
                'prod_item',
                'nronota',
                DB::raw('MIN(precio_unitario) as precio_min'),
                DB::raw('MAX(precio_unitario) as precio_max'),
            ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    private function aplicarFecha($query, array $filtros, string $alias): void
    {
        $expr = $this->sqlFechaCierre($alias);
        $desde = trim((string) ($filtros['fecha_desde'] ?? ''));
        $hasta = trim((string) ($filtros['fecha_hasta'] ?? ''));
        if ($desde !== '') {
            $query->whereRaw("{$expr} >= ?", [$desde.' 00:00:00']);
        }
        if ($hasta !== '') {
            $query->whereRaw("{$expr} <= ?", [$hasta.' 23:59:59']);
        }
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    private function aplicarBusqueda($query, array $filtros): void
    {
        $buscar = trim((string) ($filtros['buscar'] ?? ''));
        if ($buscar === '') {
            return;
        }

        $like = '%'.$buscar.'%';
        $op = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $query->where(function ($q) use ($like, $op) {
            $q->where('d.prod_item', $op, $like)
                ->orWhere('mp.prod_nombre', $op, $like)
                ->orWhere('d.prod_descripcion_maestro', $op, $like);
        });
    }

    /**
     * El CASE de propio usa dos veces el IN de RUT, así que los bindings van duplicados en el select.
     */
    private function sqlEsPropio(string $alias): string
    {
        $ruts = $this->rutsPropiosCompactos();
        $rutSql = "replace(replace(replace(upper(coalesce({$alias}.rut_proveedor, '')), '.', ''), '-', ''), ' ', '')";
        if ($ruts === []) {
            return "({$alias}.es_propio IS TRUE)";
        }

        $marcas = implode(',', array_fill(0, count($ruts), '?'));

        return "({$alias}.es_propio IS TRUE OR {$rutSql} IN ({$marcas}))";
    }

    /**
     * @return list<string>
     */
    private function rutsPropiosCompactos(): array
    {
        $valores = [
            (string) config('cotiz.reicol_rut', ''),
            (string) config('cotiz.romulo_rut', ''),
            (string) config('cotiz.empresa_rut', ''),
        ];
        $out = [];
        foreach ($valores as $rut) {
            $compacto = strtoupper(preg_replace('/[^0-9K]/', '', $rut) ?? '');
            if ($compacto !== '') {
                $out[] = $compacto;
            }
        }

        return array_values(array_unique($out));
    }

    private function sqlNombre(): string
    {
        return "COALESCE(NULLIF(TRIM(mp.prod_nombre), ''), NULLIF(TRIM(d.prod_descripcion_maestro), ''), NULLIF(TRIM(d.prod_descripcion_agile), ''), '')";
    }

    private function sqlFechaCierre(string $alias): string
    {
        return "COALESCE({$alias}.fecha_cierre, {$alias}.fecha_cierre_segundo_llamado, {$alias}.fecha_cierre_primer_llamado)";
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @param  array<string, mixed>  $filtros
     * @return list<array<string, mixed>>
     */
    /**
     * @param  list<array<string, mixed>>  $filas
     */
    private function libroExcel(array $filas): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Precios competencia');
        $sheet->fromArray([[
            'Cód. propio',
            'Descripción propia',
            'Cant. total',
            'Adjudicada propio',
            'Adjudicadas otros',
            'Nadie se ganó',
            'Tu precio',
            'Más barato',
            'Más caro',
        ]], null, 'A1');
        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9D9D9'],
            ],
        ]);

        $row = 2;
        foreach ($filas as $fila) {
            $sheet->fromArray([[
                $fila['prod_item'],
                $fila['prod_nombre'],
                $fila['cant_total'],
                $fila['adjudicada_propia'],
                $fila['adjudicada_otros'],
                $fila['nadie_gano'],
                $fila['tu_precio'],
                $fila['precio_min'],
                $fila['precio_max'],
            ]], null, 'A'.$row);
            $row++;
        }

        $last = max(2, $row - 1);
        if ($filas !== []) {
            $sheet->getStyle('C2:F'.$last)->getNumberFormat()->setFormatCode('#,##0.##');
            $sheet->getStyle('G2:I'.$last)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('C2:I'.$last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    private function ordenar(array $filas, array $filtros): array
    {
        $columna = (string) ($filtros['orden'] ?? 'cant_total');
        if (! in_array($columna, ['cant_total', 'adjudicada_propia', 'adjudicada_otros', 'nadie_gano'], true)) {
            $columna = 'cant_total';
        }
        $desc = ($filtros['dir'] ?? 'desc') !== 'asc';

        usort($filas, function (array $a, array $b) use ($columna, $desc): int {
            $cmp = $a[$columna] <=> $b[$columna];
            if ($cmp === 0) {
                $cmp = strcmp($a['prod_item'], $b['prod_item']);
            }

            return $desc ? -$cmp : $cmp;
        });

        return $filas;
    }

    private function ofertaEsPropia(object $oferta): bool
    {
        if ($this->esSeleccionado($oferta->es_propio) || filter_var($oferta->es_propio, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $compacto = strtoupper(preg_replace('/[^0-9K]/', '', (string) ($oferta->rut_proveedor ?? '')) ?? '');

        return $compacto !== '' && in_array($compacto, $this->rutsPropiosCompactos(), true);
    }

    private function esSeleccionado(mixed $valor): bool
    {
        if ($valor === true || $valor === 1 || $valor === '1' || $valor === 't' || $valor === 'true') {
            return true;
        }

        return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }

    private function formatearFecha(mixed $fecha): string
    {
        if ($fecha === null || $fecha === '') {
            return '—';
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $fecha)->format('d/m/Y');
        } catch (\Throwable) {
            return '—';
        }
    }
}
