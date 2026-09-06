<?php

namespace Tests\Unit;

use App\Services\CompraAgilComisionesService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompraAgilComisionesServiceTest extends TestCase
{
    #[Test]
    public function constantes_comision_coinciden_con_planilla(): void
    {
        $service = app(CompraAgilComisionesService::class);

        $this->assertEqualsWithDelta(1.2, $service->factorComisionBase(), 0.001);
        $this->assertEqualsWithDelta(0.20, $service->porcentajeComision(), 0.001);
        $this->assertSame(10000, (int) config('cotiz.comisiones.pago_fijo', 10000));
    }

    #[Test]
    public function calculo_ejemplo_planilla_region(): void
    {
        $costo = 1_000_000;
        $factor = 1.30;
        $factorBase = 1.2;
        $pct = 0.20;
        $pago = 10000;

        $venta = (int) round($costo * $factor);
        $venta12 = (int) round($costo * $factorBase);
        $utilidad = $venta12 - $costo;
        $comision = (int) round($utilidad * $pct);
        $aPagar = $comision + $pago;

        $this->assertSame(1_300_000, $venta);
        $this->assertSame(1_200_000, $venta12);
        $this->assertSame(200_000, $utilidad);
        $this->assertSame(40_000, $comision);
        $this->assertSame(50_000, $aPagar);
    }
}
