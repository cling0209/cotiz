<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\NotaMpCorrida;
use App\Models\NotaMpCorridaCambio;
use App\Models\NotaMpCorridaDetalle;
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

    public function test_novedades_muestra_solo_ultimo_cambio_por_nota(): void
    {
        $corrida = NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now(),
            'fin' => now(),
            'estado' => 'ok',
            'total_notas' => 1,
            'notas_procesadas' => 1,
        ]);

        Nota::query()->create([
            'nronota' => 804,
            'descripcion' => 'Test ultimo cambio',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '804-1-COT26',
            'nota_softland' => 80400,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => 804,
            'codigo_proceso' => '804-1-COT26',
            'estado_mp_codigo' => 'proveedor_seleccionado',
            'rut_ganador' => '77399254-1',
            'razon_social_ganador' => 'LIBRERIA ANTU LIMITADA',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
            'fecha_ultimo_cambio' => '2026-07-06 11:00:00',
            'ultima_corrida_id' => $corrida->id,
        ]);

        NotaMpCorridaCambio::query()->create([
            'corrida_id' => $corrida->id,
            'nronota' => 804,
            'codigo_proceso' => '804-1-COT26',
            'estado_anterior' => null,
            'estado_nuevo' => 'cerrada',
            'resultado_propio' => 'pendiente',
        ]);

        NotaMpCorridaCambio::query()->create([
            'corrida_id' => $corrida->id,
            'nronota' => 804,
            'codigo_proceso' => '804-1-COT26',
            'estado_anterior' => 'cerrada',
            'estado_nuevo' => 'proveedor_seleccionado',
            'resultado_propio' => 'cerrada',
            'rut_ganador' => '77399254-1',
            'razon_social_ganador' => 'LIBRERIA ANTU LIMITADA',
        ]);

        $novedades = $this->app->make(NotaMpResultadosService::class)->novedadesRecientes();

        $this->assertCount(1, $novedades);
        $this->assertSame(804, $novedades->first()->nronota);
        $this->assertSame('proveedor_seleccionado', $novedades->first()->estado_nuevo);
        $this->assertSame('cerrada', $novedades->first()->estado_anterior);
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
            'pendientes_json' => [
                ['nronota' => 3300, 'codigo' => '3300-66-COT26'],
            ],
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

    public function test_corrida_masiva_reintenta_errores_http_recuperables_como_consulta_individual(): void
    {
        config([
            'cotiz.mercadopublico.api_reintentos_http' => 3,
            'cotiz.mercadopublico.api_espera_reintento_seg' => 0,
        ]);

        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        Nota::query()->create([
            'nronota' => 5031,
            'descripcion' => 'Test reintento MP masivo',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente test',
            'encargado' => '5031-1-COT26',
            'nota_softland' => 503100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/5031-1-COT26' => Http::sequence()
                ->push([], 503)
                ->push([], 503)
                ->push([
                    'success' => 'OK',
                    'payload' => [
                        'codigo' => '5031-1-COT26',
                        'estado' => ['codigo' => 'publicada', 'glosa' => 'Publicada'],
                        'institucion' => ['organismo_comprador' => 'Test'],
                        'proveedores_cotizando' => [],
                    ],
                ]),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.iniciar'))
            ->assertOk()
            ->assertJsonPath('estado.en_curso', false);

        $this->assertDatabaseHas('nota_mp_corridas', [
            'estado' => 'ok',
            'notas_procesadas' => 1,
            'total_notas' => 1,
        ]);

        $this->assertDatabaseHas('nota_mp_seguimientos', [
            'nronota' => 5031,
            'estado_mp_glosa' => 'Publicada',
            'resultado_propio' => 'pendiente',
        ]);

        Http::assertSentCount(3);
    }

    public function test_corrida_masiva_no_reintenta_codigo_ruta_invalido_en_mp(): void
    {
        config([
            'cotiz.mercadopublico.api_reintentos_http' => 3,
            'cotiz.mercadopublico.api_espera_reintento_seg' => 0,
        ]);

        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        Nota::query()->create([
            'nronota' => 5032,
            'descripcion' => 'Codigo CA invalido para MP',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente test',
            'encargado' => '5032-1-COT26',
            'nota_softland' => 503200,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        $errorMp = "Parámetro de ruta 'codigo' inválido.";

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/5032-1-COT26' => Http::sequence()
                ->push(['success' => 'ERROR', 'errors' => [['mensaje' => $errorMp]]], 503)
                ->push(['success' => 'ERROR', 'errors' => [['mensaje' => $errorMp]]], 503)
                ->push(['success' => 'ERROR', 'errors' => [['mensaje' => $errorMp]]], 503),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.iniciar'))
            ->assertOk()
            ->assertJsonPath('estado.en_curso', false);

        Http::assertSentCount(1);

        $this->assertDatabaseHas('nota_mp_corrida_detalle', [
            'nronota' => 5032,
            'exito' => false,
            'mensaje' => $errorMp.' (sin reintento)',
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

    public function test_nota_colgada_no_se_recupera_si_hay_job_reservado_activo(): void
    {
        config(['cotiz.mercadopublico.resultados_nota_max_segundos' => 180]);

        $corrida = NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now()->subMinutes(10),
            'estado' => 'running',
            'total_notas' => 1,
            'notas_procesadas' => 0,
            'nronota_actual' => 902,
            'codigo_actual' => '902-1-COT26',
            'pendientes_json' => [
                ['nronota' => 902, 'codigo' => '902-1-COT26', 'empresa' => 'Test'],
            ],
            'updated_at' => now()->subMinutes(4),
        ]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{"displayName":"App\\\\Jobs\\\\ProcessNotaMpCorridaJob","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","data":{"commandName":"App\\\\Jobs\\\\ProcessNotaMpCorridaJob","command":"O:33:\"App\\\\Jobs\\\\ProcessNotaMpCorridaJob\":1:{s:8:\"corridaId\";i:'.$corrida->id.';}"}}',
            'attempts' => 1,
            'reserved_at' => time(),
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $service = $this->app->make(NotaMpResultadosService::class);
        $this->assertFalse($service->liberarNotaColgadaIfNeeded($corrida));

        $corrida->refresh();
        $this->assertSame(0, (int) $corrida->notas_procesadas);
        $this->assertDatabaseMissing('nota_mp_corrida_detalle', [
            'corrida_id' => $corrida->id,
            'nronota' => 902,
        ]);
    }

    public function test_resultado_ultimo_proceso_muestra_consultar_mp_solo_en_filas_error(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        $corrida = NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now()->subHour(),
            'fin' => now(),
            'estado' => 'ok',
            'total_notas' => 2,
            'notas_procesadas' => 2,
            'pendientes_json' => [
                ['nronota' => 950, 'codigo' => '950-1-COT26', 'empresa' => 'Cliente error'],
                ['nronota' => 951, 'codigo' => '951-1-COT26', 'empresa' => 'Cliente ok'],
            ],
        ]);

        NotaMpCorridaDetalle::query()->create([
            'corrida_id' => $corrida->id,
            'nronota' => 950,
            'codigo_proceso' => '950-1-COT26',
            'empresa' => 'Cliente error',
            'exito' => false,
            'mensaje' => 'Tiempo máximo por nota excedido.',
        ]);

        NotaMpCorridaDetalle::query()->create([
            'corrida_id' => $corrida->id,
            'nronota' => 951,
            'codigo_proceso' => '951-1-COT26',
            'empresa' => 'Cliente ok',
            'exito' => true,
            'estado_mp_glosa' => 'Cerrada',
            'resultado_propio' => 'pendiente',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.resultado'))
            ->assertOk()
            ->assertSee('950-1-COT26', false)
            ->assertSee('951-1-COT26', false)
            ->assertSee('Consultar MP', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Consultar MP'));
        $this->assertSame(1, substr_count($html, 'btn-consultar-mp-individual'));
        $this->assertSame(1, substr_count($html, 'Comparar'));
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

    public function test_pendientes_seguimiento_listado_y_boton_en_index(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        Nota::query()->create([
            'nronota' => 920,
            'descripcion' => 'Pendiente seguimiento sin prov',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '920-1-COT26',
            'nota_softland' => 92000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => 920,
            'codigo_proceso' => '920-1-COT26',
            'estado_mp_codigo' => 'publicada',
            'estado_mp_glosa' => 'Publicada',
            'organismo' => 'Municipalidad Test',
            'resultado_propio' => 'pendiente',
            'finalizado' => false,
            'fecha_publicacion' => '2026-05-01 10:00:00',
            'fecha_ultimo_cambio' => '2026-05-02 11:00:00',
            'ultimo_consultado_en' => now(),
        ]);

        Nota::query()->create([
            'nronota' => 921,
            'descripcion' => 'Pendiente con prov seleccionado',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '921-1-COT26',
            'nota_softland' => 92100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => 921,
            'codigo_proceso' => '921-1-COT26',
            'estado_mp_codigo' => 'proveedor_seleccionado',
            'estado_mp_glosa' => 'Proveedor seleccionado',
            'organismo' => 'Municipalidad Test',
            'razon_social_ganador' => 'PROVEEDOR SPA',
            'rut_ganador' => '11111111-1',
            'resultado_propio' => 'pendiente',
            'finalizado' => false,
            'fecha_publicacion' => '2026-05-01 10:00:00',
            'fecha_ultimo_cambio' => '2026-05-03 11:00:00',
            'ultimo_consultado_en' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.index'))
            ->assertOk()
            ->assertSee('Pendientes seguimiento', false)
            ->assertSee('920-1-COT26', false);

        $html = $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.pendientes'))
            ->assertOk()
            ->assertSee('920-1-COT26', false)
            ->assertSee('921-1-COT26', false)
            ->assertSee('Consultar MP', false)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'Consultar MP'));
        $this->assertSame(1, substr_count($html, 'Comparar'));
    }

    public function test_todas_las_notas_listado_exporta_y_boton_en_index(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        Nota::query()->create([
            'nronota' => 930,
            'descripcion' => 'Pendiente en listado todas',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '930-1-COT26',
            'nota_softland' => 93000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => 930,
            'codigo_proceso' => '930-1-COT26',
            'estado_mp_codigo' => 'publicada',
            'estado_mp_glosa' => 'Publicada',
            'organismo' => 'Municipalidad Test',
            'resultado_propio' => 'pendiente',
            'finalizado' => false,
            'fecha_publicacion' => '2026-05-01 10:00:00',
            'fecha_ultimo_cambio' => '2026-05-02 11:00:00',
            'ultimo_consultado_en' => now(),
        ]);

        Nota::query()->create([
            'nronota' => 932,
            'descripcion' => 'Cerrada en listado todas',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '932-1-COT26',
            'nota_softland' => 93200,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => 932,
            'codigo_proceso' => '932-1-COT26',
            'estado_mp_codigo' => 'cerrada',
            'estado_mp_glosa' => 'Cerrada',
            'organismo' => 'Municipalidad Test',
            'razon_social_ganador' => 'GANADOR SPA',
            'rut_ganador' => '22222222-2',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
            'fecha_publicacion' => '2026-04-01 10:00:00',
            'fecha_ultimo_cambio' => '2026-04-10 11:00:00',
            'ultimo_consultado_en' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.index'))
            ->assertOk()
            ->assertSee('Todas las notas', false);

        $html = $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.todas'))
            ->assertOk()
            ->assertSee('930-1-COT26', false)
            ->assertSee('932-1-COT26', false)
            ->assertSee('Detalle', false)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'Consultar MP'));
        $this->assertSame(1, substr_count($html, 'Comparar'));

        $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.todas', ['seguimiento' => 'cerrada']))
            ->assertOk()
            ->assertSee('932-1-COT26', false)
            ->assertDontSee('930-1-COT26', false);

        $csv = $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.todas.exportar'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('930-1-COT26', $csv);
        $this->assertStringContainsString('932-1-COT26', $csv);
        $this->assertStringContainsString('Seguimiento', $csv);
    }

    public function test_todas_incluye_nota_sin_consultar_mp_por_codigo_ca(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        Nota::query()->create([
            'nronota' => 13561,
            'descripcion' => 'Cotiz Quilpue sin consultar MP',
            'fecha' => '2026-07-05',
            'usuario' => 'admin',
            'empresa' => 'I MUNICIPALIDAD DE QUILPUE',
            'encargado' => '2428-902-COT26',
            'nota_softland' => 1356100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.todas', ['codigo_proceso' => '2428-902-COT26']))
            ->assertOk()
            ->assertSee('13561', false)
            ->assertSee('2428-902-COT26', false)
            ->assertSee('Sin consultar MP', false)
            ->assertSee('Consultar MP', false)
            ->getContent();

        $this->assertSame(0, substr_count($html, 'btn-detalle-mp'));
        $this->assertStringContainsString('dataRowTodas', $html);
        $this->assertStringContainsString('actualizarFilaTodas', $html);
    }

    public function test_consultar_individual_responde_cambio_estado(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        $nota = Nota::query()->create([
            'nronota' => 931,
            'descripcion' => 'Consulta individual cambio',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '931-1-COT26',
            'nota_softland' => 93100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => 931,
            'codigo_proceso' => '931-1-COT26',
            'estado_mp_codigo' => 'proveedor_seleccionado',
            'estado_mp_glosa' => 'Proveedor seleccionado',
            'resultado_propio' => 'pendiente',
            'finalizado' => false,
            'ultimo_consultado_en' => now()->subDay(),
        ]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/931-1-COT26' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'codigo' => '931-1-COT26',
                    'estado' => ['codigo' => 'proveedor_seleccionado', 'glosa' => 'Proveedor seleccionado'],
                    'id_orden_compra' => 55070998,
                    'institucion' => ['organismo_comprador' => 'Municipalidad'],
                    'fechas' => [
                        'fecha_publicacion' => '2026-05-01 10:00',
                        'fecha_cierre' => '2026-05-10 09:00',
                        'fecha_ultimo_cambio' => '2026-05-11 11:00',
                        'fecha_cancelacion' => null,
                    ],
                    'convocatoria' => [
                        'estado_convocatoria' => 1,
                        'descripcion' => 'Primer llamado',
                        'fecha_cierre_primer_llamado' => '2026-05-10 09:00',
                        'fecha_cierre_segundo_llamado' => null,
                    ],
                    'proveedores_cotizando' => [
                        [
                            'id_cotizacion' => 1,
                            'rut_proveedor' => '76.779.675-7',
                            'razon_social' => 'INTEGRAMUNDO SPA',
                            'proveedor_seleccionado' => 1,
                            'activo' => 1,
                            'id_oc' => 55070998,
                            'monto_total' => 120000,
                            'productos_cotizados' => [],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.consultar-individual', ['nronota' => $nota->nronota]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('resultado.cambio', true)
            ->assertJsonPath('resultado.resultado_anterior', 'pendiente')
            ->assertJsonPath('resultado.resultado_propio', 'cerrada')
            ->assertJsonPath('resultado.estado_anterior', 'proveedor_seleccionado');
    }

    public function test_consultar_individual_actualiza_seguimiento(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        $nota = Nota::query()->create([
            'nronota' => 930,
            'descripcion' => 'Consulta individual',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '930-1-COT26',
            'nota_softland' => 93000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => 930,
            'codigo_proceso' => '930-1-COT26',
            'estado_mp_codigo' => 'publicada',
            'estado_mp_glosa' => 'Publicada',
            'resultado_propio' => 'pendiente',
            'finalizado' => false,
            'ultimo_consultado_en' => now()->subDay(),
        ]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/930-1-COT26' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'codigo' => '930-1-COT26',
                    'estado' => ['codigo' => 'proveedor_seleccionado', 'glosa' => 'Proveedor seleccionado'],
                    'id_orden_compra' => 55070999,
                    'institucion' => ['organismo_comprador' => 'Municipalidad'],
                    'fechas' => [
                        'fecha_publicacion' => '2026-05-01 10:00',
                        'fecha_cierre' => '2026-05-10 09:00',
                        'fecha_ultimo_cambio' => '2026-05-11 11:00',
                        'fecha_cancelacion' => null,
                    ],
                    'convocatoria' => [
                        'estado_convocatoria' => 1,
                        'descripcion' => 'Primer llamado',
                        'fecha_cierre_primer_llamado' => '2026-05-10 09:00',
                        'fecha_cierre_segundo_llamado' => null,
                    ],
                    'proveedores_cotizando' => [
                        [
                            'id_cotizacion' => 1,
                            'rut_proveedor' => '76.779.675-7',
                            'razon_social' => 'INTEGRAMUNDO SPA',
                            'proveedor_seleccionado' => 1,
                            'activo' => 1,
                            'id_oc' => 55070999,
                            'monto_total' => 120000,
                            'productos_cotizados' => [],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.consultar-individual', ['nronota' => $nota->nronota]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('resultado.resultado_propio', 'cerrada');

        $this->assertDatabaseHas('nota_mp_seguimientos', [
            'nronota' => $nota->nronota,
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);
    }

    public function test_consultar_individual_permitido_con_corrida_masiva_en_curso(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now()->subMinutes(5),
            'estado' => 'running',
            'total_notas' => 50,
            'notas_procesadas' => 10,
            'notas_con_cambio' => 2,
            'pendientes_json' => [
                ['nronota' => 900, 'codigo' => '900-1-COT26'],
                ['nronota' => 901, 'codigo' => '901-1-COT26'],
            ],
        ]);

        $nota = Nota::query()->create([
            'nronota' => 932,
            'descripcion' => 'Consulta individual con corrida masiva',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '932-1-COT26',
            'nota_softland' => 93200,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => 932,
            'codigo_proceso' => '932-1-COT26',
            'estado_mp_codigo' => 'publicada',
            'estado_mp_glosa' => 'Publicada',
            'resultado_propio' => 'pendiente',
            'finalizado' => false,
            'ultimo_consultado_en' => now()->subDay(),
        ]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/932-1-COT26' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'codigo' => '932-1-COT26',
                    'estado' => ['codigo' => 'cerrada', 'glosa' => 'Cerrada'],
                    'id_orden_compra' => null,
                    'institucion' => ['organismo_comprador' => 'Ejercito'],
                    'fechas' => [
                        'fecha_publicacion' => '2026-07-01 10:00',
                        'fecha_cierre' => '2026-07-05 09:00',
                        'fecha_ultimo_cambio' => '2026-07-04 17:05',
                        'fecha_cancelacion' => null,
                    ],
                    'convocatoria' => [
                        'estado_convocatoria' => 1,
                        'descripcion' => 'Primer llamado',
                        'fecha_cierre_primer_llamado' => '2026-07-05 09:00',
                        'fecha_cierre_segundo_llamado' => null,
                    ],
                    'proveedores_cotizando' => [],
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.consultar-individual', ['nronota' => $nota->nronota]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $corridaMasiva = $this->app->make(NotaMpResultadosService::class)->corridaEnCurso();
        $this->assertNotNull($corridaMasiva);
        $this->assertSame(50, (int) $corridaMasiva->total_notas);
        $this->assertSame(10, (int) $corridaMasiva->notas_procesadas);

        $this->assertDatabaseHas('nota_mp_corridas', [
            'estado' => 'running',
            'total_notas' => 50,
        ]);
        $this->assertDatabaseHas('nota_mp_corridas', [
            'estado' => 'ok',
            'total_notas' => 1,
            'notas_procesadas' => 1,
        ]);
    }
}
