<?php

namespace App\Jobs;

use App\Services\CompraAgilReporteExportService;
use App\Support\RenderKeepAlive;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessCompraAgilReporteExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public string $jobId,
    ) {}

    public function uniqueId(): string
    {
        return $this->jobId;
    }

    public function handle(CompraAgilReporteExportService $exports): void
    {
        RenderKeepAlive::pingIfDue();
        $exports->run($this->jobId);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessCompraAgilReporteExportJob failed', [
            'job_id' => $this->jobId,
            'message' => $exception?->getMessage(),
        ]);
    }
}
