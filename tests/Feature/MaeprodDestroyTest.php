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

class MaeprodDestroyTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $ejecutivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GramajeSeeder::class);
        $this->seed(FamprodSeeder::class);

        $this->superadmin = User::factory()->create([
            'username' => 'superadmin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->ejecutivo = User::factory()->create([
            'username' => 'ejecutivo',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
    }

    public function test_superadmin_puede_eliminar_producto(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'DEL01',
            'prod_nombre' => 'PRODUCTO A ELIMINAR',
            'prod_valor' => 1000,
            'prod_familia' => 'PAPEL',
        ]);

        $this->actingAs($this->superadmin)
            ->delete(route('admin.productos.destroy', 'DEL01'))
            ->assertRedirect(route('admin.productos.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('maeprod', ['prod_item' => 'DEL01']);
    }

    public function test_eliminar_producto_conserva_lineas_de_cotizacion(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'COT01',
            'prod_nombre' => 'EN COTIZACION',
            'prod_valor' => 2500,
            'prod_familia' => 'PAPEL',
        ]);

        Nota::query()->create([
            'nronota' => 100,
            'descripcion' => 'Cotiz test',
            'fecha' => now()->toDateString(),
            'usuario' => 'superadmin',
            'empresa' => 'Cliente',
            'encargado' => 'COT-100',
            'nota_softland' => 10000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaDetalle::query()->create([
            'nronota' => 100,
            'prod_item' => 'COT01',
            'orden' => 1,
            'cantidad' => 3,
            'prod_valor' => 2500,
            'prod_valor_costo' => 1800,
            'fechahora' => now(),
        ]);

        $this->actingAs($this->superadmin)
            ->delete(route('admin.productos.destroy', 'COT01'))
            ->assertRedirect(route('admin.productos.index'));

        $this->assertDatabaseMissing('maeprod', ['prod_item' => 'COT01']);
        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => 100,
            'prod_item' => 'COT01',
            'cantidad' => 3,
            'prod_valor' => 2500,
            'prod_valor_costo' => 1800,
        ]);
    }

    public function test_ejecutivo_no_puede_eliminar_productos(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'NO-DEL',
            'prod_nombre' => 'NO ELIMINABLE',
            'prod_valor' => 1000,
            'prod_familia' => 'PAPEL',
        ]);

        $this->actingAs($this->ejecutivo)
            ->delete(route('admin.productos.destroy', 'NO-DEL'))
            ->assertForbidden();

        $this->assertDatabaseHas('maeprod', ['prod_item' => 'NO-DEL']);
    }

    public function test_listado_superadmin_muestra_boton_eliminar(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'BTN01',
            'prod_nombre' => 'CON BOTON',
            'prod_valor' => 1000,
            'prod_familia' => 'PAPEL',
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.index'))
            ->assertOk()
            ->assertSee('Eliminar', false);
    }

    public function test_listado_ejecutivo_no_muestra_boton_eliminar(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'BTN02',
            'prod_nombre' => 'SIN BOTON',
            'prod_valor' => 1000,
            'prod_familia' => 'PAPEL',
        ]);

        $this->actingAs($this->ejecutivo)
            ->get(route('admin.productos.index'))
            ->assertOk()
            ->assertDontSee('>Eliminar</button>', false);
    }
}
