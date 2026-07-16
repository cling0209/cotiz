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

    public function test_agregar_ya_no_replica_al_sitio_par(): void
    {
        config([
            'cotiz.sistema' => 'Romulo',
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
            'cotiz.api_usuario.url' => 'https://cotiza.reicol.cl/api/v1/usuario',
            'cotiz.api_nota.user' => 'api',
            'cotiz.api_nota.password' => 'secret',
        ]);

        Http::fake();

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->post(route('admin.oportunidades.palabras-clave.store'), [
                'frase' => 'aseo industrial',
            ])
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('oportunidad_palabras_clave', [
            'frase' => 'aseo industrial',
        ]);

        Http::assertNothingSent();
    }

    public function test_api_recibir_graba_sigue_disponible(): void
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

    public function test_reordenar_ya_no_replica_al_sitio_par(): void
    {
        config([
            'cotiz.sistema' => 'Romulo',
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
            'cotiz.api_usuario.url' => 'https://cotiza.reicol.cl/api/v1/usuario',
            'cotiz.api_nota.user' => 'api',
            'cotiz.api_nota.password' => 'secret',
        ]);

        Http::fake();

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $a = OportunidadPalabraClave::query()->create([
            'frase' => 'papel',
            'orden' => 1,
            'created_by' => $user->id,
        ]);
        $b = OportunidadPalabraClave::query()->create([
            'frase' => 'aseo',
            'orden' => 2,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.palabras-clave.reordenar'), [
                'ids' => [$b->id, $a->id],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertNothingSent();
    }

    public function test_api_recibir_reordenar_aplica_orden(): void
    {
        config([
            'cotiz.api_nota.user' => 'api',
            'cotiz.api_nota.password' => 'secret',
        ]);

        OportunidadPalabraClave::query()->create(['frase' => 'papel', 'orden' => 1]);
        OportunidadPalabraClave::query()->create(['frase' => 'aseo', 'orden' => 2]);

        $this->withBasicAuth('api', 'secret')
            ->postJson('/api/v1/palabra-clave', [
                'accion' => 'reordenar',
                'replicacion' => true,
                'frases' => ['aseo', 'papel'],
            ])
            ->assertOk()
            ->assertJsonPath('resultado', 'OK')
            ->assertJsonPath('actualizados', 2);

        $this->assertSame(1, (int) OportunidadPalabraClave::query()->where('frase', 'aseo')->value('orden'));
        $this->assertSame(2, (int) OportunidadPalabraClave::query()->where('frase', 'papel')->value('orden'));
    }
}
