<?php

namespace Tests\Unit;

use App\Services\MaeprodEmbeddingService;
use App\Models\Maeprod;
use Tests\TestCase;

class MaeprodEmbeddingServiceTest extends TestCase
{
    public function test_texto_para_producto_incluye_item_nombre_y_familia(): void
    {
        $service = app(MaeprodEmbeddingService::class);
        $producto = new Maeprod([
            'prod_item' => 'DEMO001',
            'prod_nombre' => 'PAPEL BOND',
            'prod_familia' => 'PAPEL',
        ]);

        $this->assertSame('DEMO001 — PAPEL BOND — PAPEL', $service->textoParaProducto($producto));
    }

    public function test_vector_literal_dimension_configurada(): void
    {
        config(['cotiz.busqueda_vectores.dimension' => 64]);
        $service = app(MaeprodEmbeddingService::class);
        $literal = $service->vectorLiteral(array_merge([0.1, 0.2, 0.3], array_fill(0, 61, 0.0)));
        $this->assertStringStartsWith('[0.1,0.2,0.3,', $literal);
        $this->assertSame(64, count(explode(',', trim($literal, '[]'))));
    }

    public function test_vectores_deshabilitados_en_sqlite(): void
    {
        $service = app(MaeprodEmbeddingService::class);
        $this->assertFalse($service->vectoresHabilitados());
        $this->assertTrue($service->buscarSimilares('papel bond', null, 5)->isEmpty());
    }
}
