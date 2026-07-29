<?php

namespace Tests\Feature;

use App\Models\OportunidadPalabraClave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OportunidadPalabrasClaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cotiz.sistema' => 'Romulo',
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
            'cotiz.api_usuario.url' => 'https://cotiza.reicol.cl/api/v1/usuario',
            'cotiz.api_nota.user' => 'api',
            'cotiz.api_nota.password' => 'secret',
        ]);

        Http::fake();
    }

    public function test_superadmin_puede_agregar_y_eliminar_palabra_clave(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->post(route('admin.oportunidades.palabras-clave.store'), [
                'frase' => '  servicio de aseo  ',
            ])
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'));

        $this->assertDatabaseHas('oportunidad_palabras_clave', [
            'frase' => 'servicio de aseo',
            'created_by' => $user->id,
            'orden' => 1,
        ]);

        $palabra = OportunidadPalabraClave::query()->firstOrFail();

        $this->actingAs($user)
            ->delete(route('admin.oportunidades.palabras-clave.destroy', $palabra))
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'));

        $this->assertDatabaseCount('oportunidad_palabras_clave', 0);
    }

    public function test_no_permite_duplicados(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadPalabraClave::query()->create([
            'frase' => 'alimentación',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->from(route('admin.oportunidades.palabras-clave.index'))
            ->post(route('admin.oportunidades.palabras-clave.store'), [
                'frase' => 'alimentación',
            ])
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'))
            ->assertSessionHasErrors('frase');
    }

    public function test_para_cotizar_muestra_aviso_sin_palabras(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->assertSee('No hay palabras clave configuradas', false);
    }

    public function test_para_cotizar_no_busca_sin_parametro(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadPalabraClave::query()->create([
            'frase' => 'aseo',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->assertSee('Buscar cotizaciones', false)
            ->assertSee('match local', false)
            ->assertSee('graba', false);
    }

    public function test_para_cotizar_muestra_frases_en_orden_alfabetico(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadPalabraClave::query()->create([
            'frase' => 'papel',
            'orden' => 1,
            'created_by' => $user->id,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'aseo',
            'orden' => 2,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->assertSeeInOrder(['aseo', 'papel'], false);
    }

    public function test_puede_mover_prioridad_con_flechas(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $papel = OportunidadPalabraClave::query()->create([
            'frase' => 'papel',
            'orden' => 1,
            'created_by' => $user->id,
        ]);
        $aseo = OportunidadPalabraClave::query()->create([
            'frase' => 'aseo',
            'orden' => 2,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('admin.oportunidades.palabras-clave.mover', $aseo), [
                'direccion' => 'up',
            ])
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'));

        $this->assertSame(1, (int) $aseo->fresh()->orden);
        $this->assertSame(2, (int) $papel->fresh()->orden);
    }

    public function test_puede_reordenar_por_drag(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $a = OportunidadPalabraClave::query()->create([
            'frase' => 'a',
            'orden' => 1,
            'created_by' => $user->id,
        ]);
        $b = OportunidadPalabraClave::query()->create([
            'frase' => 'b',
            'orden' => 2,
            'created_by' => $user->id,
        ]);
        $c = OportunidadPalabraClave::query()->create([
            'frase' => 'c',
            'orden' => 3,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.palabras-clave.reordenar'), [
                'ids' => [$c->id, $a->id, $b->id],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(1, (int) $c->fresh()->orden);
        $this->assertSame(2, (int) $a->fresh()->orden);
        $this->assertSame(3, (int) $b->fresh()->orden);
    }

    public function test_superadmin_distinto_de_admin_puede_agregar_palabra(): void
    {
        config([
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
        ]);

        $user = User::factory()->create([
            'username' => 'gerencia',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->post(route('admin.oportunidades.palabras-clave.store'), [
                'frase' => 'papel bond',
            ])
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'));

        $this->assertDatabaseHas('oportunidad_palabras_clave', [
            'frase' => 'papel bond',
            'created_by' => $user->id,
        ]);
    }

    public function test_otro_superadmin_puede_ver_y_buscar_oportunidades(): void
    {
        $user = User::factory()->create([
            'username' => 'otro_admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadPalabraClave::query()->create([
            'frase' => 'aseo',
            'orden' => 1,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->assertSee('Buscar cotizaciones', false)
            ->assertSee('Detalle por regi', false);

        $this->actingAs($user)
            ->getJson(route('admin.oportunidades.para-cotizar.estado'))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_ejecutivo_puede_ver_oportunidades_sin_buscar(): void
    {
        config([
            'cotiz.mercadopublico.analisis_admin_habilitado' => false,
        ]);

        $user = User::factory()->create([
            'username' => 'ejecutivo',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.index'))
            ->assertOk()
            ->assertSee('Oportunidades', false)
            ->assertDontSee('Buscar cotizaciones', false);

        $this->actingAs($user)
            ->get(route('admin.cotizaciones.index'))
            ->assertOk()
            ->assertSee('Oportunidades', false)
            ->assertSee(route('admin.oportunidades.para-cotizar.index'), false);

        $this->actingAs($user)
            ->getJson(route('admin.oportunidades.para-cotizar.estado'))
            ->assertForbidden();
    }

    public function test_sin_analisis_no_muestra_ni_permite_palabras_clave(): void
    {
        config([
            'cotiz.mercadopublico.analisis_admin_habilitado' => false,
        ]);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.palabras-clave.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.oportunidades.palabras-clave.store'), [
                'frase' => 'papel',
            ])
            ->assertForbidden();
    }

    public function test_index_muestra_orden_alfabetico_por_defecto(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadPalabraClave::query()->create([
            'frase' => 'papel',
            'orden' => 1,
            'created_by' => $user->id,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'aseo',
            'orden' => 2,
            'created_by' => $user->id,
        ]);

        $html = $this->actingAs($user)
            ->get(route('admin.oportunidades.palabras-clave.index'))
            ->assertOk()
            ->assertSee('orden alfab', false)
            ->assertSee('todas', false)
            ->getContent();

        $posAseo = strpos($html, '>aseo<');
        $posPapel = strpos($html, '>papel<');
        $this->assertNotFalse($posAseo);
        $this->assertNotFalse($posPapel);
        $this->assertLessThan($posPapel, $posAseo);
    }

    public function test_index_permite_ordenar_por_columna(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadPalabraClave::query()->create([
            'frase' => 'aseo',
            'orden' => 1,
            'created_by' => $user->id,
        ]);
        OportunidadPalabraClave::query()->create([
            'frase' => 'papel',
            'orden' => 2,
            'created_by' => $user->id,
        ]);

        $html = $this->actingAs($user)
            ->get(route('admin.oportunidades.palabras-clave.index', [
                'orden_campo' => 'frase',
                'orden_dir' => 'DESC',
            ]))
            ->assertOk()
            ->getContent();

        $posAseo = strpos($html, '>aseo<');
        $posPapel = strpos($html, '>papel<');
        $this->assertNotFalse($posAseo);
        $this->assertNotFalse($posPapel);
        $this->assertLessThan($posAseo, $posPapel);
    }

    public function test_store_con_regiones_asocia_solo_esas(): void
    {
        config(['cotiz.mercadopublico.regiones' => [13, 5, 8]]);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->post(route('admin.oportunidades.palabras-clave.store'), [
                'frase' => 'papel bond',
                'regiones' => ['13', '5'],
            ])
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'));

        $palabra = OportunidadPalabraClave::query()->where('frase', 'papel bond')->firstOrFail();
        $this->assertDatabaseHas('oportunidad_palabra_clave_regiones', [
            'palabra_clave_id' => $palabra->id,
            'region_codigo' => 13,
        ]);
        $this->assertDatabaseHas('oportunidad_palabra_clave_regiones', [
            'palabra_clave_id' => $palabra->id,
            'region_codigo' => 5,
        ]);
        $this->assertDatabaseMissing('oportunidad_palabra_clave_regiones', [
            'palabra_clave_id' => $palabra->id,
            'region_codigo' => 8,
        ]);
    }

    public function test_update_sin_regiones_deja_busqueda_en_todas(): void
    {
        config(['cotiz.mercadopublico.regiones' => [13, 5]]);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $palabra = OportunidadPalabraClave::query()->create([
            'frase' => 'aseo',
            'orden' => 1,
            'created_by' => $user->id,
        ]);
        $palabra->regiones()->create(['region_codigo' => 13]);

        $this->actingAs($user)
            ->put(route('admin.oportunidades.palabras-clave.update', $palabra), [
                'regiones' => [],
            ])
            ->assertRedirect(route('admin.oportunidades.palabras-clave.index'));

        $this->assertDatabaseCount('oportunidad_palabra_clave_regiones', 0);
        $palabra->refresh();
        $this->assertTrue($palabra->aplicaATodasLasRegiones());
    }

    public function test_palabras_clave_para_region_respeta_asociacion(): void
    {
        config(['cotiz.mercadopublico.regiones' => [13, 8]]);

        OportunidadPalabraClave::query()->create(['frase' => 'aseo', 'orden' => 1]);
        $rm = OportunidadPalabraClave::query()->create(['frase' => 'papel', 'orden' => 2]);
        $rm->regiones()->create(['region_codigo' => 13]);

        $servicio = app(\App\Services\OportunidadParaCotizarService::class);

        $this->assertSame(['aseo', 'papel'], $servicio->palabrasClaveParaRegion(13));
        $this->assertSame(['aseo'], $servicio->palabrasClaveParaRegion(8));
    }

    public function test_plan_busqueda_omite_region_sin_frases_aplicables(): void
    {
        config([
            'cotiz.mercadopublico.regiones' => [13, 8],
            'cotiz.mercadopublico.ticket' => 'test-ticket',
        ]);

        $soloRm = OportunidadPalabraClave::query()->create(['frase' => 'papel', 'orden' => 1]);
        $soloRm->regiones()->create(['region_codigo' => 13]);

        $plan = app(\App\Services\OportunidadParaCotizarService::class)->planBusqueda();

        $this->assertNull($plan['error']);
        $this->assertSame(1, $plan['total_pasos']);
        $this->assertSame(13, $plan['pasos'][0]['region']);
    }
}
