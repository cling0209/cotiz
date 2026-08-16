<?php

namespace Tests\Feature;

use App\Services\PdfPaddleOcrService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PdfPaddleOcrServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cotiz.paddleocr.enabled' => true,
            'cotiz.paddleocr.url' => 'http://paddleocr.test',
            'cotiz.paddleocr.timeout' => 60,
            'cotiz.paddleocr.per_page_min_pages' => 30,
            'cotiz.paddleocr.max_pages' => 15,
        ]);
    }

    public function test_esta_disponible_cuando_health_responde_ok(): void
    {
        Http::fake([
            'http://paddleocr.test/health' => Http::response(['status' => 'ok']),
        ]);

        $this->assertTrue((new PdfPaddleOcrService)->estaDisponible());
    }

    public function test_extraer_lineas_tabla_desde_respuesta_json(): void
    {
        Http::fake([
            'http://paddleocr.test/extract-tabla' => Http::response([
                'lineas' => [
                    ['cantidad' => 10, 'descripcion' => 'RESMA OFICIO 500 HOJAS'],
                    ['cantidad' => 5, 'descripcion' => 'GOMA EVA HOJA 50X70 CM'],
                ],
                'total' => 2,
            ]),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
        file_put_contents($tmp, '%PDF-1.4');

        try {
            $lineas = (new PdfPaddleOcrService)->extraerLineasTabla($tmp);
        } finally {
            @unlink($tmp);
        }

        $this->assertCount(2, $lineas);
        $this->assertSame(10, $lineas[0]['cantidad']);
        $this->assertStringContainsString('RESMA OFICIO', $lineas[0]['descripcion']);
    }

    public function test_extraer_lineas_tabla_multipagina_usa_pool_por_pagina(): void
    {
        config([
            'cotiz.paddleocr.per_page_min_pages' => 3,
            'cotiz.paddleocr.parallel_pages' => 2,
            'cotiz.paddleocr.max_pages' => 4,
        ]);

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/extract-tabla')) {
                $pagina = (int) ($request->data()['first_page'] ?? 1);

                return Http::response([
                    'lineas' => [
                        ['cantidad' => $pagina, 'descripcion' => "PRODUCTO PAGINA {$pagina}"],
                    ],
                    'total' => 1,
                ]);
            }

            return Http::response(['status' => 'ok']);
        });

        $tmp = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
        file_put_contents($tmp, '%PDF-1.4 fake');

        try {
            $lineas = (new PdfPaddleOcrService)->extraerLineasTabla($tmp, 'ESPECIFICACIONES TECNICAS2 .pdf');
        } finally {
            @unlink($tmp);
        }

        $this->assertCount(4, $lineas);
    }

    public function test_reintenta_paginas_faltantes_tras_fallo_paralelo(): void
    {
        config([
            'cotiz.paddleocr.per_page_min_pages' => 3,
            'cotiz.paddleocr.parallel_pages' => 4,
            'cotiz.paddleocr.max_pages' => 11,
        ]);

        $intentos = [];

        Http::fake(function ($request) use (&$intentos) {
            if (! str_ends_with($request->url(), '/extract-tabla')) {
                return Http::response(['status' => 'ok']);
            }

            $pagina = (int) ($request->data()['first_page'] ?? 1);
            $intentos[$pagina] = ($intentos[$pagina] ?? 0) + 1;

            // Primer intento paralelo: fallan páginas 3 y 4; reintento secuencial OK.
            if ($pagina >= 3 && $intentos[$pagina] === 1) {
                return Http::response(['detail' => 'busy'], 503);
            }

            return Http::response([
                'lineas' => [
                    ['cantidad' => $pagina, 'descripcion' => "PRODUCTO PAGINA {$pagina}"],
                ],
                'total' => 1,
            ]);
        });

        $tmp = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
        file_put_contents($tmp, '%PDF-1.4 fake');

        try {
            $lineas = (new PdfPaddleOcrService)->extraerLineasTabla($tmp, 'ESPECIFICACIONES TECNICAS2 .pdf');
        } finally {
            @unlink($tmp);
        }

        $this->assertCount(11, $lineas);
    }

    public function test_extraer_grilla_tabla_envia_columnas_y_usa_items(): void
    {
        config([
            'cotiz.paddleocr.max_pages' => 1,
            'cotiz.paddleocr.parallel_pages' => 1,
        ]);

        Http::fake([
            'http://paddleocr.test/extract-grilla' => Http::response([
                'paginas' => [
                    [
                        'filas' => [
                            ['6 Silla con respaldo'],
                            ['3 Mesa Modular'],
                        ],
                    ],
                ],
                'items' => [
                    ['cantidad' => 2, 'descripcion' => 'Mesón de préstamo'],
                    ['cantidad' => 2, 'descripcion' => 'Diario mural tipo vitrina'],
                    ['cantidad' => 4, 'descripcion' => 'Lector Inalámbrico'],
                    ['cantidad' => 1, 'descripcion' => 'Alfombra Rectangular'],
                    ['cantidad' => 6, 'descripcion' => 'Silla con respaldo'],
                    ['cantidad' => 3, 'descripcion' => 'Mesa Modular Masca para 3 personas.'],
                ],
                'total_filas' => 2,
            ]),
            'http://paddleocr.test/*' => Http::response(['status' => 'ok']),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'cotiz-pdf-');
        file_put_contents($tmp, '%PDF-1.4');

        try {
            $paginas = (new PdfPaddleOcrService)->extraerGrillaTabla(
                $tmp,
                'DETALLE 011 CRA.pdf',
                'CANTIDAD',
                'DESCRIPCIÓN',
            );
        } finally {
            @unlink($tmp);
        }

        $this->assertCount(1, $paginas);
        $this->assertCount(6, $paginas[0]['items'] ?? []);
        $this->assertSame('Lector Inalámbrico', $paginas[0]['items'][2]['descripcion']);

        $campos = [];
        foreach (Http::recorded() as $pair) {
            $request = $pair[0];
            if (! str_contains($request->url(), 'extract-grilla')) {
                continue;
            }
            foreach ($request->data() as $parte) {
                if (is_array($parte) && isset($parte['name'])) {
                    $campos[$parte['name']] = $parte['contents'] ?? null;
                }
            }
        }
        $this->assertSame('CANTIDAD', $campos['columna_cantidad'] ?? null);
        $this->assertSame('DESCRIPCIÓN', $campos['columna_producto'] ?? null);
    }
}
