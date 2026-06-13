<?php

namespace Tests\Unit;

use App\Services\CompraAgilTextoParserService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompraAgilTextoParserServiceTest extends TestCase
{
    private CompraAgilTextoParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CompraAgilTextoParserService;
    }

    public function test_parsea_cabecera_y_lineas_desde_texto_mp(): void
    {
        $texto = <<<'TXT'
Detalle de la cotización 1161-172-COT26
Nombre
COMPRA DE MATERIALES DE ASEO OFICINA SAG PUERTO MONTT
Descripción
Materiales varios
SERVICIO AGRICOLA Y GANADERO
RUT 61.303.000-7
Participar de la Compra Ágil
Limpiadores de uso general ID: 31237835
LIMPIADOR DE PISOS CON AROMAS 5 LTS. CADA UNO
5 Litro
Limpiadores de uso general ID: 31237836
LIMPIADOR PISO FLOTANTE
24 Litro
TXT;

        $result = $this->parser->parse($texto);

        $this->assertSame('1161-172-COT26', $result['codigo_cotizacion']);
        $this->assertSame('SERVICIO AGRICOLA Y GANADERO', $result['empresa']);
        $this->assertSame('61303000-7', $result['rutempresa']);
        $this->assertSame('COMPRA DE MATERIALES DE ASEO OFICINA SAG PUERTO MONTT', $result['nombre']);
        $this->assertCount(2, $result['lineas']);
        $this->assertSame('31237835', $result['lineas'][0]['id_agile']);
        $this->assertSame('LIMPIADOR DE PISOS CON AROMAS 5 LTS. CADA UNO', $result['lineas'][0]['descripcion']);
        $this->assertSame(5, $result['lineas'][0]['cantidad']);
        $this->assertSame('31237836', $result['lineas'][1]['id_agile']);
        $this->assertSame(24, $result['lineas'][1]['cantidad']);
    }

    #[DataProvider('rutProvider')]
    public function test_normaliza_rut(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, $this->parser->normalizarRut($entrada));
    }

    public static function rutProvider(): array
    {
        return [
            ['61.303.000-7', '61303000-7'],
            ['12.345.678-9', '12345678-9'],
            ['1.234.567-8', '1234567-8'],
        ];
    }

    public function test_texto_vacio_devuelve_estructura_vacia(): void
    {
        $result = $this->parser->parse('   ');

        $this->assertSame('', $result['codigo_cotizacion']);
        $this->assertSame([], $result['lineas']);
    }
}
