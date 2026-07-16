<?php

namespace Tests\Feature;

use App\Models\OportunidadEncontrada;
use App\Models\User;
use App\Services\OportunidadEncontradaRelayService;
use App\Services\OportunidadParaCotizarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OportunidadEncontradaRelayTest extends TestCase
{
    use RefreshDatabase;

    public function test_guardar_replica_al_sitio_par(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.sistema' => 'Romulo',
            'cotiz.api_usuario.url' => 'https://cotiza.reicol.cl/api/v1/usuario',
            'cotiz.api_nota.user' => 'api',
            'cotiz.api_nota.password' => 'secret',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00', 'America/Santiago'));

        Http::fake([
            'cotiza.reicol.cl/api/v1/oportunidad-encontrada' => Http::response([
                'resultado' => 'OK',
                'recibidos' => 1,
            ], 200),
        ]);

        $svc = $this->app->make(OportunidadParaCotizarService::class);
        $svc->guardarEncontradas([
            [
                'codigo' => '2403-1-COT26',
                'nombre' => 'Servicio de aseo',
                'organismo' => 'Municipalidad',
                'region' => 13,
                'nombre_region' => 'Metropolitana',
                'monto_presupuesto_clp' => 100000,
                'palabras_coinciden' => ['aseo'],
                'cantidad_productos' => 2,
                'indice_region_config' => 0,
            ],
        ], null);

        $this->assertDatabaseHas('oportunidad_encontradas', [
            'codigo' => '2403-1-COT26',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://cotiza.reicol.cl/api/v1/oportunidad-encontrada'
                && ($request['accion'] ?? null) === 'graba'
                && is_array($request['items'] ?? null)
                && ($request['items'][0]['codigo'] ?? null) === '2403-1-COT26';
        });
    }

    public function test_api_recibe_y_graba_sin_reenviar(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.api_nota.user' => 'api',
            'cotiz.api_nota.password' => 'secret',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00', 'America/Santiago'));

        Http::fake();

        $response = $this->withBasicAuth('api', 'secret')
            ->postJson('/api/v1/oportunidad-encontrada', [
                'accion' => 'graba',
                'replicacion' => true,
                'items' => [
                    [
                        'codigo' => '9999-1-COT26',
                        'nombre' => 'Papel',
                        'fecha_busqueda' => '2026-07-16',
                        'palabras_coinciden' => ['papel'],
                        'region' => 13,
                        'monto_presupuesto_clp' => 50000,
                        'cantidad_productos' => 1,
                        'indice_region_config' => 0,
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('resultado', 'OK')
            ->assertJsonPath('recibidos', 1);

        $this->assertDatabaseHas('oportunidad_encontradas', [
            'codigo' => '9999-1-COT26',
        ]);

        Http::assertNothingSent();
    }

    public function test_sync_pendientes_reintenta_cola(): void
    {
        config([
            'cotiz.sistema' => 'Romulo',
            'cotiz.api_usuario.url' => 'https://cotiza.reicol.cl/api/v1/usuario',
            'cotiz.api_nota.user' => 'api',
            'cotiz.api_nota.password' => 'secret',
        ]);

        Http::fake([
            'cotiza.reicol.cl/up' => Http::response('ok', 200),
            'cotiza.reicol.cl/api/v1/oportunidad-encontrada' => Http::response([
                'resultado' => 'OK',
                'recibidos' => 1,
            ], 200),
        ]);

        $relay = $this->app->make(OportunidadEncontradaRelayService::class);
        $relay->encolarPendiente([
            [
                'codigo' => '1111-1-COT26',
                'nombre' => 'Pendiente',
                'fecha_busqueda' => '2026-07-16',
                'palabras_coinciden' => ['x'],
            ],
        ], 'peer down');

        $this->artisan('oportunidad:sync-encontradas-par', ['--sin-wake' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('oportunidad_encontrada_sync_pendientes', 0);
    }

    public function test_sitio_sin_analisis_ve_listado_pero_no_palabras(): void
    {
        config([
            'cotiz.mercadopublico.analisis_admin_habilitado' => false,
        ]);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => '2222-1-COT26',
            'nombre' => 'Sync',
            'fecha_busqueda' => now()->toDateString(),
            'palabras_coinciden' => ['aseo'],
            'indice_region_config' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->assertSee('sincronizadas desde el sitio', false)
            ->assertDontSee('Buscar cotizaciones', false);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.palabras-clave.index'))
            ->assertForbidden();
    }
}
