<?php

namespace Tests\Unit;

use App\Services\CompraAgilTextoParserService;
use App\Services\MercadoPublicoOrdenCompraService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPublicoOrdenCompraServiceTest extends TestCase
{
    private MercadoPublicoOrdenCompraService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cotiz.mercadopublico.ticket' => 'test-ticket',
            'cotiz.mercadopublico.oc_v1_base_url' => 'https://api.mercadopublico.cl/servicios/v1/publico',
            'cotiz.mercadopublico.codigo_proveedor_por_rut' => [
                '76185139K' => '1276139',
            ],
        ]);
        $this->service = new MercadoPublicoOrdenCompraService(new CompraAgilTextoParserService);
    }

    public function test_buscar_codigo_en_listado_por_texto_cot(): void
    {
        $listado = [
            ['Codigo' => '1411-2423-AG26', 'Nombre' => 'OC generada por invitación a compra ágil: 1411-882-COT26'],
        ];

        $this->assertSame(
            '1411-2423-AG26',
            $this->service->buscarCodigoEnListado($listado, '1411-882-COT26'),
        );
    }

    public function test_resolver_codigo_consulta_oc_v1(): void
    {
        Http::fake([
            'api.mercadopublico.cl/servicios/v1/publico/ordenesdecompra.json*' => Http::response([
                'Cantidad' => 1,
                'Listado' => [
                    [
                        'Codigo' => '1411-2423-AG26',
                        'Nombre' => 'Orden de Compra generada por invitación a compra ágil: 1411-882-COT26',
                    ],
                ],
            ]),
        ]);

        $payload = [
            'id_orden_compra' => 55258095,
            'fechas' => ['fecha_ultimo_cambio' => '2026-08-05 11:55:00'],
        ];

        $codigo = $this->service->resolverCodigoPorCotizacion(
            '1411-882-COT26',
            $payload,
            '76.185.139-K',
        );

        $this->assertSame('1411-2423-AG26', $codigo);
    }

    public function test_no_resuelve_sin_id_orden_compra(): void
    {
        $this->assertNull($this->service->resolverCodigoPorCotizacion(
            '1411-882-COT26',
            ['fechas' => ['fecha_ultimo_cambio' => '2026-08-05 11:55:00']],
            '76.185.139-K',
        ));
    }
}
