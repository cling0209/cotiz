<?php

namespace Tests\Unit;

use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Services\CotizacionExportService;
use App\Services\NotaDetalleService;
use Tests\TestCase;

class CotizacionExportServiceSoftlandTest extends TestCase
{
    public function test_fila_softland_tiene_57_columnas_y_la_orden_en_su_lugar(): void
    {
        $fila = $this->filaSoftland(new Nota([
            'nota_softland' => 55154,
            'fecha' => '2026-10-06',
            'rutempresa' => '61.607.402-5',
            'ocompra' => 'O/C 2103-644-AG26',
            'empresa' => 'Hospital Lonquimay',
        ]), new NotaDetalle([
            'cantidad' => 30,
            'prod_valor' => 1158,
        ]), '42192', 'CAJA DE ARCHIVO STANDAR NUOVO 40X25.5X15');

        $columnas = str_getcsv($fila, ';', '"', '\\');

        $this->assertCount(57, $columnas);
        $this->assertSame('55154', $columnas[0]);
        $this->assertSame('06/10/2026', $columnas[1]);
        $this->assertSame('', $columnas[2]);
        $this->assertSame('2103-644-AG26', $columnas[4]);
        $this->assertSame('61607402', $columnas[5]);
        $this->assertSame('01', $columnas[6]);
        $this->assertSame('01', $columnas[8]);
        $this->assertSame('O/C 2103-644-AG26', $columnas[9]);
        $this->assertSame('002', $columnas[11]);
        $this->assertSame('Hospital Lonquimay', $columnas[12]);
        $this->assertSame('', $columnas[34]);
        $this->assertSame('42192', $columnas[35]);
        $this->assertSame('', $columnas[36]);
        $this->assertSame('30', $columnas[37]);
        $this->assertSame('1158', $columnas[38]);
        $this->assertSame('CAJA DE ARCHIVO STANDAR NUOVO 40X25.5X15', $columnas[52]);
        $this->assertSame('', $columnas[56]);
    }

    public function test_sin_orden_de_compra_las_columnas_5_y_10_van_vacias(): void
    {
        $fila = $this->filaSoftland(new Nota([
            'nota_softland' => 1,
            'ocompra' => '',
        ]), new NotaDetalle([
            'cantidad' => 1,
            'prod_valor' => 10,
        ]), 'ABC', 'Producto');

        $columnas = str_getcsv($fila, ';', '"', '\\');

        $this->assertSame('', $columnas[4]);
        $this->assertSame('', $columnas[9]);
    }

    private function filaSoftland(Nota $nota, NotaDetalle $linea, string $codigo, string $nombre): string
    {
        $detalle = $this->createMock(NotaDetalleService::class);
        $service = new CotizacionExportService($detalle);
        $metodo = new \ReflectionMethod(CotizacionExportService::class, 'filaSoftland');

        return $metodo->invoke($service, $nota, $linea, $codigo, $nombre);
    }
}
