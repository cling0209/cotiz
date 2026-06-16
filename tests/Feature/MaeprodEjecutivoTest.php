<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use Database\Seeders\FamprodSeeder;
use Database\Seeders\GramajeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaeprodEjecutivoTest extends TestCase
{
    use RefreshDatabase;

    private User $ejecutivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GramajeSeeder::class);
        $this->seed(FamprodSeeder::class);

        $this->ejecutivo = User::factory()->create([
            'username' => 'ejecutivo',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
    }

    public function test_ejecutivo_puede_acceder_formulario_crear_producto(): void
    {
        $this->actingAs($this->ejecutivo)
            ->get(route('admin.productos.create'))
            ->assertOk()
            ->assertSee('name="prod_gramaje"', false)
            ->assertSee('unidad', false)
            ->assertSee('resma', false)
            ->assertDontSee('name="prod_item_softland"', false);
    }

    public function test_ejecutivo_puede_crear_producto_sin_grabar_softland(): void
    {
        $response = $this->actingAs($this->ejecutivo)->post(route('admin.productos.store'), [
            'prod_item' => 'EJEC001',
            'prod_nombre' => 'Producto ejecutivo',
            'prod_familia' => 'PAPEL',
            'prod_gramaje' => 'unidad',
            'prod_valor' => 5000,
            'prod_valor_costo' => 4000,
            'prod_item_softland' => 'SL-IGNORAR',
        ]);

        $response->assertRedirect(route('admin.productos.index'));

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'EJEC001',
            'prod_nombre' => 'PRODUCTO EJECUTIVO',
            'prod_gramaje' => 'unidad',
            'prod_valor' => 5000,
            'prod_item_softland' => null,
        ]);
    }

    public function test_ejecutivo_puede_listar_productos(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'LIST01',
            'prod_nombre' => 'PARA LISTAR',
            'prod_valor' => 1000,
            'prod_familia' => 'PAPEL',
        ]);

        $this->actingAs($this->ejecutivo)
            ->get(route('admin.productos.index'))
            ->assertOk()
            ->assertSee('LIST01')
            ->assertSee('PARA LISTAR')
            ->assertDontSee('Carga masiva')
            ->assertDontSee('Editar');
    }

    public function test_ejecutivo_no_puede_editar_productos(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'EXIST01',
            'prod_nombre' => 'EXISTENTE',
            'prod_valor' => 1000,
            'prod_familia' => 'PAPEL',
        ]);

        $this->actingAs($this->ejecutivo)
            ->get(route('admin.productos.edit', 'EXIST01'))
            ->assertForbidden();
    }

    public function test_ejecutivo_no_puede_actualizar_productos(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'UPD01',
            'prod_nombre' => 'ORIGINAL',
            'prod_valor' => 1000,
            'prod_familia' => 'PAPEL',
        ]);

        $this->actingAs($this->ejecutivo)
            ->put(route('admin.productos.update', 'UPD01'), [
                'prod_nombre' => 'MODIFICADO',
                'prod_valor' => 2000,
                'prod_valor_costo' => 800,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'UPD01',
            'prod_nombre' => 'ORIGINAL',
            'prod_valor' => 1000,
        ]);
    }

    public function test_ejecutivo_no_actualiza_softland_al_guardar_cotizacion(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'PROD-SL',
            'prod_nombre' => 'SIN SOFTLAND',
            'prod_valor' => 2000,
            'prod_valor_costo' => 1500,
            'prod_familia' => 'PAPEL',
            'prod_item_softland' => null,
        ]);

        $nota = Nota::query()->create([
            'nronota' => 500,
            'descripcion' => 'Test softland',
            'fecha' => now()->toDateString(),
            'usuario' => 'ejecutivo',
            'empresa' => 'Cliente',
            'encargado' => 'COT-500',
            'nota_softland' => 10000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD-SL',
            'orden' => 1,
            'cantidad' => 1,
            'prod_valor' => 2000,
            'prod_valor_costo' => 1500,
        ]);

        $this->actingAs($this->ejecutivo)->put(route('admin.cotizaciones.update', $nota->nronota), [
            'descripcion' => 'Test softland',
            'encargado' => 'COT-500',
            'lineas' => [
                [
                    'prod_item' => 'PROD-SL',
                    'orden' => 1,
                    'cantidad' => 1,
                    'prod_valor' => 2000,
                    'prod_valor_costo' => 1500,
                    'prod_item_softland' => 'SL-NO-GRABAR',
                ],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'PROD-SL',
            'prod_item_softland' => null,
        ]);
    }
}
