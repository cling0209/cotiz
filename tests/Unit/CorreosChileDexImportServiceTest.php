<?php

namespace Tests\Unit;

use App\Services\Admin\CorreosChileDexImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CorreosChileDexImportServiceTest extends TestCase
{
    public function test_parse_detecta_filas_y_corta_en_notas(): void
    {
        $path = $this->crearExcel();

        try {
            $rows = app(CorreosChileDexImportService::class)->parseSpreadsheet($path);

            $this->assertCount(2, $rows);
            $this->assertSame('ALGARROBO', $rows[0]['destino']);
            $this->assertSame(20, $rows[0]['recargo_pct']);
            $this->assertSame(3830, $rows[0]['tarifas']['5.9']);
            $this->assertSame('ANTOFAGASTA', $rows[1]['destino']);
            $this->assertNull($rows[1]['recargo_pct']);
        } finally {
            @unlink($path);
        }
    }

    private function crearExcel(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Tarifa Distribución Expresa (DEX) - B2B'],
            [null],
            ['ORIGEN', 'DESTINO', 'Zona Recargo', '5,9', '10', '20'],
            ['SANTIAGO', 'ALGARROBO', '20%', 3830, 720, 530],
            ['SANTIAGO', 'ANTOFAGASTA', 'NO', 5590, 940, 770],
            [null],
            ['Notas:'],
            ['Tarifas exentas de IVA'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'dex').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
