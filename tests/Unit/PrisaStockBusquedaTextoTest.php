<?php

namespace Tests\Unit;

use App\Services\PrisaStockService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrisaStockBusquedaTextoTest extends TestCase
{
    public function test_filas_desde_busqueda_parsea_resultados(): void
    {
        $html = '<div data-page-component-options="'
            .htmlspecialchars(json_encode([
                'data' => ['data' => [
                    ['sku' => '11827', 'name' => 'Papel bond', 'availability' => 9103, 'view_link' => '/papel-bond'],
                    ['sku' => '99999', 'name' => 'Otro', 'availability' => 9102, 'view_link' => '/otro'],
                ]],
            ]), ENT_QUOTES).'"></div>';

        $svc = app(PrisaStockService::class);
        $filas = $svc->filasDesdeBusqueda($html);

        $this->assertIsArray($filas);
        $this->assertCount(2, $filas);
        $this->assertSame('11827', $filas[0]['sku']);
    }

    public function test_buscar_por_texto_devuelve_skus_con_stock(): void
    {
        config([
            'cotiz.prisa.base_url' => 'https://prisa.test',
            'cotiz.prisa.cache_horas' => 0,
            'cotiz.prisa.habilitado' => true,
        ]);
        Cache::forget('prisa_stock:cookie');

        $html = '<div data-page-component-options="'
            .htmlspecialchars(json_encode([
                'data' => ['data' => [
                    ['sku' => 'TORN001', 'name' => 'Tornillo 8x1', 'availability' => 9103, 'view_link' => '/tornillo', 'minimal_price' => 11900],
                ]],
            ]), ENT_QUOTES).'"></div>';

        Http::fake(['https://prisa.test/product/search*' => Http::response($html)]);

        $resultado = app(PrisaStockService::class)->buscarPorTexto('tornillo autoperforante');

        $this->assertIsArray($resultado);
        $this->assertCount(1, $resultado);
        $this->assertSame('TORN001', $resultado[0]['sku']);
        $this->assertSame(PrisaStockService::ESTADO_DISPONIBLE, $resultado[0]['stock_prisa']['estado']);
        $this->assertSame(11900, $resultado[0]['precio_clp']);
    }
}
