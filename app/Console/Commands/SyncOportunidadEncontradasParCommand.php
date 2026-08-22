<?php

namespace App\Console\Commands;

use App\Models\NotaMpCorrida;
use App\Models\OportunidadAdjuntoCorrida;
use App\Models\OportunidadBusquedaCorrida;
use App\Models\OportunidadVinculoCorrida;
use App\Services\OportunidadAdjuntoCorridaService;
use App\Services\OportunidadBusquedaService;
use App\Services\OportunidadEncontradaRelayService;
use App\Services\OportunidadVinculoService;
use Illuminate\Console\Command;

class SyncOportunidadEncontradasParCommand extends Command
{
    protected $signature = 'oportunidad:sync-encontradas-par
                            {--sin-wake : No llamar /up del sitio par}';

    protected $description = 'Reintenta sincronizar oportunidades encontradas pendientes con el sitio par (Reicol ↔ Romulo)';

    public function handle(OportunidadEncontradaRelayService $relay): int
    {
        if ($this->pipelineEnCurso()) {
            $this->info('Pipeline en curso (búsqueda / vinculación / adjuntos / estados); sync periódico omitido.');

            return self::SUCCESS;
        }

        $this->info('Sincronizando oportunidades encontradas con el sitio par…');

        if ($this->option('sin-wake')) {
            $cotizaciones = $relay->sincronizarPendientesPorTipo('cotizaciones', despertar: false);
            $vinculaciones = $relay->sincronizarPendientesPorTipo('vinculaciones', despertar: false);
            $resultado = [
                'ok' => (bool) ($cotizaciones['ok'] ?? false) && (bool) ($vinculaciones['ok'] ?? false),
                'mensaje' => 'cotizaciones ('.($cotizaciones['mensaje'] ?? '').'); '
                    .'vinculaciones ('.($vinculaciones['mensaje'] ?? '').').',
            ];
        } else {
            $resultado = $relay->sincronizarPipelineTrasVinculacion('schedule');
        }

        if ($resultado['ok']) {
            $this->info($resultado['mensaje']);

            return self::SUCCESS;
        }

        $this->warn($resultado['mensaje']);

        return self::FAILURE;
    }

    private function pipelineEnCurso(): bool
    {
        if (NotaMpCorrida::query()->masivas()->where('estado', 'running')->exists()) {
            return true;
        }

        if (OportunidadBusquedaCorrida::query()
            ->where('estado', OportunidadBusquedaService::ESTADO_RUNNING)
            ->exists()) {
            return true;
        }

        if (OportunidadVinculoCorrida::query()
            ->where('estado', OportunidadVinculoService::ESTADO_RUNNING)
            ->exists()) {
            return true;
        }

        return OportunidadAdjuntoCorrida::query()
            ->where('estado', OportunidadAdjuntoCorridaService::ESTADO_RUNNING)
            ->exists();
    }
}
