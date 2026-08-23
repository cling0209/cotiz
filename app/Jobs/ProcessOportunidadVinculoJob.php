<?php

namespace App\Jobs;

use App\Models\NotaMpCorrida;
use App\Models\OportunidadBusquedaCorrida;
use App\Models\OportunidadVinculoCorrida;
use App\Services\OportunidadBusquedaService;
use App\Services\OportunidadVinculoService;
use App\Support\RenderKeepAlive;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOportunidadVinculoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public function __construct(
        public int $corridaId,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('oportunidad-vinculo-'.$this->corridaId))
                ->releaseAfter(30)
                ->expireAfter(180),
        ];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10);
    }

    public function handle(OportunidadVinculoService $vinculos): void
    {
        RenderKeepAlive::pingIfDue();

        $corrida = OportunidadVinculoCorrida::query()->find($this->corridaId);
        if ($corrida === null || $corrida->estado !== OportunidadVinculoService::ESTADO_RUNNING) {
            return;
        }

        // No competir con la consulta de resultados MP ni con la búsqueda.
        if (NotaMpCorrida::query()
            ->masivas()
            ->where('estado', 'running')
            ->exists()) {
            self::dispatch($this->corridaId)->delay(now()->addSeconds(45));

            return;
        }

        if (OportunidadBusquedaCorrida::query()
            ->where('estado', OportunidadBusquedaService::ESTADO_RUNNING)
            ->exists()) {
            self::dispatch($this->corridaId)->delay(now()->addSeconds(45));

            return;
        }

        $continua = $vinculos->procesarPaso($corrida);
        if ($continua) {
            $delayMs = max(0, (int) config('cotiz.mercadopublico.resultados_delay_ms', 350));
            $siguiente = self::dispatch($corrida->id);
            if ($delayMs > 0) {
                $siguiente->delay(now()->addMilliseconds($delayMs));
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessOportunidadVinculoJob failed', [
            'corrida_id' => $this->corridaId,
            'message' => $exception?->getMessage(),
        ]);

        try {
            app(OportunidadVinculoService::class)->continuarTrasInterrupcionWorker(
                $this->corridaId,
                $exception?->getMessage() ?: 'Worker interrumpido; se continúa con la siguiente cotización.',
            );
        } catch (Throwable $e) {
            Log::warning('No se pudo continuar la vinculación tras fallo del worker', [
                'corrida_id' => $this->corridaId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
