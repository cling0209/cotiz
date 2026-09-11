<?php

namespace App\Jobs;

use App\Services\NotaMpResultadosService;
use App\Support\RenderKeepAlive;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reintenta el catch-up de resultados MP cuando al boot/login el pipeline estaba ocupado.
 */
class ReintentarCatchUpResultadosJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(
        public string $usuario = 'sistema',
        public string $origen = NotaMpResultadosService::CATCHUP_ORIGEN_BOOT,
        public int $intento = 1,
    ) {}

    public function uniqueId(): string
    {
        return 'reintentar-catchup-resultados';
    }

    public function handle(NotaMpResultadosService $resultados): void
    {
        RenderKeepAlive::pingIfDue();

        // Libera solo el lock para permitir reprogramar; conserva el contador de intentos.
        $resultados->limpiarReintentoCatchUp(false);

        $usuario = trim($this->usuario) ?: 'sistema';
        $resultado = $resultados->asegurarCorridaProgramadaSiCorresponde($usuario, $this->origen);
        $accion = (string) ($resultado['accion'] ?? '');

        Log::info('Catch-up: reintento diferido ejecutado', [
            'intento' => $this->intento,
            'accion' => $accion,
            'mensaje' => $resultado['mensaje'] ?? null,
            'corrida_id' => $resultado['corrida_id'] ?? null,
        ]);
    }
}
