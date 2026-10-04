<?php

namespace Tests\Feature;

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

    public function test_registrar_cotizar_ia_incrementa_contador_global(): void
    {
        $servicio = app(OportunidadParaCotizarService::class);

        $this->assertSame(1, $servicio->registrarCotizarIaUso('1000-1-cot26'));
        $this->assertSame(2, $servicio->registrarCotizarIaUso('1000-1-COT26'));

        $this->assertDatabaseHas('oportunidad_cotizar_ia', [
            'codigo' => '1000-1-COT26',
            'veces' => 2,
        ]);
    }

    public function test_listado_incluye_cotizar_ia_veces_solo_para_admin_y_pame(): void
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

        $htmlPame = $this->actingAs($pame)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('"cotizar_ia_veces":4', $htmlPame);

        $htmlEjecutivo = $this->actingAs($ejecutivo)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('"cotizar_ia_veces":4', $htmlEjecutivo);
    }
}
