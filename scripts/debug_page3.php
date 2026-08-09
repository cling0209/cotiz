<?php

require dirname(__DIR__).'/vendor/autoload.php';

$p = new App\Services\ListadoMaterialesPdfParserService();
$texto = file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/vps_ocr_real.txt');
$lineas = $p->parseTexto($texto);

echo 'Total: '.count($lineas).PHP_EOL.PHP_EOL;

$needles = [
    'MICRÓFONO', 'GREDA', 'ARCILLA', 'PAPEL BOND', 'POMPONES', 'KRAFT', 'BROCHA',
    'CINTA SAT', 'SOBRE CARTA', 'SOBRE 1/4', 'PLIEGO CARTULINA MET', 'BOLSITAS',
    'LIMPIA PIPAS', 'FUNDA PLASTICA', 'CARTULINA CORRUGADO', 'ESPONJA', 'BLOCK PAÑOLENCI',
];

foreach ($lineas as $i => $f) {
    $d = mb_strtoupper($f['descripcion']);
    foreach ($needles as $n) {
        if (str_contains($d, mb_strtoupper($n))) {
            echo ($i + 1).': '.$f['cantidad'].' | '.$f['descripcion'].PHP_EOL;
            break;
        }
    }
}
