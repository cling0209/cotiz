<?php

/**
 * Lista productos agrupados por hoja (campo pagina del golden / Paddle).
 * Uso: php scripts/listar_por_hoja.php
 */

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ListadoMaterialesPdfParserService;
use App\Services\PdfPaddleOcrService;

$golden = json_decode(
    (string) file_get_contents(dirname(__DIR__).'/tests/Fixtures/pdf_materiales/solicitud_83965_paddle_golden.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

$lineasConPagina = array_map(static function (array $fila): array {
    return [
        'cantidad' => $fila['cantidad'],
        'descripcion' => $fila['descripcion'],
        'pagina' => (int) ($fila['pagina'] ?? 0),
    ];
}, $golden['lineas']);

$paddle = new class ($lineasConPagina) extends PdfPaddleOcrService {
    public function __construct(private array $lineas) {}

    public function estaDisponible(): bool
    {
        return true;
    }

    public function extraerLineasTabla(string $path): array
    {
        return $this->lineas;
    }
};

$parser = new ListadoMaterialesPdfParserService(null, $paddle);
$metodo = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'fusionarLineasConPaddle');
$metodo->setAccessible(true);

$texto = "ESPECIFICACIONES TECNICAS\n83965\nPRODUCTO CANTIDAD IMAGEN REFERENCIA\n";
$tmp = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
file_put_contents($tmp, '%PDF-1.4');

try {
    $fusionadas = $metodo->invoke($parser, $tmp, [], $texto);
} finally {
    @unlink($tmp);
}

$porPagina = [];
foreach ($lineasConPagina as $fila) {
    $porPagina[$fila['pagina']][] = $fila;
}
ksort($porPagina);

foreach ($porPagina as $numPagina => $filas) {
    echo '=== HOJA '.$numPagina.' ('.count($filas)." productos) ===\n";
    foreach ($filas as $j => $f) {
        echo sprintf(" %2d. [%2d] %s\n", $j + 1, $f['cantidad'], $f['descripcion']);
    }
    echo "\n";
}

echo 'Total: '.count($lineasConPagina).' productos en '.count($porPagina)." hojas\n";
echo 'Fusión Paddle (celdas): '.count($fusionadas)." filas\n";
