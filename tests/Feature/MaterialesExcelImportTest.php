<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use App\Services\ListadoMaterialesExcelParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class MaterialesExcelImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        config([
            'app.url' => 'http://localhost',
            'cotiz.sistema' => 'Cotiz',
        ]);

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        Maeprod::query()->create([
            'prod_item' => 'ART001',
            'prod_nombre' => 'ACUARELAS DE 12 COLORES',
            'prod_valor' => 3500,
            'prod_valor_costo' => 2800,
            'prod_familia' => 'ART',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_preview_excel_rechaza_sin_numero_cotizacion(): void
    {
        $nota = $this->crearNota(['encargado' => '']);
        $excel = UploadedFile::fake()->create('oferta.xlsx', 20, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-excel.preview', $nota->nronota),
            [
                'excel' => $excel,
                'columna_descripcion' => 'A',
                'columna_cantidad' => 'D',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Debe ingresar el número de cotización antes de continuar.');
    }

    public function test_preview_excel_detecta_lineas(): void
    {
        $nota = $this->crearNota();
        $excel = UploadedFile::fake()->create('oferta.xlsx', 20, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $mock = Mockery::mock(ListadoMaterialesExcelParserService::class);
        $mock->shouldReceive('parseDocumentoCompleto')
            ->once()
            ->andReturn([
                'cabecera' => [
                    'codigo_cotizacion' => '',
                    'empresa' => '',
                    'rutempresa' => '',
                    'nombre' => '',
                ],
                'lineas' => [
                    ['cantidad' => 25, 'descripcion' => 'ACUARELAS DE 12 COLORES C/U'],
                ],
                'omitidas' => 12,
            ]);
        $this->app->instance(ListadoMaterialesExcelParserService::class, $mock);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-excel.preview', $nota->nronota),
            [
                'excel' => $excel,
                'columna_descripcion' => 'A',
                'columna_cantidad' => 'D',
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('resumen.total', 1);
        $response->assertJsonPath('omitidas', 12);
        $this->assertStringStartsWith('xls:', $response->json('lineas.0.id_agile'));
        $this->assertSame('ACUARELAS DE 12 COLORES C/U', $response->json('lineas.0.descripcion'));
    }

    public function test_importar_excel_desde_preview(): void
    {
        $nota = $this->crearNota();

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-excel', $nota->nronota),
            [
                'desde' => 0,
                'hasta' => 1,
                'cabecera_json' => json_encode([
                    'codigo_cotizacion' => '',
                    'empresa' => '',
                    'rutempresa' => '',
                    'nombre' => '',
                ]),
                'lineas_json' => json_encode([
                    [
                        'id_agile' => 'xls:abc123',
                        'descripcion' => 'ACUARELAS DE 12 COLORES C/U',
                        'cantidad' => 25,
                        'categoria' => '',
                        'estado' => 'vinculado',
                        'es_sugerencia' => false,
                        'producto' => [
                            'prod_item' => 'ART001',
                            'prod_nombre' => 'ACUARELAS DE 12 COLORES',
                            'prod_valor' => 3500,
                            'prod_valor_costo' => 2800,
                        ],
                    ],
                ]),
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('agregadas', 1);

        $lineas = NotaDetalle::query()->where('nronota', $nota->nronota)->get();
        $this->assertCount(1, $lineas);
        $this->assertSame('ART001', $lineas->first()->prod_item);
        $this->assertSame(25, (int) $lineas->first()->cantidad);
    }

    private function crearNota(array $attrs = []): Nota
    {
        return Nota::query()->create(array_merge([
            'nronota' => 310,
            'usuario' => 'admin',
            'encargado' => '1161-999-COT26',
            'empresa' => 'Demo',
            'rutempresa' => '1-9',
            'estado' => 'ABIERTA',
        ], $attrs));
    }
}
