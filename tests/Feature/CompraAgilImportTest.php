<?php

namespace Tests\Feature;

use App\Models\AgileMaeprod;
use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use App\Services\NotaDetalleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompraAgilImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $textoMp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        config([
            'app.url' => 'http://localhost',
            'cotiz.sistema' => 'Cotiz',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
        ]);

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        Maeprod::query()->create([
            'prod_item' => 'ASEO001',
            'prod_nombre' => 'LIMPIADOR DE PISOS CON AROMAS 5 LTS',
            'prod_valor' => 4500,
            'prod_valor_costo' => 3200,
            'prod_familia' => 'ASEO',
        ]);

        Maeprod::query()->create([
            'prod_item' => 'ASEO002',
            'prod_nombre' => 'LIMPIADOR PISO FLOTANTE 1 LITRO',
            'prod_valor' => 2800,
            'prod_valor_costo' => 2100,
            'prod_familia' => 'ASEO',
        ]);

        $this->textoMp = <<<'TXT'
Detalle de la cotización 1161-172-COT26
Nombre
COMPRA DE MATERIALES DE ASEO
SERVICIO AGRICOLA Y GANADERO
RUT 61.303.000-7
Limpiadores de uso general ID: 31237835
LIMPIADOR DE PISOS CON AROMAS 5 LTS. CADA UNO
5 Litro
Limpiadores de uso general ID: 31237836
LIMPIADOR PISO FLOTANTE
24 Litro
TXT;
    }

    public function test_preview_detecta_cabecera_y_sugerencias(): void
    {
        $nota = $this->crearNota();

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil.preview', $nota->nronota),
            ['texto' => $this->textoMp],
        );

        $response->assertOk();
        $response->assertJsonPath('cabecera.codigo_cotizacion', '1161-172-COT26');
        $response->assertJsonPath('cabecera.empresa', 'SERVICIO AGRICOLA Y GANADERO');
        $response->assertJsonPath('cabecera.rutempresa', '61303000-7');
        $response->assertJsonPath('resumen.total', 2);
        $response->assertJsonPath('resumen.pendientes', 2);
        $this->assertGreaterThanOrEqual(1, $response->json('resumen.con_sugerencia'));
    }

    public function test_importar_actualiza_cabecera_y_agrega_lineas_pendientes(): void
    {
        $nota = $this->crearNota(['encargado' => '']);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil', $nota->nronota),
            ['texto' => $this->textoMp],
        );

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('agregadas', 2);
        $response->assertJsonPath('vinculadas', 2);
        $response->assertJsonPath('pendientes', 0);

        $nota->refresh();
        $this->assertSame('1161-172-COT26', trim((string) $nota->encargado));
        $this->assertSame('SERVICIO AGRICOLA Y GANADERO', trim((string) $nota->empresa));
        $this->assertSame('61303000-7', trim((string) $nota->rutempresa));

        $lineas = NotaDetalle::query()->where('nronota', $nota->nronota)->orderBy('orden')->get();
        $this->assertCount(2, $lineas);
        $this->assertFalse(NotaDetalleService::lineaPendienteVinculo($lineas->first()));
        $this->assertSame('ASEO001', $lineas->first()->prod_item);
        $this->assertSame('ASEO002', $lineas->get(1)->prod_item);
        $this->assertTrue(
            AgileMaeprod::query()->where('prod_item_agile', '31237835')->exists()
        );
    }

    public function test_preview_prioriza_vinculo_agilemaeprod_sobre_similitud(): void
    {
        AgileMaeprod::query()->create([
            'prod_item_agile' => '31237835',
            'prod_descripcion_agile' => 'OTRO PRODUCTO',
            'prod_item' => 'ASEO002',
        ]);

        $nota = $this->crearNota();

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil.preview', $nota->nronota),
            ['texto' => $this->textoMp],
        );

        $response->assertOk();
        $lineaVinculada = collect($response->json('lineas'))
            ->firstWhere('id_agile', '31237835');

        $this->assertNotNull($lineaVinculada);
        $this->assertSame('vinculado', $lineaVinculada['estado']);
        $this->assertFalse($lineaVinculada['es_sugerencia']);
        $this->assertSame('ASEO002', $lineaVinculada['producto']['prod_item']);
    }

    public function test_preview_propone_similitud_si_no_hay_vinculo_agilemaeprod(): void
    {
        $nota = $this->crearNota();

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil.preview', $nota->nronota),
            ['texto' => $this->textoMp],
        );

        $response->assertOk();
        $linea = collect($response->json('lineas'))->firstWhere('id_agile', '31237835');

        $this->assertNotNull($linea);
        $this->assertSame('pendiente', $linea['estado']);
        $this->assertTrue($linea['es_sugerencia']);
        $this->assertSame('ASEO001', $linea['producto']['prod_item']);
    }

    public function test_importar_vincula_lineas_con_maestro_previo(): void
    {
        AgileMaeprod::query()->create([
            'prod_item_agile' => '31237835',
            'prod_descripcion_agile' => 'LIMPIADOR DE PISOS',
            'prod_item' => 'ASEO001',
        ]);

        $nota = $this->crearNota(['encargado' => '']);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil', $nota->nronota),
            ['texto' => $this->textoMp],
        );

        $response->assertOk();
        $response->assertJsonPath('vinculadas', 2);
        $response->assertJsonPath('pendientes', 0);

        $vinculada = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('prod_item_agile', '31237835')
            ->first();

        $this->assertSame('ASEO001', $vinculada->prod_item);
        $this->assertFalse(NotaDetalleService::lineaPendienteVinculo($vinculada));
    }

    public function test_vincular_linea_agile_asigna_producto_maestro(): void
    {
        $nota = $this->crearNota();

        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => '31237835',
            'prod_valor' => 0,
            'cantidad' => 5,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 0,
            'prod_item_agile' => '31237835',
            'prod_descripcion_agile' => 'LIMPIADOR DE PISOS CON AROMAS 5 LTS',
        ]);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.lineas.vincular-agile', $nota->nronota),
            [
                'orden' => 1,
                'prod_item_agile' => '31237835',
                'prod_item' => 'ASEO001',
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('linea.prod_item', 'ASEO001');

        $linea = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('orden', 1)
            ->where('prod_item', 'ASEO001')
            ->first();

        $this->assertNotNull($linea);
        $this->assertFalse(NotaDetalleService::lineaPendienteVinculo($linea));
        $this->assertSame('ASEO001', AgileMaeprod::query()->find('31237835')->prod_item);
    }

    public function test_importar_rechaza_sin_numero_cotizacion_si_texto_no_lo_trae(): void
    {
        $nota = $this->crearNota(['encargado' => '']);

        $texto = <<<'TXT'
SERVICIO AGRICOLA Y GANADERO
RUT 61.303.000-7
Limpiadores ID: 31237835
LIMPIADOR DE PISOS CON AROMAS 5 LTS
5 Litro
TXT;

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil', $nota->nronota),
            ['texto' => $texto],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Debe ingresar el número de cotización antes de continuar, o pegar un texto que lo incluya.');
    }

    public function test_preview_advierte_cotizacion_duplicada(): void
    {
        $this->crearNota([
            'nronota' => 2,
            'encargado' => '1161-172-COT26',
        ]);

        $nota = $this->crearNota(['encargado' => '']);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil.preview', $nota->nronota),
            ['texto' => $this->textoMp],
        );

        $response->assertOk();
        $response->assertJsonPath('puede_importar', false);
        $response->assertJsonPath('error_cabecera', 'La cotización «1161-172-COT26» ya existe (nota #2). No se puede duplicar.');
        $response->assertJsonPath('resumen.total', 2);
    }

    public function test_importar_por_lotes_agrega_todas_las_lineas(): void
    {
        $nota = $this->crearNota(['encargado' => '']);

        $response1 = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil', $nota->nronota),
            ['texto' => $this->textoMp, 'desde' => 0, 'hasta' => 1],
        );
        $response1->assertOk();
        $response1->assertJsonPath('procesadas', 1);
        $response1->assertJsonPath('completado', false);

        $response2 = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil', $nota->nronota),
            ['texto' => $this->textoMp, 'desde' => 1, 'hasta' => 2],
        );
        $response2->assertOk();
        $response2->assertJsonPath('completado', true);

        $this->assertSame(2, NotaDetalle::query()->where('nronota', $nota->nronota)->count());
    }

    public function test_preview_por_lotes_analiza_todas_las_lineas(): void
    {
        $nota = $this->crearNota();

        $response1 = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil.preview', $nota->nronota),
            ['texto' => $this->textoMp, 'desde' => 0, 'hasta' => 1],
        );

        $response1->assertOk();
        $response1->assertJsonPath('total', 2);
        $response1->assertJsonPath('completado', false);
        $response1->assertJsonCount(1, 'lineas');

        $response2 = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil.preview', $nota->nronota),
            ['texto' => $this->textoMp, 'desde' => 1, 'hasta' => 2],
        );

        $response2->assertOk();
        $response2->assertJsonPath('completado', true);
        $response2->assertJsonCount(1, 'lineas');
    }

    public function test_preview_texto_rechaza_duplicado_local(): void
    {
        Nota::query()->create([
            'nronota' => 300,
            'descripcion' => 'Cotización existente',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => '',
            'encargado' => '1161-172-COT26',
            'nota_softland' => 30000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        $nota = $this->crearNota(['encargado' => '']);

        $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil.preview', $nota->nronota),
            ['texto' => $this->textoMp],
        )
            ->assertOk()
            ->assertJsonPath('puede_importar', false)
            ->assertJsonPath('error_cabecera', fn ($msg) => str_contains($msg, '1161-172-COT26'));
    }

    public function test_preview_texto_rechaza_duplicado_en_par(): void
    {
        config([
            'app.url' => 'https://cotiza.romulo.cl',
            'cotiz.sistema' => 'Romulo',
            'cotiz.api_nota.consulta_nro_cotizacion' => 'https://cotiza.reicol.cl/api/v1/nota-consulta',
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
        ]);

        Http::fake([
            'cotiza.reicol.cl/*' => Http::response([
                'resultado' => 'OK',
                'nronota' => 99,
            ], 200),
        ]);

        $nota = $this->crearNota(['encargado' => '']);

        $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil.preview', $nota->nronota),
            ['texto' => $this->textoMp],
        )
            ->assertOk()
            ->assertJsonPath('puede_importar', false)
            ->assertJsonPath('error_cabecera', fn ($msg) => str_contains($msg, '2686-279-COT26'));
    }

    public function test_importar_texto_rechaza_duplicado_en_par(): void
    {
        config([
            'app.url' => 'https://cotiza.romulo.cl',
            'cotiz.sistema' => 'Romulo',
            'cotiz.api_nota.consulta_nro_cotizacion' => 'https://cotiza.reicol.cl/api/v1/nota-consulta',
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
        ]);

        Http::fake([
            'cotiza.reicol.cl/*' => Http::response([
                'resultado' => 'OK',
                'nronota' => 99,
            ], 200),
        ]);

        $nota = $this->crearNota(['encargado' => '']);

        $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil', $nota->nronota),
            ['texto' => $this->textoMp],
        )
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($msg) => str_contains($msg, '2686-279-COT26'));
    }

    public function test_limpiar_elimina_todas_las_lineas_agile_al_reanalizar(): void
    {
        $nota = $this->crearNota();

        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => '31237835',
            'prod_valor' => 0,
            'cantidad' => 5,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 0,
            'prod_item_agile' => '31237835',
            'prod_descripcion_agile' => 'LIMPIADOR DE PISOS',
        ]);

        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'DEMO001',
            'prod_valor' => 1000,
            'cantidad' => 2,
            'fechahora' => now(),
            'orden' => 2,
            'prod_valor_costo' => 800,
            'prod_item_agile' => null,
        ]);

        $coincidencias = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil.coincidencias', $nota->nronota),
            ['texto' => $this->textoMp],
        );
        $coincidencias->assertOk();
        $coincidencias->assertJsonPath('con_agile', 1);
        $coincidencias->assertJsonPath('detalle.total', 2);
        $coincidencias->assertJsonPath('detalle.sin_agile', 1);

        $limpiar = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil.limpiar-agile', $nota->nronota),
        );
        $limpiar->assertOk();
        $limpiar->assertJsonPath('eliminadas', 1);
        $limpiar->assertJsonPath('detalle.total', 1);
        $limpiar->assertJsonPath('detalle.sin_agile', 1);
        $limpiar->assertJsonPath('detalle.con_agile', 0);

        $this->assertSame(1, NotaDetalle::query()->where('nronota', $nota->nronota)->count());
        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nota->nronota,
            'prod_item' => 'DEMO001',
            'orden' => 1,
        ]);
    }

    public function test_importar_guarda_descripcion_agile_en_linea(): void
    {
        $nota = $this->crearNota(['encargado' => '']);

        $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil', $nota->nronota),
            ['texto' => $this->textoMp],
        )->assertOk();

        $linea = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('prod_item_agile', '31237835')
            ->first();

        $this->assertNotNull($linea);
        $this->assertNotSame('', trim((string) $linea->prod_descripcion_agile));
        $this->assertStringContainsString('LIMPIADOR', (string) $linea->prod_descripcion_agile);
    }

    public function test_grabar_lineas_sincroniza_vinculo_agilemaeprod(): void
    {
        $nota = $this->crearNota();

        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'ASEO001',
            'prod_valor' => 4500,
            'cantidad' => 5,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 3200,
            'prod_item_agile' => '31237835',
            'prod_descripcion_agile' => 'LIMPIADOR DE PISOS CON AROMAS 5 LTS',
        ]);

        $this->assertFalse(
            AgileMaeprod::query()
                ->where('prod_item_agile', '31237835')
                ->where('prod_item', 'ASEO001')
                ->exists()
        );

        $response = $this->actingAs($this->admin)->post(
            route('admin.cotizaciones.update', $nota->nronota),
            [
                'accion' => 'grabar',
                'descripcion' => $nota->descripcion,
                'encargado' => $nota->encargado,
                'empresa' => $nota->empresa,
                'celular' => '',
                'contacto' => '',
                'contactocorreo' => '',
                'rutempresa' => '',
                'diashabiles' => 2,
                'ocompra' => '',
                'lineas' => [
                    [
                        'prod_item' => 'ASEO001',
                        'orden' => 1,
                        'cantidad' => 5,
                        'prod_valor' => 4600,
                        'prod_valor_costo' => 3200,
                    ],
                ],
            ],
        );

        $response->assertRedirect();
        $this->assertSame('ASEO001', AgileMaeprod::query()->find('31237835')->prod_item);
    }

    private function crearNota(array $attrs = []): Nota
    {
        return Nota::query()->create(array_merge([
            'nronota' => 200,
            'descripcion' => 'Test import MP',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => '',
            'encargado' => 'COT-IMPORT-001',
            'nota_softland' => 20000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ], $attrs));
    }
}
