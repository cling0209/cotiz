<?php

namespace App\Services;

use App\Jobs\ProcessCompraAgilReporteExportJob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompraAgilReporteExportService
{
    public const TYPE_PRODUCTOS_GANADOS = 'productos_ganados';

    public const TYPE_PRODUCTOS_GANADOS_DETALLE = 'productos_ganados_detalle';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const TTL_SECONDS = 3600;

    public function __construct(
        protected NotaMpResultadosService $resultados,
    ) {}

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function encolar(string $type, int $userId, array $filtros): string
    {
        $this->assertSupportedType($type);
        $this->validarFiltrosProductosGanados($type, $filtros);

        $jobId = (string) Str::uuid();
        $filename = $this->buildFilename($type);

        $this->write($jobId, [
            'job_id' => $jobId,
            'user_id' => $userId,
            'type' => $type,
            'filtros' => $filtros,
            'status' => self::STATUS_QUEUED,
            'percent' => 0,
            'detail' => 'En cola…',
            'filename' => $filename,
            'file_path' => null,
            'error' => null,
            'updated_at' => now()->toIso8601String(),
        ]);

        // sync + afterResponse: corre tras la respuesta HTTP, sin depender del worker database.
        ProcessCompraAgilReporteExportJob::dispatch($jobId)
            ->onConnection('sync')
            ->afterResponse();

        return $jobId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $jobId): ?array
    {
        $payload = cache()->get($this->cacheKey($jobId));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function estadoParaPoll(string $jobId, int $userId): array
    {
        $payload = $this->read($jobId);
        if ($payload === null) {
            throw new RuntimeException('Exportación no encontrada o expirada.');
        }

        if ((int) ($payload['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('No autorizado.');
        }

        $status = (string) ($payload['status'] ?? self::STATUS_QUEUED);
        $percent = (int) ($payload['percent'] ?? 0);

        return [
            'job_id' => $jobId,
            'status' => $status,
            'percent' => $percent,
            'detail' => (string) ($payload['detail'] ?? ''),
            'filename' => (string) ($payload['filename'] ?? ''),
            'error' => $payload['error'] ?? null,
            'download_url' => $status === self::STATUS_COMPLETED
                ? route('admin.compra-agil.resultados.reportes.exportaciones.descargar', ['jobId' => $jobId])
                : null,
            'row_count' => (int) ($payload['row_count'] ?? 0),
            'updated_at' => $payload['updated_at'] ?? null,
        ];
    }

    public function run(string $jobId): void
    {
        $payload = $this->read($jobId);
        if ($payload === null) {
            return;
        }

        $type = (string) ($payload['type'] ?? '');
        $filtros = is_array($payload['filtros'] ?? null) ? $payload['filtros'] : [];

        try {
            $this->patch($jobId, [
                'status' => self::STATUS_PROCESSING,
                'percent' => 10,
                'detail' => 'Consultando datos…',
            ]);

            $directory = $this->jobDirectory($jobId);
            File::ensureDirectoryExists($directory);

            $path = $directory.'/'.(string) ($payload['filename'] ?? 'reporte.csv');

            $rowCount = 0;
            if ($type === self::TYPE_PRODUCTOS_GANADOS) {
                $rowCount = $this->generarCsvProductosGanados($jobId, $path, $filtros);
            } elseif ($type === self::TYPE_PRODUCTOS_GANADOS_DETALLE) {
                $rowCount = $this->generarCsvProductosGanadosDetalle($jobId, $path, $filtros);
            } else {
                throw new RuntimeException('Tipo de reporte no soportado.');
            }

            $this->patch($jobId, [
                'status' => self::STATUS_COMPLETED,
                'percent' => 100,
                'detail' => $rowCount > 0
                    ? 'Listo para descargar.'
                    : 'Listo (sin filas para los filtros aplicados).',
                'file_path' => $path,
                'row_count' => $rowCount,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->patch($jobId, [
                'status' => self::STATUS_FAILED,
                'percent' => 100,
                'detail' => 'Error al generar el reporte.',
                'error' => $e->getMessage(),
            ]);

            $this->cleanupFiles($jobId);

            throw $e;
        }
    }

    /**
     * @return array{path: string, filename: string}
     */
    public function resolveDownload(int $userId, string $jobId): array
    {
        $payload = $this->read($jobId);
        if ($payload === null) {
            throw new RuntimeException('Exportación no encontrada o ya descargada.');
        }

        if ((int) ($payload['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('No autorizado.');
        }

        if (($payload['status'] ?? '') !== self::STATUS_COMPLETED) {
            throw new RuntimeException('El reporte aún no está listo.');
        }

        $path = (string) ($payload['file_path'] ?? '');
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('Archivo no disponible.');
        }

        return [
            'path' => $path,
            'filename' => (string) ($payload['filename'] ?? basename($path)),
        ];
    }

    public function forget(string $jobId): void
    {
        cache()->forget($this->cacheKey($jobId));
    }

    public function cleanupDirectory(string $jobId): void
    {
        $this->cleanupFiles($jobId);
    }

    /**
     * Descarga directa del CSV (sin cola ni polling).
     *
     * @param  array<string, mixed>  $filtros
     */
    public function streamProductosGanados(string $type, array $filtros): StreamedResponse
    {
        $this->assertSupportedType($type);
        $this->validarFiltrosProductosGanados($type, $filtros);

        $filename = $this->buildFilename($type);

        return response()->streamDownload(function () use ($type, $filtros): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new RuntimeException('No se pudo crear el archivo CSV.');
            }

            if ($type === self::TYPE_PRODUCTOS_GANADOS) {
                $this->escribirCsvProductosGanadosResumen($out, $filtros);
            } else {
                $this->escribirCsvProductosGanadosDetalle($out, $filtros);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    private function generarCsvProductosGanados(string $jobId, string $path, array $filtros): int
    {
        $this->patch($jobId, [
            'percent' => 35,
            'detail' => 'Agregando productos del proveedor seleccionado…',
        ]);

        $out = fopen($path, 'w');
        if ($out === false) {
            throw new RuntimeException('No se pudo crear el archivo CSV.');
        }

        $total = $this->escribirCsvProductosGanadosResumen($out, $filtros, $jobId);
        fclose($out);

        return $total;
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    private function generarCsvProductosGanadosDetalle(string $jobId, string $path, array $filtros): int
    {
        $this->patch($jobId, [
            'percent' => 35,
            'detail' => 'Consultando detalle de cotizaciones…',
        ]);

        $out = fopen($path, 'w');
        if ($out === false) {
            throw new RuntimeException('No se pudo crear el archivo CSV.');
        }

        $total = $this->escribirCsvProductosGanadosDetalle($out, $filtros, $jobId);
        fclose($out);

        return $total;
    }

    /**
     * @param  resource  $out
     * @param  array<string, mixed>  $filtros
     */
    private function escribirCsvProductosGanadosResumen($out, array $filtros, ?string $jobId = null): int
    {
        $filas = $this->resultados->productosGanadosExportar($filtros);
        $total = $filas->count();

        if ($jobId !== null) {
            $this->patch($jobId, [
                'percent' => 55,
                'detail' => $total > 0
                    ? sprintf('Escribiendo CSV (%s filas)…', number_format($total, 0, '', '.'))
                    : 'Escribiendo CSV…',
            ]);
        }

        fprintf($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Código producto',
            'Producto',
            'Proveedor seleccionado',
            'Cantidad acumulada',
            'Monto venta acumulado',
        ], ';');

        $written = 0;
        foreach ($filas as $f) {
            fputcsv($out, [
                $f->codigo_producto,
                $f->nombre_producto,
                $f->proveedor_seleccionado,
                $f->cantidad_acumulada,
                $f->monto_venta_acumulado,
            ], ';');
            $written++;
            if ($jobId !== null && $total > 0 && ($written % 50 === 0 || $written === $total)) {
                $percent = 55 + (int) round(($written / $total) * 40);
                $this->patch($jobId, [
                    'percent' => min(95, $percent),
                    'detail' => sprintf('Escribiendo CSV (%s / %s)…', number_format($written, 0, '', '.'), number_format($total, 0, '', '.')),
                ]);
            }
        }

        return $total;
    }

    /**
     * @param  resource  $out
     * @param  array<string, mixed>  $filtros
     */
    private function escribirCsvProductosGanadosDetalle($out, array $filtros, ?string $jobId = null): int
    {
        $filas = $this->resultados->productosGanadosDetalleExportar($filtros);
        $total = $filas->count();

        if ($jobId !== null) {
            $this->patch($jobId, [
                'percent' => 55,
                'detail' => $total > 0
                    ? sprintf('Escribiendo CSV detalle (%s filas)…', number_format($total, 0, '', '.'))
                    : 'Escribiendo CSV…',
            ]);
        }

        fprintf($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Número nota',
            'Número cotización',
            'Orden de compra',
            'Código producto',
            'Producto',
            'Proveedor seleccionado',
            'Cantidad',
            'Valor',
            'Total',
        ], ';');

        $written = 0;
        foreach ($filas as $f) {
            fputcsv($out, [
                $f->nronota,
                $f->numero_cotizacion,
                $f->orden_compra ?? '',
                $f->codigo_producto,
                $f->nombre_producto,
                $f->proveedor_seleccionado,
                $f->cantidad,
                $f->valor,
                $f->total,
            ], ';');
            $written++;
            if ($jobId !== null && $total > 0 && ($written % 50 === 0 || $written === $total)) {
                $percent = 55 + (int) round(($written / $total) * 40);
                $this->patch($jobId, [
                    'percent' => min(95, $percent),
                    'detail' => sprintf('Escribiendo CSV (%s / %s)…', number_format($written, 0, '', '.'), number_format($total, 0, '', '.')),
                ]);
            }
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    private function validarFiltrosProductosGanados(string $type, array $filtros): void
    {
        if (! in_array($type, [self::TYPE_PRODUCTOS_GANADOS, self::TYPE_PRODUCTOS_GANADOS_DETALLE], true)) {
            return;
        }

        if (empty($filtros['fecha_desde']) || empty($filtros['fecha_hasta'])) {
            throw new RuntimeException('Indique fecha desde y hasta.');
        }

        $tipoFecha = strtolower(trim((string) ($filtros['tipo_fecha'] ?? 'cierre')));
        if (! in_array($tipoFecha, ['publicacion', 'cierre'], true)) {
            throw new RuntimeException('Tipo de fecha inválido.');
        }
    }

    private function assertSupportedType(string $type): void
    {
        if (! in_array($type, [self::TYPE_PRODUCTOS_GANADOS, self::TYPE_PRODUCTOS_GANADOS_DETALLE], true)) {
            throw new RuntimeException('Tipo de reporte no soportado.');
        }
    }

    private function buildFilename(string $type): string
    {
        return match ($type) {
            self::TYPE_PRODUCTOS_GANADOS => 'productos_proveedor_seleccionado_resumen_'.now()->format('Ymd_His').'.csv',
            self::TYPE_PRODUCTOS_GANADOS_DETALLE => 'productos_proveedor_seleccionado_detalle_'.now()->format('Ymd_His').'.csv',
            default => 'reporte_'.now()->format('Ymd_His').'.csv',
        };
    }

    private function jobDirectory(string $jobId): string
    {
        return storage_path('app/compra-agil-reportes/'.$jobId);
    }

    private function cleanupFiles(string $jobId): void
    {
        $dir = $this->jobDirectory($jobId);
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
    }

    private function cacheKey(string $jobId): string
    {
        return 'compra_agil_reporte_export:'.$jobId;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(string $jobId, array $data): void
    {
        cache()->put($this->cacheKey($jobId), $data, self::TTL_SECONDS);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function patch(string $jobId, array $data): void
    {
        $current = $this->read($jobId) ?? [];
        $this->write($jobId, array_merge($current, $data, [
            'updated_at' => now()->toIso8601String(),
        ]));
    }
}
