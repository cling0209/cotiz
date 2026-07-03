<?php

namespace Tests\Unit;

use App\Services\CompraAgilGanadorResolver;
use App\Services\CompraAgilTextoParserService;
use Tests\TestCase;

class CompraAgilGanadorResolverTest extends TestCase
{
    private CompraAgilGanadorResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cotiz.empresa_rut' => '76.779.675-7']);
        $this->resolver = new CompraAgilGanadorResolver(new CompraAgilTextoParserService);
    }

    public function test_detecta_ganador_con_proveedor_seleccionado_plano(): void
    {
        $payload = [
            'id_orden_compra' => 55070937,
            'estado' => ['codigo' => 'proveedor_seleccionado'],
            'proveedores_cotizando' => [
                [
                    'rut_proveedor' => '78.083.346-7',
                    'razon_social' => 'TT PRINT SPA',
                    'proveedor_seleccionado' => 0,
                ],
                [
                    'rut_proveedor' => '76.779.675-7',
                    'razon_social' => 'INTEGRAMUNDO SPA',
                    'proveedor_seleccionado' => 1,
                    'activo' => 1,
                    'id_oc' => 55070937,
                    'monto_total' => 298809,
                ],
            ],
        ];

        $ganador = $this->resolver->ganadorPrincipal($payload);

        $this->assertSame('INTEGRAMUNDO SPA', $ganador['razon_social']);
        $this->assertSame('76779675-7', $this->resolver->rutGanador($payload));
        $this->assertSame('cerrada', $this->resolver->resultadoPropio($payload));
    }

    public function test_detecta_ganador_con_seleccion_anidada(): void
    {
        $payload = [
            'proveedores_cotizando' => [
                [
                    'rut_proveedor' => '76.356.855-5',
                    'seleccion' => ['proveedor_seleccionado' => true],
                ],
            ],
        ];

        $this->assertTrue($this->resolver->tieneProveedorAdjudicado($payload));
        $this->assertSame('76356855-5', $this->resolver->rutGanador($payload));
    }
}
