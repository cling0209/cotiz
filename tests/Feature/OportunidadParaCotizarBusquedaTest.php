<?php

namespace Tests\Feature;

use App\Jobs\ProcessOportunidadBusquedaJob;
use App\Models\NotaMpCorrida;
use App\Models\OportunidadBusquedaCorrida;
use App\Models\OportunidadEncontrada;
use App\Models\OportunidadPalabraClave;
use App\Models\User;
use App\Services\OportunidadBusquedaService;
use App\Services\OportunidadParaCotizarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    public function test_iniciar_busqueda_cancela_cambios_de_estado_en_curso(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13],
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-16',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00', 'America/Santiago'));
        Queue::fake();

        $estados = NotaMpCorrida::query()->create([
            'usuario' => 'sistema',
            'inicio' => now()->subMinutes(20),
            'estado' => 'running',
            'total_notas' => 2,
            'notas_procesadas' => 0,
            'pendientes_json' => [
                ['nronota' => 1, 'codigo' => '1-1-COT26'],
                ['nronota' => 2, 'codigo' => '2-1-COT26'],
            ],
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

        $corrida = $this->app->make(OportunidadBusquedaService::class)->iniciar('admin');

        $estados->refresh();
        $this->assertSame('cancelled', $estados->estado);
        $this->assertStringContainsString('búsqueda de cotizaciones', (string) $estados->mensaje);
        $this->assertSame(OportunidadBusquedaService::ESTADO_RUNNING, $corrida->estado);

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
        $this->assertNotNull($estado['pasos_resumen'][0]['cambio_desde_texto']);
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

    public function test_estado_expone_esperando_worker_con_job_en_cola_sin_reservar(): void
    {
        config([
            'queue.default' => 'database',
            'cotiz.mercadopublico.oportunidad_corrida_stalled_segundos' => 60,
        ]);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $corrida = OportunidadBusquedaCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now()->subMinutes(10),
            'estado' => OportunidadBusquedaService::ESTADO_RUNNING,
            'total_pasos' => 1,
            'pasos_procesados' => 0,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => 0,
            'plan_json' => [
                ['frase' => '(todas)', 'region' => 13, 'estado' => 'pending', 'intentos' => 0],
            ],
            'errores_json' => [],
            'mensaje' => 'Búsqueda encolada. Esperando worker…',
        ]);
        OportunidadBusquedaCorrida::query()->whereKey($corrida->id)->update([
            'updated_at' => now()->subMinutes(3),
        ]);

        ProcessOportunidadBusquedaJob::dispatch($corrida->id);

        $this->actingAs($user)
            ->getJson(route('admin.oportunidades.para-cotizar.estado'))
            ->assertOk()
            ->assertJsonPath('corrida.id', $corrida->id)
            ->assertJsonPath('corrida.esperando_worker', true);

        $servicio = $this->app->make(OportunidadBusquedaService::class);
        $this->assertTrue($servicio->corridaEsperandoWorker($corrida->fresh()));
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

        $estadoEncolada = $this->app->make(OportunidadBusquedaService::class)->estado($corrida);
        $this->assertTrue($estadoEncolada['pasos_resumen'][0]['consulta_incremental']);
        $this->assertNotNull($estadoEncolada['pasos_resumen'][0]['cambio_desde_texto']);
        $this->assertStringContainsString('10:18', $estadoEncolada['pasos_resumen'][0]['cambio_desde_texto']);

        $cambioDesde = Carbon::parse((string) $corrida->plan_json[0]['cambio_desde'])
            ->timezone('America/Santiago');
        // Última Pub. 10:17 → cambio_desde = minuto siguiente.
        $this->assertSame('2026-07-17 10:18:00', $cambioDesde->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_segunda_corrida_usa_ultimo_cambio_visto_por_region_no_ultima_publicacion(): void
    {
        Queue::fake();
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.regiones' => [13],
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-08-21',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-21 19:00:00', 'America/Santiago'));

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
            'fecha_busqueda' => '2026-08-21',
            'inicio' => Carbon::parse('2026-08-21 10:00:00', 'America/Santiago'),
            'fin' => Carbon::parse('2026-08-21 10:30:00', 'America/Santiago'),
            'estado' => OportunidadBusquedaService::ESTADO_COMPLETED,
            'total_pasos' => 1,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => 1,
            'plan_json' => [
                [
                    'frase' => '(todas)',
                    'region' => 13,
                    'region_nombre' => 'Metropolitana',
                    'estado' => 'ok',
                    'intentos' => 1,
                    'encontradas' => 1,
                    'tomado_at' => Carbon::parse('2026-08-21 10:28:00', 'America/Santiago')->toIso8601String(),
                    'ultimo_cambio_visto' => Carbon::parse('2026-08-21 10:20:00', 'America/Santiago')->toIso8601String(),
                ],
            ],
            'errores_json' => [],
            'mensaje' => 'Búsqueda terminada correctamente.',
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => '900-1-COT26',
            'nombre' => 'Oficina tarde',
            'organismo' => 'SAG',
            'region' => 5,
            'nombre_region' => 'Valparaíso',
            'monto_presupuesto_clp' => 100000,
            'fecha_publicacion' => Carbon::parse('2026-08-21 18:00:00', 'America/Santiago'),
            'fecha_cierre' => Carbon::parse('2026-08-24 11:00:00', 'America/Santiago'),
            'palabras_coinciden' => ['escritorio'],
            'fecha_busqueda' => '2026-08-21',
            'indice_region_config' => 1,
        ]);

        $corrida = $this->app->make(OportunidadBusquedaService::class)->iniciar('sistema');

        $this->assertTrue((bool) ($corrida->plan_json[0]['incremental'] ?? false));
        $cambioDesde = Carbon::parse((string) $corrida->plan_json[0]['cambio_desde'])
            ->timezone('America/Santiago');
        // Último cambio visto 10:20 → +1 min. No usar la pub. 18:00 de otro match.
        $this->assertSame('2026-08-21 10:21:00', $cambioDesde->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_cursor_incremental_cae_a_tomado_at_si_no_hay_ultimo_cambio_visto(): void
    {
        Queue::fake();
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.regiones' => [13],
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-08-21',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-21 19:00:00', 'America/Santiago'));

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
            'fecha_busqueda' => '2026-08-21',
            'inicio' => Carbon::parse('2026-08-21 10:00:00', 'America/Santiago'),
            'fin' => Carbon::parse('2026-08-21 10:30:00', 'America/Santiago'),
            'estado' => OportunidadBusquedaService::ESTADO_COMPLETED,
            'total_pasos' => 1,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => 0,
            'plan_json' => [
                [
                    'frase' => '(todas)',
                    'region' => 13,
                    'region_nombre' => 'Metropolitana',
                    'estado' => 'ok',
                    'intentos' => 1,
                    'encontradas' => 0,
                    'tomado_at' => Carbon::parse('2026-08-21 10:28:00', 'America/Santiago')->toIso8601String(),
                ],
            ],
            'errores_json' => [],
            'mensaje' => 'Búsqueda terminada correctamente.',
        ]);

        $corrida = $this->app->make(OportunidadBusquedaService::class)->iniciar('sistema');

        $cambioDesde = Carbon::parse((string) $corrida->plan_json[0]['cambio_desde'])
            ->timezone('America/Santiago');
        $this->assertSame('2026-08-21 10:29:00', $cambioDesde->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_cambio_desde_incremental_usa_ultima_pub_de_dia_anterior_mas_un_minuto(): void
    {
        Queue::fake();
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.regiones' => [13],
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-31',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-03 19:03:00', 'America/Santiago'));

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'escritorio',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => '517-999-COT26',
            'nombre' => 'Mesas',
            'organismo' => 'SAG',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'monto_presupuesto_clp' => 100000,
            'fecha_publicacion' => Carbon::parse('2026-07-31 17:35:00', 'America/Santiago'),
            'fecha_cierre' => Carbon::parse('2026-08-10 11:00:00', 'America/Santiago'),
            'palabras_coinciden' => ['escritorio'],
            'fecha_busqueda' => '2026-07-31',
            'indice_region_config' => 0,
        ]);

        $servicio = $this->app->make(OportunidadParaCotizarService::class);
        $ventana = $servicio->ventanaCambioParaDia(
            '2026-08-03',
            Carbon::parse('2026-07-31 17:36:00', 'America/Santiago')->toIso8601String(),
        );
        $this->assertNotNull($ventana);
        $this->assertSame(
            Carbon::parse('2026-07-31 17:36:00', 'America/Santiago')->toIso8601String(),
            $ventana['desde'],
        );
        $this->assertSame(
            Carbon::parse('2026-08-03', 'America/Santiago')->endOfDay()->toIso8601String(),
            $ventana['hasta'],
        );

        $corrida = $this->app->make(OportunidadBusquedaService::class)->iniciar('sistema', '2026-08-03');

        $this->assertSame('2026-08-03', $corrida->fecha_busqueda->toDateString());
        $this->assertTrue((bool) ($corrida->plan_json[0]['incremental'] ?? false));

        $cambioDesde = Carbon::parse((string) $corrida->plan_json[0]['cambio_desde'])
            ->timezone('America/Santiago');
        $this->assertSame('2026-07-31 17:36:00', $cambioDesde->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_siguiente_corrida_reintenta_regiones_fallidas_antes_del_incremental(): void
    {
        Queue::fake();
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.regiones' => [13, 10],
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-17',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-17 13:00:00', 'America/Santiago'));

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'oficina',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        OportunidadBusquedaCorrida::query()->create([
            'usuario' => 'sistema',
            'fecha_busqueda' => '2026-07-17',
            'inicio' => Carbon::parse('2026-07-17 09:00:00', 'America/Santiago'),
            'fin' => Carbon::parse('2026-07-17 10:00:00', 'America/Santiago'),
            'estado' => OportunidadBusquedaService::ESTADO_COMPLETED,
            'total_pasos' => 2,
            'pasos_procesados' => 2,
            'pasos_fallidos' => 1,
            'oportunidades_encontradas' => 1,
            'plan_json' => [
                [
                    'frase' => '(todas)',
                    'region' => 13,
                    'region_nombre' => 'Metropolitana',
                    'estado' => 'ok',
                    'intentos' => 1,
                    'encontradas' => 1,
                ],
                [
                    'frase' => '(todas)',
                    'region' => 10,
                    'region_nombre' => 'Los Lagos',
                    'estado' => 'retry_failed',
                    'intentos' => 2,
                    'encontradas' => 0,
                ],
            ],
            'errores_json' => [],
            'mensaje' => 'Búsqueda terminada con 1 paso(s) fallido(s).',
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => '517-148-COT26',
            'nombre' => 'Oficina',
            'organismo' => 'SAG',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'monto_presupuesto_clp' => 100000,
            'fecha_publicacion' => Carbon::parse('2026-07-17 09:30:00', 'America/Santiago'),
            'fecha_cierre' => Carbon::parse('2026-07-20 11:00:00', 'America/Santiago'),
            'palabras_coinciden' => ['oficina'],
            'fecha_busqueda' => '2026-07-17',
            'indice_region_config' => 0,
        ]);

        $corrida = $this->app->make(OportunidadBusquedaService::class)->iniciar('sistema');

        $this->assertCount(2, $corrida->plan_json);
        $this->assertSame(10, (int) ($corrida->plan_json[0]['region'] ?? 0));
        $this->assertTrue((bool) ($corrida->plan_json[0]['reintento_fallo_previo'] ?? false));
        $this->assertFalse((bool) ($corrida->plan_json[0]['incremental'] ?? false));
        $this->assertArrayNotHasKey('cambio_desde', $corrida->plan_json[0]);

        $this->assertSame(13, (int) ($corrida->plan_json[1]['region'] ?? 0));
        $this->assertTrue((bool) ($corrida->plan_json[1]['incremental'] ?? false));
        $this->assertNotEmpty($corrida->plan_json[1]['cambio_desde'] ?? null);
        $this->assertStringContainsString('Reintento completo', (string) $corrida->mensaje);

        Carbon::setTestNow();
    }

    public function test_timeout_mp_reintenta_la_misma_pagina_antes_de_saltar(): void
    {
        Queue::fake();
        Cache::flush();
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13],
            'cotiz.mercadopublico.api_reintentos_http' => 1,
            'cotiz.mercadopublico.oportunidad_pagina_reintentos' => 1,
            'cotiz.mercadopublico.oportunidad_pagina_reintento_seg' => 8,
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-16',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00', 'America/Santiago'));

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'oficina',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        $llamadasPagina2 = 0;
        Http::fake(function ($request) use (&$llamadasPagina2) {
            $pagina = (int) ($request->data()['numero_pagina'] ?? 1);
            if ($pagina === 1) {
                return Http::response($this->payloadPaginaLlenaMp(13, '2026-07-16'), 200);
            }
            if ($pagina === 2) {
                $llamadasPagina2++;

                return Http::response('timeout', 504);
            }

            return Http::response($this->payloadPaginaVaciaMp(), 200);
        });

        $servicio = $this->app->make(OportunidadBusquedaService::class);
        $corrida = $servicio->iniciar('admin');
        $this->assertTrue($servicio->procesarPaso($corrida));
        $corrida->refresh();
        $this->assertTrue($servicio->procesarPaso($corrida));
        $corrida->refresh();

        $paso = $corrida->plan_json[0];
        $this->assertSame(1, $llamadasPagina2);
        $this->assertSame(2, (int) ($paso['siguiente_pagina'] ?? 0));
        $this->assertSame(1, (int) ($paso['reintentos_pagina'] ?? 0));
        $this->assertSame(8, $servicio->delayReencoladoSegundos($corrida));
        $this->assertSame('running', $paso['estado'] ?? null);

        $servicio->procesar($corrida);
        $corrida->refresh();

        $this->assertSame(2, $llamadasPagina2);
        $this->assertSame(OportunidadBusquedaService::ESTADO_COMPLETED, $corrida->estado);
        $this->assertSame([2], $corrida->plan_json[0]['paginas_omitidas'] ?? null);
        $this->assertSame('ok', $corrida->plan_json[0]['estado'] ?? null);

        $estado = $servicio->estado($corrida);
        $this->assertSame('ok_con_hueco', $estado['pasos_resumen'][0]['resultado']);
        $this->assertSame([2], $estado['pasos_resumen'][0]['paginas_omitidas']);

        $siguiente = $servicio->iniciar('sistema');
        $this->assertTrue((bool) ($siguiente->plan_json[0]['reintento_fallo_previo'] ?? false));
        $this->assertFalse((bool) ($siguiente->plan_json[0]['incremental'] ?? false));
        $this->assertArrayNotHasKey('cambio_desde', $siguiente->plan_json[0]);
        $this->assertStringContainsString('Reintento completo', (string) $siguiente->mensaje);

        Carbon::setTestNow();
    }

    public function test_timeout_mp_recuperado_en_reintento_no_omite_la_pagina(): void
    {
        Queue::fake();
        Cache::flush();
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13],
            'cotiz.mercadopublico.api_reintentos_http' => 1,
            'cotiz.mercadopublico.oportunidad_pagina_reintentos' => 1,
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-16',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00', 'America/Santiago'));

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'oficina',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        $llamadasPagina2 = 0;
        Http::fake(function ($request) use (&$llamadasPagina2) {
            $pagina = (int) ($request->data()['numero_pagina'] ?? 1);
            if ($pagina === 1) {
                return Http::response($this->payloadPaginaLlenaMp(13, '2026-07-16'), 200);
            }
            if ($pagina === 2) {
                $llamadasPagina2++;
                if ($llamadasPagina2 === 1) {
                    return Http::response('timeout', 504);
                }

                return Http::response($this->payloadPaginaVaciaMp(), 200);
            }

            return Http::response($this->payloadPaginaVaciaMp(), 200);
        });

        $servicio = $this->app->make(OportunidadBusquedaService::class);
        $corrida = $servicio->iniciar('admin');
        $servicio->procesar($corrida);
        $corrida->refresh();

        $this->assertSame(2, $llamadasPagina2);
        $this->assertSame(OportunidadBusquedaService::ESTADO_COMPLETED, $corrida->estado);
        $this->assertSame('ok', $corrida->plan_json[0]['estado'] ?? null);
        $this->assertSame([], $corrida->plan_json[0]['paginas_omitidas'] ?? []);

        $estado = $servicio->estado($corrida);
        $this->assertSame('ok', $estado['pasos_resumen'][0]['resultado']);

        $siguiente = $servicio->iniciar('sistema');
        $this->assertFalse((bool) ($siguiente->plan_json[0]['reintento_fallo_previo'] ?? false));
        $this->assertTrue((bool) ($siguiente->plan_json[0]['incremental'] ?? false));

        Carbon::setTestNow();
    }

    public function test_dia_con_fallos_sigue_pendiente_tras_avanzar_calendario(): void
    {
        Queue::fake();
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.regiones' => [13, 10],
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-08-24',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00', 'America/Santiago'));

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'oficina',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        $corrida = OportunidadBusquedaCorrida::query()->create([
            'usuario' => 'sistema',
            'fecha_busqueda' => '2026-08-24',
            'inicio' => Carbon::parse('2026-08-24 09:00:00', 'America/Santiago'),
            'fin' => Carbon::parse('2026-08-24 10:00:00', 'America/Santiago'),
            'estado' => OportunidadBusquedaService::ESTADO_COMPLETED,
            'total_pasos' => 2,
            'pasos_procesados' => 2,
            'pasos_fallidos' => 1,
            'oportunidades_encontradas' => 1,
            'plan_json' => [
                [
                    'frase' => '(todas)',
                    'region' => 13,
                    'region_nombre' => 'Metropolitana',
                    'estado' => 'ok',
                    'intentos' => 1,
                    'encontradas' => 1,
                ],
                [
                    'frase' => '(todas)',
                    'region' => 10,
                    'region_nombre' => 'Los Lagos',
                    'estado' => 'retry_failed',
                    'intentos' => 2,
                    'encontradas' => 0,
                ],
            ],
            'errores_json' => [],
            'mensaje' => 'Búsqueda terminada con 1 paso(s) fallido(s).',
        ]);

        $servicio = $this->app->make(OportunidadBusquedaService::class);
        $nueva = $servicio->iniciar('sistema');

        $this->assertSame('2026-08-24', $nueva->fecha_busqueda->toDateString());
        $this->assertNotSame($corrida->id, $nueva->id);

        $estado = $servicio->estado($corrida);
        $this->assertSame('2026-08-24', $estado['fecha_siguiente_pendiente']);

        Carbon::setTestNow();
    }

    public function test_catch_up_reintenta_dia_con_fallos_antes_de_avanzar(): void
    {
        Queue::fake();
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.regiones' => [13, 10],
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-08-24',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00', 'America/Santiago'));

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'oficina',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        OportunidadBusquedaCorrida::query()->create([
            'usuario' => 'sistema',
            'fecha_busqueda' => '2026-08-24',
            'inicio' => Carbon::parse('2026-08-24 09:00:00', 'America/Santiago'),
            'fin' => Carbon::parse('2026-08-24 10:00:00', 'America/Santiago'),
            'estado' => OportunidadBusquedaService::ESTADO_COMPLETED,
            'total_pasos' => 1,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 1,
            'oportunidades_encontradas' => 0,
            'plan_json' => [
                [
                    'frase' => '(todas)',
                    'region' => 10,
                    'region_nombre' => 'Los Lagos',
                    'estado' => 'retry_failed',
                    'intentos' => 2,
                    'encontradas' => 0,
                ],
            ],
            'errores_json' => [],
            'mensaje' => 'Búsqueda terminada con 1 paso(s) fallido(s).',
        ]);

        $servicio = $this->app->make(OportunidadBusquedaService::class);
        $servicio->continuarCatchUpTrasVinculacion('2026-08-24', 'sistema');

        $nueva = OportunidadBusquedaCorrida::query()->latest('id')->first();
        $this->assertNotNull($nueva);
        $this->assertSame('2026-08-24', $nueva->fecha_busqueda->toDateString());
        $this->assertSame(OportunidadBusquedaService::ESTADO_RUNNING, $nueva->estado);

        Carbon::setTestNow();
    }

    public function test_catch_up_avanza_al_dia_siguiente_si_busqueda_satisfactoria(): void
    {
        Queue::fake();
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.regiones' => [13],
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-08-24',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-25 10:00:00', 'America/Santiago'));

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'oficina',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        OportunidadBusquedaCorrida::query()->create([
            'usuario' => 'sistema',
            'fecha_busqueda' => '2026-08-24',
            'inicio' => Carbon::parse('2026-08-24 09:00:00', 'America/Santiago'),
            'fin' => Carbon::parse('2026-08-24 10:00:00', 'America/Santiago'),
            'estado' => OportunidadBusquedaService::ESTADO_COMPLETED,
            'total_pasos' => 1,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => 1,
            'plan_json' => [
                [
                    'frase' => '(todas)',
                    'region' => 13,
                    'region_nombre' => 'Metropolitana',
                    'estado' => 'ok',
                    'intentos' => 1,
                    'encontradas' => 1,
                ],
            ],
            'errores_json' => [],
            'mensaje' => 'Búsqueda terminada correctamente.',
        ]);

        $servicio = $this->app->make(OportunidadBusquedaService::class);
        $servicio->continuarCatchUpTrasVinculacion('2026-08-24', 'sistema');

        $nueva = OportunidadBusquedaCorrida::query()->latest('id')->first();
        $this->assertNotNull($nueva);
        $this->assertSame('2026-08-25', $nueva->fecha_busqueda->toDateString());
        $this->assertSame(OportunidadBusquedaService::ESTADO_RUNNING, $nueva->estado);

        Carbon::setTestNow();
    }

    /**
     * @return array{success: string, payload: array{items: list<array<string, mixed>>, paginacion: array<string, mixed>}}
     */
    private function payloadPaginaLlenaMp(int $region, string $dia): array
    {
        $items = [];
        for ($i = 1; $i <= OportunidadParaCotizarService::REGION_TAMANO_PAGINA; $i++) {
            $items[] = [
                'codigo' => sprintf('1000-%d-COT26', $i),
                'nombre' => 'Material de oficina '.$i,
                'fechas' => [
                    'fecha_publicacion' => $dia.'T09:00:00-04:00',
                    'fecha_cierre' => $dia.'T18:00:00-04:00',
                ],
                'montos' => ['monto_disponible_clp' => 100000],
                'institucion' => ['region' => $region, 'comuna' => 'Santiago'],
            ];
        }

        return [
            'success' => 'OK',
            'payload' => [
                'items' => $items,
                'paginacion' => [],
            ],
        ];
    }

    /**
     * @return array{success: string, payload: array{items: list<array<string, mixed>>, paginacion: array<string, mixed>}}
     */
    private function payloadPaginaVaciaMp(): array
    {
        return [
            'success' => 'OK',
            'payload' => [
                'items' => [],
                'paginacion' => [],
            ],
        ];
    }
}
