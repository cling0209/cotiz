<?php

namespace App\Jobs;

use App\Models\NotaMpCorrida;
use App\Models\ProductoMpBusquedaCorrida;
use App\Services\ProductoMpBusquedaService;
use App\Support\RenderKeepAlive;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessProductoMpBusquedaJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

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
            (new WithoutOverlapping('producto-mp-busqueda-'.$this->corridaId))
                ->releaseAfter(15)
                ->expireAfter(180),
        ];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(12);
    }

    public function handle(ProductoMpBusquedaService $busqueda): void
    {
        RenderKeepAlive::pingIfDue();

        $corrida = ProductoMpBusquedaCorrida::query()->find($this->corridaId);
        if ($corrida === null || $corrida->estado !== ProductoMpBusquedaService::ESTADO_RUNNING) {
            return;
        }

        if (NotaMpCorrida::query()
            ->masivas()
            ->where('estado', 'running')
            ->exists()) {
            self::dispatch($this->corridaId)->delay(now()->addSeconds(45));

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
        Log::error('ProcessProductoMpBusquedaJob failed', [
            'corrida_id' => $this->corridaId,
            'message' => $exception?->getMessage(),
        ]);

        $corrida = ProductoMpBusquedaCorrida::query()->find($this->corridaId);
        if ($corrida === null || $corrida->estado !== ProductoMpBusquedaService::ESTADO_RUNNING) {
            return;
        }

        try {
            app(ProductoMpBusquedaService::class)->registrarInterrupcionWorker(
                $corrida,
                $exception?->getMessage(),
            );
        } catch (Throwable) {
            $corrida->fill([
                'mensaje' => 'Worker interrumpido; la búsqueda de productos MP se retomará.',
            ])->save();
        }

        self::dispatch($corrida->id)->delay(now()->addSeconds(15));
    }
}
