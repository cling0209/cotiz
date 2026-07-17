<?php

namespace Tests\Feature;

use App\Jobs\ProcessOportunidadBusquedaJob;
use App\Models\OportunidadBusquedaCorrida;
use App\Models\OportunidadEncontrada;
use App\Models\OportunidadPalabraClave;
use App\Models\User;
use App\Services\OportunidadBusquedaService;
use App\Services\OportunidadParaCotizarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OportunidadParaCotizarBusquedaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
        ]);
    }

    public function test_estado_informa_siguiente_fecha_pendiente_para_refrescar_catch_up(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-14',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00', 'America/Santiago'));

        $corrida = OportunidadBusquedaCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-14',
            'inicio' => now()->subMinute(),
            'fin' => now(),
            'estado' => OportunidadBusquedaService::ESTADO_COMPLETED,
            'total_pasos' => 0,
            'pasos_procesados' => 0,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => 0,
            'plan_json' => [],
            'errores_json' => [],
            'mensaje' => 'Búsqueda terminada correctamente.',
        ]);

        $estado = $this->app->make(OportunidadBusquedaService::class)->estado($corrida);

        $this->assertSame('2026-07-15', $estado['fecha_siguiente_pendiente']);

        Carbon::setTestNow();
    }

    public function test_paso_devuelve_solo_publicadas_hoy(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'America/Santiago'));

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil*' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'items' => [
                        [
                            'codigo' => '1000-1-COT26',
                            'nombre' => 'Servicio de aseo industrial',
                            'fechas' => [
                                'fecha_publicacion' => '2026-07-15T09:00:00-04:00',
                                'fecha_cierre' => '2026-07-16T18:00:00-04:00',
                            ],
                            'montos' => ['monto_disponible_clp' => 500000],
                            'institucion' => ['region' => 13, 'comuna' => 'Santiago'],
                        ],
                        [
                            'codigo' => '1000-2-COT26',
                            'nombre' => 'Ayer aseo',
                            'fechas' => [
                                'fecha_publicacion' => '2026-07-14T09:00:00-04:00',
                                'fecha_cierre' => '2026-07-16T18:00:00-04:00',
                            ],
                            'montos' => ['monto_disponible_clp' => 900000],
                            'institucion' => ['region' => 13, 'comuna' => 'Santiago'],
                        ],
                        [
                            'codigo' => '1000-3-COT26',
                            'nombre' => 'Bomba sumergible y turbo calefactor',
                            'fechas' => [
                                'fecha_publicacion' => '2026-07-15T10:00:00-04:00',
                                'fecha_cierre' => '2026-07-16T18:00:00-04:00',
                            ],
                            'montos' => ['monto_disponible_clp' => 700000],
                            'institucion' => ['region' => 13, 'comuna' => 'Santiago'],
                        ],
                    ],
                    'productos_solicitados' => [
                        ['id' => 1],
                        ['id' => 2],
                    ],
                    'paginacion' => [],
                ],
            ], 200),
        ]);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'aseo',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.paso'), [
                'frase' => 'aseo',
                'region' => 13,
                'indice' => 0,
                'total_pasos' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('terminado', true)
            ->assertJsonCount(1, 'nuevos')
            ->assertJsonPath('nuevos.0.codigo', '1000-1-COT26')
            ->assertJsonPath('nuevos.0.cantidad_productos', 2)
            ->assertJsonPath('fin_label', now()->format('H:i:s'))
            ->assertJsonPath('consulta.metodo', 'GET')
            ->assertJsonPath('consulta.parametros.q', 'aseo')
            ->assertJsonPath('consulta.parametros.region', 13)
            ->assertJsonPath('consulta.parametros.estado', 'publicada')
            ->assertJsonPath('consulta.total_api', 3)
            ->assertJsonPath('consulta.total_publicadas_hoy', 1)
            ->assertJsonPath('guardadas', 1);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'compra-agil')
                && str_contains($request->url(), 'cambio_desde=')
                && str_contains($request->url(), 'cambio_hasta=')
                && str_contains(urldecode($request->url()), '2026-07-15');
        });

        $this->assertDatabaseHas('oportunidad_encontradas', [
            'codigo' => '1000-1-COT26',
            'fecha_busqueda' => '2026-07-15',
            'cantidad_productos' => 2,
        ]);
        $this->assertDatabaseMissing('oportunidad_encontradas', [
            'codigo' => '1000-2-COT26',
        ]);
        $this->assertDatabaseMissing('oportunidad_encontradas', [
            'codigo' => '1000-3-COT26',
        ]);

        Carbon::setTestNow();
    }

    public function test_paso_error_incluye_consulta_debug(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13],
        ]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil*' => Http::response([], 503),
        ]);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.paso'), [
                'frase' => 'aseo',
                'region' => 13,
            ])
            ->assertStatus(502)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('consulta.metodo', 'GET')
            ->assertJsonPath('consulta.parametros.q', 'aseo')
            ->assertJsonPath('consulta.parametros.region', 13);
    }

    public function test_iniciar_requiere_palabras(): void
    {
        config(['cotiz.mercadopublico.ticket' => 'ticket-test']);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.iniciar'))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_iniciar_ordena_pasos_por_region(): void
    {
        Queue::fake();
        config([
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.regiones' => [13, 5],
        ]);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'papel',
            'orden' => 1,
            'created_by' => $user->id,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'aseo',
            'orden' => 2,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.iniciar'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('corrida.total_pasos', 2)
            ->assertJsonPath('corrida.estado', 'running');

        $corrida = OportunidadBusquedaCorrida::query()->firstOrFail();
        $this->assertSame(13, $corrida->plan_json[0]['region']);
        $this->assertSame('(todas)', $corrida->plan_json[0]['frase']);
        $this->assertSame(5, $corrida->plan_json[1]['region']);
        $this->assertSame('(todas)', $corrida->plan_json[1]['frase']);

        Queue::assertPushed(ProcessOportunidadBusquedaJob::class, fn ($job) => $job->corridaId === $corrida->id);

        $this->actingAs($user)
            ->getJson(route('admin.oportunidades.para-cotizar.estado'))
            ->assertOk()
            ->assertJsonPath('corrida.id', $corrida->id)
            ->assertJsonPath('corrida.progreso', 0);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.cancelar'))
            ->assertOk()
            ->assertJsonPath('corrida.estado', OportunidadBusquedaService::ESTADO_CANCELLED)
            ->assertJsonPath('corrida.pasos_resumen.0.resultado', 'cancelado')
            ->assertJsonPath('corrida.pasos_resumen.1.resultado', 'cancelado');
    }

    public function test_corrida_reintenta_fallidos_de_region_antes_de_seguir(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13, 5],
            'cotiz.mercadopublico.api_reintentos_http' => 1,
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-16',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00', 'America/Santiago'));

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'papel',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        $llamadasRegion13 = 0;
        Http::fake(function ($request) use (&$llamadasRegion13) {
            $region = (int) ($request->data()['region'] ?? 0);
            if ($region === 13) {
                $llamadasRegion13++;

                return Http::response([], 503);
            }

            return Http::response([
                'success' => 'OK',
                'payload' => ['items' => [], 'paginacion' => []],
            ]);
        });

        Queue::fake();
        $servicio = $this->app->make(OportunidadBusquedaService::class);
        $corrida = $servicio->iniciar('admin');
        $servicio->procesar($corrida);
        $corrida->refresh();

        // Región 13: intento + reintento; luego región 5.
        $this->assertSame(2, $llamadasRegion13);
        $this->assertSame(OportunidadBusquedaService::ESTADO_COMPLETED, $corrida->estado);
        $this->assertSame(3, $corrida->pasos_procesados);
        $this->assertSame(1, $corrida->pasos_fallidos);
        $this->assertSame('retry_failed', $corrida->plan_json[0]['estado']);
        $this->assertSame('ok', $corrida->plan_json[1]['estado']);
        $this->assertCount(2, $corrida->errores_json);

        $estado = $servicio->estado($corrida);
        $this->assertSame('fallo_definitivo', $estado['pasos_resumen'][0]['resultado']);
        $this->assertNotNull($estado['pasos_resumen'][0]['error']);
        $this->assertSame(0, $estado['pasos_resumen'][0]['encontradas']);
        $this->assertIsInt($estado['pasos_resumen'][0]['duracion_segundos']);
        $this->assertNotNull($estado['pasos_resumen'][0]['duracion_texto']);
        $this->assertIsArray($estado['pasos_resumen'][0]['consulta']);
        $this->assertArrayHasKey('url_completa', $estado['pasos_resumen'][0]['consulta']);
        $this->assertSame('ok', $estado['pasos_resumen'][1]['resultado']);
        $this->assertSame('OK (1.er intento)', $estado['pasos_resumen'][1]['etiqueta']);
        $this->assertSame(0, $estado['pasos_resumen'][1]['encontradas']);
        $this->assertIsInt($estado['pasos_resumen'][1]['duracion_segundos']);
        $this->assertNotNull($estado['pasos_resumen'][1]['duracion_texto']);
        $this->assertIsArray($estado['pasos_resumen'][1]['consulta']);
        $this->assertIsArray($estado['ultima_consulta']);

        Carbon::setTestNow();
    }

    public function test_reintento_de_region_puede_recuperar_paso_fallido(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13],
            'cotiz.mercadopublico.api_reintentos_http' => 1,
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-16',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00', 'America/Santiago'));

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'papel',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        $llamadas = 0;
        Http::fake(function () use (&$llamadas) {
            $llamadas++;
            if ($llamadas === 1) {
                return Http::response([], 503);
            }

            return Http::response([
                'success' => 'OK',
                'payload' => ['items' => [], 'paginacion' => []],
            ]);
        });

        Queue::fake();
        $servicio = $this->app->make(OportunidadBusquedaService::class);
        $corrida = $servicio->iniciar('admin');
        $servicio->procesar($corrida);
        $corrida->refresh();

        $this->assertSame(2, $llamadas);
        $this->assertSame(OportunidadBusquedaService::ESTADO_COMPLETED, $corrida->estado);
        $this->assertSame(0, $corrida->pasos_fallidos);
        $this->assertSame('ok', $corrida->plan_json[0]['estado']);
        $this->assertSame(2, $corrida->plan_json[0]['intentos']);
        $this->assertSame(0, $corrida->plan_json[0]['encontradas']);

        $estado = $servicio->estado($corrida);
        $this->assertSame('2026-07-16', $estado['fecha_busqueda']);
        $this->assertCount(1, $estado['pasos_resumen']);
        $this->assertSame('ok_reintento', $estado['pasos_resumen'][0]['resultado']);
        $this->assertSame('OK (reintento)', $estado['pasos_resumen'][0]['etiqueta']);
        $this->assertSame('2026-07-16', $estado['pasos_resumen'][0]['fecha_busqueda']);
        $this->assertSame(13, $estado['pasos_resumen'][0]['region']);
        $this->assertSame(0, $estado['pasos_resumen'][0]['encontradas']);
        $this->assertIsInt($estado['pasos_resumen'][0]['duracion_segundos']);
        $this->assertNotNull($estado['pasos_resumen'][0]['duracion_texto']);

        Carbon::setTestNow();
    }

    public function test_paso_omite_codigos_ya_en_lista(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'America/Santiago'));

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil*' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'items' => [
                        [
                            'codigo' => '1000-1-COT26',
                            'nombre' => 'Compra de papel bond ya listada',
                            'fechas' => [
                                'fecha_publicacion' => '2026-07-15T09:00:00-04:00',
                                'fecha_cierre' => '2026-07-16T18:00:00-04:00',
                            ],
                            'montos' => ['monto_disponible_clp' => 500000],
                            'institucion' => ['region' => 13, 'comuna' => 'Santiago'],
                        ],
                        [
                            'codigo' => '1000-3-COT26',
                            'nombre' => 'Papel oficio nueva compra',
                            'fechas' => [
                                'fecha_publicacion' => '2026-07-15T10:00:00-04:00',
                                'fecha_cierre' => '2026-07-16T18:00:00-04:00',
                            ],
                            'montos' => ['monto_disponible_clp' => 300000],
                            'institucion' => ['region' => 13, 'comuna' => 'Santiago'],
                        ],
                    ],
                    'paginacion' => [],
                ],
            ], 200),
        ]);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.paso'), [
                'frase' => 'papel',
                'region' => 13,
                'codigos_excluidos' => ['1000-1-COT26'],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'nuevos')
            ->assertJsonPath('nuevos.0.codigo', '1000-3-COT26');

        Carbon::setTestNow();
    }

    public function test_estado_reencola_corrida_colgada_sin_job(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
            'cotiz.mercadopublico.oportunidad_corrida_stalled_segundos' => 60,
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-16',
            'queue.default' => 'database',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00', 'America/Santiago'));
        Queue::fake();

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $corrida = OportunidadBusquedaCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now()->subHours(2),
            'estado' => OportunidadBusquedaService::ESTADO_RUNNING,
            'total_pasos' => 2,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => 5,
            'plan_json' => [
                ['frase' => '(todas)', 'region' => 13, 'estado' => 'ok', 'intentos' => 1, 'encontradas' => 5],
                ['frase' => '(todas)', 'region' => 5, 'estado' => 'pending', 'intentos' => 0],
            ],
            'errores_json' => [],
            'mensaje' => 'Paso región 13: 5 cotización(es) (1/2).',
        ]);
        OportunidadBusquedaCorrida::query()->whereKey($corrida->id)->update([
            'updated_at' => now()->subMinutes(5),
        ]);
        $corrida->refresh();

        $this->actingAs($user)
            ->getJson(route('admin.oportunidades.para-cotizar.estado'))
            ->assertOk()
            ->assertJsonPath('corrida.id', $corrida->id)
            ->assertJsonPath('corrida.reanudada_auto', true)
            ->assertJsonPath('corrida.worker_stalled', false);

        Queue::assertPushed(ProcessOportunidadBusquedaJob::class, fn ($job) => $job->corridaId === $corrida->id);

        $corrida->refresh();
        $this->assertStringContainsString('retomada automáticamente', (string) $corrida->mensaje);

        Carbon::setTestNow();
    }

    public function test_reanudar_endpoint_encola_si_no_hay_job(): void
    {
        config([
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
            'queue.default' => 'database',
        ]);
        Queue::fake();

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $corrida = OportunidadBusquedaCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now(),
            'estado' => OportunidadBusquedaService::ESTADO_RUNNING,
            'total_pasos' => 1,
            'pasos_procesados' => 0,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => 0,
            'plan_json' => [
                ['frase' => '(todas)', 'region' => 13, 'estado' => 'pending', 'intentos' => 0],
            ],
            'errores_json' => [],
            'mensaje' => 'Búsqueda encolada.',
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.reanudar'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('corrida.id', $corrida->id);

        Queue::assertPushed(ProcessOportunidadBusquedaJob::class, fn ($job) => $job->corridaId === $corrida->id);
    }

    public function test_segunda_corrida_del_dia_es_incremental_desde_ultima_publicacion(): void
    {
        Queue::fake();
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.regiones' => [13],
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-17',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-17 19:00:00', 'America/Santiago'));

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'escritorio',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        OportunidadBusquedaCorrida::query()->create([
            'usuario' => 'sistema',
            'fecha_busqueda' => '2026-07-17',
            'inicio' => Carbon::parse('2026-07-17 10:00:00', 'America/Santiago'),
            'fin' => Carbon::parse('2026-07-17 10:30:00', 'America/Santiago'),
            'estado' => OportunidadBusquedaService::ESTADO_COMPLETED,
            'total_pasos' => 1,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => 1,
            'plan_json' => [],
            'errores_json' => [],
            'mensaje' => 'Búsqueda terminada correctamente.',
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => '517-148-COT26',
            'nombre' => 'Sillas',
            'organismo' => 'SAG',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'monto_presupuesto_clp' => 488733,
            'fecha_publicacion' => Carbon::parse('2026-07-17 10:17:00', 'America/Santiago'),
            'fecha_cierre' => Carbon::parse('2026-07-20 11:00:00', 'America/Santiago'),
            'palabras_coinciden' => ['escritorio'],
            'fecha_busqueda' => '2026-07-17',
            'indice_region_config' => 0,
        ]);

        $servicio = $this->app->make(OportunidadParaCotizarService::class);
        $ventana = $servicio->ventanaCambioParaDia(
            '2026-07-17',
            Carbon::parse('2026-07-17 10:17:00', 'America/Santiago')->toIso8601String(),
        );
        $this->assertNotNull($ventana);
        $this->assertSame(
            Carbon::parse('2026-07-17 10:17:00', 'America/Santiago')->toIso8601String(),
            $ventana['desde'],
        );

        $corrida = $this->app->make(OportunidadBusquedaService::class)->iniciar('sistema');

        $this->assertSame('2026-07-17', $corrida->fecha_busqueda->toDateString());
        $this->assertTrue((bool) ($corrida->plan_json[0]['incremental'] ?? false));
        $this->assertNotEmpty($corrida->plan_json[0]['cambio_desde'] ?? null);
        $this->assertStringContainsString('incremental', (string) $corrida->mensaje);

        $cambioDesde = Carbon::parse((string) $corrida->plan_json[0]['cambio_desde'])
            ->timezone('America/Santiago');
        $this->assertSame('2026-07-17 10:17:00', $cambioDesde->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }
}
