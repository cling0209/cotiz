<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\MaeprodFrase;
use App\Models\User;
use Database\Seeders\FamprodSeeder;
use Database\Seeders\GramajeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaeprodFraseTest extends TestCase
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
            'prod_item' => 'DEMO003',
            'prod_nombre' => 'LAPIZ GRAFITO',
            'prod_valor' => 500,
            'prod_familia' => 'LIBR',
            'prod_gramaje' => 'unidad',
        ]);

        Maeprod::query()->create([
            'prod_item' => 'OTRO01',
            'prod_nombre' => 'OTRO PRODUCTO',
            'prod_valor' => 100,
            'prod_familia' => 'PAPEL',
            'prod_gramaje' => 'unidad',
        ]);
    }

    public function test_superadmin_puede_agregar_y_eliminar_frase(): void
    {
        $this->actingAs($this->superadmin)
            ->post(route('admin.productos.frases.store', 'DEMO003'), [
                'frase' => '  lapiz azul  ',
            ])
            ->assertRedirect(route('admin.productos.edit', 'DEMO003'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('maeprod_frases', [
            'prod_item' => 'DEMO003',
            'frase' => 'lapiz azul',
            'frase_norm' => 'LAPIZ AZUL',
        ]);

        $frase = MaeprodFrase::query()->firstOrFail();

        $this->actingAs($this->superadmin)
            ->post(route('admin.productos.frases.destroy', [
                'prod_item' => 'DEMO003',
                'frase' => $frase->id,
            ]))
            ->assertRedirect(route('admin.productos.edit', 'DEMO003'));

        $this->assertDatabaseMissing('maeprod_frases', ['id' => $frase->id]);
    }

    public function test_frase_no_puede_repetirse_en_otro_producto(): void
    {
        MaeprodFrase::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'lapiz azul',
            'frase_norm' => 'LAPIZ AZUL',
        ]);

        $this->actingAs($this->superadmin)
            ->from(route('admin.productos.edit', 'OTRO01'))
            ->post(route('admin.productos.frases.store', 'OTRO01'), [
                'frase' => 'LAPIZ AZUL',
            ])
            ->assertRedirect(route('admin.productos.edit', 'OTRO01'))
            ->assertSessionHasErrors('frase');

        $this->assertSame(1, MaeprodFrase::query()->count());
    }

    public function test_formulario_edicion_muestra_frases(): void
    {
        MaeprodFrase::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'lapiz azul',
            'frase_norm' => 'LAPIZ AZUL',
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.edit', 'DEMO003'))
            ->assertOk()
            ->assertSee('Frases para vincular')
            ->assertSee('lapiz azul');
    }
}
