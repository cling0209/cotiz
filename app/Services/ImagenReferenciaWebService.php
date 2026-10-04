<?php

namespace App\Services;

use App\Models\NotaDetalle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fotos de referencias web (Mercado Libre, Prisa) copiadas al bucket de productos para que la
 * cotización/PDF no dependa de URLs externas. Se agrupan por mes ({carpeta}/AAAA/MM/{id}.jpg).
 */
class ImagenReferenciaWebService
{
    public const CARPETA_MERCADO_LIBRE = 'MERCADOLIBRE';

    public const CARPETA_PRISA = 'PRISA';

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

    public static function urlPermitidaPrisa(string $url): bool
    {
        if (! str_starts_with($url, 'https://')) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        $configHost = strtolower((string) parse_url((string) config('cotiz.prisa.base_url'), PHP_URL_HOST));
        if ($configHost !== '' && ($host === $configHost || str_ends_with($host, '.'.$configHost))) {
            return true;
        }

        return $host === 'prisa.cl' || str_ends_with($host, '.prisa.cl');
    }

    public static function urlReferenciaPermitida(string $url): bool
    {
        return self::urlPermitida($url) || self::urlPermitidaPrisa($url);
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

        return $this->guardarEnCarpeta($url, self::CARPETA_MERCADO_LIBRE, $id, 'Mercado Libre');
    }

    /**
     * @param  non-empty-string  $sku  Código Prisa (= prod_item cuando existe en maestro)
     */
    public function guardarPrisa(string $url, string $sku): string
    {
        $sku = self::normalizarIdPrisa($sku);
        if (! self::urlPermitidaPrisa($url) || $sku === '') {
            throw new RuntimeException('Imagen de referencia Prisa no permitida.');
        }

        return $this->guardarEnCarpeta($url, self::CARPETA_PRISA, $sku, 'Prisa');
    }

    public static function normalizarIdPrisa(string $sku): string
    {
        $sku = mb_strtoupper(trim($sku), 'UTF-8');
        if ($sku === '' || preg_match('/^[A-Z0-9._-]{1,40}$/', $sku) !== 1) {
            return '';
        }

        return $sku;
    }

    /**
     * Borra las carpetas mensuales anteriores a los últimos $meses y quita la referencia de las
     * líneas que apuntaban a ellas.
     *
     * @return list<string> meses borrados (AAAA/MM)
     */
    public function limpiar(int $meses): array
    {
        $borrados = $this->limpiarCarpeta(self::CARPETA_MERCADO_LIBRE, $meses);
        foreach ($this->limpiarCarpeta(self::CARPETA_PRISA, $meses) as $mes) {
            if (! in_array($mes, $borrados, true)) {
                $borrados[] = $mes;
            }
        }

        return $borrados;
    }

    private function guardarEnCarpeta(string $url, string $carpeta, string $id, string $origen): string
    {
        if (! $this->storage->canUpload()) {
            throw new RuntimeException('El almacenamiento de imágenes no está configurado.');
        }

        $mes = now()->format('Y/m');
        $relativa = $carpeta.'/'.$mes.'/'.$id.'.jpg';
        $disk = Storage::disk($this->storage->disk());
        $clave = $this->clave($relativa);
        if ($disk->exists($clave)) {
            return $relativa;
        }

        $respuesta = Http::timeout(15)->get($url);
        $contenido = $respuesta->body();
        $mime = strtolower(trim(explode(';', (string) $respuesta->header('Content-Type'))[0]));
        if (! $respuesta->successful() || ! str_starts_with($mime, 'image/') || $contenido === '' || strlen($contenido) > self::MAX_BYTES) {
            throw new RuntimeException('No se pudo descargar la imagen de '.$origen.' (HTTP '.$respuesta->status().').');
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
     * @return list<string> meses borrados (AAAA/MM)
     */
    private function limpiarCarpeta(string $carpeta, int $meses): array
    {
        $limite = now()->startOfMonth()->subMonths(max(1, $meses) - 1);
        $disk = Storage::disk($this->storage->disk());
        $borrados = [];
        $raiz = $this->clave($carpeta);

        foreach ($disk->directories($raiz) as $dirAnio) {
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
                    ->where('imagen_ref', 'like', $carpeta.'/'.$anio.'/'.$mes.'/%')
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
