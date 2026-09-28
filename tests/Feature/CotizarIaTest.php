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
use App\Services\OportunidadVinculoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
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
        ]);

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        foreach ([
            ['ARTE001', 'LAPIZ AZUL ESCOLAR 12 UNIDADES', 3500, 2800],
            ['PAPEL001', 'GREDAS ESCOLARES 1 KG', 1200, 900],
            ['HIG001', 'PAPEL HIGIENICO HOJA DOBLE 50 MTS', 1300, 900],
            ['HIG002', 'PAPEL HIGIENICO HOJA DOBLE 50 MTS ECONOMICO', 1000, 700],
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
            $mock->shouldReceive('buscar')->andReturnUsing(
                fn (string $term) => str_contains(mb_strtoupper($term), 'HIGIENICO')
                    ? Maeprod::query()->whereIn('prod_item', ['HIG001', 'HIG002'])->get()
                    : collect(),
            );
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
                            ['sitio' => 'otro', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada', 'precio_clp' => 50, 'unidades_por_pack' => 1, 'url' => 'https://www.falabella.com/tornillo'],
                            ['sitio' => 'sodimac', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada', 'precio_clp' => 2380, 'unidades_por_pack' => 1, 'url' => 'https://www.sodimac.cl/sodimac-cl/product/123'],
                            ['sitio' => 'mercadolibre', 'titulo' => 'Tornillo autoperforante 8 x 1 pulgada pack', 'precio_clp' => 11900, 'unidades_por_pack' => 100, 'url' => 'https://articulo.mercadolibre.cl/MLC-123-tornillo'],
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
        $this->assertNull($detalle[self::DESC_IA]->observacion);

        $hash = app(AgileVinculoAprendizajeService::class)->hashDescripcion(self::DESC_IA);
        $aprendido = AgileMaeprod::query()->where('descripcion_norm_hash', $hash)->first();
        $this->assertNotNull($aprendido);
        $this->assertSame('HIG002', $aprendido->prod_item);
        $this->assertSame(VinculoOrigen::IA->value, $aprendido->vinculado_origen);
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
