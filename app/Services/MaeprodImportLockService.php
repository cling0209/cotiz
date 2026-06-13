<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
}
