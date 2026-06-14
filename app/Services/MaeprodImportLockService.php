<?php

namespace App\Services;

use App\Models\MaeprodImportStaging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class MaeprodImportLockService
{
    public const CACHE_KEY = 'maeprod_import_lock';

    public const TTL_SECONDS = 1800;

    /**
     * @return array{user_id: int, username: string, upload_id: string, original_name: string, started_at: string}|null
     */
    public function current(): ?array
    {
        $lock = Cache::get(self::CACHE_KEY);

        if (! is_array($lock) || ! isset($lock['upload_id'], $lock['username'], $lock['started_at'])) {
            return null;
        }

        return $lock;
    }

    /**
     * Devuelve el bloqueo activo o lo libera si quedó huérfano (p. ej. tras un 502).
     *
     * @return array{user_id: int, username: string, upload_id: string, original_name: string, started_at: string}|null
     */
    public function currentOrReleaseIfAbandoned(): ?array
    {
        $current = $this->current();

        if ($current === null) {
            return null;
        }

        if ($this->hasActiveWork((string) $current['upload_id'])) {
            return $current;
        }

        $this->release((string) $current['upload_id']);

        return null;
    }

    public function hasActiveWork(string $uploadId): bool
    {
        if (File::isDirectory(storage_path('app/imports/chunks/'.$uploadId))) {
            return true;
        }

        if (app(MaeprodImportPendingService::class)->has($uploadId)) {
            return true;
        }

        $prepareStatePath = storage_path('app/imports/jobs/'.$uploadId.'/prepare-state.json');

        if (File::exists($prepareStatePath)) {
            return true;
        }

        if (MaeprodImportStaging::query()->where('upload_id', $uploadId)->exists()) {
            return true;
        }

        $jobPath = storage_path('app/imports/jobs/'.$uploadId.'/job.json');

        if (! File::exists($jobPath)) {
            return false;
        }

        $job = json_decode(File::get($jobPath), true);

        if (! is_array($job)) {
            return false;
        }

        if (($job['import_mode'] ?? MaeprodImportJobService::IMPORT_MODE_BATCH) === MaeprodImportJobService::IMPORT_MODE_STREAM) {
            $processedRows = (int) ($job['processed_rows'] ?? 0);
            $totalRows = (int) ($job['total_rows'] ?? 0);

            if ($totalRows < 1) {
                return File::exists((string) ($job['source_path'] ?? ''));
            }

            return $processedRows < $totalRows;
        }

        return (int) ($job['next_batch'] ?? 0) < (int) ($job['batch_count'] ?? 0);
    }

    public function isBlockedFor(string $uploadId): bool
    {
        $current = $this->current();

        return $current !== null && $current['upload_id'] !== $uploadId;
    }

    public function acquire(int $userId, string $username, string $uploadId, string $originalName): void
    {
        $current = $this->current();

        if ($current !== null && $current['upload_id'] !== $uploadId) {
            $started = Carbon::parse($current['started_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i');

            throw new \InvalidArgumentException(
                "Hay una importación en curso iniciada por {$current['username']} el {$started}. Espere a que termine antes de cargar otro archivo.",
            );
        }

        Cache::put(self::CACHE_KEY, [
            'user_id' => $userId,
            'username' => $username,
            'upload_id' => $uploadId,
            'original_name' => $originalName,
            'started_at' => now()->toIso8601String(),
        ], self::TTL_SECONDS);
    }

    public function touch(string $uploadId): void
    {
        $current = $this->current();

        if ($current === null || $current['upload_id'] !== $uploadId) {
            return;
        }

        Cache::put(self::CACHE_KEY, $current, self::TTL_SECONDS);
    }

    public function release(string $uploadId): void
    {
        $current = $this->current();

        if ($current !== null && $current['upload_id'] === $uploadId) {
            Cache::forget(self::CACHE_KEY);
        }
    }

    public function forceRelease(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
