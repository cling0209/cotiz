<?php

namespace Tests\Unit;

use App\Services\CompraAgilTextoParserService;
use App\Services\MercadoPublicoOrdenCompraService;
use Carbon\Carbon;
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
                '763568555' => '1417881',
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

    public function test_id_orden_compra_desde_id_oc_proveedor_ganador(): void
    {
        $payload = [
            'proveedores_cotizando' => [
                [
                    'rut_proveedor' => '76.356.855-5',
                    'proveedor_seleccionado' => 1,
                    'id_oc' => 55258095,
                ],
            ],
        ];

        $this->assertSame(55258095, $this->service->idOrdenCompraDesdePayload($payload));
    }

    public function test_fechas_busqueda_incluye_rango_hasta_hoy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'America/Santiago'));
        config(['app.timezone' => 'America/Santiago', 'cotiz.mercadopublico.oc_busqueda_max_dias' => 15]);

        $fechas = $this->service->fechasBusquedaDesdePayload([
            'fechas' => ['fecha_cierre' => '2026-08-01 09:00:00'],
        ]);

        $this->assertContains('01082026', $fechas);
        $this->assertContains('10082026', $fechas);
        $this->assertGreaterThanOrEqual(8, count($fechas));

        Carbon::setTestNow();
    }

    public function test_resolver_codigo_en_fecha_posterior_al_cierre(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'America/Santiago'));
        config(['app.timezone' => 'America/Santiago', 'cotiz.mercadopublico.oc_busqueda_max_dias' => 15]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'fecha=08082026')) {
                return Http::response([
                    'Cantidad' => 1,
                    'Listado' => [
                        [
                            'Codigo' => '4168-999-AG26',
                            'Nombre' => 'OC generada por invitación a compra ágil: 4168-224-COT26',
                        ],
                    ],
                ]);
            }

            return Http::response(['Cantidad' => 0, 'Listado' => []]);
        });

        $codigo = $this->service->resolverCodigoPorCotizacion(
            '4168-224-COT26',
            [
                'id_orden_compra' => 55123456,
                'fechas' => ['fecha_cierre' => '2026-08-01 09:00:00'],
            ],
            '76.356.855-5',
        );

        $this->assertSame('4168-999-AG26', $codigo);

        Carbon::setTestNow();
    }
}
