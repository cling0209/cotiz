<?php

require __DIR__.'/../vendor/autoload.php';

$pdfPath = $argv[1] ?? 'c:/Archivos Varios/OTROS/John/Req/pdf cotiz/ESPECIFICACIONES TECNICAS - PROGRAMAS DIDECO.pdf';
$parser = new App\Services\ListadoMaterialesPdfParserService();

$texto = trim((string) (new Smalot\PdfParser\Parser())->parseFile($pdfPath)->getText());
if ($texto === '') {
    echo "No text extracted\n";
    exit(1);
}

echo 'Formato: '.$parser->detectarFormato($texto).PHP_EOL;
echo 'BIEN O SERVICIO: '.(str_contains(mb_strtoupper($texto), 'BIEN O SERVICIO') ? 'si' : 'no').PHP_EOL;
echo 'UNIDAD DE MEDIDA: '.(str_contains(mb_strtoupper($texto), 'UNIDAD DE MEDIDA') ? 'si' : 'no').PHP_EOL;
echo substr($texto, 0, 500).PHP_EOL.'---'.PHP_EOL;
$lineas = $parser->parseTexto($texto);
echo 'Lineas parseadas: '.count($lineas).PHP_EOL.PHP_EOL;

foreach ($lineas as $i => $l) {
    echo ($i + 1).'. ['.$l['cantidad'].'] '.substr($l['descripcion'], 0, 100).PHP_EOL;
}
