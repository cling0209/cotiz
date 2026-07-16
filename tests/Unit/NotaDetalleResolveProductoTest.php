<?php

namespace Tests\Unit;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Services\NotaDetalleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotaDetalleResolveProductoTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_producto_encuentra_maeprod_con_codigo_con_espacios(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'CARPUSI013',
            'prod_nombre' => 'CARPETA VINIL JM OFICIO AZUL',
            'prod_valor' => 350,
            'prod_valor_costo' => 350,
        ]);

        $linea = new NotaDetalle([
            'prod_item' => 'CARPUSI013 ',
        ]);

        $producto = $linea->resolveProducto();

        $this->assertNotNull($producto);
        $this->assertSame('CARPETA VINIL JM OFICIO AZUL', $producto->prod_nombre);
    }

    public function test_lineas_de_nota_resuelve_descripcion_maestro_con_codigo_con_espacios(): void
    {
        $nota = Nota::query()->create([
            'nronota' => 13325,
            'descripcion' => 'Cotización prueba',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
        ]);

        Maeprod::query()->create([
            'prod_item' => 'CARPUSI013',
            'prod_nombre' => 'CARPETA VINIL JM OFICIO AZUL',
            'prod_valor' => 350,
            'prod_valor_costo' => 350,
        ]);

        DB::table('notasdetalle')->insert([
            'nronota' => $nota->nronota,
            'prod_item' => 'CARPUSI013 ',
            'prod_valor' => 350,
            'cantidad' => 1,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 350,
        ]);

        $filas = app(NotaDetalleService::class)->lineasDeNota($nota);

        $this->assertCount(1, $filas);
        $this->assertSame('CARPETA VINIL JM OFICIO AZUL', $filas->first()['prod_nombre']);
    }

    public function test_linea_pendiente_vinculo_reconoce_maestro_con_codigo_con_espacios(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'DEMO001',
            'prod_nombre' => 'PRODUCTO DEMO',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
        ]);

        $linea = new NotaDetalle([
            'prod_item' => ' DEMO001',
            'prod_item_agile' => '12345',
        ]);

        $this->assertFalse(NotaDetalleService::lineaPendienteVinculo($linea));
    }

    public function test_linea_pendiente_vinculo_reconoce_codigo_nok(): void
    {
        $linea = new NotaDetalle([
            'prod_item' => 'NOK-3',
            'prod_item_agile' => '99990001',
        ]);

        $this->assertTrue(NotaDetalleService::esCodigoNokPendiente('NOK-3'));
        $this->assertSame('NOK-3', NotaDetalleService::codigoNokParaOrden(3));
        $this->assertTrue(NotaDetalleService::lineaPendienteVinculo($linea));
    }
}
