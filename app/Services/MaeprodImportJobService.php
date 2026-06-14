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

    public const ROWS_PER_STREAM_CHUNK = 2000;

    public const IMPORT_MODE_BATCH = 'batch';

    public const IMPORT_MODE_STREAM = 'stream';

    /**
     * @return array{upload_id: string, stream_mode: bool, total_rows: int, ready_to_process: bool}
     */
    public function beginStreamJobFromMergedFile(
        string $uploadId,
        string $mergedPath,
        int $userId,
        string $username,
        string $originalName,
        ?array $columnMapping = null,
    ): array {
        return $this->beginStreamJobFromPath(
            $uploadId,
            $mergedPath,
            $userId,
            $username,
            $originalName,
            $columnMapping,
        );
    }

    /**
     * @return array{upload_id: string, stream_mode: bool, total_rows: int, ready_to_process: bool}
     */
    public function beginStreamJobFromPath(
        string $uploadId,
        string $sourcePath,
        int $userId,
        string $username,
        string $originalName,
        ?array $columnMapping = null,
        ?int $totalRows = null,
    ): array {
        $this->assertValidUploadId($uploadId);

        $jobDir = $this->jobDirectory($uploadId);
        $destPath = $jobDir.'/source.csv';
        $sourceInJobDir = realpath($sourcePath) !== false
            && realpath($jobDir) !== false
            && str_starts_with(realpath($sourcePath), realpath($jobDir));

        if (! $sourceInJobDir) {
            if (File::isDirectory($jobDir)) {
                File::deleteDirectory($jobDir);
            }

            File::ensureDirectoryExists($jobDir);

            if (! File::copy($sourcePath, $destPath)) {
                throw new \RuntimeException('No se pudo preparar el archivo de importación.');
            }
        } else {
            $destPath = $sourcePath;
            File::ensureDirectoryExists($jobDir);
        }

        return $this->finalizeStreamJobFromCsvPath(
            $uploadId,
            $destPath,
            $userId,
            $username,
            $originalName,
            $columnMapping,
            $totalRows,
        );
    }

    /**
     * @return array{upload_id: string, stream_mode: bool, total_rows: int, ready_to_process: bool}
     */
    public function beginStreamJobFromCsvContent(
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

        $destPath = $jobDir.'/source.csv';

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            File::put($destPath, $content);
        } else {
            File::put($destPath, "\xEF\xBB\xBF".$content);
        }

        return $this->finalizeStreamJobFromCsvPath(
            $uploadId,
            $destPath,
            $userId,
            $username,
            $originalName,
            $columnMapping,
        );
    }

    /**
     * @return array{upload_id: string, stream_mode: bool, total_rows: int, ready_to_process: bool}
     */
    protected function finalizeStreamJobFromCsvPath(
        string $uploadId,
        string $destPath,
        int $userId,
        string $username,
        string $originalName,
        ?array $columnMapping = null,
        ?int $totalRows = null,
    ): array {
        $preparer = app(MaeprodImportStreamPreparer::class);
        $parsed = $preparer->readCsvHeaders($destPath);
        $delimiter = $parsed['delimiter'];
        $dataHeaders = $parsed['data_headers'];

        if ($dataHeaders === []) {
            File::deleteDirectory($this->jobDirectory($uploadId));
            throw new \InvalidArgumentException('El archivo no contiene encabezados válidos.');
        }

        if ($totalRows === null) {
            $totalRows = $preparer->countCsvDataRows($destPath, $delimiter);
        }

        if ($totalRows < 1) {
            File::deleteDirectory($this->jobDirectory($uploadId));
            throw new \InvalidArgumentException('El archivo no contiene filas de productos.');
        }

        $this->writeStreamJob(
            $uploadId,
            $userId,
            $username,
            $originalName,
            $destPath,
            $dataHeaders,
            $delimiter,
            $totalRows,
            $columnMapping,
        );

        return [
            'upload_id' => $uploadId,
            'stream_mode' => true,
            'total_rows' => $totalRows,
            'ready_to_process' => true,
        ];
    }

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
            return $this->buildPrepareFinishedResponse($uploadId);
        }

        $pendingService = app(MaeprodImportPendingService::class);
        $preparer = app(MaeprodImportStreamPreparer::class);
        $jobDir = $this->jobDirectory($uploadId);
        File::ensureDirectoryExists($jobDir);
        $statePath = $this->prepareStatePath($uploadId);

        if (! $pendingService->has($uploadId) && ! File::exists($statePath)) {
            $sourceSpreadsheet = $this->findSourceSpreadsheetInJobDir($uploadId);

            if ($sourceSpreadsheet !== null) {
                $state = [
                    'user_id' => $userId,
                    'username' => (string) ($sourceSpreadsheet['username'] ?? 'import'),
                    'original_name' => (string) ($sourceSpreadsheet['original_name'] ?? 'import.xlsx'),
                    'source_path' => $sourceSpreadsheet['path'],
                    'csv_path' => $jobDir.'/source.csv',
                    'next_row' => 2,
                    'processed_rows' => 0,
                    'csv_started' => false,
                    'pending' => false,
                ];

                File::put($statePath, json_encode($state, JSON_THROW_ON_ERROR));
            } else {
                throw new \InvalidArgumentException('La importación no está lista o ya expiró. Vuelva a subir el archivo.');
            }
        }

        if (File::exists($statePath)) {
            $state = json_decode(File::get($statePath), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($state) || (int) ($state['user_id'] ?? 0) !== $userId) {
                throw new \InvalidArgumentException('No autorizado para procesar esta importación.');
            }
        } elseif ($pendingService->has($uploadId)) {
            $pending = $pendingService->find($uploadId);

            if ((int) $pending['user_id'] !== $userId) {
                throw new \InvalidArgumentException('No autorizado para procesar esta importación.');
            }

            if (($pending['mode'] ?? 'template') !== 'template') {
                throw new \InvalidArgumentException('La importación no está lista o ya expiró.');
            }

            $sourcePath = (string) $pending['merged_path'];

            if (! $preparer->isSpreadsheetPath($sourcePath, (string) $pending['original_name'])) {
                try {
                    $this->beginStreamJobFromPath(
                        $uploadId,
                        $sourcePath,
                        $userId,
                        (string) $pending['username'],
                        (string) $pending['original_name'],
                    );

                    $pendingService->consume($uploadId);

                    if (is_file($sourcePath)) {
                        File::delete($sourcePath);
                    }

                    return $this->buildPrepareFinishedResponse($uploadId);
                } catch (\Throwable $e) {
                    if (File::isDirectory($jobDir) && ! File::exists($jobDir.'/job.json')) {
                        File::deleteDirectory($jobDir);
                    }

                    throw $e;
                }
            }

            $state = [
                'user_id' => $userId,
                'username' => (string) $pending['username'],
                'original_name' => (string) $pending['original_name'],
                'source_path' => $sourcePath,
                'csv_path' => $jobDir.'/source.csv',
                'next_row' => 2,
                'processed_rows' => 0,
                'csv_started' => false,
                'pending' => true,
            ];

            File::put($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        } else {
            throw new \InvalidArgumentException('La importación no está lista o ya expiró. Vuelva a subir el archivo.');
        }

        return $this->runSpreadsheetPrepareChunk($uploadId, $state, $statePath, $pendingService);
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
        $prepared = $this->beginStreamJobFromCsvContent(
            $uploadId,
            $content,
            $userId,
            $username,
            $originalName,
            $columnMapping,
        );

        return [
            'upload_id' => $prepared['upload_id'],
            'batch_count' => $this->virtualBatchCount($prepared['total_rows']),
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

        if (($job['import_mode'] ?? self::IMPORT_MODE_BATCH) === self::IMPORT_MODE_STREAM) {
            return $this->processNextStreamChunk($uploadId, $job);
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
        $isStream = ($job['import_mode'] ?? self::IMPORT_MODE_BATCH) === self::IMPORT_MODE_STREAM;
        $atStart = $isStream
            ? (int) ($job['processed_rows'] ?? 0) === 0
            : (int) $job['next_batch'] === 0;

        if ($runId === null && $atStart) {
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
            if (File::exists($this->prepareStatePath($uploadId))) {
                throw new \InvalidArgumentException('El archivo aún se está convirtiendo. Espere a que termine la preparación.');
            }

            throw new \InvalidArgumentException('La importación no está lista o ya expiró. Vuelva a subir el archivo.');
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
            return $this->buildPrepareFinishedResponse($uploadId);
        }

        $sourcePath = $staging->source_path;

        if ($sourcePath === null || $sourcePath === '') {
            $prepared = $this->beginStreamJobFromCsvContent(
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
                'batch_count' => $this->virtualBatchCount($prepared['total_rows']),
                'processed_rows' => 0,
                'total_rows' => $prepared['total_rows'],
                'stream_mode' => true,
            ];
        }

        $preparer = app(MaeprodImportStreamPreparer::class);
        $jobDir = $this->jobDirectory($uploadId);
        File::ensureDirectoryExists($jobDir);
        $statePath = $this->prepareStatePath($uploadId);

        if (! $preparer->isSpreadsheetPath($sourcePath, $staging->original_name)) {
            try {
                $this->beginStreamJobFromPath(
                    $uploadId,
                    $sourcePath,
                    $userId,
                    $staging->username,
                    $staging->original_name,
                    $columnMapping,
                    (int) $staging->total_rows,
                );

                $stagingService->cleanup($uploadId);

                if (is_file($sourcePath)) {
                    File::delete($sourcePath);
                }

                return $this->buildPrepareFinishedResponse($uploadId);
            } catch (\Throwable $e) {
                if (File::isDirectory($jobDir) && ! File::exists($jobDir.'/job.json')) {
                    File::deleteDirectory($jobDir);
                }

                throw $e;
            }
        }

        if (! File::exists($statePath)) {
            $state = [
                'user_id' => $userId,
                'username' => $staging->username,
                'original_name' => $staging->original_name,
                'source_path' => $sourcePath,
                'csv_path' => $jobDir.'/source.csv',
                'next_row' => 2,
                'processed_rows' => 0,
                'csv_started' => false,
                'column_mapping' => $columnMapping,
            ];

            File::put($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        } else {
            $state = json_decode(File::get($statePath), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($state) || (int) ($state['user_id'] ?? 0) !== $userId) {
                throw new \InvalidArgumentException('No autorizado para preparar esta importación.');
            }
        }

        return $this->runSpreadsheetPrepareChunk($uploadId, $state, $statePath, $stagingService);
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

    /**
     * @return array{
     *     finished: bool,
     *     processed_batches: int,
     *     total_batches: int,
     *     processed_rows: int,
     *     total_rows: int,
     *     import_mode: string,
     *     result: array{created: int, updated: int, skipped: int, errors: list<string>}
     * }
     */
    protected function processNextStreamChunk(string $uploadId, array $job): array
    {
        $totalRows = (int) ($job['total_rows'] ?? 0);
        $processedRows = (int) ($job['processed_rows'] ?? 0);
        $totalBatches = $this->virtualBatchCount($totalRows);

        if ($processedRows >= $totalRows && $totalRows > 0) {
            return [
                'finished' => true,
                'processed_batches' => $totalBatches,
                'total_batches' => $totalBatches,
                'processed_rows' => $processedRows,
                'total_rows' => $totalRows,
                'import_mode' => self::IMPORT_MODE_STREAM,
                'result' => $job['result'],
            ];
        }

        $sourcePath = (string) ($job['source_path'] ?? '');

        if ($sourcePath === '' || ! File::exists($sourcePath)) {
            throw new \InvalidArgumentException('No se encontró el archivo CSV de la importación.');
        }

        $columnMapping = isset($job['column_mapping']) && is_array($job['column_mapping'])
            ? $job['column_mapping']
            : null;

        $streamResult = app(MaeprodImportService::class)->importFromCsvStreamPath(
            $sourcePath,
            (int) ($job['next_physical_row'] ?? 2),
            self::ROWS_PER_STREAM_CHUNK,
            $job['username'] ?? null,
            $columnMapping,
            $job['data_headers'] ?? [],
            (string) ($job['delimiter'] ?? ';'),
        );

        $job['result'] = $this->mergeResults($job['result'], $streamResult['chunk_result']);
        $job['processed_rows'] = $processedRows + $streamResult['rows_read'];
        $job['next_physical_row'] = $streamResult['next_physical_row'];

        $finished = $streamResult['exhausted'];

        if ($finished) {
            File::delete($sourcePath);
            $this->writeJob($uploadId, $job);
            $this->cleanup($uploadId);
        } else {
            $this->writeJob($uploadId, $job);
        }

        $processedBatches = max(1, (int) ceil($job['processed_rows'] / self::ROWS_PER_STREAM_CHUNK));

        return [
            'finished' => $finished,
            'processed_batches' => min($processedBatches, $totalBatches),
            'total_batches' => $totalBatches,
            'processed_rows' => (int) $job['processed_rows'],
            'total_rows' => $totalRows,
            'import_mode' => self::IMPORT_MODE_STREAM,
            'result' => $job['result'],
        ];
    }

    /**
     * @param  list<string>  $dataHeaders
     * @param  array<string, string|null>|null  $columnMapping
     */
    protected function writeStreamJob(
        string $uploadId,
        int $userId,
        string $username,
        string $originalName,
        string $sourcePath,
        array $dataHeaders,
        string $delimiter,
        int $totalRows,
        ?array $columnMapping = null,
    ): void {
        $jobData = [
            'import_mode' => self::IMPORT_MODE_STREAM,
            'user_id' => $userId,
            'username' => $username,
            'original_name' => $originalName,
            'source_path' => $sourcePath,
            'next_physical_row' => 2,
            'processed_rows' => 0,
            'total_rows' => $totalRows,
            'data_headers' => $dataHeaders,
            'delimiter' => $delimiter,
            'result' => $this->emptyResult(),
            'created_at' => now()->toIso8601String(),
        ];

        if ($columnMapping !== null) {
            $jobData['column_mapping'] = $columnMapping;
        }

        $this->writeJob($uploadId, $jobData);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{
     *     prepare_finished: bool,
     *     upload_id: string,
     *     batch_count: int,
     *     processed_rows: int,
     *     total_rows: int|null,
     *     stream_mode: bool
     * }
     */
    protected function runSpreadsheetPrepareChunk(
        string $uploadId,
        array $state,
        string $statePath,
        MaeprodImportPendingService|MaeprodImportStagingService $cleanupService,
    ): array {
        $jobDir = $this->jobDirectory($uploadId);
        $csvPath = (string) ($state['csv_path'] ?? $jobDir.'/source.csv');
        $preparer = app(MaeprodImportStreamPreparer::class);

        try {
            $chunk = $preparer->exportExcelChunkToCsv($state, $csvPath);

            $state['next_row'] = $chunk['next_row'];
            $state['processed_rows'] = $chunk['processed_rows'];
            $state['csv_started'] = true;
            $state['data_headers'] = $chunk['data_headers'];
            $state['raw_headers'] = $chunk['raw_headers'];
            $state['column_count'] = $chunk['column_count'];
            $state['highest_row'] = $chunk['highest_row'];
            $state['delimiter'] = $chunk['delimiter'];

            if (! $chunk['finished']) {
                File::put($statePath, json_encode($state, JSON_THROW_ON_ERROR));

                return [
                    'prepare_finished' => false,
                    'upload_id' => $uploadId,
                    'batch_count' => $this->virtualBatchCount($chunk['total_rows']),
                    'processed_rows' => $chunk['processed_rows'],
                    'total_rows' => $chunk['total_rows'],
                    'stream_mode' => true,
                ];
            }

            $columnMapping = isset($state['column_mapping']) && is_array($state['column_mapping'])
                ? $state['column_mapping']
                : null;

            $this->finalizeStreamJobFromCsvPath(
                $uploadId,
                $csvPath,
                (int) $state['user_id'],
                (string) $state['username'],
                (string) $state['original_name'],
                $columnMapping,
                $chunk['processed_rows'],
            );

            $sourcePath = (string) ($state['source_path'] ?? '');

            if ($cleanupService instanceof MaeprodImportPendingService) {
                if (! empty($state['pending'])) {
                    $cleanupService->consume($uploadId);
                }
            } else {
                $cleanupService->cleanup($uploadId);
            }

            if ($sourcePath !== '' && is_file($sourcePath)) {
                File::delete($sourcePath);
            }

            File::delete($statePath);

            return $this->buildPrepareFinishedResponse($uploadId);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * @return array{
     *     prepare_finished: bool,
     *     upload_id: string,
     *     batch_count: int,
     *     processed_rows: int,
     *     total_rows: int|null,
     *     stream_mode: bool
     * }
     */
    protected function buildPrepareFinishedResponse(string $uploadId): array
    {
        $job = $this->readJob($uploadId);
        $totalRows = isset($job['total_rows']) ? (int) $job['total_rows'] : null;

        return [
            'prepare_finished' => true,
            'upload_id' => $uploadId,
            'batch_count' => $this->virtualBatchCount($totalRows),
            'processed_rows' => (int) ($job['processed_rows'] ?? 0),
            'total_rows' => $totalRows,
            'stream_mode' => ($job['import_mode'] ?? self::IMPORT_MODE_BATCH) === self::IMPORT_MODE_STREAM,
        ];
    }

    protected function virtualBatchCount(?int $totalRows): int
    {
        if ($totalRows === null || $totalRows < 1) {
            return 1;
        }

        return max(1, (int) ceil($totalRows / self::ROWS_PER_STREAM_CHUNK));
    }

    /**
     * @return array{path: string, original_name: string, username: string}|null
     */
    protected function findSourceSpreadsheetInJobDir(string $uploadId): ?array
    {
        $jobDir = $this->jobDirectory($uploadId);
        $metaPath = $jobDir.'/upload-meta.json';
        $originalName = null;
        $username = null;

        if (File::exists($metaPath)) {
            $meta = json_decode(File::get($metaPath), true);

            if (is_array($meta)) {
                $originalName = (string) ($meta['original_name'] ?? '');
                $username = (string) ($meta['username'] ?? '');
            }
        }

        foreach (['xlsx', 'xls'] as $extension) {
            $path = $jobDir.'/source.'.$extension;

            if (is_file($path)) {
                return [
                    'path' => $path,
                    'original_name' => $originalName !== '' ? $originalName : 'import.'.$extension,
                    'username' => $username !== '' ? $username : 'import',
                ];
            }
        }

        return null;
    }

    protected function assertValidUploadId(string $uploadId): void
    {
        if (! Str::isUuid($uploadId)) {
            throw new \InvalidArgumentException('Identificador de carga inválido.');
        }
    }
}
