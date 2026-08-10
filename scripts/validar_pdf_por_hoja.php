<?php

require __DIR__.'/../vendor/autoload.php';

$pdfPath = $argv[1] ?? 'c:/Archivos Varios/OTROS/John/Req/pdf cotiz/ESPECIFICACIONES TECNICAS - PROGRAMAS DIDECO.pdf';
$colCant = $argv[2] ?? 'CANTIDAD';
$colProd = $argv[3] ?? 'ESPECIFICACIONES TECNICAS';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ListadoMaterialesPdfParserService;
use App\Services\PdfPaddleOcrService;
use Illuminate\Http\UploadedFile;
use Smalot\PdfParser\Parser;

if (! is_readable($pdfPath)) {
    fwrite(STDERR, "No se puede leer: {$pdfPath}\n");
    exit(1);
}

$parserPdf = new Parser;
$pdf = $parserPdf->parseFile($pdfPath);
$totalPaginas = count($pdf->getPages());
echo "PDF: {$pdfPath}\n";
echo "Páginas: {$totalPaginas}\n";
echo "Columnas: cantidad=[{$colCant}] producto=[{$colProd}]\n\n";

$paddle = app(PdfPaddleOcrService::class);
$parser = app(ListadoMaterialesPdfParserService::class);

echo 'Paddle disponible: '.($paddle->estaDisponible() ? 'si' : 'no')."\n\n";

if ($paddle->estaDisponible()) {
    try {
        $grilla = $paddle->extraerGrillaTabla($pdfPath, basename($pdfPath));
        echo 'Hojas con grilla Paddle: '.count($grilla)."\n";
        foreach ($grilla as $pag) {
            $n = (int) ($pag['pagina'] ?? 0);
            $filas = $pag['filas'] ?? [];
            echo "\n=== HOJA {$n} (Paddle) — ".count($filas)." filas ===\n";
            foreach ($filas as $i => $fila) {
                echo sprintf("  %3d | %s\n", $i + 1, implode(' || ', array_map(static fn ($c) => str_replace("\n", ' ', (string) $c), $fila)));
            }
        }
    } catch (Throwable $e) {
        echo 'Paddle error: '.$e->getMessage()."\n";
    }
}

$file = new UploadedFile($pdfPath, basename($pdfPath), 'application/pdf', null, true);

try {
    $resultado = $parser->parseDocumentoConMapeoColumnas($file, $colCant, $colProd);
    $lineas = $resultado['lineas'] ?? [];
    echo "\n=== RESULTADO MAPEO — ".count($lineas)." líneas ===\n";
    foreach ($lineas as $i => $l) {
        echo sprintf("%3d. [%4d] %s\n", $i + 1, (int) $l['cantidad'], mb_substr($l['descripcion'], 0, 120));
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error mapeo: '.$e->getMessage()."\n");
    exit(1);
}
