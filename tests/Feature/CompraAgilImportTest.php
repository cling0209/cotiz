<?php

namespace Tests\Feature;

use App\Models\AgileMaeprod;
use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use App\Services\NotaDetalleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $response->assertJsonPath('pendientes', 2);
        $response->assertJsonPath('vinculadas', 0);

        $nota->refresh();
        $this->assertSame('1161-172-COT26', trim((string) $nota->encargado));
        $this->assertSame('SERVICIO AGRICOLA Y GANADERO', trim((string) $nota->empresa));
        $this->assertSame('61303000-7', trim((string) $nota->rutempresa));

        $lineas = NotaDetalle::query()->where('nronota', $nota->nronota)->orderBy('orden')->get();
        $this->assertCount(2, $lineas);
        $this->assertTrue(NotaDetalleService::lineaPendienteVinculo($lineas->first()));
        $this->assertTrue(
            AgileMaeprod::query()->where('prod_item_agile', '31237835')->exists()
        );
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
        $response->assertJsonPath('vinculadas', 1);
        $response->assertJsonPath('pendientes', 1);

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
