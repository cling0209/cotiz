<?php

namespace App\Services;

use App\Jobs\ProcessCompraAgilReporteExportJob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class CompraAgilReporteExportService
{
    public const TYPE_PRODUCTOS_GANADOS = 'productos_ganados';

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

        $dispatch = ProcessCompraAgilReporteExportJob::dispatch($jobId);

        if (config('queue.default') !== 'sync') {
            $dispatch->afterResponse();
        }

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

            if ($type === self::TYPE_PRODUCTOS_GANADOS) {
                $this->generarCsvProductosGanados($jobId, $path, $filtros);
            } else {
                throw new RuntimeException('Tipo de reporte no soportado.');
            }

            $this->patch($jobId, [
                'status' => self::STATUS_COMPLETED,
                'percent' => 100,
                'detail' => 'Listo para descargar.',
                'file_path' => $path,
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
     * @param  array<string, mixed>  $filtros
     */
    private function generarCsvProductosGanados(string $jobId, string $path, array $filtros): void
    {
        $this->patch($jobId, [
            'percent' => 35,
            'detail' => 'Agregando productos ganados…',
        ]);

        $filas = $this->resultados->productosGanadosExportar($filtros);
        $total = $filas->count();

        $this->patch($jobId, [
            'percent' => 55,
            'detail' => $total > 0
                ? sprintf('Escribiendo CSV (%s filas)…', number_format($total, 0, '', '.'))
                : 'Escribiendo CSV…',
        ]);

        $out = fopen($path, 'w');
        if ($out === false) {
            throw new RuntimeException('No se pudo crear el archivo CSV.');
        }

        fprintf($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Código producto',
            'Producto',
            'Ganador',
            'Cantidad acumulada',
            'Monto venta acumulado',
        ], ';');

        $written = 0;
        foreach ($filas as $f) {
            fputcsv($out, [
                $f->codigo_producto,
                $f->nombre_producto,
                $f->ganador,
                $f->cantidad_acumulada,
                $f->monto_venta_acumulado,
            ], ';');
            $written++;
            if ($total > 0 && ($written % 50 === 0 || $written === $total)) {
                $percent = 55 + (int) round(($written / $total) * 40);
                $this->patch($jobId, [
                    'percent' => min(95, $percent),
                    'detail' => sprintf('Escribiendo CSV (%s / %s)…', number_format($written, 0, '', '.'), number_format($total, 0, '', '.')),
                ]);
            }
        }

        fclose($out);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    private function validarFiltrosProductosGanados(string $type, array $filtros): void
    {
        if ($type !== self::TYPE_PRODUCTOS_GANADOS) {
            return;
        }

        if (empty($filtros['fecha_desde']) || empty($filtros['fecha_hasta'])) {
            throw new RuntimeException('Indique publicación desde y hasta.');
        }
    }

    private function assertSupportedType(string $type): void
    {
        if ($type !== self::TYPE_PRODUCTOS_GANADOS) {
            throw new RuntimeException('Tipo de reporte no soportado.');
        }
    }

    private function buildFilename(string $type): string
    {
        return match ($type) {
            self::TYPE_PRODUCTOS_GANADOS => 'productos_ganados_'.now()->format('Ymd_His').'.csv',
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
