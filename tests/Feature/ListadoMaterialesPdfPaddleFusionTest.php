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

    public function test_sanear_paddle_142_fragmentos_poda_a_97_golden(): void
    {
        $golden = Solicitud83965Golden::load();
        $fragmentadas = $this->simularPaddle142DesdeGolden($golden['lineas']);
        $this->assertGreaterThanOrEqual(135, count($fragmentadas));
        $this->assertLessThanOrEqual(150, count($fragmentadas));

        $fixturePath = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'pdf_materiales'.DIRECTORY_SEPARATOR.'vps_ocr_real.txt';
        $texto = (string) file_get_contents($fixturePath);
        $texto = preg_replace('/ESPECIFICACIONES SOLICITUD DE PEDIDO/u', 'ESPECIFICACIONES TECNICAS', $texto, 1) ?? $texto;

        $parser = new ListadoMaterialesPdfParserService;
        $sanear = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'sanearFilasTablaSolicitud');
        $sanear->setAccessible(true);

        $out = $sanear->invoke($parser, $fragmentadas, $texto, 11, true);

        Solicitud83965Golden::assertLineasMatchGolden($this, $out);
    }

    public function test_fusion_paddle_142_fragmentos_poda_a_97_golden(): void
    {
        $golden = Solicitud83965Golden::load();
        $lineasPaddle = $this->simularPaddle142DesdeGolden($golden['lineas']);

        $fixturePath = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'pdf_materiales'.DIRECTORY_SEPARATOR.'vps_ocr_real.txt';
        $texto = (string) file_get_contents($fixturePath);
        $texto = preg_replace('/ESPECIFICACIONES SOLICITUD DE PEDIDO/u', 'ESPECIFICACIONES TECNICAS', $texto, 1) ?? $texto;

        $parserBase = new ListadoMaterialesPdfParserService;
        $lineasTexto = $parserBase->parseTexto($texto);

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
    }

    public function test_fusion_tecnicas_sin_cabecera_texto_paddle_142_poda_a_97(): void
    {
        $golden = Solicitud83965Golden::load();
        $lineasPaddle = $this->simularPaddle142Realista($golden['lineas']);
        $this->assertGreaterThanOrEqual(135, count($lineasPaddle));

        $texto = "CURICÓ DAEM\nESPECIFICACIONES TECNICAS\n83965\n8 unidades\nLAPICES DE CERA JUMBO\n";

        $parserBase = new ListadoMaterialesPdfParserService;
        $lineasTexto = $parserBase->parseTexto($texto);
        $this->assertLessThan(10, count($lineasTexto));

        $paddle = $this->createMock(PdfPaddleOcrService::class);
        $paddle->method('estaDisponible')->willReturn(true);
        $paddle->method('extraerLineasTabla')->willReturn($lineasPaddle);
        $paddle->method('extraerLineasTablaPorPagina')->willReturn($lineasPaddle);

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
    }

    public function test_detecta_tabla_escaneada_por_nombre_archivo(): void
    {
        $parser = new ListadoMaterialesPdfParserService;
        $metodo = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'esProbableTablaMaterialesEscaneada');
        $metodo->setAccessible(true);

        $this->assertTrue($metodo->invoke(
            $parser,
            'texto minimo',
            1,
            'C:/tmp/ESPECIFICACIONES TECNICAS .pdf',
        ));
    }

    public function test_detecta_tabla_escaneada_por_nombre_original_en_subida_web(): void
    {
        $parser = new ListadoMaterialesPdfParserService;
        $metodo = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'esProbableTablaMaterialesEscaneada');
        $metodo->setAccessible(true);

        $this->assertTrue($metodo->invoke(
            $parser,
            'texto minimo sin cabecera',
            1,
            '/tmp/phpAbCdEf',
            'ESPECIFICACIONES TECNICAS2 .pdf',
        ));
    }

    public function test_fusion_paddle_142_no_podable_elige_texto_sobre_paddle(): void
    {
        $golden = Solicitud83965Golden::load();
        $lineasPaddle = $golden['lineas'];
        for ($i = 0; $i < 45; $i++) {
            $lineasPaddle[] = [
                'cantidad' => 1,
                'descripcion' => 'ARTICULO UNICO SIN DUPLICADO NUMERO '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ];
        }
        $this->assertSame(142, count($lineasPaddle));

        $fixturePath = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'pdf_materiales'.DIRECTORY_SEPARATOR.'vps_ocr_real.txt';
        $texto = (string) file_get_contents($fixturePath);
        $texto = preg_replace('/ESPECIFICACIONES SOLICITUD DE PEDIDO/u', 'ESPECIFICACIONES TECNICAS', $texto, 1) ?? $texto;

        $parserBase = new ListadoMaterialesPdfParserService;
        $lineasTexto = $parserBase->parseTexto($texto);

        $paddle = $this->createMock(PdfPaddleOcrService::class);
        $paddle->method('estaDisponible')->willReturn(true);
        $paddle->method('extraerLineasTabla')->willReturn($lineasPaddle);
        $paddle->method('extraerLineasTablaPorPagina')->willReturn($lineasPaddle);

        $parser = new ListadoMaterialesPdfParserService(null, $paddle);
        $metodo = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'fusionarLineasConPaddle');
        $metodo->setAccessible(true);

        $tmp = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
        file_put_contents($tmp, '%PDF-1.4');

        try {
            $fusionadas = $metodo->invoke(
                $parser,
                $tmp,
                $lineasTexto,
                $texto,
                'ESPECIFICACIONES TECNICAS2 .pdf',
            );
        } finally {
            @unlink($tmp);
        }

        $this->assertGreaterThanOrEqual(97, count($fusionadas));
        $this->assertLessThan(count($lineasPaddle), count($fusionadas));
    }

    public function test_fusion_subida_web_nombre_original_activa_poda_paddle_97(): void
    {
        $golden = Solicitud83965Golden::load();
        $lineasPaddle = $this->simularPaddle142Realista($golden['lineas']);

        $texto = "83965\n8 unidades\nLAPICES DE CERA JUMBO\n";

        $parserBase = new ListadoMaterialesPdfParserService;
        $lineasTexto = $parserBase->parseTexto($texto);

        $paddle = $this->createMock(PdfPaddleOcrService::class);
        $paddle->method('estaDisponible')->willReturn(true);
        $paddle->method('extraerLineasTabla')->willReturn($lineasPaddle);
        $paddle->method('extraerLineasTablaPorPagina')->willReturn($lineasPaddle);

        $parser = new ListadoMaterialesPdfParserService(null, $paddle);
        $metodo = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'fusionarLineasConPaddle');
        $metodo->setAccessible(true);

        $tmp = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
        file_put_contents($tmp, '%PDF-1.4');

        try {
            $fusionadas = $metodo->invoke(
                $parser,
                $tmp,
                $lineasTexto,
                $texto,
                'ESPECIFICACIONES TECNICAS2 .pdf',
            );
        } finally {
            @unlink($tmp);
        }

        Solicitud83965Golden::assertLineasMatchGolden($this, $fusionadas);
    }

    public function test_golden_hoja_2_tiene_diez_productos_como_pdf(): void
    {
        $golden = Solicitud83965Golden::load();
        $paginas = json_decode(
            file_get_contents(__DIR__.'/../Fixtures/pdf_materiales/solicitud_83965_paginas.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $filasPorHoja = $paginas['filas_por_hoja'];
        $this->assertSame(10, $filasPorHoja[1]);

        $offset = array_sum(array_slice($filasPorHoja, 0, 1));
        $hoja2 = array_slice($golden['lineas'], $offset, $filasPorHoja[1]);

        $this->assertCount(10, $hoja2);
        $this->assertSame(2, $hoja2[0]['cantidad']);
        $this->assertStringContainsString('CUADERNO CUARTA', $hoja2[0]['descripcion']);
        $this->assertSame(10, $hoja2[9]['cantidad']);
        $this->assertStringContainsString('MARCADORES JUMBO', $hoja2[9]['descripcion']);
    }

    public function test_golden_hoja_3_tiene_doce_productos_como_pdf(): void
    {
        $golden = Solicitud83965Golden::load();
        $paginas = json_decode(
            file_get_contents(__DIR__.'/../Fixtures/pdf_materiales/solicitud_83965_paginas.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $filasPorHoja = $paginas['filas_por_hoja'];
        $this->assertSame(12, $filasPorHoja[2]);

        $offset = array_sum(array_slice($filasPorHoja, 0, 2));
        $hoja3 = array_slice($golden['lineas'], $offset, $filasPorHoja[2]);

        $this->assertCount(12, $hoja3);
        $this->assertStringContainsString('MARCADORES 20 COLORES', $hoja3[0]['descripcion']);
        $this->assertSame(10, $hoja3[0]['cantidad']);
        $this->assertStringContainsString('MARCADORES NEGRO', $hoja3[9]['descripcion']);
        $this->assertSame(1, $hoja3[9]['cantidad']);
        $this->assertStringContainsString('FINELINER', $hoja3[10]['descripcion']);
        $this->assertStringContainsString('ACUARELA SET', $hoja3[11]['descripcion']);
        $this->assertSame(20, $hoja3[11]['cantidad']);
    }

    public function test_golden_hoja_4_tiene_diez_productos_como_pdf(): void
    {
        $golden = Solicitud83965Golden::load();
        $paginas = json_decode(
            file_get_contents(__DIR__.'/../Fixtures/pdf_materiales/solicitud_83965_paginas.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $filasPorHoja = $paginas['filas_por_hoja'];
        $this->assertSame(10, $filasPorHoja[3]);

        $offset = array_sum(array_slice($filasPorHoja, 0, 3));
        $hoja4 = array_slice($golden['lineas'], $offset, $filasPorHoja[3]);

        $this->assertCount(10, $hoja4);
        $this->assertStringContainsString('PLUMON PIZARRA NEGRO', $hoja4[0]['descripcion']);
        $this->assertSame(1, $hoja4[0]['cantidad']);
        $this->assertStringContainsString('TÉMPERA 250ML', $hoja4[4]['descripcion']);
        $this->assertSame(20, $hoja4[4]['cantidad']);
        $this->assertStringContainsString('LAPIZ GRAFITO', $hoja4[6]['descripcion']);
        $this->assertSame(2, $hoja4[6]['cantidad']);
        $this->assertStringContainsString('MEZCLADOR GRANDES', $hoja4[8]['descripcion']);
        $this->assertSame(30, $hoja4[8]['cantidad']);
        $this->assertStringContainsString('PAPEL VOLANTIN', $hoja4[9]['descripcion']);
        $this->assertSame(20, $hoja4[9]['cantidad']);
    }

    public function test_golden_todas_las_hojas_cuadran_con_pdf(): void
    {
        $golden = Solicitud83965Golden::load();
        $paginas = json_decode(
            file_get_contents(__DIR__.'/../Fixtures/pdf_materiales/solicitud_83965_paginas.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $filasPorHoja = $paginas['filas_por_hoja'];
        $this->assertSame(97, array_sum($filasPorHoja));
        $this->assertCount(11, $filasPorHoja);

        $offset = 0;
        foreach ($filasPorHoja as $indice => $cantidad) {
            $hoja = $indice + 1;
            $slice = array_slice($golden['lineas'], $offset, $cantidad);
            $this->assertCount($cantidad, $slice, "Hoja {$hoja} debe tener {$cantidad} productos");

            $primera = $paginas['primera_fila_por_hoja'][$indice] ?? '';
            $ultima = $paginas['ultima_fila_por_hoja'][$indice] ?? '';
            $this->assertStringContainsString(
                mb_strtoupper($primera),
                mb_strtoupper($slice[0]['descripcion']),
                "Primera fila hoja {$hoja}",
            );
            $this->assertStringContainsString(
                mb_strtoupper($ultima),
                mb_strtoupper($slice[$cantidad - 1]['descripcion']),
                "Última fila hoja {$hoja}",
            );

            foreach ($slice as $fila) {
                $this->assertSame($hoja, $fila['pagina'] ?? null, 'Campo pagina en golden');
            }

            $offset += $cantidad;
        }

        $this->assertSame(97, $offset);
    }

    public function test_paddle_celda_cantidad_desde_columna_no_texto_producto(): void
    {
        $lineasPaddle = [
            ['cantidad' => 2, 'descripcion' => 'CUADERNO CUARTA 150 HOJAS 7MM PACK 6 UNIDADES', 'pagina' => 2],
            ['cantidad' => 10, 'descripcion' => 'MARCADORES JUMBO 12 COLORES', 'pagina' => 2],
        ];

        $paddle = $this->createMock(PdfPaddleOcrService::class);
        $paddle->method('estaDisponible')->willReturn(true);
        $paddle->method('extraerLineasTabla')->willReturn($lineasPaddle);

        $parser = new ListadoMaterialesPdfParserService(null, $paddle);
        $metodo = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'fusionarLineasConPaddle');
        $metodo->setAccessible(true);

        $texto = "ESPECIFICACIONES TECNICAS\nPRODUCTO CANTIDAD IMAGEN REFERENCIA\n";
        $tmp = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
        file_put_contents($tmp, '%PDF-1.4');

        try {
            $fusionadas = $metodo->invoke($parser, $tmp, [], $texto);
        } finally {
            @unlink($tmp);
        }

        $this->assertCount(2, $fusionadas);
        $this->assertSame(2, $fusionadas[0]['cantidad']);
        $this->assertSame(10, $fusionadas[1]['cantidad']);
        $this->assertArrayHasKey('pagina', $fusionadas[0]);
        $this->assertSame(2, $fusionadas[0]['pagina']);
    }

    /**
     * @param  array<int, array{needle: string, cantidad: int, descripcion: string}>  $goldenLineas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function simularPaddle142Realista(array $goldenLineas): array
    {
        $out = [];
        $porPagina = 0;

        foreach ($goldenLineas as $fila) {
            if ($porPagina === 0) {
                $out[] = ['cantidad' => 1, 'descripcion' => 'PRODUCTO'];
                $out[] = ['cantidad' => 1, 'descripcion' => 'CANTIDAD IMAGEN REFERENCIA'];
            }

            $out[] = [
                'cantidad' => $fila['cantidad'],
                'descripcion' => $fila['descripcion'],
            ];

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

    /**
     * @param  array<int, array{needle: string, cantidad: int, descripcion: string}>  $goldenLineas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function simularPaddle142DesdeGolden(array $goldenLineas): array
    {
        $out = [];

        foreach ($goldenLineas as $i => $fila) {
            $out[] = [
                'cantidad' => $fila['cantidad'],
                'descripcion' => $fila['descripcion'],
            ];

            if ($i % 2 !== 0) {
                continue;
            }

            $words = preg_split('/\s+/u', trim($fila['descripcion'])) ?: [];
            if (count($words) < 3) {
                continue;
            }

            $out[] = [
                'cantidad' => $fila['cantidad'],
                'descripcion' => implode(' ', array_slice($words, 0, 2)),
            ];
        }

        return $out;
    }

    public function test_pdf_escaneado_sin_texto_nativo_no_falla_si_paddle_disponible(): void
    {
        $golden = Solicitud83965Golden::load();
        $lineasPaddle = array_map(
            static fn (array $fila): array => [
                'cantidad' => $fila['cantidad'],
                'descripcion' => $fila['descripcion'],
            ],
            $golden['lineas'],
        );

        $paddle = $this->createMock(PdfPaddleOcrService::class);
        $paddle->method('estaDisponible')->willReturn(true);
        $paddle->method('extraerLineasTabla')->willReturn($lineasPaddle);

        $parser = new ListadoMaterialesPdfParserService(null, $paddle);

        $tmp = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
        file_put_contents($tmp, '%PDF-1.4 escaneado sin texto');

        try {
            $uploaded = new \Illuminate\Http\UploadedFile(
                $tmp,
                'ESPECIFICACIONES TECNICAS2 .pdf',
                'application/pdf',
                null,
                true,
            );
            $documento = $parser->parseDocumentoCompleto($uploaded);
        } finally {
            @unlink($tmp);
        }

        $this->assertNotEmpty($documento['lineas']);
        Solicitud83965Golden::assertLineasMatchGolden($this, $documento['lineas']);
    }

    public function test_paddle_39_incompleto_usa_ocr_por_pagina(): void
    {
        $golden = Solicitud83965Golden::load();
        $lineasPaddle39 = array_map(
            static fn (array $fila): array => [
                'cantidad' => $fila['cantidad'],
                'descripcion' => $fila['descripcion'],
            ],
            array_slice($golden['lineas'], 0, 39),
        );

        $vpsPath = dirname(__DIR__).'/Fixtures/pdf_materiales/vps_ocr_real.txt';
        $vpsTexto = (string) file_get_contents($vpsPath);
        $vpsTexto = preg_replace('/ESPECIFICACIONES SOLICITUD DE PEDIDO/u', 'ESPECIFICACIONES TECNICAS', $vpsTexto, 1) ?? $vpsTexto;

        $paddle = $this->createMock(PdfPaddleOcrService::class);
        $paddle->method('estaDisponible')->willReturn(true);
        $paddle->method('extraerLineasTabla')->willReturn($lineasPaddle39);

        $ocr = $this->createMock(\App\Services\PdfOcrService::class);
        $ocr->method('estaDisponible')->willReturn(true);
        $ocr->method('extraerTextoPagina')->willReturn($vpsTexto);

        $parser = new ListadoMaterialesPdfParserService($ocr, $paddle);
        $hint = "ESPECIFICACIONES TECNICAS\nPRODUCTO CANTIDAD\nPÁGINA 1 DE 11\n";

        $metodo = new ReflectionMethod(ListadoMaterialesPdfParserService::class, 'fusionarLineasConPaddle');
        $metodo->setAccessible(true);

        $tmp = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
        file_put_contents($tmp, '%PDF-1.4');

        try {
            $fusionadas = $metodo->invoke($parser, $tmp, [], $hint, 'ESPECIFICACIONES TECNICAS2 .pdf');
        } finally {
            @unlink($tmp);
        }

        $this->assertGreaterThanOrEqual(90, count($fusionadas));
        $this->assertGreaterThan(39, count($fusionadas));
    }
}
