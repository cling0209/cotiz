<?php

/**
 * Compara import real (Paddle + parser) vs golden solicitud 83965, hoja por hoja.
 *
 * Uso:
 *   COTIZ_PADDLEOCR_URL=http://localhost:8010 php scripts/comparar_pdf_golden.php [ruta.pdf]
 */

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ListadoMaterialesPdfParserService;
use Illuminate\Http\UploadedFile;

$pdfPath = $argv[1] ?? 'C:\\Users\\csoto\\Downloads\\ESPECIFICACIONES TECNICAS .pdf';
if (! is_readable($pdfPath)) {
    $alt = dirname(__DIR__).'/storage/app/especificaciones-test.pdf';
    if (is_readable($alt)) {
        $pdfPath = $alt;
    } else {
        fwrite(STDERR, "PDF no legible: {$pdfPath}\n");
        exit(1);
    }
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

$parser = new ListadoMaterialesPdfParserService;
$uploaded = new UploadedFile($pdfPath, basename($pdfPath), 'application/pdf', null, true);
$doc = $parser->parseDocumentoCompleto($uploaded);
$importadas = $doc['lineas'];

$diag = $parser->diagnosticarPdf($pdfPath);

echo "=== DIAGNÓSTICO ===\n";
echo 'Paddle disponible: '.(($diag['herramientas']['paddleocr'] ?? false) ? 'sí' : 'no')."\n";
echo 'Filas Paddle raw: '.($diag['conteos']['paddle'] ?? 0)."\n";
echo 'Filas import final: '.count($importadas)."\n";
echo 'Golden esperado: '.$golden['total']."\n\n";

$normalizar = static function (string $s): string {
    $s = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $s) ?? $s));
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', '', $s) ?? $s;

    return trim($s);
};

$errores = 0;

if (count($importadas) !== (int) $golden['total']) {
    echo '⚠ Total distinto: import='.count($importadas).' golden='.$golden['total']."\n\n";
    $errores++;
}

$max = max(count($importadas), count($golden['lineas']));
for ($i = 0; $i < $max; $i++) {
    $imp = $importadas[$i] ?? null;
    $gol = $golden['lineas'][$i] ?? null;

    if ($imp === null) {
        echo sprintf("Fila %3d FALTA en import | golden [%d] %s\n", $i + 1, $gol['cantidad'], $gol['descripcion']);
        $errores++;

        continue;
    }
    if ($gol === null) {
        echo sprintf("Fila %3d EXTRA en import [%d] %s\n", $i + 1, $imp['cantidad'], $imp['descripcion']);
        $errores++;

        continue;
    }

    $cantOk = $imp['cantidad'] === $gol['cantidad'];
    $descImp = $normalizar($imp['descripcion']);
    $descGol = $normalizar($gol['descripcion']);
    $needle = $normalizar($gol['needle'] ?? $gol['descripcion']);
    $descOk = str_contains($descImp, $needle) || str_contains($descGol, mb_substr($descImp, 0, min(20, mb_strlen($descImp))));

    if (! $cantOk || ! $descOk) {
        echo sprintf(
            "Fila %3d | cant %s→%s | import [%d] %s\n         | golden [%d] %s\n",
            $i + 1,
            $cantOk ? 'ok' : 'NO',
            $descOk ? 'ok' : 'NO',
            $imp['cantidad'],
            $imp['descripcion'],
            $gol['cantidad'],
            $gol['descripcion'],
        );
        $errores++;
    }
}

echo "\n=== POR HOJA (Paddle pagina vs golden pagina) ===\n";
$lineasPaddle = $diag['lineas']['paddle'] ?? [];
$porPaginaPaddle = [];
foreach ($lineasPaddle as $fila) {
    $p = (int) ($fila['pagina'] ?? 0);
    $porPaginaPaddle[$p][] = $fila;
}
ksort($porPaginaPaddle);

$porPaginaGolden = [];
foreach ($golden['lineas'] as $fila) {
    $porPaginaGolden[(int) $fila['pagina']][] = $fila;
}
ksort($porPaginaGolden);

$filasPorHoja = $paginasMeta['filas_por_hoja'];
for ($h = 1; $h <= count($filasPorHoja); $h++) {
    $cntPaddle = count($porPaginaPaddle[$h] ?? []);
    $cntGolden = count($porPaginaGolden[$h] ?? []);
    $esperado = $filasPorHoja[$h - 1];
    $ok = $cntPaddle === $esperado && $cntGolden === $esperado;
    $mark = $ok ? '✓' : '✗';
    echo sprintf(
        "%s Hoja %2d: Paddle=%d golden=%d esperado=%d\n",
        $mark,
        $h,
        $cntPaddle,
        $cntGolden,
        $esperado,
    );
    if (! $ok) {
        $errores++;
    }
}

echo "\n".($errores === 0 ? "OK: cuadra con golden.\n" : "Errores: {$errores}\n");
exit($errores === 0 ? 0 : 1);
