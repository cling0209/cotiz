<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use App\Services\NotaListadoService;
use App\Services\NotaMpResultadosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CotizacionInternaTest extends TestCase
{
    use RefreshDatabase;

    private User $ejecutivo;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ejecutivo = User::factory()->create([
            'username' => 'ejec01',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
    }

    public function test_nueva_interna_muestra_formulario_sin_importar_mp(): void
    {
        $response = $this->actingAs($this->ejecutivo)
            ->post(route('admin.cotizaciones.create'), ['es_interna' => '1']);

        $response->assertOk();
        $response->assertSee('es_interna', false);
        $response->assertDontSee('id="btn-abrir-importar-compra-agil"', false);
        $this->assertSame(0, Nota::query()->where('usuario', $this->ejecutivo->username)->count());
    }

    public function test_grabar_cabecera_interna_asigna_cm_y_no_consulta_mp(): void
    {
        Http::fake();

        $response = $this->actingAs($this->ejecutivo)->postJson(
            route('admin.cotizaciones.cabecera.store', 0),
            [
                'es_interna' => '1',
                'descripcion' => 'Cotización interna de prueba',
                'encargado' => '1161-999-COT26',
                'empresa' => 'Cliente interno',
                'diashabiles' => 5,
            ],
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('recien_creada', true);

        $nronota = (int) $response->json('nronota');
        $this->assertGreaterThan(0, $nronota);

        $nota = Nota::query()->findOrFail($nronota);
        $this->assertFalse($nota->es_compra_agil);
        $this->assertTrue($nota->esCotizacionInterna());
        $this->assertSame('CM-'.$nronota, $nota->encargado);
        $this->assertSame(1, (int) $nota->correlativo);

        Http::assertNothingSent();
    }

    public function test_interna_no_entra_a_consulta_mp(): void
    {
        config([
            'cotiz.empresa_rut' => '',
            'cotiz.mercadopublico.resultados_filtrar_por_ultimo_cambio' => false,
            'cotiz.mercadopublico.resultados_skip_consultadas_mismo_dia' => false,
        ]);

        $interna = $this->crearInterna();
        $mp = $this->crearNotaMp([
            'nronota' => 401,
            'encargado' => '1161-401-COT26',
        ]);

        $ids = $this->app->make(NotaMpResultadosService::class)
            ->notasPendientesConsulta()
            ->pluck('nronota')
            ->map(fn ($n) => (int) $n)
            ->all();

        $this->assertContains((int) $mp->nronota, $ids);
        $this->assertNotContains((int) $interna->nronota, $ids);
    }

    public function test_listado_muestra_no_aplica_mp_para_interna(): void
    {
        $nota = $this->crearInterna([
            'fecha' => now()->toDateString(),
            'empresa' => 'Cliente interno listado',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.cotizaciones.index', [
            'fechadesde' => now()->subDay()->toDateString(),
            'fechahasta' => now()->toDateString(),
            'nronota' => $nota->nronota,
        ]));

        $response->assertOk();
        $response->assertSee('No aplica MP', false);
        $response->assertSee('Nueva interna', false);
    }

    public function test_filtro_sin_consultar_no_incluye_internas(): void
    {
        $this->crearInterna(['nronota' => 501, 'fecha' => now()->toDateString()]);
        $mp = $this->crearNotaMp(['nronota' => 502, 'fecha' => now()->toDateString()]);

        $page = $this->app->make(NotaListadoService::class)->listar($this->admin, [
            'estado_mp' => 'sin_consultar',
            'orden_campo' => 'nronota',
            'orden_dir' => 'desc',
        ], 20);

        $ids = $page->getCollection()->pluck('nronota')->map(fn ($n) => (int) $n)->all();

        $this->assertContains((int) $mp->nronota, $ids);
        $this->assertNotContains(501, $ids);
    }

    public function test_duplicar_interna_asigna_nuevo_codigo_cm_y_correlativo_uno(): void
    {
        $nota = $this->crearInterna(['nronota' => 410, 'encargado' => 'CM-410']);
        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'PROD001',
            'prod_valor' => 1000,
            'cantidad' => 2,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 800,
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.cotizaciones.duplicar', $nota->nronota));

        $copia = Nota::query()
            ->where('nronota', '!=', $nota->nronota)
            ->orderByDesc('nronota')
            ->firstOrFail();

        $response->assertRedirect(route('admin.cotizaciones.edit', $copia->nronota));
        $this->assertTrue($copia->esCotizacionInterna());
        $this->assertSame('CM-'.$copia->nronota, $copia->encargado);
        $this->assertNotSame($nota->encargado, $copia->encargado);
        $this->assertSame(1, (int) $copia->correlativo);
        $this->assertSame(1, NotaDetalle::query()->where('nronota', $copia->nronota)->count());
    }

    public function test_importar_compra_agil_rechaza_interna(): void
    {
        $nota = $this->crearInterna();

        $this->actingAs($this->ejecutivo)->postJson(
            route('admin.cotizaciones.importar-compra-agil', $nota->nronota),
            ['texto' => "1161-1-COT26\nProducto demo"],
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error',
                'Esta cotización interna no se importa ni se consulta en Mercado Público.',
            );
    }

    public function test_nueva_interna_no_reutiliza_nota_mp_vacia(): void
    {
        $vaciaMp = $this->crearNotaMp([
            'encargado' => '',
            'empresa' => '',
        ]);

        $response = $this->actingAs($this->ejecutivo)
            ->post(route('admin.cotizaciones.create'), ['es_interna' => '1']);

        $response->assertOk();
        $this->assertSame(1, Nota::query()->where('usuario', $this->ejecutivo->username)->count());
        $this->assertTrue((bool) $vaciaMp->fresh()->es_compra_agil);
        $this->assertSame('', trim((string) $vaciaMp->fresh()->encargado));
    }

    private function crearInterna(array $attrs = []): Nota
    {
        $nronota = (int) ($attrs['nronota'] ?? 400);

        return Nota::query()->create(array_merge([
            'nronota' => $nronota,
            'descripcion' => 'Test interna '.$nronota,
            'fecha' => now()->toDateString(),
            'usuario' => $this->ejecutivo->username,
            'empresa' => 'Cliente interno',
            'encargado' => 'CM-'.$nronota,
            'correlativo' => 1,
            'nota_softland' => 20000 + $nronota,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
            'es_compra_agil' => false,
        ], $attrs));
    }

    private function crearNotaMp(array $attrs = []): Nota
    {
        $nronota = (int) ($attrs['nronota'] ?? 300);

        return Nota::query()->create(array_merge([
            'nronota' => $nronota,
            'descripcion' => 'Test MP '.$nronota,
            'fecha' => now()->toDateString(),
            'usuario' => $this->ejecutivo->username,
            'empresa' => 'Cliente MP',
            'encargado' => '1161-'.$nronota.'-COT26',
            'correlativo' => 1,
            'nota_softland' => 10000 + $nronota,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
            'es_compra_agil' => true,
        ], $attrs));
    }
}
