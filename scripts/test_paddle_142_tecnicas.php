<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\ListadoMaterialesPdfParserService;
use App\Services\PdfPaddleOcrService;
use Tests\Support\Solicitud83965Golden;

$golden = Solicitud83965Golden::load();

/** Simula salida real Paddle ~142: productos + sufijos + prefijos + cabeceras por página. */
function simularPaddle142Realista(array $goldenLineas): array
{
    $out = [];
    $porPagina = 0;

    foreach ($goldenLineas as $i => $fila) {
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

        if (str_contains(mb_strtoupper($fila['descripcion']), 'JUMBO')
            || str_contains(mb_strtoupper($fila['descripcion']), 'COLORES')
            || str_contains(mb_strtoupper($fila['descripcion']), '12')) {
            $out[] = ['cantidad' => 1, 'descripcion' => 'COLORES'];
        }

        if (str_contains(mb_strtoupper($fila['descripcion']), 'UNIDADES')) {
            $out[] = ['cantidad' => 1, 'descripcion' => 'UNIDADES IMAGIA TRIANGULAR'];
        }

        $porPagina++;
        if ($porPagina >= 9) {
            $porPagina = 0;
        }
    }

    return $out;
}

$lineasPaddle = simularPaddle142Realista($golden['lineas']);
echo 'Paddle simulado: '.count($lineasPaddle)."\n";

// Texto mínimo TECNICAS sin cabecera PRODUCTO CANTIDAD (como PDF escaneado)
$texto = "CURICÓ DAEM\nESPECIFICACIONES TECNICAS\n83965\n8 unidades\nLAPICES DE CERA JUMBO\n";

$parserBase = new ListadoMaterialesPdfParserService;
$lineasTexto = $parserBase->parseTexto($texto);
echo 'Texto parse: '.count($lineasTexto)."\n";

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

$parser = new ListadoMaterialesPdfParserService(null, $paddle);
$metodo = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'fusionarLineasConPaddle');
$metodo->setAccessible(true);

$tmp = tempnam(sys_get_temp_dir(), 'pdf');
file_put_contents($tmp, '%PDF-1.4');
$fusion = $metodo->invoke($parser, $tmp, $lineasTexto, $texto);
@unlink($tmp);

echo 'Fusion final: '.count($fusion)."\n";

try {
    Solicitud83965Golden::assertLineasMatchGolden(new PHPUnit\Framework\TestCase, $fusion);
    echo "OK golden match\n";
} catch (Throwable $e) {
    echo 'FAIL: '.$e->getMessage()."\n";
    exit(1);
}
