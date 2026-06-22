<?php

namespace Tests\Unit;

use App\Services\CompraAgilPayloadMapper;
use App\Services\CompraAgilTextoParserService;
use Tests\TestCase;

class CompraAgilPayloadMapperTest extends TestCase
{
    public function test_from_detalle_mapea_cabecera_y_lineas(): void
    {
        $mapper = new CompraAgilPayloadMapper(new CompraAgilTextoParserService);
        $datos = $mapper->fromDetalle([
            'codigo' => '1161-172-COT26',
            'nombre' => 'Compra prueba',
            'institucion' => [
                'organismo_comprador' => 'Hospital Test',
                'rut' => '61.303.000-7',
            ],
            'productos_solicitados' => [
                [
                    'codigo_producto' => '31237835',
                    'nombre' => 'Limpiador',
                    'cantidad' => 10,
                ],
            ],
        ]);

        $this->assertSame('1161-172-COT26', $datos['cabecera']['codigo_cotizacion']);
        $this->assertSame('61303000-7', $datos['cabecera']['rutempresa']);
        $this->assertCount(1, $datos['lineas']);
    }
}
