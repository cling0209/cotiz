<?php

namespace Tests\Unit;

use App\Services\NotaConsultaRemotaService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotaConsultaRemotaServiceTest extends TestCase
{
    private NotaConsultaRemotaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotaConsultaRemotaService;
    }

    public function test_respuesta_error_400_sin_cotizacion_permite_continuar(): void
    {
        config([
            'app.url' => 'https://cotiza.romulo.cl',
            'cotiz.api_nota.consulta_nro_cotizacion' => 'https://cotiza.reicol.cl/api/v1/nota-consulta',
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
        ]);

        Http::fake([
            'cotiza.reicol.cl/*' => Http::response([
                'resultado' => 'ERROR',
                'mensaje' => 'La cotización no existe en notas.',
            ], 400),
        ]);

        $error = $this->service->errorSiEncargadoExisteEnPar('OC-NUEVA-001', 'Duplicada');

        $this->assertSame('', $error);
    }

    public function test_respuesta_ok_indica_duplicado_en_par(): void
    {
        config([
            'app.url' => 'https://cotiza.romulo.cl',
            'cotiz.api_nota.consulta_nro_cotizacion' => 'https://cotiza.reicol.cl/api/v1/nota-consulta',
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
        ]);

        Http::fake([
            'cotiza.reicol.cl/*' => Http::response([
                'resultado' => 'OK',
                'mensaje' => 'La cotización «OC-EXISTE» ya existe (nota #42).',
                'nronota' => 42,
                'encargado' => 'OC-EXISTE',
            ], 200),
        ]);

        $consulta = $this->service->consultarEncargadoEnPar('OC-EXISTE');

        $this->assertTrue($consulta['existe']);
        $this->assertSame(42, $consulta['nronota']);
        $this->assertSame('OK', $consulta['resultado']);

        $error = $this->service->errorSiEncargadoExisteEnPar('OC-EXISTE', 'Ya existe en par');

        $this->assertSame('La cotización «OC-EXISTE» ya existe (nota #42).', $error);
    }

    public function test_url_vacia_no_consulta(): void
    {
        config([
            'app.url' => 'http://localhost',
            'cotiz.sistema' => 'Cotiz',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
        ]);

        Http::fake();

        $error = $this->service->errorSiEncargadoExisteEnPar('OC-001', 'Duplicada');

        $this->assertSame('', $error);
        Http::assertNothingSent();
    }

    public function test_reicol_sin_credenciales_no_permite_continuar(): void
    {
        config([
            'app.url' => 'https://cotiza.reicol.cl',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
            'cotiz.api_nota.user' => '',
            'cotiz.api_nota.password' => '',
        ]);

        Http::fake();

        $error = $this->service->errorSiEncargadoExisteEnPar('2686-279-COT26', 'Duplicada');

        $this->assertStringContainsString('COTIZ_API_NOTA_USER', $error);
        Http::assertNothingSent();
    }

    public function test_reicol_auto_url_consulta_romulo(): void
    {
        config([
            'app.url' => 'https://cotiza.reicol.cl',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
        ]);

        Http::fake([
            'cotiza.romulo.cl/*' => Http::response([
                'resultado' => 'OK',
                'mensaje' => 'La cotización «2686-279-COT26» ya existe (nota #13383).',
                'nronota' => 13383,
                'encargado' => '2686-279-COT26',
            ], 200),
        ]);

        $error = $this->service->errorSiEncargadoExisteEnPar('2686-279-COT26', 'Ya existe en Romulo');

        $this->assertSame('La cotización «2686-279-COT26» ya existe (nota #13383).', $error);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'cotiza.romulo.cl'));
    }
}
