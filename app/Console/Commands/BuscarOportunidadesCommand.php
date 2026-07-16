<?php

namespace App\Console\Commands;

use App\Services\OportunidadBusquedaService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class BuscarOportunidadesCommand extends Command
{
    protected $signature = 'oportunidad:buscar
                            {--usuario=sistema : Usuario registrado en la corrida}
                            {--catch-up : Reanuda o crea la corrida omitida por sleep de Render}';

    protected $description = 'Encola la búsqueda de oportunidades por palabras clave en Mercado Público';

    public function handle(OportunidadBusquedaService $busqueda): int
    {
        $usuario = trim((string) $this->option('usuario')) ?: 'sistema';

        try {
            if ($this->option('catch-up')) {
                $resultado = $busqueda->catchUp($usuario);
                $this->info($resultado['mensaje']);

                return self::SUCCESS;
            }

            if (! $busqueda->habilitada()) {
                $this->info('Búsqueda de oportunidades omitida: este sitio no es ANALISIS_ADMIN.');

                return self::SUCCESS;
            }

            $corrida = $busqueda->iniciar($usuario);
            $this->info(sprintf(
                'Búsqueda encolada (corrida #%d, %d pasos).',
                $corrida->id,
                (int) $corrida->total_pasos,
            ));

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
