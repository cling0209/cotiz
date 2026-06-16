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
                'nronota' => 42,
            ], 200),
        ]);

        $error = $this->service->errorSiEncargadoExisteEnPar('OC-EXISTE', 'Ya existe en par');

        $this->assertSame('Ya existe en par', $error);
    }

    public function test_url_vacia_no_consulta(): void
    {
        config(['cotiz.api_nota.consulta_nro_cotizacion' => '']);

        Http::fake();

        $error = $this->service->errorSiEncargadoExisteEnPar('OC-001', 'Duplicada');

        $this->assertSame('', $error);
        Http::assertNothingSent();
    }
}
