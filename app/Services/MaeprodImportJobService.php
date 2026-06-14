<?php

namespace App\Services;

use App\Models\MaeprodImportRun;
use App\Support\MaeprodImportColumnMapping;
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
        ?array $columnMapping = null,
    ): array {
        return $this->prepareFromMergedFile(
            $uploadId,
            $mergedPath,
            $userId,
            $username,
            $originalName,
            $columnMapping,
        );
    }

    /**
     * @return array{upload_id: string, batch_count: int}
     */
    public function prepareFromMergedFile(
        string $uploadId,
        string $mergedPath,
        int $userId,
        string $username,
        string $originalName,
        ?array $columnMapping = null,
    ): array {
        $this->assertValidUploadId($uploadId);
        $preparer = app(MaeprodImportStreamPreparer::class);
        $jobDir = $this->jobDirectory($uploadId);

        if (File::isDirectory($jobDir)) {
            File::deleteDirectory($jobDir);
        }

        File::ensureDirectoryExists($jobDir);

        if ($preparer->isSpreadsheetPath($mergedPath, $originalName)) {
            throw new \InvalidArgumentException('Los archivos Excel deben prepararse por etapas.');
        }

        $result = $preparer->streamCsvFile($mergedPath, $jobDir, self::ROWS_PER_BATCH);

        $this->writePreparedJob(
            $uploadId,
            $userId,
            $username,
            $originalName,
            $result['batch_count'],
            $columnMapping,
            $result['rows_written'],
            $result['rows_written'],
        );

        return [
            'upload_id' => $uploadId,
            'batch_count' => $result['batch_count'],
        ];
    }

    /**
     * @return array{
     *     prepare_finished: bool,
     *     upload_id: string,
     *     batch_count: int,
     *     processed_rows: int,
     *     total_rows: int|null
     * }
     */
    public function continuePrepareTemplate(string $uploadId, int $userId): array
    {
        @set_time_limit(600);
        $this->assertValidUploadId($uploadId);

        if (File::exists($this->jobDirectory($uploadId).'/job.json')) {
            $job = $this->readJob($uploadId);

            return [
                'prepare_finished' => true,
                'upload_id' => $uploadId,
                'batch_count' => (int) $job['batch_count'],
                'processed_rows' => (int) ($job['prepared_rows'] ?? 0),
                'total_rows' => isset($job['total_rows']) ? (int) $job['total_rows'] : null,
            ];
        }

        $statePath = $this->prepareStatePath($uploadId);
        $preparer = app(MaeprodImportStreamPreparer::class);
        $pendingService = app(MaeprodImportPendingService::class);
        $jobDir = $this->jobDirectory($uploadId);
        File::ensureDirectoryExists($jobDir);

        if (! File::exists($statePath)) {
            if (! $pendingService->has($uploadId)) {
                throw new \InvalidArgumentException('La importación no está lista o ya expiró.');
            }

            $pending = $pendingService->find($uploadId);

            if ((int) $pending['user_id'] !== $userId) {
                throw new \InvalidArgumentException('No autorizado para procesar esta importación.');
            }

            if (($pending['mode'] ?? 'template') !== 'template') {
                throw new \InvalidArgumentException('La importación no está lista o ya expiró.');
            }

            $isSpreadsheet = $preparer->isSpreadsheetPath(
                (string) $pending['merged_path'],
                (string) $pending['original_name'],
            );

            $state = [
                'user_id' => $userId,
                'username' => (string) $pending['username'],
                'original_name' => (string) $pending['original_name'],
                'source_path' => (string) $pending['merged_path'],
                'file_kind' => $isSpreadsheet ? 'spreadsheet' : 'csv',
                'next_row' => 2,
                'processed_rows' => 0,
                'batch_count' => 0,
                'highest_row' => null,
                'pending' => true,
            ];

            File::put($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        } else {
            $state = json_decode(File::get($statePath), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($state) || (int) ($state['user_id'] ?? 0) !== $userId) {
                throw new \InvalidArgumentException('No autorizado para procesar esta importación.');
            }
        }

        try {
            if (($state['file_kind'] ?? 'csv') === 'csv') {
                $result = $preparer->streamCsvFile(
                    (string) $state['source_path'],
                    $jobDir,
                    self::ROWS_PER_BATCH,
                );

                $state['processed_rows'] = $result['rows_written'];
                $state['batch_count'] = $result['batch_count'];
                $state['finished'] = true;
                $state['total_rows'] = $result['rows_written'];
            } else {
                $result = $preparer->streamExcelFile(
                    (string) $state['source_path'],
                    $jobDir,
                    self::ROWS_PER_BATCH,
                    (string) $state['original_name'],
                );

                $state['processed_rows'] = $result['rows_written'];
                $state['batch_count'] = $result['batch_count'];
                $state['finished'] = true;
                $state['total_rows'] = $result['rows_written'];
            }

            $prepareFinished = (bool) ($state['finished'] ?? false);

            if ($prepareFinished) {
                $this->writePreparedJob(
                    $uploadId,
                    $userId,
                    (string) $state['username'],
                    (string) $state['original_name'],
                    (int) $state['batch_count'],
                    null,
                    (int) $state['processed_rows'],
                    isset($state['total_rows']) ? (int) $state['total_rows'] : null,
                );

                if (! empty($state['pending'])) {
                    $pendingService->consume($uploadId);
                }

                File::delete($statePath);
            } else {
                File::put($statePath, json_encode($state, JSON_THROW_ON_ERROR));
            }

            return [
                'prepare_finished' => $prepareFinished,
                'upload_id' => $uploadId,
                'batch_count' => (int) $state['batch_count'],
                'processed_rows' => (int) $state['processed_rows'],
                'total_rows' => isset($state['total_rows']) ? (int) $state['total_rows'] : null,
            ];
        } catch (\Throwable $e) {
            if (File::isDirectory($jobDir) && ! File::exists($jobDir.'/job.json')) {
                File::deleteDirectory($jobDir);
            }

            throw $e;
        }
    }

    /**
     * @return array{upload_id: string, batch_count: int}
     */
    public function ensurePrepared(string $uploadId, int $userId): array
    {
        $progress = $this->continuePrepareTemplate($uploadId, $userId);

        if (! $progress['prepare_finished']) {
            throw new \RuntimeException('La preparación del archivo aún no ha finalizado.');
        }

        return [
            'upload_id' => $progress['upload_id'],
            'batch_count' => $progress['batch_count'],
        ];
    }

    /**
     * @return array{upload_id: string, batch_count: int}
     */
    public function prepareFromCsvContent(
        string $uploadId,
        string $content,
        int $userId,
        string $username,
        string $originalName,
        ?array $columnMapping = null,
    ): array {
        $this->assertValidUploadId($uploadId);
        $jobDir = $this->jobDirectory($uploadId);

        if (File::isDirectory($jobDir)) {
            File::deleteDirectory($jobDir);
        }

        File::ensureDirectoryExists($jobDir);

        $importService = app(MaeprodImportService::class);
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

        $jobData = [
            'user_id' => $userId,
            'username' => $username,
            'original_name' => $originalName,
            'next_batch' => 0,
            'batch_count' => $batchCount,
            'result' => $this->emptyResult(),
            'created_at' => now()->toIso8601String(),
        ];

        if ($columnMapping !== null) {
            $jobData['column_mapping'] = $columnMapping;
        }

        File::put($jobDir.'/job.json', json_encode($jobData, JSON_THROW_ON_ERROR));

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

        $columnMapping = isset($job['column_mapping']) && is_array($job['column_mapping'])
            ? $job['column_mapping']
            : null;

        $batchResult = app(MaeprodImportService::class)->importFromUploadedFile(
            $uploaded,
            $job['username'] ?? null,
            $columnMapping,
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
     * Procesa un lote por petición HTTP (evita timeouts en producción).
     *
     * @return array{
     *     finished: bool,
     *     processed_batches: int,
     *     total_batches: int,
     *     result: array{created: int, updated: int, skipped: int, errors: list<string>},
     *     run_id?: int
     * }
     */
    public function processNextBatchWithRun(string $uploadId, int $userId): array
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

        $job = $this->readJob($uploadId);

        if ((int) $job['user_id'] !== $userId) {
            throw new \InvalidArgumentException('No autorizado para procesar esta importación.');
        }

        $runService = app(MaeprodImportRunService::class);
        $runId = isset($job['run_id']) ? (int) $job['run_id'] : null;

        if ($runId === null && (int) $job['next_batch'] === 0) {
            $run = $runService->beginRun(
                (string) ($job['username'] ?? 'import'),
                (string) ($job['original_name'] ?? 'import.csv'),
            );
            $runId = $run->id;
            $job['run_id'] = $runId;
            $this->writeJob($uploadId, $job);
        }

        try {
            $lock->touch($uploadId);
            $progress = $this->processNextBatch($uploadId, $userId);

            if ($progress['finished'] && $runId !== null) {
                $run = $runService->completeRun(
                    MaeprodImportRun::query()->findOrFail($runId),
                    $progress['result'],
                );

                return array_merge($progress, [
                    'run_id' => $run->id,
                ]);
            }

            return $progress;
        } finally {
            if (isset($progress) && $progress['finished'] === true) {
                $lock->release($uploadId);
            }
        }
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

    protected function prepareStatePath(string $uploadId): string
    {
        return $this->jobDirectory($uploadId).'/prepare-state.json';
    }

    /**
     * @param  array<string, string|null>|null  $columnMapping
     */
    protected function writePreparedJob(
        string $uploadId,
        int $userId,
        string $username,
        string $originalName,
        int $batchCount,
        ?array $columnMapping = null,
        int $preparedRows = 0,
        ?int $totalRows = null,
    ): void {
        $jobData = [
            'user_id' => $userId,
            'username' => $username,
            'original_name' => $originalName,
            'next_batch' => 0,
            'batch_count' => $batchCount,
            'prepared_rows' => $preparedRows,
            'result' => $this->emptyResult(),
            'created_at' => now()->toIso8601String(),
        ];

        if ($totalRows !== null) {
            $jobData['total_rows'] = $totalRows;
        }

        if ($columnMapping !== null) {
            $jobData['column_mapping'] = $columnMapping;
        }

        $this->writeJob($uploadId, $jobData);
    }

    /**
     * @return array{
     *     prepare_finished: bool,
     *     upload_id: string,
     *     batch_count: int,
     *     processed_rows: int,
     *     total_rows: int|null
     * }
     */
    public function continuePrepareCustom(
        string $uploadId,
        int $userId,
        array $columnMapping,
    ): array {
        @set_time_limit(600);
        $stagingService = app(MaeprodImportStagingService::class);
        $staging = $stagingService->findStagingRecord($uploadId);

        if ((int) $staging->user_id !== $userId) {
            throw new \InvalidArgumentException('No autorizado para preparar esta importación.');
        }

        MaeprodImportColumnMapping::validate($columnMapping);

        $this->assertValidUploadId($uploadId);

        if (File::exists($this->jobDirectory($uploadId).'/job.json')) {
            $job = $this->readJob($uploadId);

            return [
                'prepare_finished' => true,
                'upload_id' => $uploadId,
                'batch_count' => (int) $job['batch_count'],
                'processed_rows' => (int) ($job['prepared_rows'] ?? 0),
                'total_rows' => isset($job['total_rows']) ? (int) $job['total_rows'] : null,
            ];
        }

        $sourcePath = $staging->source_path;

        if ($sourcePath === null || $sourcePath === '') {
            $prepared = $this->prepareFromCsvContent(
                $uploadId,
                $staging->csv_content ?? '',
                $userId,
                $staging->username,
                $staging->original_name,
                $columnMapping,
            );
            $stagingService->cleanup($uploadId);

            return [
                'prepare_finished' => true,
                'upload_id' => $prepared['upload_id'],
                'batch_count' => $prepared['batch_count'],
                'processed_rows' => 0,
                'total_rows' => (int) $staging->total_rows,
            ];
        }

        $statePath = $this->prepareStatePath($uploadId);
        $preparer = app(MaeprodImportStreamPreparer::class);
        $jobDir = $this->jobDirectory($uploadId);
        File::ensureDirectoryExists($jobDir);

        if (! File::exists($statePath)) {
            $isSpreadsheet = $preparer->isSpreadsheetPath($sourcePath, $staging->original_name);
            $state = [
                'user_id' => $userId,
                'username' => $staging->username,
                'original_name' => $staging->original_name,
                'source_path' => $sourcePath,
                'file_kind' => $isSpreadsheet ? 'spreadsheet' : 'csv',
                'next_row' => 2,
                'processed_rows' => 0,
                'batch_count' => 0,
                'highest_row' => null,
                'column_mapping' => $columnMapping,
                'total_rows' => (int) $staging->total_rows,
            ];

            File::put($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        } else {
            $state = json_decode(File::get($statePath), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($state) || (int) ($state['user_id'] ?? 0) !== $userId) {
                throw new \InvalidArgumentException('No autorizado para preparar esta importación.');
            }
        }

        try {
            if (($state['file_kind'] ?? 'csv') === 'csv') {
                $result = $preparer->streamCsvFile(
                    (string) $state['source_path'],
                    $jobDir,
                    self::ROWS_PER_BATCH,
                );

                $state['processed_rows'] = $result['rows_written'];
                $state['batch_count'] = $result['batch_count'];
                $state['finished'] = true;
            } else {
                $result = $preparer->streamExcelFile(
                    (string) $state['source_path'],
                    $jobDir,
                    self::ROWS_PER_BATCH,
                    (string) $state['original_name'],
                );

                $state['processed_rows'] = $result['rows_written'];
                $state['batch_count'] = $result['batch_count'];
                $state['finished'] = true;
            }

            $prepareFinished = (bool) ($state['finished'] ?? false);

            if ($prepareFinished) {
                $this->writePreparedJob(
                    $uploadId,
                    $userId,
                    (string) $state['username'],
                    (string) $state['original_name'],
                    (int) $state['batch_count'],
                    $columnMapping,
                    (int) $state['processed_rows'],
                    isset($state['total_rows']) ? (int) $state['total_rows'] : null,
                );

                $stagingService->cleanup($uploadId);

                if (is_file((string) $state['source_path'])) {
                    File::delete((string) $state['source_path']);
                }

                File::delete($statePath);
            } else {
                File::put($statePath, json_encode($state, JSON_THROW_ON_ERROR));
            }

            return [
                'prepare_finished' => $prepareFinished,
                'upload_id' => $uploadId,
                'batch_count' => (int) $state['batch_count'],
                'processed_rows' => (int) $state['processed_rows'],
                'total_rows' => isset($state['total_rows']) ? (int) $state['total_rows'] : null,
            ];
        } catch (\Throwable $e) {
            if (File::isDirectory($jobDir) && ! File::exists($jobDir.'/job.json')) {
                File::deleteDirectory($jobDir);
            }

            throw $e;
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
