<?php

namespace Tests\Feature;

use App\Models\AgileMaeprod;
use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
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

    public function test_preview_detecta_cabecera_y_matches(): void
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
        $this->assertGreaterThanOrEqual(1, $response->json('resumen.con_producto'));
    }

    public function test_importar_actualiza_cabecera_y_agrega_lineas(): void
    {
        $nota = $this->crearNota(['encargado' => '']);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-compra-agil', $nota->nronota),
            ['texto' => $this->textoMp],
        );

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertGreaterThanOrEqual(1, $response->json('agregadas'));

        $nota->refresh();
        $this->assertSame('1161-172-COT26', trim((string) $nota->encargado));
        $this->assertSame('SERVICIO AGRICOLA Y GANADERO', trim((string) $nota->empresa));
        $this->assertSame('61303000-7', trim((string) $nota->rutempresa));

        $lineas = NotaDetalle::query()->where('nronota', $nota->nronota)->orderBy('orden')->get();
        $this->assertGreaterThanOrEqual(1, $lineas->count());
        $this->assertNotNull($lineas->first()->prod_item_agile);
        $this->assertTrue(
            AgileMaeprod::query()->where('prod_item_agile', '31237835')->exists()
        );
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
