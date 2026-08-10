<?php

/**
 * Compara extracción vs números de línea detectables en la grilla del PDF.
 * Uso: php scripts/validar_coincidencia_pdf.php [ruta.pdf]
 */

require __DIR__.'/../vendor/autoload.php';

$pdfPath = $argv[1] ?? 'c:/Archivos Varios/OTROS/John/Req/pdf cotiz/BASES_LICT._PUBLICA_MATERIAL_DIDACTICO_Y_ART._DE_ESCRITORIO.pdf';
$colCant = 'UNIDADES* POR AÑO';
$colProd = 'DESCRIPCION REQUERIMIENTO';
$esperadoTotal = 537;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ListadoMaterialesPdfParserService;
use Illuminate\Http\UploadedFile;

if (! is_readable($pdfPath)) {
    fwrite(STDERR, "No se puede leer: {$pdfPath}\n");
    exit(1);
}

$parser = app(ListadoMaterialesPdfParserService::class);
$ref = new ReflectionClass($parser);

$extraer = $ref->getMethod('extraerGrillaNativaPdf');
$extraer->setAccessible(true);
$grilla = $extraer->invoke($parser, $pdfPath);

$file = new UploadedFile($pdfPath, basename($pdfPath), 'application/pdf', null, true);
$resultado = $parser->parseDocumentoConMapeoColumnas($file, $colCant, $colProd);
$lineas = $resultado['lineas'] ?? [];

/** @var array<int, array{desc: string, cantidad: int, pagina: int, fila: string}> $enGrilla */
$enGrilla = [];
foreach ($grilla as $pag) {
    $numPag = (int) ($pag['pagina'] ?? 0);
    if ($numPag < 34 || $numPag > 48) {
        continue;
    }
    foreach ($pag['filas'] ?? [] as $filaRaw) {
        if (! is_array($filaRaw)) {
            continue;
        }
        $celdas = array_values(array_filter(array_map(static fn ($c): string => trim((string) $c), $filaRaw)));
        if ($celdas === []) {
            continue;
        }
        $texto = implode(' ', $celdas);
        if (preg_match('/(?:^|\s)(\d{1,3})\s+(?:[A-ZÁÉÍÓÚÑ(]|STANDARD|Limpiapipa|Agujas|AGUJAS)/u', $texto, $m) === 1) {
            $n = (int) $m[1];
            if ($n >= 1 && $n <= $esperadoTotal && ! isset($enGrilla[$n])) {
                $enGrilla[$n] = ['desc' => $texto, 'pagina' => $numPag, 'fila' => $texto];
            }
        } elseif (preg_match('/^\d{1,3}$/u', $celdas[0] ?? '') === 1) {
            $n = (int) $celdas[0];
            if ($n >= 1 && $n <= $esperadoTotal && ! isset($enGrilla[$n])) {
                $enGrilla[$n] = ['desc' => $texto, 'pagina' => $numPag, 'fila' => $texto];
            }
        }
    }
}

/** @var array<int, array{cantidad: int, descripcion: string}> $porNumero */
$porNumero = [];
$sinNumero = [];
foreach ($lineas as $i => $l) {
    $desc = trim($l['descripcion'] ?? '');
    if (preg_match('/^(\d{1,3})\s+(.+)$/u', $desc, $m) === 1) {
        $porNumero[(int) $m[1]] = [
            'cantidad' => (int) $l['cantidad'],
            'descripcion' => trim($m[2]),
            'descCompleta' => $desc,
            'idx' => $i + 1,
        ];
    } elseif (preg_match('/\b(\d{1,3})\s+([A-ZÁÉÍÓÚÑ])/u', $desc, $m) === 1) {
        $porNumero[(int) $m[1]] = [
            'cantidad' => (int) $l['cantidad'],
            'descripcion' => $desc,
            'descCompleta' => $desc,
            'idx' => $i + 1,
        ];
    } else {
        $sinNumero[] = [
            'idx' => $i + 1,
            'cantidad' => (int) $l['cantidad'],
            'descripcion' => $desc,
        ];
    }
}

$extraidosNumerados = count($porNumero);
$detectablesGrilla = count($enGrilla);
$coinciden = 0;
$faltanEnExtraccion = [];
$extraSinGrilla = [];

for ($n = 1; $n <= $esperadoTotal; $n++) {
    $inGrilla = isset($enGrilla[$n]);
    $inExtra = isset($porNumero[$n]);
    if ($inGrilla && $inExtra) {
        $coinciden++;
    } elseif ($inGrilla && ! $inExtra) {
        $faltanEnExtraccion[] = $n;
    } elseif (! $inGrilla && $inExtra) {
        $extraSinGrilla[] = $n;
    }
}

echo "PDF: ".basename($pdfPath).PHP_EOL;
echo str_repeat('=', 60).PHP_EOL;
echo "Esperado (tabla PDF):           {$esperadoTotal} ítems (líneas 1–537)".PHP_EOL;
echo "Extraídos por mapeo:            ".count($lineas).PHP_EOL;
echo "Con número de línea reconocido: {$extraidosNumerados}".PHP_EOL;
echo "Detectables en grilla nativa:   {$detectablesGrilla}".PHP_EOL;
echo "Coinciden (grilla ∩ extracción): {$coinciden}".PHP_EOL;
echo "En grilla pero NO extraídos:    ".count($faltanEnExtraccion).PHP_EOL;
echo "Extraídos sin rastro en grilla: ".count($extraSinGrilla).PHP_EOL;
echo "Sin número en descripción:      ".count($sinNumero).PHP_EOL;
echo str_repeat('=', 60).PHP_EOL;

$casos = [1, 3, 10, 128, 510, 511, 528, 534, 537];
echo PHP_EOL.'Muestra ítems clave:'.PHP_EOL;
foreach ($casos as $n) {
    $g = $enGrilla[$n]['fila'] ?? '(no en grilla)';
    $e = isset($porNumero[$n])
        ? sprintf('[%4d] %s', $porNumero[$n]['cantidad'], mb_substr($porNumero[$n]['descCompleta'], 0, 70))
        : '(no extraído)';
    echo PHP_EOL."--- Línea {$n} ---".PHP_EOL;
    echo "  Grilla:     ".mb_substr($g, 0, 90).PHP_EOL;
    echo "  Extracción: {$e}".PHP_EOL;
}

if ($faltanEnExtraccion !== []) {
    echo PHP_EOL.'Primeros 20 en grilla pero no extraídos: '.implode(', ', array_slice($faltanEnExtraccion, 0, 20)).PHP_EOL;
}

if ($sinNumero !== []) {
    echo PHP_EOL.'Primeros 10 extraídos sin número de línea al inicio:'.PHP_EOL;
    foreach (array_slice($sinNumero, 0, 10) as $row) {
        echo sprintf('  #%d [%4d] %s', $row['idx'], $row['cantidad'], mb_substr($row['descripcion'], 0, 75)).PHP_EOL;
    }
}

$pct = $detectablesGrilla > 0 ? round(100 * $coinciden / $detectablesGrilla, 1) : 0;
$pctTotal = round(100 * count($lineas) / $esperadoTotal, 1);
echo PHP_EOL."Cobertura vs grilla detectable: {$pct}%".PHP_EOL;
echo "Cobertura vs 537 del PDF:       {$pctTotal}% (".count($lineas)."/{$esperadoTotal})".PHP_EOL;

exit(count($lineas) >= 490 ? 0 : 1);
