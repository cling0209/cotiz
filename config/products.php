<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Imágenes de productos (URL externa)
    |--------------------------------------------------------------------------
    |
    | URL = {image_base_url}/{familia}/{image_filename}
    | familia e image_filename se definen por producto en admin o carga masiva.
    |
    | Ejemplo:
    | https://www.romulo.cl/allproducts/imagenes/productos/LIB/90503.jpg
    |
    */

    /*
    | Prioridad lectura: PRODUCT_IMAGE_BASE_URL → R2_PUBLIC_URL + prefijo
    */
    'image_base_url' => env('PRODUCT_IMAGE_BASE_URL') ?: (
        filled($r2Public = env('R2_PUBLIC_URL'))
            ? rtrim($r2Public, '/').'/'.trim(env('R2_IMAGE_PREFIX', 'productos'), '/')
            : null
    ),

    'image_fallback_url' => env(
        'PRODUCT_IMAGE_FALLBACK_URL',
        '/images/no-image.svg'
    ),

    'storage_disk' => env('PRODUCT_STORAGE_DISK', 'r2'),

    'r2_prefix' => env('R2_IMAGE_PREFIX', 'productos'),

    'legacy_images_path' => env('LEGACY_PRODUCT_IMAGES_PATH'),

];
