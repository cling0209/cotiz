<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CotizacionDuplicarTest extends TestCase
{
    use RefreshDatabase;

    private const CODIGO = '1161-172-COT26';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
    }

    public function test_duplicar_copia_cabecera_y_productos_con_correlativo_siguiente(): void
    {
        $nota = $this->crearNota();
        $this->crearLinea($nota, ['prod_item' => 'PROD001', 'orden' => 1, 'cantidad' => 3]);
        $this->crearLinea($nota, ['prod_item' => 'PROD002', 'orden' => 2, 'prod_valor' => 2500]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.cotizaciones.duplicar', $nota->nronota));

        $copia = $this->copiaDe($nota);

        $response->assertRedirect(route('admin.cotizaciones.edit', $copia->nronota));
        $this->assertSame(self::CODIGO, trim((string) $copia->encargado));
        $this->assertSame(2, (int) $copia->correlativo);
        $this->assertSame(1, (int) $nota->fresh()->correlativo);
        $this->assertSame('Cliente Test', $copia->empresa);
        $this->assertNotSame((int) $nota->nota_softland, (int) $copia->nota_softland);

        $lineas = NotaDetalle::query()->where('nronota', $copia->nronota)->orderBy('orden')->get();
        $this->assertCount(2, $lineas);
        $this->assertSame('PROD001', $lineas[0]->prod_item);
        $this->assertSame(3, (int) $lineas[0]->cantidad);
        $this->assertSame(2500, (int) $lineas[1]->prod_valor);
    }

    public function test_duplicar_una_copia_avanza_al_correlativo_siguiente(): void
    {
        $nota = $this->crearNota();

        $this->actingAs($this->admin)->post(route('admin.cotizaciones.duplicar', $nota->nronota));
        $segunda = $this->copiaDe($nota);

        $this->actingAs($this->admin)->post(route('admin.cotizaciones.duplicar', $segunda->nronota));
        $tercera = Nota::query()
            ->whereNotIn('nronota', [$nota->nronota, $segunda->nronota])
            ->firstOrFail();

        $this->assertSame(2, (int) $segunda->correlativo);
        $this->assertSame(3, (int) $tercera->correlativo);
        $this->assertSame(self::CODIGO, trim((string) $tercera->encargado));
    }

    public function test_la_copia_no_hereda_estado_aceptada_ni_envio_api(): void
    {
        $nota = $this->crearNota([
            'estado' => 'aceptada',
            'estadousuario' => 'admin',
            'estadofecha' => now(),
            'enviadoapi' => 1,
            'notaorigen' => 55,
        ]);

        $this->actingAs($this->admin)->post(route('admin.cotizaciones.duplicar', $nota->nronota));

        $copia = $this->copiaDe($nota);

        $this->assertSame('', trim((string) $copia->estado));
        $this->assertSame(0, (int) $copia->enviadoapi);
        $this->assertSame(0, (int) $copia->notaorigen);
    }

    public function test_no_duplica_cotizacion_sin_numero(): void
    {
        $nota = $this->crearNota(['encargado' => '']);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.cotizaciones.duplicar', $nota->nronota));

        $response->assertRedirect(route('admin.cotizaciones.index'));
        $response->assertSessionHas('error');
        $this->assertSame(1, Nota::query()->count());
    }

    public function test_la_copia_acepta_productos_pese_a_repetir_el_codigo(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'PROD130',
            'prod_nombre' => 'Producto 130',
            'prod_valor' => 1220,
            'prod_valor_costo' => 1000,
            'prod_familia' => 'PAPEL',
        ]);

        $nota = $this->crearNota();
        $this->actingAs($this->admin)->post(route('admin.cotizaciones.duplicar', $nota->nronota));
        $copia = $this->copiaDe($nota);

        $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.lineas.store', $copia->nronota),
            [
                'prod_item' => 'PROD130',
                'cantidad' => 2,
                'prod_valor' => 1220,
                'prod_valor_costo' => 1000,
            ],
        )
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $copia->nronota,
            'prod_item' => 'PROD130',
            'cantidad' => 2,
        ]);
    }

    private function copiaDe(Nota $nota): Nota
    {
        return Nota::query()
            ->where('nronota', '!=', $nota->nronota)
            ->orderByDesc('nronota')
            ->firstOrFail();
    }

    private function crearNota(array $attrs = []): Nota
    {
        return Nota::query()->create(array_merge([
            'nronota' => 300,
            'descripcion' => 'Test duplicar',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente Test',
            'encargado' => self::CODIGO,
            'correlativo' => 1,
            'nota_softland' => 30000,
            'enviadoapi' => 0,
            'diashabiles' => 5,
            'factor_precio_venta' => 1.22,
        ], $attrs));
    }

    private function crearLinea(Nota $nota, array $attrs = []): NotaDetalle
    {
        return NotaDetalle::query()->create(array_merge([
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD',
            'prod_valor' => 1000,
            'cantidad' => 1,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 800,
        ], $attrs));
    }
}
