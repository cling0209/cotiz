<?php

namespace App\Console\Commands;

use App\Services\OportunidadPalabraClaveRelayService;
use Illuminate\Console\Command;

class SyncOportunidadPalabrasParCommand extends Command
{
    protected $signature = 'oportunidad:sync-palabras-par
                            {--sin-wake : No llamar /up del sitio par}
                            {--solo-pendientes : No empujar todas las frases locales}';

    protected $description = 'Sincroniza palabras clave con el sitio par (Reicol ↔ Romulo), reintentando pendientes si el par estaba dormido';

    public function handle(OportunidadPalabraClaveRelayService $relay): int
    {
        $this->info('Sincronizando palabras clave con el sitio par…');

        $resultado = $relay->sincronizarConPar(
            despertar: ! $this->option('sin-wake'),
            pushTodas: ! $this->option('solo-pendientes'),
        );

        if ($resultado['ok']) {
            $this->info($resultado['mensaje']);

            return self::SUCCESS;
        }

        $this->warn($resultado['mensaje']);

        return self::FAILURE;
    }
}
