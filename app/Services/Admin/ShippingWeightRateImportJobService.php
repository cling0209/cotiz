<?php

namespace App\Services\Admin;

use App\Services\ProductImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ShippingWeightRateImportJobService
{
    public const ROWS_PER_BATCH = 50;

    public function __construct(
        protected ProductImportService $csvReader,
        protected ShippingWeightRateImportService $importService,
    ) {}

    public function registerPendingUpload(
        string $uploadId,
        string $mergedPath,
        int $userId,
        string $originalName,
    ): void {
        $this->assertValidUploadId($uploadId);
        $jobDir = $this->jobDirectory($uploadId);

        if (File::isDirectory($jobDir)) {
            File::deleteDirectory($jobDir);
        }

        File::ensureDirectoryExists($jobDir);

        File::put($jobDir.'/job.json', json_encode([
            'user_id' => $userId,
            'original_name' => $originalName,
            'merged_path' => $mergedPath,
            'prepared' => false,
            'next_batch' => 0,
            'batch_count' => 0,
            'result' => $this->emptyResult(),
            'created_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{upload_id: string, batch_count: int}
     */
    public function prepareFromMergedCsv(string $uploadId, string $mergedPath, int $userId, string $originalName): array
    {
        $this->assertValidUploadId($uploadId);
        $jobDir = $this->jobDirectory($uploadId);

        if (File::isDirectory($jobDir)) {
            File::deleteDirectory($jobDir);
        }

        File::ensureDirectoryExists($jobDir);

        $batchCount = $this->splitMergedCsvIntoBatches($uploadId, $mergedPath);

        if ($batchCount === 0) {
            File::deleteDirectory($jobDir);
            throw new \InvalidArgumentException('El archivo no contiene filas de tramos.');
        }

        File::put($jobDir.'/job.json', json_encode([
            'user_id' => $userId,
            'original_name' => $originalName,
            'prepared' => true,
            'next_batch' => 0,
            'batch_count' => $batchCount,
            'result' => $this->emptyResult(),
            'created_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return [
            'upload_id' => $uploadId,
            'batch_count' => $batchCount,
        ];
    }

    /**
     * @return array{
     *     finished: bool,
     *     processed_batches: int,
     *     total_batches: int,
     *     result: array{created: int, updated: int, skipped: int, errors: list<string>}
     * }
     */
    public function processNextBatch(string $uploadId, int $userId): array
    {
        $this->assertValidUploadId($uploadId);
        $job = $this->readJob($uploadId);

        if ((int) $job['user_id'] !== $userId) {
            throw new \InvalidArgumentException('No autorizado para procesar esta importación.');
        }

        if (! ($job['prepared'] ?? false)) {
            $mergedPath = (string) ($job['merged_path'] ?? '');

            if ($mergedPath === '' || ! File::exists($mergedPath)) {
                throw new \InvalidArgumentException('El archivo fusionado ya no está disponible. Vuelve a subir el CSV.');
            }

            $batchCount = $this->splitMergedCsvIntoBatches($uploadId, $mergedPath);
            File::delete($mergedPath);

            if ($batchCount === 0) {
                $this->cleanup($uploadId);
                throw new \InvalidArgumentException('El archivo no contiene filas de tramos.');
            }

            $job['prepared'] = true;
            $job['batch_count'] = $batchCount;
            $job['next_batch'] = 0;
            unset($job['merged_path']);
            $this->writeJob($uploadId, $job);

            return [
                'finished' => false,
                'processed_batches' => 0,
                'total_batches' => $batchCount,
                'result' => $job['result'],
            ];
        }

        $nextBatch = (int) $job['next_batch'];
        $batchCount = (int) $job['batch_count'];

        if ($nextBatch >= $batchCount) {
            return [
                'finished' => true,
                'processed_batches' => $batchCount,
                'total_batches' => $batchCount,
                'result' => $job['result'],
            ];
        }

        $batchPath = $this->jobDirectory($uploadId).'/batch-'.str_pad((string) $nextBatch, 6, '0', STR_PAD_LEFT).'.csv';

        if (! File::exists($batchPath)) {
            throw new \InvalidArgumentException('No se encontró el lote '.($nextBatch + 1).' de la importación.');
        }

        $uploaded = new UploadedFile(
            $batchPath,
            $job['original_name'],
            'text/csv',
            null,
            true
        );

        $batchResult = $this->importService->importFromUploadedFile($uploaded);
        $job['result'] = $this->mergeResults($job['result'], $batchResult);
        $job['next_batch'] = $nextBatch + 1;

        File::delete($batchPath);
        $this->writeJob($uploadId, $job);

        $finished = $job['next_batch'] >= $batchCount;

        if ($finished) {
            $this->cleanup($uploadId);
        }

        return [
            'finished' => $finished,
            'processed_batches' => (int) $job['next_batch'],
            'total_batches' => $batchCount,
            'result' => $job['result'],
        ];
    }

    protected function splitMergedCsvIntoBatches(string $uploadId, string $mergedPath): int
    {
        $jobDir = $this->jobDirectory($uploadId);
        File::ensureDirectoryExists($jobDir);

        foreach (File::glob($jobDir.'/batch-*.csv') ?: [] as $oldBatch) {
            File::delete($oldBatch);
        }

        $content = $this->csvReader->readPathAsUtf8($mergedPath);
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('No se pudo preparar el archivo para importar.');
        }

        fwrite($handle, $content);
        rewind($handle);

        $firstLine = fgets($handle);

        if ($firstLine === false) {
            fclose($handle);
            throw new \InvalidArgumentException('El archivo no contiene datos.');
        }

        $delimiter = str_contains($firstLine, ';') ? ';' : ',';
        $headerRow = str_getcsv(rtrim($firstLine, "\r\n"), $delimiter);
        $batchCount = 0;
        $rowsInBatch = 0;
        $batchHandle = null;

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isEmptyCsvRow($data)) {
                continue;
            }

            if ($rowsInBatch === 0) {
                if ($batchHandle !== null) {
                    fclose($batchHandle);
                }

                $batchPath = $jobDir.'/batch-'.str_pad((string) $batchCount, 6, '0', STR_PAD_LEFT).'.csv';
                $batchHandle = fopen($batchPath, 'wb');

                if ($batchHandle === false) {
                    fclose($handle);
                    throw new \RuntimeException('No se pudo crear un lote de importación.');
                }

                fwrite($batchHandle, "\xEF\xBB\xBF");
                fputcsv($batchHandle, $headerRow, $delimiter);
                $batchCount++;
            }

            fputcsv($batchHandle, $data, $delimiter);
            $rowsInBatch++;

            if ($rowsInBatch >= self::ROWS_PER_BATCH) {
                $rowsInBatch = 0;
            }
        }

        if ($batchHandle !== null) {
            fclose($batchHandle);
        }

        fclose($handle);

        return $batchCount;
    }

    /**
     * @param  list<string|null>  $data
     */
    protected function isEmptyCsvRow(array $data): bool
    {
        foreach ($data as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{created: int, updated: int, skipped: int, errors: list<string>}  $current
     * @param  array{created: int, updated: int, skipped: int, errors: list<string>}  $batch
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    protected function mergeResults(array $current, array $batch): array
    {
        return [
            'created' => $current['created'] + $batch['created'],
            'updated' => $current['updated'] + $batch['updated'],
            'skipped' => $current['skipped'] + $batch['skipped'],
            'errors' => array_merge($current['errors'], $batch['errors']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function readJob(string $uploadId): array
    {
        $path = $this->jobDirectory($uploadId).'/job.json';

        if (! File::exists($path)) {
            throw new \InvalidArgumentException('La importación no está lista o ya expiró.');
        }

        $job = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($job)) {
            throw new \InvalidArgumentException('Estado de importación inválido.');
        }

        return $job;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    protected function writeJob(string $uploadId, array $job): void
    {
        File::put(
            $this->jobDirectory($uploadId).'/job.json',
            json_encode($job, JSON_THROW_ON_ERROR)
        );
    }

    protected function cleanup(string $uploadId): void
    {
        $dir = $this->jobDirectory($uploadId);

        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
    }

    protected function jobDirectory(string $uploadId): string
    {
        return storage_path('app/imports/shipping-jobs/'.$uploadId);
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    protected function emptyResult(): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
    }

    protected function assertValidUploadId(string $uploadId): void
    {
        if (! Str::isUuid($uploadId)) {
            throw new \InvalidArgumentException('Identificador de carga inválido.');
        }
    }
}
