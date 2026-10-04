<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\NotaCotizarIaAplicacion;
use App\Models\OportunidadCotizarIa;
use App\Models\OportunidadEncontrada;
use App\Models\User;
use App\Services\OportunidadParaCotizarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OportunidadCotizarIaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
            'cotiz.mercadopublico.fecha_inicio_busqueda' => '2026-07-14',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00', 'America/Santiago'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registrar_cotizar_ia_ejecucion_incrementa_contador(): void
    {
        $servicio = app(OportunidadParaCotizarService::class);

        $m1 = $servicio->registrarCotizarIaEjecucion('1000-1-cot26');
        $m2 = $servicio->registrarCotizarIaEjecucion('1000-1-COT26');

        $this->assertSame(1, $m1['cotizar_ia_veces']);
        $this->assertSame(0, $m1['cotizar_ia_aplicadas_veces']);
        $this->assertSame(2, $m2['cotizar_ia_veces']);

        $this->assertDatabaseHas('oportunidad_cotizar_ia', [
            'codigo' => '1000-1-COT26',
            'veces' => 2,
            'veces_aplicada' => 0,
        ]);
    }

    public function test_registrar_cotizar_ia_aplicacion_incrementa_y_guarda_nota(): void
    {
        $servicio = app(OportunidadParaCotizarService::class);
        $servicio->registrarCotizarIaEjecucion('1000-1-COT26');

        $nota = Nota::query()->create([
            'nronota' => 501,
            'descripcion' => 'Test',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => '',
            'encargado' => '1000-1-COT26',
            'nota_softland' => 50100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        $metricas = $servicio->registrarCotizarIaAplicacion('1000-1-COT26', (int) $nota->nronota, 'admin', 3);

        $this->assertSame(1, $metricas['cotizar_ia_aplicadas_veces']);
        $this->assertSame(1, $metricas['cotizar_ia_aplicaciones_nota']);
        $this->assertDatabaseHas('oportunidad_cotizar_ia', [
            'codigo' => '1000-1-COT26',
            'veces' => 1,
            'veces_aplicada' => 1,
        ]);
        $this->assertDatabaseHas('nota_cotizar_ia_aplicaciones', [
            'nronota' => 501,
            'codigo' => '1000-1-COT26',
            'usuario' => 'admin',
            'lineas_agregadas' => 3,
        ]);
    }

    public function test_listado_incluye_metricas_ia_solo_para_admin_y_pame(): void
    {
        config(['cotiz.mercadopublico.analisis_admin_habilitado' => false]);

        OportunidadEncontrada::query()->create([
            'codigo' => '1000-1-COT26',
            'nombre' => 'Papel bond',
            'organismo' => 'Hospital Demo',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'monto_presupuesto_clp' => 500000,
            'moneda' => 'CLP',
            'fecha_publicacion' => now()->subDay(),
            'fecha_cierre' => now()->addDays(5),
            'palabras_coinciden' => ['papel'],
            'cantidad_productos' => 3,
            'fecha_busqueda' => '2026-07-16',
            'indice_region_config' => 0,
        ]);

        OportunidadCotizarIa::query()->create([
            'codigo' => '1000-1-COT26',
            'veces' => 4,
            'veces_aplicada' => 2,
            'ultimo_uso_at' => now(),
        ]);

        $admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        $pame = User::factory()->create([
            'username' => 'pame',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
        $ejecutivo = User::factory()->create([
            'username' => 'ejecutivo',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $htmlAdmin = $this->actingAs($admin)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('"cotizar_ia_veces":4', $htmlAdmin);
        $this->assertStringContainsString('"cotizar_ia_aplicadas_veces":2', $htmlAdmin);

        $htmlPame = $this->actingAs($pame)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('"cotizar_ia_aplicadas_veces":2', $htmlPame);

        $htmlEjecutivo = $this->actingAs($ejecutivo)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('"cotizar_ia_veces":4', $htmlEjecutivo);
        $this->assertStringNotContainsString('"cotizar_ia_aplicadas_veces":2', $htmlEjecutivo);
    }

    public function test_listado_cotizaciones_muestra_ia_aplicada_solo_admin(): void
    {
        $admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        $ejecutivo = User::factory()->create([
            'username' => 'ejecutivo',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $nota = Nota::query()->create([
            'nronota' => 601,
            'descripcion' => 'Con IA',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Demo',
            'encargado' => '1000-1-COT26',
            'nota_softland' => 60100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaCotizarIaAplicacion::query()->create([
            'nronota' => $nota->nronota,
            'codigo' => '1000-1-COT26',
            'usuario' => 'admin',
            'lineas_agregadas' => 5,
            'aplicado_at' => now(),
        ]);

        $htmlAdmin = $this->actingAs($admin)
            ->get(route('admin.cotizaciones.index', ['nronota' => $nota->nronota]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Aplic. 1', $htmlAdmin);
        $this->assertStringContainsString('filtro-solo-ia-aplicada', $htmlAdmin);

        $htmlEjecutivo = $this->actingAs($ejecutivo)
            ->get(route('admin.cotizaciones.index', ['nronota' => $nota->nronota]))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Aplic. 1', $htmlEjecutivo);
        $this->assertStringNotContainsString('filtro-solo-ia-aplicada', $htmlEjecutivo);
    }
}
