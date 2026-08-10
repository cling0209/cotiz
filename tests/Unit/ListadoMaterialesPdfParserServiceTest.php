<?php

namespace Tests\Unit;

use App\Services\ListadoMaterialesPdfParserService;
use App\Services\PdfOcrService;
use Illuminate\Http\UploadedFile;
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

    public function test_fixture_listado_escaneado_conserva_todas_las_filas(): void
    {
        $texto = $this->cargarFixture('listado_escaneado_araucarias_ocr.txt');
        $lineas = $this->parser->parseTexto($texto);

        $this->assertSame('listado_cantidad', $this->parser->detectarFormato($texto));
        $this->assertGreaterThanOrEqual(90, count($lineas));
        $this->assertSame(30, $lineas[0]['cantidad']);
        $this->assertStringContainsString('Cajas de L', $lineas[0]['descripcion']);

        // El OCR antepone bordes de celda ("20 -_ [Cajas…"); no deben quedar en la descripción.
        foreach ($lineas as $linea) {
            $this->assertDoesNotMatchRegularExpression('/^[|\[(_]/u', $linea['descripcion']);
        }

        // "B0"/"BO" mal leídos como 80 deben generar filas propias, no pegarse al ítem anterior.
        $descripciones = array_map(
            static fn (array $l): string => mb_strtolower($l['descripcion']),
            $lineas,
        );
        $this->assertTrue(
            count(array_filter($descripciones, static fn (string $d) => str_contains($d, 'marcadores jumbo'))) >= 1,
        );
        $this->assertTrue(
            count(array_filter($descripciones, static fn (string $d) => str_contains($d, 'lápices de colores jumbo') || str_contains($d, 'lapices de colores jumbo'))) >= 1,
        );

        $argollas = array_values(array_filter(
            $lineas,
            static fn (array $l): bool => str_contains(mb_strtolower($l['descripcion']), 'argollas'),
        ));
        $termo = array_values(array_filter(
            $lineas,
            static fn (array $l): bool => str_contains(mb_strtolower($l['descripcion']), 'termolaminadoras'),
        ));
        $this->assertCount(1, $argollas);
        $this->assertSame(100, $argollas[0]['cantidad']);
        $this->assertStringNotContainsStringIgnoringCase('Termolaminadoras', $argollas[0]['descripcion']);
        $this->assertCount(1, $termo);
        $this->assertSame(3, $termo[0]['cantidad']);
    }

    public function test_ocr_corrige_cantidad_b0_y_separa_filas_fusionadas(): void
    {
        $texto = <<<'TXT'
Cantidad NOMBRE DEL PRODUCTO
20 Pegamentos en barra Pritt 40g B0 Cajas de Lápices marcadores jumbo
i B0 Lápices de colores jumbo
: BO Saca puntas doble
P0cm Cartulina española por pliego
TXT;

        $lineas = $this->parser->parseTexto($texto);

        $this->assertGreaterThanOrEqual(5, count($lineas));
        $this->assertSame(20, $lineas[0]['cantidad']);
        $this->assertStringContainsString('Pegamentos', $lineas[0]['descripcion']);
        $this->assertStringNotContainsString('marcadores', $lineas[0]['descripcion']);

        $this->assertSame(80, $lineas[1]['cantidad']);
        $this->assertStringContainsString('marcadores jumbo', $lineas[1]['descripcion']);

        $this->assertSame(80, $lineas[2]['cantidad']);
        $this->assertStringContainsString('colores jumbo', $lineas[2]['descripcion']);

        $this->assertSame(80, $lineas[3]['cantidad']);
        $this->assertStringContainsString('Saca puntas', $lineas[3]['descripcion']);

        $this->assertSame(20, $lineas[4]['cantidad']);
        $this->assertStringContainsString('Cartulina española', $lineas[4]['descripcion']);
    }

    public function test_ocr_separa_cantidad_sola_y_nombre_en_siguiente_linea(): void
    {
        $texto = <<<'TXT'
1 kilo Algodón bolsa
100 Argollas de madera 3em
3
Termolaminadoras
TXT;

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(3, $lineas);
        $this->assertSame(1, $lineas[0]['cantidad']);
        $this->assertStringContainsString('Algodón', $lineas[0]['descripcion']);
        $this->assertSame(100, $lineas[1]['cantidad']);
        $this->assertSame('Argollas de madera 3em', $lineas[1]['descripcion']);
        $this->assertSame(3, $lineas[2]['cantidad']);
        $this->assertSame('Termolaminadoras', $lineas[2]['descripcion']);
    }

    public function test_ocr_separa_cantidad_pegada_tras_medida_cm(): void
    {
        $texto = <<<'TXT'
100 Argollas de madera 3em 3 Termolaminadoras
TXT;

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(2, $lineas);
        $this->assertSame(100, $lineas[0]['cantidad']);
        $this->assertSame('Argollas de madera 3em', $lineas[0]['descripcion']);
        $this->assertSame(3, $lineas[1]['cantidad']);
        $this->assertSame('Termolaminadoras', $lineas[1]['descripcion']);
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
        $this->assertGreaterThanOrEqual(520, count($lineas));
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

        $uploaded = new UploadedFile(
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

    public function test_parse_eett_especificaciones_desde_ocr(): void
    {
        $texto = $this->cargarFixture('eett_ocr.txt');

        $this->assertSame('eett_especificaciones', $this->parser->detectarFormato($texto));

        $lineas = $this->parser->parseTexto($texto);

        $this->assertGreaterThanOrEqual(2, count($lineas));
        $this->assertSame(30, $lineas[0]['cantidad']);
        $this->assertStringContainsString('STEP', $lineas[0]['descripcion']);
        $this->assertSame(2, $lineas[1]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('BANDA', $lineas[1]['descripcion']);
    }

    public function test_detecta_formato_tabla_producto_cantidad(): void
    {
        $texto = $this->cargarFixture('solicitud_pedido_tabla.txt');

        $this->assertSame('tabla_producto_cantidad', $this->parser->detectarFormato($texto));
    }

    public function test_detecta_formato_tabla_columnas_cotizacion_proveedor(): void
    {
        $texto = $this->cargarFixture('cotizacion_ibf.txt');

        $this->assertSame('tabla_columnas', $this->parser->detectarFormato($texto));
    }

    public function test_parse_tabla_columnas_cotizacion_ibf_dos_productos(): void
    {
        $texto = $this->cargarFixture('cotizacion_ibf.txt');

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(2, $lineas);
        $this->assertSame(30, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('CUADERNO UNIVERSITARIO MATEMATICA 7 MM 100 HOJAS LISO', $lineas[0]['descripcion']);
        $this->assertSame(30, $lineas[1]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('REPUESTO CUCHILLO CARTONERO GRANDE 10 UN', $lineas[1]['descripcion']);
    }

    public function test_parse_tabla_columnas_con_tabs_descripcion_y_cantidad(): void
    {
        $texto = <<<'TXT'
DESCRIPCION	UNIDAD	CANTIDAD	PRECIO
CUADERNO UNIVERSITARIO MATEMATICA 7 MM 100 HOJAS LISO	UNIDAD	30	1652
REPUESTO CUCHILLO CARTONERO GRANDE 10 UN	UNIDAD	30	1007
TXT;

        $this->assertSame('tabla_columnas', $this->parser->detectarFormato($texto));
        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(2, $lineas);
        $this->assertSame(30, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('CUADERNO UNIVERSITARIO', $lineas[0]['descripcion']);
        $this->assertSame(30, $lineas[1]['cantidad']);
    }

    public function test_parse_tabla_columnas_producto_en_lugar_de_descripcion(): void
    {
        $texto = <<<'TXT'
PRODUCTO	UNIDAD	CANTIDAD	PRECIO UNIT
GOMA EVA 50X70 CM	UNIDAD	5	990
TXT;

        $this->assertSame('tabla_columnas', $this->parser->detectarFormato($texto));
        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(1, $lineas);
        $this->assertSame(5, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('GOMA EVA', $lineas[0]['descripcion']);
    }

    public function test_detecta_formato_oferta_precio_enami(): void
    {
        $texto = $this->cargarFixture('enami_oferta_dual_column_sim.txt');

        $this->assertSame('oferta_precio', $this->parser->detectarFormato($texto));
    }

    public function test_parse_oferta_precio_dual_column_cantidad_default_uno(): void
    {
        $texto = $this->cargarFixture('enami_oferta_dual_column_sim.txt');

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(6, $lineas);

        foreach ($lineas as $linea) {
            $this->assertSame(1, $linea['cantidad']);
        }

        $this->assertStringContainsStringIgnoringCase('ACCOCLIP METALICO CAJA 50U DORADO RHEIN', $lineas[0]['descripcion']);
        $this->assertStringContainsStringIgnoringCase('LAPIZ PASTA P/MEDIA BPS GP 1.0 AZUL PILOT', $lineas[1]['descripcion']);
        $this->assertStringNotContainsString('391', $lineas[0]['descripcion']);
    }

    public function test_parse_oferta_precio_columna_unica_sin_dual(): void
    {
        $texto = <<<'TXT'
ANEXO N 2 OFERTA ECONOMICA
N item	Descripcion	Unidad	Precio Neto Unitario ($)
1	ACCOCLIP METALICO CAJA 50U DORADO RHEIN	CJA	-
2	ADH. EN BARRA 10GR PRITT STICK FIX HENKEL	UNI	-
TXT;

        $this->assertSame('oferta_precio', $this->parser->detectarFormato($texto));
        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(2, $lineas);
        $this->assertSame(1, $lineas[0]['cantidad']);
        $this->assertSame(1, $lineas[1]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('ACCOCLIP', $lineas[0]['descripcion']);
        $this->assertStringContainsStringIgnoringCase('PRITT STICK', $lineas[1]['descripcion']);
    }

    public function test_parse_oferta_precio_multilinea_por_celdas(): void
    {
        $texto = <<<'TXT'
ANEXO N 2 OFERTA ECONOMICA
N item	Descripcion	Unidad	Precio Neto Unitario ($)
1	ACCOCLIP METALICO CAJA 50U
DORADO RHEIN	CJA	-
TXT;

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(1, $lineas);
        $this->assertSame(1, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('ACCOCLIP', $lineas[0]['descripcion']);
        $this->assertStringContainsStringIgnoringCase('DORADO RHEIN', $lineas[0]['descripcion']);
    }

    public function test_parse_tabla_producto_cantidad_solicitud_pedido(): void
    {
        $texto = $this->cargarFixture('solicitud_pedido_tabla.txt');

        $lineas = $this->parser->parseTexto($texto);

        $this->assertGreaterThanOrEqual(11, count($lineas));
        $this->assertSame(8, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('LAPICES DE CERA', $lineas[0]['descripcion']);
        $this->assertSame(10, $lineas[3]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('RESMA OFICIO', $lineas[3]['descripcion']);
        $this->assertSame(1, $lineas[8]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('CUADERNO UNIVERSITARIO', $lineas[8]['descripcion']);
        $this->assertSame(5, $lineas[9]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('GOMA EVA', $lineas[9]['descripcion']);
        $this->assertSame(2, $lineas[10]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('CLIP METALICO', $lineas[10]['descripcion']);
    }

    public function test_listado_cantidad_no_se_confunde_con_tabla_producto(): void
    {
        $texto = <<<'TXT'
Cantidad    NOMBRE DEL PRODUCTO
40          ACUARELAS DE 12 COLORES C/U
5           LIMPIADOR DE PISOS CON AROMAS 5 LTS. CADA UNO
TXT;

        $this->assertSame('listado_cantidad', $this->parser->detectarFormato($texto));
        $this->assertCount(2, $this->parser->parseTexto($texto));
    }

    public function test_parse_solicitud_pedido_con_cabeceras_ocr_separadas(): void
    {
        $texto = $this->cargarFixture('solicitud_pedido_ocr.txt');

        $this->assertSame('tabla_producto_cantidad', $this->parser->detectarFormato($texto));

        $lineas = $this->parser->parseTexto($texto);

        $this->assertGreaterThanOrEqual(6, count($lineas));
        $this->assertSame(10, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('bolsas COLORES SURTIDOS', $lineas[0]['descripcion']);
        $this->assertSame(8, $lineas[1]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('LAPICES DE CERA', $lineas[1]['descripcion']);

        foreach ($lineas as $linea) {
            $this->assertFalse(
                (bool) preg_match('/^\d+\s*\|\s*\d+\s*unidades?\.?\s*$/iu', $linea['descripcion']),
                'No debe importar fragmentos EETT: '.$linea['descripcion'],
            );
            $this->assertFalse(
                (bool) preg_match('/^\d+\s+unidades?\.?\s*$/iu', $linea['descripcion']),
                'No debe importar solo cantidad: '.$linea['descripcion'],
            );
        }
    }

    public function test_parse_solicitud_pedido_fusiona_lineas_ocr_partidas(): void
    {
        $texto = $this->cargarFixture('solicitud_pedido_ocr_splits.txt');

        $this->assertSame('tabla_producto_cantidad', $this->parser->detectarFormato($texto));

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(9, $lineas);

        $this->assertSame(8, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('LAPICES DE CERA', $lineas[0]['descripcion']);

        $this->assertSame(1, $lineas[1]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('LAPIZ PASTA ARTEL PTA AZUL', $lineas[1]['descripcion']);
        $this->assertStringContainsStringIgnoringCase('PTA FINA 0,7', $lineas[1]['descripcion']);

        $this->assertSame(15, $lineas[7]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('CINTA DOBLE CONTACTO 18MM X 13,7MTS', $lineas[7]['descripcion']);

        $this->assertSame(1, $lineas[8]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('CUADERNO UNIVERSITARIO', $lineas[8]['descripcion']);

        foreach ($lineas as $linea) {
            $this->assertFalse(
                preg_match('/^(PTA FINA|13,7MTS|UNIDADES PTA)/iu', $linea['descripcion']) === 1,
                'No debe quedar fragmento suelto: '.$linea['descripcion'],
            );
        }
    }

    public function test_parse_solicitud_pedido_cantidad_en_linea_sola(): void
    {
        $texto = $this->cargarFixture('solicitud_pedido_cantidad_sola.txt');

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(3, $lineas);
        $this->assertSame(5, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('GOMA EVA', $lineas[0]['descripcion']);
        $this->assertSame(2, $lineas[1]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('CLIP METALICO', $lineas[1]['descripcion']);
        $this->assertSame(3, $lineas[2]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('PERFORADORA', $lineas[2]['descripcion']);
    }

    public function test_parse_solicitud_pedido_cantidad_al_inicio_de_linea(): void
    {
        $texto = $this->cargarFixture('solicitud_pedido_cantidad_inicio.txt');

        $lineas = $this->parser->parseTexto($texto);

        $this->assertGreaterThanOrEqual(3, count($lineas));
        $this->assertSame(10, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('RESMA OFICIO', $lineas[0]['descripcion']);
        $this->assertSame(5, $lineas[1]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('GOMA EVA', $lineas[1]['descripcion']);
    }

    public function test_parse_solicitud_pedido_sin_cabecera_columnas_cantidad_primero(): void
    {
        $texto = $this->cargarFixture('solicitud_pedido_sin_cabecera_columnas.txt');

        $this->assertSame('tabla_producto_cantidad', $this->parser->detectarFormato($texto));

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(3, $lineas);
        $this->assertSame(10, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('RESMA OFICIO', $lineas[0]['descripcion']);
        $this->assertSame(5, $lineas[1]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('GOMA EVA', $lineas[1]['descripcion']);
        $this->assertSame(8, $lineas[2]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('LAPICES DE CERA', $lineas[2]['descripcion']);
    }

    public function test_parse_columnas_tabuladas_cantidad_antes_producto(): void
    {
        $texto = <<<'TXT'
ESPECIFICACIONES SOLICITUD DE PEDIDO N° 1
10	RESMA OFICIO 500 HOJAS
5	GOMA EVA HOJA 50X70 CM
TXT;

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(2, $lineas);
        $this->assertSame(10, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('RESMA OFICIO', $lineas[0]['descripcion']);
        $this->assertSame(5, $lineas[1]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('GOMA EVA', $lineas[1]['descripcion']);
    }

    public function test_parse_solicitud_pedido_pagina1_nueve_productos(): void
    {
        $texto = $this->cargarFixture('solicitud_pedido_pagina1.txt');

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(9, $lineas);

        $this->assertSame(8, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('LAPICES DE CERA', $lineas[0]['descripcion']);

        $this->assertSame(1, $lineas[1]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('LAPIZ PASTA ARTEL PTA AZUL', $lineas[1]['descripcion']);

        $this->assertSame(1, $lineas[2]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('LAPIZ PASTA ARTEL PTA ROJO', $lineas[2]['descripcion']);

        $this->assertSame(10, $lineas[3]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('RESMA OFICIO', $lineas[3]['descripcion']);

        $this->assertSame(10, $lineas[4]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('RESMA CARTA', $lineas[4]['descripcion']);

        $this->assertSame(15, $lineas[7]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('CINTA DOBLE CONTACTO 18MM X 13,7MTS', $lineas[7]['descripcion']);

        $this->assertSame(1, $lineas[8]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('CUADERNO UNIVERSITARIO', $lineas[8]['descripcion']);
    }

    public function test_parse_solicitud_pedido_ocr_vps_nueve_productos(): void
    {
        $texto = $this->cargarFixture('solicitud_pedido_ocr_vps.txt');

        $this->assertSame('tabla_producto_cantidad', $this->parser->detectarFormato($texto));

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(9, $lineas);

        $this->assertSame(8, $lineas[0]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('LAPICES DE CERA JUMBO 12 UNIDADES IMAGIA TRIANGULAR', $lineas[0]['descripcion']);

        $this->assertSame(1, $lineas[1]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('LAPIZ PASTA ARTEL PTA AZUL', $lineas[1]['descripcion']);
        $this->assertStringNotContainsStringIgnoringCase('UNIDADES IMAGIA TRIANGULAR', $lineas[1]['descripcion']);

        $this->assertSame(1, $lineas[2]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('LAPIZ PASTA ARTEL PTA ROJO', $lineas[2]['descripcion']);

        $this->assertSame(10, $lineas[3]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('RESMA OFICIO', $lineas[3]['descripcion']);

        $this->assertSame(3, $lineas[6]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('PERFORADORA GRANDE', $lineas[6]['descripcion']);
        $this->assertStringNotContainsStringIgnoringCase('CT ETS', $lineas[6]['descripcion']);

        $this->assertSame(15, $lineas[7]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('CINTA DOBLE CONTACTO 18MM X 13,7MTS', $lineas[7]['descripcion']);

        $this->assertSame(1, $lineas[8]['cantidad']);
        $this->assertStringContainsStringIgnoringCase('CUADERNO UNIVERSITARIO', $lineas[8]['descripcion']);
    }

    public function test_parse_lapiz_pasta_cantidad_empaque_sin_unidades_en_descripcion(): void
    {
        $texto = $this->cargarFixture('solicitud_pedido_ocr_truncado.txt');

        $lineas = $this->parser->parseTexto($texto);

        $rojo = collect($lineas)->first(
            fn (array $l) => str_contains(mb_strtoupper($l['descripcion']), 'LAPIZ PASTA ARTEL PTA ROJO'),
        );

        $this->assertNotNull($rojo);
        $this->assertSame(1, $rojo['cantidad']);
    }

    public function test_elegir_mejor_texto_pdf_prefiere_ocr_con_mas_filas(): void
    {
        $metodo = new \ReflectionMethod(ListadoMaterialesPdfParserService::class, 'elegirMejorTextoPdfTablaProducto');
        $metodo->setAccessible(true);

        $textoNativo = $this->cargarFixture('solicitud_pedido_ocr.txt');
        $textoOcr = $this->cargarFixture('solicitud_pedido_tabla.txt');

        $elegido = $metodo->invoke($this->parser, $textoNativo, $textoOcr);

        $this->assertGreaterThanOrEqual(
            count($this->parser->parseTexto($textoOcr)),
            count($this->parser->parseTexto($elegido)),
        );
        $this->assertStringContainsString('LAPIZ PASTA ARTEL PTA ROJO', $elegido);
    }

    public function test_elegir_mejor_texto_pdf_combina_nativo_y_ocr_con_mismo_conteo(): void
    {
        $metodo = new \ReflectionMethod(ListadoMaterialesPdfParserService::class, 'elegirMejorTextoPdfTablaProducto');
        $metodo->setAccessible(true);

        $textoNativo = $this->cargarFixture('solicitud_pedido_native_single.txt');
        $textoOcr = $this->cargarFixture('solicitud_pedido_ocr_vps.txt');

        $elegido = $metodo->invoke($this->parser, $textoNativo, $textoOcr);

        $this->assertStringContainsString('LAPICES DE CERA JUMBO 12 UNIDADES IMAGIA TRIANGULAR 8 unidades', $elegido);
        $this->assertStringContainsString('LAPIZ PASTA ARTEL PTA AZUL', $elegido);
        $this->assertStringContainsString('LAPIZ PASTA ARTEL PTA ROJO', $elegido);
        $this->assertStringContainsString('RESMA OFICIO 500 HOJAS 10', $elegido);

        $lineas = $this->parser->parseTexto($elegido);
        $this->assertGreaterThanOrEqual(9, count($lineas));
        $this->assertTrue(collect($lineas)->contains(
            fn (array $fila): bool => str_contains(mb_strtoupper($fila['descripcion']), 'LAPIZ PASTA ARTEL PTA AZUL'),
        ));
        $this->assertTrue(collect($lineas)->contains(
            fn (array $fila): bool => str_contains(mb_strtoupper($fila['descripcion']), 'RESMA OFICIO'),
        ));
    }

    public function test_detecta_solicitud_pedido_aunque_incluya_especificaciones_tecnicas(): void
    {
        $texto = $this->cargarFixture('solicitud_pedido_con_eett.txt');

        $this->assertSame('tabla_producto_cantidad', $this->parser->detectarFormato($texto));
        $this->assertGreaterThanOrEqual(3, count($this->parser->parseTexto($texto)));
    }

    public function test_debe_complementar_ocr_en_solicitud_pedido_pagina_unica_incompleta(): void
    {
        $metodo = new \ReflectionMethod(ListadoMaterialesPdfParserService::class, 'debeComplementarTextoPdfConOcr');
        $metodo->setAccessible(true);

        $texto = $this->cargarFixture('solicitud_pedido_native_parcial.txt');
        $pdf = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cotiz-test-solicitud.pdf';
        file_put_contents($pdf, '%PDF-1.4 test');

        try {
            $debe = $metodo->invoke($this->parser, $pdf, $texto);
        } finally {
            @unlink($pdf);
        }

        if (! (new PdfOcrService)->estaDisponible()) {
            $this->markTestSkipped('tesseract/pdftoppm no disponibles.');
        }

        $this->assertTrue($debe);
    }

    public function test_combinar_nativo_parcial_y_ocr_vps_recupera_nueve_filas(): void
    {
        $metodo = new \ReflectionMethod(
            ListadoMaterialesPdfParserService::class,
            'elegirMejorTextoTablaProductoDesdeFragmentos',
        );
        $metodo->setAccessible(true);

        $nativo = $this->cargarFixture('solicitud_pedido_native_parcial.txt');
        $ocr = $this->cargarFixture('solicitud_pedido_ocr_vps.txt');

        $elegido = $metodo->invoke($this->parser, [
            'nativo' => $nativo,
            'ocr' => $ocr,
        ]);

        $lineas = $this->parser->parseTexto($elegido);

        $this->assertGreaterThanOrEqual(9, count($lineas));
        $this->assertTrue(collect($lineas)->contains(
            fn (array $fila): bool => str_contains(mb_strtoupper($fila['descripcion']), 'LAPIZ PASTA ARTEL PTA ROJO'),
        ));
        $this->assertTrue(collect($lineas)->contains(
            fn (array $fila): bool => str_contains(mb_strtoupper($fila['descripcion']), 'RESMA OFICIO'),
        ));
        $this->assertTrue(collect($lineas)->contains(
            fn (array $fila): bool => str_contains(mb_strtoupper($fila['descripcion']), 'CUADERNO UNIVERSITARIO'),
        ));
    }

    public function test_parse_vps_ocr_real_recupera_nueve_productos_solicitud_83965(): void
    {
        $texto = $this->cargarFixture('vps_ocr_real.txt');
        $lineas = $this->parser->parseTexto($texto);

        $this->assertGreaterThanOrEqual(9, count($lineas));

        $buscar = static function (array $lineas, string $needle): ?array {
            foreach ($lineas as $fila) {
                if (str_contains(mb_strtoupper($fila['descripcion']), mb_strtoupper($needle))) {
                    return $fila;
                }
            }

            return null;
        };

        $this->assertSame(8, $buscar($lineas, 'LAPICES DE CERA JUMBO 12 UNIDADES IMAGIA TRIANGULAR')['cantidad'] ?? null);
        $this->assertSame(1, $buscar($lineas, 'LAPIZ PASTA ARTEL PTA AZUL')['cantidad'] ?? null);
        $this->assertSame(1, $buscar($lineas, 'LAPIZ PASTA ARTEL PTA ROJO')['cantidad'] ?? null);
        $this->assertSame(10, $buscar($lineas, 'RESMA OFICIO')['cantidad'] ?? null);
        $this->assertSame(10, $buscar($lineas, 'RESMA CARTA')['cantidad'] ?? null);
        $this->assertSame(3, $buscar($lineas, 'CORCHETERA')['cantidad'] ?? null);
        $this->assertSame(3, $buscar($lineas, 'PERFORADORA GRANDE')['cantidad'] ?? null);
        $this->assertSame(15, $buscar($lineas, 'CINTA DOBLE CONTACTO 18MM X 13,7MTS')['cantidad'] ?? null);
        $this->assertSame(1, $buscar($lineas, 'CUADERNO UNIVERSITARIO')['cantidad'] ?? null);
    }

    public function test_parse_vps_ocr_real_separa_productos_pagina_dos(): void
    {
        $texto = $this->cargarFixture('vps_ocr_real.txt');
        $lineas = $this->parser->parseTexto($texto);

        $buscar = static function (array $lineas, string $needle): ?array {
            foreach ($lineas as $fila) {
                if (str_contains(mb_strtoupper($fila['descripcion']), mb_strtoupper($needle))) {
                    return $fila;
                }
            }

            return null;
        };

        $lapizStilnovo = $buscar($lineas, 'LÁPIZ DE MADERA STILNOVO');
        $lapizPasteles = $buscar($lineas, 'LÁPIZ DE MADERA COLORES min PASTELES');
        $sacapuntas = $buscar($lineas, 'SACAPUNTAS IGLOO');
        $cola = $buscar($lineas, 'COLA FRÍA');
        $temperaSolida = $buscar($lineas, 'TÉMPERA SOLIDA');

        $this->assertNotNull($lapizStilnovo);
        $this->assertSame(10, $lapizStilnovo['cantidad']);
        $this->assertNotNull($lapizPasteles);
        $this->assertSame(10, $lapizPasteles['cantidad']);
        $this->assertNotNull($sacapuntas);
        $this->assertSame(1, $sacapuntas['cantidad']);
        $this->assertStringNotContainsString('MEDIUM', mb_strtoupper($sacapuntas['descripcion']));
        $this->assertNotNull($cola);
        $this->assertSame(2, $cola['cantidad']);
        $this->assertNotNull($temperaSolida);
        $this->assertSame(15, $temperaSolida['cantidad']);
        $this->assertFalse(
            str_contains(mb_strtoupper($temperaSolida['descripcion']), 'COLA FR'),
            'Témpera sólida no debe incluir cola fría',
        );

        $cuadernoCuarta = $buscar($lineas, 'CUADERNO CUARTA');
        $blockDibujo = $buscar($lineas, 'BLOCK DE DIBUJO MEDIUM');
        $marcadoresJumbo = $buscar($lineas, 'MARCADORES JUMBO');

        $this->assertNotNull($cuadernoCuarta);
        $this->assertStringContainsString('7MM PACK 6 UNIDADES', mb_strtoupper($cuadernoCuarta['descripcion']));
        // OCR sin celdas puede no leer la columna CANTIDAD; no tomar ruido imagen ("e. 3").
        $this->assertNotSame(3, $cuadernoCuarta['cantidad']);
        $this->assertNotNull($blockDibujo);
        $this->assertSame(5, $blockDibujo['cantidad']);
        $this->assertNotNull($marcadoresJumbo);
        $this->assertSame(10, $marcadoresJumbo['cantidad']);
    }

    public function test_parse_vps_ocr_real_fusiona_colores_misma_celda_marcadores_jumbo(): void
    {
        $texto = $this->cargarFixture('vps_ocr_real.txt');
        $lineas = $this->parser->parseTexto($texto);

        $marcadoresJumbo = null;
        $coloresHuerfano = null;

        foreach ($lineas as $fila) {
            $upper = mb_strtoupper($fila['descripcion']);
            if (str_contains($upper, 'MARCADORES JUMBO')) {
                $marcadoresJumbo = $fila;
            }
            if ($upper === 'COLORES' || preg_match('/^COLORES\s+[A-ZÁÉÍÓÚ]{1,2}$/u', $upper) === 1) {
                $coloresHuerfano = $fila;
            }
        }

        $this->assertNotNull($marcadoresJumbo);
        $this->assertSame(10, $marcadoresJumbo['cantidad']);
        $this->assertStringContainsStringIgnoringCase('COLORES', $marcadoresJumbo['descripcion']);
        $this->assertNull($coloresHuerfano, 'COLORES suelto no debe quedar como fila aparte');
    }

    public function test_parse_vps_ocr_real_recupera_productos_despues_cinta_satin(): void
    {
        $texto = $this->cargarFixture('vps_ocr_real.txt');
        $lineas = $this->parser->parseTexto($texto);

        $buscar = static function (array $lineas, string $needle): ?array {
            foreach ($lineas as $fila) {
                if (str_contains(mb_strtoupper($fila['descripcion']), mb_strtoupper($needle))) {
                    return $fila;
                }
            }

            return null;
        };

        $this->assertNotNull($buscar($lineas, 'MICRÓFONO DINÁMICO SHURE'));
        $this->assertSame(10, $buscar($lineas, 'GREDA PROFESIONAL')['cantidad'] ?? null);
        $this->assertSame(5, $buscar($lineas, 'ARCILLA PROFESIONAL')['cantidad'] ?? null);
        $this->assertSame(3, $buscar($lineas, 'PAPEL BOND ROLLO')['cantidad'] ?? null);
        $this->assertSame(10, $buscar($lineas, 'POMPONES 25 MM')['cantidad'] ?? null);
        $this->assertSame(3, $buscar($lineas, 'ROLLO PAPEL KRAFT')['cantidad'] ?? null);
        $this->assertSame(15, $buscar($lineas, 'BROCHA PELO CAMELLO')['cantidad'] ?? null);
        $this->assertSame(10, $buscar($lineas, 'CINTA SATÍN O RASO')['cantidad'] ?? null);
        $this->assertSame(2, $buscar($lineas, 'SOBRE CARTA 154')['cantidad'] ?? null);
        $this->assertSame(2, $buscar($lineas, 'SOBRE 1/4 OFICIO')['cantidad'] ?? null);
        $this->assertNotNull($buscar($lineas, 'PLIEGO CARTULINA METÁLICA'));
        $this->assertNotNull($buscar($lineas, 'BOLSITAS CON ESCARCHA'));
        $bolsitas = $buscar($lineas, 'BOLSITAS CON ESCARCHA');
        $this->assertSame(5, $bolsitas['cantidad']);
        $this->assertSame(10, $buscar($lineas, 'LIMPIA PIPAS COLORES FLUOR')['cantidad'] ?? null);
        $this->assertSame(3, $buscar($lineas, 'FUNDA PLASTICA TRANSPATENTE')['cantidad'] ?? null);
        $this->assertSame(10, $buscar($lineas, 'CARTULINA CORRUGADO 50X70')['cantidad'] ?? null);
        $this->assertNotNull($buscar($lineas, 'ESPONJA CEPILLO BROCHA'));
        $this->assertNotNull($buscar($lineas, 'BLOCK PAÑOLENCI ARTEL'));
    }

    public function test_parse_vps_ocr_tecnicas_titulo_usa_tabla_producto_cantidad(): void
    {
        $texto = $this->cargarFixture('vps_ocr_real.txt');
        $texto = preg_replace(
            '/ESPECIFICACIONES SOLICITUD DE PEDIDO/u',
            'ESPECIFICACIONES TECNICAS',
            $texto,
            1,
        ) ?? $texto;

        $this->assertSame('tabla_producto_cantidad', $this->parser->detectarFormato($texto));
        $lineas = $this->parser->parseTexto($texto);
        $this->assertGreaterThanOrEqual(90, count($lineas));
        $this->assertTrue(collect($lineas)->contains(
            fn (array $fila): bool => str_contains(mb_strtoupper($fila['descripcion']), 'LAPICES DE CERA JUMBO'),
        ));
    }

    public function test_limpia_cantidad_duplicada_en_descripcion_perforadora(): void
    {
        $metodo = new \ReflectionMethod(ListadoMaterialesPdfParserService::class, 'limpiarCantidadDuplicadaEnDescripcion');
        $metodo->setAccessible(true);

        $fila = $metodo->invoke($this->parser, [
            'cantidad' => 3,
            'descripcion' => 'PERFORADORA GRANDE 3',
        ]);

        $this->assertSame('PERFORADORA GRANDE', $fila['descripcion']);
        $this->assertSame(3, $fila['cantidad']);
    }

    public function test_inferir_paginas_desde_marcadores_en_texto(): void
    {
        $metodo = new \ReflectionMethod(ListadoMaterialesPdfParserService::class, 'inferirPaginasDesdeTexto');
        $metodo->setAccessible(true);

        $texto = "ESPECIFICACIONES SOLICITUD DE PEDIDO\nPágina 2 de 11\nPágina 5 de 11";

        $this->assertSame(11, $metodo->invoke($this->parser, $texto));
    }

    public function test_ocr_pdf_escaneado_eett_si_herramientas_disponibles(): void
    {
        $ocr = new PdfOcrService;
        if (! $ocr->estaDisponible()) {
            $this->markTestSkipped('tesseract/pdftoppm no disponibles.');
        }

        $pdf = 'c:\\Archivos Varios\\OTROS\\John\\Req\\pdf cotiz\\EETT (3).pdf';
        if (! is_file($pdf)) {
            $this->markTestSkipped('PDF EETT de muestra no disponible.');
        }

        $uploaded = new UploadedFile($pdf, 'EETT (3).pdf', 'application/pdf', null, true);
        $parser = new ListadoMaterialesPdfParserService($ocr);
        $lineas = $parser->parseUploadedFile($uploaded);

        $this->assertGreaterThanOrEqual(2, count($lineas));
        $this->assertSame(30, $lineas[0]['cantidad']);
        $this->assertStringContainsString('STEP', $lineas[0]['descripcion']);
    }

    public function test_parse_cotizacion_ibf_multilinea_11_productos(): void
    {
        $texto = $this->cargarFixture('cotizacion_ibf_1291_nativo.txt');

        $this->assertSame('cotizacion_multilinea', $this->parser->detectarFormato($texto));

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(11, $lineas);
        $this->assertSame(30, $lineas[0]['cantidad']);
        $this->assertSame('CUADERNO UNIVERSITARIO MATEMATICA 7 MM 100 HOJAS LISO', $lineas[0]['descripcion']);
        $this->assertSame(30, $lineas[1]['cantidad']);
        $this->assertSame('REPUESTO CUCHILLO CARTONERO GRANDE 10 UN', $lineas[1]['descripcion']);
        $this->assertSame(30, $lineas[2]['cantidad']);
        $this->assertSame('DISPENSADOR CINTA ADHESIVA ISOFIT MEDIANO', $lineas[2]['descripcion']);
        $this->assertSame(300, $lineas[3]['cantidad']);
        $this->assertSame('LAPIZ PASTA 1.0 MM PUNTA MEDIA AZUL CRISTAL', $lineas[3]['descripcion']);
        $this->assertSame(120, $lineas[6]['cantidad']);
        $this->assertSame('MARCADOR PIZARRA S70 PUNTA REDONDA NEGRO', $lineas[6]['descripcion']);
        $this->assertSame(36, $lineas[8]['cantidad']);
        $this->assertSame('DESTACADOR ADIX PALETA TRANSPARENTE AMARILLO', $lineas[8]['descripcion']);
        $this->assertSame(30, $lineas[10]['cantidad']);
        $this->assertSame('CUCHILLO CARTONERO GRANDE N18', $lineas[10]['descripcion']);
    }

    public function test_detecta_y_parsea_formato_tabla_dideco(): void
    {
        $texto = $this->cargarFixture('dideco_programas.txt');

        $this->assertSame('tabla_dideco_especificaciones', $this->parser->detectarFormato($texto));

        $lineas = $this->parser->parseTexto($texto);

        $this->assertCount(5, $lineas);
        $this->assertSame(3, $lineas[0]['cantidad']);
        $this->assertSame(
            'Block de cartulina Bolson cartulina de colores 18 pliegos de 26.5x37cm',
            $lineas[0]['descripcion'],
        );
        $this->assertSame(5, $lineas[1]['cantidad']);
        $this->assertSame('Cinta de embalaje Cinta de embalaje transparente de 48mmx100mt.', $lineas[1]['descripcion']);
        $this->assertSame(6, $lineas[2]['cantidad']);
        $this->assertStringContainsString('Cinta de tela raso satín', $lineas[2]['descripcion']);
        $this->assertStringContainsString('rosado', $lineas[2]['descripcion']);
        $this->assertSame(1, $lineas[3]['cantidad']);
        $this->assertStringContainsString('Lapiceras pasta', $lineas[3]['descripcion']);
        $this->assertSame(10, $lineas[4]['cantidad']);
        $this->assertStringContainsString('Resmas de papel oficio', $lineas[4]['descripcion']);
    }

    public function test_mapeo_columnas_fila_encabezado_unificada_dideco(): void
    {
        $paginasFilas = [
            [
                'pagina' => 1,
                'filas' => [
                    ['UNIDAD DE MEDIDA CANTIDAD BIEN O SERVICIO ESPECIFICACIONES TÉCNICAS'],
                    ['Unidades 3 Block de cartulina Bolson cartulina de colores 18 pliegos de 26.5x37cm'],
                    ['Unidades 5 Cinta de embalaje Cinta de embalaje transparente de 48mmx100mt.'],
                ],
            ],
        ];

        $ref = new \ReflectionClass($this->parser);
        $method = $ref->getMethod('aplicarMapeoColumnasPorNombre');
        $method->setAccessible(true);

        /** @var array<int, array{cantidad: int, descripcion: string}> $lineas */
        $lineas = $method->invoke($this->parser, $paginasFilas, 'CANTIDAD', 'BIEN O SERVICIO');

        $this->assertCount(2, $lineas);
        $this->assertSame(3, $lineas[0]['cantidad']);
        $this->assertSame('Block de cartulina', $lineas[0]['descripcion']);
        $this->assertSame(5, $lineas[1]['cantidad']);
        $this->assertSame('Cinta de embalaje', $lineas[1]['descripcion']);
    }

    public function test_mapeo_columnas_multihoja_omite_headers_repetidos(): void
    {
        $paginasFilas = [
            [
                'pagina' => 1,
                'filas' => [
                    ['UNIDAD DE MEDIDA', 'CANTIDAD', 'BIEN O SERVICIO', 'ESPECIFICACIONES'],
                    ['UN', '3', 'Block de cartulina', 'Colores surtidos'],
                    ['UN', '', 'continuacion descripcion', ''],
                ],
            ],
            [
                'pagina' => 2,
                'filas' => [
                    ['UNIDAD DE MEDIDA', 'CANTIDAD', 'BIEN O SERVICIO', 'ESPECIFICACIONES'],
                    ['UN', '5', 'Cinta de embalaje', '48mm x 100mt'],
                ],
            ],
        ];

        $ref = new \ReflectionClass($this->parser);
        $method = $ref->getMethod('aplicarMapeoColumnasPorNombre');
        $method->setAccessible(true);

        /** @var array<int, array{cantidad: int, descripcion: string}> $lineas */
        $lineas = $method->invoke($this->parser, $paginasFilas, 'CANTIDAD', 'BIEN O SERVICIO');

        $this->assertCount(2, $lineas);
        $this->assertSame(3, $lineas[0]['cantidad']);
        $this->assertSame('Block de cartulina continuacion descripcion', $lineas[0]['descripcion']);
        $this->assertSame(5, $lineas[1]['cantidad']);
        $this->assertSame('Cinta de embalaje', $lineas[1]['descripcion']);
    }

    public function test_mapeo_columnas_fila_sin_unidad_no_toma_especificacion(): void
    {
        $paginasFilas = [
            [
                'pagina' => 1,
                'filas' => [
                    ['UNIDAD DE MEDIDA', 'CANTIDAD', 'BIEN O SERVICIO', 'ESPECIFICACIONES TECNICAS'],
                    ['300', 'Chapitas 58mm', 'Chapita de 58mm, NO ensamblado con filmina en bolsas de 100 unidades'],
                    ['de 58mm, NO ensamblado con filmina en bolsas de 100'],
                    ['300', 'Chapitas 37mm', 'Chapita de 37mm, NO ensamblado con filmina en bolsa de 100 unidades.'],
                    ['600', 'Chapita llavero con espejo', 'Chapita llavero con espejo de 58mm con filmina.'],
                ],
            ],
        ];

        $ref = new \ReflectionClass($this->parser);
        $method = $ref->getMethod('aplicarMapeoColumnasPorNombre');
        $method->setAccessible(true);

        $lineas = $method->invoke($this->parser, $paginasFilas, 'CANTIDAD', 'BIEN O SERVICIO');

        $this->assertCount(3, $lineas);
        $this->assertSame('Chapitas 58mm', $lineas[0]['descripcion']);
        $this->assertSame(300, $lineas[0]['cantidad']);
        $this->assertSame('Chapitas 37mm', $lineas[1]['descripcion']);
        $this->assertSame('Chapita llavero con espejo', $lineas[2]['descripcion']);
    }

    public function test_mapeo_columnas_multilinea_solo_celda_producto(): void
    {
        $paginasFilas = [
            [
                'pagina' => 1,
                'filas' => [
                    ['UNIDAD DE MEDIDA', 'CANTIDAD', 'BIEN O SERVICIO', 'ESPECIFICACIONES TECNICAS'],
                    ['Unidades', '4', 'Paquete de Papel', 'Papel adhesivo 1m x 45cm'],
                    ['', '', 'adhesivo blanco', ''],
                ],
            ],
        ];

        $ref = new \ReflectionClass($this->parser);
        $method = $ref->getMethod('aplicarMapeoColumnasPorNombre');
        $method->setAccessible(true);

        $lineas = $method->invoke($this->parser, $paginasFilas, 'CANTIDAD', 'BIEN O SERVICIO');

        $this->assertCount(1, $lineas);
        $this->assertSame(4, $lineas[0]['cantidad']);
        $this->assertSame('Paquete de Papel adhesivo blanco', $lineas[0]['descripcion']);
    }

    public function test_mapeo_columnas_hojas_titulo_repetido_y_continuacion_sin_encabezado(): void
    {
        $paginasFilas = [
            [
                'pagina' => 1,
                'filas' => [
                    ['UNIDAD DE MEDIDA CANTIDAD BIEN O SERVICIO ESPECIFICACIONES TÉCNICAS'],
                    ['Unidades 3 Block de cartulina Bolson cartulina de colores'],
                ],
            ],
            [
                'pagina' => 2,
                'filas' => [
                    ['ESPECIFICACIONES TECNICAS - PROMOCION, MUJER Y GENERO'],
                    ['Ítem Presupuestario: 215.22.04.002.016'],
                    ['UNIDAD DE MEDIDA CANTIDAD BIEN O SERVICIO ESPECIFICACIONES TÉCNICAS'],
                    ['Unidades 5 Cinta de embalaje Cinta de embalaje transparente de 48mm'],
                ],
            ],
            [
                'pagina' => 3,
                'filas' => [
                    ['Unidades 6 Cinta de tela Cinta de tela raso satín 10mm'],
                    ['Caja 1 Lapiceras pasta Lapiz pasta azul x50'],
                ],
            ],
        ];

        $ref = new \ReflectionClass($this->parser);
        $method = $ref->getMethod('aplicarMapeoColumnasPorNombre');
        $method->setAccessible(true);

        /** @var array<int, array{cantidad: int, descripcion: string}> $lineas */
        $lineas = $method->invoke($this->parser, $paginasFilas, 'CANTIDAD', 'BIEN O SERVICIO');

        $this->assertCount(4, $lineas);
        $this->assertSame(3, $lineas[0]['cantidad']);
        $this->assertSame(5, $lineas[1]['cantidad']);
        $this->assertSame(6, $lineas[2]['cantidad']);
        $this->assertSame(1, $lineas[3]['cantidad']);
        $this->assertStringContainsString('Block de cartulina', $lineas[0]['descripcion']);
        $this->assertStringContainsString('Cinta de embalaje', $lineas[1]['descripcion']);
        $this->assertStringContainsString('Cinta de tela', $lineas[2]['descripcion']);
        $this->assertStringContainsString('Lapiceras pasta', $lineas[3]['descripcion']);
    }

    private function cargarFixture(string $nombre): string
    {
        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'pdf_materiales'.DIRECTORY_SEPARATOR.$nombre;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
