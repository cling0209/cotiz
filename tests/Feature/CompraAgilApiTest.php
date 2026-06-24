<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\User;
use App\Services\CompraAgilPayloadMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompraAgilApiTest extends TestCase
{
    use RefreshDatabase;

    private User $ejecutivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        config(['cotiz.mercadopublico.ticket' => 'test-ticket']);

        $this->ejecutivo = User::factory()->create([
            'username' => 'ejecapi',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
    }

    public function test_mapper_convierte_detalle_api(): void
    {
        $mapper = app(CompraAgilPayloadMapper::class);
        $datos = $mapper->fromDetalle([
            'codigo' => '1161-172-COT26',
            'nombre' => 'Compra prueba',
            'institucion' => [
                'organismo_comprador' => 'Hospital Test',
                'rut' => '61.303.000-7',
            ],
            'productos_solicitados' => [
                [
                    'codigo_producto' => '31237835',
                    'nombre' => 'Limpiador',
                    'cantidad' => 10,
                    'unidad_medida' => 'EA',
                ],
            ],
        ]);

        $this->assertSame('1161-172-COT26', $datos['cabecera']['codigo_cotizacion']);
        $this->assertSame('Hospital Test', $datos['cabecera']['empresa']);
        $this->assertCount(1, $datos['lineas']);
        $this->assertSame('31237835', $datos['lineas'][0]['id_agile']);
        $this->assertSame(10, $datos['lineas'][0]['cantidad']);
    }

    public function test_buscar_api_requiere_ticket_configurado(): void
    {
        config(['cotiz.mercadopublico.ticket' => '']);

        $nota = $this->crearNota();

        $this->actingAs($this->ejecutivo)
            ->getJson(route('admin.cotizaciones.compra-agil-api.buscar', [
                'nronota' => $nota->nronota,
                'modo' => 'texto',
                'q' => 'papel',
                'region' => 13,
            ]))
            ->assertStatus(503);
    }

    public function test_buscar_texto_requiere_region(): void
    {
        config(['cotiz.mercadopublico.regiones' => [13]]);

        $nota = $this->crearNota();

        $this->actingAs($this->ejecutivo)
            ->getJson(route('admin.cotizaciones.compra-agil-api.buscar', [
                'nronota' => $nota->nronota,
                'modo' => 'texto',
                'q' => 'papel',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['region']);
    }

    public function test_buscar_texto_por_region(): void
    {
        config(['cotiz.mercadopublico.regiones' => [13]]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil*' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'items' => [[
                        'codigo' => '1161-172-COT26',
                        'nombre' => 'Compra papel',
                        'institucion' => ['organismo_comprador' => 'Hospital', 'rut' => '61.303.000-7', 'region' => 13],
                        'montos' => ['monto_disponible_clp' => 100000, 'moneda' => 'CLP'],
                        'fechas' => ['fecha_publicacion' => '2026-06-01T12:00:00Z'],
                        'estado' => ['codigo' => 'publicada', 'nombre' => 'Publicada'],
                        'resumen' => ['cantidad_productos' => 1],
                    ]],
                    'paginacion' => ['total_resultados' => 1, 'numero_pagina' => 1, 'total_paginas' => 1],
                ],
                'errors' => null,
            ]),
        ]);

        $nota = $this->crearNota();

        $this->actingAs($this->ejecutivo)
            ->getJson(route('admin.cotizaciones.compra-agil-api.buscar', [
                'nronota' => $nota->nronota,
                'modo' => 'texto',
                'q' => 'papel',
                'region' => 13,
            ]))
            ->assertOk()
            ->assertJsonPath('items.0.codigo', '1161-172-COT26');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'region=13')
                && str_contains($request->url(), 'q=papel');
        });
    }

    public function test_preview_codigo_api(): void
    {
        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/1161-172-COT26' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'codigo' => '1161-172-COT26',
                    'nombre' => 'Compra prueba',
                    'institucion' => ['organismo_comprador' => 'Hospital', 'rut' => '61.303.000-7'],
                    'productos_solicitados' => [
                        ['codigo_producto' => '99', 'nombre' => 'Item', 'cantidad' => 2],
                    ],
                ],
                'errors' => null,
            ]),
        ]);

        Maeprod::query()->create([
            'prod_item' => 'P001',
            'prod_nombre' => 'Item catálogo',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'prod_familia' => 'ASEO',
        ]);

        $nota = $this->crearNota(['encargado' => '']);

        $this->actingAs($this->ejecutivo)
            ->postJson(route('admin.cotizaciones.compra-agil-api.preview', $nota->nronota), [
                'codigo' => '1161-172-COT26',
            ])
            ->assertOk()
            ->assertJsonPath('cabecera.codigo_cotizacion', '1161-172-COT26')
            ->assertJsonPath('resumen.total', 1);
    }

    public function test_preview_codigo_api_en_lotes_consulta_detalle_una_sola_vez(): void
    {
        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/1161-172-COT26' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'codigo' => '1161-172-COT26',
                    'nombre' => 'Compra prueba',
                    'institucion' => ['organismo_comprador' => 'Hospital', 'rut' => '61.303.000-7'],
                    'productos_solicitados' => [
                        ['codigo_producto' => '1', 'nombre' => 'Item 1', 'cantidad' => 1],
                        ['codigo_producto' => '2', 'nombre' => 'Item 2', 'cantidad' => 1],
                    ],
                ],
                'errors' => null,
            ]),
        ]);

        $nota = $this->crearNota(['encargado' => '']);

        $this->actingAs($this->ejecutivo)
            ->postJson(route('admin.cotizaciones.compra-agil-api.preview', $nota->nronota), [
                'codigo' => '1161-172-COT26',
                'desde' => 0,
                'hasta' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->actingAs($this->ejecutivo)
            ->postJson(route('admin.cotizaciones.compra-agil-api.preview', $nota->nronota), [
                'codigo' => '1161-172-COT26',
                'desde' => 1,
                'hasta' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('completado', true);

        Http::assertSentCount(1);
    }

    public function test_analisis_admin_solo_usuario_admin(): void
    {
        config(['cotiz.mercadopublico.analisis_admin_habilitado' => true]);
        $this->withMiddleware();

        $this->actingAs($this->ejecutivo)
            ->get(route('admin.compra-agil.analisis.index'))
            ->assertForbidden();

        $otroSuperadmin = User::factory()->create([
            'username' => 'otro_super',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($otroSuperadmin)
            ->get(route('admin.compra-agil.analisis.index'))
            ->assertForbidden();

        $admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.compra-agil.analisis.index'))
            ->assertOk();
    }

    private function crearNota(array $attrs = []): Nota
    {
        return Nota::query()->create(array_merge([
            'nronota' => 301,
            'descripcion' => 'Test API MP',
            'fecha' => now()->toDateString(),
            'usuario' => 'ejecapi',
            'empresa' => '',
            'encargado' => 'COT-API-001',
            'nota_softland' => 30100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ], $attrs));
    }
}
