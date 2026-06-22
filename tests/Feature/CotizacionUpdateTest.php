<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\NotaDetalle;
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

    public function test_guardar_cabecera_json(): void
    {
        $nota = $this->crearNota();

        $response = $this->actingAs($this->ejecutivo)->postJson(
            route('admin.cotizaciones.cabecera.store', $nota->nronota),
            [
                'descripcion' => 'Cabecera vía AJAX',
                'encargado' => 'COT-AJAX',
            ],
        );

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'mensaje' => 'Cotización guardada.',
            ]);

        $this->assertDatabaseHas('notas', [
            'nronota' => $nota->nronota,
            'encargado' => 'COT-AJAX',
            'descripcion' => 'Cabecera vía AJAX',
        ]);
    }

    public function test_guardar_lineas_en_lotes_de_diez(): void
    {
        $nota = $this->crearNota();
        $lineas = [];

        for ($i = 1; $i <= 35; $i++) {
            NotaDetalle::query()->create([
                'nronota' => $nota->nronota,
                'prod_item' => 'PROD'.$i,
                'orden' => $i,
                'cantidad' => 1,
                'prod_valor' => 1000 + $i,
                'prod_valor_costo' => 800,
                'fechahora' => now(),
            ]);
            $lineas[] = [
                'prod_item' => 'PROD'.$i,
                'orden' => $i,
                'cantidad' => 2,
                'prod_valor' => 2000 + $i,
                'prod_valor_costo' => 800,
            ];
        }

        $lotes = array_chunk($lineas, 10);
        $guardadas = 0;

        foreach ($lotes as $lote) {
            $response = $this->actingAs($this->ejecutivo)->postJson(
                route('admin.cotizaciones.lineas.lote', $nota->nronota),
                ['lineas' => $lote],
            );

            $response->assertOk()->assertJson(['ok' => true]);
            $guardadas += $response->json('guardadas');
        }

        $this->assertSame(35, $guardadas);

        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD35',
            'cantidad' => 2,
            'prod_valor' => 2035,
        ]);
    }

    public function test_lote_rechaza_mas_de_diez_lineas(): void
    {
        $nota = $this->crearNota();
        $lineas = [];

        for ($i = 1; $i <= 11; $i++) {
            $lineas[] = [
                'prod_item' => 'PROD'.$i,
                'orden' => $i,
                'cantidad' => 1,
                'prod_valor' => 1000,
            ];
        }

        $response = $this->actingAs($this->ejecutivo)->postJson(
            route('admin.cotizaciones.lineas.lote', $nota->nronota),
            ['lineas' => $lineas],
        );

        $response->assertUnprocessable();
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
