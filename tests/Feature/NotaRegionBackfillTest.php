<?php

namespace Tests\Feature;

use App\Models\CompraAgilProceso;
use App\Models\Nota;
use App\Models\NotaMpCorrida;
use App\Models\User;
use App\Services\NotaMpResultadosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotaRegionBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultar_nota_rellena_region_desde_mp_si_faltaba(): void
    {
        config(['cotiz.mercadopublico.ticket' => 'test-ticket']);

        $admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $nota = Nota::query()->create([
            'nronota' => 1201,
            'descripcion' => 'Sin región',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '1201-1-COT26',
            'nota_softland' => 120100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
            'region' => null,
            'nombre_region' => null,
        ]);

        $corrida = NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now(),
            'estado' => 'running',
            'total_notas' => 1,
            'notas_procesadas' => 0,
            'pendientes_json' => [['nronota' => 1201, 'codigo' => '1201-1-COT26']],
        ]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/1201-1-COT26' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'codigo' => '1201-1-COT26',
                    'estado' => ['codigo' => 'publicada', 'glosa' => 'Publicada'],
                    'institucion' => [
                        'organismo_comprador' => 'Municipalidad Maule',
                        'region' => 7,
                        'nombre_region' => 'Región del Maule',
                    ],
                    'fechas' => [
                        'fecha_publicacion' => '2026-03-20 16:19',
                        'fecha_cierre' => '2026-03-25 09:00',
                    ],
                    'proveedores_cotizando' => [],
                ],
            ]),
        ]);

        $this->app->make(NotaMpResultadosService::class)->consultarNota(
            $nota->nronota,
            $corrida,
            'admin',
        );

        $nota->refresh();
        $this->assertSame(7, (int) $nota->region);
        $this->assertSame('Región del Maule', $nota->nombre_region);
    }

    public function test_rellenar_region_si_falta_usa_proceso_local_sin_llamar_mp(): void
    {
        config(['cotiz.mercadopublico.ticket' => 'test-ticket']);

        Nota::query()->create([
            'nronota' => 1202,
            'descripcion' => 'Con proceso',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '1202-1-COT26',
            'nota_softland' => 120200,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
            'region' => null,
            'nombre_region' => null,
        ]);

        CompraAgilProceso::query()->create([
            'codigo' => '1202-1-COT26',
            'nombre' => 'Proceso test',
            'region' => 9,
        ]);

        Http::fake();

        $resultado = $this->app->make(NotaMpResultadosService::class)->rellenarRegionSiFalta(1202);

        $this->assertSame('updated', $resultado);
        $this->assertDatabaseHas('notas', [
            'nronota' => 1202,
            'region' => 9,
            'nombre_region' => 'Araucanía',
        ]);
        Http::assertNothingSent();
    }

    public function test_rellenar_region_si_falta_completa_solo_nombre_si_ya_hay_codigo(): void
    {
        Nota::query()->create([
            'nronota' => 1203,
            'descripcion' => 'Solo código',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '1203-1-COT26',
            'nota_softland' => 120300,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
            'region' => 13,
            'nombre_region' => null,
        ]);

        Http::fake();

        $resultado = $this->app->make(NotaMpResultadosService::class)->rellenarRegionSiFalta(1203);

        $this->assertSame('updated', $resultado);
        $this->assertDatabaseHas('notas', [
            'nronota' => 1203,
            'region' => 13,
            'nombre_region' => 'Metropolitana',
        ]);
        Http::assertNothingSent();
    }
}
