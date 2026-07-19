<?php

namespace Tests\Feature;

use App\Models\CorreosChileDexTarifa;
use App\Models\User;
use App\Services\Admin\CorreosChileDexImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CorreosChileDexImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
    }

    public function test_superadmin_puede_ver_mantenedor(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.correos-chile.index'))
            ->assertOk()
            ->assertSee('Importar tarifa CChile');
    }

    public function test_importa_excel_dex_y_reemplaza_tarifas(): void
    {
        CorreosChileDexTarifa::query()->create([
            'origen' => 'SANTIAGO',
            'destino' => 'VIEJO',
            'destino_key' => 'VIEJO',
            'recargo_pct' => 20,
            'tarifas' => ['5.9' => 1000],
            'archivo_origen' => 'viejo.xlsx',
            'imported_at' => now()->subDay(),
        ]);

        $path = $this->crearExcelDexTemporal();
        $upload = new UploadedFile($path, 'tarifa-dex.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($this->admin)->post(route('admin.correos-chile.import'), [
            'archivo' => $upload,
        ]);

        $response->assertRedirect(route('admin.correos-chile.index'));
        $response->assertSessionHas('success');

        $this->assertSame(2, CorreosChileDexTarifa::query()->count());
        $this->assertDatabaseMissing('correos_chile_dex_tarifas', ['destino' => 'VIEJO']);

        $algarrobo = CorreosChileDexTarifa::query()->where('destino_key', 'ALGARROBO')->first();
        $this->assertNotNull($algarrobo);
        $this->assertSame(20, $algarrobo->recargo_pct);
        $this->assertSame(3830, $algarrobo->tarifas['5.9']);
        $this->assertSame(720, $algarrobo->tarifas['10']);

        $antofagasta = CorreosChileDexTarifa::query()->where('destino_key', 'ANTOFAGASTA')->first();
        $this->assertNotNull($antofagasta);
        $this->assertNull($antofagasta->recargo_pct);
        $this->assertSame(5590, $antofagasta->tarifas['5.9']);
    }

    public function test_parser_detecta_encabezados_y_detiene_en_notas(): void
    {
        $path = $this->crearExcelDexTemporal();
        $rows = app(CorreosChileDexImportService::class)->parseSpreadsheet($path);

        $this->assertCount(2, $rows);
        $this->assertSame('ALGARROBO', $rows[0]['destino']);
        $this->assertSame(20, $rows[0]['recargo_pct']);
        @unlink($path);
    }

    private function crearExcelDexTemporal(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray([
            ['Tarifa Distribución Expresa (DEX) - B2B', null, null, null, null],
            [null, null, null, null, null],
            ['ORIGEN', 'DESTINO', 'Zona Recargo', '5,9', '10', '20'],
            ['SANTIAGO', 'ALGARROBO', '20%', 3830, 720, 530],
            ['SANTIAGO', 'ANTOFAGASTA', 'NO', 5590, 940, 770],
            [null, null, null, null, null, null],
            ['Notas:', null, null, null, null, null],
            ['Tarifas exentas de IVA', null, null, null, null, null],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'dex').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
