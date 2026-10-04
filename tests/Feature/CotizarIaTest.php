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
use App\Services\ImagenReferenciaWebService;
use App\Services\MaeprodBusquedaSimilitudService;
use App\Services\MercadoLibreApiService;
use App\Services\NotaDetalleService;
use App\Services\OportunidadAdjuntoService;
use App\Services\OportunidadVinculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
            'cotiz.prisa.busqueda_texto' => false,
            'cotiz.prisa.base_url' => 'https://prisa.test',
            'cotiz.mercadolibre.habilitado' => false,
            'products.image_base_url' => null,
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

    public function test_modal_compila_a_php_valido(): void
    {
        $compilado = app('blade.compiler')->compileString(
            file_get_contents(resource_path('views/admin/cotizaciones/partials/cotizar-ia-modal.blade.php'))
        );

        $tokens = token_get_all($compilado, TOKEN_PARSE);

        $this->assertNotEmpty($tokens);
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
        $this->assertSame(1, $preview['cotizar_ia_veces']);
        $this->assertDatabaseHas('oportunidad_cotizar_ia', [
            'codigo' => '1000-1-COT26',
            'veces' => 1,
        ]);
        $lineas = collect($preview['lineas'])->keyBy('descripcion');

        $this->assertSame(CotizarIaService::ORIGEN_FRASE, $lineas[self::DESC_FRASE]['origen']);
        $this->assertSame('ARTE001', $lineas[self::DESC_FRASE]['producto']['prod_item']);
        $this->assertSame(CotizarIaService::ORIGEN_APRENDIDO, $lineas[self::DESC_APRENDIDO]['origen']);
        $this->assertSame(CotizarIaService::ORIGEN_IA, $lineas[self::DESC_IA]['origen']);
        $this->assertSame('HIG002', $lineas[self::DESC_IA]['producto']['prod_item']);
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $lineas[self::DESC_WEB]['estado']);
        $this->assertSame('Mercado Libre', $lineas[self::DESC_WEB]['referencia']['sitio']);
        $this->assertSame(119, $lineas[self::DESC_WEB]['referencia']['neto_unitario']);
        $this->assertTrue($lineas[self::DESC_WEB]['puede_prorratear']);
        $this->assertSame(11900, $lineas[self::DESC_WEB]['precio_listado']);
        $this->assertSame(11900, $lineas[self::DESC_WEB]['costo']);
        $this->assertSame(119, $lineas[self::DESC_WEB]['costo_prorrateado']);
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
        $this->assertSame(700, (int) $detalle[self::DESC_IA]->prod_valor_costo);

        $web = $detalle[self::DESC_WEB];
        $this->assertStringStartsWith('NOK-', (string) $web->prod_item);
        $this->assertSame(11900, (int) $web->prod_valor_costo);
        $this->assertSame(14518, (int) $web->prod_valor);
        $this->assertSame(5, (int) $web->cantidad);
        $this->assertStringContainsString('Ref. Mercado Libre', (string) $web->observacion);
        $this->assertStringContainsString('Sin prorrateo', (string) $web->observacion);
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

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->body(), 'redondeo hacia arriba'));
    }

    public function test_aprendido_a_producto_sin_precio_ni_costo_no_se_usa_y_se_busca_otro(): void
    {
        Maeprod::query()->where('prod_item', 'PAPEL001')->update(['prod_valor' => 0, 'prod_valor_costo' => 0]);
        $nota = $this->crearNotaConLineas();
        $this->fakeGeminiEquivalentes([['codigo' => 'HIG002', 'unidades' => 1]]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $linea = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_APRENDIDO);
        $this->assertNotSame(CotizarIaService::ORIGEN_APRENDIDO, $linea['origen']);
        $this->assertSame(CotizarIaService::ESTADO_PENDIENTE, $linea['estado']);
        $this->assertNull($linea['producto']);
    }

    public function test_pack_mayor_del_maestro_prorratea_precio_por_unidad_solicitada(): void
    {
        Maeprod::query()->where('prod_item', 'HIG002')->update([
            'prod_nombre' => 'PAPEL HIGIENICO HOJA DOBLE 50 MTS (100 UNIDADES)',
            'prod_valor' => 3900,
            'prod_valor_costo' => 3000,
        ]);
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => [['codigo' => 'HIG002', 'unidades' => 1]], 'busqueda' => []],
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
        $this->assertSame(100, $linea['producto']['pack_maestro']);
        $this->assertTrue($linea['puede_prorratear']);
        $this->assertSame(3900, $linea['precio_listado']);
        $this->assertSame(3660, $linea['precio_venta']);
        $this->assertSame(3000, $linea['costo']);
        $this->assertSame(30, $linea['costo_prorrateado']);
        $this->assertSame(3000, $linea['costo_pack']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'rechazados' => [],
                'reemplazar' => true,
                'prorratear' => [$linea['indice']],
            ])
            ->assertOk();

        $ia = NotaDetalle::query()->where('nronota', $nota->nronota)->where('prod_descripcion_agile', self::DESC_IA)->firstOrFail();
        $this->assertSame('HIG002', trim($ia->prod_item));
        $this->assertSame(37, (int) $ia->prod_valor);
        $this->assertSame(30, (int) $ia->prod_valor_costo);
        $this->assertStringContainsString('Precio prorrateado', (string) $ia->observacion);
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

    public function test_sin_equivalente_por_nombre_se_revisa_la_foto_de_los_mejores_candidatos(): void
    {
        config(['products.image_base_url' => 'https://img.test']);
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'img.test/*' => Http::response('JPEG', 200, ['Content-Type' => 'image/jpeg']),
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => [], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'codigo' => 'HIG002', 'coincide' => true, 'se_ve' => 'envase dice hoja doble 50 mts'],
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
        $this->assertSame('HIG002', $linea['producto']['prod_item']);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->body(), 'Confirmar: que sea el producto solicitado'));
    }

    public function test_dudoso_mas_barato_que_el_equivalente_se_revisa_por_foto_y_lo_reemplaza(): void
    {
        config(['products.image_base_url' => 'https://img.test']);
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'img.test/*' => Http::response('JPEG', 200, ['Content-Type' => 'image/jpeg']),
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => ['HIG001'], 'revisar_foto' => [
                            ['codigo' => 'HIG002', 'unidades' => 1, 'falta' => 'hoja doble'],
                        ], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'codigo' => 'HIG002', 'coincide' => true, 'se_ve' => 'hoja doble en el envase'],
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
        $this->assertSame('HIG002', $linea['producto']['prod_item']);
        $this->assertSame(854, $linea['precio_venta']);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->body(), 'Confirmar: hoja doble'));
    }

    public function test_dudoso_mas_caro_que_el_equivalente_no_se_revisa_por_foto(): void
    {
        config(['products.image_base_url' => 'https://img.test']);
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'img.test/*' => Http::response('JPEG', 200, ['Content-Type' => 'image/jpeg']),
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => ['HIG002'], 'revisar_foto' => [
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
        $this->assertSame(CotizarIaService::ORIGEN_IA, $linea['origen']);
        $this->assertSame('HIG002', $linea['producto']['prod_item']);
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->body(), 'inline_data'));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->body(), 'incluye 100 lanyard'));
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
        $this->assertSame(1428, $web['referencia']['neto_unitario']);
        $this->assertSame(1428, $web['costo']);
        $this->assertSame(1742, $web['precio_venta']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'rechazados' => [],
                'reemplazar' => true,
            ])
            ->assertOk();

        $linea = NotaDetalle::query()->where('nronota', $nota->nronota)->where('prod_descripcion_agile', self::DESC_WEB)->firstOrFail();
        $this->assertSame(1428, (int) $linea->prod_valor_costo);
        $this->assertSame(1742, (int) $linea->prod_valor);
        $this->assertStringContainsString('costo c/IVA por pack de 2', (string) $linea->observacion);
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

    public function test_producto_sin_costo_estima_costo_desde_precio_metropolitana_y_aplica_factor_de_region(): void
    {
        Maeprod::query()->where('prod_item', 'HIG002')->update(['prod_valor' => 14000, 'prod_valor_costo' => 0]);
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

        $ia = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame('HIG002', $ia['producto']['prod_item']);
        $this->assertTrue($ia['costo_estimado']);
        $this->assertSame(11475, $ia['costo']);
        $this->assertSame(14918, $ia['precio_venta']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'rechazados' => [],
                'reemplazar' => true,
            ])
            ->assertOk();

        $linea = NotaDetalle::query()->where('nronota', $nota->nronota)->where('prod_descripcion_agile', self::DESC_IA)->firstOrFail();
        $this->assertSame(11475, (int) $linea->prod_valor_costo);
        $this->assertSame(14918, (int) $linea->prod_valor);
    }

    public function test_producto_sin_costo_en_metropolitana_mantiene_el_precio_del_maestro(): void
    {
        Maeprod::query()->where('prod_item', 'HIG002')->update(['prod_valor' => 14000, 'prod_valor_costo' => 0]);
        $nota = $this->crearNotaConLineas();
        $nota->update(['region' => 13]);

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

        $ia = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame(1.22, $preview['venta']['factor']);
        $this->assertSame(14000, $ia['precio_venta']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'rechazados' => [],
                'reemplazar' => true,
            ])
            ->assertOk();

        $linea = NotaDetalle::query()->where('nronota', $nota->nronota)->where('prod_descripcion_agile', self::DESC_IA)->firstOrFail();
        $this->assertSame(14000, (int) $linea->prod_valor);
    }

    public function test_costo_mal_cargado_en_el_maestro_no_se_usa_manda_el_precio(): void
    {
        Maeprod::query()->where('prod_item', 'HIG002')->update(['prod_valor' => 8400, 'prod_valor_costo' => 8]);
        $nota = $this->crearNotaConLineas();
        $nota->update(['region' => 8]);

        $this->fakeGeminiEquivalentes([['codigo' => 'HIG002', 'unidades' => 1]]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $ia = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame(6885, $ia['costo']);
        $this->assertSame(8951, $ia['precio_venta']);
        $this->assertSame(8400, $ia['precio_rm']);

        $this->aplicarPreview($nota, $preview);

        $linea = NotaDetalle::query()->where('nronota', $nota->nronota)->where('prod_descripcion_agile', self::DESC_IA)->firstOrFail();
        $this->assertSame(6885, (int) $linea->prod_valor_costo);
        $this->assertSame(8951, (int) $linea->prod_valor);
    }

    public function test_elige_por_precio_del_maestro_y_no_por_su_costo(): void
    {
        Maeprod::query()->where('prod_item', 'HIG001')->update(['prod_valor' => 14000, 'prod_valor_costo' => 0]);
        Maeprod::query()->where('prod_item', 'HIG002')->update(['prod_valor' => 8400, 'prod_valor_costo' => 8]);
        $nota = $this->crearNotaConLineas();
        $nota->update(['region' => 13]);

        $this->fakeGeminiEquivalentes([
            ['codigo' => 'HIG002', 'unidades' => 2],
            ['codigo' => 'HIG001', 'unidades' => 1],
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $ia = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame('HIG001', $ia['producto']['prod_item']);
        $this->assertSame(11475, $ia['costo']);
        $this->assertSame(14000, $ia['precio_venta']);
    }

    public function test_prefiere_productos_con_precio_de_venta_sobre_los_que_solo_tienen_costo(): void
    {
        Maeprod::query()->where('prod_item', 'HIG001')->update(['prod_valor' => 14000, 'prod_valor_costo' => 0]);
        Maeprod::query()->where('prod_item', 'HIG002')->update(['prod_valor' => 0, 'prod_valor_costo' => 10992]);
        $nota = $this->crearNotaConLineas();
        $nota->update(['region' => 13]);

        $this->fakeGeminiEquivalentes([
            ['codigo' => 'HIG002', 'unidades' => 1],
            ['codigo' => 'HIG001', 'unidades' => 1],
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $ia = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame('HIG001', $ia['producto']['prod_item']);
        $this->assertSame(14000, $ia['precio_venta']);
    }

    public function test_sin_precio_de_venta_en_ningun_equivalente_usa_el_costo_como_respaldo(): void
    {
        Maeprod::query()->where('prod_item', 'HIG002')->update(['prod_valor' => 0, 'prod_valor_costo' => 10992]);
        $nota = $this->crearNotaConLineas();
        $nota->update(['region' => 13]);

        $this->fakeGeminiEquivalentes([['codigo' => 'HIG002', 'unidades' => 1]]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $ia = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame('HIG002', $ia['producto']['prod_item']);
        $this->assertSame(10992, $ia['costo']);
        $this->assertSame(13410, $ia['precio_venta']);
    }

    public function test_en_metropolitana_se_cobra_el_precio_del_maestro_aunque_no_haya_costo_exacto(): void
    {
        Maeprod::query()->where('prod_item', 'HIG002')->update(['prod_valor' => 330, 'prod_valor_costo' => 24300]);
        $nota = $this->crearNotaConLineas();
        $nota->update(['region' => 13]);

        $this->fakeGeminiEquivalentes([['codigo' => 'HIG002', 'unidades' => 1]]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $ia = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_IA);
        $this->assertSame(270, $ia['costo']);
        $this->assertSame(330, $ia['precio_venta']);

        $this->aplicarPreview($nota, $preview);

        $linea = NotaDetalle::query()->where('nronota', $nota->nronota)->where('prod_descripcion_agile', self::DESC_IA)->firstOrFail();
        $this->assertSame(270, (int) $linea->prod_valor_costo);
        $this->assertSame(330, (int) $linea->prod_valor);
    }

    /**
     * @param  list<array{codigo: string, unidades: int}>  $equivalentes
     */
    private function fakeGeminiEquivalentes(array $equivalentes): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => $equivalentes, 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]))
                ->push($this->respuestaGemini(['resultados' => []])),
        ]);
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function aplicarPreview(Nota $nota, array $preview): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'rechazados' => [],
                'reemplazar' => true,
            ])
            ->assertOk();
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
        $this->assertSame(3570, $ref['neto_unitario']);

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

    public function test_pendiente_se_vincula_desde_busqueda_prisa_antes_de_mercado_libre(): void
    {
        config([
            'cotiz.prisa.habilitado' => true,
            'cotiz.prisa.busqueda_texto' => true,
            'cotiz.mercadolibre.habilitado' => false,
        ]);
        Maeprod::query()->create([
            'prod_item' => 'TORN001',
            'prod_nombre' => 'TORNILLO AUTOPERFORANTE 8 X 1 PULGADA CAJA 100 UNIDADES',
            'prod_valor' => 5000,
            'prod_valor_costo' => 4000,
            'prod_familia' => 'VARIOS',
        ]);
        $nota = $this->crearNotaConLineas();

        Http::fake(function (HttpRequest $request) {
            if (str_starts_with($request->url(), 'https://prisa.test/')) {
                if (! str_contains($request->header('Cookie')[0] ?? '', 'OCXS=')) {
                    return Http::response('<script>var a=toNumbers("'.str_repeat('a1', 16).'"),b=toNumbers("'.str_repeat('b2', 16).'"),'
                        .'c=toNumbers("'.str_repeat('c3', 16).'");document.cookie="OCXS="+toHex(slowAES.decrypt(c,2,a,b));</script>');
                }
                $busqueda = mb_strtoupper((string) ($request->data()['search'] ?? ''));
                if (str_contains($busqueda, 'TORNILLO')) {
                    $filas = [['sku' => 'TORN001', 'name' => 'Tornillo autoperforante', 'availability' => 9103, 'view_link' => '/tornillo']];
                } else {
                    $filas = [];
                }

                return Http::response('<div data-page-component-options="'
                    .htmlspecialchars(json_encode(['data' => ['data' => $filas]]), ENT_QUOTES).'"></div>');
            }

            if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                return Http::response($this->respuestaGemini([
                    'resultados' => [
                        ['i' => 2, 'equivalentes' => ['HIG001', 'HIG002'], 'busqueda' => []],
                        ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                    ],
                ]));
            }

            return null;
        });

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_WEB);
        $this->assertSame(CotizarIaService::ESTADO_VINCULADO, $web['estado']);
        $this->assertSame(CotizarIaService::ORIGEN_PRISA, $web['origen']);
        $this->assertSame('TORN001', $web['producto']['prod_item']);
        $this->assertSame('DISPONIBLE', $web['stock_prisa']['etiqueta']);
        $this->assertNull($web['referencia']);
        $this->assertTrue(collect($preview['avisos'])->contains(fn ($a) => str_contains($a, 'vinculadas al maestro por búsqueda por descripción')));
    }

    public function test_prisa_busqueda_usa_equivalencias_de_config_antes_de_mercado_libre(): void
    {
        config([
            'cotiz.prisa.habilitado' => true,
            'cotiz.prisa.busqueda_texto' => true,
            'cotiz.mercadolibre.habilitado' => false,
        ]);
        Maeprod::query()->create([
            'prod_item' => 'REGLHOL005',
            'prod_nombre' => 'SET REGLAS ACRILICAS 30CM 4 PCS',
            'prod_valor' => 1990,
            'prod_valor_costo' => 1200,
            'prod_familia' => 'LIBR',
        ]);
        $nota = $this->crearNota();
        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'NOK-1',
            'prod_valor' => 0,
            'cantidad' => 10,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 0,
            'prod_item_agile' => 'MP1',
            'prod_descripcion_agile' => 'SET GEOMETRICO GRANDE 30 CM 04 U',
            'prod_descripcion_maestro' => 'SET GEOMETRICO GRANDE 30 CM 04 U',
        ]);

        Http::fake(function (HttpRequest $request) {
            if (str_starts_with($request->url(), 'https://prisa.test/')) {
                if (! str_contains($request->header('Cookie')[0] ?? '', 'OCXS=')) {
                    return Http::response('<script>var a=toNumbers("'.str_repeat('a1', 16).'"),b=toNumbers("'.str_repeat('b2', 16).'"),'
                        .'c=toNumbers("'.str_repeat('c3', 16).'");document.cookie="OCXS="+toHex(slowAES.decrypt(c,2,a,b));</script>');
                }
                $busqueda = mb_strtoupper((string) ($request->data()['search'] ?? ''));
                if (str_contains($busqueda, 'GEOMETRICO')) {
                    $filas = [];
                } elseif (str_contains($busqueda, 'REGLAS')) {
                    $filas = [['sku' => 'REGLHOL005', 'name' => 'Set reglas', 'availability' => 9103, 'view_link' => '/reglas']];
                } else {
                    $filas = [];
                }

                return Http::response('<div data-page-component-options="'
                    .htmlspecialchars(json_encode(['data' => ['data' => $filas]]), ENT_QUOTES).'"></div>');
            }

            if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                return Http::response($this->respuestaGemini(['resultados' => []]));
            }

            return null;
        });

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $linea = collect($preview['lineas'])->first();
        $this->assertSame(CotizarIaService::ESTADO_VINCULADO, $linea['estado']);
        $this->assertSame(CotizarIaService::ORIGEN_PRISA, $linea['origen']);
        $this->assertSame('REGLHOL005', $linea['producto']['prod_item']);
    }

    public function test_stock_prisa_cambia_agotado_por_equivalente_y_a_pedido_queda_pendiente(): void
    {
        Cache::flush();
        config([
            'cotiz.prisa.habilitado' => true,
            'cotiz.prisa.busqueda_texto' => false,
        ]);
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

    public function test_mercado_libre_sale_de_la_api_y_no_de_gemini(): void
    {
        config([
            'cotiz.mercadolibre.habilitado' => true,
            'cotiz.mercadolibre.client_id' => '7269705659698000',
            'cotiz.mercadolibre.client_secret' => 'secreto',
            'cotiz.mercadolibre.site_id' => 'MLC',
            'cotiz.mercadolibre.refresh_token' => '',
        ]);
        $nota = $this->crearNotaConLineas();

        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'token-ml',
                'expires_in' => 21600,
            ]),
            'api.mercadolibre.com/products/search*' => Http::response([
                'results' => [
                    ['id' => 'MLC777', 'name' => 'Tornillo autoperforante 8 x 1 pulgada pack 100 sin publicaciones'],
                    ['id' => 'MLC555', 'name' => 'Tornillo autoperforante 8 x 1 pulgada pack 100', 'pictures' => [
                        ['id' => 'x', 'url' => 'https://evil.example.com/foto.jpg'],
                        ['id' => 'y', 'url' => 'https://http2.mlstatic.com/D_NQ_NP_555-F.jpg'],
                    ]],
                ],
            ]),
            'http2.mlstatic.com/*' => Http::response('jpeg-falso', 200, ['Content-Type' => 'image/jpeg']),
            'api.mercadolibre.com/products/MLC777/items' => Http::response(['message' => 'not found'], 404),
            'api.mercadolibre.com/products/MLC555/items' => Http::response([
                'results' => [
                    ['item_id' => 'MLC9001', 'price' => 12900],
                    ['item_id' => 'MLC9002', 'price' => 11900],
                ],
            ]),
            'generativelanguage.googleapis.com/*' => Http::response($this->respuestaGemini([
                'resultados' => [
                    ['i' => 2, 'equivalentes' => ['HIG001'], 'busqueda' => []],
                    ['i' => 3, 'equivalentes' => [], 'busqueda' => []],
                ],
            ])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', self::DESC_WEB);
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $web['estado']);
        $this->assertSame('Mercado Libre', $web['referencia']['sitio']);
        $this->assertSame('https://www.mercadolibre.cl/p/MLC555', $web['referencia']['url']);
        $this->assertSame(11900, $web['referencia']['precio_clp']);
        $this->assertSame(100, $web['referencia']['unidades_por_pack']);
        $this->assertSame(119, $web['referencia']['neto_unitario']);
        $this->assertFalse($web['referencia']['stock_verificado']);
        $this->assertNull($web['referencia']['stock']);
        $this->assertSame('https://http2.mlstatic.com/D_NQ_NP_555-F.jpg', $web['referencia']['imagen_url']);

        config([
            'products.storage_disk' => 'r2',
            'products.r2_prefix' => 'productos',
            'products.image_base_url' => 'https://pub.r2.dev/productos',
            'filesystems.disks.r2.bucket' => 'bucket',
            'filesystems.disks.r2.key' => 'key',
            'filesystems.disks.r2.secret' => 'secret',
        ]);
        Storage::fake('r2');

        $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.aplicar', $nota->nronota), [
                'token' => $preview['token'],
                'rechazados' => [],
                'reemplazar' => true,
            ])
            ->assertOk()
            ->assertJsonPath('referencias_web', 1);

        $relativa = 'MERCADOLIBRE/'.now()->format('Y/m').'/MLC555.jpg';
        Storage::disk('r2')->assertExists('productos/'.$relativa);
        $linea = NotaDetalle::query()->where('nronota', $nota->nronota)->where('prod_descripcion_agile', self::DESC_WEB)->firstOrFail();
        $this->assertSame($relativa, $linea->imagen_ref);

        $fila = app(NotaDetalleService::class)->lineasDeNota($nota->fresh())
            ->first(fn (array $row) => (int) $row['linea']->orden === (int) $linea->orden);
        $this->assertSame('https://pub.r2.dev/productos/'.$relativa, $fila['image_url']);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'api.mercadolibre.com/products/search')
            && $request->hasHeader('Authorization', 'Bearer token-ml'));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'api.mercadolibre.com/products/MLC555/items')
            && $request->hasHeader('Authorization', 'Bearer token-ml'));
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'api.mercadolibre.com/sites/'));
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->body(), 'google_search'));
    }

    public function test_mercado_libre_busca_auto_como_automotriz(): void
    {
        config([
            'cotiz.mercadolibre.habilitado' => true,
            'cotiz.mercadolibre.client_id' => '7269705659698000',
            'cotiz.mercadolibre.client_secret' => 'secreto',
            'cotiz.mercadolibre.refresh_token' => '',
        ]);
        $desc = 'SHAMPOO AUTO CONCENTRADO';
        $nota = $this->crearNota();
        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'NOK-1',
            'prod_valor' => 0,
            'cantidad' => 200,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 0,
            'prod_item_agile' => 'MP1',
            'prod_descripcion_agile' => $desc,
            'prod_descripcion_maestro' => $desc,
        ]);

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if (str_contains($url, 'api.mercadolibre.com/oauth/token')) {
                return Http::response(['access_token' => 'token-ml', 'expires_in' => 21600]);
            }
            if (str_contains($url, 'api.mercadolibre.com/products/search')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
                $q = mb_strtolower((string) ($params['q'] ?? ''));
                if (! str_contains($q, 'automotriz')) {
                    return Http::response(['results' => []]);
                }

                return Http::response(['results' => [[
                    'id' => 'MLC88',
                    'name' => 'Shampoo Automotriz Concentrado 5 L',
                ]]]);
            }
            if (str_contains($url, 'api.mercadolibre.com/products/MLC88/items')) {
                return Http::response(['results' => [['price' => 313]]]);
            }

            return Http::response($this->respuestaGemini([
                'resultados' => [['i' => 0, 'equivalentes' => [], 'busqueda' => [], 'generico' => 'shampoo concentrado']],
            ]));
        });

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', $desc);
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $web['estado']);
        $this->assertSame('https://www.mercadolibre.cl/p/MLC88', $web['referencia']['url']);
        $this->assertSame(313, $web['referencia']['precio_clp']);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'products/search')
            && str_contains(mb_strtolower(urldecode($request->url())), 'automotriz'));
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->body(), 'google_search'));
    }

    public function test_mercado_libre_sin_catalogo_busca_publicacion_en_google(): void
    {
        config([
            'cotiz.mercadolibre.habilitado' => true,
            'cotiz.mercadolibre.client_id' => '7269705659698000',
            'cotiz.mercadolibre.client_secret' => 'secreto',
            'cotiz.mercadolibre.refresh_token' => '',
        ]);
        $desc = 'SHAMPOO AUTO CONCENTRADO';
        $nota = $this->crearNota();
        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'NOK-1',
            'prod_valor' => 0,
            'cantidad' => 200,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 0,
            'prod_item_agile' => 'MP1',
            'prod_descripcion_agile' => $desc,
            'prod_descripcion_maestro' => $desc,
        ]);
        $listado = $this->urlBusqueda('https://articulo.mercadolibre.cl/MLC-143-shampoo-automotriz');

        Http::fake(function (HttpRequest $request) use ($listado) {
            $url = $request->url();
            $prefijo = 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/';
            if (str_starts_with($url, $prefijo)) {
                return Http::response('', 302, [
                    'Location' => base64_decode(strtr(substr($url, strlen($prefijo)), '-_', '+/')),
                ]);
            }
            if (str_contains($url, 'api.mercadolibre.com/oauth/token')) {
                return Http::response(['access_token' => 'token-ml', 'expires_in' => 21600]);
            }
            if (str_contains($url, 'api.mercadolibre.com/products/search')) {
                return Http::response(['results' => []]);
            }
            if (str_contains($request->body(), 'google_search')) {
                return Http::response($this->respuestaGemini([
                    'resultados' => [[
                        'i' => 0,
                        'opciones' => [[
                            'sitio' => 'mercadolibre',
                            'titulo' => 'Shampoo Automotriz Pink Ph Neutro Profix 5 Lts',
                            'precio_clp' => 313,
                            'unidades_por_pack' => 1,
                            'url' => $listado,
                        ]],
                    ]],
                ]));
            }

            return Http::response($this->respuestaGemini([
                'resultados' => [['i' => 0, 'equivalentes' => [], 'busqueda' => []]],
            ]));
        });

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', $desc);
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $web['estado']);
        $this->assertSame('https://articulo.mercadolibre.cl/MLC-143-shampoo-automotriz', $web['referencia']['url']);
        $this->assertSame(313, $web['referencia']['precio_clp']);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->body(), 'google_search'));
    }

    public function test_mercado_libre_solicitud_de_caja_usa_costo_de_la_caja_completa(): void
    {
        config([
            'cotiz.mercadolibre.habilitado' => true,
            'cotiz.mercadolibre.client_id' => '7269705659698000',
            'cotiz.mercadolibre.client_secret' => 'secreto',
            'cotiz.mercadolibre.refresh_token' => '',
        ]);
        $desc = 'CORRECTOR CINTA LAPIZ RETRACTIL 5MM CAJA 12 UNIDADES TORRE';
        $nota = $this->crearNota();
        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'NOK-1',
            'prod_valor' => 0,
            'cantidad' => 5,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 0,
            'prod_item_agile' => 'MP1',
            'prod_descripcion_agile' => $desc,
            'prod_descripcion_maestro' => $desc,
        ]);

        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response(['access_token' => 'token-ml', 'expires_in' => 21600]),
            'api.mercadolibre.com/products/search*' => Http::response([
                'results' => [['id' => 'MLC12', 'name' => 'Corrector Cinta Lapiz Retractil 5mm Caja 12 Unidades Torre']],
            ]),
            'api.mercadolibre.com/products/MLC12/items' => Http::response(['results' => [['price' => 7560]]]),
            'generativelanguage.googleapis.com/*' => Http::response($this->respuestaGemini([
                'resultados' => [['i' => 0, 'equivalentes' => [], 'busqueda' => []]],
            ])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', $desc);
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $web['estado']);
        $this->assertSame(12, $web['referencia']['unidades_por_pack']);
        $this->assertSame(12, $web['referencia']['unidades_solicitud']);
        $this->assertSame(7560, $web['referencia']['neto_unitario']);
    }

    public function test_mercado_libre_usa_precio_premium_que_muestra_la_pagina_y_no_la_clasica_mas_barata(): void
    {
        config([
            'cotiz.mercadolibre.habilitado' => true,
            'cotiz.mercadolibre.client_id' => '7269705659698000',
            'cotiz.mercadolibre.client_secret' => 'secreto',
            'cotiz.mercadolibre.refresh_token' => '',
        ]);
        $desc = 'PASTILLA PARA ESTANQUE INODORO AZUL';
        $nota = $this->crearNota();
        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'NOK-1',
            'prod_valor' => 0,
            'cantidad' => 10,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 0,
            'prod_item_agile' => 'MP1',
            'prod_descripcion_agile' => $desc,
            'prod_descripcion_maestro' => $desc,
        ]);

        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response(['access_token' => 'token-ml', 'expires_in' => 21600]),
            'api.mercadolibre.com/products/search*' => Http::response([
                'results' => [
                    ['id' => 'MLC80', 'name' => 'Pastilla Para Estanque Inodoro Azul'],
                    ['id' => 'MLC81', 'name' => 'Pastilla Para Estanque Inodoro Azul Ambientador'],
                ],
            ]),
            'api.mercadolibre.com/products/MLC80/items' => Http::response(['results' => [
                ['item_id' => 'MLC1', 'price' => 2800, 'listing_type_id' => 'gold_pro'],
                ['item_id' => 'MLC2', 'price' => 2990, 'listing_type_id' => 'gold_pro'],
                ['item_id' => 'MLC3', 'price' => 2500, 'listing_type_id' => 'gold_special'],
            ]]),
            'api.mercadolibre.com/products/MLC81/items' => Http::response(['results' => [
                ['item_id' => 'MLC4', 'price' => 3100, 'listing_type_id' => 'gold_special'],
            ]]),
            'generativelanguage.googleapis.com/*' => Http::response($this->respuestaGemini([
                'resultados' => [['i' => 0, 'equivalentes' => [], 'busqueda' => []]],
            ])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', $desc);
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $web['estado']);
        $this->assertSame(2800, $web['referencia']['precio_clp']);
        $this->assertSame('https://www.mercadolibre.cl/p/MLC80', $web['referencia']['url']);
    }

    public function test_mercado_libre_toma_unidades_del_atributo_si_el_titulo_no_las_dice(): void
    {
        config([
            'cotiz.mercadolibre.habilitado' => true,
            'cotiz.mercadolibre.client_id' => '7269705659698000',
            'cotiz.mercadolibre.client_secret' => 'secreto',
            'cotiz.mercadolibre.refresh_token' => '',
        ]);
        $desc = 'PASTILLA PARA ESTANQUE INODORO';
        $nota = $this->crearNota();
        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'NOK-1',
            'prod_valor' => 0,
            'cantidad' => 10,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 0,
            'prod_item_agile' => 'MP1',
            'prod_descripcion_agile' => $desc,
            'prod_descripcion_maestro' => $desc,
        ]);

        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response(['access_token' => 'token-ml', 'expires_in' => 21600]),
            'api.mercadolibre.com/products/search*' => Http::response([
                'results' => [[
                    'id' => 'MLC90',
                    'name' => 'Pastilla Para Estanque 3x Excell',
                    'attributes' => [
                        ['id' => 'UNITS_PER_PACK', 'value_name' => '1'],
                        ['id' => 'UNITS_PER_PACKAGE', 'value_name' => '3'],
                    ],
                ]],
            ]),
            'api.mercadolibre.com/products/MLC90/items' => Http::response(['results' => [
                ['item_id' => 'MLC5', 'price' => 4250, 'listing_type_id' => 'gold_pro'],
            ]]),
            'generativelanguage.googleapis.com/*' => Http::response($this->respuestaGemini([
                'resultados' => [['i' => 0, 'equivalentes' => [], 'busqueda' => []]],
            ])),
        ]);

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', $desc);
        $this->assertSame(3, $web['referencia']['unidades_por_pack']);
        $this->assertSame(1417, $web['referencia']['neto_unitario']);
    }

    public function test_mercado_libre_descarta_publicaciones_que_la_ia_marca_como_otro_producto(): void
    {
        config([
            'cotiz.mercadolibre.habilitado' => true,
            'cotiz.mercadolibre.client_id' => '7269705659698000',
            'cotiz.mercadolibre.client_secret' => 'secreto',
            'cotiz.mercadolibre.refresh_token' => '',
        ]);
        $desc = 'CORRECTOR CINTA 5MM CAJA 12 UNIDADES TORRE';
        $nota = $this->crearNota();
        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'NOK-1',
            'prod_valor' => 0,
            'cantidad' => 1,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 0,
            'prod_item_agile' => 'MP1',
            'prod_descripcion_agile' => $desc,
            'prod_descripcion_maestro' => $desc,
        ]);

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if (str_contains($url, 'api.mercadolibre.com/oauth/token')) {
                return Http::response(['access_token' => 'token-ml', 'expires_in' => 21600]);
            }
            if (str_contains($url, 'api.mercadolibre.com/products/search')) {
                return Http::response(['results' => [
                    ['id' => 'MLC20', 'name' => 'Corrector Liquido Caja 12 Unidades'],
                    ['id' => 'MLC21', 'name' => 'Corrector Cinta 5mm Caja 12 Unidades'],
                ]]);
            }
            if (str_contains($url, 'api.mercadolibre.com/products/MLC20/items')) {
                return Http::response(['results' => [['price' => 3000]]]);
            }
            if (str_contains($url, 'api.mercadolibre.com/products/MLC21/items')) {
                return Http::response(['results' => [['price' => 6000]]]);
            }

            return Http::response($this->respuestaGemini(str_contains($request->body(), 'descartar')
                ? ['resultados' => [['i' => 0, 'descartar' => [0]]]]
                : ['resultados' => [['i' => 0, 'equivalentes' => [], 'busqueda' => [], 'generico' => 'corrector cinta 5 mm caja 12 unidades']]]));
        });

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $web = collect($preview['lineas'])->firstWhere('descripcion', $desc);
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $web['estado']);
        $this->assertSame('https://www.mercadolibre.cl/p/MLC21', $web['referencia']['url']);
    }

    public function test_medida_cercana_del_maestro_se_cotiza_con_aviso(): void
    {
        $nota = $this->notaConMedidaDistinta();
        Http::fake(fn (HttpRequest $request) => Http::response($this->respuestaGemini(str_contains($request->body(), 'descartar')
            ? ['resultados' => []]
            : ['resultados' => [['i' => 0, 'equivalentes' => ['TAMP65'], 'busqueda' => []]]])));

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $linea = collect($preview['lineas'])->firstWhere('descripcion', 'TAMPON DACTILAR 70 MM');
        $this->assertSame(CotizarIaService::ESTADO_VINCULADO, $linea['estado']);
        $this->assertSame('TAMP65', $linea['producto']['prod_item']);
        $this->assertStringContainsString('70 MM', (string) $linea['medida_nota']);
        $this->assertStringContainsString('65 MM', (string) $linea['medida_nota']);
    }

    public function test_medida_exacta_en_mercado_libre_reemplaza_al_maestro_aproximado(): void
    {
        config([
            'cotiz.mercadolibre.habilitado' => true,
            'cotiz.mercadolibre.client_id' => '7269705659698000',
            'cotiz.mercadolibre.client_secret' => 'secreto',
            'cotiz.mercadolibre.refresh_token' => '',
        ]);
        $nota = $this->notaConMedidaDistinta();
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if (str_contains($url, 'api.mercadolibre.com/oauth/token')) {
                return Http::response(['access_token' => 'token-ml', 'expires_in' => 21600]);
            }
            if (str_contains($url, 'api.mercadolibre.com/products/search')) {
                return Http::response(['results' => [
                    ['id' => 'MLC60', 'name' => 'Tampon Dactilar 60 mm Negro'],
                    ['id' => 'MLC70', 'name' => 'Tampon Dactilar 70 mm Negro'],
                ]]);
            }
            if (str_contains($url, 'api.mercadolibre.com/products/MLC60/items')) {
                return Http::response(['results' => [['price' => 500]]]);
            }
            if (str_contains($url, 'api.mercadolibre.com/products/MLC70/items')) {
                return Http::response(['results' => [['price' => 1100]]]);
            }

            return Http::response($this->respuestaGemini(str_contains($request->body(), 'descartar')
                ? ['resultados' => []]
                : ['resultados' => [['i' => 0, 'equivalentes' => ['TAMP65'], 'busqueda' => []]]]));
        });

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $linea = collect($preview['lineas'])->firstWhere('descripcion', 'TAMPON DACTILAR 70 MM');
        $this->assertSame(CotizarIaService::ESTADO_REFERENCIA_WEB, $linea['estado']);
        $this->assertSame('https://www.mercadolibre.cl/p/MLC70', $linea['referencia']['url']);
        $this->assertNull($linea['medida_nota']);
        $this->assertStringContainsString('TAMP65', (string) $linea['stock_nota']);
    }

    public function test_set_geometrico_vincula_set_reglas_del_maestro_y_no_mercado_libre(): void
    {
        config([
            'cotiz.mercadolibre.habilitado' => true,
            'cotiz.mercadolibre.client_id' => '7269705659698000',
            'cotiz.mercadolibre.client_secret' => 'secreto',
            'cotiz.mercadolibre.refresh_token' => '',
        ]);
        Maeprod::query()->create([
            'prod_item' => 'REGLHOL005',
            'prod_nombre' => 'SET REGLAS ACRILICAS 30CM 4 PCS',
            'prod_valor' => 1990,
            'prod_valor_costo' => 1200,
            'prod_familia' => 'LIBR',
        ]);
        $this->partialMock(MaeprodBusquedaSimilitudService::class, function ($mock) {
            $mock->shouldReceive('buscar')->andReturnUsing(function (string $term) {
                $t = mb_strtoupper($term);
                if (str_contains($t, 'GEOMETR') || str_contains($t, 'REGLA')) {
                    return Maeprod::query()->where('prod_item', 'REGLHOL005')->get();
                }

                return collect();
            });
        });

        $nota = $this->crearNota();
        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'NOK-1',
            'prod_valor' => 0,
            'cantidad' => 86,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 0,
            'prod_item_agile' => 'MP1',
            'prod_descripcion_agile' => 'SET GEOMETRICO GRANDE 30 CM 04 U',
            'prod_descripcion_maestro' => 'SET GEOMETRICO GRANDE 30 CM 04 U',
        ]);

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if (str_contains($url, 'api.mercadolibre.com/oauth/token')) {
                return Http::response(['access_token' => 'token-ml', 'expires_in' => 21600]);
            }
            if (str_contains($url, 'api.mercadolibre.com')) {
                return Http::response(['results' => [
                    ['id' => 'MLC30', 'name' => 'Set Geometrico Art and Craft 30 cm pack 4'],
                ]]);
            }

            return Http::response($this->respuestaGemini([
                'resultados' => [['i' => 0, 'equivalentes' => ['REGLHOL005'], 'busqueda' => []]],
            ]));
        });

        $preview = $this->actingAs($this->admin)
            ->postJson(route('admin.cotizaciones.cotizar-ia.preview', $nota->nronota))
            ->assertOk()
            ->json();

        $linea = collect($preview['lineas'])->firstWhere('descripcion', 'SET GEOMETRICO GRANDE 30 CM 04 U');
        $this->assertSame(CotizarIaService::ESTADO_VINCULADO, $linea['estado']);
        $this->assertSame(CotizarIaService::ORIGEN_IA, $linea['origen']);
        $this->assertSame('REGLHOL005', $linea['producto']['prod_item']);
        $this->assertNull($linea['referencia'] ?? null);
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'api.mercadolibre.com/products'));
    }

    private function notaConMedidaDistinta(): Nota
    {
        Maeprod::query()->create([
            'prod_item' => 'TAMP65',
            'prod_nombre' => 'TAMPON DACTILAR NEGRO 65 MM',
            'prod_valor' => 692,
            'prod_valor_costo' => 532,
            'prod_familia' => 'VARIOS',
        ]);
        $this->partialMock(MaeprodBusquedaSimilitudService::class, function ($mock) {
            $mock->shouldReceive('buscar')->andReturnUsing(fn (string $term) => str_contains(mb_strtoupper($term), 'TAMPON')
                ? Maeprod::query()->where('prod_item', 'TAMP65')->get()
                : collect());
        });

        $nota = $this->crearNota();
        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'NOK-1',
            'prod_valor' => 0,
            'cantidad' => 1,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 0,
            'prod_item_agile' => 'MP1',
            'prod_descripcion_agile' => 'TAMPON DACTILAR 70 MM',
            'prod_descripcion_maestro' => 'TAMPON DACTILAR 70 MM',
        ]);

        return $nota;
    }

    public function test_mercado_libre_lee_unidades_del_nombre_del_catalogo(): void
    {
        config([
            'cotiz.mercadolibre.habilitado' => true,
            'cotiz.mercadolibre.client_id' => '7269705659698000',
            'cotiz.mercadolibre.client_secret' => 'secreto',
            'cotiz.mercadolibre.refresh_token' => '',
        ]);
        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response(['access_token' => 'token-ml', 'expires_in' => 21600]),
            'api.mercadolibre.com/products/search*' => Http::response([
                'results' => [
                    ['id' => 'MLC1', 'name' => 'Tornillo Autoperforante Cabeza Lenteja 8 X 1 1000un'],
                    ['id' => 'MLC2', 'name' => 'Tornillo Autoperforante Zincado 6 X 1-5/8 - 520 Unidades'],
                    ['id' => 'MLC3', 'name' => 'Resma Papel Carta 500 Hojas'],
                ],
            ]),
            'api.mercadolibre.com/products/*/items' => Http::response(['results' => [['price' => 5000]]]),
        ]);

        $opciones = app(MercadoLibreApiService::class)->buscar('tornillo autoperforante');

        $this->assertSame([1000, 520, 1], array_column($opciones, 'unidades_por_pack'));
        $this->assertSame([null, null, null], array_column($opciones, 'stock_disponible'));
    }

    public function test_limpieza_borra_meses_antiguos_de_imagenes_de_mercado_libre(): void
    {
        config([
            'products.storage_disk' => 'r2',
            'products.r2_prefix' => 'productos',
            'filesystems.disks.r2.bucket' => 'bucket',
            'filesystems.disks.r2.key' => 'key',
            'filesystems.disks.r2.secret' => 'secret',
        ]);
        Storage::fake('r2');
        $this->travelTo(now()->setDate(2026, 9, 15));

        $disk = Storage::disk('r2');
        foreach (['2026/03', '2026/04', '2026/09', '2025/12'] as $mes) {
            $disk->put('productos/MERCADOLIBRE/'.$mes.'/MLC1.jpg', 'x');
        }
        $disk->put('productos/VARIOS/HIG001.jpg', 'x');

        $nota = $this->crearNotaConLineas();
        $antigua = NotaDetalle::query()->where('nronota', $nota->nronota)->orderBy('orden')->firstOrFail();
        NotaDetalle::query()->where('nronota', $nota->nronota)->where('orden', $antigua->orden)
            ->update(['imagen_ref' => 'MERCADOLIBRE/2026/03/MLC1.jpg']);
        NotaDetalle::query()->where('nronota', $nota->nronota)->where('orden', '>', $antigua->orden)
            ->update(['imagen_ref' => 'MERCADOLIBRE/2026/04/MLC1.jpg']);

        $borrados = app(ImagenReferenciaWebService::class)->limpiar(6);

        $this->assertSame(['2025/12', '2026/03'], collect($borrados)->sort()->values()->all());
        $disk->assertMissing('productos/MERCADOLIBRE/2026/03/MLC1.jpg');
        $disk->assertMissing('productos/MERCADOLIBRE/2025/12/MLC1.jpg');
        $disk->assertExists('productos/MERCADOLIBRE/2026/04/MLC1.jpg');
        $disk->assertExists('productos/MERCADOLIBRE/2026/09/MLC1.jpg');
        $disk->assertExists('productos/VARIOS/HIG001.jpg');
        $this->assertNull(NotaDetalle::query()->where('nronota', $nota->nronota)->where('orden', $antigua->orden)->value('imagen_ref'));
        $this->assertSame(0, NotaDetalle::query()->where('imagen_ref', 'like', 'MERCADOLIBRE/2026/03/%')->count());
        $this->assertGreaterThan(0, NotaDetalle::query()->where('imagen_ref', 'MERCADOLIBRE/2026/04/MLC1.jpg')->count());
    }

    public function test_callback_de_mercadolibre_guarda_el_refresh_token(): void
    {
        config([
            'cotiz.mercadolibre.habilitado' => true,
            'cotiz.mercadolibre.client_id' => '7269705659698000',
            'cotiz.mercadolibre.client_secret' => 'secreto',
            'cotiz.mercadolibre.redirect_uri' => 'https://cotiz.romulo.cl/admin/mercadolibre/callback',
        ]);
        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'token-ml',
                'refresh_token' => 'refresh-ml',
                'expires_in' => 21600,
            ]),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.mercadolibre.callback', ['code' => 'TG-codigo']))
            ->assertOk();

        $this->assertDatabaseHas('integracion_tokens', [
            'proveedor' => 'mercadolibre',
            'refresh_token' => 'refresh-ml',
        ]);

        Http::assertSent(function (HttpRequest $request) {
            $datos = $request->data();

            return str_contains($request->url(), 'oauth/token')
                && ($datos['grant_type'] ?? '') === 'authorization_code'
                && ($datos['code'] ?? '') === 'TG-codigo'
                && ($datos['redirect_uri'] ?? '') === 'https://cotiz.romulo.cl/admin/mercadolibre/callback';
        });
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
