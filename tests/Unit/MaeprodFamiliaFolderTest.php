<?php

namespace Tests\Unit;

use App\Models\Maeprod;
use Database\Seeders\FamprodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaeprodFamiliaFolderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FamprodSeeder::class);
    }

    public function test_resolve_familia_folder_for_codigo(): void
    {
        $this->assertSame('LIBR', Maeprod::resolveFamiliaFolderFor('LIBR'));
    }

    public function test_resolve_familia_folder_for_nombre_catalogo(): void
    {
        $this->assertSame('LIBR', Maeprod::resolveFamiliaFolderFor('LIBRERIA'));
    }

    public function test_resolve_familia_folder_for_legacy_aliases(): void
    {
        $this->assertSame('PAPEL', Maeprod::resolveFamiliaFolderFor('PAPELERIA'));
    }

    public function test_build_external_image_url_usa_misma_carpeta_que_subida(): void
    {
        $producto = new Maeprod([
            'prod_item' => 'MGHAMA117',
            'prod_familia' => 'LIBRERIA',
            'prod_imagen' => 'MGHAMA117.jpg',
        ]);

        config(['products.image_base_url' => 'https://cdn.example/productos']);

        $this->assertSame(
            'https://cdn.example/productos/LIBR/MGHAMA117.jpg',
            $producto->buildExternalImageUrl(),
        );
    }
}
