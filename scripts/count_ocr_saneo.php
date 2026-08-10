<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$parser = app(App\Services\ListadoMaterialesPdfParserService::class);
$ocr = file_get_contents(__DIR__.'/../tests/Fixtures/pdf_materiales/vps_ocr_real.txt');

$ref = new ReflectionMethod($parser, 'parseTablaProductoCantidad');
$ref->setAccessible(true);
$lineas = $ref->invoke($parser, $ocr);

$refS = new ReflectionMethod($parser, 'sanearFilasTablaSolicitud');
$refS->setAccessible(true);
$saneadas = $refS->invoke($parser, $lineas, $ocr, 11, false);

echo 'parseTabla: '.count($lineas).PHP_EOL;
echo 'sanear (ocr path): '.count($saneadas).PHP_EOL;

$golden = json_decode(file_get_contents(__DIR__.'/../tests/Fixtures/pdf_materiales/solicitud_83965_paddle_golden.json'), true);
echo 'golden: '.$golden['total'].PHP_EOL;
