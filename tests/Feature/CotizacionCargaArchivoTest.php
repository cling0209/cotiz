<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CotizacionCargaArchivoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        $this->user = User::factory()->create([
            'username' => 'ejecutivo1',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
    }

    public function test_muestra_formulario_carga_archivo(): void
    {
        $response = $this->actingAs($this->user)->get(route('admin.cotizaciones.carga-archivo.index'));

        $response->assertOk()
            ->assertSee('Carga de cotización desde archivo')
            ->assertSee('Previsualizar');
    }

    public function test_descarga_plantilla_csv(): void
    {
        $response = $this->actingAs($this->user)->get(route('admin.cotizaciones.carga-archivo.plantilla'));

        $response->assertOk();
        $this->assertStringContainsString('ORDEN DE COMPRA', $response->getContent());
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_previsualizar_y_confirmar_crea_cotizacion(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'PRODCSV01',
            'prod_nombre' => 'Producto CSV',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'prod_stock_real' => 10,
        ]);

        $csv = $this->csvEjemplo('OC-TEST-001', 'PRODCSV01');
        $archivo = UploadedFile::fake()->createWithContent('carga.csv', $csv);

        $preview = $this->actingAs($this->user)->post(
            route('admin.cotizaciones.carga-archivo.previsualizar'),
            ['archivo' => $archivo],
        );

        $preview->assertOk()
            ->assertSee('Previsualización')
            ->assertSee('PRODCSV01')
            ->assertSee('Producto CSV')
            ->assertSee('Confirmar carga');

        preg_match('/name="previewToken" value="([^"]+)"/', $preview->getContent(), $tokenMatch);
        preg_match('/name="previewPayload" value="([^"]+)"/', $preview->getContent(), $payloadMatch);

        $this->assertNotEmpty($tokenMatch[1] ?? '');
        $this->assertNotEmpty($payloadMatch[1] ?? '');

        $confirm = $this->actingAs($this->user)->post(
            route('admin.cotizaciones.carga-archivo.confirmar'),
            [
                'previewToken' => $tokenMatch[1],
                'previewPayload' => html_entity_decode($payloadMatch[1], ENT_QUOTES, 'UTF-8'),
            ],
        );

        $confirm->assertRedirect(route('admin.cotizaciones.carga-archivo.index'));

        $nota = Nota::query()->where('encargado', 'OC-TEST-001')->first();
        $this->assertNotNull($nota);
        $this->assertSame('ejecutivo1', $nota->usuario);
        $this->assertSame('CARGA_ARCHIVO', $nota->sistema);
        $this->assertSame('NOMBRE EMPRESA', $nota->empresa);

        $linea = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('prod_item', 'PRODCSV01')
            ->first();

        $this->assertNotNull($linea);
        $this->assertSame(2, (int) $linea->cantidad);
        $this->assertSame(1040, (int) $linea->prod_valor);
    }

    public function test_rechaza_orden_duplicada_de_otro_usuario(): void
    {
        Nota::query()->create([
            'nronota' => 9001,
            'descripcion' => 'Existente',
            'fecha' => now()->toDateString(),
            'usuario' => 'otro_user',
            'encargado' => 'OC-DUP-001',
            'empresa' => 'Empresa X',
            'nota_softland' => 1,
            'notaorigen' => 0,
            'enviadoapi' => 0,
            'diashabiles' => 2,
            'factor_precio_venta' => 1.22,
        ]);

        $csv = $this->csvEjemplo('OC-DUP-001', 'CODIGO');
        $archivo = UploadedFile::fake()->createWithContent('carga.csv', $csv);

        $response = $this->actingAs($this->user)->post(
            route('admin.cotizaciones.carga-archivo.previsualizar'),
            ['archivo' => $archivo],
        );

        $response->assertRedirect(route('admin.cotizaciones.carga-archivo.index'));
        $response->assertSessionHas('error');
    }

    private function csvEjemplo(string $orden, string $codigoProducto): string
    {
        return "ORDEN DE COMPRA;RUT o CODIGO CLIENTE;NOMBRE DEL CLIENTE;CONTACTO;FECHA;DIAS DE ENTREGA;FACTOR;Codigo Producto;cantidad\n"
            ."{$orden};99999999-9;NOMBRE EMPRESA;CONTACTO TEST;10/10/2025;7;1,3;{$codigoProducto};2\n";
    }
}
