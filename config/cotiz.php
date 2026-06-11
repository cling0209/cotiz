<?php

return [
    'empresa_nombre' => env('COTIZ_EMPRESA_NOMBRE', 'Comercializadora Reicol SPA'),
    'empresa_rut' => env('COTIZ_EMPRESA_RUT', '76.356.855-5'),
    'factor_precio_venta' => (float) env('COTIZ_FACTOR_PRECIO_VENTA', 1.22),
    'prod_valor_fecha_meses' => (int) env('COTIZ_PROD_VALOR_FECHA_MESES', 1),
    'listado_por_pagina' => (int) env('COTIZ_LISTADO_POR_PAGINA', 20),
];
