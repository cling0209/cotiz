<?php

namespace Tests\Feature;

use App\Services\PdfMistralOcrService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PdfMistralOcrServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cotiz.mistral_ocr.enabled' => true,
            'cotiz.mistral_ocr.api_key' => 'test-key',
            'cotiz.mistral_ocr.model' => 'mistral-ocr-latest',
            'cotiz.mistral_ocr.endpoint' => 'https://api.mistral.ai/v1/ocr',
            'cotiz.mistral_ocr.timeout' => 30,
            'cotiz.mistral_ocr.annotation_enabled' => true,
        ]);
    }

    public function test_esta_disponible_con_api_key(): void
    {
        $this->assertTrue((new PdfMistralOcrService)->estaDisponible());
    }

    public function test_mapea_tabla_html_por_nombres_de_columna(): void
    {
        $html = '<table><tr><th>CANTIDAD</th><th>DESCRIPCION</th></tr>'
            .'<tr><td>2</td><td>Meson de prestamo cubierta simple</td></tr>'
            .'<tr><td>4</td><td>Lector Inalambrico</td></tr>'
            .'</table>';

        $paginas = (new PdfMistralOcrService)->paginasDesdeRespuesta([
            'pages' => [[
                'index' => 0,
                'tables' => [['id' => 'tbl-0.html', 'content' => $html, 'format' => 'html']],
            ]],
        ], 'CANTIDAD', 'DESCRIPCION');

        $this->assertCount(1, $paginas);
        $this->assertCount(2, $paginas[0]['items']);
        $this->assertSame(2, $paginas[0]['items'][0]['cantidad']);
        $this->assertStringContainsString('Meson de prestamo', $paginas[0]['items'][0]['descripcion']);
        $this->assertSame(4, $paginas[0]['items'][1]['cantidad']);
        $this->assertSame('Lector Inalambrico', $paginas[0]['items'][1]['descripcion']);
    }

    public function test_elige_tabla_de_requerimiento_y_omite_antecedentes(): void
    {
        $productos = '<table><tr><th>LINEA</th><th>DESCRIPCION REQUERIMIENTO</th><th>UNIDADES* POR AÑO</th></tr>'
            .'<tr><td>1</td><td>ACRÍLICO BLANCO 600 ML</td><td>1</td></tr>'
            .'<tr><td>2</td><td>ACRÍLICO AMARILLO</td><td>3</td></tr>'
            .'</table>';
        $sep = '<table><tr><td colspan="2"><b>2. ANTECEDENTES GENERALES</b></td></tr>'
            .'<tr><td>DESCRIPCIÓN DE LA ACCIÓN</td><td>Fortalecimiento de la biblioteca CRA</td></tr>'
            .'</table>';

        $paginas = (new PdfMistralOcrService)->paginasDesdeRespuesta([
            'pages' => [[
                'index' => 33,
                'tables' => [
                    ['content' => $productos],
                    ['content' => $sep],
                ],
            ]],
        ], 'UNIDADES* POR AÑO', 'descripcion del requerimiento');

        $this->assertCount(1, $paginas);
        $this->assertCount(2, $paginas[0]['items']);
        $this->assertSame(1, $paginas[0]['items'][0]['cantidad']);
        $this->assertSame('ACRÍLICO BLANCO 600 ML', $paginas[0]['items'][0]['descripcion']);
        $this->assertSame(3, $paginas[0]['items'][1]['cantidad']);
        foreach ($paginas[0]['items'] as $item) {
            $this->assertStringNotContainsString('Fortalecimiento', $item['descripcion']);
        }
    }

    public function test_extraer_grilla_usa_http_ocr(): void
    {
        Http::fake([
            'https://api.mistral.ai/v1/ocr' => Http::response([
                'pages' => [[
                    'index' => 0,
                    'tables' => [[
                        'content' => '<table><tr><th>CANTIDAD</th><th>PRODUCTO</th></tr><tr><td>6</td><td>Silla tapiz</td></tr></table>',
                    ]],
                ]],
            ]),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'cotiz-mistral-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, '%PDF-1.4 fake');

        $paginas = (new PdfMistralOcrService)->extraerGrillaTabla($tmp, 'cra.pdf', 'CANTIDAD', 'PRODUCTO');
        @unlink($tmp);

        $this->assertSame(6, $paginas[0]['items'][0]['cantidad']);
        $this->assertSame('Silla tapiz', $paginas[0]['items'][0]['descripcion']);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return ($data['table_format'] ?? null) === 'html'
                && ($data['model'] ?? null) === 'mistral-ocr-latest'
                && isset($data['document_annotation_format'])
                && is_string($data['document_annotation_prompt'] ?? null)
                && str_contains((string) $data['document_annotation_prompt'], 'CANTIDAD')
                && str_contains((string) $data['document_annotation_prompt'], 'PRODUCTO');
        });
    }

    public function test_prioriza_document_annotation_sobre_tablas_html(): void
    {
        $html = '<table><tr><th>CANTIDAD</th><th>DESCRIPCION</th></tr>'
            .'<tr><td>2</td><td>Solo HTML</td></tr></table>';

        $paginas = (new PdfMistralOcrService)->paginasDesdeRespuesta([
            'document_annotation' => json_encode([
                'items' => [
                    ['cantidad' => 1, 'descripcion' => 'MINAS 0.3MM HB PILOT'],
                    ['cantidad' => 3, 'descripcion' => 'CINTA DOBLE CONTACTO'],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'pages' => [[
                'index' => 0,
                'tables' => [['content' => $html]],
            ]],
        ], 'CANTIDAD', 'Descripción');

        $this->assertCount(1, $paginas);
        $this->assertCount(2, $paginas[0]['items']);
        $this->assertSame(1, $paginas[0]['items'][0]['cantidad']);
        $this->assertSame('MINAS 0.3MM HB PILOT', $paginas[0]['items'][0]['descripcion']);
        $this->assertSame(3, $paginas[0]['items'][1]['cantidad']);
    }

    public function test_prompt_anotacion_incluye_columnas_usuario(): void
    {
        $prompt = (new PdfMistralOcrService)->promptAnotacionMateriales('N° ítem', 'Descripción');
        $this->assertStringContainsString('N° ítem', $prompt);
        $this->assertStringContainsString('Descripción', $prompt);
        $this->assertStringContainsString('acentos', $prompt);
        $this->assertStringContainsString('lado a lado', $prompt);
    }

    public function test_mapea_encabezado_descripcion_con_acento(): void
    {
        $html = '<table><tr><th>CANTIDAD</th><th>DESCRIPCIÓN</th></tr>'
            .'<tr><td>2</td><td>Diario mural tipo vitrina</td></tr>'
            .'<tr><td>6</td><td>Silla con respaldo</td></tr>'
            .'</table>';

        $paginas = (new PdfMistralOcrService)->paginasDesdeRespuesta([
            'pages' => [[
                'index' => 0,
                'tables' => [['content' => $html]],
            ]],
        ], 'CANTIDAD', 'DESCRIPCIÓN');

        $this->assertCount(2, $paginas[0]['items']);
        $this->assertSame(6, $paginas[0]['items'][1]['cantidad']);
    }

    public function test_continua_tabla_sin_encabezado_en_paginas_siguientes(): void
    {
        $paginaConHeader = '<table><tr><th>LINEA</th><th>DESCRIPCION REQUERIMIENTO</th><th>UNIDADES* POR ANO</th><th>Monto</th></tr>'
            .'<tr><td>1</td><td>ACRILICO BLANCO</td><td>1</td><td>10</td></tr>'
            .'<tr><td>2</td><td>ACRILICO AMARILLO</td><td>3</td><td>20</td></tr>'
            .'</table>';
        $paginaSinHeader = '<table><tr><td>10</td><td>ACRILICO 250ML AZUL</td><td>23</td><td>119</td></tr>'
            .'<tr><td>11</td><td>ACRILICO 250ML NEGRO</td><td>23</td><td>119</td></tr>'
            .'</table>';
        $otraTabla = '<table><tr><td>DIMENSION</td><td>Gestion de Recursos</td></tr>'
            .'<tr><td>ACCION</td><td>Mejoramiento biblioteca</td></tr>'
            .'</table>';

        $paginas = (new PdfMistralOcrService)->paginasDesdeRespuesta([
            'pages' => [
                ['index' => 33, 'tables' => [['content' => $paginaConHeader]]],
                ['index' => 34, 'tables' => [['content' => $paginaSinHeader]]],
                ['index' => 35, 'tables' => [['content' => $otraTabla]]],
            ],
        ], 'UNIDADES* POR ANO', 'DESCRIPCION REQUERIMIENTO');

        $items = [];
        foreach ($paginas as $pagina) {
            foreach ($pagina['items'] as $item) {
                $items[] = $item;
            }
        }

        $this->assertCount(4, $items);
        $this->assertSame(1, $items[0]['cantidad']);
        $this->assertSame('ACRILICO BLANCO', $items[0]['descripcion']);
        $this->assertSame(23, $items[2]['cantidad']);
        $this->assertSame('ACRILICO 250ML AZUL', $items[2]['descripcion']);
        foreach ($items as $item) {
            $this->assertStringNotContainsString('Mejoramiento', $item['descripcion']);
            $this->assertStringNotContainsString('Gestion', $item['descripcion']);
        }
    }

    public function test_omite_fila_pie_subtotal_iva_rhein(): void
    {
        $html = '<table><tr><th>ITEM</th><th>DESCRIPCION</th><th>CANTIDAD</th></tr>'
            .'<tr><td>4</td><td>LAPIZ PASTA AZUL</td><td>300</td></tr>'
            .'<tr><td>5</td><td>LAPIZ PASTA NEGRO</td><td>100</td></tr>'
            .'<tr><td></td><td>RHEIN SUBTOTAL NETO IVA 19% TOTAL</td><td>30</td></tr>'
            .'</table>';

        $paginas = (new PdfMistralOcrService)->paginasDesdeRespuesta([
            'pages' => [[
                'index' => 0,
                'tables' => [['content' => $html]],
            ]],
        ], 'CANTIDAD', 'DESCRIPCION');

        $this->assertCount(2, $paginas[0]['items']);
        $this->assertSame(300, $paginas[0]['items'][0]['cantidad']);
        foreach ($paginas[0]['items'] as $item) {
            $this->assertStringNotContainsString('SUBTOTAL', $item['descripcion']);
            $this->assertStringNotContainsString('RHEIN', $item['descripcion']);
        }
    }

    public function test_omite_tabla_lugar_de_entrega_tras_productos(): void
    {
        $productos = '<table><tr><th>ITEM</th><th>CANTIDAD</th><th>DESCRIPCION</th></tr>'
            .'<tr><td>1</td><td>30 unidades</td><td>STEP: Color Negro</td></tr>'
            .'<tr><td>2</td><td>2 unidades</td><td>BANDA ELASTICA 45 M</td></tr>'
            .'</table>';
        $entrega = '<table><tr><td>CESFAM Manuel Bustos Huerta</td>'
            .'<td>LUNES A VIERNES, DE 08:00 A 13:00 HORAS, Y DE 14:00 A 16:48</td>'
            .'<td>Las personas a coordinar recepcion de productos seran de subdireccion</td></tr></table>';

        $paginas = (new PdfMistralOcrService)->paginasDesdeRespuesta([
            'pages' => [[
                'index' => 0,
                'tables' => [
                    ['content' => $productos],
                    ['content' => $entrega],
                ],
            ]],
        ], 'CANTIDAD', 'DESCRIPCION');

        $this->assertCount(2, $paginas[0]['items']);
        $this->assertSame(30, $paginas[0]['items'][0]['cantidad']);
        $this->assertSame(2, $paginas[0]['items'][1]['cantidad']);
        foreach ($paginas[0]['items'] as $item) {
            $this->assertStringNotContainsString('CESFAM', $item['descripcion']);
            $this->assertStringNotContainsString('coordinar', mb_strtolower($item['descripcion']));
            $this->assertStringNotContainsString('LUNES', $item['descripcion']);
        }
    }

    public function test_parse_cantidad_no_usa_hora(): void
    {
        $svc = new PdfMistralOcrService;
        $this->assertNull($svc->parseCantidad('LUNES A VIERNES, DE 08:00 A 13:00 HORAS'));
        $this->assertSame(2, $svc->parseCantidad('2 unidades'));
        $this->assertSame(30, $svc->parseCantidad('30'));
    }

    public function test_oferta_enami_dual_column_cantidad_uno_y_ambas_tablas(): void
    {
        $html = '<table><tr>'
            .'<th>N° ítem</th><th>Descripción</th><th>Unidad</th><th>Precio Neto Unitario ($)</th>'
            .'<th>N° ítem</th><th>Descripción</th><th>Unidad</th><th>Precio Neto Unitario ($)</th>'
            .'</tr>'
            .'<tr><td>1</td><td>ADH. EN BARRA 10GR PRITT</td><td>UNI</td><td>-</td>'
            .'<td>391</td><td>LAPIZ PASTA AZUL PILOT</td><td>UNI</td><td>-</td></tr>'
            .'<tr><td>2</td><td>ADH. TUBO 2GR INSTANTANEO</td><td>UNI</td><td>-</td>'
            .'<td>392</td><td>LAPIZ PASTA NEGRO PILOT</td><td>UNI</td><td>-</td></tr>'
            .'</table>';

        $paginas = (new PdfMistralOcrService)->paginasDesdeRespuesta([
            'pages' => [[
                'index' => 15,
                'tables' => [['content' => $html]],
            ]],
        ], 'N° ítem', 'Descripción');

        $items = $paginas[0]['items'];
        $this->assertCount(4, $items);
        foreach ($items as $item) {
            $this->assertSame(1, $item['cantidad']);
        }
        $this->assertSame('ADH. EN BARRA 10GR PRITT', $items[0]['descripcion']);
        $this->assertSame('LAPIZ PASTA AZUL PILOT', $items[1]['descripcion']);
        $this->assertSame('ADH. TUBO 2GR INSTANTANEO', $items[2]['descripcion']);
        $this->assertSame('LAPIZ PASTA NEGRO PILOT', $items[3]['descripcion']);
    }

    public function test_oferta_enami_dos_tablas_html_separadas_mismo_header(): void
    {
        $izq = '<table><tr><th>N° Item</th><th>Descripción</th><th>Unidad</th><th>Precio Neto Unitario ($)</th></tr>'
            .'<tr><td>12</td><td>ARCHIVADOR CARTON</td><td>UNI</td><td>-</td></tr>'
            .'<tr><td>13</td><td>BANDEJA ESCRITORIO</td><td>UNI</td><td>-</td></tr></table>';
        $der = '<table><tr><th>N° Item</th><th>Descripción</th><th>Unidad</th><th>Precio Neto Unitario ($)</th></tr>'
            .'<tr><td>403</td><td>LIBRETA UNIVERSITARIA</td><td>UNI</td><td>-</td></tr>'
            .'<tr><td>404</td><td>LOMO ARCHIVADOR</td><td>UNI</td><td>-</td></tr></table>';

        $paginas = (new PdfMistralOcrService)->paginasDesdeRespuesta([
            'pages' => [[
                'index' => 16,
                'tables' => [
                    ['content' => $izq],
                    ['content' => $der],
                ],
            ]],
        ], 'N Item', 'Descripción');

        $items = $paginas[0]['items'];
        $this->assertCount(4, $items);
        foreach ($items as $item) {
            $this->assertSame(1, $item['cantidad'], $item['descripcion']);
        }
        $descs = array_column($items, 'descripcion');
        $this->assertContains('ARCHIVADOR CARTON', $descs);
        $this->assertContains('LIBRETA UNIVERSITARIA', $descs);
    }
}
