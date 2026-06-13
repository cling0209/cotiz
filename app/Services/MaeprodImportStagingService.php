<?php

namespace App\Services;

use App\Support\MaeprodImportColumnMapping;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MaeprodImportStagingService
{
    /**
     * @return array{upload_id: string, columns: list<string>, total_rows: int, suggested_mapping: array<string, string>}
     */
    public function storeFromMergedCsv(
        string $uploadId,
        string $mergedPath,
        int $userId,
        string $username,
        string $originalName,
    ): array {
        $this->assertValidUploadId($uploadId);

        $importService = app(MaeprodImportService::class);
        $content = $importService->readPathAsUtf8($mergedPath);
        $rows = $importService->parseCsvText($content);

        if ($rows === []) {
            throw new \InvalidArgumentException('El archivo no contiene filas de productos.');
        }

        $columns = array_values(array_filter(
            array_keys($rows[0]),
            fn (string $header) => $header !== '_csv_line',
        ));

        File::ensureDirectoryExists($this->stagingDirectory());
        File::copy($mergedPath, $this->stagingCsvPath($uploadId));

        File::put($this->stagingMetaPath($uploadId), json_encode([
            'user_id' => $userId,
            'username' => $username,
            'original_name' => $originalName,
            'columns' => $columns,
            'total_rows' => count($rows),
            'created_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return [
            'upload_id' => $uploadId,
            'columns' => $columns,
            'total_rows' => count($rows),
            'suggested_mapping' => MaeprodImportColumnMapping::suggest($columns),
        ];
    }

    /**
     * @param  array<string, string|null>  $mapping
     * @return array{upload_id: string, batch_count: int}
     */
    public function prepareJob(string $uploadId, int $userId, array $mapping): array
    {
        $meta = $this->readMeta($uploadId);

        if ((int) $meta['user_id'] !== $userId) {
            throw new \InvalidArgumentException('No autorizado para preparar esta importación.');
        }

        MaeprodImportColumnMapping::validate($mapping);

        $csvPath = $this->stagingCsvPath($uploadId);

        if (! File::exists($csvPath)) {
            throw new \InvalidArgumentException('El archivo temporal expiró. Vuelva a subir el CSV.');
        }

        $prepared = app(MaeprodImportJobService::class)->prepareFromMergedCsv(
            $uploadId,
            $csvPath,
            $userId,
            $meta['username'],
            $meta['original_name'],
            $mapping,
        );

        $this->cleanup($uploadId);

        return $prepared;
    }

    /**
     * @param  array<string, string|null>  $mapping
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     summary: array{crear: int, actualizar: int, error: int},
     *     total_rows: int,
     *     preview_limit: int
     * }
     */
    public function preview(string $uploadId, int $userId, array $mapping, int $limit = 10): array
    {
        $meta = $this->readMeta($uploadId);

        if ((int) $meta['user_id'] !== $userId) {
            throw new \InvalidArgumentException('No autorizado para previsualizar esta importación.');
        }

        MaeprodImportColumnMapping::validate($mapping);

        $csvPath = $this->stagingCsvPath($uploadId);

        if (! File::exists($csvPath)) {
            throw new \InvalidArgumentException('El archivo temporal expiró. Vuelva a subir el CSV.');
        }

        return app(MaeprodImportService::class)->previewFromPath($csvPath, $mapping, $limit);
    }

    /**
     * @return array{user_id: int, username: string, original_name: string, columns: list<string>, total_rows: int, created_at: string}
     */
    public function readMeta(string $uploadId): array
    {
        $this->assertValidUploadId($uploadId);

        $metaPath = $this->stagingMetaPath($uploadId);

        if (! File::exists($metaPath)) {
            throw new \InvalidArgumentException('No se encontró la carga temporal. Vuelva a subir el archivo.');
        }

        $meta = json_decode(File::get($metaPath), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($meta) || ! isset($meta['user_id'], $meta['username'], $meta['original_name'], $meta['columns'])) {
            throw new \InvalidArgumentException('Metadatos de carga temporal inválidos.');
        }

        return $meta;
    }

    public function cleanup(string $uploadId): void
    {
        $csv = $this->stagingCsvPath($uploadId);
        $meta = $this->stagingMetaPath($uploadId);

        if (File::exists($csv)) {
            File::delete($csv);
        }

        if (File::exists($meta)) {
            File::delete($meta);
        }
    }

    protected function stagingDirectory(): string
    {
        return storage_path('app/imports/staging');
    }

    protected function stagingCsvPath(string $uploadId): string
    {
        return $this->stagingDirectory().'/'.$uploadId.'.csv';
    }

    protected function stagingMetaPath(string $uploadId): string
    {
        return $this->stagingDirectory().'/'.$uploadId.'.json';
    }

    protected function assertValidUploadId(string $uploadId): void
    {
        if (! Str::isUuid($uploadId)) {
            throw new \InvalidArgumentException('Identificador de carga inválido.');
        }
    }
}
