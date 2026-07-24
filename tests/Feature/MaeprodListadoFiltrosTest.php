<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\User;
use Database\Seeders\FamprodSeeder;
use Database\Seeders\GramajeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaeprodListadoFiltrosTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GramajeSeeder::class);
        $this->seed(FamprodSeeder::class);

        $this->superadmin = User::factory()->create([
            'username' => 'superadmin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        Maeprod::query()->create([
            'prod_item' => 'FILT01',
            'prod_nombre' => 'PRODUCTO FILTRADO',
            'prod_valor' => 1000,
            'prod_familia' => 'PAPEL',
            'prod_gramaje' => 'unidad',
        ]);
    }

    public function test_editar_propaga_filtros_en_enlace_al_listado(): void
    {
        $url = route('admin.productos.index', [
            'q' => 'filtrado',
            'familia' => 'PAPEL',
            'page' => 2,
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.edit', [
                'prod_item' => 'FILT01',
                'q' => 'filtrado',
                'familia' => 'PAPEL',
                'page' => 2,
            ]))
            ->assertOk()
            ->assertSee(e($url), false);
    }

    public function test_listado_enlace_editar_incluye_filtros(): void
    {
        $url = route('admin.productos.edit', [
            'prod_item' => 'FILT01',
            'q' => 'filtrado',
            'familia' => 'PAPEL',
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.index', [
                'q' => 'filtrado',
                'familia' => 'PAPEL',
            ]))
            ->assertOk()
            ->assertSee(e($url), false);
    }

    public function test_actualizar_producto_conserva_filtros_en_redirect(): void
    {
        $this->actingAs($this->superadmin)
            ->put(route('admin.productos.update', 'FILT01'), [
                'prod_nombre' => 'PRODUCTO FILTRADO ACT',
                'prod_familia' => 'PAPEL',
                'prod_gramaje' => 'unidad',
                'prod_valor' => 1200,
                'q' => 'filtrado',
                'familia' => 'PAPEL',
                'page' => 2,
            ])
            ->assertRedirect(route('admin.productos.edit', [
                'prod_item' => 'FILT01',
                'q' => 'filtrado',
                'familia' => 'PAPEL',
                'page' => 2,
            ]));
    }
}
