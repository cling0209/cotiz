<?php

namespace Tests\Unit;

use App\Models\Maeprod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaeprodEncontrarPorCodigoTest extends TestCase
{
    use RefreshDatabase;

    public function test_encontrar_por_codigo_encuentra_prod_item_con_espacios_en_maestro(): void
    {
        DB::table('maeprod')->insert([
            'prod_item' => 'MEZCDAN001 ',
            'prod_nombre' => 'MEZCLADOR DANES',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'prod_familia' => 'LIBR',
        ]);

        $porFind = Maeprod::query()->find('MEZCDAN001');
        $porEspacios = Maeprod::query()->find(' MEZCDAN001 ');
        $producto = Maeprod::encontrarPorCodigo('MEZCDAN001');

        $this->assertNotNull($porFind);
        $this->assertNotNull($porEspacios);
        $this->assertNotNull($producto);
        $this->assertSame('MEZCLADOR DANES', $porFind->prod_nombre);
        $this->assertSame('MEZCLADOR DANES', $porEspacios->prod_nombre);
        $this->assertSame('MEZCLADOR DANES', $producto->prod_nombre);
    }

    public function test_map_por_codigos_incluye_prod_item_con_espacios(): void
    {
        DB::table('maeprod')->insert([
            'prod_item' => 'MEZCDAN001 ',
            'prod_nombre' => 'MEZCLADOR DANES',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'prod_familia' => 'LIBR',
        ]);

        Maeprod::query()->create([
            'prod_item' => 'DEMO001',
            'prod_nombre' => 'PRODUCTO DEMO',
            'prod_valor' => 100,
            'prod_valor_costo' => 80,
        ]);

        $mapa = Maeprod::mapPorCodigos(['MEZCDAN001', 'DEMO001', 'NOEXISTE']);

        $this->assertTrue($mapa->has('MEZCDAN001'));
        $this->assertTrue($mapa->has('DEMO001'));
        $this->assertFalse($mapa->has('NOEXISTE'));
        $this->assertSame('MEZCLADOR DANES', $mapa->get('MEZCDAN001')?->prod_nombre);
    }
}
