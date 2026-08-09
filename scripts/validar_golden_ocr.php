<?php

/**
 * Compara parseTexto(vps_ocr_real) vs golden 97.
 * Uso: php scripts/validar_golden_ocr.php
 */

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ListadoMaterialesPdfParserService;
use Tests\Support\Solicitud83965Golden;

$texto = (string) file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/vps_ocr_real.txt');
$texto = preg_replace('/ESPECIFICACIONES SOLICITUD DE PEDIDO/u', 'ESPECIFICACIONES TECNICAS', $texto, 1) ?? $texto;

$parser = new ListadoMaterialesPdfParserService;
$lineas = $parser->parseTexto($texto);
$golden = Solicitud83965Golden::load();

echo 'Filas parseadas: '.count($lineas)."\n";
echo 'Golden esperado: '.$golden['total']."\n\n";

$faltan = [];
$extras = [];
$matched = 0;

foreach ($golden['lineas'] as $i => $g) {
    $encontrada = null;
    foreach ($lineas as $j => $l) {
        if (str_contains(mb_strtoupper($l['descripcion']), mb_strtoupper($g['needle']))) {
            $encontrada = $l;
            break;
        }
    }
    if ($encontrada === null) {
        $faltan[] = sprintf('#%d [%d] %s', $i + 1, $g['cantidad'], $g['needle']);
    } else {
        $matched++;
        if ($encontrada['cantidad'] !== $g['cantidad']) {
            echo sprintf(
                "CANTIDAD distinta #%d: esperada %d, got %d — %s\n",
                $i + 1,
                $g['cantidad'],
                $encontrada['cantidad'],
                $g['needle'],
            );
        }
    }
}

if ($faltan !== []) {
    echo "\nFALTAN (".count($faltan)."):\n";
    foreach ($faltan as $f) {
        echo "  $f\n";
    }
}

if (count($lineas) > $golden['total']) {
    echo "\nPosibles EXTRAS (filas sin needle golden):\n";
    foreach ($lineas as $j => $l) {
        $ok = false;
        foreach ($golden['lineas'] as $g) {
            if (str_contains(mb_strtoupper($l['descripcion']), mb_strtoupper($g['needle']))) {
                $ok = true;
                break;
            }
        }
        if (! $ok) {
            echo sprintf('  #%d [%d] %s', $j + 1, $l['cantidad'], $l['descripcion'])."\n";
        }
    }
}

echo "\nMatch: $matched/{$golden['total']}\n";
exit($matched === $golden['total'] && count($lineas) === $golden['total'] ? 0 : 1);
