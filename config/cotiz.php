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
    // Factor por región al importar Compra Ágil / Oportunidades (editable después en la nota).
    'factor_precio_venta_rm' => (float) env('COTIZ_FACTOR_PRECIO_VENTA_RM', 1.22),
    'factor_precio_venta_otras' => (float) env('COTIZ_FACTOR_PRECIO_VENTA_OTRAS', 1.30),
    // Días hábiles sugeridos por región (editable en la nota).
    'diashabiles_rm' => (int) env('COTIZ_DIASHABILES_RM', 5),
    'diashabiles_otras' => (int) env('COTIZ_DIASHABILES_OTRAS', 10),
    // Umbral (meses) para marcar prod_valor_fecha en rojo (cotización y recepción Agile). Legacy: AGILERECEPCION_PROD_VALOR_FECHA_MESES.
    'prod_valor_fecha_meses' => (int) env('COTIZ_PROD_VALOR_FECHA_MESES', 3),
    'listado_por_pagina' => (int) env('COTIZ_LISTADO_POR_PAGINA', 20),

    // Recepción / consulta (apinota.php, apiconsulta.php) y destino del relay
    'sistema' => env('COTIZ_SISTEMA', env('APP_NAME', 'Cotiz')),
    'api_usuario' => [
        'url' => env('COTIZ_API_USUARIO_URL', ''),
    ],
    'api_organismo_observacion' => [
        // Si vacío, se deriva de COTIZ_API_USUARIO_URL (.../usuario → .../organismo-observacion).
        'url' => env('COTIZ_API_ORGANISMO_OBSERVACION_URL', ''),
    ],
    'api_palabra_clave' => [
        // Legacy: ya no se sincronizan palabras clave al par.
        // Si vacío, se deriva de COTIZ_API_USUARIO_URL (.../usuario → .../palabra-clave).
        'url' => env('COTIZ_API_PALABRA_CLAVE_URL', ''),
    ],
    'api_oportunidad_encontrada' => [
        // Si vacío, se deriva de COTIZ_API_USUARIO_URL (.../usuario → .../oportunidad-encontrada).
        'url' => env('COTIZ_API_OPORTUNIDAD_ENCONTRADA_URL', ''),
        // Tras búsqueda/vinculación: espera tras wake /up antes de reenviar pendientes (Render free cold start).
        'sync_wake_espera_seg' => max(0, min(120, (int) env('COTIZ_OPORTUNIDAD_SYNC_WAKE_ESPERA_SEG', 25))),
        // Despertar y esperar activo: poll a /up hasta que responda 200 antes de enviar.
        'sync_wake_poll_max_seg' => max(0, min(120, (int) env('COTIZ_OPORTUNIDAD_SYNC_WAKE_POLL_MAX_SEG', 40))),
        'sync_wake_poll_intervalo_seg' => max(1, min(30, (int) env('COTIZ_OPORTUNIDAD_SYNC_WAKE_POLL_INTERVALO_SEG', 3))),
        // Pausa entre lotes al reenviar al par (evita 429 rate limit).
        'sync_pausa_lote_ms' => max(0, min(10000, (int) env('COTIZ_OPORTUNIDAD_SYNC_PAUSA_LOTE_MS', 1000))),
        // Tamaño de lote para la sincronización por lotes con progreso visible en el frontend.
        'sync_batch_size' => max(1, min(50, (int) env('COTIZ_OPORTUNIDAD_SYNC_BATCH_SIZE', 5))),
        // Reintentos por lote ante 429 (rate limit), con backoff.
        'sync_reintentos_429' => max(0, min(10, (int) env('COTIZ_OPORTUNIDAD_SYNC_REINTENTOS_429', 3))),
        'sync_backoff_429_seg' => max(1, min(60, (int) env('COTIZ_OPORTUNIDAD_SYNC_BACKOFF_429_SEG', 3))),
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
        // Consulta duplicados en sitio par (Render free: wake /up + reintentos ~2–3 min)
        'consulta_par_timeout' => (int) env('COTIZ_CONSULTA_PAR_TIMEOUT', 15),
        'consulta_par_max_intentos' => max(1, (int) env('COTIZ_CONSULTA_PAR_MAX_INTENTOS', 30)),
        'consulta_par_espera_segundos' => max(1, (int) env('COTIZ_CONSULTA_PAR_ESPERA_SEGUNDOS', 5)),
        'consulta_par_mensaje_iniciando' => env(
            'COTIZ_CONSULTA_PAR_MENSAJE_INICIANDO',
            'Levantando servicio del otro sitio, espere unos momentos…',
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

    // Render free: evita spin-down (idle ~15 min) mientras hay jobs.
    // Solo activo con RENDER_KEEPALIVE=true (ver .env.render.example).
    // - Servidor: worker hace GET APP_URL/up (refuerzo; poco fiable solo).
    // - Browser: pantallas con proceso largo hacen Image+fetch+iframe a /up (patrón wake Reicol).
    'render_keepalive' => [
        'enabled' => filter_var(env('RENDER_KEEPALIVE', false), FILTER_VALIDATE_BOOL),
        // Debe ser < 15 (idle de Render free). Default 10.
        'minutes' => max(5, min(14, (int) env('RENDER_KEEPALIVE_MINUTES', 10))),
        // Intervalo del keep-alive desde el browser (ms) mientras hay proceso en curso.
        'browser_interval_ms' => max(15000, min(120000, (int) env('RENDER_KEEPALIVE_BROWSER_INTERVAL_MS', 60000))),
    ],

    'agile' => [
        'user' => env('COTIZ_AGILE_USER', 'AGI2025'),
        'password' => env('COTIZ_AGILE_PASSWORD', 'Rsdfh_jghagi'),
        'sistema' => env('COTIZ_AGILE_SISTEMA', 'API'),
        'maeprod_factor_precio_venta' => (float) env('COTIZ_AGILE_MAEPROD_FACTOR', 1.22),
        // Score mínimo para auto-vincular desde agilemaeprod por similitud de descripción.
        // Tras filtro de tokens distintivos; 20000 evita matches solo por PACK/COLORES/SURTIDOS.
        'vinculo_score_minimo' => (float) env('COTIZ_AGILE_VINCULO_SCORE_MINIMO', 20000),
    ],

    'mercadopublico' => [
        'base_url' => env('MERCADOPUBLICO_BASE_URL', 'https://api2.mercadopublico.cl'),
        'ticket' => env('MERCADOPUBLICO_TICKET', ''),
            'regiones' => array_values(array_filter(array_map(
            'intval',
            array_map('trim', explode(',', (string) env(
                'MERCADOPUBLICO_REGIONES',
                // Sin Magallanes (12). Isla de Pascua se excluye por comuna en código.
                '1,2,3,4,5,6,7,8,9,10,11,13,14,15,16',
            ))),
        ))),
        'analisis_admin_habilitado' => filter_var(env('MERCADOPUBLICO_ANALISIS_ADMIN', false), FILTER_VALIDATE_BOOL),
        // Legado: ya no se usa. Ver Oportunidades = todos los ejecutivos + superadmin (User::canVerOportunidades).
        'oportunidades_viewers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MERCADOPUBLICO_OPORTUNIDADES_VIEWERS', '')),
        ))),
        // Primer día histórico para la búsqueda automática de oportunidades.
        // Si quedan días sin corrida completed, se procesan desde esta fecha hasta hoy.
        'fecha_inicio_busqueda' => env('MERCADOPUBLICO_FECHA_INICIO_BUSQUEDA', '2026-07-14'),
        'sync_dias' => max(1, (int) env('MERCADOPUBLICO_SYNC_DIAS', 30)),
        'sync_dias_inicial' => max(1, (int) env('MERCADOPUBLICO_SYNC_DIAS_INICIAL', 180)),
        'sync_max_detalle' => (int) env('MERCADOPUBLICO_SYNC_MAX_DETALLE', 50),
        'detalle_cache_segundos' => max(60, (int) env('MERCADOPUBLICO_DETALLE_CACHE_SEGUNDOS', 3600)),
        // Timeout HTTP por request. LOW_SPEED_LIMIT=0 desactiva el corte por velocidad baja (cURL).
        'api_timeout_segundos' => max(15, min(180, (int) env('MERCADOPUBLICO_API_TIMEOUT_SEG', 45))),
        'api_connect_timeout_segundos' => max(5, min(60, (int) env('MERCADOPUBLICO_API_CONNECT_TIMEOUT_SEG', 15))),
        'api_low_speed_time_segundos' => max(0, min(120, (int) env('MERCADOPUBLICO_API_LOW_SPEED_TIME_SEG', 20))),
        'api_low_speed_limit_bytes' => max(0, (int) env('MERCADOPUBLICO_API_LOW_SPEED_LIMIT_BYTES', 10)),
        'api_reintentos_http' => max(1, (int) env('MERCADOPUBLICO_API_REINTENTOS', 3)),
        'api_espera_reintento_seg' => max(1, (int) env('MERCADOPUBLICO_API_ESPERA_REINTENTO_SEG', 5)),
        // Tope de páginas MP por región en búsqueda de oportunidades (Metropolitana puede ser lenta).
        'oportunidad_max_paginas' => max(1, min(20, (int) env('MERCADOPUBLICO_OPORTUNIDAD_MAX_PAGINAS', 8))),
        // Segundos sin update_at para considerar la corrida colgada (worker caído o HTTP trabado).
        'oportunidad_corrida_stalled_segundos' => max(60, (int) env('MERCADOPUBLICO_OPORTUNIDAD_STALLED_SEG', 90)),
        'alerta_desvio_pct' => (float) env('MERCADOPUBLICO_ALERTA_DESVIO_PCT', 15),
        'resultados_admin_habilitado' => filter_var(env('MERCADOPUBLICO_RESULTADOS_ADMIN', true), FILTER_VALIDATE_BOOL),
        'resultados_delay_ms' => max(0, (int) env('MERCADOPUBLICO_RESULTADOS_DELAY_MS', 500)),
        // Máx. consultas MP en vuelo (Http async). El siguiente se dispara sin esperar respuesta.
        'resultados_concurrencia' => max(1, (int) env('MERCADOPUBLICO_RESULTADOS_CONCURRENCIA', 5)),
        // Tope superior configurable (evita valores accidentales muy altos).
        'resultados_concurrencia_max' => max(1, (int) env('MERCADOPUBLICO_RESULTADOS_CONCURRENCIA_MAX', 200)),
        // Espera entre disparos sucesivos (no entre fin de lote).
        'resultados_stagger_ms' => max(0, (int) env('MERCADOPUBLICO_RESULTADOS_STAGGER_MS', 2000)),
        'resultados_nota_max_segundos' => max(60, (int) env('MERCADOPUBLICO_RESULTADOS_NOTA_MAX_SEG', 180)),
        'resultados_nota_alerta_segundos' => max(60, (int) env('MERCADOPUBLICO_RESULTADOS_NOTA_ALERTA_SEG', 180)),
        // Default 30 min (antes 43200 = 12 h dejaba corridas eternas al ~99%).
        'resultados_corrida_colgada_segundos' => max(300, (int) env('MERCADOPUBLICO_RESULTADOS_COLGADA_SEG', 1800)),
        // Consulta masiva automática («Consultar ahora») vía scheduler.
        'resultados_schedule_habilitado' => filter_var(env('MERCADOPUBLICO_RESULTADOS_SCHEDULE', true), FILTER_VALIDATE_BOOL),
        'resultados_schedule_hours' => env('MERCADOPUBLICO_RESULTADOS_SCHEDULE_HOURS', '10,19'),
        // No reconsultar en corrida masiva si ya se consultó hoy (timezone APP_TIMEZONE).
        'resultados_skip_consultadas_mismo_dia' => filter_var(
            env('MERCADOPUBLICO_RESULTADOS_SKIP_MISMO_DIA', true),
            FILTER_VALIDATE_BOOL,
        ),
        // En masiva: primer horario de proceso ≥ fecha_ultimo_cambio (ej. 17:05 → slot 19).
        // Fallos (último detalle exito=false) entran en la corrida siguiente sin re-esperar el slot.
        'resultados_filtrar_por_ultimo_cambio' => filter_var(
            env('MERCADOPUBLICO_RESULTADOS_FILTRAR_ULTIMO_CAMBIO', true),
            FILTER_VALIDATE_BOOL,
        ),
    ],
];
