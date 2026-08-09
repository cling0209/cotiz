<?php

/**
 * Extrae celdas Paddle página a página (reinicia contenedor tras cada hoja si hace falta).
 * Cuadra con golden; al terminar imprime listado por hoja.
 *
 * Uso: php scripts/procesar_cuadrar_por_hoja.php [pdf]
 */

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

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

$pdfBytes = (string) file_get_contents($pdfPath);
$totalHojas = 11;
$baseUrl = rtrim((string) (getenv('COTIZ_PADDLEOCR_URL') ?: config('cotiz.paddleocr.url', 'http://127.0.0.1:8010')), '/');
$cotizRoot = dirname(__DIR__);
$goldenPath = $cotizRoot.'/tests/Fixtures/pdf_materiales/solicitud_83965_paddle_golden.json';
$paginasPath = $cotizRoot.'/tests/Fixtures/pdf_materiales/solicitud_83965_paginas.json';
$outJson = $cotizRoot.'/storage/app/paddle_extraccion_por_hoja.json';

$golden = json_decode((string) file_get_contents($goldenPath), true, 512, JSON_THROW_ON_ERROR);
$paginasMeta = json_decode((string) file_get_contents($paginasPath), true, 512, JSON_THROW_ON_ERROR);

function esperarPaddle(string $baseUrl, int $maxSeg = 120): bool
{
    $deadline = time() + $maxSeg;
    while (time() < $deadline) {
        try {
            $r = Http::timeout(5)->get($baseUrl.'/health');
            if ($r->successful()) {
                return true;
            }
        } catch (\Throwable) {
            // reiniciando
        }
        sleep(3);
    }

    return false;
}

function reiniciarPaddle(string $cotizRoot): void
{
    $compose = escapeshellarg(str_replace('\\', '/', $cotizRoot).'/docker-compose.yml');
    shell_exec("docker compose -f {$compose} restart paddleocr 2>&1");
    sleep(5);
}

function extraerHoja(string $baseUrl, string $pdfBytes, string $nombrePdf, int $hoja, int $intentos = 3): ?array
{
    for ($i = 0; $i < $intentos; $i++) {
        try {
            $response = Http::timeout(600)
                ->attach('pdf', $pdfBytes, $nombrePdf)
                ->post($baseUrl.'/extract-tabla', [
                    'first_page' => $hoja,
                    'last_page' => $hoja,
                ]);

            if ($response->successful()) {
                return $response->json('lineas') ?? [];
            }

            fwrite(STDERR, "  HTTP {$response->status()}: {$response->body()}\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "  Error: {$e->getMessage()}\n");
        }

        global $cotizRoot;
        reiniciarPaddle($cotizRoot);
        if (! esperarPaddle($baseUrl)) {
            continue;
        }
    }

    return null;
}

if (! esperarPaddle($baseUrl)) {
    reiniciarPaddle($cotizRoot);
    if (! esperarPaddle($baseUrl, 180)) {
        fwrite(STDERR, "PaddleOCR no responde en {$baseUrl}\n");
        exit(1);
    }
}

$extraccion = ['total' => 0, 'filas_por_hoja' => [], 'lineas' => []];
$errores = 0;
$nombrePdf = basename($pdfPath);

for ($hoja = 1; $hoja <= $totalHojas; $hoja++) {
    echo "Extrayendo hoja {$hoja}/{$totalHojas} (celdas Paddle)...\n";

    $lineas = extraerHoja($baseUrl, $pdfBytes, $nombrePdf, $hoja);
    if ($lineas === null) {
        echo "  FALLO definitivo hoja {$hoja}\n";
        exit(1);
    }

    $esperado = $paginasMeta['filas_por_hoja'][$hoja - 1] ?? null;
    echo sprintf("  → %d filas (esperado %s)\n", count($lineas), $esperado ?? '?');

    $goldenHoja = array_values(array_filter(
        $golden['lineas'],
        static fn (array $f): bool => (int) ($f['pagina'] ?? 0) === $hoja,
    ));

    if (count($lineas) !== count($goldenHoja)) {
        echo sprintf("  ✗ conteo: paddle=%d golden=%d\n", count($lineas), count($goldenHoja));
        $errores++;
    }

    foreach ($lineas as $i => $fila) {
        $g = $goldenHoja[$i] ?? null;
        if ($g === null) {
            echo sprintf("  ✗ #%d extra [%d] %s\n", $i + 1, $fila['cantidad'], $fila['descripcion']);
            $errores++;
            continue;
        }
        $needle = mb_strtoupper($g['needle'] ?? mb_substr($g['descripcion'], 0, 20));
        $desc = mb_strtoupper($fila['descripcion']);
        if ($fila['cantidad'] !== $g['cantidad'] || ! str_contains($desc, mb_substr($needle, 0, min(12, mb_strlen($needle))))) {
            echo sprintf(
                "  ✗ #%d paddle [%d] %s\n      golden [%d] %s\n",
                $i + 1,
                $fila['cantidad'],
                $fila['descripcion'],
                $g['cantidad'],
                $g['descripcion'],
            );
            $errores++;
        }
    }

    $extraccion['filas_por_hoja'][] = count($lineas);
    foreach ($lineas as $fila) {
        $fila['pagina'] = $hoja;
        $extraccion['lineas'][] = $fila;
    }

    // Liberar RAM del sidecar entre páginas
    if ($hoja < $totalHojas) {
        reiniciarPaddle($cotizRoot);
        if (! esperarPaddle($baseUrl, 180)) {
            fwrite(STDERR, "Paddle no volvió tras hoja {$hoja}\n");
            exit(1);
        }
    }
}

$extraccion['total'] = count($extraccion['lineas']);
file_put_contents($outJson, json_encode($extraccion, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL);

echo "\nTotal Paddle: {$extraccion['total']} | Golden: {$golden['total']}\n";
echo 'Por hoja: '.json_encode($extraccion['filas_por_hoja'])."\n";

if ($errores > 0 || $extraccion['total'] !== (int) $golden['total']) {
    echo "\nNo cuadra con el PDF/golden ({$errores} diferencias). No se muestra listado.\n";
    exit(1);
}

echo "\n========== LISTADO POR HOJA (celdas Paddle = PDF) ==========\n\n";

$porPagina = [];
foreach ($extraccion['lineas'] as $fila) {
    $porPagina[$fila['pagina']][] = $fila;
}
ksort($porPagina);

foreach ($porPagina as $num => $filas) {
    echo "=== HOJA {$num} (".count($filas)." productos) ===\n";
    foreach ($filas as $j => $f) {
        echo sprintf(" %2d. [%2d] %s\n", $j + 1, $f['cantidad'], $f['descripcion']);
    }
    echo "\n";
}

echo 'Total: '.$extraccion['total']." productos en {$totalHojas} hojas\n";
exit(0);
