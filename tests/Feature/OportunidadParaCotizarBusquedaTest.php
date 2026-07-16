<?php

namespace Tests\Feature;

use App\Jobs\ProcessOportunidadBusquedaJob;
use App\Models\OportunidadBusquedaCorrida;
use App\Models\OportunidadPalabraClave;
use App\Models\User;
use App\Services\OportunidadBusquedaService;
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

    public function test_iniciar_ordena_pasos_region_luego_palabra(): void
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
            ->assertJsonPath('corrida.total_pasos', 4)
            ->assertJsonPath('corrida.estado', 'running');

        $corrida = OportunidadBusquedaCorrida::query()->firstOrFail();
        $this->assertSame(13, $corrida->plan_json[0]['region']);
        $this->assertSame('papel', $corrida->plan_json[0]['frase']);
        $this->assertSame(13, $corrida->plan_json[1]['region']);
        $this->assertSame('aseo', $corrida->plan_json[1]['frase']);
        $this->assertSame(5, $corrida->plan_json[2]['region']);
        $this->assertSame('papel', $corrida->plan_json[2]['frase']);
        $this->assertSame(5, $corrida->plan_json[3]['region']);
        $this->assertSame('aseo', $corrida->plan_json[3]['frase']);

        Queue::assertPushed(ProcessOportunidadBusquedaJob::class, fn ($job) => $job->corridaId === $corrida->id);

        $this->actingAs($user)
            ->getJson(route('admin.oportunidades.para-cotizar.estado'))
            ->assertOk()
            ->assertJsonPath('corrida.id', $corrida->id)
            ->assertJsonPath('corrida.progreso', 0);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.cancelar'))
            ->assertOk()
            ->assertJsonPath('corrida.estado', OportunidadBusquedaService::ESTADO_CANCELLED);
    }

    public function test_corrida_reintenta_fallidos_de_region_antes_de_seguir(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13, 5],
            'cotiz.mercadopublico.api_reintentos_http' => 1,
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
}
