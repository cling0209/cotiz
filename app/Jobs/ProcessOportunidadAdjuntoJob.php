<?php

namespace App\Jobs;

use App\Models\NotaMpCorrida;
use App\Models\OportunidadBusquedaCorrida;
use App\Models\OportunidadVinculoCorrida;
use App\Services\OportunidadAdjuntoCorridaService;
use App\Services\OportunidadBusquedaService;
use App\Services\OportunidadVinculoService;
use App\Support\RenderKeepAlive;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOportunidadAdjuntoJob implements ShouldQueue
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
            (new WithoutOverlapping('oportunidad-adjunto-'.$this->corridaId))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(12);
    }

    public function handle(OportunidadAdjuntoCorridaService $adjuntosCorrida): void
    {
        RenderKeepAlive::pingIfDue();

        $corrida = $adjuntosCorrida->findCorrida($this->corridaId);
        if ($corrida === null || $corrida->estado !== OportunidadAdjuntoCorridaService::ESTADO_RUNNING) {
            return;
        }

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

        if (OportunidadVinculoCorrida::query()
            ->where('estado', OportunidadVinculoService::ESTADO_RUNNING)
            ->exists()) {
            self::dispatch($this->corridaId)->delay(now()->addSeconds(45));

            return;
        }

        $continua = $adjuntosCorrida->procesarPaso($corrida);
        if ($continua) {
            $delayMs = max(0, (int) config('cotiz.mercadopublico.adjuntos_delay_ms', 500));
            $siguiente = self::dispatch($corrida->id);
            if ($delayMs > 0) {
                $siguiente->delay(now()->addMilliseconds($delayMs));
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessOportunidadAdjuntoJob failed', [
            'corrida_id' => $this->corridaId,
            'message' => $exception?->getMessage(),
        ]);

        $corrida = app(OportunidadAdjuntoCorridaService::class)->findCorrida($this->corridaId);
        if ($corrida === null || $corrida->estado !== OportunidadAdjuntoCorridaService::ESTADO_RUNNING) {
            return;
        }

        $corrida->fill([
            'mensaje' => 'Worker interrumpido; adjuntos se retomarán desde el paso '
                .((int) $corrida->pasos_procesados + 1).'.',
        ])->save();

        self::dispatch($corrida->id)->delay(now()->addMinute());
    }
}
