<?php

namespace App\Jobs;

use App\Models\OportunidadBusquedaCorrida;
use App\Services\OportunidadBusquedaService;
use App\Support\RenderKeepAlive;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOportunidadBusquedaJob implements ShouldQueue
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
            (new WithoutOverlapping('oportunidad-busqueda-'.$this->corridaId))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(12);
    }

    public function handle(OportunidadBusquedaService $busqueda): void
    {
        RenderKeepAlive::pingIfDue();

        $corrida = OportunidadBusquedaCorrida::query()->find($this->corridaId);
        if ($corrida === null || $corrida->estado !== OportunidadBusquedaService::ESTADO_RUNNING) {
            return;
        }

        $continua = $busqueda->procesarPaso($corrida);
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
        Log::error('ProcessOportunidadBusquedaJob failed', [
            'corrida_id' => $this->corridaId,
            'message' => $exception?->getMessage(),
        ]);

        $corrida = OportunidadBusquedaCorrida::query()->find($this->corridaId);
        if ($corrida === null || $corrida->estado !== OportunidadBusquedaService::ESTADO_RUNNING) {
            return;
        }

        $corrida->fill([
            'mensaje' => 'Worker interrumpido; la búsqueda se retomará desde el paso '
                .((int) $corrida->pasos_procesados + 1).'.',
        ])->save();

        // Nuevo job con contador de intentos limpio. El índice persistido evita repetir pasos completos.
        self::dispatch($corrida->id)->delay(now()->addMinute());
    }
}
