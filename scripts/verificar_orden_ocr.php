<?php
/** Verifica que los 97 productos golden aparecen en orden en el OCR VPS. */
require dirname(__DIR__).'/vendor/autoload.php';

$golden = json_decode(
    file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/solicitud_83965_paddle_golden.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$ocr = mb_strtoupper((string) file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/vps_ocr_real.txt'));

$pos = 0;
$errores = 0;
foreach ($golden['lineas'] as $i => $fila) {
    $needle = mb_strtoupper($fila['needle'] ?? mb_substr($fila['descripcion'], 0, 15));
    $needle = preg_replace('/\s+/u', ' ', $needle) ?? $needle;
    $found = mb_strpos($ocr, $needle, $pos);
    if ($found === false) {
        // intentar primeras 3 palabras
        $words = explode(' ', $needle);
        $short = implode(' ', array_slice($words, 0, min(3, count($words))));
        $found = mb_strpos($ocr, $short, $pos);
    }
    if ($found === false) {
        echo sprintf("✗ #%d no encontrado: %s (hoja %d)\n", $i + 1, $needle, $fila['pagina']);
        $errores++;
    } else {
        $pos = $found + mb_strlen($needle);
    }
}

echo $errores === 0
    ? "OK: 97 productos en orden en OCR VPS.\n"
    : "Errores: {$errores}\n";
exit($errores === 0 ? 0 : 1);
