<?php

namespace Tests\Unit;

use App\Services\CompraAgilRegionScope;
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

    public function test_orden_presupuesto_y_luego_region(): void
    {
        $items = [
            ['monto_presupuesto_clp' => 100_000, 'region' => 13],
            ['monto_presupuesto_clp' => 500_000, 'region' => 12],
            ['monto_presupuesto_clp' => 500_000, 'region' => 13],
            ['monto_presupuesto_clp' => 200_000, 'region' => 5],
        ];

        usort($items, function (array $a, array $b): int {
            $montoA = (int) ($a['monto_presupuesto_clp'] ?? 0);
            $montoB = (int) ($b['monto_presupuesto_clp'] ?? 0);
            if ($montoA !== $montoB) {
                return $montoB <=> $montoA;
            }

            return CompraAgilRegionScope::distanciaASantiago($a['region'] ?? null)
                <=> CompraAgilRegionScope::distanciaASantiago($b['region'] ?? null);
        });

        $this->assertSame(13, $items[0]['region']);
        $this->assertSame(500_000, $items[0]['monto_presupuesto_clp']);
        $this->assertSame(12, $items[1]['region']);
        $this->assertSame(200_000, $items[2]['monto_presupuesto_clp']);
    }
}
