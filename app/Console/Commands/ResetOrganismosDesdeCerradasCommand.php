<?php

namespace App\Console\Commands;

use App\Services\OrganismoObservacionRelayService;
use App\Services\OrganismoObservacionService;
use App\Services\OrganismoPerfilAutomaticoService;
use Illuminate\Console\Command;
use Throwable;

class ResetOrganismosDesdeCerradasCommand extends Command
{
    protected $signature = 'organismo:reset-desde-cerradas
                            {--sin-sync : No sincronizar al sitio par}
                            {--con-perfil : Tras cargar, recalcular perfiles automáticos (solo analisis admin)}';

    protected $description = 'Borra organismo_observaciones y las recrea solo desde cotizaciones MP cerradas';

    public function handle(
        OrganismoObservacionService $organismos,
        OrganismoObservacionRelayService $relay,
        OrganismoPerfilAutomaticoService $perfil,
    ): int {
        $this->warn('Se eliminarán todos los organismos observaciones y se recrearán desde cerradas.');
        $stats = $organismos->resetDesdeCerradas();
        $this->info("Borrados: {$stats['borrados']} · Creados desde cerradas: {$stats['creados']}");

        if ($this->option('con-perfil') && $perfil->analisisHabilitado()) {
            $this->info('Recalculando perfiles automáticos…');
            $p = $perfil->recalcularTodos();
            $this->info("Con perfil: {$p['con_perfil']} · Sin historial: {$p['sin_historial']}");
        }

        if ($this->option('sin-sync')) {
            return self::SUCCESS;
        }

        try {
            $this->info('Limpiando y sincronizando al sitio par…');
            $relay->purgarPar();
            $sync = $relay->empujarTodos();
            $this->info("Sync OK: {$sync['ok']} · Fallos: {$sync['fail']}");

            return $sync['fail'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
