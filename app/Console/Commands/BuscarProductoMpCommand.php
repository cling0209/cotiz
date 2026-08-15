<?php

namespace App\Console\Commands;

use App\Services\ProductoMpBusquedaService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class BuscarProductoMpCommand extends Command
{
    protected $signature = 'producto-mp:buscar
                            {--usuario=sistema : Usuario registrado en la corrida}';

    protected $description = 'Encola la búsqueda de productos MP por frases de catálogo (todas las CA publicadas)';

    public function handle(ProductoMpBusquedaService $busqueda): int
    {
        $usuario = trim((string) $this->option('usuario')) ?: 'sistema';

        try {
            if (! $busqueda->habilitada()) {
                $this->info('Búsqueda de productos MP omitida: este sitio no es ANALISIS_ADMIN.');

                return self::SUCCESS;
            }

            $corrida = $busqueda->iniciar($usuario);
            $this->info(sprintf(
                'Búsqueda de productos MP encolada (corrida #%d, %d pasos).',
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
