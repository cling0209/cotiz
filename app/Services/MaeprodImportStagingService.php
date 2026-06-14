<?php

namespace App\Services;

use App\Models\MaeprodImportStaging;
use App\Support\MaeprodImportColumnMapping;
use App\Support\MaeprodImportFileTypes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MaeprodImportStagingService
{
    public const MAX_AGE_HOURS = 24;

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

        return $this->storeFromMergedPath(
            $uploadId,
            $mergedPath,
            $userId,
            $username,
            $originalName,
        );
    }

    /**
     * @return array{upload_id: string, columns: list<string>, total_rows: int, suggested_mapping: array<string, string>}
     */
    public function storeFromMergedPath(
        string $uploadId,
        string $mergedPath,
        int $userId,
        string $username,
        string $originalName,
    ): array {
        $this->assertValidUploadId($uploadId);

        if (! is_file($mergedPath)) {
            throw new \InvalidArgumentException('No se encontró el archivo subido.');
        }

        $preparer = app(MaeprodImportStreamPreparer::class);
        $stagingPath = $this->stagingFilePath($uploadId, $originalName);
        File::ensureDirectoryExists(dirname($stagingPath));
        File::copy($mergedPath, $stagingPath);

        if ($preparer->isSpreadsheetPath($mergedPath, $originalName)) {
            $metadata = app(MaeprodSpreadsheetReader::class)->readMetadata($stagingPath);
            $columns = array_values(array_filter(
                $metadata['headers'],
                fn (string $header) => $header !== '',
            ));
            $totalRows = max(0, (int) $metadata['highest_row'] - 1);
        } else {
            $parsed = $preparer->readCsvHeaders($stagingPath);
            $columns = $parsed['data_headers'];
            $totalRows = $preparer->countCsvDataRows($stagingPath, $parsed['delimiter']);
        }

        if ($columns === []) {
            File::delete($stagingPath);
            throw new \InvalidArgumentException('El archivo no contiene encabezados válidos.');
        }

        MaeprodImportStaging::query()->where('upload_id', $uploadId)->delete();

        MaeprodImportStaging::query()->create([
            'upload_id' => $uploadId,
            'user_id' => $userId,
            'username' => $username,
            'original_name' => $originalName,
            'source_path' => $stagingPath,
            'columns' => $columns,
            'total_rows' => $totalRows,
            'csv_content' => '',
        ]);

        return [
            'upload_id' => $uploadId,
            'columns' => $columns,
            'total_rows' => $totalRows,
            'suggested_mapping' => MaeprodImportColumnMapping::suggest($columns),
        ];
    }

    /**
     * @return array{upload_id: string, columns: list<string>, total_rows: int, suggested_mapping: array<string, string>}
     */
    public function initializeFromPending(string $uploadId, int $userId): array
    {
        $existing = MaeprodImportStaging::query()->where('upload_id', $uploadId)->first();

        if ($existing !== null) {
            if ((int) $existing->user_id !== $userId) {
                throw new \InvalidArgumentException('No autorizado para preparar esta importación.');
            }

            return [
                'upload_id' => $uploadId,
                'columns' => $existing->columns ?? [],
                'total_rows' => (int) $existing->total_rows,
                'suggested_mapping' => MaeprodImportColumnMapping::suggest($existing->columns ?? []),
            ];
        }

        $pendingService = app(MaeprodImportPendingService::class);
        $pending = $pendingService->find($uploadId);

        if ((int) $pending['user_id'] !== $userId) {
            throw new \InvalidArgumentException('No autorizado para preparar esta importación.');
        }

        if (($pending['mode'] ?? 'custom') !== 'custom') {
            throw new \InvalidArgumentException('La importación no está lista o ya expiró.');
        }

        try {
            return $this->storeFromMergedPath(
                $uploadId,
                (string) $pending['merged_path'],
                $userId,
                (string) $pending['username'],
                (string) $pending['original_name'],
            );
        } finally {
            $pendingService->consume($uploadId);
        }
    }

    /**
     * @return array{upload_id: string, columns: list<string>, total_rows: int, suggested_mapping: array<string, string>}
     */
    public function storeFromCsvContent(
        string $uploadId,
        string $content,
        int $userId,
        string $username,
        string $originalName,
    ): array {
        $this->assertValidUploadId($uploadId);

        $importService = app(MaeprodImportService::class);
        $rows = $importService->parseCsvText($content);

        if ($rows === []) {
            throw new \InvalidArgumentException('El archivo no contiene filas de productos.');
        }

        $columns = array_values(array_filter(
            array_keys($rows[0]),
            fn (string $header) => $header !== '_csv_line',
        ));

        MaeprodImportStaging::query()->where('upload_id', $uploadId)->delete();

        MaeprodImportStaging::query()->create([
            'upload_id' => $uploadId,
            'user_id' => $userId,
            'username' => $username,
            'original_name' => $originalName,
            'source_path' => null,
            'columns' => $columns,
            'total_rows' => count($rows),
            'csv_content' => $content,
        ]);

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
        $progress = app(MaeprodImportJobService::class)->continuePrepareCustom($uploadId, $userId, $mapping);

        if (! $progress['prepare_finished']) {
            throw new \RuntimeException('La preparación del archivo aún no ha finalizado.');
        }

        return [
            'upload_id' => $progress['upload_id'],
            'batch_count' => $progress['batch_count'],
        ];
    }

    public function findStagingRecord(string $uploadId): MaeprodImportStaging
    {
        return $this->findStaging($uploadId);
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
        $staging = $this->findStaging($uploadId);

        if ((int) $staging->user_id !== $userId) {
            throw new \InvalidArgumentException('No autorizado para previsualizar esta importación.');
        }

        MaeprodImportColumnMapping::validate($mapping);

        $importService = app(MaeprodImportService::class);

        if ($staging->source_path) {
            return $importService->previewFromPath(
                $staging->source_path,
                $mapping,
                $limit,
                $staging->original_name,
            );
        }

        return $importService->previewFromContent(
            $staging->csv_content ?? '',
            $mapping,
            $limit,
        );
    }

    public function cleanup(string $uploadId): void
    {
        $staging = MaeprodImportStaging::query()->find($uploadId);

        if ($staging !== null && $staging->source_path && is_file($staging->source_path)) {
            File::delete($staging->source_path);
        }

        MaeprodImportStaging::query()->where('upload_id', $uploadId)->delete();
    }

    protected function findStaging(string $uploadId): MaeprodImportStaging
    {
        $this->assertValidUploadId($uploadId);
        $this->purgeExpired();

        $staging = MaeprodImportStaging::query()->find($uploadId);

        if ($staging === null) {
            throw new \InvalidArgumentException('No se encontró la carga temporal. Vuelva a subir el archivo.');
        }

        return $staging;
    }

    protected function stagingFilePath(string $uploadId, string $originalName): string
    {
        $extension = MaeprodImportFileTypes::extensionFromName($originalName);

        return storage_path('app/imports/staging/'.$uploadId.'.'.$extension);
    }

    protected function purgeExpired(): void
    {
        $expired = MaeprodImportStaging::query()
            ->where('created_at', '<', now()->subHours(self::MAX_AGE_HOURS))
            ->get();

        foreach ($expired as $staging) {
            $this->cleanup($staging->upload_id);
        }
    }

    protected function assertValidUploadId(string $uploadId): void
    {
        if (! Str::isUuid($uploadId)) {
            throw new \InvalidArgumentException('Identificador de carga inválido.');
        }
    }
}
