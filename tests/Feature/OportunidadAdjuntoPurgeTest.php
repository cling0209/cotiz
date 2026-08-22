<?php

namespace Tests\Feature;

use App\Jobs\ProcessOportunidadAdjuntoPurgeJob;
use App\Models\OportunidadAdjuntoCorrida;
use App\Models\OportunidadEncontrada;
use App\Models\OportunidadTomada;
use App\Services\OportunidadAdjuntoCorridaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OportunidadAdjuntoPurgeTest extends TestCase
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
            'filesystems.disks.r2_adjuntos.bucket' => 'mp-adjuntos',
            'filesystems.disks.r2_adjuntos.key' => 'test-key',
            'filesystems.disks.r2_adjuntos.secret' => 'test-secret',
        ]);
        Storage::fake('r2_adjuntos');
        Carbon::setTestNow(Carbon::parse('2026-07-16 19:42:00', 'America/Santiago'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_purge_borra_cerradas_y_respeta_vigentes_y_tomadas(): void
    {
        $this->crearOportunidad('CERRADA-001', now()->subDay());
        $this->crearOportunidad('VIGENTE-001', now()->addDays(2));
        $this->crearOportunidad('TOMADA-001', now()->subDay());
        OportunidadTomada::query()->create([
            'codigo' => 'TOMADA-001',
            'usuario' => 'admin',
            'tomada_at' => now(),
        ]);

        Storage::disk('r2_adjuntos')->put('CERRADA-001/bases.pdf', '%PDF-1.4');
        Storage::disk('r2_adjuntos')->put('CERRADA-001/manifest.json', '{}');
        Storage::disk('r2_adjuntos')->put('VIGENTE-001/bases.pdf', '%PDF-1.4');
        Storage::disk('r2_adjuntos')->put('VIGENTE-001/manifest.json', '{}');
        Storage::disk('r2_adjuntos')->put('TOMADA-001/bases.pdf', '%PDF-1.4');
        Storage::disk('r2_adjuntos')->put('TOMADA-001/manifest.json', '{}');

        $corrida = $this->crearCorridaCompletada();
        $resultado = $this->app->make(OportunidadAdjuntoCorridaService::class)
            ->ejecutarPurgeCerrados($corrida);

        $this->assertSame(1, $resultado['eliminados']);
        $this->assertSame(1, $resultado['omitidos']);
        Storage::disk('r2_adjuntos')->assertMissing('CERRADA-001/bases.pdf');
        Storage::disk('r2_adjuntos')->assertExists('VIGENTE-001/bases.pdf');
        Storage::disk('r2_adjuntos')->assertExists('TOMADA-001/bases.pdf');

        $corrida->refresh();
        $this->assertSame(OportunidadAdjuntoCorridaService::PURGE_COMPLETED, $corrida->purge_json['estado'] ?? null);
        $this->assertSame(1, $corrida->purge_json['eliminados'] ?? null);
        $this->assertStringContainsString('Inicio 19:42', (string) ($corrida->purge_json['mensaje'] ?? ''));
        $this->assertStringContainsString('Fin 19:42', (string) ($corrida->purge_json['mensaje'] ?? ''));

        $estado = $this->app->make(OportunidadAdjuntoCorridaService::class)->estado($corrida);
        $this->assertSame(1, $estado['purge']['eliminados'] ?? null);
        $this->assertSame('19:42', $estado['purge']['inicio_hora'] ?? null);
        $this->assertSame('19:42', $estado['purge']['fin_hora'] ?? null);
        $this->assertNotEmpty($estado['purge']['inicio_texto'] ?? null);
        $this->assertNotEmpty($estado['purge']['fin_texto'] ?? null);
        $this->assertNotNull($estado['purge']['duracion_texto'] ?? null);
        $this->assertNull($estado['purge']['ultimo_error'] ?? null);
        $this->assertNull($estado['ultimo_error'] ?? null);
    }

    public function test_estado_purge_expone_paso_actual_y_tiempos(): void
    {
        $corrida = $this->crearCorridaCompletada();
        $inicio = now()->subMinutes(2);
        $corrida->fill([
            'purge_json' => [
                'estado' => OportunidadAdjuntoCorridaService::PURGE_RUNNING,
                'inicio' => $inicio->toIso8601String(),
                'fin' => null,
                'eliminados' => 1,
                'omitidos' => 0,
                'fallos' => 0,
                'revisados' => 1,
                'total' => 3,
                'indice' => 2,
                'codigo_actual' => 'CERRADA-002',
                'ultimos_eliminados' => [
                    ['codigo' => 'CERRADA-001', 'at' => now()->subMinute()->toIso8601String()],
                ],
                'mensaje' => 'Eliminando CERRADA-002 (2/3)…',
            ],
        ])->save();

        $purge = $this->app->make(OportunidadAdjuntoCorridaService::class)->estado($corrida)['purge'];

        $this->assertSame('CERRADA-002', $purge['paso_actual']['codigo'] ?? null);
        $this->assertSame(2, $purge['paso_actual']['indice'] ?? null);
        $this->assertSame(3, $purge['paso_actual']['total'] ?? null);
        $this->assertSame('running', $purge['paso_actual']['estado'] ?? null);
        $this->assertNotNull($purge['inicio_hora'] ?? null);
        $this->assertNull($purge['fin_hora'] ?? null);
        $this->assertNotNull($purge['ultima_actividad'] ?? null);
    }

    public function test_estado_purge_muestra_historial_si_corrida_actual_sin_eliminados(): void
    {
        $corrida = $this->crearCorridaCompletada();
        $corrida->fill([
            'purge_json' => [
                'estado' => OportunidadAdjuntoCorridaService::PURGE_COMPLETED,
                'inicio' => now()->subMinute()->toIso8601String(),
                'fin' => now()->toIso8601String(),
                'eliminados' => 0,
                'omitidos' => 2,
                'fallos' => 0,
                'ultimos_eliminados' => [],
                'ultimos_eliminados_guardados' => [
                    ['codigo' => 'CERRADA-OLD', 'at' => now()->subHour()->toIso8601String()],
                ],
                'mensaje' => 'Limpieza sin carpetas.',
            ],
        ])->save();

        $purge = $this->app->make(OportunidadAdjuntoCorridaService::class)->estado($corrida)['purge'];

        $this->assertSame('CERRADA-OLD', $purge['ultimos_eliminados'][0]['codigo'] ?? null);
        $this->assertTrue($purge['ultimos_eliminados_es_historial'] ?? false);
        $this->assertNotNull($purge['cierre'] ?? null);
    }

    public function test_finalizar_adjuntos_encola_purge(): void
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
                    'codigo' => 'ADJ-DONE-002',
                    'estado' => 'ok',
                ],
            ],
            'errores_json' => [],
            'mensaje' => 'Adjuntos 1/1…',
        ]);

        $this->app->make(OportunidadAdjuntoCorridaService::class)->procesarPaso($corrida);

        Queue::assertPushed(ProcessOportunidadAdjuntoPurgeJob::class);
        $corrida->refresh();
        $this->assertSame(OportunidadAdjuntoCorridaService::PURGE_PENDING, $corrida->purge_json['estado'] ?? null);
    }

    private function crearOportunidad(string $codigo, mixed $fechaCierre): OportunidadEncontrada
    {
        return OportunidadEncontrada::query()->create([
            'codigo' => $codigo,
            'nombre' => 'Demo',
            'organismo' => 'Hospital Demo',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'fecha_cierre' => $fechaCierre,
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
        ]);
    }

    private function crearCorridaCompletada(): OportunidadAdjuntoCorrida
    {
        return OportunidadAdjuntoCorrida::query()->create([
            'usuario' => 'admin',
            'fecha_busqueda' => '2026-07-16',
            'inicio' => now()->subMinutes(10),
            'fin' => now()->subMinute(),
            'estado' => OportunidadAdjuntoCorridaService::ESTADO_COMPLETED,
            'total_pasos' => 0,
            'pasos_procesados' => 0,
            'pasos_fallidos' => 0,
            'plan_json' => [],
            'errores_json' => [],
            'mensaje' => 'Adjuntos terminados.',
        ]);
    }
}
