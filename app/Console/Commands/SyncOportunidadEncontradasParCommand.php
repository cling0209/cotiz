<?php

namespace App\Console\Commands;

use App\Services\OportunidadEncontradaRelayService;
use Illuminate\Console\Command;

class SyncOportunidadEncontradasParCommand extends Command
{
    protected $signature = 'oportunidad:sync-encontradas-par
                            {--sin-wake : No llamar /up del sitio par}';

    protected $description = 'Reintenta sincronizar oportunidades encontradas pendientes con el sitio par (Reicol ↔ Romulo)';

    public function handle(OportunidadEncontradaRelayService $relay): int
    {
        $this->info('Sincronizando oportunidades encontradas con el sitio par…');

        $resultado = $relay->sincronizarPendientes(
            despertar: ! $this->option('sin-wake'),
        );

        if ($resultado['ok']) {
            $this->info($resultado['mensaje']);

            return self::SUCCESS;
        }

        $this->warn($resultado['mensaje']);

        return self::FAILURE;
    }
}
