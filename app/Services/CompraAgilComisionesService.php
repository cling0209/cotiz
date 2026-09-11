<?php

namespace App\Services;

use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\NotaMpSeguimiento;
use App\Models\Parametro;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompraAgilComisionesService
{
    public const PARTICIPACION_SI = 'si';

    public const PARTICIPACION_NO = 'no';

    /** MP aún no entrega listado de proveedores cotizando. */
    public const PARTICIPACION_SIN_PROVEEDORES = 'sin_proveedores';

    /** Estados de seguimiento visibles en Comisiones. */
    public const RESULTADOS_VISIBLE = ['cerrada', 'desierta', 'cancelada'];

    /** Zona agregada en resumen por ejecutivo. */
    public const ZONA_METROPOLITANA = 'Metropolitana';

    public const ZONA_REGION = 'Región';

    /** @var list<string>|null */
    private ?array $rutsGanadorasNorm = null;

    public function factorComisionBase(): float
    {
        return (float) config('cotiz.comisiones.factor_base', 1.2);
    }

    public function porcentajeComision(): float
    {
        return (float) config('cotiz.comisiones.porcentaje', 0.20);
    }

    public function pagoPorCotizacion(): int
    {
        $default = (int) config('cotiz.comisiones.pago_fijo', 10000);

        try {
            return Parametro::getInt(Parametro::CLAVE_PAGO_COTIZACION_REALIZADA, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /** @deprecated usar pagoPorCotizacion() */
    public function pagoFijo(): int
    {
        return $this->pagoPorCotizacion();
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function listadoPaginado(int $porPagina, array $filtros = []): LengthAwarePaginator
    {
        $paginator = $this->aplicarOrden($this->buildQuery($filtros), $filtros)
            ->with(['nota.usuarioRel', 'nota.detalle', 'ofertas'])
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
            ->with(['nota.usuarioRel', 'nota.detalle', 'ofertas'])
            ->limit($limite)
            ->get()
            ->map(fn (NotaMpSeguimiento $seg) => $this->enriquecerFila($seg));
    }

    /**
     * Resumen por ejecutivo y zona (Metropolitana / Región).
     * Si la nota no tiene región, la zona se deduce por factor (1,22 = RM).
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function resumenPorEjecutivo(array $filtros = []): Collection
    {
        $filas = $this->listadoDetalle($filtros);

        return $filas
            ->groupBy(function (object $fila) {
                $user = $fila->ejecutivo_username ?: '(sin ejecutivo)';

                return $user.'|'.$fila->zona_resumen;
            })
            ->map(function (Collection $grupo) {
                $primera = $grupo->first();

                return (object) [
                    'ejecutivo' => $primera->ejecutivo,
                    'ejecutivo_username' => $primera->ejecutivo_username,
                    'zona' => $primera->zona_resumen,
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
            ->sortBy(
                fn (object $fila) => mb_strtolower((string) $fila->ejecutivo)
                    .'|'.($fila->zona === self::ZONA_METROPOLITANA ? '0' : '1'),
                SORT_NATURAL,
            )
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
                'Fecha de creación',
                'Código CA',
                'Seguimiento',
                'Participó MP',
                'Ganada',
                'Orden compra',
                'Fecha envío OC o última modificación',
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
                    $fila->fecha_creacion?->format('d/m/Y') ?? '',
                    $fila->codigo_proceso,
                    $fila->resultado_propio,
                    $fila->participacion_mp_label,
                    $fila->es_ganada ? 'Sí' : 'No',
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
        $filename = 'comisiones_resumen_ejecutivo_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($filas) {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Resumen ejecutivo');

            $headers = [
                'Ejecutivo',
                'Zona',
                'Cantidad cotizaciones',
                'Costo',
                'Venta',
                'Venta 1.2',
                'Utilidad',
                '20% Comisión',
                'Pago',
                'A pagar',
            ];
            $sheet->fromArray([$headers], null, 'A1');

            $headerRange = 'A1:J1';
            $sheet->getStyle($headerRange)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => '000000'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'D9D9D9'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => false,
                ],
            ]);
            $sheet->getRowDimension(1)->setRowHeight(18);

            $row = 2;
            $ejecutivoAnterior = null;
            foreach ($filas as $fila) {
                $userKey = $fila->ejecutivo_username ?: $fila->ejecutivo;
                if ($ejecutivoAnterior !== null && $ejecutivoAnterior !== $userKey) {
                    $row++;
                }

                $sheet->fromArray([[
                    $fila->ejecutivo,
                    $fila->zona,
                    (int) $fila->cantidad_cotizaciones,
                    (int) $fila->costo,
                    (int) $fila->venta,
                    (int) $fila->venta_12,
                    (int) $fila->utilidad,
                    (int) $fila->comision_20,
                    (int) $fila->pago,
                    (int) $fila->a_pagar,
                ]], null, 'A'.$row);

                $ejecutivoAnterior = $userKey;
                $row++;
            }

            $lastDataRow = max(2, $row - 1);
            if ($filas->isNotEmpty()) {
                $sheet->getStyle('C2:J'.$lastDataRow)
                    ->getNumberFormat()
                    ->setFormatCode('#,##0');
                $sheet->getStyle('C2:J'.$lastDataRow)
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }

            foreach (range('A', 'J') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Asegura que títulos largos (ej. "Cantidad cotizaciones", "20% Comisión")
            // queden visibles aunque autoSize sea corto en algunos entornos.
            $minWidths = [
                'A' => 18,
                'B' => 14,
                'C' => 22,
                'D' => 12,
                'E' => 12,
                'F' => 12,
                'G' => 12,
                'H' => 14,
                'I' => 12,
                'J' => 12,
            ];
            foreach ($minWidths as $col => $min) {
                $dim = $sheet->getColumnDimension($col);
                $current = (float) ($dim->getWidth() ?: 0);
                if ($current < $min) {
                    $dim->setAutoSize(false);
                    $dim->setWidth($min);
                }
            }

            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Cotizaciones cerradas / desiertas / canceladas (no pendientes).
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<NotaMpSeguimiento>
     */
    private function buildQuery(array $filtros): Builder
    {
        $query = NotaMpSeguimiento::query()
            ->whereIn('resultado_propio', self::RESULTADOS_VISIBLE);

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

        [$sqlFecha, $bindingsFecha] = $this->sqlExpresionFechaEnvioVisible();

        if (! empty($filtros['fecha_envio_desde'])) {
            $query->whereRaw(
                "({$sqlFecha}) >= ?",
                [...$bindingsFecha, $filtros['fecha_envio_desde'].' 00:00:00'],
            );
        }

        if (! empty($filtros['fecha_envio_hasta'])) {
            $query->whereRaw(
                "({$sqlFecha}) <= ?",
                [...$bindingsFecha, $filtros['fecha_envio_hasta'].' 23:59:59'],
            );
        }

        if (! empty($filtros['fecha_creacion_desde'])) {
            $query->whereHas(
                'nota',
                fn (Builder $q) => $q->whereDate('fecha', '>=', $filtros['fecha_creacion_desde']),
            );
        }

        if (! empty($filtros['fecha_creacion_hasta'])) {
            $query->whereHas(
                'nota',
                fn (Builder $q) => $q->whereDate('fecha', '<=', $filtros['fecha_creacion_hasta']),
            );
        }

        return $query;
    }

    /**
     * Fecha mostrada/filtrada en Comisiones:
     * - cerrada propia (ganador Reicol/Rómulo): oc_fecha_envio (fallback último cambio)
     * - cerrada ajena / desierta / cancelada: fecha_ultimo_cambio
     *
     * @return array{0: string, 1: list<string>}
     */
    private function sqlExpresionFechaEnvioVisible(string $alias = 'nota_mp_seguimientos'): array
    {
        $ruts = $this->rutsGanadorasNormalizados();
        if ($ruts === []) {
            return ["{$alias}.fecha_ultimo_cambio", []];
        }

        $placeholders = implode(',', array_fill(0, count($ruts), '?'));
        $rutNormSql = "regexp_replace(upper(coalesce({$alias}.rut_ganador, '')), '[^0-9K]', '', 'g')";

        $sql = "CASE
            WHEN {$alias}.resultado_propio = 'cerrada'
             AND {$rutNormSql} IN ({$placeholders})
            THEN COALESCE({$alias}.oc_fecha_envio, {$alias}.fecha_ultimo_cambio)
            ELSE {$alias}.fecha_ultimo_cambio
        END";

        return [$sql, $ruts];
    }

    /**
     * Fecha a mostrar en columna «Fecha envío OC o última modificación».
     */
    private function fechaEnvioOUltimaModificacion(NotaMpSeguimiento $seg): mixed
    {
        $resultado = (string) ($seg->resultado_propio ?? '');
        $propia = $this->esGanadaGrupo($seg->rut_ganador);

        if ($resultado === 'cerrada' && $propia) {
            return $seg->oc_fecha_envio ?? $seg->fecha_ultimo_cambio;
        }

        return $seg->fecha_ultimo_cambio;
    }

    /**
     * @return list<string>
     */
    private function rutsGanadorasNormalizados(): array
    {
        if ($this->rutsGanadorasNorm !== null) {
            return $this->rutsGanadorasNorm;
        }

        $this->rutsGanadorasNorm = array_values(array_filter([
            $this->rutNormalizado((string) config('cotiz.reicol_rut', '')),
            $this->rutNormalizado((string) config('cotiz.romulo_rut', '')),
        ]));

        return $this->rutsGanadorasNorm;
    }

    private function esGanadaGrupo(?string $rutGanador): bool
    {
        $rutNorm = $this->rutNormalizado((string) ($rutGanador ?? ''));
        if ($rutNorm === '') {
            return false;
        }

        return in_array($rutNorm, $this->rutsGanadorasNormalizados(), true);
    }

    /**
     * Ganada para comisión: RUT Reicol/Rómulo y con orden de compra alfanumérica
     * en la nota (no basta id_orden_compra de MP / "Pendiente").
     */
    private function esGanadaParaComision(?string $rutGanador, ?Nota $nota): bool
    {
        if (! $this->esGanadaGrupo($rutGanador)) {
            return false;
        }

        return $this->tieneOrdenCompra($nota);
    }

    private function tieneOrdenCompra(?Nota $nota): bool
    {
        return trim((string) ($nota?->ocompra ?? '')) !== '';
    }

    /**
     * Participación de la empresa de esta instancia en MP.
     * - si: figura en proveedores cotizando
     * - no: hay proveedores en MP y esta empresa no está
     * - sin_proveedores: MP aún no muestra participantes
     *
     * @return self::PARTICIPACION_*
     */
    private function estadoParticipacionMp(NotaMpSeguimiento $seg): string
    {
        $ofertas = $seg->relationLoaded('ofertas')
            ? $seg->ofertas
            : $seg->ofertas()->get();

        if ($ofertas->isEmpty()) {
            return self::PARTICIPACION_SIN_PROVEEDORES;
        }

        foreach ($ofertas as $oferta) {
            if ($oferta->es_propio) {
                return self::PARTICIPACION_SI;
            }
        }

        $rutPropio = $this->rutNormalizado((string) config('cotiz.empresa_rut', ''));
        if ($rutPropio === '') {
            return self::PARTICIPACION_NO;
        }

        foreach ($ofertas as $oferta) {
            if ($this->rutNormalizado((string) ($oferta->rut_proveedor ?? '')) === $rutPropio) {
                return self::PARTICIPACION_SI;
            }
        }

        return self::PARTICIPACION_NO;
    }

    private function labelParticipacionMp(string $estado): string
    {
        return match ($estado) {
            self::PARTICIPACION_SI => 'Sí',
            self::PARTICIPACION_NO => 'No participó',
            self::PARTICIPACION_SIN_PROVEEDORES => 'Sin proveedores en MP',
            default => '—',
        };
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
        $sort = (string) ($filtros['sort'] ?? 'nronota');
        $dir = strtolower((string) ($filtros['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sort === 'fecha_envio') {
            [$sqlFecha, $bindingsFecha] = $this->sqlExpresionFechaEnvioVisible();

            return $query
                ->orderByRaw("({$sqlFecha}) IS NULL", $bindingsFecha)
                ->orderByRaw("({$sqlFecha}) {$dir}", $bindingsFecha)
                ->orderByDesc('nronota');
        }

        return match ($sort) {
            'codigo_proceso' => $query->orderBy('codigo_proceso', $dir)->orderByDesc('nronota'),
            'orden_compra' => $query->orderBy('id_orden_compra', $dir)->orderByDesc('nronota'),
            'fecha_creacion' => $query->orderBy(
                Nota::query()->select('fecha')->whereColumn('notas.nronota', 'nota_mp_seguimientos.nronota'),
                $dir,
            )->orderByDesc('nronota'),
            'seguimiento' => $query->orderBy('resultado_propio', $dir)->orderByDesc('nronota'),
            default => $query->orderBy('nronota', $dir),
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

        $esGanada = $this->esGanadaParaComision($seg->rut_ganador, $nota);
        $estadoParticipacion = $this->estadoParticipacionMp($seg);
        $participoMp = $estadoParticipacion === self::PARTICIPACION_SI;
        $factorBase = $this->factorComisionBase();
        $venta = (int) round($costo * $factor);
        $venta12 = (int) round($costo * $factorBase);
        $utilidad = $esGanada ? ($venta12 - $costo) : 0;
        $comision = $esGanada ? (int) round($utilidad * $this->porcentajeComision()) : 0;
        // Pago del parámetro solo si esta empresa cotizó en MP; si no o aún sin listado, $0.
        $pago = $participoMp ? $this->pagoPorCotizacion() : 0;

        $ejecutivoUsername = trim((string) ($nota->usuario ?? ''));
        $ejecutivo = trim((string) ($nota->usuarioRel?->fullName() ?: $ejecutivoUsername));
        $ordenCompra = trim((string) ($nota->ocompra ?? ''));
        if ($ordenCompra === '' && $seg->id_orden_compra) {
            $ordenCompra = 'Pendiente';
        }

        return (object) [
            'nronota' => $seg->nronota,
            'fecha_creacion' => $nota?->fecha,
            'codigo_proceso' => (string) ($seg->codigo_proceso ?? ''),
            'resultado_propio' => (string) ($seg->resultado_propio ?? ''),
            'participacion_mp' => $estadoParticipacion,
            'participacion_mp_label' => $this->labelParticipacionMp($estadoParticipacion),
            'participo_mp' => $participoMp,
            'es_ganada' => $esGanada,
            'orden_compra' => $ordenCompra,
            'fecha_envio_oc' => $this->fechaEnvioOUltimaModificacion($seg),
            'ejecutivo' => $ejecutivo !== '' ? $ejecutivo : '—',
            'ejecutivo_username' => $ejecutivoUsername,
            'region_nombre' => $this->regionNombreNota($nota),
            'zona_resumen' => $this->zonaResumenNota($nota, $factor),
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

    private function regionNombreNota(?Nota $nota): string
    {
        if ($nota === null) {
            return '—';
        }

        $nombre = trim((string) ($nota->nombre_region ?? ''));
        if ($nombre !== '') {
            return $nombre;
        }

        $region = $nota->region !== null ? (int) $nota->region : 0;
        if ($region > 0) {
            return CompraAgilRegionScope::nombreRegion($region);
        }

        return '—';
    }

    /**
     * Zona para resumen: región de la nota, o por factor si falta (1,22 = Metropolitana).
     */
    private function zonaResumenNota(?Nota $nota, float $factor): string
    {
        $region = $nota?->region !== null ? (int) $nota->region : 0;
        if ($region > 0) {
            return CompraAgilRegionScope::esMetropolitana($region)
                ? self::ZONA_METROPOLITANA
                : self::ZONA_REGION;
        }

        $factorRm = round((float) config('cotiz.factor_precio_venta_rm', 1.22), 2);

        return abs(round($factor, 2) - $factorRm) < 0.001
            ? self::ZONA_METROPOLITANA
            : self::ZONA_REGION;
    }
}
