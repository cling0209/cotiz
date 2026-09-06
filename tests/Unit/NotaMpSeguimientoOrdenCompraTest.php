<?php

namespace Tests\Unit;

use App\Models\Nota;
use App\Models\NotaMpSeguimiento;
use App\Services\NotaMpResultadosService;
use Mockery;
use Tests\TestCase;

class NotaMpSeguimientoOrdenCompraTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_muestra_ocompra_aunque_ganador_no_sea_reicol_ni_romulo(): void
    {
        config([
            'cotiz.reicol_rut' => '76.356.855-5',
            'cotiz.romulo_rut' => '76.779.675-7',
        ]);

        $service = Mockery::mock(NotaMpResultadosService::class)->makePartial();
        $service->shouldReceive('etiquetaGanadorPorRut')
            ->with('76356855-5')
            ->andReturn('Reicol');
        $service->shouldReceive('etiquetaGanadorPorRut')
            ->with('11111111-1')
            ->andReturn(null);
        $this->app->instance(NotaMpResultadosService::class, $service);

        $nota = new Nota(['ocompra' => '1411-2423-AG26']);
        $segGanador = new NotaMpSeguimiento([
            'rut_ganador' => '76356855-5',
            'id_orden_compra' => 55258095,
        ]);
        $segGanador->setRelation('nota', $nota);

        $segOtro = new NotaMpSeguimiento([
            'rut_ganador' => '11111111-1',
            'id_orden_compra' => 999,
        ]);
        $segOtro->setRelation('nota', new Nota(['ocompra' => '1411-2423-AG26']));

        $this->assertSame('1411-2423-AG26', $segGanador->textoOrdenCompraMp());
        $this->assertSame('1411-2423-AG26', $segOtro->textoOrdenCompraMp());
    }

    public function test_pendiente_cuando_falta_codigo_ag_y_es_grupo(): void
    {
        config([
            'cotiz.reicol_rut' => '76.356.855-5',
            'cotiz.romulo_rut' => '76.185.139-K',
        ]);

        $service = Mockery::mock(NotaMpResultadosService::class)->makePartial();
        $service->shouldReceive('etiquetaGanadorPorRut')->andReturn('Romulo');
        $this->app->instance(NotaMpResultadosService::class, $service);

        $seg = new NotaMpSeguimiento([
            'rut_ganador' => '76185139-K',
            'id_orden_compra' => 55258095,
        ]);
        $seg->setRelation('nota', new Nota(['ocompra' => '']));

        $this->assertSame('Pendiente', $seg->textoOrdenCompraMp());
    }

    public function test_guion_si_no_es_grupo_con_id_oc_sin_codigo(): void
    {
        config([
            'cotiz.reicol_rut' => '76.356.855-5',
            'cotiz.romulo_rut' => '76.185.139-K',
        ]);

        $service = Mockery::mock(NotaMpResultadosService::class)->makePartial();
        $service->shouldReceive('etiquetaGanadorPorRut')->andReturn(null);
        $this->app->instance(NotaMpResultadosService::class, $service);

        $seg = new NotaMpSeguimiento([
            'rut_ganador' => '11.111.111-1',
            'id_orden_compra' => 999999,
        ]);
        $seg->es_ganador_propio = false;
        $seg->setRelation('nota', new Nota(['ocompra' => '']));

        $this->assertSame('—', $seg->textoOrdenCompraMp());
    }

    public function test_muestra_ocompra_si_ganador_propio_no_grupo_ya_tiene_codigo(): void
    {
        config([
            'cotiz.reicol_rut' => '76.356.855-5',
            'cotiz.romulo_rut' => '76.779.675-7',
        ]);

        $service = Mockery::mock(NotaMpResultadosService::class)->makePartial();
        $service->shouldReceive('etiquetaGanadorPorRut')->andReturn(null);
        $this->app->instance(NotaMpResultadosService::class, $service);

        $seg = new NotaMpSeguimiento([
            'rut_ganador' => '76185139-K',
            'id_orden_compra' => 54917712,
        ]);
        $seg->es_ganador_propio = true;
        $seg->setRelation('nota', new Nota(['ocompra' => '3497-305-AG26']));

        $this->assertSame('3497-305-AG26', $seg->textoOrdenCompraMp());
    }

    public function test_puede_reconsultar_si_seguimiento_no_finalizado(): void
    {
        $seg = new NotaMpSeguimiento([
            'resultado_propio' => 'cerrada',
            'finalizado' => false,
        ]);

        $this->assertTrue($seg->puedeReconsultarMp());
    }
}
