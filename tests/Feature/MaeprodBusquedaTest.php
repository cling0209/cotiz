<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaeprodBusquedaTest extends TestCase
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

        Maeprod::query()->create([
            'prod_item' => 'DEMO001',
            'prod_nombre' => 'PRODUCTO DEMO PAPEL BOND A4',
            'prod_valor' => 4500,
            'prod_valor_costo' => 3600,
            'prod_familia' => 'PAPEL',
            'prod_gramaje' => '75 GR',
        ]);

        Maeprod::query()->create([
            'prod_item' => 'DEMO003',
            'prod_nombre' => 'PRODUCTO DEMO LAPIZ GRAFITO',
            'prod_valor' => 350,
            'prod_valor_costo' => 250,
            'prod_familia' => 'LIBR',
        ]);

        Maeprod::query()->create([
            'prod_item' => 'U438742',
            'prod_nombre' => 'CAJA ARCHIVO MEGABOX - MEMPHIS',
            'prod_valor' => 8500,
            'prod_valor_costo' => 6200,
            'prod_familia' => 'ARCH',
        ]);

        Maeprod::query()->create([
            'prod_item' => 'JUEGFUN063',
            'prod_nombre' => 'SET DE COMIDA PARA PICNIC 63 PIEZAS #7020',
            'prod_valor' => 34648,
            'prod_valor_costo' => 28000,
            'prod_familia' => 'LIBR',
        ]);
    }

    public function test_buscar_productos_encuentra_similares_con_texto_cliente(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('admin.productos.buscar', [
            'q' => 'papel bond 75 gr a4',
        ]));

        $response->assertOk();
        $items = $response->json('data');
        $this->assertNotEmpty($items);
        $this->assertSame('DEMO001', $items[0]['prod_item']);
    }

    public function test_buscar_productos_agile_usa_mismo_motor(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('admin.agile.productos.buscar', [
            'q' => 'lapiz grafito demo',
        ]));

        $response->assertOk();
        $items = $response->json('productos');
        $this->assertNotEmpty($items);
        $this->assertSame('DEMO003', $items[0]['codigo']);
    }

    public function test_buscar_megabox_memphis_prioriza_caja_archivo(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('admin.productos.buscar', [
            'q' => 'CAJAS MEGABOX MEMPHIS (CAJAS PARA 6 ARCHIVOS) 2',
        ]));

        $response->assertOk();
        $items = $response->json('data');
        $this->assertNotEmpty($items);
        $this->assertSame('U438742', $items[0]['prod_item']);
        $this->assertNotSame('JUEGFUN063', $items[0]['prod_item']);
    }

    public function test_buscar_productos_requiere_minimo_caracteres(): void
    {
        config(['cotiz.buscar_productos_min_chars' => 3]);

        $response = $this->actingAs($this->admin)->getJson(route('admin.productos.buscar', [
            'q' => 'ab',
        ]));

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }
}
