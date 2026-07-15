<?php

namespace Tests\Feature;

use App\Models\OportunidadPalabraClave;
use App\Models\User;
use App\Services\OportunidadPalabraClaveRelayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OportunidadPalabraClaveRelayTest extends TestCase
{
    use RefreshDatabase;

    public function test_agregar_replica_al_sitio_par(): void
    {
        config([
            'cotiz.sistema' => 'Romulo',
            'cotiz.api_usuario.url' => 'https://cotiza.reicol.cl/api/v1/usuario',
            'cotiz.api_nota.user' => 'api',
            'cotiz.api_nota.password' => 'secret',
        ]);

        Http::fake([
            'cotiza.reicol.cl/api/v1/palabra-clave' => Http::response([
                'resultado' => 'OK',
                'created' => true,
                'frase' => 'aseo industrial',
            ], 200),
        ]);

        $user = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        $this->actingAs($user)
            ->post(route('admin.oportunidades.palabras-clave.store'), [
                'frase' => 'aseo industrial',
            ])
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('oportunidad_palabras_clave', [
            'frase' => 'aseo industrial',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://cotiza.reicol.cl/api/v1/palabra-clave'
                && ($request['accion'] ?? null) === 'graba'
                && ($request['frase'] ?? null) === 'aseo industrial'
                && ($request['replicacion'] ?? null) === true;
        });
    }

    public function test_api_recibir_graba_no_dispara_bucle(): void
    {
        config([
            'cotiz.api_nota.user' => 'api',
            'cotiz.api_nota.password' => 'secret',
        ]);

        $response = $this->withBasicAuth('api', 'secret')
            ->postJson('/api/v1/palabra-clave', [
                'accion' => 'graba',
                'replicacion' => true,
                'frase' => 'catering',
            ]);

        $response->assertOk()
            ->assertJson([
                'resultado' => 'OK',
                'created' => true,
                'frase' => 'catering',
            ]);

        $this->assertDatabaseHas('oportunidad_palabras_clave', [
            'frase' => 'catering',
        ]);
    }

    public function test_url_destino_se_deriva_de_usuario(): void
    {
        config([
            'cotiz.api_palabra_clave.url' => '',
            'cotiz.api_usuario.url' => 'https://cotiza.romulo.cl/api/v1/usuario',
        ]);

        $url = $this->app->make(OportunidadPalabraClaveRelayService::class)->urlDestino();

        $this->assertSame('https://cotiza.romulo.cl/api/v1/palabra-clave', $url);
    }

    public function test_si_par_cae_encola_y_sync_reintenta(): void
    {
        config([
            'cotiz.sistema' => 'Romulo',
            'cotiz.api_usuario.url' => 'https://cotiza.reicol.cl/api/v1/usuario',
            'cotiz.api_nota.user' => 'api',
            'cotiz.api_nota.password' => 'secret',
        ]);

        Http::fake([
            'cotiza.reicol.cl/api/v1/palabra-clave' => Http::sequence()
                ->push('Service Unavailable', 503)
                ->push(['resultado' => 'OK', 'created' => true, 'frase' => 'limpieza'], 200),
            'cotiza.reicol.cl/up' => Http::response('ok', 200),
        ]);

        $user = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        $this->actingAs($user)
            ->post(route('admin.oportunidades.palabras-clave.store'), [
                'frase' => 'limpieza',
            ])
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'))
            ->assertSessionHas('info');

        $this->assertDatabaseHas('oportunidad_palabra_clave_sync_pendientes', [
            'accion' => 'graba',
            'frase' => 'limpieza',
        ]);

        $this->artisan('oportunidad:sync-palabras-par', ['--sin-wake' => true, '--solo-pendientes' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('oportunidad_palabra_clave_sync_pendientes', [
            'frase' => 'limpieza',
        ]);
    }
}
