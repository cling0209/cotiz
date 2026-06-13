<?php

namespace App\Console\Commands;

use App\Services\MaeprodImportService;
use App\Support\MaeprodImportError;
use Illuminate\Console\Command;

class ImportMaeprodCommand extends Command
{
    protected $signature = 'maeprod:import
                            {file : Ruta al archivo CSV}
                            {--user= : Usuario para prod_user_upd (default: import)}';

    protected $description = 'Importa productos al maestro maeprod desde CSV (rápido, ideal para migraciones)';

    public function handle(MaeprodImportService $importService): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error('No se encontró el archivo: '.$path);

            return self::FAILURE;
        }

        $usuario = (string) ($this->option('user') ?: 'import');

        $this->info('Importando: '.$path);
        $startedAt = microtime(true);

        try {
            $result = $importService->importFromPath($path, $usuario);
        } catch (\Throwable $exception) {
            $this->error('Importación fallida: '.$exception->getMessage());

            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $startedAt, 2);

        $this->newLine();
        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Creados', $result['created']],
                ['Actualizados', $result['updated']],
                ['Omitidos', $result['skipped']],
                ['Tiempo (s)', $elapsed],
            ],
        );

        if ($result['errors'] !== []) {
            $this->warn('Errores ('.count($result['errors']).'):');

            foreach (array_slice($result['errors'], 0, 20) as $error) {
                $this->line(' - '.MaeprodImportError::summary($error));
            }

            if (count($result['errors']) > 20) {
                $this->line(' ... y '.(count($result['errors']) - 20).' más.');
            }
        }

        if ($result['created'] + $result['updated'] === 0) {
            $this->warn('No se importó ningún producto.');

            return self::FAILURE;
        }

        $this->info('Importación completada.');

        return self::SUCCESS;
    }
}
