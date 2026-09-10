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
    public function participacion_mp_distingue_sin_proveedores_y_no_participo(): void
    {
        $service = app(CompraAgilComisionesService::class);
        $ref = new \ReflectionClass($service);
        $estado = $ref->getMethod('estadoParticipacionMp');
        $estado->setAccessible(true);

        config(['cotiz.empresa_rut' => '76.185.139-K']);

        $sinOfertas = new \App\Models\NotaMpSeguimiento(['nronota' => 1]);
        $sinOfertas->setRelation('ofertas', collect());
        $this->assertSame(
            CompraAgilComisionesService::PARTICIPACION_SIN_PROVEEDORES,
            $estado->invoke($service, $sinOfertas),
        );

        $ajeno = new \App\Models\NotaMpSeguimiento(['nronota' => 2]);
        $ajeno->setRelation('ofertas', collect([
            new \App\Models\NotaMpOferta([
                'rut_proveedor' => '76.111.111-1',
                'es_propio' => false,
            ]),
        ]));
        $this->assertSame(
            CompraAgilComisionesService::PARTICIPACION_NO,
            $estado->invoke($service, $ajeno),
        );

        $propio = new \App\Models\NotaMpSeguimiento(['nronota' => 3]);
        $propio->setRelation('ofertas', collect([
            new \App\Models\NotaMpOferta([
                'rut_proveedor' => '76.185.139-K',
                'es_propio' => true,
            ]),
        ]));
        $this->assertSame(
            CompraAgilComisionesService::PARTICIPACION_SI,
            $estado->invoke($service, $propio),
        );
    }

    #[Test]
    public function fecha_envio_usa_oc_en_cerrada_propia_y_ultimo_cambio_en_ajena(): void
    {
        $service = app(CompraAgilComisionesService::class);
        $ref = new \ReflectionClass($service);
        $fecha = $ref->getMethod('fechaEnvioOUltimaModificacion');
        $fecha->setAccessible(true);

        config([
            'cotiz.reicol_rut' => '76.111.111-1',
            'cotiz.romulo_rut' => '76.222.222-2',
        ]);

        $envio = \Illuminate\Support\Carbon::parse('2026-09-01 10:00:00');
        $cambio = \Illuminate\Support\Carbon::parse('2026-09-05 15:30:00');

        $propia = new \App\Models\NotaMpSeguimiento([
            'resultado_propio' => 'cerrada',
            'rut_ganador' => '76.111.111-1',
            'oc_fecha_envio' => $envio,
            'fecha_ultimo_cambio' => $cambio,
        ]);
        $this->assertTrue($envio->equalTo($fecha->invoke($service, $propia)));

        $ajena = new \App\Models\NotaMpSeguimiento([
            'resultado_propio' => 'cerrada',
            'rut_ganador' => '11.111.111-1',
            'oc_fecha_envio' => $envio,
            'fecha_ultimo_cambio' => $cambio,
        ]);
        $this->assertTrue($cambio->equalTo($fecha->invoke($service, $ajena)));

        $desierta = new \App\Models\NotaMpSeguimiento([
            'resultado_propio' => 'desierta',
            'rut_ganador' => null,
            'oc_fecha_envio' => $envio,
            'fecha_ultimo_cambio' => $cambio,
        ]);
        $this->assertTrue($cambio->equalTo($fecha->invoke($service, $desierta)));
    }

    #[Test]
    public function resultados_visibles_solo_cerrada_desierta_cancelada(): void
    {
        $this->assertSame(
            ['cerrada', 'desierta', 'cancelada'],
            CompraAgilComisionesService::RESULTADOS_VISIBLE,
        );
    }
}
