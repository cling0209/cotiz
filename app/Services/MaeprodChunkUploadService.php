<?php

namespace App\Services;

use App\Support\MaeprodImportFileTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MaeprodChunkUploadService
{
    public const MAX_CHUNK_BYTES = 6291456;

    public const MAX_TOTAL_BYTES = 52428800;

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
            File::put($dir.'/meta.json', json_encode([
                'user_id' => $userId,
                'username' => $username,
                'original_name' => $originalName,
                'total_chunks' => $totalChunks,
                'mode' => $mode,
                'created_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
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

        if ($chunkIndex !== $totalChunks - 1) {
            return ['ready' => false];
        }

        $mergedPath = $this->mergeChunks($uploadId, $totalChunks);
        $isSpreadsheet = MaeprodImportFileTypes::isSpreadsheet((string) $meta['original_name']);

        if ($isSpreadsheet) {
            app(MaeprodImportPendingService::class)->register(
                $uploadId,
                $mergedPath,
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

            $prepared = app(MaeprodImportJobService::class)->prepareFromMergedFile(
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

        return [
            'ready' => true,
            'mode' => 'template',
            'upload_id' => $prepared['upload_id'],
            'batch_count' => $prepared['batch_count'],
        ];
    }

    protected function mergeChunks(string $uploadId, int $totalChunks): string
    {
        $dir = $this->uploadDirectory($uploadId);
        File::ensureDirectoryExists(storage_path('app/imports/merged'));

        $extension = MaeprodImportFileTypes::extensionFromName($this->readMeta($uploadId)['original_name'] ?? 'csv');
        $mergedPath = storage_path('app/imports/merged/'.$uploadId.'.'.$extension);
        $output = fopen($mergedPath, 'wb');

        if ($output === false) {
            throw new \RuntimeException('No se pudo crear el archivo temporal.');
        }

        for ($index = 0; $index < $totalChunks; $index++) {
            $partPath = $dir.'/'.$this->chunkFilename($index);

            if (! File::exists($partPath)) {
                fclose($output);
                File::delete($mergedPath);
                throw new \InvalidArgumentException('Falta el fragmento '.($index + 1).' de '.$totalChunks.'.');
            }

            $input = fopen($partPath, 'rb');

            if ($input === false) {
                fclose($output);
                File::delete($mergedPath);
                throw new \RuntimeException('No se pudo leer un fragmento de la carga.');
            }

            stream_copy_to_stream($input, $output);
            fclose($input);
        }

        fclose($output);

        return $mergedPath;
    }

    /**
     * @return array{user_id: int, username: string, original_name: string, total_chunks: int, created_at: string}
     */
    protected function readMeta(string $uploadId): array
    {
        $metaPath = $this->uploadDirectory($uploadId).'/meta.json';

        if (! File::exists($metaPath)) {
            throw new \InvalidArgumentException('La carga no fue iniciada correctamente.');
        }

        $meta = json_decode(File::get($metaPath), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($meta) || ! isset($meta['user_id'], $meta['username'], $meta['original_name'], $meta['total_chunks'])) {
            throw new \InvalidArgumentException('Metadatos de carga inválidos.');
        }

        return $meta;
    }

    protected function storeChunkFile(UploadedFile $chunk, string $destination): void
    {
        $source = $chunk->getRealPath();

        if ($source === false) {
            throw new \InvalidArgumentException('No se recibió el fragmento del archivo.');
        }

        $bytes = file_get_contents($source);

        if ($bytes === false || File::put($destination, $bytes) === false) {
            throw new \RuntimeException('No se pudo guardar el fragmento en el servidor.');
        }
    }

    protected function cleanup(string $uploadId): void
    {
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
