<?php

/**
 * Simula VPS: Paddle ~39 filas + sin OCR previo (solo hint) → OCR por página debe completar.
 * Uso: php scripts/test_paddle_39_escenario_vps.php
 */

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ListadoMaterialesPdfParserService;
use App\Services\PdfOcrService;
use App\Services\PdfPaddleOcrService;
use Tests\Support\Solicitud83965Golden;

$golden = Solicitud83965Golden::load();
$lineasPaddle39 = array_map(
    static fn (array $fila): array => [
        'cantidad' => $fila['cantidad'],
        'descripcion' => $fila['descripcion'],
    ],
    array_slice($golden['lineas'], 0, 39),
);

$vpsPath = dirname(__DIR__).'/tests/Fixtures/pdf_materiales/vps_ocr_real.txt';
$vpsTexto = (string) file_get_contents($vpsPath);
$vpsTexto = preg_replace('/ESPECIFICACIONES SOLICITUD DE PEDIDO/u', 'ESPECIFICACIONES TECNICAS', $vpsTexto, 1) ?? $vpsTexto;

$paddle = new class ($lineasPaddle39) extends PdfPaddleOcrService {
    public function __construct(private array $lineas) {}

    public function estaDisponible(): bool
    {
        return true;
    }

    public function extraerLineasTabla(string $path, string $nombreArchivo = ''): array
    {
        return $this->lineas;
    }
};

$ocr = new class ($vpsTexto) extends PdfOcrService {
    public function __construct(private string $textoCompleto) {}

    public function estaDisponible(): bool
    {
        return true;
    }

    public function extraerTexto(string $pdfPath, array $opciones = []): string
    {
        return $this->textoCompleto;
    }

    public function extraerTextoPagina(string $pdfPath, int $pagina, array $opciones = []): string
    {
        return $this->textoCompleto;
    }
};

$parser = new ListadoMaterialesPdfParserService($ocr, $paddle);
$hint = "ESPECIFICACIONES TECNICAS\nPRODUCTO CANTIDAD\nPÁGINA 1 DE 11\n";
$lineasTexto = $parser->parseTexto($hint);

$uploadedPath = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
file_put_contents($uploadedPath, '%PDF-1.4');

$metodo = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'fusionarLineasConPaddle');
$metodo->setAccessible(true);

echo "=== ESCENARIO VPS: Paddle 39 + hint (sin OCR previo) ===\n";
echo 'Paddle simulado: '.count($lineasPaddle39)." filas\n";
echo 'Texto hint parseado: '.count($lineasTexto)." filas\n";

$fusion = $metodo->invoke(
    $parser,
    $uploadedPath,
    $lineasTexto,
    $hint,
    'ESPECIFICACIONES TECNICAS2 .pdf',
);
@unlink($uploadedPath);

echo 'Import final: '.count($fusion)." filas\n\n";

if (count($fusion) < 90) {
    echo "FALLO: se esperaban al menos 90 filas (objetivo 97)\n";
    exit(1);
}

$checks = 0;
foreach (array_slice($golden['lineas'], 0, 10) as $esperada) {
    foreach ($fusion as $linea) {
        if (str_contains(mb_strtoupper($linea['descripcion']), mb_strtoupper($esperada['needle']))) {
            $checks++;
            break;
        }
    }
}

if ($checks < 8) {
    echo "FALLO: faltan productos clave del golden en el resultado\n";
    exit(1);
}

echo "OK: complemento OCR activo ({$checks}/10 productos clave presentes)\n";
exit(0);
