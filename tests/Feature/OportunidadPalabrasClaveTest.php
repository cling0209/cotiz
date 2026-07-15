<?php

namespace Tests\Feature;

use App\Models\OportunidadPalabraClave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OportunidadPalabrasClaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_ejecutivo_puede_agregar_y_eliminar_palabra_clave(): void
    {
        $user = User::factory()->create([
            'perfil' => User::PERFIL_EJECUTIVO,
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
            'perfil' => User::PERFIL_EJECUTIVO,
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
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->assertSee('No hay palabras clave configuradas', false);
    }
}
