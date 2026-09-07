<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\User;
use Database\Seeders\FamprodSeeder;
use Database\Seeders\GramajeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MaeprodBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $ejecutivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GramajeSeeder::class);
        $this->seed(FamprodSeeder::class);

        $this->superadmin = User::factory()->create([
            'username' => 'superadmin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->ejecutivo = User::factory()->create([
            'username' => 'ejecutivo',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
    }

    public function test_superadmin_puede_ver_formulario_eliminacion_masiva(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.bulk-delete'))
            ->assertOk()
            ->assertSee('Eliminación masiva', false);
    }

    public function test_ejecutivo_no_puede_acceder_eliminacion_masiva(): void
    {
        $this->actingAs($this->ejecutivo)
            ->get(route('admin.productos.bulk-delete'))
            ->assertForbidden();
    }

    public function test_listado_superadmin_muestra_boton_eliminacion_masiva(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.index'))
            ->assertOk()
            ->assertSee(route('admin.productos.bulk-delete'), false);
    }

    public function test_requiere_archivo_para_procesar(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'DEL-A',
            'prod_nombre' => 'PRODUCTO A',
            'prod_valor' => 1000,
            'prod_familia' => 'PAPEL',
        ]);

        $this->actingAs($this->superadmin)
            ->post(route('admin.productos.bulk-delete.process'), [])
            ->assertSessionHasErrors('archivo');

        $this->assertDatabaseHas('maeprod', ['prod_item' => 'DEL-A']);
    }

    public function test_elimina_encontrados_y_reporta_no_encontrados(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'OK-1',
            'prod_nombre' => 'EXISTE',
            'prod_valor' => 1000,
            'prod_familia' => 'PAPEL',
        ]);
        Maeprod::query()->create([
            'prod_item' => 'OK-2',
            'prod_nombre' => 'TAMBIEN EXISTE',
            'prod_valor' => 2000,
            'prod_familia' => 'PAPEL',
        ]);

        $file = $this->excelWithCodes(['OK-1', 'MISSING', 'OK-2', 'OK-1']);

        $response = $this->actingAs($this->superadmin)
            ->post(route('admin.productos.bulk-delete.process'), [
                'archivo' => $file,
            ]);

        $response->assertRedirect(route('admin.productos.bulk-delete'))
            ->assertSessionHas('success')
            ->assertSessionHas('bulk_delete_result');

        $resultado = session('bulk_delete_result');
        $this->assertSame(2, $resultado['deleted']);
        $this->assertSame(2, $resultado['not_deleted']);
        $this->assertSame(4, $resultado['total']);

        $motivos = collect($resultado['failures'])->pluck('motivo', 'codigo');
        $this->assertStringContainsString('no encontrado', mb_strtolower((string) $motivos['MISSING']));
        $this->assertStringContainsString('duplicado', mb_strtolower((string) $motivos['OK-1']));

        $this->assertDatabaseMissing('maeprod', ['prod_item' => 'OK-1']);
        $this->assertDatabaseMissing('maeprod', ['prod_item' => 'OK-2']);
    }

    public function test_acepta_csv_con_columna_codigo(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'CSV01',
            'prod_nombre' => 'CSV PRODUCTO',
            'prod_valor' => 500,
            'prod_familia' => 'PAPEL',
        ]);

        $csv = "codigo\nCSV01\nNO-EXISTE\n";
        $file = UploadedFile::fake()->createWithContent('borrar.csv', $csv);

        $this->actingAs($this->superadmin)
            ->post(route('admin.productos.bulk-delete.process'), [
                'archivo' => $file,
            ])
            ->assertRedirect(route('admin.productos.bulk-delete'))
            ->assertSessionHas('bulk_delete_result');

        $resultado = session('bulk_delete_result');
        $this->assertSame(1, $resultado['deleted']);
        $this->assertSame(1, $resultado['not_deleted']);
        $this->assertDatabaseMissing('maeprod', ['prod_item' => 'CSV01']);
    }

    public function test_puede_descargar_plantilla(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.bulk-delete.template'))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    /**
     * @param  list<string>  $codes
     */
    private function excelWithCodes(array $codes): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(array_merge([['codigo']], array_map(static fn ($c) => [$c], $codes)));

        $tmp = tempnam(sys_get_temp_dir(), 'bulkdel_');
        $this->assertNotFalse($tmp);
        $path = $tmp.'.xlsx';
        rename($tmp, $path);
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'eliminacion.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            UPLOAD_ERR_OK,
            true,
        );
    }
}
