<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CotizacionUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $ejecutivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        $this->ejecutivo = User::factory()->create([
            'username' => 'ejec01',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
    }

    public function test_grabar_cotizacion_acepta_post_sin_method_spoofing(): void
    {
        $nota = $this->crearNota();

        $response = $this->actingAs($this->ejecutivo)->post(
            route('admin.cotizaciones.update', $nota->nronota),
            [
                'descripcion' => 'Cotización actualizada',
                'encargado' => 'COT-13259',
            ],
        );

        $response->assertRedirect(route('admin.cotizaciones.edit', $nota->nronota));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('notas', [
            'nronota' => $nota->nronota,
            'encargado' => 'COT-13259',
            'descripcion' => 'Cotización actualizada',
        ]);
    }

    public function test_grabar_cotizacion_put_redirige_al_formulario(): void
    {
        $nota = $this->crearNota();

        $response = $this->actingAs($this->ejecutivo)->put(
            route('admin.cotizaciones.update', $nota->nronota),
            [
                'descripcion' => 'Cotización PUT',
                'encargado' => 'COT-PUT',
            ],
        );

        $response->assertRedirect(route('admin.cotizaciones.edit', $nota->nronota));
        $response->assertSessionHas('success');
    }

    public function test_edit_nota_inexistente_redirige_al_listado(): void
    {
        $response = $this->actingAs($this->ejecutivo)->get(route('admin.cotizaciones.edit', 99999));

        $response->assertRedirect(route('admin.cotizaciones.index'));
        $response->assertSessionHas('error');
    }

    private function crearNota(): Nota
    {
        return Nota::query()->create([
            'nronota' => 13259,
            'descripcion' => 'Test cotización',
            'fecha' => now()->toDateString(),
            'usuario' => $this->ejecutivo->username,
            'encargado' => 'COT-INICIAL',
            'empresa' => '',
            'celular' => '',
            'contacto' => '',
            'contactocorreo' => '',
            'nota_softland' => 10000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);
    }
}
