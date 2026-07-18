<?php

namespace Tests\Feature;

use App\Models\OportunidadEncontrada;
use App\Models\OportunidadVisita;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OportunidadVisitasTest extends TestCase
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

    public function test_registrar_visita_incrementa_contador_del_usuario(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.visita'), [
                'codigo' => '1000-1-COT26',
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'codigo' => '1000-1-COT26',
                'visitas_usuario' => 1,
            ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.visita'), [
                'codigo' => '1000-1-cot26',
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'visitas_usuario' => 2,
            ]);

        $this->assertDatabaseHas('oportunidad_visitas', [
            'user_id' => $user->id,
            'codigo' => '1000-1-COT26',
            'veces' => 2,
        ]);
    }

    public function test_listado_incluye_visitas_del_usuario_logueado(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
        $otro = User::factory()->create([
            'username' => 'otro',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

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

        OportunidadVisita::query()->create([
            'user_id' => $user->id,
            'codigo' => '1000-1-COT26',
            'veces' => 3,
            'ultima_visita_at' => now(),
        ]);
        OportunidadVisita::query()->create([
            'user_id' => $otro->id,
            'codigo' => '1000-1-COT26',
            'veces' => 9,
            'ultima_visita_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('"visitas_usuario":3', $html);
        $this->assertStringNotContainsString('"visitas_usuario":9', $html);
    }

    public function test_ir_a_cotizar_con_codigo_registra_visita(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->get(route('admin.cotizaciones.create', ['codigo' => '607603-40-COT26']))
            ->assertOk();

        $this->assertDatabaseHas('oportunidad_visitas', [
            'user_id' => $user->id,
            'codigo' => '607603-40-COT26',
            'veces' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('admin.cotizaciones.create', ['codigo' => '607603-40-COT26']))
            ->assertOk();

        $this->assertDatabaseHas('oportunidad_visitas', [
            'user_id' => $user->id,
            'codigo' => '607603-40-COT26',
            'veces' => 2,
        ]);
    }

    public function test_ejecutivo_sin_permiso_no_registra_visita(): void
    {
        $user = User::factory()->create([
            'username' => 'ejecutivo',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.visita'), [
                'codigo' => '1000-1-COT26',
            ])
            ->assertForbidden();
    }
}
