<?php

return [
    'empresa_nombre' => env('COTIZ_EMPRESA_NOMBRE', 'Comercializadora Reicol SPA'),
    'empresa_rut' => env('COTIZ_EMPRESA_RUT', '76.356.855-5'),
    // RUTs del grupo Reicol / Romulo (reportes de productos ganados en Compra Ágil).
    'reicol_rut' => env('COTIZ_REICOL_RUT', '76.356.855-5'),
    'romulo_rut' => env('COTIZ_ROMULO_RUT', '76.185.139-K'),
    'empresa_correo' => env('COTIZ_EMPRESA_CORREO', 'jlocier@reicol.cl'),
    'empresa_fono' => env('COTIZ_EMPRESA_FONO', '+56 9 9044 5886'),
    'empresa_direccion' => env('COTIZ_EMPRESA_DIRECCION', 'Santa Carolina Parcela 14-A Lampa, Santiago'),
    'empresa_cuenta' => env('COTIZ_EMPRESA_CUENTA', 'Banco Estado Cta.Cte. 3854418'),
    'codigo_bodega' => env('COTIZ_CODIGO_BODEGA', '01'),
    'concepto_bodega' => env('COTIZ_CONCEPTO_BODEGA', '26'),
    'codigo_proveedor' => env('COTIZ_CODIGO_PROVEEDOR', '76185139'),
    'factor_precio_venta' => (float) env('COTIZ_FACTOR_PRECIO_VENTA', 1.22),
    // Prefijo fijo del código de cotizaciones internas (no Mercado Público).
    'cotizacion_interna_prefijo' => env('COTIZ_COTIZACION_INTERNA_PREFIJO', 'CM-'),
    // Factor por región al importar Compra Ágil / Oportunidades (editable después en la nota).
    'factor_precio_venta_rm' => (float) env('COTIZ_FACTOR_PRECIO_VENTA_RM', 1.22),
    'factor_precio_venta_otras' => (float) env('COTIZ_FACTOR_PRECIO_VENTA_OTRAS', 1.30),
    // Días hábiles sugeridos por región (editable en la nota).
    'diashabiles_rm' => (int) env('COTIZ_DIASHABILES_RM', 5),
    'diashabiles_otras' => (int) env('COTIZ_DIASHABILES_OTRAS', 10),
    // Umbral (meses) para marcar prod_valor_fecha en rojo (cotización y recepción Agile). Legacy: AGILERECEPCION_PROD_VALOR_FECHA_MESES.
    'prod_valor_fecha_meses' => (int) env('COTIZ_PROD_VALOR_FECHA_MESES', 3),
    // Default histórico; en pantalla se usa App\Support\ListadoPorPagina (20/40/60 + sesión).
    'listado_por_pagina' => (int) env('COTIZ_LISTADO_POR_PAGINA', 20),
    'listado_por_pagina_opciones' => [20, 40, 60],

    // Recepción / consulta (apinota.php, apiconsulta.php) y destino del relay
    'sistema' => env('COTIZ_SISTEMA', env('APP_NAME', 'Cotiz')),
    'api_usuario' => [
        'url' => env('COTIZ_API_USUARIO_URL', ''),
    ],
    'api_organismo_observacion' => [
        // Si vacío, se deriva de COTIZ_API_USUARIO_URL (.../usuario → .../organismo-observacion).
        'url' => env('COTIZ_API_ORGANISMO_OBSERVACION_URL', ''),
    ],
    'api_maeprod_frase' => [
        // Si vacío, se deriva de COTIZ_API_USUARIO_URL (.../usuario → .../maeprod-frase).
        'url' => env('COTIZ_API_MAEPROD_FRASE_URL', ''),
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
        // Timeout corto al pedir un vínculo ya procesado al par (Productos / Ir a cotizar).
        'consultar_vinculo_timeout_seg' => max(3, min(60, (int) env('COTIZ_OPORTUNIDAD_CONSULTAR_VINCULO_TIMEOUT_SEG', 12))),
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

    // OCR de PDF escaneado (pdftoppm + tesseract). Bajar dpi/max_pages si el
    // servidor tiene poca CPU; psm 4 es el que conserva una fila por línea.
    'ocr' => [
        'dpi' => max(100, min(600, (int) env('COTIZ_OCR_DPI', 300))),
        'max_pages' => max(1, min(50, (int) env('COTIZ_OCR_MAX_PAGES', 15))),
        'psm' => max(0, min(13, (int) env('COTIZ_OCR_PSM', 4))),
        // Recorte columna izquierda (producto+cantidad) en tablas solicitud de pedido.
        'crop_left_percent_tabla' => max(40, min(75, (int) env('COTIZ_OCR_CROP_LEFT_TABLA', 58))),
    ],

    // Mistral OCR (tablas HTML + anotación JSON guiada por columnas del usuario).
    'mistral_ocr' => [
        'enabled' => filter_var(env('COTIZ_MISTRAL_OCR_ENABLED', true), FILTER_VALIDATE_BOOL),
        'api_key' => (string) (env('COTIZ_MISTRAL_API_KEY') ?: env('MISTRAL_API_KEY', '')),
        'model' => env('COTIZ_MISTRAL_OCR_MODEL', 'mistral-ocr-latest'),
        'endpoint' => rtrim((string) env('COTIZ_MISTRAL_OCR_ENDPOINT', 'https://api.mistral.ai/v1/ocr'), '/'),
        'timeout' => max(30, min(300, (int) env('COTIZ_MISTRAL_OCR_TIMEOUT', 240))),
        // Extracción estructurada (document_annotation) con prompt + columnas del usuario.
        'annotation_enabled' => filter_var(env('COTIZ_MISTRAL_OCR_ANNOTATION', true), FILTER_VALIDATE_BOOL),
    ],

    // Sidecar PaddleOCR (tablas escaneadas producto/cantidad). En Docker: http://paddleocr:8080
    'paddleocr' => [
        'enabled' => filter_var(env('COTIZ_PADDLEOCR_ENABLED', true), FILTER_VALIDATE_BOOL),
        'url' => rtrim((string) env('COTIZ_PADDLEOCR_URL', 'http://127.0.0.1:8010'), '/'),
        'timeout' => max(30, min(600, (int) env('COTIZ_PADDLEOCR_TIMEOUT', 300))),
        'max_pages' => max(1, min(30, (int) env('COTIZ_PADDLEOCR_MAX_PAGES', 30))),
        // PDFs con ≥ N páginas van directo a extracción por hoja (más rápido que documento completo).
        'per_page_min_pages' => max(2, min(30, (int) env('COTIZ_PADDLEOCR_PER_PAGE_MIN', 6))),
        // Requests Paddle concurrentes por lote (2 evita saturar el sidecar; reintento secuencial cubre faltantes).
        'parallel_pages' => max(1, min(8, (int) env('COTIZ_PADDLEOCR_PARALLEL_PAGES', 2))),
    ],

    // Sidecar LibreOffice (vista previa Office → PDF). En Docker: http://libreoffice:8080
    'libreoffice' => [
        'enabled' => filter_var(env('COTIZ_LIBREOFFICE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'url' => rtrim((string) env('COTIZ_LIBREOFFICE_URL', 'http://libreoffice:8080'), '/'),
        'timeout' => max(30, min(180, (int) env('COTIZ_LIBREOFFICE_TIMEOUT', 120))),
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
        'oc_v1_base_url' => env('MERCADOPUBLICO_OC_V1_BASE_URL', 'https://api.mercadopublico.cl/servicios/v1/publico'),
        'ticket' => env('MERCADOPUBLICO_TICKET', ''),
        // Código proveedor MP (CodigoProveedor) para listar OC v1 por fecha — clave RUT normalizado.
        'codigo_proveedor_por_rut' => [
            '76185139K' => env('MERCADOPUBLICO_CODIGO_PROVEEDOR_ROMULO', '1276139'),
            '763568555' => env('MERCADOPUBLICO_CODIGO_PROVEEDOR_REICOL', '1417881'),
        ],
        // Días máximos (desde fecha cierre/último cambio −1 hasta hoy) al buscar código AG en OC v1.
        'oc_busqueda_max_dias' => max(4, min(31, (int) env('MERCADOPUBLICO_OC_BUSQUEDA_MAX_DIAS', 31))),
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
        // Tope de seguridad. La búsqueda sigue mientras MP traiga página llena (50);
        // el piso 200 ignora un env viejo de 8/20 que cortaba Metropolitana.
        'oportunidad_max_paginas' => max(200, min(500, (int) env('MERCADOPUBLICO_OPORTUNIDAD_MAX_PAGINAS', 200))),
        // Segundos sin update_at para considerar la corrida colgada (worker caído o HTTP trabado).
        // Con 1 job = 1 página, 90s era agresivo y reencolaba mientras aún procesaba.
        'oportunidad_corrida_stalled_segundos' => max(60, (int) env('MERCADOPUBLICO_OPORTUNIDAD_STALLED_SEG', 180)),
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
        'adjuntos_disk' => env('R2_ADJUNTOS_DISK', 'r2_adjuntos'),
        'adjuntos_prefix' => env('R2_ADJUNTOS_PREFIX', ''),
        'compra_agil_adjuntos_base' => env(
            'MP_COMPRA_AGIL_ADJUNTOS_BASE',
            'https://servicios-compra-agil.mercadopublico.cl',
        ),
        // Clave pública del buscador (header user_key). Vacío = se obtiene del JS del portal.
        'compra_agil_user_key' => env('MP_COMPRA_AGIL_USER_KEY', ''),
        'http_without_verifying' => filter_var(env('MP_HTTP_WITHOUT_VERIFYING', false), FILTER_VALIDATE_BOOL),
    ],
];
