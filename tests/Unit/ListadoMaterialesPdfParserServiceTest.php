<?php

namespace Tests\Unit;

use App\Services\ListadoMaterialesPdfParserService;
use PHPUnit\Framework\TestCase;

class ListadoMaterialesPdfParserServiceTest extends TestCase
{
    private ListadoMaterialesPdfParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ListadoMaterialesPdfParserService;
    }

    public function test_parse_texto_detecta_cantidad_y_descripcion(): void
    {
        $texto = <<<'TXT'
Cantidad    NOMBRE DEL PRODUCTO
40          ACUARELAS DE 12 COLORES C/U
5           LIMPIADOR DE PISOS CON AROMAS 5 LTS. CADA UNO
TXT;

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(2, $lineas);
        $this->assertSame(40, $lineas[0]['cantidad']);
        $this->assertSame('ACUARELAS DE 12 COLORES C/U', $lineas[0]['descripcion']);
        $this->assertSame(5, $lineas[1]['cantidad']);
        $this->assertSame('LIMPIADOR DE PISOS CON AROMAS 5 LTS. CADA UNO', $lineas[1]['descripcion']);
    }

    public function test_parse_texto_une_lineas_de_continuacion(): void
    {
        $texto = <<<'TXT'
10 CARTULINA BRISTOL COLOR
CADA COLOR
TXT;

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(1, $lineas);
        $this->assertSame(10, $lineas[0]['cantidad']);
        $this->assertSame('CARTULINA BRISTOL COLOR CADA COLOR', $lineas[0]['descripcion']);
    }
}
