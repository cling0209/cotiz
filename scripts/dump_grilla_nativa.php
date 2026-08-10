<?php

require __DIR__.'/../vendor/autoload.php';

$pdfPath = $argv[1] ?? 'c:/Archivos Varios/OTROS/John/Req/pdf cotiz/ESPECIFICACIONES TECNICAS - PROGRAMAS DIDECO.pdf';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$parser = app(\App\Services\ListadoMaterialesPdfParserService::class);
$ref = new ReflectionClass($parser);
$m = $ref->getMethod('extraerGrillaNativaPdf');
$m->setAccessible(true);
$grilla = $m->invoke($parser, $pdfPath);

$totalFilas = 0;
foreach ($grilla as $pag) {
    $filas = $pag['filas'] ?? [];
    $totalFilas += count($filas);
    echo "\n=== PAG {$pag['pagina']} — ".count($filas)." filas ===\n";
    foreach ($filas as $i => $fila) {
        echo sprintf("  %3d [%d cols] %s\n", $i + 1, count($fila), implode(' | ', $fila));
    }
}
echo "\nTotal filas grilla: {$totalFilas}\n";
