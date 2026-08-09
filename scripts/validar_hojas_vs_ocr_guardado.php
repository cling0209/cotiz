<?php

/** Valida golden vs OCR guardados (storage/app/ocr_hoja_N.txt). */
require dirname(__DIR__).'/vendor/autoload.php';

$golden = json_decode(
    file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/solicitud_83965_paddle_golden.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

function norm(string $s): string
{
    $s = mb_strtoupper($s);
    $s = str_replace(['Á','É','Í','Ó','Ú','Ñ','*','°'], ['A','E','I','O','U','N','',''], $s);

    return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s) ?? '');
}

function matchNeedle(string $ocr, string $needle): bool
{
    $n = norm($needle);
    $o = norm($ocr);
    if ($n !== '' && str_contains($o, $n)) {
        return true;
    }
    $w = explode(' ', $n);
    if (count($w) >= 2 && str_contains($o, implode(' ', array_slice($w, 0, 3)))) {
        return true;
    }
    if (count($w) >= 1 && mb_strlen($w[0]) >= 6 && str_contains($o, $w[0])) {
        return true;
    }

    return false;
}

$errores = 0;
for ($h = 5; $h <= 11; $h++) {
    $ocrFile = dirname(__DIR__)."/storage/app/ocr_hoja_{$h}.txt";
    if (! is_readable($ocrFile)) {
        echo "Hoja {$h}: sin OCR\n";
        $errores++;
        continue;
    }
    $ocr = (string) file_get_contents($ocrFile);
    $prods = array_filter($golden['lineas'], fn ($f) => (int) $f['pagina'] === $h);
    $ok = 0;
    echo "\n=== HOJA {$h} (".count($prods)." prod.) ===\n";
    foreach ($prods as $i => $f) {
        $found = matchNeedle($ocr, $f['needle'] ?? $f['descripcion']);
        if ($found) {
            echo sprintf(" ✓ #%d [%2d] %s\n", $i + 1, $f['cantidad'], mb_substr($f['descripcion'], 0, 50));
            $ok++;
        } else {
            echo sprintf(" ✗ #%d %s\n", $i + 1, $f['needle'] ?? $f['descripcion']);
            $errores++;
        }
    }
    echo " → {$ok}/".count($prods)."\n";
}

exit($errores === 0 ? 0 : 1);
