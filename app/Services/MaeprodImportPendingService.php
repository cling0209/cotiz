<?php

namespace App\Services;

use App\Support\MaeprodImportFileTypes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MaeprodImportPendingService
{
    public function register(
        string $uploadId,
        string $mergedPath,
        int $userId,
        string $username,
        string $originalName,
        string $mode,
    ): void {
        $this->assertValidUploadId($uploadId);
        File::ensureDirectoryExists($this->pendingDirectory());

        File::put($this->pendingPath($uploadId), json_encode([
            'user_id' => $userId,
            'username' => $username,
            'original_name' => $originalName,
            'merged_path' => $mergedPath,
            'mode' => $mode,
            'created_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{
     *     user_id: int,
     *     username: string,
     *     original_name: string,
     *     merged_path: string,
     *     mode: string,
     *     created_at: string
     * }
     */
    public function find(string $uploadId): array
    {
        $this->assertValidUploadId($uploadId);
        $path = $this->pendingPath($uploadId);

        if (! File::exists($path)) {
            throw new \InvalidArgumentException('La importación no está lista o ya expiró.');
        }

        $pending = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($pending)
            || ! isset($pending['user_id'], $pending['username'], $pending['original_name'], $pending['merged_path'], $pending['mode'])) {
            throw new \InvalidArgumentException('Metadatos de importación pendiente inválidos.');
        }

        if (! is_file($pending['merged_path'])) {
            throw new \InvalidArgumentException('No se encontró el archivo subido. Vuelva a cargarlo.');
        }

        return $pending;
    }

    public function has(string $uploadId): bool
    {
        if (! Str::isUuid($uploadId)) {
            return false;
        }

        return File::exists($this->pendingPath($uploadId));
    }

    public function consume(string $uploadId): void
    {
        if (! $this->has($uploadId)) {
            return;
        }

        try {
            $pending = $this->find($uploadId);
            $mergedPath = (string) $pending['merged_path'];
            $jobDir = storage_path('app/imports/jobs/'.$uploadId);
            $mergedReal = realpath($mergedPath);
            $jobReal = is_dir($jobDir) ? realpath($jobDir) : false;
            $mergedIsJobSource = $mergedReal !== false
                && $jobReal !== false
                && str_starts_with($mergedReal, $jobReal);

            if (! $mergedIsJobSource && File::exists($mergedPath)) {
                File::delete($mergedPath);
            }
        } catch (\InvalidArgumentException) {
            // ignore
        }

        File::delete($this->pendingPath($uploadId));
    }

    public function mergedPathFor(string $uploadId, string $originalName): string
    {
        $extension = MaeprodImportFileTypes::extensionFromName($originalName);

        return storage_path('app/imports/merged/'.$uploadId.'.'.$extension);
    }

    protected function pendingDirectory(): string
    {
        return storage_path('app/imports/pending');
    }

    protected function pendingPath(string $uploadId): string
    {
        return $this->pendingDirectory().'/'.$uploadId.'.json';
    }

    protected function assertValidUploadId(string $uploadId): void
    {
        if (! Str::isUuid($uploadId)) {
            throw new \InvalidArgumentException('Identificador de carga inválido.');
        }
    }
}
