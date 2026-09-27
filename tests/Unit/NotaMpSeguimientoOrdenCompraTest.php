<?php

namespace Tests\Unit;

use App\Enums\EstadoOrdenCompraMp;
use App\Models\Nota;
use App\Models\NotaMpSeguimiento;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NotaMpSeguimientoOrdenCompraTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cotiz.empresa_rut' => '76.356.855-5',
            'cotiz.reicol_rut' => '76.356.855-5',
            'cotiz.romulo_rut' => '76.185.139-K',
            'cotiz.mercadopublico.oc_codigo_plazo_dias' => 60,
        ]);
    }

    private function seguimiento(array $atributos, string $ocompra = ''): NotaMpSeguimiento
    {
        // PgBoolean escribe una expresión SQL; en memoria se asigna el valor crudo.
        $finalizado = $atributos['finalizado'] ?? null;
        unset($atributos['finalizado']);

        $seg = new NotaMpSeguimiento($atributos);
        if ($finalizado !== null) {
            $seg->setRawAttributes(['finalizado' => $finalizado] + $seg->getAttributes());
        }
        $seg->setRelation('nota', new Nota(['ocompra' => $ocompra]));

        return $seg;
    }

    public function test_muestra_codigo_solo_si_ganador_propio(): void
    {
        $propio = $this->seguimiento([
            'rut_ganador' => '76356855-5',
            'id_orden_compra' => 55258095,
            'estado_mp_codigo' => 'oc_emitida',
        ], '1411-2423-AG26');

        $this->assertSame(EstadoOrdenCompraMp::CODIGO, $propio->estadoOrdenCompraMp());
        $this->assertSame('1411-2423-AG26', $propio->textoOrdenCompraMp());
    }

    public function test_oc_de_otra_empresa_del_grupo_no_muestra_pendiente(): void
    {
        // Romulo en la instancia Reicol: otra empresa aunque sea del grupo.
        $seg = $this->seguimiento([
            'rut_ganador' => '76185139-K',
            'razon_social_ganador' => 'COMERCIAL ROMULO SPA',
            'id_orden_compra' => 55258095,
            'estado_mp_codigo' => 'oc_emitida',
        ], '3482-106-AG26');

        $this->assertSame(EstadoOrdenCompraMp::OTRA_EMPRESA, $seg->estadoOrdenCompraMp());
        $this->assertSame('OC entregada a otra empresa', $seg->textoOrdenCompraMp());
        $this->assertSame('OC entregada a otra empresa (COMERCIAL ROMULO SPA)', $seg->valorOrdenCompraExport());
        $this->assertSame([
            'orden_compra' => null,
            'orden_compra_estado' => 'otra_empresa',
            'orden_compra_texto' => 'OC entregada a otra empresa',
        ], $seg->ordenCompraParaJson());
    }

    public function test_oc_de_otra_empresa_con_proveedor_seleccionado_aun_no_esta_entregada(): void
    {
        $seg = $this->seguimiento([
            'rut_ganador' => '11.111.111-1',
            'id_orden_compra' => 999999,
            'estado_mp_codigo' => 'proveedor_seleccionado',
        ]);

        $this->assertNull($seg->estadoOrdenCompraMp());
        $this->assertSame('—', $seg->textoOrdenCompraMp());
    }

    public function test_por_emitir_si_ganamos_con_proveedor_seleccionado(): void
    {
        $seg = $this->seguimiento([
            'rut_ganador' => '76.356.855-5',
            'id_orden_compra' => 55258095,
            'estado_mp_codigo' => 'proveedor_seleccionado',
        ]);

        $this->assertSame('OC por emitir', $seg->textoOrdenCompraMp());
    }

    public function test_buscando_codigo_dentro_del_plazo_y_no_encontrado_despues(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 12:00:00'));

        $reciente = $this->seguimiento([
            'rut_ganador' => '76356855-5',
            'id_orden_compra' => 55258095,
            'estado_mp_codigo' => 'oc_emitida',
            'fecha_ultimo_cambio' => Carbon::parse('2026-09-01 10:00:00'),
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);
        $this->assertSame('Buscando código OC', $reciente->textoOrdenCompraMp());
        $this->assertTrue($reciente->puedeReconsultarMp());

        $vencida = $this->seguimiento([
            'rut_ganador' => '76356855-5',
            'id_orden_compra' => 55258095,
            'estado_mp_codigo' => 'oc_emitida',
            'fecha_ultimo_cambio' => Carbon::parse('2026-07-01 10:00:00'),
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);
        $this->assertSame('Código OC no encontrado', $vencida->textoOrdenCompraMp());
        $this->assertFalse($vencida->puedeReconsultarMp());

        Carbon::setTestNow();
    }

    public function test_guion_si_no_hay_id_oc_ni_codigo(): void
    {
        $seg = $this->seguimiento([
            'rut_ganador' => '11.111.111-1',
            'id_orden_compra' => null,
            'estado_mp_codigo' => 'cerrada',
        ]);

        $this->assertSame('—', $seg->textoOrdenCompraMp());
        $this->assertSame('', $seg->valorOrdenCompraExport());
    }

    public function test_puede_reconsultar_si_seguimiento_no_finalizado(): void
    {
        $seg = $this->seguimiento([
            'resultado_propio' => 'cerrada',
            'finalizado' => false,
        ]);

        $this->assertTrue($seg->puedeReconsultarMp());
    }

    public function test_oc_entregada_a_otra_empresa_no_se_reconsulta(): void
    {
        $seg = $this->seguimiento([
            'rut_ganador' => '76185139-K',
            'id_orden_compra' => 55258095,
            'estado_mp_codigo' => 'oc_emitida',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);

        $this->assertFalse($seg->puedeReconsultarMp());
    }
}
