<?php

namespace Tests\Feature;

use App\Jobs\ProcessOportunidadAdjuntoJob;
use App\Jobs\ProcessOportunidadAdjuntoPurgeJob;
use App\Jobs\ProcessOportunidadVinculoJob;
use App\Models\OportunidadAdjuntoCorrida;
use App\Models\OportunidadEncontrada;
use App\Models\OportunidadVinculoCorrida;
use App\Models\User;
use App\Services\OportunidadAdjuntoCorridaService;
use App\Services\OportunidadVinculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OportunidadAdjuntoCorridaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-14',
            'cotiz.mercadopublico.adjuntos_disk' => 'r2_adjuntos',
            'cotiz.mercadopublico.adjuntos_prefix' => '',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
            'cotiz.api_oportunidad_encontrada.url' => '',
            'cotiz.api_oportunidad_encontrada.sync_wake_poll_max_seg' => 0,
            'cotiz.api_oportunidad_encontrada.sync_wake_espera_seg' => 0,
            'cotiz.api_usuario.url' => '',
            'filesystems.disks.r2_adjuntos.bucket' => 'mp-adjuntos',
            'filesystems.disks.r2_adjuntos.key' => 'test-key',
            'filesystems.disks.r2_adjuntos.secret' => 'test-secret',
        ]);
        Http::fake([
            '*' => Http::response(['resultado' => 'OK', 'recibidos' => 0], 200),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00', 'America/Santiago'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_iniciar_adjuntos_encola_corrida(): void
    {
        Queue::fake();

        OportunidadEncontrada::query()->create([
            'codigo' => 'ADJ-001-COT26',
            'nombre' => 'Con vinculo',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
            'vinculo_completo' => true,
            'fecha_cierre' => now()->addDays(3),
        ]);

        $resultado = $this->app->make(OportunidadAdjuntoCorridaService::class)
            ->iniciarConDetalle('2026-07-16', 'admin');

        $this->assertTrue($resultado['ok']);
        $this->assertNotNull($resultado['corrida']);
        $this->assertSame(OportunidadAdjuntoCorridaService::ESTADO_RUNNING, $resultado['corrida']->estado);
        $this->assertSame('ADJ-001-COT26', $resultado['corrida']->plan_json[0]['codigo'] ?? null);
        Queue::assertPushed(ProcessOportunidadAdjuntoJob::class);
    }

    public function test_finalizar_vinculo_sin_encadenar_inicia_adjuntos(): void
    {
        Queue::fake();

        $this->mock(\App\Services\OportunidadEncontradaRelayService::class, function ($mock) {
            $mock->shouldReceive('sincronizarPipelineTrasVinculacion')->andReturn([
                'ok' => true,
                'pendientes_ok' => 0,
                'pendientes_fail' => 0,
                'mensaje' => 'Sync omitido en test.',
            ]);
        });

        OportunidadEncontrada::query()->create([
            'codigo' => 'VIN-OK-001',
            'nombre' => 'Vinculada',
            'region' => 3,
            'nombre_region' => 'Atacama',
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
            'vinculo_completo' => true,
            'vinculo_preview_json' => ['productos' => 1],
            'fecha_cierre' => now()->addDays(2),
        ]);

        $corrida = OportunidadVinculoCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now()->subMinutes(5),
            'estado' => OportunidadVinculoService::ESTADO_RUNNING,
            'total_pasos' => 1,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 0,
            'plan_json' => [
                [
                    'codigo' => 'VIN-OK-001',
                    'region' => 3,
                    'region_nombre' => 'Atacama',
                    'estado' => 'ok',
                ],
            ],
            'errores_json' => [],
            'mensaje' => 'Vinculadas 1/1…',
        ]);

        $this->app->make(OportunidadVinculoService::class)->procesarPaso($corrida);

        $adjCorrida = OportunidadAdjuntoCorrida::query()->latest('id')->first();
        $this->assertNotNull($adjCorrida);
        $this->assertSame(OportunidadAdjuntoCorridaService::ESTADO_RUNNING, $adjCorrida->estado);
        Queue::assertPushed(ProcessOportunidadAdjuntoJob::class);
    }

    public function test_finalizar_vinculo_encadenando_no_inicia_adjuntos(): void
    {
        Queue::fake();

        OportunidadEncontrada::query()->create([
            'codigo' => 'CHAIN-NEW-001',
            'nombre' => 'Pendiente nueva',
            'region' => 3,
            'nombre_region' => 'Atacama',
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
            'vinculo_completo' => false,
            'fecha_cierre' => now()->addDays(2),
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => 'ALREADY-OK',
            'nombre' => 'Ya vinculada',
            'region' => 3,
            'nombre_region' => 'Atacama',
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
            'vinculo_completo' => true,
            'fecha_cierre' => now()->addDays(2),
        ]);

        $corrida = OportunidadVinculoCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now()->subMinutes(5),
            'estado' => OportunidadVinculoService::ESTADO_RUNNING,
            'total_pasos' => 1,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 0,
            'plan_json' => [
                [
                    'codigo' => 'ALREADY-OK',
                    'region' => 3,
                    'region_nombre' => 'Atacama',
                    'estado' => 'ok',
                ],
            ],
            'errores_json' => [],
            'mensaje' => 'Vinculadas 1/1…',
        ]);

        $this->app->make(OportunidadVinculoService::class)->procesarPaso($corrida);

        $this->assertSame(0, OportunidadAdjuntoCorrida::query()->count());
        Queue::assertNotPushed(ProcessOportunidadAdjuntoJob::class);
        Queue::assertPushed(ProcessOportunidadVinculoJob::class);
    }

    public function test_iniciar_adjuntos_endpoint_manual(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => 'ADJ-EP-001',
            'nombre' => 'Endpoint',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
            'vinculo_completo' => true,
            'fecha_cierre' => now()->addDays(2),
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.iniciar-adjuntos'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('corrida.estado', 'running')
            ->assertJsonPath('corrida.total_pasos', 1);

        Queue::assertPushed(ProcessOportunidadAdjuntoJob::class);
    }

    public function test_finalizar_adjuntos_continúa_pipeline_sin_error(): void
    {
        Queue::fake();

        $corrida = OportunidadAdjuntoCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now()->subMinutes(5),
            'estado' => OportunidadAdjuntoCorridaService::ESTADO_RUNNING,
            'total_pasos' => 1,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 0,
            'plan_json' => [
                [
                    'codigo' => 'ADJ-DONE-001',
                    'estado' => 'ok',
                ],
            ],
            'errores_json' => [],
            'mensaje' => 'Adjuntos 1/1…',
        ]);

        $this->app->make(OportunidadAdjuntoCorridaService::class)->procesarPaso($corrida);

        $corrida->refresh();
        $this->assertSame(OportunidadAdjuntoCorridaService::ESTADO_COMPLETED, $corrida->estado);
        Queue::assertPushed(ProcessOportunidadAdjuntoPurgeJob::class);
    }

    public function test_estado_adjuntos_expone_ultimo_error_inicio_y_duracion(): void
    {
        $corrida = OportunidadAdjuntoCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now()->subMinutes(4),
            'fin' => now()->subMinute(),
            'estado' => OportunidadAdjuntoCorridaService::ESTADO_COMPLETED,
            'total_pasos' => 1,
            'pasos_procesados' => 0,
            'pasos_fallidos' => 1,
            'plan_json' => [
                ['codigo' => 'ADJ-ERR-001', 'estado' => 'failed'],
            ],
            'errores_json' => [
                ['codigo' => 'ADJ-ERR-001', 'error' => 'R2 timeout', 'at' => now()->toIso8601String()],
            ],
            'mensaje' => 'Adjuntos 0/1…',
        ]);

        $estado = $this->app->make(OportunidadAdjuntoCorridaService::class)->estado($corrida);

        $this->assertSame('R2 timeout', $estado['ultimo_error']['mensaje'] ?? null);
        $this->assertSame('ADJ-ERR-001', $estado['ultimo_error']['codigo'] ?? null);
        $this->assertNotNull($estado['inicio']);
        $this->assertNotNull($estado['fin']);
        $this->assertSame(180, (int) $estado['duracion_segundos']);
        $this->assertNotEmpty($estado['duracion_texto']);
    }

    public function test_estado_adjuntos_expone_recientes_y_cierre(): void
    {
        $corrida = OportunidadAdjuntoCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now()->subMinutes(2),
            'fin' => now(),
            'estado' => OportunidadAdjuntoCorridaService::ESTADO_COMPLETED,
            'total_pasos' => 2,
            'pasos_procesados' => 2,
            'pasos_fallidos' => 0,
            'plan_json' => [
                ['codigo' => 'ADJ-001', 'estado' => 'ok', 'fin' => now()->subMinute()->toIso8601String()],
                ['codigo' => 'ADJ-002', 'estado' => 'ok', 'fin' => now()->toIso8601String()],
            ],
            'errores_json' => [],
            'mensaje' => 'Adjuntos 2/2 completados.',
        ]);

        $estado = $this->app->make(OportunidadAdjuntoCorridaService::class)->estado($corrida);

        $this->assertCount(2, $estado['recientes']);
        $this->assertSame('ADJ-002', $estado['recientes'][1]['codigo']);
        $this->assertTrue($estado['cierre']['sin_mas_automatico'] ?? false);
        $this->assertStringContainsString('Limpieza', $estado['cierre']['siguiente_proceso_label'] ?? '');
    }
}
