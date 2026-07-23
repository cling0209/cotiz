<?php

namespace App\Console\Commands;

use App\Services\OrganismoObservacionRelayService;
use App\Services\OrganismoPerfilAutomaticoService;
use Illuminate\Console\Command;

class AnalizarOrganismoPerfilesCommand extends Command
{
    protected $signature = 'organismo:analizar-perfiles
                            {--sin-sync : No empujar resultados al sitio par}
                            {--solo-sync : Solo sincronizar fichas existentes al par (sin recalcular)}';

    protected $description = 'Recalcula perfiles automáticos de organismos (solo MERCADOPUBLICO_ANALISIS_ADMIN=true) y sincroniza al par';

    public function handle(
        OrganismoPerfilAutomaticoService $perfil,
        OrganismoObservacionRelayService $relay,
    ): int {
        if ($this->option('solo-sync')) {
            $this->info('Sincronizando organismos observaciones al sitio par…');
            $stats = $relay->empujarTodos();
            $this->info("OK: {$stats['ok']} · Fallos: {$stats['fail']}");

            return $stats['fail'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        if (! $perfil->analisisHabilitado()) {
            $this->warn('Análisis deshabilitado (MERCADOPUBLICO_ANALISIS_ADMIN=false). No se recalcula.');

            return self::SUCCESS;
        }

        $this->info('Recalculando perfiles automáticos de organismos…');
        $stats = $perfil->recalcularTodos();
        $this->info(
            "Organismos: {$stats['organismos']} · Con perfil: {$stats['con_perfil']} · Sin historial: {$stats['sin_historial']}"
        );

        if ($this->option('sin-sync')) {
            return self::SUCCESS;
        }

        $this->info('Sincronizando al sitio par…');
        $sync = $relay->empujarTodos();
        $this->info("Sync OK: {$sync['ok']} · Fallos: {$sync['fail']}");

        return $sync['fail'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
