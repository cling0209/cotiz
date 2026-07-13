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

    public function test_detecta_formato_detalle_unidades(): void
    {
        $texto = <<<'TXT'
DETALLE PRODUCTO UNIDADES FORMATO
CLIP 28MM 50 CAJAS
SCOTCH 100 UNIDADES
POST -IT GRANDE 127X76 MM 100 HOJAS 200 UNIDADES
TXT;

        $this->assertSame('detalle_unidades', $this->parser->detectarFormato($texto));

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(3, $lineas);
        $this->assertSame(50, $lineas[0]['cantidad']);
        $this->assertSame('CLIP 28MM', $lineas[0]['descripcion']);
        $this->assertSame(100, $lineas[1]['cantidad']);
        $this->assertSame('SCOTCH', $lineas[1]['descripcion']);
        $this->assertSame(200, $lineas[2]['cantidad']);
        $this->assertSame('POST -IT GRANDE 127X76 MM 100 HOJAS', $lineas[2]['descripcion']);
    }

    public function test_detecta_formato_licitacion_pedido(): void
    {
        $texto = <<<'TXT'
PEDIDO ESTABLECIMIENTO  PRODUCTO CANTIDAD
97540 JARDIN
CALABACITAS
PACK DE 10 PLIEGOS DE CARTON FORRADO EN COLORES SURTIDOS
12
100608   CINTA DOBLE CONTACTO BLANCO 40MTS. X 24MM 36
98075 HUATULAME CAJAS DE GRAPA 5/16 8MM 20
MONTO ESTIMADO   $ 700.000.-
TXT;

        $this->assertSame('licitacion_pedido', $this->parser->detectarFormato($texto));

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(3, $lineas);
        $this->assertSame(12, $lineas[0]['cantidad']);
        $this->assertStringContainsString('CARTON FORRADO', $lineas[0]['descripcion']);
        $this->assertSame(36, $lineas[1]['cantidad']);
        $this->assertStringContainsString('CINTA DOBLE CONTACTO', $lineas[1]['descripcion']);
        $this->assertSame(20, $lineas[2]['cantidad']);
        $this->assertStringContainsString('CAJAS DE GRAPA', $lineas[2]['descripcion']);
    }

    public function test_detecta_formato_bases_linea(): void
    {
        $texto = <<<'TXT'
5. DESCRIPCIÓN TÉCNICA
LINEA DESCRIPCION REQUERIMIENTO
UNIDADES* POR
AÑO Monto Total ($) POR AÑO
ACRÍLICO (TIPO AMSTERDAM EQUIVALENTE) SERIE
1 STANDARD 105 BLANCO TITANIO 600 ML 1 33.201
10 ACRÍLICO 250ML AZUL REAL METAL 440 23 119.114
86 GLOBOS N°9 AZUL 2.030 40.584
Los oferentes podrán postular a una o más líneas, de manera independiente.
TXT;

        $this->assertSame('bases_linea', $this->parser->detectarFormato($texto));

        $lineas = $this->parser->parseTexto($texto);

        $this->assertGreaterThanOrEqual(3, count($lineas));
        $this->assertSame(1, $lineas[0]['cantidad']);
        $this->assertStringContainsString('ACRÍLICO', $lineas[0]['descripcion']);
        $this->assertSame(23, $lineas[1]['cantidad']);
        $this->assertStringContainsString('ACRÍLICO 250ML', $lineas[1]['descripcion']);
        $this->assertSame(2030, $lineas[2]['cantidad']);
        $this->assertStringContainsString('GLOBOS', $lineas[2]['descripcion']);
    }

    public function test_fixture_listado_materiales(): void
    {
        $texto = $this->cargarFixture('listado_materiales.txt');
        $lineas = $this->parser->parseTexto($texto);

        $this->assertSame('listado_cantidad', $this->parser->detectarFormato($texto));
        $this->assertGreaterThanOrEqual(80, count($lineas));
        $this->assertSame(40, $lineas[0]['cantidad']);
        $this->assertStringContainsString('ACUARELAS', $lineas[0]['descripcion']);
    }

    public function test_fixture_detalle_sg(): void
    {
        $texto = $this->cargarFixture('detalle_sg.txt');
        $lineas = $this->parser->parseTexto($texto);

        $this->assertSame('detalle_unidades', $this->parser->detectarFormato($texto));
        $this->assertCount(13, $lineas);
        $this->assertSame(50, $lineas[0]['cantidad']);
        $this->assertSame('CLIP 28MM', $lineas[0]['descripcion']);
        $this->assertSame(300, $lineas[9]['cantidad']);
    }

    public function test_fixture_licitacion_le26(): void
    {
        $texto = $this->cargarFixture('licitacion_le26.txt');
        $lineas = $this->parser->parseTexto($texto);

        $this->assertSame('licitacion_pedido', $this->parser->detectarFormato($texto));
        $this->assertGreaterThanOrEqual(500, count($lineas));
        $this->assertSame(12, $lineas[0]['cantidad']);
        $this->assertLessThan(100000, $lineas[0]['cantidad']);
        $this->assertStringNotContainsStringIgnoringCase('PEDIDO ESTABLECIMIENTO', $lineas[0]['descripcion']);
    }

    public function test_fixture_bases_las_condes(): void
    {
        $texto = $this->cargarFixture('bases_las_condes.txt');
        $lineas = $this->parser->parseTexto($texto);

        $this->assertSame('bases_linea', $this->parser->detectarFormato($texto));
        $this->assertGreaterThanOrEqual(450, count($lineas));
        $this->assertSame(1, $lineas[0]['cantidad']);
        $this->assertStringContainsString('ACRÍLICO', $lineas[0]['descripcion']);
        $this->assertStringNotContainsStringIgnoringCase('BASES ADMINISTRATIVAS', $lineas[0]['descripcion']);
        $this->assertStringNotContainsStringIgnoringCase('INSTITUCIÓN SOLICITANTE', $lineas[0]['descripcion']);
    }

    public function test_extrae_cabecera_documento_bases(): void
    {
        $texto = $this->cargarFixture('bases_las_condes.txt');
        $cabecera = $this->parser->extraerCabeceraDocumento($texto);

        $this->assertNotSame('', $cabecera['nombre']);
        $this->assertStringContainsStringIgnoringCase('CONVENIO', $cabecera['nombre']);
        $this->assertStringContainsStringIgnoringCase('Las Condes', $cabecera['empresa']);
        $this->assertSame('70902000-5', $cabecera['rutempresa']);
    }

    public function test_smalot_tabs_no_mezclan_admin_con_productos(): void
    {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'bases_smalot.txt';
        if (! is_file($path)) {
            $this->markTestSkipped('Fixture smalot no generada.');
        }

        $texto = (string) file_get_contents($path);
        $lineas = $this->parser->parseTexto($texto);
        $cabecera = $this->parser->extraerCabeceraDocumento($texto);

        $this->assertSame('bases_linea', $this->parser->detectarFormato($texto));
        $this->assertGreaterThanOrEqual(400, count($lineas));
        $this->assertStringContainsString('ACRÍLICO', $lineas[0]['descripcion']);
        $this->assertLessThan(200, mb_strlen($lineas[0]['descripcion']));
        $this->assertStringNotContainsStringIgnoringCase('BASES ADMINISTRATIVAS', $lineas[0]['descripcion']);
        $this->assertNotSame('', $cabecera['nombre']);
    }

    public function test_fixture_docx_minuta_oficina(): void
    {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'pdf_materiales'.DIRECTORY_SEPARATOR.'minuta_oficina.docx';
        $this->assertFileExists($path);

        $lineas = $this->parser->parseDocxTablas($path);

        $this->assertCount(6, $lineas);
        $this->assertSame(300, $lineas[0]['cantidad']);
        $this->assertStringContainsString('CARPETA OFICIO', $lineas[0]['descripcion']);
        $this->assertSame(25, $lineas[2]['cantidad']);
        $this->assertStringContainsString('CAJA DE ARCHIVO', $lineas[2]['descripcion']);
    }

    public function test_parse_uploaded_docx_via_uploaded_file(): void
    {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'pdf_materiales'.DIRECTORY_SEPARATOR.'minuta_oficina.docx';
        $this->assertFileExists($path);

        $uploaded = new \Illuminate\Http\UploadedFile(
            $path,
            'minuta_oficina.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        );

        $lineas = $this->parser->parseUploadedFile($uploaded);

        $this->assertCount(6, $lineas);
        $this->assertSame(300, $lineas[0]['cantidad']);
    }

    private function cargarFixture(string $nombre): string
    {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'pdf_materiales'.DIRECTORY_SEPARATOR.$nombre;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
