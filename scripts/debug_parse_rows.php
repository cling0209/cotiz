<?php

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$p = new App\Services\ListadoMaterialesPdfParserService;
$t = file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/vps_ocr_real.txt');
$t = preg_replace('/ESPECIFICACIONES SOLICITUD DE PEDIDO/u', 'ESPECIFICACIONES TECNICAS', $t, 1) ?? $t;
$lines = $p->parseTextoTablaMaterialesFinalizado($t, 11);

$from = (int) ($argv[1] ?? 1);
$to = (int) ($argv[2] ?? min(20, count($lines)));

foreach (array_slice($lines, $from - 1, $to - $from + 1, true) as $i => $l) {
    echo ($i + 1).': ['.$l['cantidad'].'] '.$l['descripcion'].PHP_EOL;
}

echo 'Total: '.count($lines).PHP_EOL;
