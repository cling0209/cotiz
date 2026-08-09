<?php

/**
 * Cuadra celdas (producto + cantidad) hoja por hoja contra el PDF.
 * 1) OCR por página → storage/app/ocr_hoja_N.txt
 * 2) Compara golden vs OCR + intenta Paddle si responde
 *
 * Uso: php scripts/cuadrar_celdas_por_hoja.php [pdf] [desde] [hasta]
 */

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

$pdfPath = $argv[1] ?? 'C:\\Users\\csoto\\Downloads\\ESPECIFICACIONES TECNICAS .pdf';
$desde = max(1, (int) ($argv[2] ?? 1));
$hasta = min(11, (int) ($argv[3] ?? 11));

if (! is_readable($pdfPath)) {
    $alt = dirname(__DIR__).'/storage/app/especificaciones-test.pdf';
    if (is_readable($alt)) {
        $pdfPath = $alt;
    } else {
        fwrite(STDERR, "PDF no legible\n");
        exit(1);
    }
}

$goldenPath = dirname(__DIR__).'/tests/Fixtures/pdf_materiales/solicitud_83965_paddle_golden.json';
$paginasPath = dirname(__DIR__).'/tests/Fixtures/pdf_materiales/solicitud_83965_paginas.json';
$reportPath = dirname(__DIR__).'/storage/app/cuadre_celdas_report.json';

$golden = json_decode((string) file_get_contents($goldenPath), true, 512, JSON_THROW_ON_ERROR);
$paginas = json_decode((string) file_get_contents($paginasPath), true, 512, JSON_THROW_ON_ERROR);
$pdfBytes = (string) file_get_contents($pdfPath);
$paddleUrl = rtrim((string) (getenv('COTIZ_PADDLEOCR_URL') ?: config('cotiz.paddleocr.url', 'http://127.0.0.1:8010')), '/');
$paddleOk = false;
try {
    $paddleOk = Http::timeout(5)->get($paddleUrl.'/health')->successful();
} catch (\Throwable) {
    $paddleOk = false;
}

function norm(string $s): string
{
    $s = mb_strtoupper($s);
    $s = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ', '*', '°', '×'], ['A', 'E', 'I', 'O', 'U', 'N', '', '', 'X'], $s);

    return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s) ?? '');
}

function ocrPagina(string $pdf, int $hoja): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cotiz-ocr-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    try {
        $prefijo = $dir.DIRECTORY_SEPARATOR.'p';
        $proc = new Process([
            'pdftoppm', '-png', '-gray', '-r', '200',
            '-f', (string) $hoja, '-l', (string) $hoja,
            $pdf, $prefijo,
        ]);
        $proc->setTimeout(120);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return '';
        }
        $imgs = glob($prefijo.'-*.png') ?: [];
        if ($imgs === []) {
            return '';
        }
        $img = $imgs[0];
        if (function_exists('imagecreatefrompng')) {
            $im = @imagecreatefrompng($img);
            if ($im !== false) {
                $w = imagesx($im);
                $h = imagesy($im);
                $cw = max(1, (int) round($w * 0.58));
                $cr = imagecrop($im, ['x' => 0, 'y' => 0, 'width' => $cw, 'height' => $h]);
                imagedestroy($im);
                if ($cr !== false) {
                    $cp = $img.'.crop.png';
                    imagepng($cr, $cp);
                    imagedestroy($cr);
                    $img = $cp;
                }
            }
        }
        $out = $img.'.ocr';
        $proc = new Process(['tesseract', $img, $out, '-l', 'spa', '--oem', '1', '--psm', '4']);
        $proc->setTimeout(180);
        $proc->run();
        $txt = $out.'.txt';

        return is_readable($txt) ? trim((string) file_get_contents($txt)) : '';
    } finally {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}

function extraerPaddleHoja(string $url, string $pdfBytes, string $nombre, int $hoja): ?array
{
    try {
        $r = Http::timeout(600)
            ->attach('pdf', $pdfBytes, $nombre)
            ->post($url.'/extract-tabla', ['first_page' => $hoja, 'last_page' => $hoja]);
        if ($r->successful()) {
            return $r->json('lineas') ?? [];
        }
    } catch (\Throwable) {
        return null;
    }

    return null;
}

function compararCeldas(array $esperadas, array $obtenidas, string $fuente): array
{
    $errores = [];
    if (count($esperadas) !== count($obtenidas)) {
        $errores[] = [
            'tipo' => 'conteo',
            'msg' => sprintf('%s: esperado %d filas, obtuvo %d', $fuente, count($esperadas), count($obtenidas)),
        ];
    }
    $max = max(count($esperadas), count($obtenidas));
    for ($i = 0; $i < $max; $i++) {
        $e = $esperadas[$i] ?? null;
        $o = $obtenidas[$i] ?? null;
        if ($e === null || $o === null) {
            $errores[] = ['tipo' => 'fila', 'idx' => $i + 1, 'msg' => 'Fila extra o faltante', 'fuente' => $fuente];
            continue;
        }
        $needle = norm($e['needle'] ?? $e['descripcion']);
        $desc = norm($o['descripcion'] ?? '');
        $okDesc = $needle === '' || str_contains($desc, norm(mb_substr($needle, 0, min(15, mb_strlen($needle)))));
        $okCant = (int) ($o['cantidad'] ?? 0) === (int) $e['cantidad'];
        if (! $okDesc || ! $okCant) {
            $errores[] = [
                'tipo' => 'celda',
                'idx' => $i + 1,
                'fuente' => $fuente,
                'esperado' => ['cantidad' => $e['cantidad'], 'descripcion' => $e['descripcion']],
                'obtenido' => ['cantidad' => $o['cantidad'] ?? null, 'descripcion' => $o['descripcion'] ?? ''],
            ];
        }
    }

    return $errores;
}

$report = ['pdf' => $pdfPath, 'paddle_disponible' => $paddleOk, 'hojas' => [], 'total_errores' => 0];
$totalErrores = 0;

for ($hoja = $desde; $hoja <= $hasta; $hoja++) {
    echo "\n========== HOJA {$hoja} ==========\n";

    $esperadas = array_values(array_filter(
        $golden['lineas'],
        static fn (array $f): bool => (int) ($f['pagina'] ?? 0) === $hoja,
    ));
    $esperadoCount = $paginas['filas_por_hoja'][$hoja - 1] ?? count($esperadas);

    echo sprintf("Golden: %d celdas (esperado split: %d)\n", count($esperadas), $esperadoCount);

    $ocrDir = dirname(__DIR__).'/storage/app';
    if (! is_dir($ocrDir)) {
        mkdir($ocrDir, 0755, true);
    }
    $ocrFile = "{$ocrDir}/ocr_hoja_{$hoja}.txt";
    $ocr = is_readable($ocrFile) ? trim((string) file_get_contents($ocrFile)) : '';
    if ($ocr === '') {
        echo "Generando OCR hoja {$hoja}...\n";
        $ocr = ocrPagina($pdfPath, $hoja);
        if ($ocr !== '') {
            file_put_contents($ocrFile, $ocr);
        }
    }

    $ocrNorm = norm($ocr);
    $ocrErrores = 0;
    foreach ($esperadas as $i => $f) {
        $needle = $f['needle'] ?? $f['descripcion'];
        $n = norm($needle);
        $found = $n !== '' && str_contains($ocrNorm, $n);
        if (! $found) {
            $w = explode(' ', $n);
            $found = count($w) >= 2 && str_contains($ocrNorm, implode(' ', array_slice($w, 0, 3)));
        }
        $cantOk = preg_match('/\b'.preg_quote((string) $f['cantidad'], '/').'\b/u', $ocr) === 1;
        if ($found && $cantOk) {
            echo sprintf("  OCR ✓ #%d [%2d] %s\n", $i + 1, $f['cantidad'], mb_substr($f['descripcion'], 0, 50));
        } else {
            echo sprintf("  OCR ✗ #%d [%2d] %s (texto=%s cant=%s)\n", $i + 1, $f['cantidad'], mb_substr($f['descripcion'], 0, 45), $found ? 'ok' : 'no', $cantOk ? 'ok' : 'no');
            $ocrErrores++;
        }
    }

    $paddleLineas = null;
    $paddleErrores = [];
    if ($paddleOk) {
        echo "Extrayendo celdas Paddle hoja {$hoja}...\n";
        $paddleLineas = extraerPaddleHoja($paddleUrl, $pdfBytes, basename($pdfPath), $hoja);
        if ($paddleLineas !== null) {
            $paddleErrores = compararCeldas($esperadas, $paddleLineas, 'Paddle');
            foreach ($paddleLineas as $i => $p) {
                echo sprintf("  Paddle #%d [%2d] %s\n", $i + 1, $p['cantidad'], mb_substr($p['descripcion'], 0, 55));
            }
            if ($paddleErrores === []) {
                echo "  Paddle: OK cuadra con golden\n";
            } else {
                foreach ($paddleErrores as $err) {
                    echo '  Paddle ✗ '.($err['msg'] ?? json_encode($err, JSON_UNESCAPED_UNICODE))."\n";
                }
            }
        } else {
            echo "  Paddle: no respondió (OOM?)\n";
        }
    }

    $hojaOk = $ocrErrores === 0 && ($paddleLineas === null || $paddleErrores === []);
    $totalErrores += $ocrErrores + count($paddleErrores);

    $report['hojas'][$hoja] = [
        'celdas_golden' => count($esperadas),
        'ocr_errores' => $ocrErrores,
        'paddle_filas' => $paddleLineas !== null ? count($paddleLineas) : null,
        'paddle_errores' => count($paddleErrores),
        'ok' => $hojaOk,
        'celdas' => array_map(static fn (array $f): array => [
            'cantidad' => $f['cantidad'],
            'descripcion' => $f['descripcion'],
        ], $esperadas),
    ];

    echo $hojaOk ? "→ HOJA {$hoja} OK\n" : "→ HOJA {$hoja} CON DIFERENCIAS\n";
}

$report['total_errores'] = $totalErrores;
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);

echo "\n========== RESUMEN {$desde}-{$hasta} ==========\n";
foreach ($report['hojas'] as $h => $r) {
    echo sprintf('Hoja %2d: %s (OCR err=%d, Paddle err=%s)'."\n", $h, $r['ok'] ? 'OK' : 'FALLO', $r['ocr_errores'], $r['paddle_errores'] !== null ? (string) $r['paddle_errores'] : 'N/A');
}
echo "Reporte: {$reportPath}\n";

exit($totalErrores === 0 ? 0 : 1);
