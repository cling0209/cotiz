<?php

namespace Tests\Unit;

use App\Models\Nota;
use App\Models\NotaMpSeguimiento;
use App\Services\CompraAgilGanadorResolver;
use App\Services\CompraAgilTextoParserService;
use App\Services\MercadoPublicoOrdenCompraService;
use App\Services\NotaMpResultadosService;
use App\Services\NotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class BackfillOcFechasTest extends TestCase
{
    use RefreshDatabase;

    public function test_rellenar_fechas_oc_desde_detalle_mp(): void
    {
        if (! Schema::hasColumn('nota_mp_seguimientos', 'oc_fecha_envio')) {
            $this->markTestSkipped('Migración oc_fecha_* no aplicada en sqlite de test.');
        }

        config([
            'cotiz.mercadopublico.ticket' => 'test-ticket',
            'cotiz.mercadopublico.oc_v1_base_url' => 'https://api.mercadopublico.cl/servicios/v1/publico',
        ]);

        $nota = Nota::query()->create([
            'nronota' => 14405,
            'descripcion' => 'Test OC fechas',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '4034-452-COT26',
            'ocompra' => '4034-510-AG26',
            'nota_softland' => 1440500,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => $nota->nronota,
            'codigo_proceso' => '4034-452-COT26',
            'id_orden_compra' => 55427925,
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);

        Http::fake([
            'api.mercadopublico.cl/servicios/v1/publico/ordenesdecompra.json*' => Http::response([
                'Cantidad' => 1,
                'Listado' => [
                    [
                        'Codigo' => '4034-510-AG26',
                        'Estado' => 'Aceptada',
                        'Fechas' => [
                            'FechaCreacion' => '2026-09-02T15:33:20',
                            'FechaEnvio' => '2026-09-02T17:24:14',
                            'FechaAceptacion' => '2026-09-04T20:55:33',
                        ],
                    ],
                ],
            ]),
        ]);

        $service = app(NotaMpResultadosService::class);
        $resultado = $service->rellenarFechasOcSiFaltan((int) $nota->nronota, '4034-510-AG26');

        $this->assertSame('updated', $resultado);
        $this->assertDatabaseHas('nota_mp_seguimientos', [
            'nronota' => 14405,
            'oc_estado' => 'Aceptada',
        ]);

        $seg = NotaMpSeguimiento::query()->find(14405);
        $this->assertNotNull($seg?->oc_fecha_envio);
        $this->assertNotNull($seg?->oc_fecha_creacion);
        $this->assertNotNull($seg?->oc_fecha_aceptacion);
    }

    public function test_rellenar_ocompra_desde_id_orden_compra_sin_compra_agil(): void
    {
        if (! Schema::hasColumn('nota_mp_seguimientos', 'oc_fecha_envio')) {
            $this->markTestSkipped('Migración oc_fecha_* no aplicada en sqlite de test.');
        }

        config([
            'cotiz.mercadopublico.ticket' => 'test-ticket',
            'cotiz.mercadopublico.oc_v1_base_url' => 'https://api.mercadopublico.cl/servicios/v1/publico',
            'cotiz.reicol_rut' => '76.356.855-5',
            'cotiz.romulo_rut' => '76.185.139-K',
            // Instancia Romulo: la OC es propia.
            'cotiz.empresa_rut' => '76.185.139-K',
        ]);

        $nota = Nota::query()->create([
            'nronota' => 14406,
            'descripcion' => 'Test backfill ocompra',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '3560-69-COT26',
            'ocompra' => '',
            'nota_softland' => 1440600,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => $nota->nronota,
            'codigo_proceso' => '3560-69-COT26',
            'id_orden_compra' => 54528069,
            'fecha_ultimo_cambio' => '2026-03-23 09:10:00',
            'rut_ganador' => '76.185.139-K',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);

        Http::fake([
            'api.mercadopublico.cl/servicios/v1/publico/ordenesdecompra.json*' => Http::response([
                'Cantidad' => 1,
                'Listado' => [
                    [
                        'Codigo' => '3560-120-AG26',
                        'Nombre' => 'Orden de Compra generada por invitación a compra ágil: 3560-69-COT26',
                        'Estado' => 'Enviada a Proveedor',
                        'Total' => 1000,
                        'Fechas' => [
                            'FechaCreacion' => '2026-03-23T09:00:00',
                            'FechaEnvio' => '2026-03-24T10:00:00',
                        ],
                    ],
                ],
            ]),
            'api2.mercadopublico.cl/*' => Http::response(['success' => 'NOK'], 500),
        ]);

        config(['cotiz.mercadopublico.oc_backfill_radio_dias' => 3]);

        $service = app(NotaMpResultadosService::class);
        $resultado = $service->rellenarOcompraDesdeIdOrdenCompra((int) $nota->nronota);

        $this->assertSame('updated', $resultado);
        $this->assertDatabaseHas('nota_mp_seguimientos', [
            'nronota' => 14406,
            'ocompra_mp' => '3560-120-AG26',
        ]);
        // notas.ocompra es el código manual: el backfill no lo escribe.
        $this->assertDatabaseHas('notas', [
            'nronota' => 14406,
            'ocompra' => '',
        ]);

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'api2.mercadopublico.cl');
        });
        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);

            return str_contains($request->url(), 'ordenesdecompra.json')
                && isset($q['fecha']);
        });
    }

    public function test_rellenar_ocompra_skip_si_ya_tiene_codigo_mp(): void
    {
        $nota = Nota::query()->create([
            'nronota' => 14407,
            'descripcion' => 'Ya tiene OC',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '3560-69-COT26',
            'ocompra' => '',
            'nota_softland' => 1440700,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => $nota->nronota,
            'codigo_proceso' => '3560-69-COT26',
            'id_orden_compra' => 54528069,
            'ocompra_mp' => '3560-120-AG26',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);

        Http::fake();

        $service = app(NotaMpResultadosService::class);
        $this->assertSame('skipped', $service->rellenarOcompraDesdeIdOrdenCompra((int) $nota->nronota));
        Http::assertNothingSent();
    }

    public function test_modificar_cabecera_registra_usuario_y_fecha_de_ocompra_manual(): void
    {
        $nota = Nota::query()->create([
            'nronota' => 16330,
            'descripcion' => 'OC manual',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '931-330-COT26',
            'celular' => '',
            'contacto' => '',
            'contactocorreo' => '',
            'ocompra' => '',
            'nota_softland' => 1633000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.30,
        ]);

        $service = app(NotaService::class);
        $service->modificarCabecera($nota, ['ocompra' => '931-400-AG26'], 'ejecutivo');

        $nota->refresh();
        $this->assertSame('931-400-AG26', $nota->ocompra);
        $this->assertSame('ejecutivo', $nota->ocompra_usuario);
        $this->assertNotNull($nota->ocompra_registrada_en);

        // Otro usuario guarda la cabecera sin cambiar el código: se conserva quién lo ingresó.
        $service->modificarCabecera($nota, ['ocompra' => '931-400-AG26', 'descripcion' => 'Otra'], 'admin');
        $this->assertSame('ejecutivo', $nota->fresh()->ocompra_usuario);
    }

    public function test_modificar_cabecera_permite_cambiar_oc_y_fecha_obtenidas_de_mp(): void
    {
        $nota = Nota::query()->create([
            'nronota' => 16331,
            'descripcion' => 'OC desde MP',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '2961-633-COT26',
            'celular' => '',
            'contacto' => '',
            'contactocorreo' => '',
            'ocompra' => '',
            'estado' => 'aceptada',
            'nota_softland' => 1633100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.30,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => $nota->nronota,
            'codigo_proceso' => '2961-633-COT26',
            'ocompra_mp' => '2961-633-AG26',
            'oc_fecha_envio' => '2026-08-27 15:40:00',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);

        $service = app(NotaService::class);
        $service->modificarCabecera($nota, [
            'ocompra' => '1111-222-AG26',
            'fecha_envio_oc' => '2026-09-01',
        ], 'admin');

        $nota->refresh();
        $this->assertSame('1111-222-AG26', $nota->ocompra);
        $this->assertSame('2026-09-01', $nota->fecha_envio_oc?->format('Y-m-d'));
    }

    public function test_rellenar_omite_nota_aceptada(): void
    {
        $nota = Nota::query()->create([
            'nronota' => 16321,
            'descripcion' => 'Adjudicada a mano',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '2859-853-COT26',
            'ocompra' => '2859-999-AG26',
            'estado' => 'aceptada',
            'nota_softland' => 1632100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.30,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => $nota->nronota,
            'codigo_proceso' => '2859-853-COT26',
            'id_orden_compra' => 55500002,
            'ocompra_mp' => '2859-999-AG26',
            'monto_total_ganador' => 585315,
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);

        Http::fake();

        $service = app(NotaMpResultadosService::class);
        $this->assertSame('skipped', $service->rellenarOcompraDesdeIdOrdenCompra(16321));
        Http::assertNothingSent();
        $this->assertDatabaseHas('nota_mp_seguimientos', ['nronota' => 16321, 'ocompra_mp' => '2859-999-AG26']);
    }

    public function test_rellenar_ocompra_skip_si_no_esta_cerrada(): void
    {
        $nota = Nota::query()->create([
            'nronota' => 14408,
            'descripcion' => 'Pendiente sin OC',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '3560-70-COT26',
            'ocompra' => '',
            'nota_softland' => 1440800,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => $nota->nronota,
            'codigo_proceso' => '3560-70-COT26',
            'id_orden_compra' => 54528070,
            'resultado_propio' => 'pendiente',
            'finalizado' => false,
        ]);

        Http::fake();

        $service = app(NotaMpResultadosService::class);
        $this->assertSame('skipped', $service->rellenarOcompraDesdeIdOrdenCompra((int) $nota->nronota));
        Http::assertNothingSent();
    }

    public function test_rellenar_ocompra_skip_si_ganador_ajeno(): void
    {
        $nota = Nota::query()->create([
            'nronota' => 14409,
            'descripcion' => 'Ganador ajeno',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '3000-1031-COT26',
            'ocompra' => '',
            'nota_softland' => 1440900,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => $nota->nronota,
            'codigo_proceso' => '3000-1031-COT26',
            'id_orden_compra' => 55445901,
            'fecha_ultimo_cambio' => '2026-09-04 14:55:00',
            'rut_ganador' => '78.308.634-4',
            'resultado_propio' => 'cerrada',
            'finalizado' => false,
        ]);

        Http::fake();

        config([
            'cotiz.reicol_rut' => '76.356.855-5',
            'cotiz.romulo_rut' => '76.185.139-K',
            'cotiz.mercadopublico.ticket' => 'test-ticket',
        ]);

        $service = app(NotaMpResultadosService::class);
        $this->assertSame('skipped', $service->rellenarOcompraDesdeIdOrdenCompra((int) $nota->nronota));
        Http::assertNothingSent();
    }
}
