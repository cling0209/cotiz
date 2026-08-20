<?php

namespace App\Console\Commands;

use App\Services\OportunidadAdjuntoCorridaService;
use App\Services\OportunidadBusquedaService;
use App\Services\OportunidadVinculoService;
use Illuminate\Console\Command;
use Throwable;

class RetomarOportunidadCorridasCommand extends Command
{
    protected $signature = 'oportunidad:retomar-corridas
                            {--boot : Tras deploy: libera jobs reservados huérfanos y reencola al instante}';

    protected $description = 'Reanuda búsqueda, vinculación o adjuntos si quedaron colgados (p. ej. tras deploy)';

    public function handle(
        OportunidadBusquedaService $busqueda,
        OportunidadVinculoService $vinculos,
        OportunidadAdjuntoCorridaService $adjuntos,
    ): int {
        $forzar = (bool) $this->option('boot');

        try {
            $busquedaResult = $busqueda->catchUp('sistema', true);
            $this->line('Búsqueda: '.($busquedaResult['mensaje'] ?? '—'));

            $vinculoResult = $vinculos->retomarCorridaActiva($forzar);
            $this->line('Vinculación: '.($vinculoResult['mensaje'] ?? '—'));

            $adjuntosResult = $adjuntos->retomarCorridaActiva($forzar);
            $this->line('Adjuntos: '.($adjuntosResult['mensaje'] ?? '—'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
