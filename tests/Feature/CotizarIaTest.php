<?php

namespace Tests\Feature;

use App\Enums\VinculoOrigen;
use App\Models\AgileMaeprod;
use App\Models\Maeprod;
use App\Models\MaeprodFrase;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use App\Services\AgileVinculoAprendizajeService;
use App\Services\CompraAgilOportunidadService;
use App\Services\CotizarIaService;
use App\Services\MaeprodBusquedaSimilitudService;
use App\Services\OportunidadAdjuntoService;
use App\Services\OportunidadVinculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CotizarIaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const DESC_FRASE = 'LAPIZ AZUL ESCOLAR FABER 12 UN';

    private const DESC_APRENDIDO = 'GREDAS ESCOLARES DE 1 KILO';

    private const DESC_IA = 'PAPEL HIGIENICO HOJA DOBLE 50 MTS';

    private const DESC_WEB = 'TORNILLO AUTOPERFORANTE 8 X 1 PULGADA';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        config([
            'app.url' => 'http://localhost',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
            'cotiz.mercadopublico.ticket' => '',
            'filesystems.disks.r2_adjuntos.bucket' => null,
            'cotiz.gemini.api_key' => 'test-key',
            'cotiz.gemini.busqueda_web' => true,
            'cotiz.gemini.model' => 'gemini-principal',
            'cotiz.gemini.modelos_respaldo' => ['gemini-respaldo'],
            'cotiz.gemini.reintento_espera_ms' => 0,
            'cotiz.prisa.habilitado' => false,
            'cotiz.prisa.base_url' => 'https://prisa.test',
        ]);

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        Http::fake(function (HttpRequest $request) {
            $prefijo = 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/';
            if (! str_starts_with($request->url(), $prefijo)) {
                return null;
            }

            return Http::response('', 302, ['Location' => base64_decode(strtr(substr($request->url(), strlen($prefijo)), '-_', '+/'))]);
        });

        foreach ([
            ['ARTE001', 'LAPIZ AZUL ESCOLAR 12 UNIDADES', 3500, 2800],
            ['PAPEL001', 'GREDAS ESCOLARES 1 KG', 1200, 900],
            ['HIG001', 'PAPEL HIGIENICO HOJA DOBLE 50 MTS', 1300, 900],
            ['HIG002', 'PAPEL HIGIENICO HOJA DOBLE 50 MTS ECONOMICO', 1000, 700],
            ['CARTB', 'CARTULINA COLOR 53.5 X 77 BLANCA HALLEY PRECIO X UNIDAD', 250, 150],
        ] as [$item, $nombre, $valor, $costo]) {
            Maeprod::query()->create([
                'prod_item' => $item,
                'prod_nombre' => $nombre,
                'prod_valor' => $valor,
                'prod_valor_costo' => $costo,
                'prod_familia' => 'VARIOS',
            ]);
        }

        MaeprodFrase::query()->create([
            'prod_item' => 'ARTE001',
            'frase' => 'lapiz azul',
            'frase_norm' => 'LAPIZ AZUL',
        ]);
        app(AgileVinculoAprendizajeService::class)->guardarAprendizaje(self::DESC_APRENDIDO, 'PAPEL001');

        $this->partialMock(MaeprodBusquedaSimilitudService::class, function ($mock) {
            $mock->shouldReceive('buscar')->andReturnUsing(fn (string $term) => match (true) {
                str_contains(mb_strtoupper($term), 'HIGIENICO') => Maeprod::query()->whereIn('prod_item', ['HIG001', 'HIG002'])->get(),
                str_contains(mb_strtoupper($term), 'CARTULINA') => Maeprod::query()->where('prod_item', 'CARTB')->get(),
                default => collect(),
            });
        });
    }

    public function test_usuario_no_permitido_recibe_403(): void
    {
        $ejecutivo = User::factory()->create([
            'username' => 'jperez',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
        $nota = $this->crearNota(['usuario' => 'jperez']);

        $this->actingAs($ejecutivo)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertForbidden();

        $this->actingAs($ejecutivo)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), ['token' => str_repeat('a', 32)])
            ->assertForbidden();
    }

    public function test_preview_respeta_frase_y_aprendido_ia_elige_mas_economico_y_web_solo_sitios_permitidos(): void
    {
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => ['HIG001', 'HIG002', 'NOEXISTE'], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini([
                    'resultados' => [[
                        'i' => 3,
                        'opciones' => [
                            ['sitio' => 'otro', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada', 'precio_clp' => 50, 'unidades_por_pack' => 1, 'url' => $this->urlBusqueda('https://www.falabella.com/tornillo')],
                            ['sitio' => 'sodimac', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada', 'precio_clp' => 2380, 'unidades_por_pack' => 1, 'url' => $this->urlBusqueda('https://www.sodimac.cl/sodimac-cl/product/123')],
                            ['sitio' => 'mercadolibre', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada pack', 'precio_clp' => 11900, 'unidades_por_pack' => 100, 'url' => $this->urlBusqueda('https://articulo.mercadolibre.cl/MLC-123-tornillo')],
                        ],
                    ]],
                ])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $this->assertSame(CotizarIaService::FUENTE_COTIZACION, $preview['fuente']);
        $lineas = collect($preview['lineas'])->keyBy('descripcion');

        $this->assertSame(CotizarIaService::ORIGEN_FRASE, $lineas[self::DESC_FRASE]['origen']);
        $this->assertSame('ARTE001', $lineas[self::DESC_FRASE]['producto']['prod_item']);
        $this->assertSame(CotizarIaService::ORIGEN_APRENDIDO, $lineas[self::DESC_APRENDIDO]['origen']);
        $this->assertSame(CotizarIaService::ORIGEN_IA, $lineas[self::DESC_IA]['origen']);
        $this->assertSame('HIG002', $lineas[self::DESC_IA]['producto']['prod_item']);
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $lineas[self::DESC_WEB]['estado']);
        $this->assertSame('Mercado Libre', $lineas[self::DESC_WEB]['referencia']['sitio']);
        $this->assertSame(100, $lineas[self::DESC_WEB]['referencia']['neto_unitario']);
        $this->assertFalse($lineas[self::DESC_WEB]['referencia']['stock_verificado']);
        $this->assertTrue(collect($preview['avisos'])->contains(fn ($a) => str_contains($a, 'stock no verificado')));

        Http::assertSent(function (HttpRequest $request) {
            $cuerpo = $request->body();

            return str_contains($cuerpo, 'HIG002')
                && ! str_contains($cuerpo, 'prod_valor')
                && ! str_contains($cuerpo, '"700"')
                && $request->hasHeader('x-goog-api-key', 'test-key');
        });

        $aplicar = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'rechazados' => [],
                'reemplazar' => true,
            ])
            ->assertOk();

        $aplicar->assertJsonPath('eliminadas', 4);
        $aplicar->assertJsonPath('agregadas', 4);
        $aplicar->assertJsonPath('vinculadas', 3);
        $aplicar->assertJsonPath('referencias_web', 1);

        $detalle = NotaDetalle::query()->where('nronota', $nota->nronota)->orderBy('orden')->get()->keyBy('prod_descripcion_agile');
        $this->assertCount(4, $detalle);
        $this->assertSame('ARTE001', trim($detalle[self::DESC_FRASE]->prod_item));
        $this->assertSame('PAPEL001', trim($detalle[self::DESC_APRENDIDO]->prod_item));
        $this->assertSame('HIG002', trim($detalle[self::DESC_IA]->prod_item));
        $this->assertSame(854, (int) $detalle[self::DESC_IA]->prod_valor);

        $web = $detalle[self::DESC_WEB];
        $this->assertStringStartsWith('NOK-', (string) $web->prod_item);
        $this->assertSame(100, (int) $web->prod_valor_costo);
        $this->assertSame(122, (int) $web->prod_valor);
        $this->assertStringContainsString('Ref. Mercado Libre', (string) $web->observacion);
        $this->assertStringContainsString('https://articulo.mercadolibre.cl/MLC-123-tornillo', (string) $web->observacion);
        $this->assertStringContainsString('stock no verificado', (string) $web->observacion);
        $this->assertNull($detalle[self::DESC_IA]->observacion);

        $hash = app(AgileVinculoAprendizajeService::class)->hashDescripcion(self::DESC_IA);
        $aprendido = AgileMaeprod::query()->where('descripcion_norm_hash', $hash)->first();
        $this->assertNotNull($aprendido);
        $this->assertSame('HIG002', $aprendido->prod_item);
        $this->assertSame(VinculoOrigen::IA->value, $aprendido->vinculado_origen);
    }

    public function test_pack_de_n_se_vincula_al_producto_unitario_con_costo_por_n_y_sin_aprendizaje(): void
    {
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => [['codigo' => 'HIG002', 'unidades' => 2]], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini(['resultados' => []])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $linea = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame('HIG002', $linea['producto']['prod_item']);
        $this->assertSame(2, $linea['producto']['unidades']);
        $this->assertSame(1400, $linea['costo']);
        $this->assertSame(1708, $linea['precio_venta']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'rechazados' => [],
                'reemplazar' => true,
            ])
            ->assertOk()
            ->assertJsonPath('aprendidas', 0);

        $detalle = NotaDetalle::query()->where('nronota', $nota->nronota)->get()->keyBy('prod_descripcion_agile');
        $ia = $detalle[self::DESC_IA];
        $this->assertSame('HIG002', trim($ia->prod_item));
        $this->assertSame(4, (int) $ia->cantidad);
        $this->assertSame(1400, (int) $ia->prod_valor_costo);
        $this->assertSame(1708, (int) $ia->prod_valor);
        $this->assertStringContainsString('Pack de 2', (string) $ia->observacion);

        $hash = app(AgileVinculoAprendizajeService::class)->hashDescripcion(self::DESC_IA);
        $this->assertNull(AgileMaeprod::query()->where('descripcion_norm_hash', $hash)->first());
    }

    public function test_pack_menor_del_maestro_se_vincula_con_los_packs_necesarios(): void
    {
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => [['codigo' => 'HIG002', 'unidades' => 4]], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini(['resultados' => []])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $linea = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame('HIG002', $linea['producto']['prod_item']);
        $this->assertSame(4, $linea['producto']['unidades']);
        $this->assertSame(2800, $linea['costo']);
        $this->assertSame(3416, $linea['precio_venta']);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->body(), 'redondeado hacia arriba'));
    }

    public function test_unidades_sobre_el_maximo_se_descartan_en_vez_de_truncarse(): void
    {
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => [['codigo' => 'HIG002', 'unidades' => 5000]], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini(['resultados' => []])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $linea = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertNotSame(CotizarIaService::ESTADO_VINCULADO, $linea['estado']);
        $this->assertNull($linea['producto']);
    }

    public function test_candidato_dudoso_se_confirma_por_foto_y_queda_en_la_observacion(): void
    {
        config(['products.image_base_url' => 'https://img.test']);
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'img.test/*' => Http::response('JPEG', 200, ['Content-Type' => 'image/jpeg']),
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => [], 'revisar_foto' => [
                            ['codigo' => 'HIG001', 'unidades' => 1, 'falta' => 'hoja doble'],
                            ['codigo' => 'HIG002', 'unidades' => 1, 'falta' => 'hoja doble'],
                        ], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'codigo' => 'HIG001', 'coincide' => true, 'se_ve' => 'rollo con hoja doble en el envase'],
                        ['i' => 2, 'codigo' => 'HIG002', 'coincide' => false, 'se_ve' => ''],
                    ],
                ]))
                ->push($this->respuestaGemini(['resultados' => []])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $linea = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame(CotizarIaService::ORIGEN_FOTO, $linea['origen']);
        $this->assertSame('HIG001', $linea['producto']['prod_item']);
        $this->assertSame('rollo con hoja doble en el envase', $linea['producto']['foto']);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->body(), 'inline_data')
            && str_contains($request->body(), 'Confirmar: hoja doble'));

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'rechazados' => [],
                'reemplazar' => true,
            ])
            ->assertOk();

        $ia = NotaDetalle::query()->where('nronota', $nota->nronota)->get()->keyBy('prod_descripcion_agile')[self::DESC_IA];
        $this->assertSame('HIG001', trim($ia->prod_item));
        $this->assertStringContainsString('Elegido por foto: se ve rollo con hoja doble en el envase en la imagen de HIG001', (string) $ia->observacion);
    }

    public function test_sin_foto_en_el_maestro_no_se_consulta_a_la_ia_y_queda_sin_vinculo(): void
    {
        config(['products.image_base_url' => 'https://img.test']);
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'img.test/*' => Http::response('', 404),
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => [], 'revisar_foto' => [
                            ['codigo' => 'HIG001', 'unidades' => 1, 'falta' => 'hoja doble'],
                        ], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini(['resultados' => []])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $linea = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertNotSame(CotizarIaService::ESTADO_VINCULADO, $linea['estado']);
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->body(), 'inline_data'));
    }

    public function test_referencia_web_de_pack_solicitado_usa_costo_del_pack_completo(): void
    {
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => ['HIG001'], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini([
                    'resultados' => [[
                        'i' => 3,
                        'unidades_solicitud' => 2,
                        'opciones' => [
                            // Pide 5 packs de 2 = 10 unidades: 4 packs de 2 no alcanzan; 5 sí.
                            ['sitio' => 'mercadolibre', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada pack 2', 'precio_clp' => 1190, 'unidades_por_pack' => 2, 'stock_disponible' => 4, 'url' => $this->urlBusqueda('https://articulo.mercadolibre.cl/MLC-1-tornillo')],
                            ['sitio' => 'mercadolibre', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada pack 2', 'precio_clp' => 1428, 'unidades_por_pack' => 2, 'stock_disponible' => 5, 'url' => $this->urlBusqueda('https://articulo.mercadolibre.cl/MLC-2-tornillo')],
                        ],
                    ]],
                ])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_WEB);
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $web['estado']);
        $this->assertSame('https://articulo.mercadolibre.cl/MLC-2-tornillo', $web['referencia']['url']);
        $this->assertSame(2, $web['referencia']['unidades_solicitud']);
        $this->assertSame(1200, $web['referencia']['neto_unitario']);
        $this->assertSame(1200, $web['costo']);
        $this->assertSame(1464, $web['precio_venta']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'rechazados' => [],
                'reemplazar' => true,
            ])
            ->assertOk();

        $linea = NotaDetalle::query()->where('nronota', $nota->nronota)->where('prod_descripcion_agile', self::DESC_WEB)->firstOrFail();
        $this->assertSame(1200, (int) $linea->prod_valor_costo);
        $this->assertSame(1464, (int) $linea->prod_valor);
        $this->assertStringContainsString('neto por pack de 2', (string) $linea->observacion);
    }

    public function test_factor_de_venta_por_region_en_preview_y_factor_manual_al_aplicar(): void
    {
        $nota = $this->crearNotaConLineas();
        $nota->update(['region' => 8]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => ['HIG002'], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini(['resultados' => []])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $this->assertSame(1.3, $preview['venta']['factor']);
        $this->assertSame(8, $preview['venta']['region']);
        $this->assertFalse(collect($preview['avisos'])->contains(fn ($a) => str_contains($a, 'región del organismo')));
        $ia = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame(700, $ia['costo']);
        $this->assertSame(910, $ia['precio_venta']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'rechazados' => [],
                'reemplazar' => true,
                'factor' => 1.5,
            ])
            ->assertOk();

        $linea = NotaDetalle::query()->where('nronota', $nota->nronota)->where('prod_descripcion_agile', self::DESC_IA)->firstOrFail();
        $this->assertSame(1050, (int) $linea->prod_valor);
        $this->assertSame(1.5, (float) $nota->fresh()->factor_precio_venta);
    }

    public function test_preview_sin_region_avisa_y_usa_factor_de_la_nota(): void
    {
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini(['resultados' => []]))
                ->push($this->respuestaGemini(['resultados' => []])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $this->assertSame(1.22, $preview['venta']['factor']);
        $this->assertNull($preview['venta']['region']);
        $this->assertTrue(collect($preview['avisos'])->contains(fn ($a) => str_contains($a, 'región del organismo')));
    }

    public function test_rechazar_vinculo_deja_linea_pendiente_sin_aprendizaje(): void
    {
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => ['HIG001'], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini(['resultados' => []])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $indiceIa = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA)['indice'];

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'rechazados' => [$indiceIa],
                'reemplazar' => true,
            ])
            ->assertOk()
            ->assertJsonPath('vinculadas', 2)
            ->assertJsonPath('pendientes', 2);

        $linea = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('prod_descripcion_agile', self::DESC_IA)
            ->first();
        $this->assertStringStartsWith('NOK-', (string) $linea->prod_item);

        $hash = app(AgileVinculoAprendizajeService::class)->hashDescripcion(self::DESC_IA);
        $this->assertFalse(AgileMaeprod::query()->where('descripcion_norm_hash', $hash)->exists());
    }

    public function test_busqueda_web_con_texto_no_json_se_reintenta_una_vez(): void
    {
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => ['HIG001'], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'Busqué en [sodimac.cl] pero no pude']]]]]])
                ->push($this->respuestaGemini([
                    'resultados' => [[
                        'i' => 3,
                        'opciones' => [
                            ['sitio' => 'sodimac', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada', 'precio_clp' => 2380, 'unidades_por_pack' => 1, 'url' => $this->urlBusqueda('https://www.sodimac.cl/sodimac-cl/product/123')],
                        ],
                    ]],
                ])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_WEB);
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $web['estado']);
        $this->assertFalse(collect($preview['avisos'])->contains(fn ($a) => str_contains($a, 'JSON')));
        Http::assertSentCount(4);
    }

    public function test_busqueda_web_sin_json_tras_reintento_deja_pendiente_y_avisa(): void
    {
        $nota = $this->crearNotaConLineas();
        $textoInvalido = ['candidates' => [['content' => ['parts' => [['text' => 'No encontré resultados.']]]]]];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => ['HIG001'], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($textoInvalido)
                ->push($textoInvalido),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_WEB);
        $this->assertSame(CotizarIaService::ESTADO_PENDIENTE, $web['estado']);
        $this->assertTrue(collect($preview['avisos'])->contains(fn ($a) => str_contains($a, 'no devolvió un resultado legible')));
    }

    public function test_busqueda_web_usa_modelo_web_y_sin_candidatos_pasa_al_siguiente(): void
    {
        config(['cotiz.gemini.modelo_web' => 'gemini-web']);
        $nota = $this->crearNotaConLineas();
        $redireccion = $this->urlBusqueda('https://articulo.mercadolibre.cl/MLC-999-tornillo');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => ['HIG001'], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push(['usageMetadata' => ['thoughtsTokenCount' => 4000]])
                ->push($this->respuestaGemini([
                    'resultados' => [[
                        'i' => 3,
                        'opciones' => [
                            ['sitio' => 'mercadolibre', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada', 'precio_clp' => 2380, 'unidades_por_pack' => 1, 'url' => $redireccion],
                        ],
                    ]],
                ])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_WEB);
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $web['estado']);
        $this->assertSame('https://articulo.mercadolibre.cl/MLC-999-tornillo', $web['referencia']['url']);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'models/gemini-web:')
            && isset($request->data()['tools'])
            && ! isset($request->data()['generationConfig']['thinkingConfig']));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'models/gemini-principal:')
            && isset($request->data()['tools']));
    }

    public function test_busqueda_web_descarta_urls_escritas_por_el_modelo_y_listados(): void
    {
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => ['HIG001'], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini([
                    'resultados' => [[
                        'i' => 3,
                        'opciones' => [
                            ['sitio' => 'mercadolibre', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada', 'precio_clp' => 500, 'unidades_por_pack' => 1, 'url' => 'https://articulo.mercadolibre.cl/MLC-111-tornillo'],
                            ['sitio' => 'mercadolibre', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada', 'precio_clp' => 600, 'unidades_por_pack' => 1, 'url' => $this->urlBusqueda('https://listado.mercadolibre.cl/tornillo-autoperforante')],
                            ['sitio' => 'mercadolibre', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada', 'precio_clp' => 700, 'unidades_por_pack' => 1, 'url' => $this->urlBusqueda('https://www.mercadolibre.cl/tornillo-autoperforante/p')],
                        ],
                    ]],
                ])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_WEB);
        $this->assertSame(CotizarIaService::ESTADO_PENDIENTE, $web['estado']);
        $this->assertTrue(collect($preview['avisos'])->contains(fn ($a) => str_contains($a, 'Se descartaron 3 publicación(es) web')));
    }

    public function test_es_pagina_producto(): void
    {
        $service = app(CotizarIaService::class);

        $this->assertTrue($service->esPaginaProducto('https://articulo.mercadolibre.cl/MLC-1430030589-pack-6-plumon-_JM'));
        $this->assertTrue($service->esPaginaProducto('https://www.mercadolibre.cl/cartulina-metalica-fucsia/p/MLC12345678'));
        $this->assertTrue($service->esPaginaProducto('https://www.mercadolibre.cl/cartulina/up/MLCU987654'));
        $this->assertTrue($service->esPaginaProducto('https://www.sodimac.cl/sodimac-cl/product/110311/tornillo/110311/'));
        $this->assertFalse($service->esPaginaProducto('https://articulo.mercadolibre.cl/MLC-cartulina-metalica-celeste-pliego-50x70cms'));
        $this->assertFalse($service->esPaginaProducto('https://www.mercadolibre.cl/pliego-cartulina-metalica-50x70-cm-manualidades-color-fucsia/p'));
        $this->assertFalse($service->esPaginaProducto('https://listado.mercadolibre.cl/pliego-cartulina-metalica-50x70-cm'));
        $this->assertFalse($service->esPaginaProducto('https://www.sodimac.cl/sodimac-cl/search?Ntt=tornillo'));
        $this->assertFalse($service->esPaginaProducto('https://www.lider.cl/product/123'));
    }

    public function test_busqueda_web_exige_stock_suficiente_para_la_cantidad(): void
    {
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => [], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini([
                    'resultados' => [
                        [
                            'i' => 2,
                            'opciones' => [
                                ['sitio' => 'mercadolibre', 'titulo' => self::DESC_IA, 'precio_clp' => 1190, 'unidades_por_pack' => 1, 'stock_disponible' => 0, 'url' => $this->urlBusqueda('https://articulo.mercadolibre.cl/MLC-1')],
                                ['sitio' => 'sodimac', 'titulo' => self::DESC_IA, 'precio_clp' => 2380, 'unidades_por_pack' => 1, 'stock_disponible' => 2, 'url' => $this->urlBusqueda('https://www.sodimac.cl/sodimac-cl/product/1')],
                            ],
                        ],
                        [
                            'i' => 3,
                            'opciones' => [
                                ['sitio' => 'mercadolibre', 'titulo' => self::DESC_WEB, 'precio_clp' => 1190, 'unidades_por_pack' => 1, 'stock_disponible' => 3, 'url' => $this->urlBusqueda('https://articulo.mercadolibre.cl/MLC-2')],
                                ['sitio' => 'sodimac', 'titulo' => self::DESC_WEB, 'precio_clp' => 2380, 'unidades_por_pack' => 1, 'stock_disponible' => null, 'url' => $this->urlBusqueda('https://www.sodimac.cl/sodimac-cl/product/2')],
                                ['sitio' => 'mercadolibre', 'titulo' => self::DESC_WEB.' pack', 'precio_clp' => 35700, 'unidades_por_pack' => 10, 'stock_disponible' => 1, 'url' => $this->urlBusqueda('https://articulo.mercadolibre.cl/MLC-3')],
                            ],
                        ],
                    ],
                ])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $lineas = collect($preview['lineas'])->keyBy('descripcion');

        // Pide 4 unidades: stock 0 y 2 no alcanzan.
        $this->assertSame(CotizarIaService::ESTADO_PENDIENTE, $lineas[self::DESC_IA]['estado']);
        $this->assertStringContainsString('stock suficiente para 4', (string) $lineas[self::DESC_IA]['stock_nota']);

        // Pide 5 unidades: la más barata tiene 3 (no alcanza); 1 pack de 10 sí alcanza y gana a la de stock desconocido.
        $ref = $lineas[self::DESC_WEB]['referencia'];
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $lineas[self::DESC_WEB]['estado']);
        $this->assertSame('https://articulo.mercadolibre.cl/MLC-3', $ref['url']);
        $this->assertTrue($ref['stock_verificado']);
        $this->assertSame(1, $ref['stock']);
        $this->assertSame(3000, $ref['neto_unitario']);

        $this->assertTrue(collect($preview['avisos'])->contains(fn ($a) => str_contains($a, '1 línea(s) sin publicaciones con stock suficiente')));
        $this->assertFalse(collect($preview['avisos'])->contains(fn ($a) => str_contains($a, 'stock no verificado')));
    }

    public function test_busqueda_web_por_tandas_sigue_si_una_tanda_falla(): void
    {
        config(['cotiz.gemini.lote_web' => 1]);
        $nota = $this->crearNotaConLineas();
        $textoInvalido = ['candidates' => [['content' => ['parts' => [['text' => 'No encontré resultados.']]]]]];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => [], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($textoInvalido)
                ->push($textoInvalido)
                ->push($this->respuestaGemini([
                    'resultados' => [[
                        'i' => 3,
                        'opciones' => [
                            ['sitio' => 'sodimac', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada', 'precio_clp' => 2380, 'unidades_por_pack' => 1, 'url' => $this->urlBusqueda('https://www.sodimac.cl/sodimac-cl/product/123')],
                        ],
                    ]],
                ])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $lineas = collect($preview['lineas'])->keyBy('descripcion');
        $this->assertSame(CotizarIaService::ESTADO_PENDIENTE, $lineas[self::DESC_IA]['estado']);
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $lineas[self::DESC_WEB]['estado']);
        $this->assertTrue(collect($preview['avisos'])->contains(fn ($a) => str_contains($a, '1 de 2 tanda(s)')));
        Http::assertSentCount(5);
    }

    public function test_sin_cuota_gemini_vincula_solo_con_reglas_y_avisa(): void
    {
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429, 'message' => 'quota']], 429),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $this->assertSame(2, $preview['resumen']['vinculados']);
        $this->assertSame(2, $preview['resumen']['pendientes']);
        $this->assertNotEmpty($preview['avisos']);
        $this->assertStringContainsString('sin cuota', mb_strtolower(implode(' ', $preview['avisos'])));
        Http::assertSentCount(2);
    }

    public function test_sin_cuota_gratuita_usa_cuenta_pagada_y_busqueda_web_va_directo_a_pago(): void
    {
        config(['cotiz.gemini.api_key_pago' => 'test-key-pago']);
        $nota = $this->crearNotaConLineas();
        $envios = [];

        Http::fake(function (HttpRequest $request) use (&$envios) {
            $cuenta = $request->header('x-goog-api-key')[0] === 'test-key-pago' ? 'pago' : 'gratis';
            $envios[] = [$cuenta, str_contains($request->body(), 'google_search')];
            if ($cuenta === 'gratis') {
                return Http::response(['error' => ['code' => 429, 'message' => 'quota']], 429);
            }

            return str_contains($request->body(), 'google_search')
                ? $this->respuestaGemini(['resultados' => []])
                : $this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => ['HIG001'], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]);
        });

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $lineaIa = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame(CotizarIaService::ORIGEN_IA, $lineaIa['origen']);
        $this->assertSame('HIG001', $lineaIa['producto']['prod_item']);
        $this->assertSame([
            ['gratis', false],
            ['gratis', false],
            ['pago', false],
            ['pago', true],
        ], $envios);
        $this->assertContains('Se usó la cuenta pagada de Gemini en 2 llamada(s).', $preview['avisos']);
        $this->assertSame(2, Cache::get('gemini:pago:'.now()->format('Y-m')));
    }

    public function test_tope_mensual_de_cuenta_pagada_no_la_usa(): void
    {
        config(['cotiz.gemini.api_key_pago' => 'test-key-pago', 'cotiz.gemini.pago_max_mes' => 5]);
        Cache::put('gemini:pago:'.now()->format('Y-m'), 5, now()->addDay());
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429, 'message' => 'quota']], 429),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        Http::assertNotSent(fn (HttpRequest $request) => $request->header('x-goog-api-key')[0] === 'test-key-pago');
        $this->assertStringContainsString('sin cuota', mb_strtolower(implode(' ', $preview['avisos'])));
        $this->assertSame(2, $preview['resumen']['pendientes']);
    }

    public function test_progreso_informa_etapa_y_se_limpia_al_terminar(): void
    {
        $nota = $this->crearNotaConLineas();
        $progresoId = str_repeat('ab12', 8);
        $vistos = [];

        Http::fake(function (HttpRequest $request) use ($progresoId, &$vistos) {
            $vistos[] = app(CotizarIaService::class)->leerProgreso('admin', $progresoId);
            if (str_contains($request->url(), 'gemini-principal')) {
                return Http::response(['error' => ['code' => 503, 'message' => 'high demand']], 503);
            }

            return Http::response($this->respuestaGemini(['resultados' => []]));
        });

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota), ['progreso_id' => $progresoId])
            ->assertOk();

        $this->assertSame(5, $vistos[0]['paso']);
        $this->assertSame(7, $vistos[0]['total']);
        $this->assertStringContainsString('equivalencias', $vistos[0]['etapa']);
        $this->assertTrue(collect($vistos)->contains(
            fn ($p) => is_array($p) && str_contains($p['detalle'], 'gemini-respaldo'),
        ));

        $this->actingAs($this->admin)
            ->getJson(route('admin.cotizaciones.cotizar-ia.progreso', $progresoId))
            ->assertOk()
            ->assertJsonPath('progreso', null);

        $ejecutivo = User::factory()->create(['username' => 'jperez', 'perfil' => User::PERFIL_EJECUTIVO]);
        $this->actingAs($ejecutivo)
            ->getJson(route('admin.cotizaciones.cotizar-ia.progreso', $progresoId))
            ->assertForbidden();
    }

    public function test_preview_async_responde_202_y_deja_el_resultado_en_el_progreso(): void
    {
        $nota = $this->crearNotaConLineas();
        $progresoId = str_repeat('cd34', 8);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->respuestaGemini(['resultados' => []])),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota), ['progreso_id' => $progresoId, 'async' => true])
            ->assertStatus(202)
            ->assertJsonPath('progreso_id', $progresoId);

        $progreso = $this->actingAs($this->admin)
            ->getJson(route('admin.cotizaciones.cotizar-ia.progreso', $progresoId))
            ->assertOk()
            ->json('progreso');

        $this->assertSame(CotizarIaService::PROGRESO_LISTO, $progreso['estado']);
        $this->assertSame(4, $progreso['resultado']['resumen']['total']);
        $this->assertNotEmpty($progreso['resultado']['token']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota), ['async' => true])
            ->assertStatus(422);
    }

    public function test_preview_async_deja_el_error_en_el_progreso(): void
    {
        config(['cotiz.gemini.api_key' => '', 'cotiz.gemini.api_key_pago' => '']);
        $nota = $this->crearNotaConLineas();
        $progresoId = str_repeat('ef56', 8);

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota), ['progreso_id' => $progresoId, 'async' => true])
            ->assertStatus(202);

        $progreso = app(CotizarIaService::class)->leerProgreso('admin', $progresoId);
        $this->assertSame(CotizarIaService::PROGRESO_ERROR, $progreso['estado']);
        $this->assertStringContainsString('Gemini no está configurado', $progreso['error']);
    }

    public function test_lote_saturado_se_reintenta_y_color_no_distingue_genero(): void
    {
        $nota = $this->crearNota();
        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'NOK-1',
            'prod_valor' => 0,
            'cantidad' => 40,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 0,
            'prod_item_agile' => 'MP1',
            'prod_descripcion_agile' => 'CARTULINA COLOR BLANCO 140 GR. 53X75 CM. UNIDAD (ESCUELA CHOMIO)',
            'prod_descripcion_maestro' => 'CARTULINA COLOR BLANCO',
        ]);

        $llamadas = 0;
        Http::fake(function (HttpRequest $request) use (&$llamadas) {
            $llamadas++;
            // Primera ronda: principal y respaldo saturados (2 intentos cada uno).
            if ($llamadas <= 4) {
                return Http::response(['error' => ['code' => 503, 'message' => 'high demand']], 503);
            }

            return Http::response($this->respuestaGemini(str_contains($request->body(), 'google_search')
                ? ['resultados' => []]
                : ['resultados' => [['i' => 0, 'equivalentes' => ['CARTB'], 'busqueda' => []]]]));
        });

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $this->assertSame(CotizarIaService::ORIGEN_IA, $preview['lineas'][0]['origen']);
        $this->assertSame('CARTB', $preview['lineas'][0]['producto']['prod_item']);
        $this->assertFalse(collect($preview['avisos'])->contains(fn ($a) => str_contains($a, 'no respondió')));
    }

    public function test_lineas_iguales_quedan_separadas(): void
    {
        $nota = $this->crearNota(['encargado' => '3000-1-COT26']);
        $this->partialMock(OportunidadVinculoService::class, function ($mock) {
            $mock->shouldReceive('previewGuardado')->andReturn([
                'cabecera' => [],
                'lineas' => [
                    ['id_agile' => '', 'descripcion' => self::DESC_FRASE, 'cantidad' => 10],
                    ['id_agile' => '', 'descripcion' => self::DESC_FRASE, 'cantidad' => 25],
                ],
            ]);
        });
        Http::fake();

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->assertJsonPath('resumen.total', 2)
            ->json();

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), ['token' => $preview['token']])
            ->assertOk()
            ->assertJsonPath('agregadas', 2);

        $lineas = NotaDetalle::query()->where('nronota', $nota->nronota)->orderBy('orden')->get();
        $this->assertCount(2, $lineas);
        $this->assertSame([10, 25], $lineas->pluck('cantidad')->map(fn ($c) => (int) $c)->all());
        $this->assertNotSame($lineas[0]->prod_item_agile, $lineas[1]->prod_item_agile);
        Http::assertNothingSent();
    }

    public function test_solicitantes_separados_crean_copias_con_obs_ejecutivo_al_confirmar(): void
    {
        $nota = $this->crearNota(['encargado' => '3000-2-COT26', 'observacion_ejecutivo' => 'Revisar plazo']);
        $this->partialMock(OportunidadVinculoService::class, function ($mock) {
            $mock->shouldReceive('previewGuardado')->andReturn([
                'cabecera' => [],
                'lineas' => [['id_agile' => 'MP1', 'descripcion' => 'MATERIALES SEGUN ADJUNTO', 'cantidad' => 1]],
            ]);
        });
        $this->partialMock(OportunidadAdjuntoService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturnTrue();
            $mock->shouldReceive('buscarSiPendiente')->andReturnNull();
            $mock->shouldReceive('listar')->andReturn([['nombre' => 'pedido.xlsx', 'bytes' => 100]]);
            $mock->shouldReceive('contenido')->andReturn('binario');
            $mock->shouldReceive('textoExcel')->andReturn('Escuela A: lapices. Escuela B: lapices y gredas. Cotizar por separado.');
        });
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()->push($this->respuestaGemini([
                'fuente' => 'adjunto',
                'motivo' => 'Pide una cotización por escuela.',
                'adjuntos_usados' => ['pedido.xlsx'],
                'separar' => true,
                'grupos_ficha' => [],
                'lineas' => [
                    ['descripcion' => self::DESC_FRASE, 'cantidad' => 10, 'solicitante' => 'Escuela A'],
                    ['descripcion' => self::DESC_FRASE, 'cantidad' => 5, 'solicitante' => 'Escuela B'],
                    ['descripcion' => self::DESC_APRENDIDO, 'cantidad' => 3, 'solicitante' => 'Escuela B'],
                ],
            ])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->assertJsonPath('separar', true)
            ->assertJsonPath('grupos.0.solicitante', 'Escuela A')
            ->assertJsonPath('grupos.0.indices', [0])
            ->assertJsonPath('grupos.1.solicitante', 'Escuela B')
            ->assertJsonPath('grupos.1.indices', [1, 2])
            ->json();
        $this->assertSame(1, Nota::query()->count());

        $aplicar = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'separar' => true,
            ])
            ->assertOk()
            ->assertJsonPath('agregadas', 3)
            ->assertJsonCount(2, 'cotizaciones')
            ->assertJsonPath('cotizaciones.0.nronota', $nota->nronota);

        $this->assertSame("Revisar plazo\nRequerimiento de: Escuela A", $nota->fresh()->observacion_ejecutivo);
        $this->assertSame([10], NotaDetalle::query()->where('nronota', $nota->nronota)->pluck('cantidad')->map(fn ($c) => (int) $c)->all());

        $copia = Nota::query()->findOrFail($aplicar->json('cotizaciones.1.nronota'));
        $this->assertSame('3000-2-COT26', $copia->encargado);
        $this->assertSame('Requerimiento de: Escuela B', $copia->observacion_ejecutivo);
        $this->assertSame(
            ['ARTE001' => 5, 'PAPEL001' => 3],
            NotaDetalle::query()->where('nronota', $copia->nronota)->get()
                ->mapWithKeys(fn (NotaDetalle $d) => [trim($d->prod_item) => (int) $d->cantidad])->sortKeys()->all(),
        );
        $this->assertStringContainsString(route('admin.cotizaciones.edit', $copia->nronota), (string) $aplicar->json('cotizaciones.1.edit_url'));
    }

    public function test_stock_prisa_cambia_agotado_por_equivalente_y_a_pedido_queda_pendiente(): void
    {
        config(['cotiz.prisa.habilitado' => true]);
        $nota = $this->crearNota();
        foreach ([self::DESC_FRASE, self::DESC_APRENDIDO, self::DESC_IA] as $n => $desc) {
            NotaDetalle::query()->create([
                'nronota' => $nota->nronota,
                'prod_item' => 'NOK-'.($n + 1),
                'prod_valor' => 0,
                'cantidad' => 5,
                'fechahora' => now(),
                'orden' => $n + 1,
                'prod_valor_costo' => 0,
                'prod_item_agile' => 'MP'.($n + 1),
                'prod_descripcion_agile' => $desc,
                'prod_descripcion_maestro' => $desc,
            ]);
        }

        $estadosPrisa = ['HIG002' => '9102', 'HIG001' => '9103', 'PAPEL001' => '9105'];
        Http::fake(function (HttpRequest $request) use ($estadosPrisa) {
            if (str_starts_with($request->url(), 'https://prisa.test/')) {
                if (! str_contains($request->header('Cookie')[0] ?? '', 'OCXS=')) {
                    return Http::response('<script>var a=toNumbers("'.str_repeat('a1', 16).'"),b=toNumbers("'.str_repeat('b2', 16).'"),'
                        .'c=toNumbers("'.str_repeat('c3', 16).'");document.cookie="OCXS="+toHex(slowAES.decrypt(c,2,a,b));</script>');
                }
                $codigo = (string) ($request->data()['search'] ?? '');
                $filas = isset($estadosPrisa[$codigo])
                    ? [['sku' => $codigo, 'availability' => $estadosPrisa[$codigo], 'view_link' => '/producto-'.strtolower($codigo)]]
                    : [];

                return Http::response('<div data-page-component-options="'
                    .htmlspecialchars(json_encode(['data' => ['data' => $filas]]), ENT_QUOTES).'"></div>');
            }

            return Http::response($this->respuestaGemini(str_contains($request->body(), 'google_search')
                ? ['resultados' => []]
                : ['resultados' => [['i' => 2, 'equivalentes' => ['HIG001', 'HIG002'], 'busqueda' => []]]]));
        });

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();
        $lineas = collect($preview['lineas'])->keyBy('descripcion');

        $frase = $lineas[self::DESC_FRASE];
        $this->assertSame('ARTE001', $frase['producto']['prod_item']);
        $this->assertNull($frase['stock_prisa']);

        $ia = $lineas[self::DESC_IA];
        $this->assertSame(CotizarIaService::ESTADO_VINCULADO, $ia['estado']);
        $this->assertSame('HIG001', $ia['producto']['prod_item']);
        $this->assertSame('DISPONIBLE', $ia['stock_prisa']['etiqueta']);
        $this->assertSame('https://prisa.test/producto-hig001', $ia['stock_prisa']['url']);
        $this->assertStringContainsString('HIG002 AGOTADO', (string) $ia['stock_nota']);

        $aPedido = $lineas[self::DESC_APRENDIDO];
        $this->assertSame(CotizarIaService::ESTADO_PENDIENTE, $aPedido['estado']);
        $this->assertNull($aPedido['producto']);
        $this->assertStringContainsString('PAPEL001', (string) $aPedido['stock_nota']);
        $this->assertStringContainsString('A PEDIDO', (string) $aPedido['stock_nota']);

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://prisa.test/')
            && str_contains($r->header('Cookie')[0] ?? '', 'OCXS='));
        $this->assertTrue(collect($preview['avisos'])->contains(fn ($a) => str_contains($a, '2 producto(s) sin stock')));
    }

    public function test_modelo_saturado_usa_modelo_de_respaldo(): void
    {
        $nota = $this->crearNotaConLineas();

        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), 'gemini-principal')) {
                return Http::response(['error' => ['code' => 503, 'message' => 'high demand']], 503);
            }
            $cuerpo = $request->body();

            return Http::response($this->respuestaGemini(str_contains($cuerpo, 'google_search')
                ? ['resultados' => []]
                : ['resultados' => [['i' => 2, 'equivalentes' => ['HIG002'], 'busqueda' => []]]]));
        });

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $ia = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame(CotizarIaService::ORIGEN_IA, $ia['origen']);
        $this->assertSame('HIG002', $ia['producto']['prod_item']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'gemini-respaldo'));
    }

    public function test_preview_exige_codigo_de_cotizacion(): void
    {
        $nota = $this->crearNota(['encargado' => '']);
        Http::fake();

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');

        Http::assertNothingSent();
    }

    public function test_borrador_preview_no_graba_y_aplicar_crea_nota_con_cabecera_mp(): void
    {
        $codigo = '2547-205-COT26';
        $this->partialMock(CompraAgilOportunidadService::class, function ($mock) {
            $mock->shouldReceive('assertExisteEnMpSiCompraAgil')->andReturnNull();
        });
        $this->partialMock(OportunidadVinculoService::class, function ($mock) use ($codigo) {
            $mock->shouldReceive('previewGuardado')->with($codigo)->andReturn([
                'cabecera' => [
                    'codigo_cotizacion' => $codigo,
                    'empresa' => 'MUNICIPALIDAD DE PRUEBA',
                    'rutempresa' => '69.000.000-1',
                    'nombre' => 'Compra de papel',
                    'region' => 5,
                ],
                'lineas' => [
                    ['id_agile' => 'MP1', 'descripcion' => self::DESC_FRASE, 'cantidad' => 3],
                    ['id_agile' => 'MP2', 'descripcion' => self::DESC_IA, 'cantidad' => 10],
                ],
            ]);
        });

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [['i' => 1, 'equivalentes' => ['HIG002'], 'busqueda' => []]],
                ])),
        ]);

        $notasAntes = Nota::query()->count();

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', 0), ['codigo' => strtolower($codigo)])
            ->assertOk()
            ->assertJsonPath('codigo', $codigo)
            ->json();

        $this->assertSame($notasAntes, Nota::query()->count());

        $aplicar = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', 0), [
                'token' => $preview['token'],
                'rechazados' => [],
                'reemplazar' => false,
            ])
            ->assertOk()
            ->assertJsonPath('vinculadas', 2)
            ->assertJsonPath('recien_creada', true);

        $nota = Nota::query()->findOrFail($aplicar->json('nronota'));
        $this->assertSame($codigo, $nota->encargado);
        $this->assertSame('MUNICIPALIDAD DE PRUEBA', $nota->empresa);
        $this->assertSame(5, (int) $nota->region);
        $this->assertSame(1.30, (float) $nota->factor_precio_venta);

        $ia = NotaDetalle::query()->where('nronota', $nota->nronota)->where('prod_descripcion_agile', self::DESC_IA)->firstOrFail();
        $this->assertSame('HIG002', trim($ia->prod_item));
        $this->assertSame(910, (int) $ia->prod_valor);
    }

    public function test_sitio_permitido_solo_mercado_libre_y_sodimac(): void
    {
        $service = app(CotizarIaService::class);

        $this->assertSame('Mercado Libre', $service->sitioPermitido('https://articulo.mercadolibre.cl/MLC-1'));
        $this->assertSame('Mercado Libre', $service->sitioPermitido('https://mercadolibre.cl/x'));
        $this->assertSame('Sodimac', $service->sitioPermitido('https://www.sodimac.cl/sodimac-cl/product/1'));
        $this->assertNull($service->sitioPermitido('https://mercadolibre.cl.evil.com/x'));
        $this->assertNull($service->sitioPermitido('https://www.lider.cl/x'));
        $this->assertNull($service->sitioPermitido('javascript:alert(1)'));
    }

    /**
     * @param  array<string, mixed>  $json
     */
    /** Enlace de resultado de búsqueda de Google que redirige a $destino. */
    private function urlBusqueda(string $destino): string
    {
        return 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/'.rtrim(strtr(base64_encode($destino), '+/', '-_'), '=');
    }

    private function respuestaGemini(array $json): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode($json, JSON_UNESCAPED_UNICODE)]]],
            ]],
        ];
    }

    private function crearNotaConLineas(): Nota
    {
        $nota = $this->crearNota();
        foreach ([self::DESC_FRASE, self::DESC_APRENDIDO, self::DESC_IA, self::DESC_WEB] as $n => $desc) {
            NotaDetalle::query()->create([
                'nronota' => $nota->nronota,
                'prod_item' => 'NOK-'.($n + 1),
                'prod_valor' => 0,
                'cantidad' => $n + 2,
                'fechahora' => now(),
                'orden' => $n + 1,
                'prod_valor_costo' => 0,
                'prod_item_agile' => 'MP'.($n + 1),
                'prod_descripcion_agile' => $desc,
                'prod_descripcion_maestro' => $desc,
            ]);
        }

        return $nota;
    }

    private function crearNota(array $attrs = []): Nota
    {
        return Nota::query()->create(array_merge([
            'nronota' => 300,
            'descripcion' => 'Test cotizar IA',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => '',
            'encargado' => '1000-1-COT26',
            'nota_softland' => 30000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ], $attrs));
    }
}
