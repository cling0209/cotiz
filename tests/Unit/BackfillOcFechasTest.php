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
}
