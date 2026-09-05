<?php

namespace App\Services;

use App\Models\NotaDetalle;
use App\Models\NotaMpSeguimiento;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompraAgilComisionesService
{
    public function factorComisionBase(): float
    {
        return (float) config('cotiz.comisiones.factor_base', 1.2);
    }

    public function porcentajeComision(): float
    {
        return (float) config('cotiz.comisiones.porcentaje', 0.20);
    }

    public function pagoFijo(): int
    {
        return (int) config('cotiz.comisiones.pago_fijo', 10000);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function listadoPaginado(int $porPagina, array $filtros = []): LengthAwarePaginator
    {
        $paginator = $this->aplicarOrden($this->buildQuery($filtros), $filtros)
            ->with(['nota.usuarioRel', 'nota.detalle'])
            ->paginate($porPagina)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (NotaMpSeguimiento $seg) => $this->enriquecerFila($seg))
        );

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function listadoDetalle(array $filtros = [], int $limite = 10000): Collection
    {
        return $this->aplicarOrden($this->buildQuery($filtros), $filtros)
            ->with(['nota.usuarioRel', 'nota.detalle'])
            ->limit($limite)
            ->get()
            ->map(fn (NotaMpSeguimiento $seg) => $this->enriquecerFila($seg));
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function resumenPorEjecutivo(array $filtros = []): Collection
    {
        $filas = $this->listadoDetalle($filtros);

        return $filas
            ->groupBy(fn (object $fila) => $fila->ejecutivo_username ?: '(sin ejecutivo)')
            ->map(function (Collection $grupo) {
                $primera = $grupo->first();

                return (object) [
                    'ejecutivo' => $primera->ejecutivo,
                    'ejecutivo_username' => $primera->ejecutivo_username,
                    'cantidad_cotizaciones' => $grupo->count(),
                    'costo' => (int) $grupo->sum('costo'),
                    'venta' => (int) $grupo->sum('venta'),
                    'venta_12' => (int) $grupo->sum('venta_12'),
                    'utilidad' => (int) $grupo->sum('utilidad'),
                    'comision_20' => (int) $grupo->sum('comision_20'),
                    'pago' => (int) $grupo->sum('pago'),
                    'a_pagar' => (int) $grupo->sum('a_pagar'),
                ];
            })
            ->sortBy('ejecutivo', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function exportarDetalle(array $filtros = []): StreamedResponse
    {
        $filas = $this->listadoDetalle($filtros);
        $filename = 'comisiones_detalle_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($filas) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Nota',
                'Código CA',
                'Orden compra',
                'Fecha envío OC',
                'Ejecutivo',
                'Región',
                'Factor',
                'Costo',
                'Venta',
                'Venta 1.2',
                'Utilidad',
                '20% Comisión',
                'Pago',
                'A pagar',
            ], ';');
            foreach ($filas as $fila) {
                fputcsv($out, [
                    $fila->nronota,
                    $fila->codigo_proceso,
                    $fila->orden_compra,
                    $fila->fecha_envio_oc?->format('d/m/Y H:i') ?? '',
                    $fila->ejecutivo,
                    $fila->region_nombre,
                    number_format($fila->factor, 2, ',', ''),
                    $fila->costo,
                    $fila->venta,
                    $fila->venta_12,
                    $fila->utilidad,
                    $fila->comision_20,
                    $fila->pago,
                    $fila->a_pagar,
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function exportarResumen(array $filtros = []): StreamedResponse
    {
        $filas = $this->resumenPorEjecutivo($filtros);
        $filename = 'comisiones_resumen_ejecutivo_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($filas) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Ejecutivo',
                'Cantidad cotizaciones',
                'Costo',
                'Venta',
                'Venta 1.2',
                'Utilidad',
                '20% Comisión',
                'Pago',
                'A pagar',
            ], ';');
            foreach ($filas as $fila) {
                fputcsv($out, [
                    $fila->ejecutivo,
                    $fila->cantidad_cotizaciones,
                    $fila->costo,
                    $fila->venta,
                    $fila->venta_12,
                    $fila->utilidad,
                    $fila->comision_20,
                    $fila->pago,
                    $fila->a_pagar,
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<NotaMpSeguimiento>
     */
    private function buildQuery(array $filtros): Builder
    {
        $query = NotaMpSeguimiento::query()
            ->where('resultado_propio', 'cerrada')
            ->whereHas('nota', function (Builder $n): void {
                $n->whereRaw("TRIM(COALESCE(ocompra, '')) <> ''");
            });

        $this->aplicarFiltroGanadasGrupo($query);

        if (! empty($filtros['nronota'])) {
            $query->where('nronota', (int) $filtros['nronota']);
        }

        if (! empty($filtros['codigo_proceso'])) {
            $query->where('codigo_proceso', 'ilike', '%'.$filtros['codigo_proceso'].'%');
        }

        $usuario = trim((string) ($filtros['usuario'] ?? ''));
        if ($usuario !== '') {
            $query->whereHas('nota', fn (Builder $q) => $q->where('usuario', $usuario));
        }

        if (! empty($filtros['fecha_envio_desde'])) {
            $query->where('oc_fecha_envio', '>=', $filtros['fecha_envio_desde'].' 00:00:00');
        }

        if (! empty($filtros['fecha_envio_hasta'])) {
            $query->where('oc_fecha_envio', '<=', $filtros['fecha_envio_hasta'].' 23:59:59');
        }

        return $query;
    }

    /**
     * Ganadas = rut_ganador Reicol o Romulo (mismo criterio que OC visible en Resultados).
     *
     * @param  Builder<NotaMpSeguimiento>  $query
     */
    private function aplicarFiltroGanadasGrupo(Builder $query): void
    {
        $ruts = array_values(array_filter([
            $this->rutNormalizado((string) config('cotiz.reicol_rut', '')),
            $this->rutNormalizado((string) config('cotiz.romulo_rut', '')),
        ]));

        if ($ruts === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereNotNull('rut_ganador')
            ->where(function (Builder $q) use ($ruts): void {
                foreach ($ruts as $rutNorm) {
                    $q->orWhereRaw(
                        "regexp_replace(upper(coalesce(rut_ganador, '')), '[^0-9K]', '', 'g') = ?",
                        [$rutNorm]
                    );
                }
            });
    }

    private function rutNormalizado(string $rut): string
    {
        $rut = trim($rut);
        if ($rut === '') {
            return '';
        }

        return strtoupper(preg_replace('/[^0-9kK]/', '', $rut) ?? '');
    }

    /**
     * @param  Builder<NotaMpSeguimiento>  $query
     * @param  array<string, mixed>  $filtros
     * @return Builder<NotaMpSeguimiento>
     */
    private function aplicarOrden(Builder $query, array $filtros): Builder
    {
        $sort = (string) ($filtros['sort'] ?? 'fecha_envio');
        $dir = strtolower((string) ($filtros['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return match ($sort) {
            'nronota' => $query->orderBy('nronota', $dir),
            'codigo_proceso' => $query->orderBy('codigo_proceso', $dir),
            'orden_compra' => $query->orderBy('id_orden_compra', $dir),
            default => $query->orderByRaw('oc_fecha_envio IS NULL')
                ->orderBy('oc_fecha_envio', $dir)
                ->orderBy('nronota', 'desc'),
        };
    }

    private function enriquecerFila(NotaMpSeguimiento $seg): object
    {
        $nota = $seg->nota;
        $costo = $this->costoNota($nota?->detalle ?? collect());
        $factor = round((float) ($nota->factor_precio_venta ?? config('cotiz.factor_precio_venta', 1.22)), 2);
        if ($factor <= 0) {
            $factor = round((float) config('cotiz.factor_precio_venta', 1.22), 2);
        }

        $factorBase = $this->factorComisionBase();
        $venta = (int) round($costo * $factor);
        $venta12 = (int) round($costo * $factorBase);
        $utilidad = $venta12 - $costo;
        $comision = (int) round($utilidad * $this->porcentajeComision());
        $pago = $this->pagoFijo();

        $ejecutivoUsername = trim((string) ($nota->usuario ?? ''));
        $ejecutivo = trim((string) ($nota->usuarioRel?->fullName() ?: $ejecutivoUsername));
        $ordenCompra = trim((string) ($nota->ocompra ?? ''));

        return (object) [
            'nronota' => $seg->nronota,
            'codigo_proceso' => (string) ($seg->codigo_proceso ?? ''),
            'orden_compra' => $ordenCompra,
            'fecha_envio_oc' => $seg->oc_fecha_envio,
            'ejecutivo' => $ejecutivo !== '' ? $ejecutivo : '—',
            'ejecutivo_username' => $ejecutivoUsername,
            'region_nombre' => trim((string) ($nota->nombre_region ?? '')) ?: '—',
            'factor' => $factor,
            'costo' => $costo,
            'venta' => $venta,
            'venta_12' => $venta12,
            'utilidad' => $utilidad,
            'comision_20' => $comision,
            'pago' => $pago,
            'a_pagar' => $comision + $pago,
            'seguimiento' => $seg,
        ];
    }

    /**
     * @param  Collection<int, NotaDetalle>|\Illuminate\Database\Eloquent\Collection<int, NotaDetalle>  $detalle
     */
    private function costoNota($detalle): int
    {
        return (int) $detalle->sum(
            fn (NotaDetalle $linea) => (int) $linea->prod_valor_costo * (int) $linea->cantidad
        );
    }
}
