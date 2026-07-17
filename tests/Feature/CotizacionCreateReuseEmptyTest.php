<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CotizacionCreateReuseEmptyTest extends TestCase
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

    public function test_nueva_reutiliza_ultima_sin_productos(): void
    {
        $vacia = $this->crearNota(100, '1000-1-COT26');

        $response = $this->actingAs($this->ejecutivo)->get(route('admin.cotizaciones.create'));

        $response->assertRedirect(route('admin.cotizaciones.edit', $vacia->nronota));
        $response->assertSessionHas('info');
        $this->assertSame(1, Nota::query()->where('usuario', $this->ejecutivo->username)->count());
    }

    public function test_nueva_muestra_borrador_sin_crear_si_ultima_tiene_productos(): void
    {
        $conProductos = $this->crearNota(101, '1000-2-COT26');
        NotaDetalle::query()->create([
            'nronota' => $conProductos->nronota,
            'prod_item' => 'DEMO001',
            'prod_valor' => 1000,
            'cantidad' => 1,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 800,
        ]);

        $antes = Nota::query()->where('usuario', $this->ejecutivo->username)->count();

        $response = $this->actingAs($this->ejecutivo)->get(route('admin.cotizaciones.create'));

        $response->assertOk();
        $response->assertSee('Nueva cotización', false);
        $this->assertSame($antes, Nota::query()->where('usuario', $this->ejecutivo->username)->count());
    }

    public function test_grabar_cabecera_en_borrador_crea_nronota(): void
    {
        $antes = Nota::query()->where('usuario', $this->ejecutivo->username)->count();

        $response = $this->actingAs($this->ejecutivo)->postJson(
            route('admin.cotizaciones.cabecera.store', 0),
            [
                'descripcion' => 'Cotización de prueba',
                'encargado' => '1161-999-COT26',
                'empresa' => 'Municipalidad Demo',
                'diashabiles' => 2,
            ],
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('recien_creada', true);

        $this->assertSame($antes + 1, Nota::query()->where('usuario', $this->ejecutivo->username)->count());
        $nronota = (int) $response->json('nronota');
        $this->assertGreaterThan(0, $nronota);
        $this->assertSame(
            '1161-999-COT26',
            Nota::query()->find($nronota)?->encargado,
        );
    }

    private function crearNota(int $nronota, string $encargado): Nota
    {
        return Nota::query()->create([
            'nronota' => $nronota,
            'descripcion' => 'Test cotización '.$nronota,
            'fecha' => now()->toDateString(),
            'usuario' => $this->ejecutivo->username,
            'encargado' => $encargado,
            'empresa' => '',
            'celular' => '',
            'contacto' => '',
            'contactocorreo' => '',
            'nota_softland' => 10000 + $nronota,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);
    }
}
