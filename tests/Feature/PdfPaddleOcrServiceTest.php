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
}
