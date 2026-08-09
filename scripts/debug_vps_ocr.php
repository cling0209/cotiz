<?php

require dirname(__DIR__).'/vendor/autoload.php';

$p = new App\Services\ListadoMaterialesPdfParserService();
$texto = file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/vps_ocr_real.txt');
$lineas = $p->parseTexto($texto);

echo 'Total: '.count($lineas).PHP_EOL.PHP_EOL;

foreach ($lineas as $i => $f) {
    $d = mb_strtoupper($f['descripcion']);
    if (
        str_contains($d, 'LAPIZ DE MADERA')
        || str_contains($d, 'LÁPIZ DE MADERA')
        || str_contains($d, 'SACAPUNTAS')
        || str_contains($d, 'TEMPERA SOLIDA')
        || str_contains($d, 'TÉMPERA SOLIDA')
        || str_contains($d, 'MARCADOR ÓLEO')
        || str_contains($d, 'COLA FR')
    ) {
        echo ($i + 1).': '.$f['cantidad'].' | '.$f['descripcion'].PHP_EOL;
    }
}
