<?php

/**
 * Extrae PaddleOCR página a página (evita OOM en Docker local) y compara con golden.
 *
 * Uso:
 *   COTIZ_PADDLEOCR_URL=http://127.0.0.1:8010 php scripts/extraer_paddle_por_hoja.php [pdf] [hojas]
 */

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$pdfPath = $argv[1] ?? 'C:\\Users\\csoto\\Downloads\\ESPECIFICACIONES TECNICAS .pdf';
$totalHojas = (int) ($argv[2] ?? 11);
$baseUrl = rtrim((string) config('cotiz.paddleocr.url', 'http://127.0.0.1:8010'), '/');

if (! is_readable($pdfPath)) {
    fwrite(STDERR, "PDF no legible: {$pdfPath}\n");
    exit(1);
}

$pdfBytes = (string) file_get_contents($pdfPath);
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

$health = Http::timeout(10)->get($baseUrl.'/health');
if (! $health->successful()) {
    fwrite(STDERR, "PaddleOCR no responde en {$baseUrl}\n");
    exit(1);
}

$todas = [];
$errores = 0;

for ($hoja = 1; $hoja <= $totalHojas; $hoja++) {
    echo "Extrayendo hoja {$hoja}...\n";

    $response = Http::timeout(600)
        ->attach('pdf', $pdfBytes, basename($pdfPath))
        ->post($baseUrl.'/extract-tabla', [
            'first_page' => $hoja,
            'last_page' => $hoja,
        ]);

    if (! $response->successful()) {
        echo "  ERROR HTTP {$response->status()}: ".$response->body()."\n";
        $errores++;

        continue;
    }

    $lineas = $response->json('lineas') ?? [];
    $esperado = $paginasMeta['filas_por_hoja'][$hoja - 1] ?? null;
    echo sprintf("  Paddle: %d filas | esperado: %s\n", count($lineas), $esperado ?? '?');

    $goldenHoja = array_values(array_filter(
        $golden['lineas'],
        static fn (array $f): bool => (int) ($f['pagina'] ?? 0) === $hoja,
    ));

    foreach ($lineas as $i => $fila) {
        $g = $goldenHoja[$i] ?? null;
        $okCant = $g && $fila['cantidad'] === $g['cantidad'];
        $needle = $g ? mb_strtoupper($g['needle'] ?? $g['descripcion']) : '';
        $desc = mb_strtoupper($fila['descripcion']);
        $okDesc = $g && ($needle === '' || str_contains($desc, mb_substr($needle, 0, min(20, mb_strlen($needle)))));

        if (! $g || ! $okCant || ! $okDesc) {
            echo sprintf(
                "  ✗ #%d paddle [%d] %s\n    golden [%s] %s\n",
                $i + 1,
                $fila['cantidad'],
                $fila['descripcion'],
                $g ? $g['cantidad'] : '-',
                $g ? $g['descripcion'] : '(falta)',
            );
            $errores++;
        }
    }

    if (count($lineas) !== count($goldenHoja)) {
        echo sprintf("  ✗ conteo hoja: paddle=%d golden=%d\n", count($lineas), count($goldenHoja));
        $errores++;
    }

    foreach ($lineas as $fila) {
        $fila['pagina'] = $hoja;
        $todas[] = $fila;
    }
}

$outPath = dirname(__DIR__).'/storage/app/paddle_por_hoja_83965.json';
file_put_contents($outPath, json_encode(['total' => count($todas), 'lineas' => $todas], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nTotal Paddle: ".count($todas)." (golden {$golden['total']})\n";
echo "Guardado: {$outPath}\n";
echo $errores === 0 ? "OK: todas las hojas cuadran.\n" : "Errores: {$errores}\n";
exit($errores === 0 ? 0 : 1);
