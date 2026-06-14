<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CotizacionOrdenLineaTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_cambiar_orden_preserva_datos_y_renumera(): void
    {
        $nota = $this->crearNota();

        $this->crearLinea($nota, [
            'prod_item' => 'PROD001',
            'orden' => 1,
            'prod_descripcion_agile' => 'Descripción Agile 1',
            'prod_item_agile' => 'AGILE001',
        ]);
        $this->crearLinea($nota, [
            'prod_item' => 'PROD002',
            'orden' => 2,
            'prod_descripcion_agile' => 'Descripción Agile 2',
            'prod_item_agile' => 'AGILE002',
        ]);
        $this->crearLinea($nota, [
            'prod_item' => 'PROD003',
            'orden' => 3,
            'prod_descripcion_agile' => 'Descripción Agile 3',
            'prod_item_agile' => 'AGILE003',
        ]);

        $response = $this->actingAs($this->admin)->patchJson(
            route('admin.cotizaciones.lineas.orden', $nota->nronota),
            [
                'prod_item' => 'PROD003',
                'orden' => 3,
                'orden_nuevo' => 1,
            ],
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(3, 'lineas');

        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD003',
            'orden' => 1,
            'prod_descripcion_agile' => 'Descripción Agile 3',
            'prod_item_agile' => 'AGILE003',
        ]);
        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD001',
            'orden' => 2,
            'prod_descripcion_agile' => 'Descripción Agile 1',
        ]);
        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD002',
            'orden' => 3,
            'prod_descripcion_agile' => 'Descripción Agile 2',
        ]);
    }

    public function test_eliminar_linea_renumera_y_permite_reordenar(): void
    {
        $nota = $this->crearNota();

        $this->crearLinea($nota, ['prod_item' => 'PROD001', 'orden' => 1]);
        $this->crearLinea($nota, ['prod_item' => 'PROD002', 'orden' => 2]);
        $this->crearLinea($nota, ['prod_item' => 'PROD003', 'orden' => 3]);

        $this->actingAs($this->admin)->deleteJson(
            route('admin.cotizaciones.lineas.destroy', $nota->nronota),
            ['prod_item' => 'PROD002', 'orden' => 2],
        )->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'lineas');

        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD001',
            'orden' => 1,
        ]);
        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD003',
            'orden' => 2,
        ]);

        $this->actingAs($this->admin)->patchJson(
            route('admin.cotizaciones.lineas.orden', $nota->nronota),
            [
                'prod_item' => 'PROD003',
                'orden' => 2,
                'orden_nuevo' => 1,
            ],
        )->assertOk()->assertJsonPath('ok', true);
    }

    public function test_mover_linea_arriba_con_direccion(): void
    {
        $nota = $this->crearNota();

        $this->crearLinea($nota, ['prod_item' => 'A', 'orden' => 1]);
        $this->crearLinea($nota, ['prod_item' => 'B', 'orden' => 2, 'prod_descripcion_agile' => 'Agile B']);

        $response = $this->actingAs($this->admin)->patchJson(
            route('admin.cotizaciones.lineas.orden', $nota->nronota),
            [
                'prod_item' => 'B',
                'orden' => 2,
                'direccion' => 'up',
            ],
        );

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nota->nronota,
            'prod_item' => 'B',
            'orden' => 1,
            'prod_descripcion_agile' => 'Agile B',
        ]);
        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nota->nronota,
            'prod_item' => 'A',
            'orden' => 2,
        ]);
    }

    public function test_ir_a_posicion_salta_varias_lineas(): void
    {
        $nota = $this->crearNota();

        $this->crearLinea($nota, ['prod_item' => 'PROD001', 'orden' => 1]);
        $this->crearLinea($nota, ['prod_item' => 'PROD002', 'orden' => 2]);
        $this->crearLinea($nota, ['prod_item' => 'PROD003', 'orden' => 3]);
        $this->crearLinea($nota, ['prod_item' => 'PROD004', 'orden' => 4]);

        $this->actingAs($this->admin)->patchJson(
            route('admin.cotizaciones.lineas.orden', $nota->nronota),
            [
                'prod_item' => 'PROD001',
                'orden' => 1,
                'orden_nuevo' => 4,
            ],
        )->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD001',
            'orden' => 4,
        ]);
        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD002',
            'orden' => 1,
        ]);
    }

    public function test_aplicar_factor_acepta_coma_decimal(): void
    {
        $nota = $this->crearNota();
        $this->crearLinea($nota, [
            'prod_item' => 'PROD001',
            'orden' => 1,
            'prod_valor_costo' => 1000,
            'prod_valor' => 1220,
        ]);

        $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.factor', $nota->nronota),
            ['factor_precio_venta' => '1,50'],
        )
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('factor_precio_venta_fmt', '1,50');

        $this->assertEquals(1.50, (float) $nota->fresh()->factor_precio_venta);
    }

    private function crearNota(array $attrs = []): Nota
    {
        return Nota::query()->create(array_merge([
            'nronota' => 200,
            'descripcion' => 'Test orden',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente Test',
            'encargado' => 'COT-ORDEN-001',
            'nota_softland' => 20000,
            'enviadoapi' => 0,
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
            'prod_item_agile' => null,
            'prod_descripcion_agile' => null,
        ], $attrs));
    }
}
