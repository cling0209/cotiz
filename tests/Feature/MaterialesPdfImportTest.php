<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use App\Services\ListadoMaterialesPdfParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class MaterialesPdfImportTest extends TestCase
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

    public function test_preview_pdf_rechaza_sin_numero_cotizacion(): void
    {
        $nota = $this->crearNota(['encargado' => '']);
        $pdf = UploadedFile::fake()->create('listado.pdf', 20, 'application/pdf');

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-pdf.preview', $nota->nronota),
            ['pdf' => $pdf],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Debe ingresar el número de cotización antes de continuar.');
    }

    public function test_preview_pdf_detecta_lineas(): void
    {
        $nota = $this->crearNota();
        $pdf = UploadedFile::fake()->create('listado.pdf', 20, 'application/pdf');

        $mock = Mockery::mock(ListadoMaterialesPdfParserService::class);
        $mock->shouldReceive('parseUploadedFile')
            ->once()
            ->andReturn([
                ['cantidad' => 40, 'descripcion' => 'ACUARELAS DE 12 COLORES C/U'],
            ]);
        $this->app->instance(ListadoMaterialesPdfParserService::class, $mock);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-pdf.preview', $nota->nronota),
            ['pdf' => $pdf],
        );

        $response->assertOk();
        $response->assertJsonPath('resumen.total', 1);
        $this->assertStringStartsWith('pdf:', $response->json('lineas.0.id_agile'));
        $this->assertSame('ACUARELAS DE 12 COLORES C/U', $response->json('lineas.0.descripcion'));
    }

    public function test_importar_pdf_agrega_lineas(): void
    {
        $nota = $this->crearNota();
        $pdf = UploadedFile::fake()->create('listado.pdf', 20, 'application/pdf');

        $mock = Mockery::mock(ListadoMaterialesPdfParserService::class);
        $mock->shouldReceive('parseUploadedFile')
            ->once()
            ->andReturn([
                ['cantidad' => 40, 'descripcion' => 'ACUARELAS DE 12 COLORES C/U'],
            ]);
        $this->app->instance(ListadoMaterialesPdfParserService::class, $mock);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.cotizaciones.importar-pdf', $nota->nronota),
            ['pdf' => $pdf],
        );

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('agregadas', 1);

        $lineas = NotaDetalle::query()->where('nronota', $nota->nronota)->get();
        $this->assertCount(1, $lineas);
        $this->assertSame(40, (int) $lineas->first()->cantidad);
    }

    private function crearNota(array $attrs = []): Nota
    {
        return Nota::query()->create(array_merge([
            'nronota' => 300,
            'descripcion' => 'Test import PDF',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => '',
            'encargado' => '4322-366-COT26',
            'nota_softland' => 30000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ], $attrs));
    }
}
