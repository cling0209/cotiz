<?php

namespace App\Console\Commands;

use App\Services\ImagenReferenciaWebService;
use App\Services\ProductImageStorageService;
use Illuminate\Console\Command;

class LimpiarImagenesMercadoLibreCommand extends Command
{
    protected $signature = 'mercadolibre:limpiar-imagenes
                            {--meses= : Meses a conservar, contando el actual (por defecto MERCADOLIBRE_IMAGENES_MESES)}';

    protected $description = 'Borra del bucket las fotos de referencias de Mercado Libre de los meses más antiguos';

    public function handle(ImagenReferenciaWebService $imagenes, ProductImageStorageService $storage): int
    {
        if (! $storage->canUpload()) {
            $this->warn('El almacenamiento de imágenes no está configurado; no hay nada que limpiar.');

            return self::SUCCESS;
        }

        $meses = (int) ($this->option('meses') ?: config('cotiz.mercadolibre.imagenes_meses', 6));
        $borrados = $imagenes->limpiar($meses);

        $this->info($borrados === []
            ? 'Sin meses para borrar (se conservan los últimos '.$meses.').'
            : 'Meses borrados: '.implode(', ', $borrados).'.');

        return self::SUCCESS;
    }
}
