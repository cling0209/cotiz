<?php

namespace App\Console\Commands;

use App\Services\ProductBulkCopyImportService;
use App\Services\ProductImportProgress;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

class ImportProductsCommand extends Command
{
    protected $signature = 'products:import
                            {file : Ruta al archivo CSV}
                            {--dry-run : Validar y mostrar resumen sin escribir en la base de datos}';

    protected $description = 'Importa productos desde CSV usando COPY + staging (rápido, ideal para cargas masivas)';

    private ?ProgressBar $progressBar = null;

    public function handle(ProductBulkCopyImportService $importService): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error('No se encontró el archivo: '.$path);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Modo dry-run: no se escribirá en la base de datos.');
        }

        $this->info('Importando: '.$path);
        $startedAt = microtime(true);

        $progress = new ProductImportProgress($this->createProgressCallback());

        try {
            $result = $importService->importFromPath($path, $dryRun, $progress);
        } catch (\Throwable $exception) {
            $this->finishProgressBar();
            $this->error('Importación fallida: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->finishProgressBar();
        $elapsed = round(microtime(true) - $startedAt, 2);

        $this->newLine();
        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Creados', $result['created']],
                ['Actualizados', $result['updated']],
                ['Reactivados', $result['reactivated']],
                ['Omitidos', $result['skipped']],
                ['Tiempo (s)', $dryRun ? '—' : $elapsed],
            ],
        );

        if ($result['errors'] !== []) {
            $this->warn('Errores ('.count($result['errors']).'):');

            foreach (array_slice($result['errors'], 0, 20) as $error) {
                $this->line(' - '.$error);
            }

            if (count($result['errors']) > 20) {
                $this->line(' ... y '.(count($result['errors']) - 20).' más.');
            }
        }

        if ($dryRun) {
            $this->info('Dry-run completado.');

            return $this->resolveExitCode($result);
        }

        $this->info('Importación completada en '.$elapsed.' s.');

        return $this->resolveExitCode($result);
    }

    private function createProgressCallback(): \Closure
    {
        return function (string $event, array $payload = []): void {
            match ($event) {
                'phase' => $this->reportPhase((string) ($payload['message'] ?? '')),
                'progress_start' => $this->startProgressBar(
                    (int) ($payload['max'] ?? 0),
                    (string) ($payload['label'] ?? 'Progreso'),
                ),
                'progress' => $this->advanceProgressBar((int) ($payload['current'] ?? 0)),
                'progress_finish' => $this->finishProgressBar(),
                default => null,
            };
        };
    }

    private function reportPhase(string $message): void
    {
        if ($message === '') {
            return;
        }

        $this->finishProgressBar();
        $this->line('<fg=cyan>▶</> '.$message);
    }

    private function startProgressBar(int $max, string $label): void
    {
        $this->finishProgressBar();

        if ($max <= 0) {
            return;
        }

        $this->progressBar = $this->output->createProgressBar($max);
        $this->progressBar->setFormat(" %current%/%max% [%bar%] %percent:3s%% — {$label}");
        $this->progressBar->start();
    }

    private function advanceProgressBar(int $current): void
    {
        if ($this->progressBar === null) {
            return;
        }

        $this->progressBar->setProgress($current);
    }

    private function finishProgressBar(): void
    {
        if ($this->progressBar === null) {
            return;
        }

        $this->progressBar->finish();
        $this->newLine();
        $this->progressBar = null;
    }

    /**
     * @param  array{created: int, updated: int, reactivated: int, skipped: int, errors: list<string>}  $result
     */
    private function resolveExitCode(array $result): int
    {
        return $result['skipped'] > 0 && $result['created'] + $result['updated'] + $result['reactivated'] === 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
