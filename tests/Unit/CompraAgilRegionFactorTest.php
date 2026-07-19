<?php

namespace Tests\Unit;

use App\Services\CompraAgilRegionScope;
use Tests\TestCase;

class CompraAgilRegionFactorTest extends TestCase
{
    public function test_metropolitana_factor_y_dias(): void
    {
        config([
            'cotiz.factor_precio_venta_rm' => 1.22,
            'cotiz.factor_precio_venta_otras' => 1.30,
            'cotiz.diashabiles_rm' => 5,
            'cotiz.diashabiles_otras' => 10,
        ]);

        $this->assertTrue(CompraAgilRegionScope::esMetropolitana(13));
        $this->assertEqualsWithDelta(1.22, CompraAgilRegionScope::factorPrecioVentaPorRegion(13), 0.001);
        $this->assertSame(5, CompraAgilRegionScope::diasHabilesPorRegion(13));
    }

    public function test_otra_region_factor_y_dias(): void
    {
        config([
            'cotiz.factor_precio_venta_rm' => 1.22,
            'cotiz.factor_precio_venta_otras' => 1.30,
            'cotiz.diashabiles_rm' => 5,
            'cotiz.diashabiles_otras' => 10,
        ]);

        $this->assertFalse(CompraAgilRegionScope::esMetropolitana(5));
        $this->assertEqualsWithDelta(1.30, CompraAgilRegionScope::factorPrecioVentaPorRegion(5), 0.001);
        $this->assertSame(10, CompraAgilRegionScope::diasHabilesPorRegion(5));
    }

    public function test_sin_region_no_sugiere(): void
    {
        $this->assertNull(CompraAgilRegionScope::factorPrecioVentaPorRegion(null));
        $this->assertNull(CompraAgilRegionScope::diasHabilesPorRegion(null));
    }
}
