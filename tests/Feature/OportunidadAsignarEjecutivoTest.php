<?php

namespace Tests\Feature;

use App\Models\OportunidadEncontrada;
use App\Models\OportunidadTomada;
use App\Models\User;
use App\Services\OportunidadParaCotizarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OportunidadAsignarEjecutivoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ejecutivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        Carbon::setTestNow(Carbon::parse('2026-09-13 15:30:00', 'America/Santiago'));

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'nombre' => 'Admin',
            'apellidop' => 'Sistema',
            'perfil' => User::PERFIL_SUPERADMIN,
            'activo' => true,
        ]);

        $this->ejecutivo = User::factory()->create([
            'username' => 'ejecutivo1',
            'nombre' => 'Ana',
            'apellidop' => 'Pérez',
            'perfil' => User::PERFIL_EJECUTIVO,
            'activo' => true,
        ]);
    }

    public function test_superadmin_asigna_oportunidad_a_ejecutivo_activo(): void
    {
        $this->crearOportunidad('3000-1-COT26');

        $response = $this->actingAs($this->admin)->post(
            route('admin.oportunidades.para-cotizar.asignar.store', '3000-1-COT26'),
            ['usuario' => 'ejecutivo1'],
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('notas', [
            'encargado' => '3000-1-COT26',
            'usuario' => 'ejecutivo1',
            'asignado_por' => 'admin',
            'empresa' => 'Hospital Demo',
        ]);
        $this->assertDatabaseHas('oportunidad_tomadas', [
            'codigo' => '3000-1-COT26',
            'usuario' => 'ejecutivo1',
        ]);

        $nota = \App\Models\Nota::query()->where('encargado', '3000-1-COT26')->first();
        $this->assertNotNull($nota);
        $this->assertNotNull($nota->asignado_at);
        $response->assertRedirect(route('admin.cotizaciones.edit', $nota->nronota));

        $items = $this->app->make(OportunidadParaCotizarService::class)->listarGuardadasVigentesDesde();
        $this->assertNotContains('3000-1-COT26', array_column($items, 'codigo'));
    }

    public function test_no_permite_asignar_ejecutivo_bloqueado(): void
    {
        $this->crearOportunidad('3000-2-COT26');
        $bloqueado = User::factory()->deshabilitado()->create([
            'username' => 'bloqueado',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('admin.oportunidades.para-cotizar.asignar.store', '3000-2-COT26'),
            ['usuario' => $bloqueado->username],
        );

        $response->assertRedirect(route('admin.oportunidades.para-cotizar.asignar', '3000-2-COT26'));
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('notas', ['encargado' => '3000-2-COT26']);
    }

    public function test_ejecutivo_no_puede_asignar(): void
    {
        $this->crearOportunidad('3000-3-COT26');

        $response = $this->actingAs($this->ejecutivo)->post(
            route('admin.oportunidades.para-cotizar.asignar.store', '3000-3-COT26'),
            ['usuario' => 'ejecutivo1'],
        );

        $response->assertForbidden();
    }

    public function test_formulario_solo_lista_ejecutivos_activos(): void
    {
        $this->crearOportunidad('3000-4-COT26');
        User::factory()->deshabilitado()->create([
            'username' => 'inactivo',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
        User::factory()->create([
            'username' => 'otroadmin',
            'perfil' => User::PERFIL_SUPERADMIN,
            'activo' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(
            route('admin.oportunidades.para-cotizar.asignar', '3000-4-COT26'),
        );

        $response->assertOk();
        $response->assertSee('ejecutivo1');
        $response->assertDontSee('inactivo');
        $response->assertDontSee('otroadmin');
    }

    public function test_no_asigna_si_ya_esta_tomada(): void
    {
        $this->crearOportunidad('3000-5-COT26');
        OportunidadTomada::query()->create([
            'codigo' => '3000-5-COT26',
            'sistema' => 'Romulo',
            'usuario' => 'otro',
            'tomada_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('admin.oportunidades.para-cotizar.asignar.store', '3000-5-COT26'),
            ['usuario' => 'ejecutivo1'],
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('notas', ['encargado' => '3000-5-COT26', 'usuario' => 'ejecutivo1']);
    }

    public function test_listado_cotizaciones_muestra_asignacion(): void
    {
        $this->crearOportunidad('3000-6-COT26');

        $this->actingAs($this->admin)->post(
            route('admin.oportunidades.para-cotizar.asignar.store', '3000-6-COT26'),
            ['usuario' => 'ejecutivo1'],
        );

        $response = $this->actingAs($this->admin)->get(route('admin.cotizaciones.index'));

        $response->assertOk();
        $response->assertSee('Asignada por');
        $response->assertSee('Admin Sistema');
        $response->assertSee('13/09/2026 15:30');
    }

    private function crearOportunidad(string $codigo): OportunidadEncontrada
    {
        return OportunidadEncontrada::query()->create([
            'codigo' => $codigo,
            'nombre' => 'Papel bond',
            'organismo' => 'Hospital Demo',
            'rut_organismo' => '61111111-1',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'comuna' => 'Santiago',
            'monto_presupuesto_clp' => 500000,
            'moneda' => 'CLP',
            'fecha_publicacion' => now()->subDay(),
            'fecha_cierre' => now()->addDays(5),
            'palabras_coinciden' => ['papel'],
            'cantidad_productos' => 3,
            'fecha_busqueda' => now()->toDateString(),
            'indice_region_config' => 0,
        ]);
    }
}
