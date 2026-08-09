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

    public function test_sanear_148_filas_exceso_poda_a_97(): void
    {
        $fixturePath = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'pdf_materiales'.DIRECTORY_SEPARATOR.'vps_ocr_real.txt';
        $texto = (string) file_get_contents($fixturePath);
        $texto = preg_replace('/ESPECIFICACIONES SOLICITUD DE PEDIDO/u', 'ESPECIFICACIONES TECNICAS', $texto, 1) ?? $texto;
        $texto .= str_repeat("\nPRODUCTO CANTIDAD IMAGEN REFERENCIA", 9);

        $parser = new ListadoMaterialesPdfParserService;
        $base = $parser->parseTexto($texto);
        $lineas = $base;

        foreach (array_slice($base, 0, 19) as $fila) {
            $partes = preg_split('/\s+/u', $fila['descripcion']) ?: [];
            if (count($partes) >= 3) {
                $lineas[] = [
                    'cantidad' => 1,
                    'descripcion' => implode(' ', array_slice($partes, -2)),
                ];
            }
        }

        $this->assertSame(148, count($lineas));

        $sanear = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'sanearFilasTablaSolicitud');
        $sanear->setAccessible(true);
        $out = $sanear->invoke($parser, $lineas, $texto, 11);

        $this->assertSame(97, count($out));
    }
}
