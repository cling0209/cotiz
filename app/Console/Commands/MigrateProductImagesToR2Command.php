<?php

namespace App\Console\Commands;

use App\Services\ProductImageStorageService;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class MigrateProductImagesToR2Command extends Command
{
    protected $signature = 'products:migrate-images-r2
                            {--source= : Ruta origen (default: LEGACY_PRODUCT_IMAGES_PATH)}
                            {--dry-run : Simular sin subir}
                            {--skip-existing : Omitir archivos que ya existen en R2}
                            {--limit=0 : Máximo de archivos a procesar (0 = todos)}';

    protected $description = 'Migra imágenes del legacy (familia/archivo) al bucket R2 (productos/familia/archivo)';

    public function handle(ProductImageStorageService $storage): int
    {
        $source = $this->resolveSourcePath();

        if ($source === null) {
            $this->error('Defina LEGACY_PRODUCT_IMAGES_PATH en .env o use --source=/ruta/imagenes/productos');

            return self::FAILURE;
        }

        if (! is_dir($source)) {
            $this->error('No existe el directorio origen: '.$source);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $skipExisting = (bool) $this->option('skip-existing');
        $limit = max(0, (int) $this->option('limit'));

        if (! $dryRun && ! $storage->canUpload()) {
            $this->error('R2 no configurado para subida. Complete R2_ACCESS_KEY_ID y R2_SECRET_ACCESS_KEY en .env.');

            return self::FAILURE;
        }

        $this->info('Origen: '.$source);
        $this->info('Destino R2: '.config('products.r2_prefix').'/{familia}/{archivo}');

        if ($dryRun) {
            $this->warn('Modo dry-run: no se subirán archivos.');
        }

        $files = $this->collectImageFiles($source, $limit);

        if ($files === []) {
            $this->warn('No se encontraron imágenes para migrar.');

            return self::SUCCESS;
        }

        $this->info('Archivos a procesar: '.count($files));

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        $stats = [
            'uploaded' => 0,
            'skipped' => 0,
            'missing' => 0,
            'errors' => 0,
        ];
        $errorSamples = [];

        foreach ($files as $file) {
            try {
                $result = $this->processFile($storage, $file, $dryRun, $skipExisting);
                $stats[$result]++;
            } catch (\Throwable $exception) {
                $stats['errors']++;
                if (count($errorSamples) < 10) {
                    $errorSamples[] = $file['familia'].'/'.$file['filename'].': '.$exception->getMessage();
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Resultado', 'Cantidad'],
            [
                ['Subidos', $stats['uploaded']],
                ['Omitidos (ya en R2)', $stats['skipped']],
                ['Sin familia/archivo válido', $stats['missing']],
                ['Errores', $stats['errors']],
            ],
        );

        if ($errorSamples !== []) {
            $this->warn('Ejemplos de error:');
            foreach ($errorSamples as $sample) {
                $this->line(' - '.$sample);
            }
        }

        if ($dryRun) {
            $this->info('Dry-run completado.');
        } elseif ($stats['uploaded'] > 0) {
            $this->info('Migración completada.');
            $this->line('URL base lectura: '.config('products.image_base_url'));
        }

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveSourcePath(): ?string
    {
        $source = trim((string) ($this->option('source') ?: config('products.legacy_images_path')));

        return $source !== '' ? $source : null;
    }

    /**
     * @return list<array{familia: string, filename: string, path: string}>
     */
    private function collectImageFiles(string $source, int $limit): array
    {
        $source = rtrim(str_replace('\\', '/', $source), '/');
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $parsed = $this->parseLegacyFile($source, $file->getPathname());

            if ($parsed === null) {
                continue;
            }

            $files[] = $parsed;

            if ($limit > 0 && count($files) >= $limit) {
                break;
            }
        }

        return $files;
    }

    /**
     * @return array{familia: string, filename: string, path: string}|null
     */
    private function parseLegacyFile(string $source, string $absolutePath): ?array
    {
        $normalized = str_replace('\\', '/', $absolutePath);
        $relative = ltrim(substr($normalized, strlen($source)), '/');
        $parts = explode('/', $relative);

        if (count($parts) < 2) {
            return null;
        }

        $filename = array_pop($parts);
        $familia = implode('/', $parts);

        if ($familia === '' || $filename === '' || str_starts_with($filename, '.')) {
            return null;
        }

        if (! $this->isImageFilename($filename)) {
            return null;
        }

        return [
            'familia' => $familia,
            'filename' => $filename,
            'path' => $absolutePath,
        ];
    }

    private function isImageFilename(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }

    /**
     * @param  array{familia: string, filename: string, path: string}  $file
     */
    private function processFile(
        ProductImageStorageService $storage,
        array $file,
        bool $dryRun,
        bool $skipExisting,
    ): string {
        if ($skipExisting && ! $dryRun && $storage->exists($file['familia'], $file['filename'])) {
            return 'skipped';
        }

        if ($dryRun) {
            return 'uploaded';
        }

        $storage->uploadFromLocalFile($file['path'], $file['familia'], $file['filename']);

        return 'uploaded';
    }
}
