<?php

return [
    'empresa_nombre' => env('COTIZ_EMPRESA_NOMBRE', 'Comercializadora Reicol SPA'),
    'empresa_rut' => env('COTIZ_EMPRESA_RUT', '76.356.855-5'),
    'empresa_correo' => env('COTIZ_EMPRESA_CORREO', 'jlocier@reicol.cl'),
    'empresa_fono' => env('COTIZ_EMPRESA_FONO', '+56 9 9044 5886'),
    'empresa_direccion' => env('COTIZ_EMPRESA_DIRECCION', 'Santa Carolina Parcela 14-A Lampa, Santiago'),
    'empresa_cuenta' => env('COTIZ_EMPRESA_CUENTA', 'Banco Estado Cta.Cte. 3854418'),
    'codigo_bodega' => env('COTIZ_CODIGO_BODEGA', '01'),
    'concepto_bodega' => env('COTIZ_CONCEPTO_BODEGA', '26'),
    'codigo_proveedor' => env('COTIZ_CODIGO_PROVEEDOR', '76185139'),
    'factor_precio_venta' => (float) env('COTIZ_FACTOR_PRECIO_VENTA', 1.22),
    'prod_valor_fecha_meses' => (int) env('COTIZ_PROD_VALOR_FECHA_MESES', 1),
    'listado_por_pagina' => (int) env('COTIZ_LISTADO_POR_PAGINA', 20),

    'api_nota_envio' => [
        'url' => env('COTIZ_API_NOTA_ENVIO_URL', ''),
        'user' => env('COTIZ_API_NOTA_ENVIO_USER', ''),
        'password' => env('COTIZ_API_NOTA_ENVIO_PASSWORD', ''),
    ],

    'buscar_productos_limite' => (int) env('COTIZ_BUSCAR_PRODUCTOS_LIMITE', 15),
    'buscar_productos_min_chars' => (int) env('COTIZ_BUSCAR_PRODUCTOS_MIN_CHARS', 2),
    'buscar_productos_debounce_ms' => (int) env('COTIZ_BUSCAR_PRODUCTOS_DEBOUNCE_MS', 250),
    'buscar_productos_max_limite' => (int) env('COTIZ_BUSCAR_PRODUCTOS_MAX_LIMITE', 50),

    'agile' => [
        'user' => env('COTIZ_AGILE_USER', 'AGI2025'),
        'password' => env('COTIZ_AGILE_PASSWORD', 'Rsdfh_jghagi'),
        'sistema' => env('COTIZ_AGILE_SISTEMA', 'API'),
        'maeprod_factor_precio_venta' => (float) env('COTIZ_AGILE_MAEPROD_FACTOR', 1.22),
        'api_nota_consulta' => env('COTIZ_AGILE_API_NOTA_CONS', ''),
        'api_nota_user' => env('COTIZ_AGILE_API_NOTA_USER', ''),
        'api_nota_password' => env('COTIZ_AGILE_API_NOTA_PASSWORD', ''),
    ],
];
