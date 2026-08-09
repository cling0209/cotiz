<?php

require dirname(__DIR__).'/vendor/autoload.php';

$p = new App\Services\ListadoMaterialesPdfParserService();
$t = file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/vps_ocr_real.txt');
$t2 = preg_replace('/ESPECIFICACIONES SOLICITUD DE PEDIDO/', 'ESPECIFICACIONES TECNICAS', $t, 1);

echo 'formato original: '.$p->detectarFormato($t).PHP_EOL;
echo 'count original: '.count($p->parseTexto($t)).PHP_EOL;
echo 'formato tecnicas: '.$p->detectarFormato($t2).PHP_EOL;
echo 'count tecnicas: '.count($p->parseTexto($t2)).PHP_EOL;
