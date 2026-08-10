<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$parser = app(App\Services\ListadoMaterialesPdfParserService::class);
$ocr = (string) file_get_contents(__DIR__.'/../tests/Fixtures/pdf_materiales/vps_ocr_real.txt');
$lineas = $parser->parseTexto($ocr);
$paginas = json_decode((string) file_get_contents(__DIR__.'/../tests/Fixtures/pdf_materiales/solicitud_83965_paginas.json'), true);

echo 'vps_ocr_real parseTexto: '.count($lineas).PHP_EOL;
echo 'expected: '.($paginas['total'] ?? '?').PHP_EOL;

$ref = new ReflectionMethod($parser, 'parseLineasTablaPorPaginaOcr');
$ref->setAccessible(true);

// Simulate per-page: split vps_ocr_real by "PRODUCTO CANTIDAD" headers
$chunks = preg_split('/(?=^PRODUCTO CANTIDAD)/mu', $ocr) ?: [];
$chunks = array_values(array_filter(array_map('trim', $chunks)));
$totalPorPagina = 0;
foreach ($chunks as $i => $chunk) {
    $n = count($parser->parseTexto($chunk));
    $totalPorPagina += $n;
    echo 'chunk '.($i + 1).': '.$n.PHP_EOL;
}
echo 'sum chunks: '.$totalPorPagina.PHP_EOL;
