<?php

namespace Tests\Unit;

use App\Services\ListadoMaterialesExcelParserService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ListadoMaterialesExcelParserServiceTest extends TestCase
{
    private ListadoMaterialesExcelParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ListadoMaterialesExcelParserService;
    }

    public function test_omite_titulos_vacios_y_totales_sin_sumar_repetidos(): void
    {
        // A=cantidad, B=producto
        $path = $this->crearExcel([
            ['ANEXO 1'],
            ['OFERTA ECONOMICA'],
            ['PRODUCTO ESCUELA BÁSICA G-48 COLMUYAO'],
            ['CANTIDAD', 'PRODUCTO'],
            ['5', 'OFICIO 100 HOJAS OPALINA BLANCA'],
            ['10', 'BLOCK DIBUJO OFICIO 20 HOJAS'],
            ['TOTAL NETO', ''],
            [],
            ['PRODUCTO ESCUELA G-222 COLCHANE'],
            ['CANTIDAD', 'PRODUCTO'],
            ['20', 'OFICIO 100 HOJAS OPALINA BLANCA'],
            ['15', 'BLOCK DIBUJO OFICIO 20 HOJAS'],
            ['8', 'PLUMONES DE 12 COLORES PUNTA GRUESA'],
            ['TOTAL NETO', ''],
        ]);

        $file = new UploadedFile($path, 'oferta.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $resultado = $this->parser->parseDocumentoCompleto($file, 'B', 'A');

        $this->assertCount(5, $resultado['lineas']);
        $this->assertSame(5, $resultado['lineas'][0]['cantidad']);
        $this->assertSame('OFICIO 100 HOJAS OPALINA BLANCA', $resultado['lineas'][0]['descripcion']);
        $this->assertSame(20, $resultado['lineas'][2]['cantidad']);
        $this->assertSame('OFICIO 100 HOJAS OPALINA BLANCA', $resultado['lineas'][2]['descripcion']);
        $this->assertSame(8, $resultado['lineas'][4]['cantidad']);
        $this->assertGreaterThan(0, $resultado['omitidas']);

        @unlink($path);
    }

    public function test_acepta_indice_numerico_de_columna(): void
    {
        $path = $this->crearExcel([
            ['3', 'Lapiz grafito HB'],
            ['7', 'Goma de borrar'],
        ]);

        $file = new UploadedFile($path, 'items.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $resultado = $this->parser->parseDocumentoCompleto($file, '2', '1');

        $this->assertCount(2, $resultado['lineas']);
        $this->assertSame(3, $resultado['lineas'][0]['cantidad']);
        $this->assertSame(7, $resultado['lineas'][1]['cantidad']);

        @unlink($path);
    }

    public function test_lee_descripcion_c_y_cantidad_inicial_d(): void
    {
        $path = $this->crearExcel($this->filasPedidoAseo());
        $file = new UploadedFile($path, 'pedido.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $resultado = $this->parser->parseDocumentoCompleto($file, 'C', 'D');

        $this->assertCount(2, $resultado['lineas']);
        $this->assertSame(200, $resultado['lineas'][0]['cantidad']);
        $this->assertSame('JABON LIQUIDO MARCA TORK HAIR BODY 1LITRO', $resultado['lineas'][0]['descripcion']);
        $this->assertSame(30, $resultado['lineas'][1]['cantidad']);
        $this->assertSame('JABON PARA MANOS TORK ESPUMA 1LTS', $resultado['lineas'][1]['descripcion']);

        @unlink($path);
    }

    public function test_autodetecta_cantidad_inicial_si_columnas_por_defecto_caen_en_unidad(): void
    {
        $path = $this->crearExcel($this->filasPedidoAseo());
        $file = new UploadedFile($path, 'pedido.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // A=vacío, B=UNIDAD → el mapeo por defecto no encuentra productos.
        $resultado = $this->parser->parseDocumentoCompleto($file, 'B', 'A');

        $this->assertCount(2, $resultado['lineas']);
        $this->assertSame(200, $resultado['lineas'][0]['cantidad']);
        $this->assertSame('JABON LIQUIDO MARCA TORK HAIR BODY 1LITRO', $resultado['lineas'][0]['descripcion']);
        $this->assertSame(30, $resultado['lineas'][1]['cantidad']);

        @unlink($path);
    }

    public function test_lee_productos_en_hoja_que_no_esta_activa(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Portada');
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Pedido utiles de aseo');
        $hoja = $spreadsheet->createSheet();
        $hoja->setTitle('Listado');
        foreach ($this->filasPedidoAseo() as $r => $cols) {
            foreach ($cols as $c => $val) {
                if ($val === null || $val === '') {
                    continue;
                }
                $hoja->setCellValue([$c + 1, $r + 1], $val);
            }
        }
        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'cotiz_xls_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $file = new UploadedFile($path, 'pedido.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $resultado = $this->parser->parseDocumentoCompleto($file, 'C', 'D');

        $this->assertCount(2, $resultado['lineas']);
        $this->assertSame(200, $resultado['lineas'][0]['cantidad']);

        @unlink($path);
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function filasPedidoAseo(): array
    {
        return [
            ['', 'UNID', 'DESCRIPCION', 'CANTIDAD INICIAL', 'IMAGEN', 'OBSERVACIONES'],
            ['', 'UNIDAD', 'JABON LIQUIDO MARCA TORK HAIR BODY 1LITRO', 200, '', 'EL JABON ES EN FORMATO DE RECARGA DE 1LTS, INDEPENDIENTE DE LA MARCA'],
            ['', 'UNIDAD', 'JABON PARA MANOS TORK ESPUMA 1LTS', 30, '', 'RECARGA JABON ESPUMA 1LTS'],
        ];
    }

    /**
     * @param  list<list<string|int|float|null>>  $filas
     */
    private function crearExcel(array $filas): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($filas as $r => $cols) {
            foreach ($cols as $c => $val) {
                if ($val === null || $val === '') {
                    continue;
                }
                $sheet->setCellValue([$c + 1, $r + 1], $val);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'cotiz_xls_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
