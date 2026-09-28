<?php

namespace App\Services;

use App\Models\NotaDetalle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fotos de las referencias de Mercado Libre aplicadas a una cotización, copiadas al bucket de
 * imágenes de productos para que el PDF no dependa de mlstatic. Se agrupan por mes
 * ({prefijo}/MERCADOLIBRE/AAAA/MM/{id}.jpg) para poder borrar los meses antiguos.
 */
class ImagenReferenciaWebService
{
    private const CARPETA = 'MERCADOLIBRE';

    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        protected ProductImageStorageService $storage,
        protected ProductImageProcessor $processor,
    ) {}

    public static function urlPermitida(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_starts_with($url, 'https://') && ($host === 'mlstatic.com' || str_ends_with($host, '.mlstatic.com'));
    }

    /**
     * Copia la foto al bucket y devuelve la ruta relativa a products.image_base_url
     * (MERCADOLIBRE/AAAA/MM/{id}.jpg). Dentro del mismo mes se reutiliza la ya copiada.
     */
    public function guardar(string $url, string $id): string
    {
        if (! self::urlPermitida($url) || preg_match('/^[A-Z]{3}\d+$/', $id) !== 1) {
            throw new RuntimeException('Imagen de referencia no permitida.');
        }
        if (! $this->storage->canUpload()) {
            throw new RuntimeException('El almacenamiento de imágenes no está configurado.');
        }

        $mes = now()->format('Y/m');
        $relativa = self::CARPETA.'/'.$mes.'/'.$id.'.jpg';
        $disk = Storage::disk($this->storage->disk());
        $clave = $this->clave($relativa);
        if ($disk->exists($clave)) {
            return $relativa;
        }

        $respuesta = Http::timeout(15)->get($url);
        $contenido = $respuesta->body();
        $mime = strtolower(trim(explode(';', (string) $respuesta->header('Content-Type'))[0]));
        if (! $respuesta->successful() || ! str_starts_with($mime, 'image/') || $contenido === '' || strlen($contenido) > self::MAX_BYTES) {
            throw new RuntimeException('No se pudo descargar la imagen de Mercado Libre (HTTP '.$respuesta->status().').');
        }

        $procesada = $this->processor->processBinary($contenido, $mime);
        $disk->put($clave, $procesada['contents'] ?? $contenido, [
            'visibility' => 'public',
            'CacheControl' => 'public, max-age=31536000, immutable',
            'ContentType' => $procesada['mime'] ?? $mime,
        ]);

        return $relativa;
    }

    /**
     * Borra las carpetas mensuales anteriores a los últimos $meses y quita la referencia de las
     * líneas que apuntaban a ellas.
     *
     * @return list<string> meses borrados (AAAA/MM)
     */
    public function limpiar(int $meses): array
    {
        $limite = now()->startOfMonth()->subMonths(max(1, $meses) - 1);
        $disk = Storage::disk($this->storage->disk());
        $borrados = [];

        foreach ($disk->directories($this->clave(self::CARPETA)) as $dirAnio) {
            $anio = basename($dirAnio);
            if (preg_match('/^\d{4}$/', $anio) !== 1) {
                continue;
            }
            foreach ($disk->directories($dirAnio) as $dirMes) {
                $mes = basename($dirMes);
                if (preg_match('/^(0[1-9]|1[0-2])$/', $mes) !== 1) {
                    continue;
                }
                if (Carbon::create((int) $anio, (int) $mes, 1)->greaterThanOrEqualTo($limite)) {
                    continue;
                }
                $disk->deleteDirectory($dirMes);
                NotaDetalle::query()
                    ->where('imagen_ref', 'like', self::CARPETA.'/'.$anio.'/'.$mes.'/%')
                    ->update(['imagen_ref' => null]);
                $borrados[] = $anio.'/'.$mes;
            }
        }

        return $borrados;
    }

    private function clave(string $relativa): string
    {
        $prefijo = trim((string) config('products.r2_prefix', 'productos'), '/');

        return ($prefijo !== '' ? $prefijo.'/' : '').$relativa;
    }
}
