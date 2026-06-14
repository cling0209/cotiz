<?php

namespace App\Services;

use App\Models\MaeprodImportRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MaeprodImportProgressService
{
    public const TTL_SECONDS = 7200;

    public const STALE_WARNING_SECONDS = 480;

    public const ZOMBIE_FAIL_SECONDS = 2700;

    public const PHASE_QUEUED = 'queued';

    public const PHASE_PREPARE = 'prepare';

    public const PHASE_PROCESS = 'process';

    public const PHASE_COMPLETED = 'completed';

    public const PHASE_FAILED = 'failed';

    /**
     * @param  array<string, string|null>|null  $columnMapping
     */
    public function beginQueued(
        string $uploadId,
        int $userId,
        string $mode,
        ?array $columnMapping = null,
    ): void {
        $this->write($uploadId, [
            'upload_id' => $uploadId,
            'user_id' => $userId,
            'mode' => $mode,
            'column_mapping' => $columnMapping,
            'phase' => self::PHASE_QUEUED,
            'stage' => 'En cola',
            'detail' => 'Esperando worker en segundo plano...',
            'percent' => 0,
            'processed_rows' => 0,
            'total_rows' => null,
            'processed_batches' => 0,
            'total_batches' => null,
            'result' => null,
            'redirect' => null,
            'error' => null,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function setPhase(string $uploadId, string $phase, string $stage, string $detail, float $percent = 0): void
    {
        $current = $this->read($uploadId) ?? [];
        $this->write($uploadId, array_merge($current, [
            'phase' => $phase,
            'stage' => $stage,
            'detail' => $detail,
            'percent' => max(0, min(100, $percent)),
            'updated_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $prepare
     */
    public function updateFromPrepare(string $uploadId, array $prepare): void
    {
        $processed = (int) ($prepare['processed_rows'] ?? 0);
        $total = isset($prepare['total_rows']) ? (int) $prepare['total_rows'] : null;
        $percent = 12;

        if ($total !== null && $total > 0) {
            $percent = 12 + (($processed / $total) * 26);
        } elseif ($processed > 0) {
            $percent = min(38, 12 + ($processed / 50000) * 26);
        }

        $detail = $total !== null && $total > 0
            ? sprintf('%s de %s filas convertidas a CSV', number_format($processed, 0, '', '.'), number_format($total, 0, '', '.'))
            : sprintf('%s filas convertidas a CSV', number_format($processed, 0, '', '.'));

        $current = $this->read($uploadId) ?? [];
        $this->write($uploadId, array_merge($current, [
            'phase' => self::PHASE_PREPARE,
            'stage' => 'Convirtiendo Excel a CSV',
            'detail' => $detail,
            'percent' => $percent,
            'processed_rows' => $processed,
            'total_rows' => $total,
            'total_batches' => isset($prepare['batch_count']) ? (int) $prepare['batch_count'] : ($current['total_batches'] ?? null),
            'updated_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * @param  array{created?: int, updated?: int, skipped?: int}|null  $currentResult
     */
    public function beginBatch(
        string $uploadId,
        int $batchNumber,
        int $totalBatches,
        ?array $currentResult = null,
    ): void {
        $current = $this->read($uploadId) ?? [];
        $result = $currentResult ?? ($current['result'] ?? []);
        $created = (int) ($result['created'] ?? 0);
        $updated = (int) ($result['updated'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);

        $detail = sprintf(
            'Procesando lote %d de %d — creados: %s, actualizados: %s, omitidos: %s',
            $batchNumber,
            $totalBatches,
            number_format($created, 0, '', '.'),
            number_format($updated, 0, '', '.'),
            number_format($skipped, 0, '', '.'),
        );

        $percent = 38 + ((max(0, $batchNumber - 1) / max(1, $totalBatches)) * 62);

        $this->write($uploadId, array_merge($current, [
            'phase' => self::PHASE_PROCESS,
            'stage' => 'Grabando en base de datos',
            'detail' => $detail,
            'percent' => min(99, $percent),
            'processing_batch' => $batchNumber,
            'total_batches' => $totalBatches,
            'updated_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array<string, mixed>
     */
    public function enrichForPoll(string $uploadId, array $progress): array
    {
        $updatedAt = isset($progress['updated_at'])
            ? Carbon::parse($progress['updated_at'])
            : null;
        $secondsSinceUpdate = $updatedAt !== null ? (int) $updatedAt->diffInSeconds(now()) : null;
        $progress['seconds_since_update'] = $secondsSinceUpdate;

        if ($this->shouldFailAsZombie($uploadId, $progress, $secondsSinceUpdate)) {
            $message = 'La importación dejó de avanzar (el worker en segundo plano puede haberse detenido). '
                .'Recargue la página; si no retoma, use «Liberar bloqueo» e intente de nuevo.';
            $this->fail($uploadId, $message);

            return $this->read($uploadId) ?? $progress;
        }

        $phase = (string) ($progress['phase'] ?? '');
        if ($secondsSinceUpdate !== null
            && $secondsSinceUpdate >= self::STALE_WARNING_SECONDS
            && in_array($phase, [self::PHASE_QUEUED, self::PHASE_PREPARE, self::PHASE_PROCESS], true)) {
            $minutes = max(1, (int) floor($secondsSinceUpdate / 60));
            $progress['stale_warning'] = "Sin actualización desde hace {$minutes} min. "
                .'Cada lote procesa hasta 5.000 filas y en producción puede tardar varios minutos.';
        }

        return $progress;
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    protected function shouldFailAsZombie(string $uploadId, array $progress, ?int $secondsSinceUpdate): bool
    {
        if ($secondsSinceUpdate === null || $secondsSinceUpdate < self::ZOMBIE_FAIL_SECONDS) {
            return false;
        }

        $phase = (string) ($progress['phase'] ?? '');
        if (in_array($phase, [self::PHASE_COMPLETED, self::PHASE_FAILED], true)) {
            return false;
        }

        if ($this->hasFailedQueueJob($uploadId)) {
            return true;
        }

        if (! app(MaeprodImportLockService::class)->hasActiveWork($uploadId)) {
            return false;
        }

        return ! $this->hasPendingQueueJob($uploadId);
    }

    protected function hasPendingQueueJob(string $uploadId): bool
    {
        return DB::table(config('queue.connections.database.table', 'jobs'))
            ->where('payload', 'like', '%'.$uploadId.'%')
            ->exists();
    }

    protected function hasFailedQueueJob(string $uploadId): bool
    {
        return DB::table('failed_jobs')
            ->where('payload', 'like', '%'.$uploadId.'%')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $process
     */
    public function updateFromProcess(string $uploadId, array $process): void
    {
        $processedBatches = (int) ($process['processed_batches'] ?? 0);
        $totalBatches = max(1, (int) ($process['total_batches'] ?? 1));
        $processedRows = (int) ($process['processed_rows'] ?? 0);
        $totalRows = isset($process['total_rows']) ? (int) $process['total_rows'] : null;
        $percent = 38 + (($processedBatches / $totalBatches) * 62);
        $result = $process['result'] ?? [];
        $created = (int) ($result['created'] ?? 0);
        $updated = (int) ($result['updated'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);

        $detail = sprintf(
            'Lote %d de %d — creados: %s, actualizados: %s, omitidos: %s',
            $processedBatches,
            $totalBatches,
            number_format($created, 0, '', '.'),
            number_format($updated, 0, '', '.'),
            number_format($skipped, 0, '', '.'),
        );

        $current = $this->read($uploadId) ?? [];
        $this->write($uploadId, array_merge($current, [
            'phase' => self::PHASE_PROCESS,
            'stage' => 'Grabando en base de datos',
            'detail' => $detail,
            'percent' => min(99, $percent),
            'processed_rows' => $processedRows,
            'total_rows' => $totalRows,
            'processed_batches' => $processedBatches,
            'total_batches' => $totalBatches,
            'result' => [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ],
            'updated_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $process
     */
    public function complete(string $uploadId, array $process): void
    {
        $result = $process['result'] ?? [];
        $redirect = null;

        if (isset($process['run_id'])) {
            $run = MaeprodImportRun::query()->find((int) $process['run_id']);
            if ($run !== null) {
                $redirect = app(MaeprodImportRunService::class)->redirectUrlForRun($run);
            }
        }

        $current = $this->read($uploadId) ?? [];
        $this->write($uploadId, array_merge($current, [
            'phase' => self::PHASE_COMPLETED,
            'stage' => 'Completado',
            'detail' => 'Importación finalizada.',
            'percent' => 100,
            'processed_batches' => (int) ($process['processed_batches'] ?? 0),
            'total_batches' => (int) ($process['total_batches'] ?? 0),
            'processed_rows' => (int) ($process['processed_rows'] ?? 0),
            'total_rows' => isset($process['total_rows']) ? (int) $process['total_rows'] : null,
            'result' => [
                'created' => (int) ($result['created'] ?? 0),
                'updated' => (int) ($result['updated'] ?? 0),
                'skipped' => (int) ($result['skipped'] ?? 0),
            ],
            'redirect' => $redirect,
            'error' => null,
            'updated_at' => now()->toIso8601String(),
        ]));
    }

    public function fail(string $uploadId, string $message): void
    {
        $current = $this->read($uploadId) ?? [];
        $this->write($uploadId, array_merge($current, [
            'phase' => self::PHASE_FAILED,
            'stage' => 'Error',
            'detail' => $message,
            'error' => $message,
            'updated_at' => now()->toIso8601String(),
        ]));
    }

    public function has(string $uploadId): bool
    {
        return Cache::has($this->cacheKey($uploadId));
    }

    public function isActive(string $uploadId): bool
    {
        $progress = $this->read($uploadId);

        if ($progress === null) {
            return false;
        }

        return ! in_array($progress['phase'] ?? '', [self::PHASE_COMPLETED, self::PHASE_FAILED], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $uploadId): ?array
    {
        $progress = Cache::get($this->cacheKey($uploadId));

        return is_array($progress) ? $progress : null;
    }

    public function forget(string $uploadId): void
    {
        Cache::forget($this->cacheKey($uploadId));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function write(string $uploadId, array $data): void
    {
        Cache::put($this->cacheKey($uploadId), $data, self::TTL_SECONDS);
    }

    protected function cacheKey(string $uploadId): string
    {
        return 'maeprod_import_progress:'.$uploadId;
    }
}
