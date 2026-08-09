<?php

/**
 * Valida hojas 5–11: OCR por página + presencia de cada producto/cantidad del golden.
 * Uso: php scripts/validar_hojas_5_11.php [pdf] [desde] [hasta]
 */

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Symfony\Component\Process\Process;

$pdfPath = $argv[1] ?? '/pdf/doc.pdf';
$desde = (int) ($argv[2] ?? 5);
$hasta = (int) ($argv[3] ?? 11);

if (! is_readable($pdfPath)) {
    fwrite(STDERR, "PDF no legible: {$pdfPath}\n");
    exit(1);
}

$golden = json_decode(
    (string) file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/solicitud_83965_paddle_golden.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$paginasMeta = json_decode(
    (string) file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/solicitud_83965_paginas.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

function normalizar(string $s): string
{
    $s = mb_strtoupper($s);
    $s = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'], ['A', 'E', 'I', 'O', 'U', 'N'], $s);
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;

    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

function buscarNeedle(string $ocrNorm, string $needle): bool
{
    $n = normalizar($needle);
    if ($n === '') {
        return false;
    }
    if (str_contains($ocrNorm, $n)) {
        return true;
    }
    $words = explode(' ', $n);
    if (count($words) >= 2) {
        return str_contains($ocrNorm, implode(' ', array_slice($words, 0, 3)));
    }

    return false;
}

function ocrPagina(string $pdfPath, int $hoja, string $dir): string
{
    $prefijo = $dir.DIRECTORY_SEPARATOR."p{$hoja}";
    $proc = new Process([
        'pdftoppm', '-png', '-gray', '-r', '200',
        '-f', (string) $hoja, '-l', (string) $hoja,
        $pdfPath, $prefijo,
    ]);
    $proc->setTimeout(120);
    $proc->run();
    if (! $proc->isSuccessful()) {
        throw new RuntimeException("pdftoppm hoja {$hoja}: ".$proc->getErrorOutput());
    }

    $imgs = glob($prefijo.'-*.png') ?: [];
    if ($imgs === []) {
        throw new RuntimeException("Sin imagen hoja {$hoja}");
    }

    $img = $imgs[0];
    if (function_exists('imagecreatefrompng')) {
        $im = @imagecreatefrompng($img);
        if ($im !== false) {
            $w = imagesx($im);
            $h = imagesy($im);
            $cw = max(1, (int) round($w * 0.58));
            $cropped = imagecrop($im, ['x' => 0, 'y' => 0, 'width' => $cw, 'height' => $h]);
            imagedestroy($im);
            if ($cropped !== false) {
                $cropPath = $img.'.crop.png';
                imagepng($cropped, $cropPath);
                imagedestroy($cropped);
                $img = $cropPath;
            }
        }
    }

    $outBase = $img.'.ocr';
    $proc = new Process(['tesseract', $img, $outBase, '-l', 'spa', '--oem', '1', '--psm', '4']);
    $proc->setTimeout(180);
    $proc->run();

    $txt = $outBase.'.txt';

    return is_readable($txt) ? trim((string) file_get_contents($txt)) : '';
}

$dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cotiz-val-'.bin2hex(random_bytes(4));
mkdir($dir, 0700, true);

$totalErrores = 0;
$resumen = [];

try {
    for ($hoja = $desde; $hoja <= $hasta; $hoja++) {
        echo "\n========== HOJA {$hoja} ==========\n";

        $esperado = $paginasMeta['filas_por_hoja'][$hoja - 1] ?? 0;
        $productos = array_values(array_filter(
            $golden['lineas'],
            static fn (array $f): bool => (int) ($f['pagina'] ?? 0) === $hoja,
        ));

        echo "Golden: {$esperado} productos\n";

        $ocrRaw = ocrPagina($pdfPath, $hoja, $dir);
        $ocrNorm = normalizar($ocrRaw);

        if ($ocrRaw === '') {
            echo "✗ OCR vacío\n";
            $totalErrores++;
            continue;
        }

        // Guardar OCR para revisión
        $ocrFile = dirname(__DIR__)."/storage/app/ocr_hoja_{$hoja}.txt";
        file_put_contents($ocrFile, $ocrRaw);

        $ok = 0;
        $fail = 0;

        foreach ($productos as $i => $fila) {
            $needle = $fila['needle'] ?? $fila['descripcion'];
            $cant = (int) $fila['cantidad'];
            $found = buscarNeedle($ocrNorm, $needle);

            // Cantidad: buscar número cerca en texto crudo (tolerante OCR)
            $cantOk = preg_match('/\b'.preg_quote((string) $cant, '/').'\b/u', $ocrRaw) === 1;

            if ($found && $cantOk) {
                echo sprintf(" ✓ #%d [%2d] %s\n", $i + 1, $cant, mb_substr($fila['descripcion'], 0, 55));
                $ok++;
            } else {
                echo sprintf(
                    " ✗ #%d [%2d] %s\n    needle=%s found=%s cant=%s\n",
                    $i + 1,
                    $cant,
                    mb_substr($fila['descripcion'], 0, 55),
                    $needle,
                    $found ? 'sí' : 'no',
                    $cantOk ? 'sí' : 'no',
                );
                $fail++;
                $totalErrores++;
            }
        }

        $estado = $fail === 0 ? 'OK' : "FALLO ({$fail})";
        $resumen[$hoja] = ['ok' => $ok, 'fail' => $fail, 'estado' => $estado];
        echo "Resultado hoja {$hoja}: {$ok}/{$esperado} {$estado}\n";
        echo "OCR guardado: {$ocrFile}\n";
    }
} finally {
    foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

echo "\n========== RESUMEN HOJAS {$desde}-{$hasta} ==========\n";
foreach ($resumen as $h => $r) {
    echo sprintf("Hoja %2d: %s (%d OK)\n", $h, $r['estado'], $r['ok']);
}

echo $totalErrores === 0
    ? "\nTodas las hojas validadas contra OCR del PDF.\n"
    : "\nTotal diferencias: {$totalErrores}\n";

exit($totalErrores === 0 ? 0 : 1);
