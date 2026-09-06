<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use Database\Seeders\FamprodSeeder;
use Database\Seeders\GramajeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaeprodListadoFiltrosTest extends TestCase
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
            'prod_item' => 'FILT01',
            'prod_nombre' => 'PRODUCTO FILTRADO',
            'prod_valor' => 1000,
            'prod_familia' => 'PAPEL',
            'prod_gramaje' => 'unidad',
        ]);
    }

    public function test_editar_propaga_filtros_en_enlace_al_listado(): void
    {
        $url = route('admin.productos.index', [
            'q' => 'filtrado',
            'familia' => 'PAPEL',
            'page' => 2,
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.edit', [
                'prod_item' => 'FILT01',
                'q' => 'filtrado',
                'familia' => 'PAPEL',
                'page' => 2,
            ]))
            ->assertOk()
            ->assertSee(e($url), false);
    }

    public function test_listado_enlace_editar_incluye_filtros(): void
    {
        $url = route('admin.productos.edit', [
            'prod_item' => 'FILT01',
            'q' => 'filtrado',
            'familia' => 'PAPEL',
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.index', [
                'q' => 'filtrado',
                'familia' => 'PAPEL',
            ]))
            ->assertOk()
            ->assertSee(e($url), false);
    }

    public function test_listado_muestra_ultimo_uso_con_enlace_a_cotizacion(): void
    {
        Nota::query()->create([
            'nronota' => 200,
            'descripcion' => 'Uso antiguo',
            'fecha' => '2026-01-10',
            'usuario' => 'superadmin',
            'empresa' => 'Cliente',
            'encargado' => 'COT-200',
            'nota_softland' => 20000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        Nota::query()->create([
            'nronota' => 201,
            'descripcion' => 'Uso reciente',
            'fecha' => '2026-03-15',
            'usuario' => 'superadmin',
            'empresa' => 'Cliente',
            'encargado' => 'COT-201',
            'nota_softland' => 20100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaDetalle::query()->create([
            'nronota' => 200,
            'prod_item' => 'FILT01',
            'orden' => 1,
            'cantidad' => 1,
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'fechahora' => '2026-01-10 10:00:00',
        ]);

        NotaDetalle::query()->create([
            'nronota' => 201,
            'prod_item' => 'FILT01',
            'orden' => 1,
            'cantidad' => 2,
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'fechahora' => '2026-03-15 18:30:00',
        ]);

        $detalleUrl = route('admin.cotizaciones.edit', 201);

        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.index', ['q' => 'FILT01']))
            ->assertOk()
            ->assertSee('&Uacute;ltimo uso', false)
            ->assertSee('15/03/2026', false)
            ->assertSee('#201', false)
            ->assertSee(e($detalleUrl), false)
            ->assertDontSee('#200', false);
    }

    public function test_export_excel_incluye_ultimo_uso_y_respeta_filtro(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'OTRO99',
            'prod_nombre' => 'OTRO PRODUCTO',
            'prod_valor' => 500,
            'prod_familia' => 'OTROS',
            'prod_gramaje' => 'unidad',
        ]);

        Nota::query()->create([
            'nronota' => 301,
            'descripcion' => 'Uso export',
            'fecha' => '2026-04-01',
            'usuario' => 'superadmin',
            'empresa' => 'Cliente',
            'encargado' => 'COT-301',
            'nota_softland' => 30100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaDetalle::query()->create([
            'nronota' => 301,
            'prod_item' => 'FILT01',
            'orden' => 1,
            'cantidad' => 1,
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'fechahora' => '2026-04-01 12:00:00',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get(route('admin.productos.export.excel', [
                'q' => 'FILT01',
                'familia' => 'PAPEL',
            ]));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString(
            '.xlsx',
            (string) $response->headers->get('content-disposition'),
        );

        $tmp = tempnam(sys_get_temp_dir(), 'maeprod_xlsx_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $response->streamedContent());

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);

        $this->assertSame('codigo', (string) $sheet->getCell('A1')->getValue());
        $this->assertSame('ultimo_uso_fecha', (string) $sheet->getCell('I1')->getValue());
        $this->assertSame('ultimo_uso_nronota', (string) $sheet->getCell('J1')->getValue());
        $this->assertSame('FILT01', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('01/04/2026', (string) $sheet->getCell('I2')->getValue());
        $this->assertSame('301', (string) $sheet->getCell('J2')->getValue());
        $this->assertSame('', trim((string) $sheet->getCell('A3')->getValue()));
    }

    public function test_actualizar_producto_conserva_filtros_en_redirect(): void
    {
        $this->actingAs($this->superadmin)
            ->put(route('admin.productos.update', 'FILT01'), [
                'prod_nombre' => 'PRODUCTO FILTRADO ACT',
                'prod_familia' => 'PAPEL',
                'prod_gramaje' => 'unidad',
                'prod_valor' => 1200,
                'q' => 'filtrado',
                'familia' => 'PAPEL',
                'page' => 2,
            ])
            ->assertRedirect(route('admin.productos.edit', [
                'prod_item' => 'FILT01',
                'q' => 'filtrado',
                'familia' => 'PAPEL',
                'page' => 2,
            ]));
    }
}
