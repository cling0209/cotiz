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
    // Umbral (meses) para marcar prod_valor_fecha en rojo (cotización y recepción Agile). Legacy: AGILERECEPCION_PROD_VALOR_FECHA_MESES.
    'prod_valor_fecha_meses' => (int) env('COTIZ_PROD_VALOR_FECHA_MESES', 3),
    'listado_por_pagina' => (int) env('COTIZ_LISTADO_POR_PAGINA', 20),

    // Recepción / consulta (apinota.php, apiconsulta.php) y destino del relay
    'sistema' => env('COTIZ_SISTEMA', env('APP_NAME', 'Cotiz')),
    'api_usuario' => [
        'url' => env('COTIZ_API_USUARIO_URL', ''),
    ],
    'api_nota' => [
        'url' => env('COTIZ_API_NOTA_URL', ''),
        'user' => env('COTIZ_API_NOTA_USER', ''),
        'password' => env('COTIZ_API_NOTA_PASSWORD', ''),
        // URL remota apiconsulta (satélite → central). Vacío = no consulta externa.
        'consulta_nro_cotizacion' => env(
            'COTIZ_API_CONSULTA_NRO_COTIZACION',
            env('COTIZ_AGILE_API_NOTA_CONS', '')
        ),
        // Consulta duplicados en sitio par (Render free: wake /up + reintentos)
        'consulta_par_timeout' => (int) env('COTIZ_CONSULTA_PAR_TIMEOUT', 15),
        'consulta_par_max_intentos' => max(1, (int) env('COTIZ_CONSULTA_PAR_MAX_INTENTOS', 8)),
        'consulta_par_espera_segundos' => max(1, (int) env('COTIZ_CONSULTA_PAR_ESPERA_SEGUNDOS', 3)),
        'consulta_par_mensaje_iniciando' => env(
            'COTIZ_CONSULTA_PAR_MENSAJE_INICIANDO',
            'Iniciando servicio de consulta, espere unos segundos.',
        ),
    ],

    // Envío desde listado (notaventalis → apinotaenvio.php o relay interno)
    'api_nota_envio' => [
        'url' => env('COTIZ_API_NOTA_ENVIO_URL', ''),
        'user' => env('COTIZ_API_NOTA_ENVIO_USER', ''),
        'password' => env('COTIZ_API_NOTA_ENVIO_PASSWORD', ''),
    ],

    'buscar_productos_limite' => (int) env('COTIZ_BUSCAR_PRODUCTOS_LIMITE', 50),
    'buscar_productos_min_chars' => (int) env('COTIZ_BUSCAR_PRODUCTOS_MIN_CHARS', 2),
    'buscar_productos_debounce_ms' => (int) env('COTIZ_BUSCAR_PRODUCTOS_DEBOUNCE_MS', 250),
    'buscar_productos_max_limite' => (int) env('COTIZ_BUSCAR_PRODUCTOS_MAX_LIMITE', 50),
    'buscar_productos_candidatos_max' => (int) env('COTIZ_BUSCAR_PRODUCTOS_CANDIDATOS_MAX', 250),
    'buscar_productos_puntaje_minimo' => (int) env('COTIZ_BUSCAR_PRODUCTOS_PUNTAJE_MINIMO', 55),
    'buscar_productos_score_php_minimo' => (int) env('COTIZ_BUSCAR_PRODUCTOS_SCORE_PHP_MINIMO', 5000),

    'import' => [
        'background' => filter_var(env('MAEPROD_IMPORT_BACKGROUND', true), FILTER_VALIDATE_BOOL),
    ],

    'agile' => [
        'user' => env('COTIZ_AGILE_USER', 'AGI2025'),
        'password' => env('COTIZ_AGILE_PASSWORD', 'Rsdfh_jghagi'),
        'sistema' => env('COTIZ_AGILE_SISTEMA', 'API'),
        'maeprod_factor_precio_venta' => (float) env('COTIZ_AGILE_MAEPROD_FACTOR', 1.22),
    ],

    'mercadopublico' => [
        'base_url' => env('MERCADOPUBLICO_BASE_URL', 'https://api2.mercadopublico.cl'),
        'ticket' => env('MERCADOPUBLICO_TICKET', ''),
        'regiones' => array_values(array_filter(array_map(
            'intval',
            array_map('trim', explode(',', (string) env(
                'MERCADOPUBLICO_REGIONES',
                '1,2,3,4,5,6,7,8,9,10,11,13,14,15,16',
            ))),
        ))),
        'analisis_admin_habilitado' => filter_var(env('MERCADOPUBLICO_ANALISIS_ADMIN', false), FILTER_VALIDATE_BOOL),
        'sync_dias' => max(1, (int) env('MERCADOPUBLICO_SYNC_DIAS', 30)),
        'sync_dias_inicial' => max(1, (int) env('MERCADOPUBLICO_SYNC_DIAS_INICIAL', 180)),
        'sync_max_detalle' => (int) env('MERCADOPUBLICO_SYNC_MAX_DETALLE', 50),
        'detalle_cache_segundos' => max(60, (int) env('MERCADOPUBLICO_DETALLE_CACHE_SEGUNDOS', 3600)),
        'alerta_desvio_pct' => (float) env('MERCADOPUBLICO_ALERTA_DESVIO_PCT', 15),
        'resultados_admin_habilitado' => filter_var(env('MERCADOPUBLICO_RESULTADOS_ADMIN', true), FILTER_VALIDATE_BOOL),
        'resultados_delay_ms' => max(0, (int) env('MERCADOPUBLICO_RESULTADOS_DELAY_MS', 350)),
        'resultados_corrida_colgada_segundos' => max(300, (int) env('MERCADOPUBLICO_RESULTADOS_COLGADA_SEG', 600)),
    ],
];
