<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProductImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_new_product_from_csv(): void
    {
        $category = Category::create([
            'name' => 'Libros',
            'slug' => 'lib',
            'is_active' => true,
        ]);

        $csv = "sku;nombre;precio;stock;familia\n";
        $csv .= 'IMP-001;Producto importado;19990;10;LIB';

        $file = UploadedFile::fake()->createWithContent('productos.csv', "\xEF\xBB\xBF".$csv);

        $result = app(ProductImportService::class)->importFromUploadedFile($file);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertDatabaseHas('products', [
            'sku' => 'IMP-001',
            'name' => 'Producto importado',
            'category_id' => $category->id,
            'familia' => 'LIB',
            'stock' => 10,
        ]);
    }

    public function test_updates_existing_product_by_sku(): void
    {
        $category = Category::create([
            'name' => 'Libros',
            'slug' => 'lib',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'sku' => 'IMP-002',
            'name' => 'Antes',
            'slug' => 'antes',
            'familia' => 'LIB',
            'price' => 1000,
            'stock' => 1,
            'is_active' => true,
        ]);

        $csv = "sku;nombre;precio;stock;familia\nIMP-002;Después;5000;9;LIB";
        $file = UploadedFile::fake()->createWithContent('productos.csv', $csv);

        $result = app(ProductImportService::class)->importFromUploadedFile($file);

        $this->assertSame(1, $result['updated']);
        $product->refresh();
        $this->assertSame('Después', $product->name);
        $this->assertSame(9, $product->stock);
    }

    public function test_imports_multiple_products_in_one_batch(): void
    {
        Category::create([
            'name' => 'Libros',
            'slug' => 'lib',
            'is_active' => true,
        ]);

        $csv = "sku;nombre;precio;stock;familia\n";
        $csv .= "IMP-010;Producto A;1000;1;LIB\n";
        $csv .= "IMP-011;Producto B;2000;2;LIB\n";
        $csv .= "IMP-012;Producto C;3000;3;LIB";

        $file = UploadedFile::fake()->createWithContent('productos.csv', $csv);

        $result = app(ProductImportService::class)->importFromUploadedFile($file);

        $this->assertSame(3, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseCount('products', 3);
    }

    public function test_reactivates_soft_deleted_product_by_sku(): void
    {
        $category = Category::create([
            'name' => 'Libros',
            'slug' => 'lib',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'sku' => 'IMP-020',
            'name' => 'Archivado',
            'slug' => 'archivado',
            'familia' => 'LIB',
            'price' => 1000,
            'stock' => 1,
            'is_active' => true,
        ]);

        $product->delete();

        $csv = "sku;nombre;precio;stock;familia\nIMP-020;Reactivado;5000;9;LIB";
        $file = UploadedFile::fake()->createWithContent('productos.csv', $csv);

        $result = app(ProductImportService::class)->importFromUploadedFile($file);

        $this->assertSame(1, $result['reactivated']);
        $product->refresh();
        $this->assertNull($product->deleted_at);
        $this->assertSame('Reactivado', $product->name);
        $this->assertSame(9, $product->stock);
    }

    public function test_prepare_bulk_import_splits_valid_and_invalid_rows(): void
    {
        Category::create([
            'name' => 'Libros',
            'slug' => 'lib',
            'is_active' => true,
        ]);

        $rows = [
            ['sku' => 'BULK-1', 'nombre' => 'Uno', 'precio' => '1000', 'stock' => '1', 'familia' => 'LIB'],
            ['sku' => '', 'nombre' => 'Sin SKU', 'precio' => '1000', 'stock' => '1', 'familia' => 'LIB'],
        ];

        $prepared = app(ProductImportService::class)->prepareBulkImport($rows);

        $this->assertCount(1, $prepared['staging']);
        $this->assertSame(1, $prepared['created']);
        $this->assertSame(1, $prepared['skipped']);
        $this->assertNotEmpty($prepared['errors']);
    }

    public function test_imports_csv_saved_with_windows_latin1_encoding(): void
    {
        Category::create([
            'name' => 'Librería',
            'slug' => 'libr',
            'is_active' => true,
        ]);

        $line = 'IMP-003;Pizarra Acrílica Arcovi;19990;5;LIBR';
        $latin1 = mb_convert_encoding($line, 'Windows-1252', 'UTF-8');
        $csv = "sku;nombre;precio;stock;familia\n".$latin1;

        $file = UploadedFile::fake()->createWithContent('productos.csv', $csv);

        $result = app(ProductImportService::class)->importFromUploadedFile($file);

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('products', [
            'sku' => 'IMP-003',
            'name' => 'Pizarra Acrílica Arcovi',
        ]);
    }
}
