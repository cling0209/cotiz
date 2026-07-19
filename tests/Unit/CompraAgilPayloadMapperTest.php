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
                'region' => 13,
                'nombre_region' => 'Metropolitana',
                'comuna' => 'Santiago',
                'direccion' => 'Av. Libertador 100',
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
        $this->assertSame(13, $datos['cabecera']['region']);
        $this->assertSame('Metropolitana', $datos['cabecera']['nombre_region']);
        $this->assertSame('Santiago', $datos['cabecera']['comuna']);
        $this->assertSame('Av. Libertador 100', $datos['cabecera']['direccion_entrega']);
        $this->assertCount(1, $datos['lineas']);
    }
}
