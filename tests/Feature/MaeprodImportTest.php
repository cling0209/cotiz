<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\MaeprodImportErrorLog;
use App\Models\MaeprodImportRun;
use App\Models\User;
use App\Services\MaeprodImportLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MaeprodImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(MaeprodImportLockService::CACHE_KEY);
    }

    public function test_superadmin_can_download_import_template(): void
    {
        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        $response = $this->actingAs($admin)->get(route('admin.productos.import.template'));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('codigo;nombre;familia;precio', $response->streamedContent());
    }

    public function test_superadmin_can_download_import_template_excel(): void
    {
        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        $response = $this->actingAs($admin)->get(route('admin.productos.import.template.excel'));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('plantilla_productos_maeprod.xlsx', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('PK', $response->streamedContent());
    }

    public function test_superadmin_can_import_products_from_excel_template(): void
    {
        $admin = User::factory()->create([
            'perfil' => User::PERFIL_SUPERADMIN,
            'username' => 'admin',
        ]);

        $this->importExcelAsAdmin($admin, [
            ['codigo', 'nombre', 'familia', 'precio', 'costo', 'nombre_archivo', 'gramaje', 'stock', 'softland'],
            ['XLS001', 'PRODUCTO EXCEL', 'PAPEL', 7500, 6000, 'XLS001_medium.jpg', '75 GR', 5, 'SLX01'],
        ]);

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'XLS001',
            'prod_nombre' => 'PRODUCTO EXCEL',
            'prod_familia' => 'PAPEL',
            'prod_valor' => 7500,
            'prod_valor_costo' => 6000,
            'prod_item_softland' => 'SLX01',
        ]);
    }

    public function test_excel_import_works_when_uploaded_in_multiple_chunks(): void
    {
        $admin = User::factory()->create([
            'perfil' => User::PERFIL_SUPERADMIN,
            'username' => 'admin',
        ]);

        $file = $this->makeExcelUpload('productos.xlsx', [
            ['codigo', 'nombre', 'familia', 'precio'],
            ['XLS003', 'EXCEL CHUNKED', 'PAPEL', 3300],
        ]);

        $content = file_get_contents($file->getPathname());
        $this->assertNotFalse($content);

        $totalChunks = 2;
        $splitAt = (int) floor(strlen($content) / 2);
        $uploadId = (string) Str::uuid();
        $jobUploadId = null;

        for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
            $partPath = tempnam(sys_get_temp_dir(), 'maeprod_chunk_test_');
            $this->assertNotFalse($partPath);
            $partBytes = $chunkIndex === 0
                ? substr($content, 0, $splitAt)
                : substr($content, $splitAt);
            file_put_contents($partPath, $partBytes);

            $chunk = new UploadedFile(
                $partPath,
                'chunk-'.$chunkIndex.'.part',
                'application/octet-stream',
                UPLOAD_ERR_OK,
                true,
            );

            $chunkResponse = $this->withoutMiddleware()
                ->actingAs($admin)
                ->postJson(route('admin.productos.import.chunk'), [
                    'upload_id' => $uploadId,
                    'chunk_index' => $chunkIndex,
                    'total_chunks' => $totalChunks,
                    'original_name' => 'productos.xlsx',
                    'chunk' => $chunk,
                ]);

            $chunkResponse->assertOk();

            if ($chunkIndex === $totalChunks - 1) {
                $chunkResponse->assertJson(['done' => true]);
                $jobUploadId = $chunkResponse->json('upload_id');
            }
        }

        $this->assertNotNull($jobUploadId);

        $this->processImportUntilFinished($admin, $jobUploadId, true);

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'XLS003',
            'prod_nombre' => 'EXCEL CHUNKED',
            'prod_familia' => 'PAPEL',
            'prod_valor' => 3300,
        ]);
    }

    public function test_custom_excel_import_with_column_mapping(): void
    {
        $admin = User::factory()->create([
            'perfil' => User::PERFIL_SUPERADMIN,
            'username' => 'admin',
        ]);

        $uploadId = (string) Str::uuid();
        $file = $this->makeExcelUpload('custom.xlsx', [
            ['sku', 'descripcion', 'categoria', 'pvp'],
            ['XLS002', 'EXCEL CUSTOM', 'LIBR', 2200],
        ]);

        $this->withoutMiddleware()
            ->actingAs($admin)
            ->postJson(route('admin.productos.import.chunk'), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 1,
                'original_name' => 'custom.xlsx',
                'mode' => 'custom',
                'chunk' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('pending_parse', true);

        $this->withoutMiddleware()
            ->actingAs($admin)
            ->postJson(route('admin.productos.import.initialize'), [
                'upload_id' => $uploadId,
            ])
            ->assertOk();

        $mapping = [
            'codigo' => 'sku',
            'nombre' => 'descripcion',
            'familia' => 'categoria',
            'precio' => 'pvp',
        ];

        $prepared = $this->prepareCustomUntilFinished($admin, $uploadId, $mapping);

        $this->processImportUntilFinished($admin, (string) $prepared['upload_id']);

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'XLS002',
            'prod_nombre' => 'EXCEL CUSTOM',
            'prod_familia' => 'LIBR',
            'prod_valor' => 2200,
        ]);
    }

    public function test_streaming_csv_import_handles_many_rows(): void
    {
        $admin = User::factory()->create([
            'perfil' => User::PERFIL_SUPERADMIN,
            'username' => 'admin',
        ]);

        $lines = ['codigo;nombre;familia;precio'];
        for ($i = 1; $i <= 2500; $i++) {
            $lines[] = "BLK{$i};PRODUCTO {$i};PAPEL;1000";
        }

        $this->importCsvAsAdmin($admin, "\xEF\xBB\xBF".implode("\n", $lines));

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'BLK2500',
            'prod_nombre' => 'PRODUCTO 2500',
            'prod_familia' => 'PAPEL',
            'prod_valor' => 1000,
        ]);
    }

    public function test_superadmin_can_import_products_from_csv(): void
    {
        $admin = User::factory()->create([
            'perfil' => User::PERFIL_SUPERADMIN,
            'username' => 'admin',
        ]);

        $csv = "codigo;nombre;familia;precio;costo;nombre_archivo;gramaje;stock;softland\n";
        $csv .= "IMP001;PRODUCTO IMPORTADO;PAPEL;5000;4000;IMP001_medium.jpg;75 GR;10;SL001\n";

        $this->importCsvAsAdmin($admin, "\xEF\xBB\xBF".$csv);

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'IMP001',
            'prod_nombre' => 'PRODUCTO IMPORTADO',
            'prod_familia' => 'PAPEL',
            'prod_valor' => 5000,
            'prod_valor_costo' => 4000,
            'prod_item_softland' => 'SL001',
        ]);
    }

    public function test_import_updates_existing_product_by_code(): void
    {
        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        Maeprod::query()->create([
            'prod_item' => 'IMP002',
            'prod_nombre' => 'ANTIGUO',
            'prod_familia' => 'PAPEL',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
        ]);

        $csv = "codigo;nombre;familia;precio;costo\nIMP002;ACTUALIZADO;PAPEL;2500;2000\n";

        $this->importCsvAsAdmin($admin, $csv);

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'IMP002',
            'prod_nombre' => 'ACTUALIZADO',
            'prod_valor' => 2500,
        ]);
    }

    public function test_import_mixed_batch_with_new_and_existing_rows(): void
    {
        $admin = User::factory()->create([
            'perfil' => User::PERFIL_SUPERADMIN,
            'username' => 'admin',
        ]);

        Maeprod::query()->create([
            'prod_item' => 'IMP003',
            'prod_nombre' => 'EXISTENTE',
            'prod_familia' => 'LIBR',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'prod_valor_fecha' => now()->subDay(),
            'prod_user_upd' => 'legacy',
        ]);

        $csv = "codigo;nombre;familia;precio;costo;gramaje;softland\n";
        $csv .= "IMP003;EXISTENTE SIN CAMBIO PRECIO;LIBR;1000;800;unidad;LIBR1135\n";
        $csv .= "IMP004;PRODUCTO NUEVO;LIBR;365;0;unidad;LIBR1136\n";

        $this->importCsvAsAdmin($admin, $csv);

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'IMP003',
            'prod_nombre' => 'EXISTENTE SIN CAMBIO PRECIO',
            'prod_valor' => 1000,
            'prod_user_upd' => 'legacy',
        ]);

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'IMP004',
            'prod_nombre' => 'PRODUCTO NUEVO',
            'prod_valor' => 365,
            'prod_user_upd' => 'admin',
        ]);
    }

    public function test_import_uses_last_occurrence_when_csv_has_duplicate_codes(): void
    {
        $admin = User::factory()->create([
            'perfil' => User::PERFIL_SUPERADMIN,
            'username' => 'admin',
        ]);

        $csv = "codigo;nombre;familia;precio;costo\n";
        $csv .= "DUP001;PRIMERA VERSION;PAPEL;1000;800\n";
        $csv .= "DUP001;SEGUNDA VERSION;PAPEL;2000;1500\n";

        $this->importCsvAsAdmin($admin, $csv);

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'DUP001',
            'prod_nombre' => 'SEGUNDA VERSION',
            'prod_valor' => 2000,
            'prod_valor_costo' => 1500,
        ]);

        $this->assertSame(1, Maeprod::query()->where('prod_item', 'DUP001')->count());
    }

    public function test_abandoned_import_lock_is_cleared_on_status_check(): void
    {
        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);
        $uploadId = (string) Str::uuid();

        app(MaeprodImportLockService::class)->acquire(
            $admin->id,
            $admin->username,
            $uploadId,
            'atascado.xlsx',
        );

        $response = $this->actingAs($admin)->getJson(route('admin.productos.import.status'));

        $response->assertOk()->assertJson(['active' => false, 'lock' => null]);
        $this->assertNull(app(MaeprodImportLockService::class)->current());
    }

    public function test_superadmin_can_force_release_import_lock(): void
    {
        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        app(MaeprodImportLockService::class)->acquire(
            $admin->id,
            'admin',
            (string) Str::uuid(),
            'bloqueado.xlsx',
        );

        $this->withoutMiddleware()
            ->actingAs($admin)
            ->postJson(route('admin.productos.import.unlock'))
            ->assertOk()
            ->assertJson(['released' => true]);

        $this->assertNull(app(MaeprodImportLockService::class)->current());
    }

    public function test_second_import_is_blocked_while_first_is_active(): void
    {
        $admin1 = User::factory()->create([
            'username' => 'admin1',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        $admin2 = User::factory()->create([
            'username' => 'admin2',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        app(MaeprodImportLockService::class)->acquire(
            $admin1->id,
            'admin1',
            (string) Str::uuid(),
            'primero.csv',
        );

        $file = UploadedFile::fake()->createWithContent(
            'productos.csv',
            "codigo;nombre;familia;precio\nX001;PRODUCTO;PAPEL;1000\n",
        );

        $response = $this->withoutMiddleware()
            ->actingAs($admin2)
            ->postJson(route('admin.productos.import.chunk'), [
                'upload_id' => (string) Str::uuid(),
                'chunk_index' => 0,
                'total_chunks' => 1,
                'original_name' => 'productos.csv',
                'chunk' => $file,
            ]);

        $response->assertStatus(409);

        $this->assertStringContainsString(
            'importación en curso',
            (string) $response->json('message'),
        );
    }

    public function test_import_reports_row_details_for_validation_errors(): void
    {
        $admin = User::factory()->create([
            'perfil' => User::PERFIL_SUPERADMIN,
            'username' => 'admin',
        ]);

        $csv = "codigo;nombre;familia;precio\nBAD001;PRODUCTO MAL;PAPEL;no-es-numero\n";
        $processResponse = $this->importCsvAsAdmin($admin, $csv);

        $processResponse->assertJsonPath('finished', true);

        $redirect = (string) $processResponse->json('redirect');
        $this->assertStringContainsString('/carga-masiva/errores/', $redirect);

        $run = MaeprodImportRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame(1, $run->total_errores);

        $error = MaeprodImportErrorLog::query()->where('run_id', $run->id)->first();
        $this->assertNotNull($error);
        $this->assertSame('BAD001', $error->codigo);
        $this->assertSame('PRODUCTO MAL', $error->nombre);
        $this->assertStringContainsString('Precio inválido', $error->mensaje);

        $this->withoutMiddleware()
            ->actingAs($admin)
            ->get(route('admin.productos.import.errores', ['run' => $run->id]))
            ->assertOk()
            ->assertSee('BAD001')
            ->assertSee('Precio inválido');
    }

    public function test_import_uses_physical_csv_line_number_with_blank_rows(): void
    {
        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        $csv = "codigo;nombre;familia;precio\n";
        $csv .= "\n";
        $csv .= "BAD002;PRODUCTO;PAPEL;no-es-numero\n";

        $this->importCsvAsAdmin($admin, $csv);

        $error = MaeprodImportErrorLog::query()->first();
        $this->assertNotNull($error);
        $this->assertSame(3, $error->fila);
    }

    public function test_import_accepts_chilean_thousands_price_format(): void
    {
        $admin = User::factory()->create([
            'perfil' => User::PERFIL_SUPERADMIN,
            'username' => 'admin',
        ]);

        $csv = "codigo;nombre;familia;precio\nCH001;PRODUCTO CARO;PAPEL;2.480.000\n";

        $processResponse = $this->importCsvAsAdmin($admin, $csv);

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'CH001',
            'prod_valor' => 2480000,
        ]);

        $redirect = (string) $processResponse->json('redirect');
        $this->assertStringContainsString('/carga-masiva/resultado/', $redirect);
    }

    public function test_successful_import_persists_run_without_errors(): void
    {
        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        $csv = "codigo;nombre;familia;precio\nOK001;PRODUCTO OK;PAPEL;1500\n";

        $this->importCsvAsAdmin($admin, $csv);

        $run = MaeprodImportRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame(1, $run->creados);
        $this->assertSame(0, $run->total_errores);
        $this->assertSame(0, MaeprodImportErrorLog::query()->count());
    }

    public function test_new_import_replaces_previous_run_errors(): void
    {
        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        $this->importCsvAsAdmin($admin, "codigo;nombre;familia;precio\nBAD001;X;PAPEL;no\n");
        $this->assertSame(1, MaeprodImportRun::query()->count());
        $this->assertSame(1, MaeprodImportErrorLog::query()->count());

        $this->importCsvAsAdmin($admin, "codigo;nombre;familia;precio\nOK002;OK;PAPEL;1000\n");
        $this->assertSame(1, MaeprodImportRun::query()->count());
        $this->assertSame(0, MaeprodImportErrorLog::query()->count());
    }

    public function test_ejecutivo_cannot_access_bulk_import(): void
    {
        $user = User::factory()->create(['perfil' => User::PERFIL_EJECUTIVO]);

        $this->withMiddleware()
            ->actingAs($user)
            ->get(route('admin.productos.import'))
            ->assertForbidden();
    }

    public function test_custom_csv_preview_does_not_persist_products(): void
    {
        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);
        $uploadId = (string) Str::uuid();

        $csv = "sku;descripcion;categoria;pvp\nCUST001;PRODUCTO CUSTOM;PAPEL;3500\n";
        $file = UploadedFile::fake()->createWithContent('custom.csv', $csv);

        $chunkResponse = $this->withoutMiddleware()
            ->actingAs($admin)
            ->postJson(route('admin.productos.import.chunk'), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 1,
                'original_name' => 'custom.csv',
                'mode' => 'custom',
                'chunk' => $file,
            ]);

        $chunkResponse->assertOk()
            ->assertJsonPath('mode', 'custom')
            ->assertJsonPath('total_rows', 1);

        $previewResponse = $this->withoutMiddleware()
            ->actingAs($admin)
            ->postJson(route('admin.productos.import.preview'), [
                'upload_id' => $uploadId,
                'mapping' => [
                    'codigo' => 'sku',
                    'nombre' => 'descripcion',
                    'familia' => 'categoria',
                    'precio' => 'pvp',
                ],
            ]);

        $previewResponse->assertOk()
            ->assertJsonPath('summary.crear', 1)
            ->assertJsonPath('rows.0.codigo', 'CUST001');

        $this->assertDatabaseMissing('maeprod', ['prod_item' => 'CUST001']);
    }

    public function test_custom_csv_import_with_column_mapping(): void
    {
        $admin = User::factory()->create([
            'perfil' => User::PERFIL_SUPERADMIN,
            'username' => 'admin',
        ]);

        $uploadId = (string) Str::uuid();
        $csv = "sku;descripcion;categoria;pvp\nCUST002;OTRO PRODUCTO;LIBR;1200\n";
        $file = UploadedFile::fake()->createWithContent('custom.csv', $csv);

        $this->withoutMiddleware()
            ->actingAs($admin)
            ->postJson(route('admin.productos.import.chunk'), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 1,
                'original_name' => 'custom.csv',
                'mode' => 'custom',
                'chunk' => $file,
            ])
            ->assertOk();

        $mapping = [
            'codigo' => 'sku',
            'nombre' => 'descripcion',
            'familia' => 'categoria',
            'precio' => 'pvp',
        ];

        $prepared = $this->prepareCustomUntilFinished($admin, $uploadId, $mapping);

        $this->processImportUntilFinished($admin, (string) $prepared['upload_id']);

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'CUST002',
            'prod_nombre' => 'OTRO PRODUCTO',
            'prod_familia' => 'LIBR',
            'prod_valor' => 1200,
        ]);
    }

    private function importCsvAsAdmin(User $admin, string $csv): \Illuminate\Testing\TestResponse
    {
        $uploadId = (string) Str::uuid();
        $file = UploadedFile::fake()->createWithContent('productos.csv', $csv);

        $chunkResponse = $this->withoutMiddleware()
            ->actingAs($admin)
            ->postJson(route('admin.productos.import.chunk'), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 1,
                'original_name' => 'productos.csv',
                'chunk' => $file,
            ]);

        $chunkResponse->assertOk()->assertJson(['done' => true]);

        $jobUploadId = $chunkResponse->json('upload_id');

        return $this->processImportUntilFinished($admin, $jobUploadId);
    }

    /**
     * @param  list<list<string|int|float>>  $rows
     */
    private function importExcelAsAdmin(User $admin, array $rows): \Illuminate\Testing\TestResponse
    {
        $uploadId = (string) Str::uuid();
        $file = $this->makeExcelUpload('productos.xlsx', $rows);

        $chunkResponse = $this->withoutMiddleware()
            ->actingAs($admin)
            ->postJson(route('admin.productos.import.chunk'), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 1,
                'original_name' => 'productos.xlsx',
                'chunk' => $file,
            ]);

        $chunkResponse->assertOk()->assertJson(['done' => true]);

        return $this->processImportUntilFinished(
            $admin,
            (string) $chunkResponse->json('upload_id'),
            $chunkResponse->json('pending_parse') === true,
        );
    }

    /**
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    private function prepareCustomUntilFinished(User $admin, string $uploadId, array $mapping): array
    {
        do {
            $prepareResponse = $this->withoutMiddleware()
                ->actingAs($admin)
                ->postJson(route('admin.productos.import.prepare'), [
                    'upload_id' => $uploadId,
                    'mapping' => $mapping,
                ]);

            $prepareResponse->assertOk()
                ->assertJsonStructure(['upload_id', 'batch_count', 'prepare_finished', 'processed_rows']);
        } while ($prepareResponse->json('prepare_finished') !== true);

        return $prepareResponse->json();
    }

    private function processImportUntilFinished(User $admin, string $uploadId, bool $pendingParse = false): \Illuminate\Testing\TestResponse
    {
        if ($pendingParse) {
            do {
                $prepareResponse = $this->withoutMiddleware()
                    ->actingAs($admin)
                    ->postJson(route('admin.productos.import.prepare.template'), [
                        'upload_id' => $uploadId,
                    ]);

                $prepareResponse->assertOk()
                    ->assertJsonStructure(['upload_id', 'batch_count', 'prepare_finished', 'processed_rows']);
            } while ($prepareResponse->json('prepare_finished') !== true);
        }

        $processResponse = null;

        do {
            $processResponse = $this->withoutMiddleware()
                ->actingAs($admin)
                ->postJson(route('admin.productos.import.process'), [
                    'upload_id' => $uploadId,
                ]);

            $processResponse->assertOk();
        } while ($processResponse->json('finished') !== true);

        $processResponse->assertJsonStructure(['redirect']);

        return $processResponse;
    }

    /**
     * @param  list<list<string|int|float>>  $rows
     */
    private function makeExcelUpload(string $filename, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);

        $tempPath = tempnam(sys_get_temp_dir(), 'maeprod_import_test_');
        $this->assertNotFalse($tempPath);

        $xlsxPath = $tempPath.'.xlsx';
        rename($tempPath, $xlsxPath);

        $writer = new Xlsx($spreadsheet);
        $writer->save($xlsxPath);

        return new UploadedFile(
            $xlsxPath,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            UPLOAD_ERR_OK,
            true,
        );
    }
}
