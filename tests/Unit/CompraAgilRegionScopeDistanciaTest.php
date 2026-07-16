<?php

namespace Tests\Unit;

use App\Services\CompraAgilRegionScope;
use App\Services\OportunidadParaCotizarService;
use PHPUnit\Framework\TestCase;

class CompraAgilRegionScopeDistanciaTest extends TestCase
{
    public function test_metropolitana_es_la_mas_cercana_a_santiago(): void
    {
        $this->assertSame(0, CompraAgilRegionScope::distanciaASantiago(13));
        $this->assertTrue(
            CompraAgilRegionScope::distanciaASantiago(5)
            < CompraAgilRegionScope::distanciaASantiago(12)
        );
        $this->assertSame(99, CompraAgilRegionScope::distanciaASantiago(null));
    }

    public function test_orden_presupuesto_y_luego_menos_productos(): void
    {
        $servicio = (new \ReflectionClass(OportunidadParaCotizarService::class))
            ->newInstanceWithoutConstructor();

        $items = [
            ['codigo' => 'A', 'monto_presupuesto_clp' => 100_000, 'cantidad_productos' => 1],
            ['codigo' => 'B', 'monto_presupuesto_clp' => 500_000, 'cantidad_productos' => 5],
            ['codigo' => 'C', 'monto_presupuesto_clp' => 500_000, 'cantidad_productos' => 2],
            ['codigo' => 'D', 'monto_presupuesto_clp' => 200_000, 'cantidad_productos' => 3],
        ];

        usort($items, [$servicio, 'compararOportunidades']);

        $this->assertSame(['C', 'B', 'D', 'A'], array_column($items, 'codigo'));
    }
}
