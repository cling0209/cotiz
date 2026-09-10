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

    public function test_buscar_codigo_en_listado_por_nombre_proceso_sin_cot(): void
    {
        $nombre = 'ADQUISICION DE MATERIAL DE LIBRERIA Y KIT DE TECLADOS PARA ESCUELA G-850 - SOLICITUDES 47-48-49-50 FONDOS SEP 90%';
        $listado = [
            ['Codigo' => '956-578-AG26', 'Nombre' => 'Orden de Compra generada por invitación a compra ágil: 956-388-COT26'],
            ['Codigo' => '4034-510-AG26', 'Nombre' => $nombre],
        ];

        $this->assertSame(
            '4034-510-AG26',
            $this->service->buscarCodigoEnListado($listado, '4034-452-COT26', $nombre),
        );
    }

    public function test_buscar_por_nombre_desambigua_con_prefijo_cot(): void
    {
        $nombre = 'MATERIALES VARIOS ESCUELA';
        $listado = [
            ['Codigo' => '1111-100-AG26', 'Nombre' => $nombre],
            ['Codigo' => '4034-510-AG26', 'Nombre' => $nombre],
        ];

        $this->assertSame(
            '4034-510-AG26',
            $this->service->buscarCodigoPorNombreProceso($listado, '4034-452-COT26', $nombre),
        );
    }

    public function test_fechas_busqueda_ventana_centrada_no_pasa_hoy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 12:00:00', 'America/Santiago'));
        config(['app.timezone' => 'America/Santiago']);

        $fechas = $this->service->fechasBusquedaVentana(
            Carbon::parse('2026-09-08 10:00:00', 'America/Santiago'),
            3,
        );

        $this->assertContains('08092026', $fechas);
        $this->assertContains('09092026', $fechas);
        $this->assertContains('07092026', $fechas);
        $this->assertNotContains('10092026', $fechas);
        $this->assertLessThanOrEqual(7, count($fechas));
        Carbon::setTestNow();
    }

    public function test_resolver_codigo_en_fechas_usa_proveedor_y_pocas_llamadas(): void
    {
        Http::fake([
            'api.mercadopublico.cl/servicios/v1/publico/ordenesdecompra.json*' => Http::response([
                'Cantidad' => 1,
                'Listado' => [
                    [
                        'Codigo' => '1411-2423-AG26',
                        'Nombre' => 'invitación a compra ágil: 1411-882-COT26',
                    ],
                ],
            ]),
        ]);

        $codigo = $this->service->resolverCodigoEnFechas(
            '1411-882-COT26',
            ['05082026'],
            '76.185.139-K',
            null,
            omitirListadoSinProveedor: true,
        );

        $this->assertSame('1411-2423-AG26', $codigo);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);

            return ($q['CodigoProveedor'] ?? null) === '1276139'
                && ($q['fecha'] ?? null) === '05082026';
        });
    }

    public function test_obtener_detalle_por_codigo_incluye_fecha_envio(): void
    {
        Http::fake([
            'api.mercadopublico.cl/servicios/v1/publico/ordenesdecompra.json*' => Http::response([
                'Cantidad' => 1,
                'Listado' => [
                    [
                        'Codigo' => '4034-510-AG26',
                        'Estado' => 'Aceptada',
                        'Total' => 1780516.0,
                        'Fechas' => [
                            'FechaCreacion' => '2026-09-02T15:33:20.513',
                            'FechaEnvio' => '2026-09-02T17:24:14.53',
                            'FechaAceptacion' => '2026-09-04T20:55:33.643',
                        ],
                    ],
                ],
            ]),
        ]);

        $detalle = $this->service->obtenerDetallePorCodigo('4034-510-AG26');

        $this->assertNotNull($detalle);
        $this->assertSame('4034-510-AG26', $detalle['codigo']);
        $this->assertSame('Aceptada', $detalle['estado']);
        $this->assertSame('2026-09-02 17:24:14', $detalle['fecha_envio']?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-02 15:33:20', $detalle['fecha_creacion']?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-04 20:55:33', $detalle['fecha_aceptacion']?->format('Y-m-d H:i:s'));
    }

    public function test_resolver_codigo_usa_listado_por_fecha_no_id_numerico(): void
    {
        Http::fake([
            'api.mercadopublico.cl/servicios/v1/publico/ordenesdecompra.json*' => Http::response([
                'Cantidad' => 1,
                'Listado' => [
                    [
                        'Codigo' => '3560-120-AG26',
                        'Nombre' => 'Orden de Compra generada por invitación a compra ágil: 3560-69-COT26',
                        'Estado' => 'Enviada a Proveedor',
                    ],
                ],
            ]),
        ]);

        $codigo = $this->service->resolverCodigoPorCotizacion(
            '3560-69-COT26',
            [
                'id_orden_compra' => 54528069,
                'fechas' => ['fecha_ultimo_cambio' => '2026-03-23 09:10:00'],
            ],
            null,
        );

        $this->assertSame('3560-120-AG26', $codigo);
        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);

            return str_contains($request->url(), 'ordenesdecompra.json')
                && isset($q['fecha'])
                && ! isset($q['codigo']);
        });
    }

    public function test_resolver_codigo_ag_por_id_numerico_no_llama_mp(): void
    {
        Http::fake();

        $this->assertNull($this->service->resolverCodigoAgPorIdOrdenCompra(54528069));
        Http::assertNothingSent();
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

    public function test_resolver_codigo_por_nombre_cuando_listado_no_tiene_cot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00', 'America/Santiago'));
        config(['app.timezone' => 'America/Santiago']);

        $nombre = 'ADQUISICION DE MATERIAL DE LIBRERIA Y KIT DE TECLADOS PARA ESCUELA G-850 - SOLICITUDES 47-48-49-50 FONDOS SEP 90%';

        Http::fake(function ($request) use ($nombre) {
            $url = $request->url();
            if (str_contains($url, 'fecha=04092026')) {
                return Http::response([
                    'Cantidad' => 1,
                    'Listado' => [
                        ['Codigo' => '4034-510-AG26', 'Nombre' => $nombre, 'CodigoEstado' => 6],
                    ],
                ]);
            }

            return Http::response(['Cantidad' => 0, 'Listado' => []]);
        });

        $codigo = $this->service->resolverCodigoPorCotizacion(
            '4034-452-COT26',
            [
                'id_orden_compra' => 55427925,
                'nombre' => $nombre,
                'fechas' => ['fecha_ultimo_cambio' => '2026-09-02 15:35:00'],
            ],
            '76.185.139-K',
        );

        $this->assertSame('4034-510-AG26', $codigo);

        Carbon::setTestNow();
    }

    public function test_resolver_codigo_continua_si_un_dia_da_cuota_429(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'America/Santiago'));
        config(['app.timezone' => 'America/Santiago']);

        $nombre = 'ADQUISICION DE MATERIAL DE LIBRERIA Y KIT DE TECLADOS PARA ESCUELA G-850';

        Http::fake(function ($request) use ($nombre) {
            $url = $request->url();
            if (str_contains($url, 'fecha=03092026')) {
                return Http::response(['Codigo' => 10500, 'Mensaje' => 'cuota'], 429);
            }
            if (str_contains($url, 'fecha=04092026')) {
                return Http::response([
                    'Cantidad' => 1,
                    'Listado' => [
                        ['Codigo' => '4034-510-AG26', 'Nombre' => $nombre],
                    ],
                ]);
            }

            return Http::response(['Cantidad' => 0, 'Listado' => []]);
        });

        $codigo = $this->service->resolverCodigoPorCotizacion(
            '4034-452-COT26',
            [
                'id_orden_compra' => 55427925,
                'nombre' => $nombre,
                'fechas' => ['fecha_ultimo_cambio' => '2026-09-02 15:35:00'],
            ],
            '76.185.139-K',
        );

        $this->assertSame('4034-510-AG26', $codigo);

        Carbon::setTestNow();
    }

    public function test_fechas_busqueda_prioriza_dias_recientes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'America/Santiago'));
        config(['app.timezone' => 'America/Santiago', 'cotiz.mercadopublico.oc_busqueda_max_dias' => 15]);

        $fechas = $this->service->fechasBusquedaDesdePayload([
            'fechas' => ['fecha_ultimo_cambio' => '2026-09-02 15:35:00'],
        ]);

        $this->assertSame('05092026', $fechas[0]);
        $this->assertContains('04092026', $fechas);
        $this->assertContains('01092026', $fechas);

        Carbon::setTestNow();
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

    public function test_fechas_busqueda_incluye_tramo_post_adjudicacion_cuando_oc_tarda_semanas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'America/Santiago'));
        config(['app.timezone' => 'America/Santiago', 'cotiz.mercadopublico.oc_busqueda_max_dias' => 31]);

        $fechas = $this->service->fechasBusquedaDesdePayload([
            'fechas' => ['fecha_ultimo_cambio' => '2026-06-03 13:05:00'],
        ]);

        $this->assertContains('26062026', $fechas);
        $this->assertContains('10082026', $fechas);

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

    public function test_buscar_por_prefijo_y_similitud_nombre_distinto(): void
    {
        $listado = [
            ['Codigo' => '1469-2396-AG26', 'Nombre' => 'Orden de Compra generada por invitación a compra ágil: 1469-2548-COT26'],
            ['Codigo' => '3958-181-AG26', 'Nombre' => 'ARTICULOS PEDAGOGICOS UTP'],
            ['Codigo' => '1978-946-AG26', 'Nombre' => 'CA 1978-284-COT26 / MATERIALES DE ESCRITORIO'],
        ];

        $this->assertSame(
            '3958-181-AG26',
            $this->service->buscarCodigoEnListado(
                $listado,
                '3958-91-COT26',
                'Materiales pedagogicos utp',
            ),
        );
    }

    public function test_buscar_por_prefijo_unico_sin_nombre(): void
    {
        $listado = [
            ['Codigo' => '3958-181-AG26', 'Nombre' => 'OC SIN RELACION CON EL NOMBRE'],
            ['Codigo' => '1978-946-AG26', 'Nombre' => 'OTRA'],
        ];

        $this->assertSame(
            '3958-181-AG26',
            $this->service->buscarCodigoPorPrefijoYSimilitud($listado, '3958-91-COT26'),
        );
    }

    public function test_desambigua_prefijos_multiples_por_monto_detalle(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'codigo=3958-100-AG26')) {
                return Http::response([
                    'Cantidad' => 1,
                    'Listado' => [['Codigo' => '3958-100-AG26', 'Total' => 100000]],
                ]);
            }
            if (str_contains($url, 'codigo=3958-181-AG26')) {
                return Http::response([
                    'Cantidad' => 1,
                    'Listado' => [['Codigo' => '3958-181-AG26', 'Total' => 392903]],
                ]);
            }

            return Http::response(['Cantidad' => 0, 'Listado' => []]);
        });

        $listado = [
            ['Codigo' => '3958-100-AG26', 'Nombre' => 'FOO BAR'],
            ['Codigo' => '3958-181-AG26', 'Nombre' => 'BAZ QUX'],
        ];

        $this->assertSame(
            '3958-181-AG26',
            $this->service->buscarCodigoPorPrefijoYSimilitud(
                $listado,
                '3958-91-COT26',
                null,
                392903.0,
            ),
        );
    }

    public function test_resolver_navidad_nombre_oc_distinto_al_proceso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 12:00:00', 'America/Santiago'));
        config(['app.timezone' => 'America/Santiago']);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'fecha=08092026') && str_contains($url, 'CodigoProveedor=1276139')) {
                return Http::response([
                    'Cantidad' => 2,
                    'Listado' => [
                        ['Codigo' => '1469-2396-AG26', 'Nombre' => 'Orden de Compra generada por invitación a compra ágil: 1469-2548-COT26'],
                        ['Codigo' => '3958-181-AG26', 'Nombre' => 'ARTICULOS PEDAGOGICOS UTP'],
                    ],
                ]);
            }

            return Http::response(['Cantidad' => 0, 'Listado' => []]);
        });

        $codigo = $this->service->resolverCodigoPorCotizacion(
            '3958-91-COT26',
            [
                'id_orden_compra' => 55439451,
                'nombre' => 'Materiales pedagogicos utp',
                'fechas' => ['fecha_ultimo_cambio' => '2026-09-03 17:50:00'],
                'proveedores_cotizando' => [
                    [
                        'rut_proveedor' => '76.185.139-K',
                        'proveedor_seleccionado' => 1,
                        'monto_total' => 392903,
                        'id_oc' => 55439451,
                    ],
                ],
            ],
            '76.185.139-K',
        );

        $this->assertSame('3958-181-AG26', $codigo);

        Carbon::setTestNow();
    }

    public function test_insumos_oficina_desambigua_dos_oc_mismo_nombre_por_monto(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'codigo=1057510-3499-AG26')) {
                return Http::response([
                    'Cantidad' => 1,
                    'Listado' => [['Codigo' => '1057510-3499-AG26', 'Total' => 216023]],
                ]);
            }
            if (str_contains($url, 'codigo=1057510-3484-AG26')) {
                return Http::response([
                    'Cantidad' => 1,
                    'Listado' => [['Codigo' => '1057510-3484-AG26', 'Total' => 480522]],
                ]);
            }

            return Http::response(['Cantidad' => 0, 'Listado' => []]);
        });

        $listado = [
            ['Codigo' => '1057510-3499-AG26', 'Nombre' => 'INSUMOS OFICINA, AGOSTO 2026'],
            ['Codigo' => '1057510-3484-AG26', 'Nombre' => 'INSUMOS OFICINA, AGOSTO 2026'],
        ];

        $this->assertSame(
            '1057510-3499-AG26',
            $this->service->buscarCodigoEnListado(
                $listado,
                '1057510-1481-COT26',
                'insumos oficina',
                216023.0,
            ),
        );
    }
}
