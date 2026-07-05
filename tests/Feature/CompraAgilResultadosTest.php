<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\NotaMpCorrida;
use App\Models\NotaMpSeguimiento;
use App\Models\User;
use App\Services\NotaMpResultadosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompraAgilResultadosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cotiz.mercadopublico.ticket' => 'test-ticket',
            'cotiz.mercadopublico.resultados_admin_habilitado' => true,
            'cotiz.mercadopublico.resultados_delay_ms' => 0,
            'cotiz.empresa_rut' => '76.779.675-7',
        ]);
    }

    public function test_solo_admin_ve_menu_resultados(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);
        $otro = User::factory()->create(['username' => 'ejecutivo1', 'perfil' => User::PERFIL_SUPERADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.index'))
            ->assertOk()
            ->assertSee('Resultados Compra Ágil', false);

        $this->actingAs($otro)
            ->get(route('admin.compra-agil.resultados.index'))
            ->assertForbidden();
    }

    public function test_encolar_corrida_procesa_nota_y_guarda_seguimiento(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);
        $nota = Nota::query()->create([
            'nronota' => 501,
            'descripcion' => 'Test resultados MP',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente test',
            'encargado' => '3300-66-COT26',
            'nota_softland' => 50100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/3300-66-COT26' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'codigo' => '3300-66-COT26',
                    'estado' => ['codigo' => 'proveedor_seleccionado', 'glosa' => 'Proveedor seleccionado'],
                    'id_orden_compra' => 55070937,
                    'institucion' => ['organismo_comprador' => 'Municipalidad'],
                    'fechas' => [
                        'fecha_publicacion' => '2026-03-20 16:19',
                        'fecha_cierre' => '2026-03-25 09:00',
                        'fecha_ultimo_cambio' => '2026-03-25 11:00',
                        'fecha_cancelacion' => null,
                    ],
                    'proveedores_cotizando' => [
                        [
                            'id_cotizacion' => 1,
                            'rut_proveedor' => '76.779.675-7',
                            'razon_social' => 'INTEGRAMUNDO SPA',
                            'proveedor_seleccionado' => 1,
                            'activo' => 1,
                            'id_oc' => 55070937,
                            'monto_total' => 298809,
                            'productos_cotizados' => [
                                ['codigo_producto' => '14111537', 'descripcion' => 'Etiqueta', 'cantidad' => 5, 'precio_unitario' => 2100, 'monto_total_producto' => 10500],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.iniciar'))
            ->assertOk()
            ->assertJsonPath('estado.en_curso', false);

        $this->assertDatabaseHas('nota_mp_seguimientos', [
            'nronota' => $nota->nronota,
            'rut_ganador' => '76779675-7',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
            'fecha_publicacion' => '2026-03-20 16:19:00',
            'fecha_cierre' => '2026-03-25 09:00:00',
            'fecha_ultimo_cambio' => '2026-03-25 11:00:00',
        ]);

        $seg = NotaMpSeguimiento::query()->find($nota->nronota);
        $this->assertNotNull($seg);
        $this->assertNull($seg->fecha_cancelacion);

        $this->assertDatabaseHas('nota_mp_corridas', [
            'estado' => 'ok',
            'notas_procesadas' => 1,
            'total_notas' => 1,
        ]);

        $this->assertDatabaseHas('nota_mp_corrida_detalle', [
            'nronota' => $nota->nronota,
            'codigo_proceso' => '3300-66-COT26',
            'exito' => true,
            'resultado_propio' => 'cerrada',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.index'))
            ->assertOk()
            ->assertSee('Resultado por cotización', false)
            ->assertSee('3300-66-COT26', false);
    }

    public function test_no_permite_dos_corridas_en_paralelo(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now(),
            'estado' => 'running',
            'total_notas' => 5,
            'notas_procesadas' => 1,
            'pendientes_json' => [['nronota' => 1, 'codigo' => '1-1-COT26']],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.iniciar'))
            ->assertStatus(409)
            ->assertJsonPath('estado.en_curso', true);
    }

    public function test_estado_expone_progreso_de_corrida_activa(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now(),
            'estado' => 'running',
            'total_notas' => 10,
            'notas_procesadas' => 3,
            'codigo_actual' => '3300-66-COT26',
            'pendientes_json' => [],
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.compra-agil.resultados.estado'))
            ->assertOk()
            ->assertJsonPath('en_curso', true)
            ->assertJsonPath('procesadas', 3)
            ->assertJsonPath('total', 10)
            ->assertJsonPath('codigo_actual', '3300-66-COT26');
    }

    public function test_cancelar_corrida_en_curso(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now(),
            'estado' => 'running',
            'total_notas' => 10,
            'notas_procesadas' => 2,
            'codigo_actual' => '3300-66-COT26',
            'pendientes_json' => [['nronota' => 1, 'codigo' => '1-1-COT26']],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.cancelar'))
            ->assertOk()
            ->assertJsonPath('estado.en_curso', false);

        $this->assertDatabaseHas('nota_mp_corridas', [
            'estado' => 'cancelled',
            'notas_procesadas' => 2,
        ]);

        $this->assertSame(0, DB::table('jobs')->where('payload', 'like', '%ProcessNotaMpCorridaJob%')->count());

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.cancelar'))
            ->assertStatus(422);
    }

    public function test_corrida_marca_error_si_todas_las_consultas_fallan(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);
        Nota::query()->create([
            'nronota' => 502,
            'descripcion' => 'Test fallo MP',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente test',
            'encargado' => '3300-66-COT26',
            'nota_softland' => 50200,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/3300-66-COT26' => Http::response([], 404),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.iniciar'))
            ->assertOk()
            ->assertJsonPath('estado.en_curso', false);

        $this->assertDatabaseHas('nota_mp_corridas', [
            'estado' => 'error',
            'notas_procesadas' => 1,
            'total_notas' => 1,
        ]);

        $this->assertDatabaseMissing('nota_mp_seguimientos', [
            'nronota' => 502,
        ]);

        $this->assertDatabaseHas('nota_mp_corrida_detalle', [
            'nronota' => 502,
            'exito' => false,
            'mensaje' => 'No existe Compra Ágil con el código indicado.',
        ]);
    }

    public function test_encolar_corrida_respeta_limite_solicitado(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        foreach ([601, 602, 603] as $i => $nronota) {
            Nota::query()->create([
                'nronota' => $nronota,
                'descripcion' => 'Test limite '.$nronota,
                'fecha' => now()->subDays(3 - $i)->toDateString(),
                'usuario' => 'admin',
                'empresa' => 'Cliente '.$nronota,
                'encargado' => '3300-66-COT2'.$i,
                'nota_softland' => 60000 + $nronota,
                'enviadoapi' => 0,
                'factor_precio_venta' => 1.22,
            ]);
        }

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/*' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'codigo' => '3300-66-COT26',
                    'estado' => ['codigo' => 'publicada', 'glosa' => 'Publicada'],
                    'institucion' => ['organismo_comprador' => 'Test'],
                    'proveedores_cotizando' => [],
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.iniciar'), ['limite' => 1])
            ->assertOk();

        $this->assertDatabaseHas('nota_mp_corridas', [
            'estado' => 'ok',
            'total_notas' => 1,
            'notas_procesadas' => 1,
        ]);
    }

    public function test_corrida_colgada_se_libera_automaticamente(): void
    {
        config(['cotiz.mercadopublico.resultados_corrida_colgada_segundos' => 600]);

        NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now()->subMinutes(15),
            'estado' => 'running',
            'total_notas' => 50,
            'notas_procesadas' => 0,
            'codigo_actual' => '1048606-4-COT26',
            'pendientes_json' => [['nronota' => 1, 'codigo' => '1048606-4-COT26']],
        ]);

        $this->assertNull($this->app->make(NotaMpResultadosService::class)->corridaEnCurso());

        $this->assertDatabaseHas('nota_mp_corridas', [
            'estado' => 'error',
            'notas_procesadas' => 0,
        ]);
    }

    public function test_estado_corrida_alerta_cuando_nota_actual_lleva_mas_de_tres_minutos(): void
    {
        config([
            'cotiz.mercadopublico.resultados_nota_alerta_segundos' => 180,
            'cotiz.mercadopublico.resultados_nota_max_segundos' => 180,
        ]);

        NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now()->subHour(),
            'estado' => 'running',
            'total_notas' => 100,
            'notas_procesadas' => 50,
            'codigo_actual' => '974556-11-COT26',
            'nronota_actual' => 999,
            'pendientes_json' => [],
            'updated_at' => now()->subMinutes(4),
        ]);

        $estado = $this->app->make(NotaMpResultadosService::class)->estadoCorrida();

        $this->assertTrue($estado['en_curso']);
        $this->assertNotNull($estado['alerta']);
        $this->assertStringContainsString('974556-11-COT26', $estado['alerta']);
        $this->assertStringContainsString('Al superar 180 s', $estado['alerta']);
        $this->assertGreaterThanOrEqual(180, $estado['segundos_en_nota_actual']);
    }

    public function test_consultar_nota_no_llama_api_si_deadline_ya_vencio(): void
    {
        $nota = Nota::query()->create([
            'nronota' => 777,
            'descripcion' => 'Deadline test',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '897-13-COT26',
            'nota_softland' => 77700,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        $corrida = NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now(),
            'estado' => 'running',
            'total_notas' => 1,
            'notas_procesadas' => 0,
            'pendientes_json' => [['nronota' => 777, 'codigo' => '897-13-COT26']],
        ]);

        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(NotaMpResultadosService::mensajeTiempoMaximoNota());

        $this->app->make(NotaMpResultadosService::class)->consultarNota(
            $nota->nronota,
            $corrida,
            'admin',
            microtime(true) - 1,
        );

        Http::assertNothingSent();
    }
}
