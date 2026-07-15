<?php

namespace Tests\Feature;

use App\Models\OportunidadPalabraClave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OportunidadPalabrasClaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
                'deleted' => true,
                'frase' => 'x',
            ], 200),
        ]);
    }

    public function test_superadmin_puede_agregar_y_eliminar_palabra_clave(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->post(route('admin.oportunidades.palabras-clave.store'), [
                'frase' => '  servicio de aseo  ',
            ])
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'));

        $this->assertDatabaseHas('oportunidad_palabras_clave', [
            'frase' => 'servicio de aseo',
            'created_by' => $user->id,
        ]);

        $palabra = OportunidadPalabraClave::query()->firstOrFail();

        $this->actingAs($user)
            ->delete(route('admin.oportunidades.palabras-clave.destroy', $palabra))
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'));

        $this->assertDatabaseCount('oportunidad_palabras_clave', 0);
    }

    public function test_no_permite_duplicados(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadPalabraClave::query()->create([
            'frase' => 'alimentación',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->from(route('admin.oportunidades.palabras-clave.index'))
            ->post(route('admin.oportunidades.palabras-clave.store'), [
                'frase' => 'alimentación',
            ])
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'))
            ->assertSessionHasErrors('frase');
    }

    public function test_para_cotizar_muestra_aviso_sin_palabras(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->assertSee('No hay palabras clave configuradas', false);
    }

    public function test_para_cotizar_no_busca_sin_parametro(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadPalabraClave::query()->create([
            'frase' => 'aseo',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->assertSee('Buscar cotizaciones', false)
            ->assertSee('Pulse', false);
    }
}
