<?php

require __DIR__.'/../vendor/autoload.php';

$pdfPath = $argv[1] ?? 'c:/Archivos Varios/OTROS/John/Req/pdf cotiz/BASES_LICT._PUBLICA_MATERIAL_DIDACTICO_Y_ART._DE_ESCRITORIO.pdf';
$colCant = $argv[2] ?? 'UNIDADES* POR AÑO';
$colProd = $argv[3] ?? 'DESCRIPCION REQUERIMIENTO';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ListadoMaterialesPdfParserService;
use Illuminate\Http\UploadedFile;

$file = new UploadedFile($pdfPath, basename($pdfPath), 'application/pdf', null, true);
$parser = app(ListadoMaterialesPdfParserService::class);
$ref = new ReflectionClass($parser);

$extraer = $ref->getMethod('extraerGrillaNativaPdf');
$extraer->setAccessible(true);
$grilla = $extraer->invoke($parser, $pdfPath);

$aplicar = $ref->getMethod('aplicarMapeoColumnasPorNombre');
$aplicar->setAccessible(true);

$total = 0;
foreach ($grilla as $pag) {
    $n = (int) ($pag['pagina'] ?? 0);
    $paginaSola = [['pagina' => $n, 'filas' => $pag['filas'] ?? []]];
    $lineas = $aplicar->invoke($parser, $paginaSola, $colCant, $colProd);
    $c = count($lineas);
    $total += $c;
    if ($c > 0) {
        echo sprintf("Pág %2d: %3d líneas", $n, $c).PHP_EOL;
        if ($c <= 5) {
            foreach ($lineas as $i => $l) {
                echo sprintf("  %d. [%4d] %s\n", $i + 1, $l['cantidad'], mb_substr($l['descripcion'], 0, 70));
            }
        }
    }
}

echo PHP_EOL."Total por páginas aisladas: {$total}".PHP_EOL;

$todas = $aplicar->invoke($parser, $grilla, $colCant, $colProd);
echo 'Total documento completo: '.count($todas).PHP_EOL;
