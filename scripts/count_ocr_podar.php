<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$parser = app(App\Services\ListadoMaterialesPdfParserService::class);
$ocr = file_get_contents(__DIR__.'/../tests/Fixtures/pdf_materiales/vps_ocr_real.txt');

$ref = new ReflectionMethod($parser, 'parseTablaProductoCantidad');
$ref->setAccessible(true);
$lineas = $ref->invoke($parser, $ocr);

$ref2 = new ReflectionMethod($parser, 'podarFilasTablaMaterialesSiExceso');
$ref2->setAccessible(true);
$podadas = $ref2->invoke($parser, $ocr, 11, $lineas);

echo 'parseTablaProductoCantidad: '.count($lineas).PHP_EOL;
echo 'after podar (11 pag): '.count($podadas).PHP_EOL;

$hint = "ESPECIFICACIONES TECNICAS\nPRODUCTO CANTIDAD\n";
$podadas2 = $ref2->invoke($parser, $hint, 11, $lineas);
echo 'after podar (hint): '.count($podadas2).PHP_EOL;
