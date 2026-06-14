<?php

namespace App\Jobs;

use App\Services\MaeprodImportJobService;
use App\Services\MaeprodImportLockService;
use App\Services\MaeprodImportProgressService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessMaeprodImportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    /**
     * @param  array<string, string|null>|null  $columnMapping
     */
    public function __construct(
        public string $uploadId,
        public int $userId,
        public string $mode = 'template',
        public ?array $columnMapping = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->uploadId;
    }

    public function handle(MaeprodImportJobService $importJob): void
    {
        $importJob->runBackgroundImport(
            $this->uploadId,
            $this->userId,
            $this->mode,
            $this->columnMapping,
        );
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessMaeprodImportJob failed', [
            'upload_id' => $this->uploadId,
            'message' => $exception?->getMessage(),
        ]);

        app(MaeprodImportProgressService::class)->fail(
            $this->uploadId,
            $exception?->getMessage() ?: 'La importación en segundo plano falló.',
        );
        app(MaeprodImportLockService::class)->release($this->uploadId);
    }
}
