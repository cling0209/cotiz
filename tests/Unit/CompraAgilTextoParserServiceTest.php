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

    public function test_parsea_id_agile_pegado_sin_espacio_como_en_mp(): void
    {
        $texto = <<<'TXT'
Detalle de la cotización 1057498-1637-COT26
Nombre
OA JERINGA DESECHABLE 50-60 ML PUNTA ROMA / SONDAS QUIRURGICAS
SERVICIO DE SALUD SUR HOSPITAL SANATORIO EL PINO
RUT 61.608.107-1
Valor unitario
$
Subtotal
Sondas quirúrgicasID: 39339378
SONDA DE ASPIRACION 18 FR (JF016101018) (INTUBE) (BOLSA X 100 UN) PG 73235 PC 71142
Cantidad
10 Unidad
TXT;

        $result = $this->parser->parse($texto);

        $this->assertSame('1057498-1637-COT26', $result['codigo_cotizacion']);
        $this->assertCount(1, $result['lineas']);
        $this->assertSame('39339378', $result['lineas'][0]['id_agile']);
        $this->assertStringContainsString('SONDA DE ASPIRACION 18 FR', $result['lineas'][0]['descripcion']);
        $this->assertSame(10, $result['lineas'][0]['cantidad']);
        $this->assertStringContainsString('Sondas', $result['lineas'][0]['categoria']);
    }

    public function test_texto_vacio_devuelve_estructura_vacia(): void
    {
        $result = $this->parser->parse('   ');

        $this->assertSame('', $result['codigo_cotizacion']);
        $this->assertSame([], $result['lineas']);
    }

    public function test_completa_digito_verificador_si_falta(): void
    {
        $this->assertSame('69061100-7', $this->parser->completarRutConDv('69061100'));
        $this->assertSame('69061100-7', $this->parser->completarRutConDv('69061100-7'));
        $this->assertSame('65077010-2', $this->parser->completarRutConDv('65077010'));
        $this->assertSame('69073900-3', $this->parser->completarRutConDv('69073900-1'));
        $this->assertSame('7', $this->parser->calcularDvRut('69061100'));
    }
}
