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
            'cotiz.sistema' => 'Romulo',
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
            'cotiz.sistema' => 'Romulo',
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
        $this->assertFalse($consulta['cold_start']);

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
            'cotiz.sistema' => 'Reicol',
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
            'cotiz.sistema' => 'Reicol',
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
        Http::assertSent(fn ($request) => str_contains($request->url(), 'cotiza.romulo.cl/api/v1/nota-consulta'));
    }

    public function test_timeout_devuelve_cold_start_y_despierta_sitio_par(): void
    {
        config([
            'app.url' => 'https://cotiza.reicol.cl',
            'cotiz.sistema' => 'Reicol',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
            'cotiz.api_nota.consulta_par_max_intentos' => 1,
        ]);

        Http::fake([
            'cotiza.romulo.cl/api/v1/nota-consulta' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Timeout'),
            'cotiza.romulo.cl/up' => Http::response('OK', 200),
        ]);

        $consulta = $this->service->consultarEncargadoEnPar('2686-279-COT26');

        $this->assertTrue($consulta['cold_start']);
        $this->assertSame(NotaConsultaRemotaService::mensajeIniciandoConsulta(), $consulta['mensaje']);
        $this->assertNull($consulta['error']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/up'));
    }

    public function test_http_500_devuelve_cold_start_y_despierta_sitio_par(): void
    {
        config([
            'app.url' => 'https://cotiza.reicol.cl',
            'cotiz.sistema' => 'Reicol',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
            'cotiz.api_nota.consulta_par_max_intentos' => 1,
        ]);

        Http::fake([
            'cotiza.romulo.cl/api/v1/nota-consulta' => Http::response('Internal Server Error', 500),
            'cotiza.romulo.cl/up' => Http::response('OK', 200),
        ]);

        $consulta = $this->service->consultarEncargadoEnPar('2686-279-COT26');

        $this->assertTrue($consulta['cold_start']);
        $this->assertSame(NotaConsultaRemotaService::mensajeIniciandoConsulta(), $consulta['mensaje']);
        $this->assertNull($consulta['error']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/up'));
    }

    public function test_con_espera_reintenta_hasta_responder(): void
    {
        config([
            'app.url' => 'https://cotiza.reicol.cl',
            'cotiz.sistema' => 'Reicol',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
            'cotiz.api_nota.consulta_par_max_intentos' => 3,
            'cotiz.api_nota.consulta_par_espera_segundos' => 0,
        ]);

        $intentos = 0;
        Http::fake(function ($request) use (&$intentos) {
            if (str_contains($request->url(), '/up')) {
                return Http::response('OK', 200);
            }
            $intentos++;
            if ($intentos < 2) {
                return Http::response('', 503);
            }

            return Http::response([
                'resultado' => 'ERROR',
                'mensaje' => 'La cotización no existe en notas.',
            ], 400);
        });

        $error = $this->service->errorSiEncargadoExisteEnPar('OC-NUEVA-002', 'Duplicada');

        $this->assertSame('', $error);
        $this->assertSame(2, $intentos);
    }

    public function test_con_espera_agota_reintentos_con_mensaje_sin_conexion(): void
    {
        config([
            'app.url' => 'https://cotiza.reicol.cl',
            'cotiz.sistema' => 'Reicol',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
            'cotiz.api_nota.consulta_par_max_intentos' => 2,
            'cotiz.api_nota.consulta_par_espera_segundos' => 0,
        ]);

        Http::fake([
            'cotiza.romulo.cl/api/v1/nota-consulta' => Http::response('', 503),
            'cotiza.romulo.cl/up' => Http::response('OK', 200),
        ]);

        $consulta = $this->service->consultarEncargadoEnParConEspera('OC-SIN-RESPUESTA');

        $this->assertFalse($consulta['cold_start']);
        $this->assertSame(NotaConsultaRemotaService::mensajeSinConexionConsultaPar(), $consulta['error']);
    }
}
