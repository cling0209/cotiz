<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\NotaMpCorrida;
use App\Models\NotaMpCorridaCambio;
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

    public function test_novedades_recientes_limita_y_ordena_por_fecha_ultimo_cambio(): void
    {
        $corrida = NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now(),
            'fin' => now(),
            'estado' => 'ok',
            'total_notas' => 2,
            'notas_procesadas' => 2,
        ]);

        foreach ([801, 802] as $nronota) {
            Nota::query()->create([
                'nronota' => $nronota,
                'descripcion' => 'Test novedades '.$nronota,
                'fecha' => now()->toDateString(),
                'usuario' => 'admin',
                'empresa' => 'Cliente',
                'encargado' => $nronota.'-1-COT26',
                'nota_softland' => 80000 + $nronota,
                'enviadoapi' => 0,
                'factor_precio_venta' => 1.22,
            ]);
        }

        NotaMpSeguimiento::query()->create([
            'nronota' => 801,
            'codigo_proceso' => '801-1-COT26',
            'estado_mp_codigo' => 'proveedor_seleccionado',
            'rut_ganador' => '76779675-7',
            'razon_social_ganador' => 'INTEGRAMUNDO SPA',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
            'fecha_ultimo_cambio' => '2026-03-25 11:00:00',
            'ultima_corrida_id' => $corrida->id,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => 802,
            'codigo_proceso' => '802-1-COT26',
            'estado_mp_codigo' => 'proveedor_seleccionado',
            'rut_ganador' => '11111111-1',
            'razon_social_ganador' => 'OTRO SPA',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
            'fecha_ultimo_cambio' => '2026-03-26 15:00:00',
            'ultima_corrida_id' => $corrida->id,
        ]);

        NotaMpCorridaCambio::query()->create([
            'corrida_id' => $corrida->id,
            'nronota' => 801,
            'codigo_proceso' => '801-1-COT26',
            'estado_anterior' => 'publicada',
            'estado_nuevo' => 'proveedor_seleccionado',
            'resultado_propio' => 'cerrada',
            'rut_ganador' => '76779675-7',
            'razon_social_ganador' => 'INTEGRAMUNDO SPA',
        ]);

        NotaMpCorridaCambio::query()->create([
            'corrida_id' => $corrida->id,
            'nronota' => 802,
            'codigo_proceso' => '802-1-COT26',
            'estado_anterior' => 'publicada',
            'estado_nuevo' => 'proveedor_seleccionado',
            'resultado_propio' => 'cerrada',
            'rut_ganador' => '11111111-1',
            'razon_social_ganador' => 'OTRO SPA',
        ]);

        $novedades = $this->app->make(NotaMpResultadosService::class)->novedadesRecientes();

        $this->assertCount(2, $novedades);
        $this->assertSame(802, $novedades->first()->nronota);
        $this->assertSame(801, $novedades->last()->nronota);
        $this->assertTrue($novedades->firstWhere('nronota', 801)->es_ganador_propio);
        $this->assertFalse($novedades->firstWhere('nronota', 802)->es_ganador_propio);
        $this->assertTrue($novedades->every(fn ($nov) => $nov->cambio_ultima_consulta));
    }

    public function test_novedades_no_marca_cambio_ultima_consulta_si_corrida_es_anterior(): void
    {
        $corridaAnterior = NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now()->subDay(),
            'fin' => now()->subDay(),
            'estado' => 'ok',
            'total_notas' => 1,
            'notas_procesadas' => 1,
        ]);

        NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now(),
            'fin' => now(),
            'estado' => 'ok',
            'total_notas' => 0,
            'notas_procesadas' => 0,
        ]);

        Nota::query()->create([
            'nronota' => 803,
            'descripcion' => 'Test corrida anterior',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '803-1-COT26',
            'nota_softland' => 80300,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => 803,
            'codigo_proceso' => '803-1-COT26',
            'estado_mp_codigo' => 'proveedor_seleccionado',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
            'fecha_ultimo_cambio' => '2026-03-27 10:00:00',
            'ultima_corrida_id' => $corridaAnterior->id,
        ]);

        NotaMpCorridaCambio::query()->create([
            'corrida_id' => $corridaAnterior->id,
            'nronota' => 803,
            'codigo_proceso' => '803-1-COT26',
            'estado_anterior' => 'publicada',
            'estado_nuevo' => 'proveedor_seleccionado',
            'resultado_propio' => 'cerrada',
        ]);

        $novedades = $this->app->make(NotaMpResultadosService::class)->novedadesRecientes();

        $this->assertCount(1, $novedades);
        $this->assertFalse($novedades->first()->cambio_ultima_consulta);
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
                    'convocatoria' => [
                        'estado_convocatoria' => 1,
                        'descripcion' => 'Primer llamado',
                        'fecha_cierre_primer_llamado' => '2026-03-25 09:00',
                        'fecha_cierre_segundo_llamado' => '2026-03-26 16:07',
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
            'convocatoria_descripcion' => 'Primer llamado',
            'fecha_cierre_primer_llamado' => '2026-03-25 09:00:00',
            'fecha_cierre_segundo_llamado' => '2026-03-26 16:07:00',
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

    public function test_consulta_publicada_guarda_fechas_y_convocatoria(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);
        $nota = Nota::query()->create([
            'nronota' => 510,
            'descripcion' => 'Test publicada MP',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente test',
            'encargado' => '1000-10-COT26',
            'nota_softland' => 51000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/1000-10-COT26' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'codigo' => '1000-10-COT26',
                    'estado' => ['codigo' => 'publicada', 'glosa' => 'Publicada'],
                    'institucion' => ['organismo_comprador' => 'Municipalidad'],
                    'fechas' => [
                        'fecha_publicacion' => '2026-07-03 12:52',
                        'fecha_cierre' => '2026-07-04 14:30',
                        'fecha_ultimo_cambio' => '2026-07-04 14:35',
                        'fecha_cancelacion' => null,
                    ],
                    'convocatoria' => [
                        'estado_convocatoria' => 1,
                        'descripcion' => 'Primer llamado',
                        'fecha_cierre_primer_llamado' => '2026-07-04 14:30',
                        'fecha_cierre_segundo_llamado' => '2026-07-05 16:07',
                    ],
                    'proveedores_cotizando' => [],
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.iniciar'))
            ->assertOk();

        $this->assertDatabaseHas('nota_mp_seguimientos', [
            'nronota' => $nota->nronota,
            'resultado_propio' => 'pendiente',
            'finalizado' => false,
            'fecha_publicacion' => '2026-07-03 12:52:00',
            'fecha_cierre' => '2026-07-04 14:30:00',
            'fecha_ultimo_cambio' => '2026-07-04 14:35:00',
            'convocatoria_descripcion' => 'Primer llamado',
            'fecha_cierre_primer_llamado' => '2026-07-04 14:30:00',
            'fecha_cierre_segundo_llamado' => '2026-07-05 16:07:00',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.compra-agil.resultados.detalle', ['nronota' => $nota->nronota]))
            ->assertOk()
            ->assertJsonPath('seguimiento.convocatoria_descripcion', 'Primer llamado')
            ->assertJsonPath('seguimiento.fecha_cierre_primer_llamado', fn ($v) => str_contains((string) $v, '2026-07-04'));
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

    public function test_nota_colgada_se_recupera_automaticamente(): void
    {
        config(['cotiz.mercadopublico.resultados_nota_max_segundos' => 180]);

        $corrida = NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now()->subMinutes(10),
            'estado' => 'running',
            'total_notas' => 2,
            'notas_procesadas' => 0,
            'nronota_actual' => 900,
            'codigo_actual' => '900-1-COT26',
            'pendientes_json' => [
                ['nronota' => 900, 'codigo' => '900-1-COT26', 'empresa' => 'Test'],
                ['nronota' => 901, 'codigo' => '901-1-COT26', 'empresa' => 'Test 2'],
            ],
            'updated_at' => now()->subMinutes(4),
        ]);

        Nota::query()->create([
            'nronota' => 900,
            'descripcion' => 'Colgada',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Test',
            'encargado' => '900-1-COT26',
            'nota_softland' => 90000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        $service = $this->app->make(NotaMpResultadosService::class);
        $this->assertTrue($service->liberarNotaColgadaIfNeeded($corrida));

        $corrida->refresh();
        $this->assertSame(1, (int) $corrida->notas_procesadas);
        $this->assertSame('901-1-COT26', $corrida->codigo_actual);
        $this->assertNotNull($corrida->nota_inicio_at);
        $this->assertDatabaseHas('nota_mp_corrida_detalle', [
            'corrida_id' => $corrida->id,
            'nronota' => 900,
            'exito' => false,
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
            'nota_inicio_at' => now()->subMinutes(4),
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

    public function test_cerradas_muestra_ejecutivo_y_exporta_csv(): void
    {
        $admin = User::factory()->create([
            'username' => 'admin',
            'nombre' => 'Ana',
            'apellidop' => 'Ejecutiva',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        Nota::query()->create([
            'nronota' => 910,
            'descripcion' => 'Cerrada propia',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente ACME',
            'encargado' => '910-1-COT26',
            'nota_softland' => 91000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => 910,
            'codigo_proceso' => '910-1-COT26',
            'estado_mp_codigo' => 'proveedor_seleccionado',
            'estado_mp_glosa' => 'Proveedor seleccionado',
            'organismo' => 'Municipalidad Test',
            'rut_ganador' => '76779675-7',
            'razon_social_ganador' => 'INTEGRAMUNDO SPA',
            'monto_total_ganador' => 150000,
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
            'fecha_publicacion' => '2026-04-01 10:00:00',
            'ultimo_consultado_en' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.cerradas'))
            ->assertOk()
            ->assertSee('Ejecutivo', false)
            ->assertSee('Ana Ejecutiva', false)
            ->assertSee('Descargar CSV', false);

        $response = $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.cerradas.exportar'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('910-1-COT26', $response->streamedContent());
        $this->assertStringContainsString('Ana Ejecutiva', $response->streamedContent());
        $this->assertStringContainsString('Sí', $response->streamedContent());
    }

    public function test_cerradas_ordena_y_filtra_por_fecha_ultimo_cambio(): void
    {
        $admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        foreach ([911, 912, 913] as $nronota) {
            Nota::query()->create([
                'nronota' => $nronota,
                'descripcion' => 'Cerrada '.$nronota,
                'fecha' => now()->toDateString(),
                'usuario' => 'admin',
                'empresa' => 'Cliente',
                'encargado' => $nronota.'-1-COT26',
                'nota_softland' => $nronota * 100,
                'enviadoapi' => 0,
                'factor_precio_venta' => 1.22,
            ]);
        }

        NotaMpSeguimiento::query()->create([
            'nronota' => 911,
            'codigo_proceso' => '911-1-COT26',
            'estado_mp_codigo' => 'proveedor_seleccionado',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
            'fecha_ultimo_cambio' => '2026-06-01 10:00:00',
        ]);
        NotaMpSeguimiento::query()->create([
            'nronota' => 912,
            'codigo_proceso' => '912-1-COT26',
            'estado_mp_codigo' => 'proveedor_seleccionado',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
            'fecha_ultimo_cambio' => '2026-06-15 10:00:00',
        ]);
        NotaMpSeguimiento::query()->create([
            'nronota' => 913,
            'codigo_proceso' => '913-1-COT26',
            'estado_mp_codigo' => 'proveedor_seleccionado',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
            'fecha_ultimo_cambio' => '2026-05-01 10:00:00',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.cerradas'));

        $response->assertOk();
        $pos912 = strpos($response->getContent(), '912-1-COT26');
        $pos911 = strpos($response->getContent(), '911-1-COT26');
        $pos913 = strpos($response->getContent(), '913-1-COT26');
        $this->assertNotFalse($pos912);
        $this->assertNotFalse($pos911);
        $this->assertNotFalse($pos913);
        $this->assertTrue($pos912 < $pos911);
        $this->assertTrue($pos911 < $pos913);

        $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.cerradas', [
                'cambio_desde' => '2026-06-01',
                'cambio_hasta' => '2026-06-30',
            ]))
            ->assertOk()
            ->assertSee('912-1-COT26', false)
            ->assertSee('911-1-COT26', false)
            ->assertDontSee('913-1-COT26', false);
    }
}
