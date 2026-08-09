<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class MaterialesImportLockService
{
    public const CACHE_KEY = 'materiales_import_lock';

    /** Tiempo máximo de análisis (OCR largo). */
    public const TTL_SECONDS = 2700;

    /** Sin actividad durante este tiempo → lock huérfano liberable. */
    public const ABANDON_MINUTES = 20;

    /**
     * @return array{
     *     user_id: int,
     *     username: string,
     *     lock_id: string,
     *     tipo: string,
     *     original_name: string,
     *     started_at: string,
     *     last_touch_at: string
     * }|null
     */
    public function current(): ?array
    {
        $lock = Cache::get(self::CACHE_KEY);

        if (! is_array($lock) || ! isset($lock['lock_id'], $lock['username'], $lock['started_at'])) {
            return null;
        }

        return $lock;
    }

    /**
     * @return array{
     *     user_id: int,
     *     username: string,
     *     lock_id: string,
     *     tipo: string,
     *     original_name: string,
     *     started_at: string,
     *     last_touch_at: string
     * }|null
     */
    public function currentOrReleaseIfAbandoned(): ?array
    {
        $current = $this->current();

        if ($current === null) {
            return null;
        }

        $lastTouch = Carbon::parse((string) ($current['last_touch_at'] ?? $current['started_at']));

        if ($lastTouch->diffInMinutes(now()) >= self::ABANDON_MINUTES) {
            $this->forceRelease();

            return null;
        }

        return $current;
    }

    public function isBlockedFor(string $lockId): bool
    {
        $current = $this->currentOrReleaseIfAbandoned();

        return $current !== null && $current['lock_id'] !== $lockId;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function acquire(
        int $userId,
        string $username,
        string $lockId,
        string $tipo,
        string $originalName,
    ): void {
        $this->currentOrReleaseIfAbandoned();
        $current = $this->current();

        if ($current !== null && $current['lock_id'] !== $lockId) {
            throw new \InvalidArgumentException($this->mensajeBloqueo($current));
        }

        $now = now()->toIso8601String();

        Cache::put(self::CACHE_KEY, [
            'user_id' => $userId,
            'username' => $username,
            'lock_id' => $lockId,
            'tipo' => $tipo,
            'original_name' => $originalName,
            'started_at' => $current['started_at'] ?? $now,
            'last_touch_at' => $now,
        ], self::TTL_SECONDS);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function assertOwnerOrRenew(string $lockId): void
    {
        $current = $this->currentOrReleaseIfAbandoned();

        if ($current === null) {
            throw new \InvalidArgumentException('El análisis expiró o fue liberado. Analice el archivo de nuevo.');
        }

        if ($current['lock_id'] !== $lockId) {
            throw new \InvalidArgumentException($this->mensajeBloqueo($current));
        }

        $this->touch($lockId);
    }

    public function touch(string $lockId): void
    {
        $current = $this->current();

        if ($current === null || $current['lock_id'] !== $lockId) {
            return;
        }

        $current['last_touch_at'] = now()->toIso8601String();

        Cache::put(self::CACHE_KEY, $current, self::TTL_SECONDS);
    }

    public function release(string $lockId): void
    {
        $current = $this->current();

        if ($current !== null && $current['lock_id'] === $lockId) {
            Cache::forget(self::CACHE_KEY);
        }
    }

    public function forceRelease(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array{username?: string, started_at?: string, tipo?: string, original_name?: string}  $lock
     */
    public function mensajeBloqueo(array $lock): string
    {
        $usuario = trim((string) ($lock['username'] ?? 'otro usuario'));
        $started = isset($lock['started_at'])
            ? Carbon::parse($lock['started_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i')
            : '';
        $archivo = trim((string) ($lock['original_name'] ?? ''));
        $tipo = strtoupper(trim((string) ($lock['tipo'] ?? '')));

        $detalle = trim(($tipo !== '' ? $tipo.' ' : '').($archivo !== '' ? "«{$archivo}»" : 'un archivo'));

        return $started !== ''
            ? "Hay un análisis en curso ({$detalle}) iniciado por {$usuario} el {$started}. Espere a que termine antes de analizar otro archivo."
            : "Hay un análisis en curso ({$detalle}) iniciado por {$usuario}. Espere a que termine antes de analizar otro archivo.";
    }
}
