<?php

namespace Tests\Feature;

use App\Jobs\ProcessOportunidadVinculoJob;
use App\Models\OportunidadEncontrada;
use App\Models\OportunidadVinculoCorrida;
use App\Models\User;
use App\Services\OportunidadVinculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OportunidadVinculoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [3, 13],
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-14',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00', 'America/Santiago'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_iniciar_tras_busqueda_encola_en_orden_de_regiones(): void
    {
        Queue::fake();

        OportunidadEncontrada::query()->create([
            'codigo' => 'B-RM-001',
            'nombre' => 'Metropolitana',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 1,
            'vinculo_completo' => false,
            'fecha_cierre' => now()->addDays(3),
        ]);
        OportunidadEncontrada::query()->create([
            'codigo' => 'A-AT-001',
            'nombre' => 'Atacama',
            'region' => 3,
            'nombre_region' => 'Atacama',
            'fecha_busqueda' => '2026-07-15',
            'indice_region_config' => 0,
            'vinculo_completo' => false,
            'fecha_cierre' => now()->addDays(3),
        ]);
        // Cerrada: no debe entrar al plan.
        OportunidadEncontrada::query()->create([
            'codigo' => 'Z-CLOSED',
            'nombre' => 'Cerrada',
            'region' => 3,
            'nombre_region' => 'Atacama',
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
            'vinculo_completo' => false,
            'fecha_cierre' => now()->subHour(),
        ]);
        // Tomada: no debe entrar al plan.
        OportunidadEncontrada::query()->create([
            'codigo' => 'Z-TOMADA',
            'nombre' => 'Tomada',
            'region' => 3,
            'nombre_region' => 'Atacama',
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
            'vinculo_completo' => false,
            'fecha_cierre' => now()->addDays(3),
        ]);
        \App\Models\OportunidadTomada::query()->create([
            'codigo' => 'Z-TOMADA',
            'usuario' => 'admin',
            'tomada_at' => now(),
        ]);

        $corrida = $this->app->make(OportunidadVinculoService::class)
            ->iniciarTrasBusqueda('2026-07-16', 'admin');

        $this->assertNotNull($corrida);
        $this->assertSame('running', $corrida->estado);
        $plan = $corrida->plan_json;
        $this->assertCount(2, $plan);
        $this->assertSame('A-AT-001', $plan[0]['codigo']);
        $this->assertSame('B-RM-001', $plan[1]['codigo']);
        $codigos = array_column($plan, 'codigo');
        $this->assertNotContains('Z-CLOSED', $codigos);
        $this->assertNotContains('Z-TOMADA', $codigos);

        Queue::assertPushed(ProcessOportunidadVinculoJob::class);
    }

    public function test_asegurar_tras_busqueda_completa_reencola_si_hay_pendientes(): void
    {
        Queue::fake();

        OportunidadEncontrada::query()->create([
            'codigo' => 'C-AT-009',
            'nombre' => 'Atacama',
            'region' => 3,
            'nombre_region' => 'Atacama',
            'fecha_busqueda' => '2026-07-14',
            'indice_region_config' => 0,
            'vinculo_completo' => false,
            'fecha_cierre' => now()->addDays(2),
        ]);

        $corrida = $this->app->make(OportunidadVinculoService::class)
            ->asegurarTrasBusquedaCompletada('2026-07-16', 'admin');

        $this->assertNotNull($corrida);
        $this->assertSame('running', $corrida->estado);
        $this->assertSame('C-AT-009', $corrida->plan_json[0]['codigo']);
        Queue::assertPushed(ProcessOportunidadVinculoJob::class);
    }

    public function test_iniciar_vinculo_endpoint_manual(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => 'D-AT-010',
            'nombre' => 'Atacama',
            'region' => 3,
            'nombre_region' => 'Atacama',
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
            'vinculo_completo' => false,
            'fecha_cierre' => now()->addDays(2),
        ]);

        \App\Models\OportunidadBusquedaCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now()->subHour(),
            'fin' => now()->subMinutes(5),
            'estado' => \App\Services\OportunidadBusquedaService::ESTADO_COMPLETED,
            'total_pasos' => 1,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => 1,
            'plan_json' => [],
            'errores_json' => [],
            'mensaje' => 'Búsqueda terminada.',
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.iniciar-vinculo'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('corrida.vinculo.estado', 'running')
            ->assertJsonPath('corrida.vinculo.total_pasos', 1);

        Queue::assertPushed(ProcessOportunidadVinculoJob::class);
    }

    public function test_estado_reencola_vinculo_si_busqueda_completa_con_pendientes(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => 'E-AT-011',
            'nombre' => 'Atacama',
            'region' => 3,
            'nombre_region' => 'Atacama',
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
            'vinculo_completo' => false,
            'fecha_cierre' => now()->addDays(2),
        ]);

        \App\Models\OportunidadBusquedaCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now()->subHour(),
            'fin' => now()->subMinutes(5),
            'estado' => \App\Services\OportunidadBusquedaService::ESTADO_COMPLETED,
            'total_pasos' => 1,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => 1,
            'plan_json' => [],
            'errores_json' => [],
            'mensaje' => 'Búsqueda terminada.',
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.oportunidades.para-cotizar.estado'))
            ->assertOk()
            ->assertJsonPath('corrida.vinculo.estado', 'running');

        Queue::assertPushed(ProcessOportunidadVinculoJob::class);
    }

    public function test_aviso_pendientes_cuando_no_hay_corrida_de_vinculo(): void
    {
        OportunidadEncontrada::query()->create([
            'codigo' => 'F-AT-012',
            'nombre' => 'Atacama',
            'region' => 3,
            'nombre_region' => 'Atacama',
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
            'vinculo_completo' => false,
            'fecha_cierre' => now()->addDays(2),
        ]);

        $aviso = $this->app->make(OportunidadVinculoService::class)
            ->avisoPendientes('2026-07-16');

        $this->assertNotNull($aviso);
        $this->assertSame(1, $aviso['pendientes']);
        $this->assertTrue($aviso['puede_iniciar']);
    }

    public function test_vincular_codigo_marca_completo_y_porcentaje(): void
    {
        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil*' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'codigo' => '607603-40-COT26',
                    'nombre' => 'Sillas escritorio',
                    'institucion' => [
                        'organismo_comprador' => 'UNIVERSIDAD DE ATACAMA',
                        'region' => 3,
                    ],
                    'productos_solicitados' => [
                        [
                            'codigo_producto' => '111',
                            'nombre' => 'Silla escritorio',
                            'descripcion' => 'Silla escritorio ergonómica',
                            'cantidad' => 10,
                        ],
                        [
                            'codigo_producto' => '222',
                            'nombre' => 'Producto raro xyz',
                            'descripcion' => 'Producto raro xyz sin match',
                            'cantidad' => 1,
                        ],
                    ],
                ],
            ]),
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => '607603-40-COT26',
            'nombre' => 'Sillas',
            'region' => 3,
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
            'vinculo_completo' => false,
            'fecha_cierre' => now()->addDays(3),
        ]);

        $resultado = $this->app->make(OportunidadVinculoService::class)
            ->vincularCodigo('607603-40-COT26', '2026-07-16');

        $this->assertSame(2, $resultado['total']);
        $this->assertDatabaseHas('oportunidad_encontradas', [
            'codigo' => '607603-40-COT26',
            'vinculo_completo' => true,
            'cantidad_productos' => 2,
        ]);

        $row = OportunidadEncontrada::query()->where('codigo', '607603-40-COT26')->first();
        $this->assertTrue((bool) $row->vinculo_completo);
        $this->assertNotNull($row->porcentaje_vinculo);
        $this->assertIsArray($row->vinculo_preview_json);
        $this->assertArrayHasKey('lineas', $row->vinculo_preview_json);
        $this->assertCount(2, $row->vinculo_preview_json['lineas']);

        $cache = $this->app->make(OportunidadVinculoService::class)
            ->previewCacheado('607603-40-COT26');
        $this->assertNotNull($cache);
        $this->assertTrue($cache['desde_cache']);
        $this->assertCount(2, $cache['lineas']);
    }

    public function test_estado_busqueda_incluye_vinculo(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        \App\Models\OportunidadBusquedaCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now()->subMinutes(10),
            'fin' => now()->subMinutes(5),
            'estado' => \App\Services\OportunidadBusquedaService::ESTADO_COMPLETED,
            'total_pasos' => 1,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => 0,
            'plan_json' => [
                [
                    'frase' => '(todas)',
                    'region' => 3,
                    'region_nombre' => 'Atacama',
                    'estado' => 'ok',
                    'intentos' => 1,
                    'encontradas' => 2,
                    'duracion_segundos' => 5,
                ],
            ],
            'errores_json' => [],
            'mensaje' => 'Búsqueda terminada.',
        ]);

        OportunidadVinculoCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now()->subMinute(),
            'estado' => OportunidadVinculoService::ESTADO_RUNNING,
            'total_pasos' => 2,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 0,
            'plan_json' => [
                [
                    'codigo' => 'A-AT-001',
                    'region' => 3,
                    'region_nombre' => 'Atacama',
                    'estado' => 'ok',
                ],
                [
                    'codigo' => 'A-AT-002',
                    'region' => 3,
                    'region_nombre' => 'Atacama',
                    'estado' => 'pending',
                ],
            ],
            'errores_json' => [],
            'mensaje' => 'Vinculando…',
        ]);

        $this->actingAs($user)
            ->getJson(route('admin.oportunidades.para-cotizar.estado'))
            ->assertOk()
            ->assertJsonPath('corrida.vinculo.estado', 'running')
            ->assertJsonPath('corrida.vinculo.total_pasos', 2)
            ->assertJsonPath('corrida.vinculo.progreso_por_region.3.hechos', 1)
            ->assertJsonPath('corrida.vinculo.progreso_por_region.3.total', 2)
            ->assertJsonPath('corrida.vinculo.progreso_por_region.3.porcentaje', 50)
            ->assertJsonPath('corrida.pasos_resumen.0.vinculo_porcentaje', 50)
            ->assertJsonPath('corrida.pasos_resumen.0.vinculo_hechos', 1)
            ->assertJsonPath('corrida.pasos_resumen.0.vinculo_total', 2);
    }
}
