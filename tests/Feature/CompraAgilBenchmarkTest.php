<?php

namespace Tests\Feature;

use App\Models\AgileMaeprod;
use App\Models\CompraAgilLineaMercado;
use App\Models\CompraAgilProceso;
use App\Models\Maeprod;
use App\Models\User;
use App\Services\CompraAgilBenchmarkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompraAgilBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_listado_admin_incluye_vinculos_agile(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'P001',
            'prod_nombre' => 'PAPEL TEST',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'prod_familia' => 'PAPEL',
        ]);

        AgileMaeprod::query()->create([
            'prod_item_agile' => '31237835',
            'prod_descripcion_agile' => 'PAPEL MP',
            'prod_item' => 'P001',
        ]);

        $service = app(CompraAgilBenchmarkService::class);
        $pagina = $service->listadoAdmin(['buscar' => 'P001']);

        $this->assertCount(1, $pagina->items());
        $row = $pagina->items()[0];
        $this->assertSame('P001', $row['prod_item']);
        $this->assertSame('31237835', $row['agile_codigo']);
        $this->assertSame('PAPEL MP', $row['agile_nombre']);
    }

    public function test_listado_sin_vinculo_excluye_codigos_en_agilemaeprod(): void
    {
        $proceso = CompraAgilProceso::query()->create([
            'codigo' => '1161-1-COT26',
            'nombre' => 'Compra test',
            'fecha_publicacion' => now()->subDays(3),
        ]);

        CompraAgilLineaMercado::query()->create([
            'codigo_proceso' => $proceso->codigo,
            'codigo_producto_mp' => '999001',
            'nombre_producto' => 'PRODUCTO SIN VINCULO',
            'cantidad' => 5,
            'precio_ganador_unitario' => 1200,
            'prod_item' => null,
            'fecha_proceso' => now()->subDays(3),
        ]);

        CompraAgilLineaMercado::query()->create([
            'codigo_proceso' => $proceso->codigo,
            'codigo_producto_mp' => '888002',
            'nombre_producto' => 'PRODUCTO VINCULADO',
            'cantidad' => 2,
            'precio_ganador_unitario' => 900,
            'prod_item' => 'P002',
            'fecha_proceso' => now()->subDays(2),
        ]);

        AgileMaeprod::query()->create([
            'prod_item_agile' => '888002',
            'prod_descripcion_agile' => 'YA VINCULADO',
            'prod_item' => 'P002',
        ]);

        $service = app(CompraAgilBenchmarkService::class);
        $pagina = $service->listadoSinVinculo([]);

        $this->assertCount(1, $pagina->items());
        $this->assertSame('999001', $pagina->items()[0]['codigo_producto_mp']);
        $this->assertSame(1, $pagina->items()[0]['procesos']);
    }

    public function test_pantalla_muestra_columnas_mp(): void
    {
        config(['cotiz.mercadopublico.analisis_admin_habilitado' => true]);

        Maeprod::query()->create([
            'prod_item' => 'P001',
            'prod_nombre' => 'PAPEL TEST',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'prod_familia' => 'PAPEL',
        ]);

        AgileMaeprod::query()->create([
            'prod_item_agile' => '31237835',
            'prod_descripcion_agile' => 'PAPEL MP',
            'prod_item' => 'P001',
        ]);

        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        $this->withoutMiddleware()
            ->actingAs($admin)
            ->get(route('admin.compra-agil.analisis.index'))
            ->assertOk()
            ->assertSee('Cód. MP')
            ->assertSee('31237835')
            ->assertSee('PAPEL MP');
    }
}
