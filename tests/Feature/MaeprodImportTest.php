<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\User;
use App\Services\MaeprodImportLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
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
        $uploadId = (string) Str::uuid();
        $file = UploadedFile::fake()->createWithContent('productos.csv', $csv);

        $this->withoutMiddleware()
            ->actingAs($admin)
            ->postJson(route('admin.productos.import.chunk'), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 1,
                'original_name' => 'productos.csv',
                'chunk' => $file,
            ])
            ->assertOk();

        $processResponse = $this->withoutMiddleware()
            ->actingAs($admin)
            ->postJson(route('admin.productos.import.process'), [
                'upload_id' => $uploadId,
            ]);

        $processResponse->assertOk()->assertJson(['finished' => true]);

        $errors = session('import_errors');
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);
        $this->assertSame('BAD001', $errors[0]['codigo']);
        $this->assertSame('PRODUCTO MAL', $errors[0]['nombre']);
        $this->assertStringContainsString('Precio inválido', $errors[0]['mensaje']);
    }

    public function test_ejecutivo_cannot_access_bulk_import(): void
    {
        $user = User::factory()->create(['perfil' => User::PERFIL_EJECUTIVO]);

        $this->withMiddleware()
            ->actingAs($user)
            ->get(route('admin.productos.import'))
            ->assertForbidden();
    }

    private function importCsvAsAdmin(User $admin, string $csv): void
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

        $processResponse = $this->withoutMiddleware()
            ->actingAs($admin)
            ->postJson(route('admin.productos.import.process'), [
                'upload_id' => $jobUploadId,
            ]);

        $processResponse->assertOk()
            ->assertJson(['finished' => true])
            ->assertJsonStructure(['redirect']);
    }
}
