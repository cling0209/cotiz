<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdjudicadaListadoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ejecutivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->ejecutivo = User::factory()->create([
            'username' => 'ejecutivo',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
    }

    public function test_superadmin_ve_solo_cotizaciones_aceptadas(): void
    {
        $this->crearNota(['nronota' => 101, 'estado' => 'aceptada', 'fechaentrega' => '2026-06-15', 'encargado' => 'COT-101']);
        $this->crearNota(['nronota' => 102, 'estado' => '', 'fechaentrega' => '2026-06-15', 'encargado' => 'COT-102']);

        $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.adjudicadas.index'))
            ->assertOk()
            ->assertSee('101')
            ->assertDontSee('102');
    }

    public function test_filtra_por_fecha_entrega(): void
    {
        $this->crearNota(['nronota' => 201, 'estado' => 'aceptada', 'fechaentrega' => '2026-06-10', 'empresa' => 'Empresa Filtro 201']);
        $this->crearNota(['nronota' => 202, 'estado' => 'aceptada', 'fechaentrega' => '2026-06-20', 'empresa' => 'Empresa Filtro 202']);

        $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.adjudicadas.index', [
                'fechaentregadesde' => '2026-06-01',
                'fechaentregahasta' => '2026-06-15',
            ]))
            ->assertOk()
            ->assertSee('Empresa Filtro 201')
            ->assertDontSee('Empresa Filtro 202');
    }

    public function test_filtra_por_ejecutivo(): void
    {
        User::factory()->create([
            'username' => 'otro_ej',
            'nombre' => 'Otro',
            'apellidop' => 'Ejecutivo',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $this->crearNota([
            'nronota' => 211,
            'estado' => 'aceptada',
            'usuario' => 'ejecutivo',
            'empresa' => 'Empresa De Ejecutivo',
            'fechaentrega' => '2026-06-10',
        ]);
        $this->crearNota([
            'nronota' => 212,
            'estado' => 'aceptada',
            'usuario' => 'otro_ej',
            'empresa' => 'Empresa De Otro',
            'fechaentrega' => '2026-06-10',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.adjudicadas.index', [
                'usuario' => 'ejecutivo',
            ]))
            ->assertOk()
            ->assertSee('Empresa De Ejecutivo')
            ->assertDontSee('Empresa De Otro')
            ->assertSee('name="usuario"', false);
    }

    public function test_rechaza_filtro_fecha_incompleto(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.adjudicadas.index', [
                'fechaentregadesde' => '2026-06-01',
            ]))
            ->assertRedirect(route('admin.cotizaciones.adjudicadas.index'))
            ->assertSessionHas('error');
    }

    public function test_ejecutivo_no_accede_al_listado(): void
    {
        $this->actingAs($this->ejecutivo)
            ->get(route('admin.cotizaciones.adjudicadas.index'))
            ->assertForbidden();
    }

    public function test_export_detalle_incluye_lineas(): void
    {
        $nota = $this->crearNota([
            'nronota' => 301,
            'estado' => 'aceptada',
            'fechaentrega' => '2026-06-12',
            'encargado' => 'COT-301',
        ]);

        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD301',
            'prod_valor' => 1000,
            'cantidad' => 2,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 800,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.adjudicadas.export.detalle', ['nronota' => 301]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('PROD301', $response->streamedContent());
    }

    public function test_ver_cotizacion_desde_adjudicadas_oculta_controles_edicion(): void
    {
        $nota = $this->crearNota([
            'nronota' => 401,
            'estado' => 'aceptada',
            'fechaentrega' => '2026-06-12',
            'encargado' => 'COT-401',
        ]);

        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD401',
            'prod_valor' => 1500,
            'cantidad' => 1,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 1000,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.edit', ['nronota' => 401, 'from' => 'adjudicadas']))
            ->assertOk()
            ->assertSee('Cotizaciones adjudicadas', false)
            ->assertDontSee('id="btn-abrir-importar-compra-agil"', false)
            ->assertDontSee('id="btn-abrir-buscar-producto"', false)
            ->assertDontSee('id="btnFactorAumentoAceptar"', false)
            ->assertDontSee('id="btnCopiarMP"', false)
            ->assertDontSee('>Eliminar</th>', false)
            ->assertDontSee('Descargar Archivo', false)
            ->assertDontSee('Descargar Gu&iacute;a', false)
            ->assertDontSee('Descargar Gu&iacute;a Ingreso', false)
            ->assertSee('Factor de aumento:', false)
            ->assertSee('Descargar PDF', false)
            ->assertSee('Descargar Excel', false);
    }

    private function crearNota(array $attrs = []): Nota
    {
        return Nota::query()->create(array_merge([
            'nronota' => 100,
            'descripcion' => 'Test adjudicada',
            'fecha' => now()->toDateString(),
            'usuario' => 'ejecutivo',
            'empresa' => 'Cliente Test',
            'encargado' => 'COT-TEST',
            'nota_softland' => 10000,
            'enviadoapi' => 0,
        ], $attrs));
    }
}
