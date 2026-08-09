<?php

/**
 * Ejercicio local: simula Paddle ~142 filas + PDF TECNICAS → lista final esperada (97).
 * Uso: php scripts/listar_productos_ejercicio_97.php
 */

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ListadoMaterialesPdfParserService;
use App\Services\PdfPaddleOcrService;
use Tests\Support\Solicitud83965Golden;

$golden = Solicitud83965Golden::load();

function simularPaddle142Realista(array $goldenLineas): array
{
    $out = [];
    $porPagina = 0;

    foreach ($goldenLineas as $fila) {
        if ($porPagina === 0) {
            $out[] = ['cantidad' => 1, 'descripcion' => 'PRODUCTO'];
            $out[] = ['cantidad' => 1, 'descripcion' => 'CANTIDAD IMAGEN REFERENCIA'];
        }

        $out[] = ['cantidad' => $fila['cantidad'], 'descripcion' => $fila['descripcion']];

        $words = preg_split('/\s+/u', trim($fila['descripcion'])) ?: [];
        if (count($words) >= 4) {
            $out[] = [
                'cantidad' => $fila['cantidad'],
                'descripcion' => implode(' ', array_slice($words, 0, 2)),
            ];
        }

        if (preg_match('/\b(?:JUMBO|12|COLORES)\b/u', mb_strtoupper($fila['descripcion'])) === 1) {
            $out[] = ['cantidad' => 1, 'descripcion' => 'COLORES'];
        }

        $porPagina++;
        if ($porPagina >= 9) {
            $porPagina = 0;
        }
    }

    return $out;
}

$lineasPaddle = simularPaddle142Realista($golden['lineas']);

$fixturePath = dirname(__DIR__).'/tests/Fixtures/pdf_materiales/vps_ocr_real.txt';
$texto = (string) file_get_contents($fixturePath);
$texto = preg_replace('/ESPECIFICACIONES SOLICITUD DE PEDIDO/u', 'ESPECIFICACIONES TECNICAS', $texto, 1) ?? $texto;

$parserBase = new ListadoMaterialesPdfParserService;
$lineasTexto = $parserBase->parseTexto($texto);

$paddle = new class ($lineasPaddle) extends PdfPaddleOcrService {
    public function __construct(private array $lineas) {}

    public function estaDisponible(): bool
    {
        return true;
    }

    public function extraerLineasTabla(string $path, string $nombreArchivo = ''): array
    {
        return $this->lineas;
    }

    public function extraerLineasTablaPorPagina(string $path, string $nombreArchivo = ''): array
    {
        return $this->lineas;
    }
};

$parser = new ListadoMaterialesPdfParserService(null, $paddle);
$metodo = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'fusionarLineasConPaddle');
$metodo->setAccessible(true);

$tmp = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
file_put_contents($tmp, '%PDF-1.4');

echo "=== EJERCICIO IMPORT PDF (simula VPS: Paddle ~142 filas → parser) ===\n\n";
echo 'Entrada Paddle (simulada): '.count($lineasPaddle)." filas\n";
echo 'Entrada texto OCR: '.count($lineasTexto)." filas\n";

$fusion = $metodo->invoke($parser, $tmp, $lineasTexto, $texto);
@unlink($tmp);

echo 'Salida parser (import final): '.count($fusion)." filas\n\n";

if (count($fusion) !== 97) {
    echo "ERROR: se esperaban 97 filas, hay ".count($fusion)."\n\n";
}

echo str_pad('#', 4).' | '.str_pad('Cant', 5).' | Producto'."\n";
echo str_repeat('-', 90)."\n";

foreach ($fusion as $i => $fila) {
    $n = $i + 1;
    echo str_pad((string) $n, 4).' | '.str_pad((string) $fila['cantidad'], 5).' | '.$fila['descripcion']."\n";
}

echo "\n=== PÁGINA 1 (primeras 9 filas del PDF) ===\n";
for ($i = 0; $i < min(9, count($fusion)); $i++) {
    echo ($i + 1).'. ['.$fusion[$i]['cantidad'].'] '.$fusion[$i]['descripcion']."\n";
}

try {
    Solicitud83965Golden::assertLineasMatchGolden(new PHPUnit\Framework\TestCase, $fusion);
    echo "\n✓ Validación golden: 97 productos con cantidades correctas\n";
} catch (Throwable $e) {
    echo "\n✗ Validación golden falló: ".$e->getMessage()."\n";
    exit(1);
}
