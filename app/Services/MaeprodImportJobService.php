<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MaeprodImportJobService
{
    public const ROWS_PER_BATCH = 1000;

    /**
     * @return array{upload_id: string, batch_count: int}
     */
    public function prepareFromMergedCsv(
        string $uploadId,
        string $mergedPath,
        int $userId,
        string $username,
        string $originalName,
    ): array {
        $this->assertValidUploadId($uploadId);
        $jobDir = $this->jobDirectory($uploadId);

        if (File::isDirectory($jobDir)) {
            File::deleteDirectory($jobDir);
        }

        File::ensureDirectoryExists($jobDir);

        $importService = app(MaeprodImportService::class);
        $content = $importService->readPathAsUtf8($mergedPath);
        $rows = $importService->parseCsvText($content);

        if ($rows === []) {
            File::deleteDirectory($jobDir);
            throw new \InvalidArgumentException('El archivo no contiene filas de productos.');
        }

        $dataHeaders = array_values(array_filter(
            array_keys($rows[0]),
            fn (string $header) => $header !== '_csv_line',
        ));
        $headerRow = array_merge(['_csv_line'], $dataHeaders);
        $delimiter = str_contains($content, ';') ? ';' : ',';
        $batchCount = 0;
        $batchHandle = null;

        foreach ($rows as $index => $row) {
            if ($index % self::ROWS_PER_BATCH === 0) {
                if ($batchHandle !== null) {
                    fclose($batchHandle);
                }

                $batchPath = $jobDir.'/batch-'.str_pad((string) $batchCount, 6, '0', STR_PAD_LEFT).'.csv';
                $batchHandle = fopen($batchPath, 'wb');

                if ($batchHandle === false) {
                    throw new \RuntimeException('No se pudo crear un lote de importación.');
                }

                fwrite($batchHandle, "\xEF\xBB\xBF");
                fputcsv($batchHandle, $headerRow, $delimiter);
                $batchCount++;
            }

            $line = [(string) ($row['_csv_line'] ?? '')];
            foreach ($dataHeaders as $header) {
                $line[] = $row[$header] ?? '';
            }

            fputcsv($batchHandle, $line, $delimiter);
        }

        if ($batchHandle !== null) {
            fclose($batchHandle);
        }

        if ($batchCount === 0) {
            File::deleteDirectory($jobDir);
            throw new \InvalidArgumentException('El archivo no contiene filas de productos.');
        }

        File::put($jobDir.'/job.json', json_encode([
            'user_id' => $userId,
            'username' => $username,
            'original_name' => $originalName,
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

        $batchResult = app(MaeprodImportService::class)->importFromUploadedFile(
            $uploaded,
            $job['username'] ?? null,
        );
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

    /**
     * @return array{
     *     finished: bool,
     *     processed_batches: int,
     *     total_batches: int,
     *     result: array{created: int, updated: int, skipped: int, errors: list<string>}
     * }
     */
    public function processAllBatches(string $uploadId, int $userId): array
    {
        $lock = app(MaeprodImportLockService::class);

        if ($lock->isBlockedFor($uploadId)) {
            $current = $lock->current();
            $started = $current
                ? \Illuminate\Support\Carbon::parse($current['started_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i')
                : '';

            throw new \InvalidArgumentException(
                "Hay una importación en curso iniciada por {$current['username']} el {$started}.",
            );
        }

        try {
            $lock->touch($uploadId);

            $job = $this->readJob($uploadId);
            $runService = app(MaeprodImportRunService::class);
            $run = $runService->beginRun(
                (string) ($job['username'] ?? 'import'),
                (string) ($job['original_name'] ?? 'import.csv'),
            );

            $progress = [
                'finished' => false,
                'processed_batches' => 0,
                'total_batches' => 0,
                'result' => $this->emptyResult(),
            ];

            while (! $progress['finished']) {
                $lock->touch($uploadId);
                $progress = $this->processNextBatch($uploadId, $userId);
            }

            $run = $runService->completeRun($run, $progress['result']);

            return array_merge($progress, [
                'run_id' => $run->id,
            ]);
        } finally {
            $lock->release($uploadId);
        }
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
     * @return array{
     *     user_id: int,
     *     username: string,
     *     original_name: string,
     *     next_batch: int,
     *     batch_count: int,
     *     result: array{created: int, updated: int, skipped: int, errors: list<string>},
     *     created_at: string
     * }
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
        return storage_path('app/imports/jobs/'.$uploadId);
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    protected function emptyResult(): array
    {
        return app(MaeprodImportService::class)->emptyResult();
    }

    protected function assertValidUploadId(string $uploadId): void
    {
        if (! Str::isUuid($uploadId)) {
            throw new \InvalidArgumentException('Identificador de carga inválido.');
        }
    }
}
