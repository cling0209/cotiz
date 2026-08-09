<?php

namespace Tests\Feature;

use App\Services\ListadoMaterialesPdfParserService;
use App\Services\PdfPaddleOcrService;
use ReflectionMethod;
use Tests\Support\Solicitud83965Golden;
use Tests\TestCase;

class ListadoMaterialesPdfPaddleFusionTest extends TestCase
{
    public function test_fusion_paddle_primario_preserva_97_productos_golden(): void
    {
        $golden = Solicitud83965Golden::load();
        $lineasPaddle = array_map(
            static fn (array $fila): array => [
                'cantidad' => $fila['cantidad'],
                'descripcion' => $fila['descripcion'],
            ],
            $golden['lineas'],
        );

        $fixturePath = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'pdf_materiales'.DIRECTORY_SEPARATOR.'vps_ocr_real.txt';
        $texto = (string) file_get_contents($fixturePath);
        $texto = preg_replace('/ESPECIFICACIONES SOLICITUD DE PEDIDO/u', 'ESPECIFICACIONES TECNICAS', $texto, 1) ?? $texto;

        $parserBase = new ListadoMaterialesPdfParserService;
        $lineasTexto = $parserBase->parseTexto($texto);
        $this->assertGreaterThan(100, count($lineasTexto));

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

        Solicitud83965Golden::assertLineasMatchGolden($this, $fusionadas);
        $this->assertLessThan(count($lineasTexto), count($fusionadas));
    }

    public function test_sanear_paddle_no_altera_cantidades_pagina_1(): void
    {
        $golden = Solicitud83965Golden::load();
        $pagina1 = array_slice($golden['lineas'], 0, 9);
        $lineasPaddle = array_map(
            static fn (array $fila): array => [
                'cantidad' => $fila['cantidad'],
                'descripcion' => $fila['descripcion'],
            ],
            $pagina1,
        );

        $parser = new ListadoMaterialesPdfParserService;
        $sanear = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'sanearFilasTablaSolicitud');
        $sanear->setAccessible(true);

        $out = $sanear->invoke($parser, $lineasPaddle, '', 11, true);

        $this->assertCount(9, $out);
        for ($i = 0; $i < 9; $i++) {
            $this->assertSame($pagina1[$i]['cantidad'], $out[$i]['cantidad'], 'Fila '.($i + 1));
            $this->assertStringContainsStringIgnoringCase(
                $pagina1[$i]['needle'],
                $out[$i]['descripcion'],
            );
        }
    }

    public function test_pagina_1_orden_pdf_desde_fixture_nativo(): void
    {
        $golden = Solicitud83965Golden::load();
        $pagina1 = array_slice($golden['lineas'], 0, 9);

        $parser = new ListadoMaterialesPdfParserService;
        $lineas = $parser->parseTexto(
            (string) file_get_contents(dirname(__DIR__).'/Fixtures/pdf_materiales/solicitud_pedido_pagina1.txt'),
        );

        $this->assertCount(9, $lineas);

        for ($i = 0; $i < 9; $i++) {
            $this->assertSame($pagina1[$i]['cantidad'], $lineas[$i]['cantidad'], 'Fila '.($i + 1));
            $this->assertStringContainsStringIgnoringCase(
                $pagina1[$i]['needle'],
                $lineas[$i]['descripcion'],
            );
        }
    }
}
