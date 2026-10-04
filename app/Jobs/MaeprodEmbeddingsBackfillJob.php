<?php

namespace App\Jobs;

use App\Services\MaeprodEmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Un solo job masivo: procesa un lote de embeddings pendientes y se re-encola si quedan más.
 * No crear un job por producto.
 */
class MaeprodEmbeddingsBackfillJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function handle(MaeprodEmbeddingService $embeddings): void
    {
        if (! $embeddings->vectoresHabilitados()) {
            return;
        }

        $lote = max(10, (int) config('cotiz.busqueda_vectores.backfill_por_job', 150));
        $resultado = $embeddings->procesarLotePendientes($lote);

        Log::info('MaeprodEmbeddingsBackfillJob: lote', $resultado);

        if (($resultado['procesados'] ?? 0) >= $lote) {
            $pausa = max(1, (int) config('cotiz.busqueda_vectores.backfill_pausa_entre_jobs_seg', 3));
            self::dispatch()->delay(now()->addSeconds($pausa));
        }
    }
}
