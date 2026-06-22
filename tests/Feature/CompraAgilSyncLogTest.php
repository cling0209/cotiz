<?php

namespace Tests\Feature;

use App\Models\CompraAgilSyncLog;
use App\Models\User;
use App\Services\CompraAgilSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompraAgilSyncLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cotiz.mercadopublico.ticket' => 'test-ticket',
            'cotiz.mercadopublico.regiones' => [13],
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
        ]);
    }

    public function test_sync_lista_usa_estado_cerrada(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/v2/compra-agil/')) {
                return Http::response([
                    'success' => 'OK',
                    'payload' => [
                        'codigo' => '1549-2596-COT26',
                        'nombre' => 'Compra test',
                        'estado' => ['codigo' => 'cerrada', 'glosa' => 'Cerrada'],
                        'institucion' => ['organismo_comprador' => 'Hospital', 'rut' => '61.303.000-7', 'region' => 13],
                        'presupuesto' => ['monto_disponible_clp' => 100000],
                        'fechas' => ['fecha_publicacion' => '2026-06-01', 'fecha_cierre' => '2026-06-10', 'fecha_ultimo_cambio' => '2026-06-17 15:05'],
                        'productos_solicitados' => [
                            ['codigo_producto' => '31237835', 'nombre' => 'Item', 'cantidad' => 2, 'unidad_medida' => 'EA'],
                        ],
                        'proveedores_cotizando' => [
                            [
                                'rut_proveedor' => '76.356.855-5',
                                'razon_social' => 'Ganador SPA',
                                'seleccion' => ['proveedor_seleccionado' => true],
                                'productos_cotizados' => [
                                    ['codigo_producto' => '31237835', 'precio_unitario' => 1200],
                                ],
                            ],
                        ],
                        'resumen' => ['total_ofertas_recibidas' => 1],
                    ],
                    'errors' => null,
                ]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['estado'] ?? '') !== 'cerrada') {
                return Http::response([
                    'success' => 'ERROR',
                    'errors' => [['mensaje' => 'estado inesperado: '.($query['estado'] ?? '')]],
                ], 400);
            }

            return Http::response([
                'success' => 'OK',
                'payload' => [
                    'items' => [
                        ['codigo' => '1549-2596-COT26', 'institucion' => ['region' => 13]],
                    ],
                    'paginacion' => [
                        'numero_pagina' => 1,
                        'total_paginas' => 1,
                        'total_resultados' => 1,
                    ],
                ],
                'errors' => null,
            ]);
        });

        config(['cotiz.mercadopublico.sync_max_detalle' => 5]);

        $resultado = app(CompraAgilSyncService::class)->sincronizarAdjudicadas(usuario: 'admin_test');

        $this->assertNull($resultado['error']);
        $this->assertSame(1, $resultado['codigos_encontrados']);
        $this->assertSame(1, $resultado['procesos_nuevos']);
        $this->assertDatabaseHas('compra_agil_procesos', [
            'codigo' => '1549-2596-COT26',
            'rut_ganador' => '76356855-5',
        ]);
    }

    public function test_sync_guarda_usuario_en_log(): void
    {
        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil*' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'items' => [],
                    'paginacion' => [
                        'numero_pagina' => 1,
                        'total_paginas' => 1,
                        'total_resultados' => 0,
                    ],
                ],
                'errors' => null,
            ]),
        ]);

        app(CompraAgilSyncService::class)->sincronizarAdjudicadas(usuario: 'admin_test');

        $log = CompraAgilSyncLog::query()->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('admin_test', $log->usuario);
        $this->assertNotNull($log->inicio);
        $this->assertNotNull($log->fin);
        $this->assertSame('ok', $log->estado);
    }

    public function test_sync_desde_web_guarda_username(): void
    {
        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil*' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'items' => [],
                    'paginacion' => [
                        'numero_pagina' => 1,
                        'total_paginas' => 1,
                        'total_resultados' => 0,
                    ],
                ],
                'errors' => null,
            ]),
        ]);

        $admin = User::factory()->create([
            'username' => 'super_sync',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->withoutMiddleware()
            ->actingAs($admin)
            ->post(route('admin.compra-agil.analisis.sync'))
            ->assertRedirect();

        $log = CompraAgilSyncLog::query()->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('super_sync', $log->usuario);
    }

    public function test_pantalla_analisis_muestra_ultimo_analisis(): void
    {
        CompraAgilSyncLog::query()->create([
            'inicio' => now()->subMinutes(5),
            'fin' => now()->subMinutes(4),
            'usuario' => 'admin_demo',
            'listados' => 3,
            'detalles' => 2,
            'procesos_nuevos' => 1,
            'estado' => 'ok',
        ]);

        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        $this->withoutMiddleware()
            ->actingAs($admin)
            ->get(route('admin.compra-agil.analisis.index'))
            ->assertOk()
            ->assertSee('Último análisis')
            ->assertSee('admin_demo')
            ->assertSee('Inicio:')
            ->assertSee('Fin:');
    }
}
