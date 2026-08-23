<?php

namespace App\Jobs;

use App\Services\OportunidadAdjuntoCorridaService;
use App\Services\OportunidadBusquedaService;
use App\Support\RenderKeepAlive;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOportunidadAdjuntoPurgeJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $fechaBusqueda,
        public string $usuario = 'sistema',
        public ?int $corridaId = null,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('oportunidad-adjunto-purge'))
                ->releaseAfter(60)
                ->expireAfter(3600),
        ];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHour();
    }

    public function uniqueId(): string
    {
        return 'oportunidad-adjunto-purge';
    }

    public function handle(
        OportunidadAdjuntoCorridaService $adjuntosCorrida,
        OportunidadBusquedaService $busqueda,
    ): void {
        RenderKeepAlive::pingIfDue();

        $corrida = $this->corridaId !== null
            ? $adjuntosCorrida->findCorrida($this->corridaId)
            : $adjuntosCorrida->ultimaCorrida();

        if ($adjuntosCorrida->purgeEstadoTerminal($corrida)) {
            $adjuntosCorrida->eliminarJobsPurge();
            $busqueda->continuarPipelineTrasPurge($this->fechaBusqueda, $this->usuario);

            return;
        }

        $adjuntosCorrida->ejecutarPurgeCerrados($corrida);

        $busqueda->continuarPipelineTrasPurge($this->fechaBusqueda, $this->usuario);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessOportunidadAdjuntoPurgeJob failed', [
            'corrida_id' => $this->corridaId,
            'message' => $exception?->getMessage(),
        ]);

        try {
            $adjuntosCorrida = app(OportunidadAdjuntoCorridaService::class);
            $corrida = $this->corridaId !== null
                ? $adjuntosCorrida->findCorrida($this->corridaId)
                : $adjuntosCorrida->ultimaCorrida();
            $adjuntosCorrida->marcarPurgeError(
                $corrida,
                $exception?->getMessage() ?? 'Worker interrumpido en limpieza de adjuntos.',
            );
            app(OportunidadBusquedaService::class)->continuarPipelineTrasPurge(
                $this->fechaBusqueda,
                $this->usuario,
            );
        } catch (Throwable $e) {
            Log::warning('No se pudo continuar el pipeline tras fallo de purge de adjuntos', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}