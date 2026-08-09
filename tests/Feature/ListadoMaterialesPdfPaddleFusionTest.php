<?php

namespace Tests\Feature;

use App\Services\ListadoMaterialesPdfParserService;
use App\Services\PdfPaddleOcrService;
use ReflectionMethod;
use Tests\TestCase;

class ListadoMaterialesPdfPaddleFusionTest extends TestCase
{
    public function test_fusion_paddle_primario_usa_solo_celdas_sin_duplicar_texto(): void
    {
        $fixturePath = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'pdf_materiales'.DIRECTORY_SEPARATOR.'vps_ocr_real.txt';
        $texto = (string) file_get_contents($fixturePath);

        $parserBase = new ListadoMaterialesPdfParserService;
        $lineasTexto = $parserBase->parseTexto($texto);
        $this->assertGreaterThan(100, count($lineasTexto));

        $lineasPaddle = [];
        for ($i = 1; $i <= 97; $i++) {
            $lineasPaddle[] = [
                'cantidad' => ($i % 20) + 1,
                'descripcion' => "PRODUCTO CELDA {$i} UNICO",
            ];
        }

        $paddle = $this->createMock(PdfPaddleOcrService::class);
        $paddle->method('estaDisponible')->willReturn(true);
        $paddle->method('extraerLineasTabla')->willReturn($lineasPaddle);

        $parser = new ListadoMaterialesPdfParserService(null, $paddle);
        $metodo = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'fusionarLineasConPaddle');
        $metodo->setAccessible(true);

        $tmp = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
        file_put_contents($tmp, '%PDF-1.4');

        try {
            $fusionadas = $metodo->invoke($parser, $tmp, $lineasTexto, $texto);
        } finally {
            @unlink($tmp);
        }

        $this->assertSame(97, count($fusionadas));
        $this->assertLessThan(count($lineasTexto), count($fusionadas));
    }
}
