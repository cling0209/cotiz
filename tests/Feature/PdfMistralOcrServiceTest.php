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
                && ($data['model'] ?? null) === 'mistral-ocr-latest';
        });
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
}
