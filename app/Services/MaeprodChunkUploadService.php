<?php

namespace App\Services;

use App\Support\MaeprodImportFileTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MaeprodChunkUploadService
{
    public const MAX_CHUNK_BYTES = 6291456;

    public const MAX_TOTAL_BYTES = 52428800;

    public const META_CACHE_TTL_SECONDS = 7200;

    /**
     * @return array{ready: bool, upload_id?: string, batch_count?: int}
     */
    public function storeChunk(
        string $uploadId,
        int $chunkIndex,
        int $totalChunks,
        string $originalName,
        UploadedFile $chunk,
        int $userId,
        string $username,
        string $mode = 'template',
    ): array {
        $this->assertValidUploadId($uploadId);
        $this->assertImportFileName($originalName);

        if ($totalChunks < 1 || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            throw new \InvalidArgumentException('Índice de chunk inválido.');
        }

        if ($chunk->getSize() > self::MAX_CHUNK_BYTES) {
            throw new \InvalidArgumentException('El fragmento supera el tamaño máximo permitido.');
        }

        $estimatedSize = $totalChunks * self::MAX_CHUNK_BYTES;

        if ($estimatedSize > self::MAX_TOTAL_BYTES) {
            throw new \InvalidArgumentException('El archivo completo supera el tamaño máximo permitido.');
        }

        if (! in_array($mode, ['template', 'custom'], true)) {
            throw new \InvalidArgumentException('Modo de importación inválido.');
        }

        $dir = $this->uploadDirectory($uploadId);
        File::ensureDirectoryExists($dir);

        if ($chunkIndex === 0) {
            File::cleanDirectory($dir);
            $this->writeMeta($uploadId, [
                'user_id' => $userId,
                'username' => $username,
                'original_name' => $originalName,
                'total_chunks' => $totalChunks,
                'mode' => $mode,
                'created_at' => now()->toIso8601String(),
            ]);
        } else {
            $this->recoverMetaIfNeeded(
                $uploadId,
                $chunkIndex,
                $totalChunks,
                $originalName,
                $userId,
                $username,
                $mode,
            );
        }

        $meta = $this->readMeta($uploadId);
        $mode = (string) ($meta['mode'] ?? $mode);

        if ($chunkIndex === 0 && $mode !== 'custom') {
            app(MaeprodImportLockService::class)->acquire(
                $userId,
                $username,
                $uploadId,
                $originalName,
            );
        } elseif ($chunkIndex > 0 && $mode !== 'custom') {
            app(MaeprodImportLockService::class)->touch($uploadId);
        }

        if ((int) $meta['user_id'] !== $userId) {
            throw new \InvalidArgumentException('No autorizado para continuar esta carga.');
        }

        if ($meta['total_chunks'] !== $totalChunks) {
            throw new \InvalidArgumentException('La cantidad total de fragmentos no coincide.');
        }

        $this->storeChunkFile($chunk, $dir.'/'.$this->chunkFilename($chunkIndex));
        $this->appendChunkToMergedFile(
            $uploadId,
            (string) $meta['original_name'],
            $dir.'/'.$this->chunkFilename($chunkIndex),
            $chunkIndex === 0,
        );

        if ($chunkIndex !== $totalChunks - 1) {
            return ['ready' => false];
        }

        @set_time_limit(120);
        @ini_set('memory_limit', '512M');

        $mergedPath = $this->mergedPathFor($uploadId, (string) $meta['original_name']);
        $isSpreadsheet = MaeprodImportFileTypes::isSpreadsheet((string) $meta['original_name']);

        if ($isSpreadsheet) {
            $extension = MaeprodImportFileTypes::extensionFromName((string) $meta['original_name']);
            $jobDir = storage_path('app/imports/jobs/'.$uploadId);
            File::ensureDirectoryExists($jobDir);

            if (File::isDirectory($jobDir)) {
                foreach (File::glob($jobDir.'/*') ?: [] as $artifact) {
                    if (is_file($artifact)) {
                        File::delete($artifact);
                    }
                }
            }

            $stablePath = $jobDir.'/source.'.$extension;

            if (! @rename($mergedPath, $stablePath)) {
                if (! File::copy($mergedPath, $stablePath)) {
                    throw new \RuntimeException('No se pudo resguardar el archivo Excel para la importación.');
                }

                File::delete($mergedPath);
            }
            File::put($jobDir.'/upload-meta.json', json_encode([
                'user_id' => $userId,
                'username' => (string) $meta['username'],
                'original_name' => (string) $meta['original_name'],
            ], JSON_THROW_ON_ERROR));

            app(MaeprodImportPendingService::class)->register(
                $uploadId,
                $stablePath,
                $userId,
                (string) $meta['username'],
                (string) $meta['original_name'],
                $mode,
            );

            $this->cleanup($uploadId);

            if ($mode === 'custom') {
                return [
                    'ready' => true,
                    'mode' => 'custom',
                    'upload_id' => $uploadId,
                    'pending_parse' => true,
                ];
            }

            return [
                'ready' => true,
                'mode' => 'template',
                'upload_id' => $uploadId,
                'pending_parse' => true,
            ];
        }

        try {
            if ($mode === 'custom') {
                $staged = app(MaeprodImportStagingService::class)->storeFromMergedCsv(
                    $uploadId,
                    $mergedPath,
                    $userId,
                    $meta['username'],
                    $meta['original_name'],
                );

                return [
                    'ready' => true,
                    'mode' => 'custom',
                    'upload_id' => $staged['upload_id'],
                    'columns' => $staged['columns'],
                    'total_rows' => $staged['total_rows'],
                    'suggested_mapping' => $staged['suggested_mapping'],
                ];
            }

            $prepared = app(MaeprodImportJobService::class)->beginStreamJobFromMergedFile(
                $uploadId,
                $mergedPath,
                $userId,
                $meta['username'],
                $meta['original_name'],
            );
        } finally {
            $this->cleanup($uploadId);

            if (File::exists($mergedPath)) {
                File::delete($mergedPath);
            }
        }

        $totalRows = (int) $prepared['total_rows'];

        return [
            'ready' => true,
            'mode' => 'template',
            'upload_id' => $prepared['upload_id'],
            'stream_mode' => true,
            'ready_to_process' => true,
            'total_rows' => $totalRows,
            'batch_count' => max(1, (int) ceil($totalRows / MaeprodImportJobService::ROWS_PER_STREAM_CHUNK)),
        ];
    }

    protected function mergeChunks(string $uploadId, int $totalChunks): string
    {
        $meta = $this->readMeta($uploadId);
        $mergedPath = $this->mergedPathFor($uploadId, (string) $meta['original_name']);

        if (! File::exists($mergedPath)) {
            throw new \InvalidArgumentException('El archivo fusionado no está completo.');
        }

        return $mergedPath;
    }

    protected function mergedPathFor(string $uploadId, string $originalName): string
    {
        File::ensureDirectoryExists(storage_path('app/imports/merged'));

        $extension = MaeprodImportFileTypes::extensionFromName($originalName);

        return storage_path('app/imports/merged/'.$uploadId.'.'.$extension);
    }

    protected function appendChunkToMergedFile(
        string $uploadId,
        string $originalName,
        string $partPath,
        bool $truncate,
    ): void {
        $mergedPath = $this->mergedPathFor($uploadId, $originalName);
        $output = fopen($mergedPath, $truncate ? 'wb' : 'ab');

        if ($output === false) {
            throw new \RuntimeException('No se pudo crear el archivo temporal.');
        }

        $input = fopen($partPath, 'rb');

        if ($input === false) {
            fclose($output);
            throw new \RuntimeException('No se pudo leer un fragmento de la carga.');
        }

        try {
            stream_copy_to_stream($input, $output);
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /**
     * @param  array{user_id: int, username: string, original_name: string, total_chunks: int, mode: string, created_at: string}  $meta
     */
    protected function writeMeta(string $uploadId, array $meta): void
    {
        $metaPath = $this->metaFilePath($uploadId);
        $encoded = json_encode($meta, JSON_THROW_ON_ERROR);

        if (! File::put($metaPath, $encoded)) {
            throw new \RuntimeException('No se pudo guardar el estado de la carga en el servidor. Verifique permisos de storage.');
        }

        Cache::put($this->metaCacheKey($uploadId), $meta, self::META_CACHE_TTL_SECONDS);
    }

    protected function recoverMetaIfNeeded(
        string $uploadId,
        int $chunkIndex,
        int $totalChunks,
        string $originalName,
        int $userId,
        string $username,
        string $mode,
    ): void {
        if ($this->hasMeta($uploadId)) {
            return;
        }

        if (! $this->canRecoverUploadState($uploadId, $chunkIndex, $originalName)) {
            throw new \InvalidArgumentException(
                'Se perdió el estado de la carga en el servidor. Pulse «Liberar carga atascada» e intente de nuevo sin cerrar esta ventana.',
            );
        }

        $this->writeMeta($uploadId, [
            'user_id' => $userId,
            'username' => $username,
            'original_name' => $originalName,
            'total_chunks' => $totalChunks,
            'mode' => $mode,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    protected function canRecoverUploadState(string $uploadId, int $chunkIndex, string $originalName): bool
    {
        if ($chunkIndex < 1) {
            return false;
        }

        $mergedPath = $this->mergedPathFor($uploadId, $originalName);

        if (File::exists($mergedPath) && (int) File::size($mergedPath) > 0) {
            return true;
        }

        $dir = $this->uploadDirectory($uploadId);

        for ($index = 0; $index < $chunkIndex; $index++) {
            if (File::exists($dir.'/'.$this->chunkFilename($index))) {
                return true;
            }
        }

        return false;
    }

    protected function hasMeta(string $uploadId): bool
    {
        if (Cache::has($this->metaCacheKey($uploadId))) {
            return true;
        }

        return File::exists($this->metaFilePath($uploadId));
    }

    /**
     * @return array{user_id: int, username: string, original_name: string, total_chunks: int, created_at: string, mode?: string}
     */
    protected function readMeta(string $uploadId): array
    {
        $cached = Cache::get($this->metaCacheKey($uploadId));

        if (is_array($cached) && isset($cached['user_id'], $cached['username'], $cached['original_name'], $cached['total_chunks'])) {
            return $cached;
        }

        $metaPath = $this->metaFilePath($uploadId);

        if (! File::exists($metaPath)) {
            throw new \InvalidArgumentException('La carga no fue iniciada correctamente.');
        }

        $meta = json_decode(File::get($metaPath), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($meta) || ! isset($meta['user_id'], $meta['username'], $meta['original_name'], $meta['total_chunks'])) {
            throw new \InvalidArgumentException('Metadatos de carga inválidos.');
        }

        Cache::put($this->metaCacheKey($uploadId), $meta, self::META_CACHE_TTL_SECONDS);

        return $meta;
    }

    protected function metaFilePath(string $uploadId): string
    {
        return $this->uploadDirectory($uploadId).'/meta.json';
    }

    protected function metaCacheKey(string $uploadId): string
    {
        return 'maeprod_import_chunk_meta:'.$uploadId;
    }

    protected function storeChunkFile(UploadedFile $chunk, string $destination): void
    {
        $source = $chunk->getRealPath();

        if ($source === false) {
            throw new \InvalidArgumentException('No se recibió el fragmento del archivo.');
        }

        $input = fopen($source, 'rb');

        if ($input === false) {
            throw new \RuntimeException('No se pudo leer el fragmento del archivo.');
        }

        $output = fopen($destination, 'wb');

        if ($output === false) {
            fclose($input);
            throw new \RuntimeException('No se pudo guardar el fragmento en el servidor.');
        }

        try {
            stream_copy_to_stream($input, $output);
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    protected function cleanup(string $uploadId): void
    {
        Cache::forget($this->metaCacheKey($uploadId));

        $dir = $this->uploadDirectory($uploadId);

        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
    }

    protected function uploadDirectory(string $uploadId): string
    {
        return storage_path('app/imports/chunks/'.$uploadId);
    }

    protected function chunkFilename(int $index): string
    {
        return 'part-'.str_pad((string) $index, 6, '0', STR_PAD_LEFT);
    }

    protected function assertValidUploadId(string $uploadId): void
    {
        if (! Str::isUuid($uploadId)) {
            throw new \InvalidArgumentException('Identificador de carga inválido.');
        }
    }

    protected function assertImportFileName(string $originalName): void
    {
        if (! MaeprodImportFileTypes::isAllowed($originalName)) {
            throw new \InvalidArgumentException('Solo se permiten archivos CSV o Excel (.xlsx, .xls).');
        }
    }
}
