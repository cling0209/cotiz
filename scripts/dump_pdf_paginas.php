<?php

require __DIR__.'/../vendor/autoload.php';

$pdfPath = $argv[1] ?? 'c:/Archivos Varios/OTROS/John/Req/pdf cotiz/ESPECIFICACIONES TECNICAS - PROGRAMAS DIDECO.pdf';
$parser = new Smalot\PdfParser\Parser();
$pages = $parser->parseFile($pdfPath)->getPages();

echo 'pages: '.count($pages).PHP_EOL;

foreach ($pages as $i => $pg) {
    $t = trim((string) $pg->getText());
    $lines = preg_split('/\r\n|\n|\r/u', $t) ?: [];
    echo PHP_EOL.'--- PAGE '.($i + 1).' ('.count($lines)." lines) ---".PHP_EOL;
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln !== '') {
            echo $ln.PHP_EOL;
        }
    }
}
