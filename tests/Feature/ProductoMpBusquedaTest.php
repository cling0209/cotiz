<?php

namespace Tests\Feature;

use App\Jobs\ProcessProductoMpBusquedaJob;
use App\Models\Maeprod;
use App\Models\MaeprodFrase;
use App\Models\MaeprodFraseBusqueda;
use App\Models\OportunidadEncontrada;
use App\Models\OportunidadPalabraClave;
use App\Models\ProductoMpEncontrado;
use App\Models\User;
use App\Services\ProductoMpBusquedaService;
use Database\Seeders\FamprodSeeder;
use Database\Seeders\GramajeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductoMpBusquedaTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GramajeSeeder::class);
        $this->seed(FamprodSeeder::class);

        $this->superadmin = User::factory()->create([
            'username' => 'superadmin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        Maeprod::query()->create([
            'prod_item' => 'DEMO003',
            'prod_nombre' => 'ADHESIVO BARRA',
            'prod_valor' => 500,
            'prod_familia' => 'LIBR',
            'prod_gramaje' => 'unidad',
        ]);

        config([
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13],
            'app.timezone' => 'America/Santiago',
        ]);
    }

    public function test_mantenedor_agrega_palabra_clave_sin_codigo_ni_tocar_frases_agile(): void
    {
        MaeprodFrase::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'adhesivo barra',
            'frase_norm' => 'ADHESIVO BARRA',
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('admin.producto-mp.frases.index'))
            ->assertOk()
            ->assertSee('Nueva palabra clave producto')
            ->assertSee('Regiones (opcional)')
            ->assertDontSee('Código producto');

        $this->actingAs($this->superadmin)
            ->post(route('admin.producto-mp.frases.store'), [
                'frase' => '  barra pritt  ',
                'regiones' => [13],
            ])
            ->assertRedirect(route('admin.producto-mp.frases.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('maeprod_frases_busqueda', [
            'frase' => 'barra pritt',
            'frase_norm' => 'BARRA PRITT',
            'created_by' => $this->superadmin->id,
        ]);
        $this->assertDatabaseHas('maeprod_frase_busqueda_regiones', [
            'region_codigo' => 13,
        ]);
        $this->assertDatabaseHas('maeprod_frases', [
            'prod_item' => 'DEMO003',
            'frase' => 'adhesivo barra',
        ]);
        $this->assertSame(1, MaeprodFrase::query()->count());
        $this->assertNull(MaeprodFraseBusqueda::query()->value('prod_item'));
    }

    public function test_palabra_clave_no_se_repite_globalmente(): void
    {
        MaeprodFraseBusqueda::query()->create([
            'frase' => 'papel oficio',
            'frase_norm' => 'PAPEL OFICIO',
        ]);

        $this->actingAs($this->superadmin)
            ->from(route('admin.producto-mp.frases.index'))
            ->post(route('admin.producto-mp.frases.store'), [
                'frase' => 'papel oficio',
            ])
            ->assertRedirect(route('admin.producto-mp.frases.index'))
            ->assertSessionHasErrors('frase');

        $this->assertSame(1, MaeprodFraseBusqueda::query()->count());
    }

    public function test_ficha_producto_muestra_ambos_bloques_de_frases(): void
    {
        MaeprodFrase::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'adhesivo barra',
            'frase_norm' => 'ADHESIVO BARRA',
        ]);
        MaeprodFraseBusqueda::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'barra pritt',
            'frase_norm' => 'BARRA PRITT',
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.edit', 'DEMO003'))
            ->assertOk()
            ->assertSee('Frases para vincular')
            ->assertSee('adhesivo barra')
            ->assertSee('Palabras clave producto')
            ->assertSee('barra pritt');
    }

    public function test_match_respeta_regiones_de_la_palabra_clave(): void
    {
        $todas = MaeprodFraseBusqueda::query()->create([
            'frase' => 'resma carta',
            'frase_norm' => 'RESMA CARTA',
        ]);
        $soloValparaiso = MaeprodFraseBusqueda::query()->create([
            'frase' => 'barra pritt',
            'frase_norm' => 'BARRA PRITT',
        ]);
        $soloValparaiso->regiones()->create(['region_codigo' => 5]);

        $svc = $this->app->make(ProductoMpBusquedaService::class);

        $this->assertNotNull($svc->mejorFraseParaDescripcion('resma carta 75g', 13));
        $this->assertNull($svc->mejorFraseParaDescripcion('barra adhesiva Pritt 21 ml', 13));
        $this->assertNotNull($svc->mejorFraseParaDescripcion('barra adhesiva Pritt 21 ml', 5));
        $this->assertSame('resma carta', $todas->frase);
    }

    public function test_match_usa_frases_busqueda_no_las_de_vincular(): void
    {
        MaeprodFrase::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'adhesivo barra',
            'frase_norm' => 'ADHESIVO BARRA',
        ]);
        MaeprodFraseBusqueda::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'barra pritt',
            'frase_norm' => 'BARRA PRITT',
        ]);

        $svc = $this->app->make(ProductoMpBusquedaService::class);

        $this->assertNotNull($svc->mejorFraseParaDescripcion('barra adhesiva Pritt 21 ml'));
        $this->assertNull($svc->mejorFraseParaDescripcion('adhesivo en barra 21 ml'));
    }

    public function test_match_no_usa_codigo_producto_mp(): void
    {
        MaeprodFraseBusqueda::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => '31237835',
            'frase_norm' => '31237835',
        ]);

        $svc = $this->app->make(ProductoMpBusquedaService::class);

        $this->assertSame('', $svc->textoLineaParaMatch([
            'id_agile' => '31237835',
            'codigo_producto' => '31237835',
            'descripcion' => '',
            'categoria' => '',
        ]));
        $this->assertNull($svc->mejorFraseParaDescripcion($svc->textoLineaParaMatch([
            'id_agile' => '31237835',
            'descripcion' => 'resma carta 75g',
        ])));
        $this->assertNotNull($svc->mejorFraseParaDescripcion($svc->textoLineaParaMatch([
            'id_agile' => '999',
            'descripcion' => 'item 31237835 oficina',
        ])));
    }

    public function test_filtro_listado_no_busca_por_codigo(): void
    {
        ProductoMpEncontrado::query()->create([
            'codigo' => '1161-1-COT26',
            'nombre_ca' => 'Utiles',
            'organismo' => 'Municipalidad X',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'codigo_producto_mp' => '31237835',
            'descripcion_mp' => 'barra adhesiva Pritt 21 ml',
            'prod_item' => 'DEMO003',
            'prod_nombre' => 'ADHESIVO BARRA',
            'frase' => 'barra pritt',
            'frase_norm' => 'BARRA PRITT',
            'origen_detalle' => 'mp',
            'fecha_busqueda' => now()->toDateString(),
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('admin.producto-mp.encontrados.index', ['q' => '1161-1-COT26']))
            ->assertOk()
            ->assertDontSee('barra adhesiva Pritt 21 ml');

        $this->actingAs($this->superadmin)
            ->get(route('admin.producto-mp.encontrados.index', ['q' => 'Pritt']))
            ->assertOk()
            ->assertSee('barra adhesiva Pritt 21 ml');
    }

    public function test_reusa_preview_de_oportunidad_sin_pedir_detalle(): void
    {
        MaeprodFraseBusqueda::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'barra pritt',
            'frase_norm' => 'BARRA PRITT',
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => '1161-1-COT26',
            'nombre' => 'Utiles de oficina',
            'organismo' => 'Municipalidad X',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'fecha_busqueda' => '2026-08-14',
            'palabras_coinciden' => ['oficina'],
            'vinculo_completo' => true,
            'vinculo_preview_json' => [
                'lineas' => [
                    [
                        'id_agile' => '31237835',
                        'descripcion' => 'barra adhesiva Pritt 21 ml',
                        'cantidad' => 2,
                    ],
                ],
            ],
        ]);

        Http::fake();

        $svc = $this->app->make(ProductoMpBusquedaService::class);
        $guardados = $svc->procesarCodigo('1161-1-COT26', [
            'codigo' => '1161-1-COT26',
            'nombre' => 'Utiles de oficina',
            'organismo' => 'Municipalidad X',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
        ], '2026-08-14');

        $this->assertSame(1, $guardados);
        $this->assertDatabaseHas('producto_mp_encontrados', [
            'codigo' => '1161-1-COT26',
            'prod_item' => 'DEMO003',
            'frase' => 'barra pritt',
            'codigo_producto_mp' => '31237835',
            'origen_detalle' => 'preview',
        ]);
        Http::assertNothingSent();
        $this->assertSame(1, OportunidadEncontrada::query()->count());
        $this->assertDatabaseHas('oportunidad_encontradas', [
            'codigo' => '1161-1-COT26',
        ]);
    }

    public function test_corrida_revisa_ca_aunque_no_este_en_oportunidades(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 12:00:00', 'America/Santiago'));

        MaeprodFraseBusqueda::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'barra pritt',
            'frase_norm' => 'BARRA PRITT',
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'aseo',
            'orden' => 1,
        ]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/3450-88-COT26' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'codigo' => '3450-88-COT26',
                    'nombre' => 'Utiles de oficina',
                    'institucion' => [
                        'organismo_comprador' => 'Hospital Y',
                        'region' => 13,
                        'comuna' => 'Santiago',
                    ],
                    'fechas' => [
                        'fecha_publicacion' => '2026-08-14T09:00:00-04:00',
                        'fecha_cierre' => '2026-08-16T18:00:00-04:00',
                    ],
                    'productos_solicitados' => [
                        [
                            'codigo_producto' => '44112200',
                            'nombre' => 'Adhesivo',
                            'descripcion' => 'barra adhesiva Pritt 21 ml',
                            'cantidad' => 1,
                        ],
                    ],
                ],
            ]),
            'api2.mercadopublico.cl/v2/compra-agil*' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'items' => [
                        [
                            'codigo' => '3450-88-COT26',
                            'nombre' => 'Utiles de oficina',
                            'fechas' => [
                                'fecha_publicacion' => '2026-08-14T09:00:00-04:00',
                                'fecha_cierre' => '2026-08-16T18:00:00-04:00',
                            ],
                            'institucion' => [
                                'organismo_comprador' => 'Hospital Y',
                                'region' => 13,
                                'comuna' => 'Santiago',
                            ],
                        ],
                    ],
                    'paginacion' => [
                        'numero_pagina' => 1,
                        'total_paginas' => 1,
                        'total_resultados' => 1,
                    ],
                ],
            ]),
        ]);

        Queue::fake();
        $svc = $this->app->make(ProductoMpBusquedaService::class);
        $corrida = $svc->iniciar('tester', '2026-08-14');
        Queue::assertPushed(ProcessProductoMpBusquedaJob::class);

        $guardas = 0;
        while ($svc->procesarPaso($corrida->fresh()) && $guardas < 20) {
            $guardas++;
        }

        $this->assertDatabaseHas('producto_mp_encontrados', [
            'codigo' => '3450-88-COT26',
            'prod_item' => 'DEMO003',
            'codigo_producto_mp' => '44112200',
            'origen_detalle' => 'mp',
        ]);
        $this->assertDatabaseMissing('oportunidad_encontradas', [
            'codigo' => '3450-88-COT26',
        ]);
        $this->assertSame(ProductoMpBusquedaService::ESTADO_COMPLETED, $corrida->fresh()->estado);

        Carbon::setTestNow();
    }

    public function test_listado_productos_mp_muestra_match(): void
    {
        ProductoMpEncontrado::query()->create([
            'codigo' => '1161-1-COT26',
            'nombre_ca' => 'Utiles',
            'organismo' => 'Municipalidad X',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'codigo_producto_mp' => '31237835',
            'descripcion_mp' => 'barra adhesiva Pritt 21 ml',
            'prod_item' => 'DEMO003',
            'prod_nombre' => 'ADHESIVO BARRA',
            'frase' => 'barra pritt',
            'frase_norm' => 'BARRA PRITT',
            'origen_detalle' => 'mp',
            'fecha_busqueda' => now()->toDateString(),
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('admin.producto-mp.encontrados.index'))
            ->assertOk()
            ->assertSee('1161-1-COT26')
            ->assertSee('barra pritt')
            ->assertSee('DEMO003');
    }

    public function test_iniciar_sin_frases_devuelve_error(): void
    {
        Queue::fake();

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.producto-mp.encontrados.iniciar'))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_estado_expone_inicio_duracion_y_ultimo_error(): void
    {
        \App\Models\ProductoMpBusquedaCorrida::query()->create([
            'usuario' => 'superadmin',
            'fecha_busqueda' => now()->toDateString(),
            'inicio' => now()->subMinutes(2),
            'estado' => ProductoMpBusquedaService::ESTADO_RUNNING,
            'total_pasos' => 2,
            'pasos_procesados' => 1,
            'pasos_fallidos' => 1,
            'matches_encontrados' => 0,
            'cas_revisadas' => 3,
            'plan_json' => [],
            'errores_json' => [
                ['region' => 13, 'mensaje' => 'ticket inválido', 'at' => now()->toIso8601String()],
            ],
            'mensaje' => 'Error en región Metropolitana: ticket inválido',
        ]);

        $estado = $this->app->make(ProductoMpBusquedaService::class)->estado();

        $this->assertTrue($estado['hay_corrida']);
        $this->assertTrue($estado['running']);
        $this->assertNotNull($estado['inicio']);
        $this->assertNull($estado['fin']);
        $this->assertGreaterThanOrEqual(110, (int) $estado['duracion_segundos']);
        $this->assertNotEmpty($estado['duracion_texto']);
        $this->assertSame('ticket inválido', $estado['ultimo_error']['mensaje'] ?? null);
        $this->assertSame(13, $estado['ultimo_error']['region'] ?? null);
    }
}
