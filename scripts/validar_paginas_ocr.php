<?php

/**
 * OCR página a página (Tesseract) y cuenta filas tabla vs golden/Paddle por hoja.
 * Uso: php scripts/validar_paginas_ocr.php [pdf]
 */

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ListadoMaterialesPdfParserService;
use Symfony\Component\Process\Process;

$pdfPath = $argv[1] ?? 'C:\\Users\\csoto\\Downloads\\ESPECIFICACIONES TECNICAS .pdf';
if (! is_readable($pdfPath)) {
    $alt = dirname(__DIR__).'/storage/app/especificaciones-test.pdf';
    $pdfPath = is_readable($alt) ? $alt : $pdfPath;
}
if (! is_readable($pdfPath)) {
    fwrite(STDERR, "PDF no legible\n");
    exit(1);
}

$golden = json_decode(
    (string) file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/solicitud_83965_paddle_golden.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$paginas = json_decode(
    (string) file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/solicitud_83965_paginas.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

$parser = new ListadoMaterialesPdfParserService;
$totalHojas = 11;
$dpi = 200;
$crop = 58;
$dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cotiz-ocr-pages-'.bin2hex(random_bytes(4));
mkdir($dir, 0700, true);

$filasPorHojaOcr = [];
$lineasPorHoja = [];
$errores = 0;

for ($hoja = 1; $hoja <= $totalHojas; $hoja++) {
    $prefijo = $dir.DIRECTORY_SEPARATOR."p{$hoja}";
    $proc = new Process([
        'pdftoppm', '-png', '-gray', '-r', (string) $dpi,
        '-f', (string) $hoja, '-l', (string) $hoja,
        $pdfPath, $prefijo,
    ]);
    $proc->setTimeout(120);
    $proc->run();
    if (! $proc->isSuccessful()) {
        echo "Hoja {$hoja}: fallo pdftoppm\n";
        $errores++;
        continue;
    }

    $imgs = glob($prefijo.'-*.png') ?: [];
    if ($imgs === []) {
        echo "Hoja {$hoja}: sin imagen\n";
        $errores++;
        continue;
    }

    $img = $imgs[0];
    if ($crop > 0 && function_exists('imagecreatefrompng')) {
        $im = @imagecreatefrompng($img);
        if ($im !== false) {
            $w = imagesx($im);
            $h = imagesy($im);
            $cw = max(1, (int) round($w * ($crop / 100)));
            $cropped = imagecrop($im, ['x' => 0, 'y' => 0, 'width' => $cw, 'height' => $h]);
            imagedestroy($im);
            if ($cropped !== false) {
                $imgCrop = $img.'.crop.png';
                imagepng($cropped, $imgCrop);
                imagedestroy($cropped);
                $img = $imgCrop;
            }
        }
    }

    $outBase = $img.'.ocr';
    $proc = new Process(['tesseract', $img, $outBase, '-l', 'spa', '--oem', '1', '--psm', '4']);
    $proc->setTimeout(180);
    $proc->run();

    $txtFile = $outBase.'.txt';
    $texto = is_readable($txtFile) ? trim((string) file_get_contents($txtFile)) : '';
    $cabecera = "ESPECIFICACIONES TECNICAS\n83965\nPRODUCTO CANTIDAD IMAGEN REFERENCIA\n{$texto}";
    $lineas = $parser->parseTexto($cabecera);
    $esperado = $paginas['filas_por_hoja'][$hoja - 1] ?? 0;

    $filasPorHojaOcr[] = count($lineas);
    $lineasPorHoja[$hoja] = $lineas;

    $ok = count($lineas) === $esperado ? 'OK' : '✗';
    echo sprintf("Hoja %2d: OCR=%d esperado=%d %s\n", $hoja, count($lineas), $esperado, $ok);
    if (count($lineas) !== $esperado) {
        $errores++;
    }
}

// cleanup
foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);

echo "\nOCR por hoja: ".json_encode($filasPorHojaOcr)."\n";
echo "Golden:       ".json_encode($paginas['filas_por_hoja'])."\n";
echo $errores === 0 ? "Conteos cuadran.\n" : "Diferencias: {$errores}\n";

if ($errores === 0) {
    file_put_contents(
        dirname(__DIR__).'/storage/app/validacion_por_hoja.json',
        json_encode(['filas_por_hoja' => $filasPorHojaOcr, 'lineas' => $lineasPorHoja], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    );
}

exit($errores === 0 ? 0 : 1);
