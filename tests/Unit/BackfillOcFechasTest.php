<?php

namespace Tests\Unit;

use App\Models\Nota;
use App\Models\NotaMpSeguimiento;
use App\Services\CompraAgilGanadorResolver;
use App\Services\CompraAgilTextoParserService;
use App\Services\MercadoPublicoOrdenCompraService;
use App\Services\NotaMpResultadosService;
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
        $this->assertDatabaseHas('notas', [
            'nronota' => 14406,
            'ocompra' => '3560-120-AG26',
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

    public function test_rellenar_ocompra_skip_si_ya_tiene_codigo(): void
    {
        $nota = Nota::query()->create([
            'nronota' => 14407,
            'descripcion' => 'Ya tiene OC',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => '3560-69-COT26',
            'ocompra' => '3560-120-AG26',
            'nota_softland' => 1440700,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => $nota->nronota,
            'codigo_proceso' => '3560-69-COT26',
            'id_orden_compra' => 54528069,
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);

        Http::fake();

        $service = app(NotaMpResultadosService::class);
        $this->assertSame('skipped', $service->rellenarOcompraDesdeIdOrdenCompra((int) $nota->nronota));
        Http::assertNothingSent();
    }
}
