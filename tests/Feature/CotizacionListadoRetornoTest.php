<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\OportunidadEncontrada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CotizacionListadoRetornoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
    }

    public function test_nueva_desde_oportunidades_vuelve_a_oportunidades(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.create', [
                'codigo' => '1000-1-COT26',
                'from' => 'oportunidades',
                'op_page' => 3,
                'op_region' => '13',
                'op_organismo' => 'Ministerio',
            ]))
            ->assertOk()
            ->assertSee('&larr; Oportunidades', false)
            ->getContent();

        $this->assertStringContainsString('op_page=3', $html);
        $this->assertStringContainsString('op_region=13', $html);
        $this->assertStringContainsString('op_organismo=Ministerio', $html);
        $this->assertStringContainsString('name="from"', $html);
        $this->assertStringContainsString('name="op_page"', $html);
        $this->assertMatchesRegularExpression('/id="encargado"[^>]*\breadonly\b/i', $html);
        $this->assertMatchesRegularExpression('/id="ca-api-codigo"[^>]*\breadonly\b/i', $html);
        $this->assertStringContainsString('value="1000-1-COT26"', $html);
        $this->assertStringContainsString('id="wrap-ca-buscar-codigo"', $html);
        $this->assertStringNotContainsString('col-md-auto d-none', $html);
    }

    public function test_nueva_desde_oportunidad_vinculada_oculta_cargar(): void
    {
        OportunidadEncontrada::query()->create([
            'codigo' => '1000-2-COT26',
            'nombre' => 'Con vinculo',
            'fecha_busqueda' => now()->toDateString(),
            'palabras_coinciden' => ['aseo'],
            'indice_region_config' => 0,
            'vinculo_completo' => true,
            'vinculo_preview_json' => [
                'cabecera' => ['codigo_cotizacion' => '1000-2-COT26'],
                'lineas' => [
                    [
                        'id_agile' => '1',
                        'descripcion' => 'Producto demo',
                        'cantidad' => 1,
                        'estado' => 'vinculado',
                    ],
                ],
                'resumen' => ['total' => 1, 'vinculados' => 1, 'pendientes' => 0],
            ],
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.create', [
                'codigo' => '1000-2-COT26',
                'from' => 'oportunidades',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('col-md-auto d-none', $html);
        $this->assertMatchesRegularExpression('/id="encargado"[^>]*\breadonly\b/i', $html);
        $this->assertStringContainsString('value="1000-2-COT26"', $html);
    }

    public function test_ver_desde_listado_conserva_filtros_y_pagina(): void
    {
        $nota = $this->crearNota(501);

        $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.edit', [
                'nronota' => $nota->nronota,
                'from' => 'listado',
                'fechadesde' => '2026-01-15',
                'fechahasta' => '2026-08-15',
                'page' => 4,
            ]))
            ->assertOk()
            ->assertSee('fechadesde=2026-01-15', false)
            ->assertSee('page=4', false)
            ->assertSee('&larr; Listado', false);
    }

    public function test_listado_ver_incluye_from_y_pagina(): void
    {
        $this->crearNota(502);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.index', [
                'nronota' => 502,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('from=listado', $html);
        $this->assertStringContainsString('buscar_nronota=502', $html);
    }

    public function test_adjudicadas_ver_incluye_filtros(): void
    {
        $this->crearNota(503, ['estado' => 'aceptada', 'fechaentrega' => '2026-06-10']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.adjudicadas.index', [
                'fechaentregadesde' => '2026-06-01',
                'fechaentregahasta' => '2026-06-30',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('from=adjudicadas', $html);
        $this->assertStringContainsString('fechaentregadesde=2026-06-01', $html);
        $this->assertStringContainsString('fechaentregahasta=2026-06-30', $html);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function crearNota(int $nronota, array $attrs = []): Nota
    {
        return Nota::query()->create(array_merge([
            'nronota' => $nronota,
            'descripcion' => 'Test retorno',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente Test',
            'encargado' => 'COT-'.$nronota,
            'nota_softland' => $nronota + 10000,
            'enviadoapi' => 0,
        ], $attrs));
    }
}
