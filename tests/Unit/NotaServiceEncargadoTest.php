<?php

namespace Tests\Unit;

use App\Models\Nota;
use App\Services\NotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotaServiceEncargadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_validar_disponible_no_consulta_par(): void
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
                'mensaje' => 'La cotización «2686-279-COT26» ya existe (nota #99).',
                'nronota' => 99,
            ], 200),
        ]);

        $nota = Nota::query()->create([
            'nronota' => 100,
            'descripcion' => 'Test',
            'fecha' => now()->toDateString(),
            'usuario' => 'u1',
            'empresa' => '',
            'encargado' => '',
            'nota_softland' => 10000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        $service = app(NotaService::class);
        $error = $service->validarNumeroCotizacionDisponible($nota, '2686-279-COT26', true);

        $this->assertNull($error);
        Http::assertNothingSent();
    }

    public function test_envio_rechaza_duplicado_en_par(): void
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
                'mensaje' => 'La cotización «2686-279-COT26» ya existe (nota #99).',
                'nronota' => 99,
            ], 200),
        ]);

        $nota = Nota::query()->create([
            'nronota' => 100,
            'descripcion' => 'Test',
            'fecha' => now()->toDateString(),
            'usuario' => 'u1',
            'empresa' => '',
            'encargado' => '2686-279-COT26',
            'nota_softland' => 10000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        $service = app(NotaService::class);
        $error = $service->errorSiEncargadoExisteEnParParaEnvio($nota);

        $this->assertSame('La cotización «2686-279-COT26» ya existe (nota #99).', $error);
        Http::assertSentCount(1);
    }
}
