<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\ListadoMaterialesPdfParserService;
use App\Services\PdfPaddleOcrService;

$fixtureDir = dirname(__DIR__).'/tests/Fixtures/pdf_materiales/';
$parser = new ListadoMaterialesPdfParserService();

foreach (glob($fixtureDir.'*.txt') as $path) {
    $texto = (string) file_get_contents($path);
    $n = count($parser->parseTexto($texto));
    $fmt = $parser->detectarFormato($texto);
    echo basename($path).": {$fmt} => {$n}\n";
}

echo "\n--- vps_ocr_real tecnicas title ---\n";
$t = (string) file_get_contents($fixtureDir.'vps_ocr_real.txt');
$t = preg_replace('/ESPECIFICACIONES SOLICITUD DE PEDIDO/u', 'ESPECIFICACIONES TECNICAS', $t, 1) ?? $t;
echo 'formato: '.$parser->detectarFormato($t)."\n";
echo 'count: '.count($parser->parseTexto($t))."\n";

echo "\n--- fusion mock paddle=97 text=148 ---\n";
$texto = $t;
$lineasTexto = $parser->parseTexto($texto);
// Simular 148 filas texto (duplicar algunas)
while (count($lineasTexto) < 148) {
    $lineasTexto[] = ['cantidad' => 1, 'descripcion' => 'FRAGMENTO OCR '.count($lineasTexto)];
}
$lineasTexto = array_slice($lineasTexto, 0, 148);

$lineasPaddle = [];
for ($i = 1; $i <= 97; $i++) {
    $lineasPaddle[] = ['cantidad' => ($i % 15) + 1, 'descripcion' => "PRODUCTO CELDA {$i}"];
}

$paddle = new class($lineasPaddle) extends PdfPaddleOcrService {
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

$parser2 = new ListadoMaterialesPdfParserService(null, $paddle);
$metodo = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'fusionarLineasConPaddle');
$metodo->setAccessible(true);
$tmp = tempnam(sys_get_temp_dir(), 'pdf');
file_put_contents($tmp, '%PDF-1.4');
$fusion = $metodo->invoke($parser2, $tmp, $lineasTexto, $texto);
@unlink($tmp);
echo 'texto='.count($lineasTexto).' paddle=97 fusion='.count($fusion)."\n";
