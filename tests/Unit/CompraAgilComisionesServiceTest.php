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

    #[Test]
    public function ganada_requiere_rut_grupo_y_orden_compra(): void
    {
        $service = app(CompraAgilComisionesService::class);
        $ref = new \ReflectionClass($service);

        $esGanada = $ref->getMethod('esGanadaParaComision');
        $esGanada->setAccessible(true);

        config([
            'cotiz.reicol_rut' => '76.111.111-1',
            'cotiz.romulo_rut' => '76.222.222-2',
        ]);

        $segConOc = new \App\Models\NotaMpSeguimiento(['id_orden_compra' => '12345', 'rut_ganador' => '76.111.111-1']);
        $segSinOc = new \App\Models\NotaMpSeguimiento(['id_orden_compra' => null, 'rut_ganador' => '76.111.111-1']);
        $notaConOc = new \App\Models\Nota(['ocompra' => '4500123456']);
        $notaSinOc = new \App\Models\Nota(['ocompra' => '']);

        $this->assertTrue($esGanada->invoke($service, '76.111.111-1', $notaConOc));
        $this->assertFalse($esGanada->invoke($service, '76.222.222-2', $notaSinOc));
        $this->assertFalse($esGanada->invoke($service, '76.111.111-1', $notaSinOc));
        $this->assertFalse($esGanada->invoke($service, '11.111.111-1', $notaConOc));
    }

    #[Test]
    public function participacion_mp_usa_empresa_propia_de_la_instancia(): void
    {
        $service = app(CompraAgilComisionesService::class);
        $ref = new \ReflectionClass($service);
        $participo = $ref->getMethod('participoEmpresaPropia');
        $participo->setAccessible(true);

        config(['cotiz.empresa_rut' => '76.185.139-K']);

        $seg = new \App\Models\NotaMpSeguimiento(['nronota' => 1]);
        $seg->setRelation('ofertas', collect([
            new \App\Models\NotaMpOferta([
                'rut_proveedor' => '76.111.111-1',
                'es_propio' => false,
            ]),
        ]));
        $this->assertFalse($participo->invoke($service, $seg));

        $segPropio = new \App\Models\NotaMpSeguimiento(['nronota' => 2]);
        $segPropio->setRelation('ofertas', collect([
            new \App\Models\NotaMpOferta([
                'rut_proveedor' => '76.185.139-K',
                'es_propio' => true,
            ]),
        ]));
        $this->assertTrue($participo->invoke($service, $segPropio));

        $segPorRut = new \App\Models\NotaMpSeguimiento(['nronota' => 3]);
        $segPorRut->setRelation('ofertas', collect([
            new \App\Models\NotaMpOferta([
                'rut_proveedor' => '76185139K',
                'es_propio' => false,
            ]),
        ]));
        $this->assertTrue($participo->invoke($service, $segPorRut));
    }
}
