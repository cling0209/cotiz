<?php

namespace App\Jobs;

use App\Models\OportunidadVinculoCorrida;
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

    public int $timeout = 43200;

    public int $tries = 5;

    public int $maxExceptions = 5;

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
                ->expireAfter(300),
        ];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(12);
    }

    public function handle(OportunidadVinculoService $vinculos): void
    {
        RenderKeepAlive::pingIfDue();

        $corrida = OportunidadVinculoCorrida::query()->find($this->corridaId);
        if ($corrida === null || $corrida->estado !== OportunidadVinculoService::ESTADO_RUNNING) {
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

        $corrida = OportunidadVinculoCorrida::query()->find($this->corridaId);
        if ($corrida === null || $corrida->estado !== OportunidadVinculoService::ESTADO_RUNNING) {
            return;
        }

        $corrida->fill([
            'mensaje' => 'Worker interrumpido; la vinculación se retomará desde el paso '
                .((int) $corrida->pasos_procesados + 1).'.',
        ])->save();

        self::dispatch($corrida->id)->delay(now()->addMinute());
    }
}
