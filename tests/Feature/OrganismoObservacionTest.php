<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\NotaMpSeguimiento;
use App\Models\OrganismoObservacion;
use App\Models\User;
use App\Services\OrganismoObservacionService;
use App\Services\OrganismoPerfilAutomaticoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrganismoObservacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cotiz.sistema' => 'Romulo',
            'cotiz.api_usuario.url' => 'https://peer.test/api/v1/usuario',
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
            'cotiz.mercadopublico.analisis_admin_habilitado' => true,
        ]);
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
    }

    private function crearCerrada(string $rut, string $empresa, string $organismo = ''): void
    {
        static $n = 9000;
        $n++;
        $user = User::factory()->create(['perfil' => User::PERFIL_EJECUTIVO]);
        Nota::query()->create([
            'nronota' => $n,
            'usuario' => $user->username,
            'empresa' => $empresa,
            'rutempresa' => $rut,
            'descripcion' => 'Test',
            'fecha' => now()->toDateString(),
            'encargado' => $n.'-1-COT26',
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaMpSeguimiento::query()->create([
            'nronota' => $n,
            'codigo_proceso' => 'CA-'.$n,
            'organismo' => $organismo !== '' ? $organismo : $empresa,
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);
    }

    public function test_reset_carga_solo_desde_cerradas_y_unifica_rut(): void
    {
        $this->crearCerrada('65077010', 'Ejercito de Chile');
        $this->crearCerrada('65077010-2', 'Ejercito de Chile');
        $this->crearCerrada('76123456-K', 'Hospital Demo');

        OrganismoObservacion::query()->create([
            'rut_organismo' => '99999999-9',
            'nombre' => 'No deberia quedar',
        ]);

        /** @var OrganismoObservacionService $svc */
        $svc = app(OrganismoObservacionService::class);
        $stats = $svc->resetDesdeCerradas();

        $this->assertSame(1, $stats['borrados']);
        $this->assertSame(2, $stats['creados']);
        $this->assertDatabaseMissing('organismo_observaciones', [
            'rut_organismo' => '99999999-9',
        ]);
        $this->assertDatabaseHas('organismo_observaciones', [
            'rut_organismo' => '65077010-2',
            'nombre' => 'Ejercito de Chile',
        ]);
        $this->assertDatabaseHas('organismo_observaciones', [
            'rut_organismo' => '76123456-K',
            'nombre' => 'Hospital Demo',
        ]);
        $this->assertSame(1, OrganismoObservacion::query()->where('nombre', 'Ejercito de Chile')->count());
    }

    public function test_fusiona_duplicados_rut_con_y_sin_dv_en_listado(): void
    {
        OrganismoObservacion::query()->create([
            'rut_organismo' => '65077010',
            'nombre' => 'Ejercito de Chile',
            'observacion' => 'Tip admin',
        ]);
        OrganismoObservacion::query()->create([
            'rut_organismo' => '65077010-2',
            'nombre' => 'Ejercito de Chile',
        ]);

        $this->actingAs($this->superadmin())
            ->get(route('admin.organismos-observaciones.index'))
            ->assertOk()
            ->assertSee('Modificar')
            ->assertDontSee('>Agregar<', false);

        $this->assertSame(1, OrganismoObservacion::query()->count());
        $org = OrganismoObservacion::query()->first();
        $this->assertSame('65077010-2', $org->rut_organismo);
        $this->assertSame('Tip admin', $org->observacion);
    }

    public function test_admin_guarda_y_sincroniza_observacion_al_par(): void
    {
        Http::fake([
            'https://peer.test/api/v1/organismo-observacion' => Http::response([
                'resultado' => 'OK',
                'created' => true,
            ], 200),
        ]);

        $org = OrganismoObservacion::query()->create([
            'rut_organismo' => '76999888-1',
            'nombre' => 'Municipalidad Test',
            'observacion' => null,
        ]);

        $user = $this->superadmin();

        $this->actingAs($user)
            ->put(route('admin.organismos-observaciones.update', $org), [
                'nombre' => 'Municipalidad Test',
                'observacion' => 'Prefieren Brother; no genérico.',
            ])
            ->assertRedirect(route('admin.organismos-observaciones.index'))
            ->assertSessionHas('success');

        $org->refresh();
        $this->assertSame('Prefieren Brother; no genérico.', $org->observacion);
        $this->assertSame($user->id, $org->updated_by);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/organismo-observacion')
                && ($request['accion'] ?? null) === 'graba'
                && ($request['rut_organismo'] ?? null) === '76999888-1'
                && in_array('admin', $request['campos'] ?? [], true);
        });
    }

    public function test_api_limpia_y_graba_desde_par(): void
    {
        OrganismoObservacion::query()->create([
            'rut_organismo' => '11111111-1',
            'nombre' => 'Viejo',
        ]);

        $this->withBasicAuth('api_user', 'api_pass')
            ->postJson('/api/v1/organismo-observacion', [
                'accion' => 'limpia',
                'replicacion' => true,
            ])
            ->assertOk()
            ->assertJson(['resultado' => 'OK']);

        $this->assertSame(0, OrganismoObservacion::query()->count());

        $this->withBasicAuth('api_user', 'api_pass')
            ->postJson('/api/v1/organismo-observacion', [
                'accion' => 'graba',
                'replicacion' => true,
                'rut_organismo' => '76111222-3',
                'nombre' => 'Organismo Par',
                'observacion' => 'Tip admin remoto',
                'campos' => ['admin'],
            ])
            ->assertOk()
            ->assertJson(['resultado' => 'OK']);

        $this->assertDatabaseHas('organismo_observaciones', [
            'rut_organismo' => '76111222-3',
            'nombre' => 'Organismo Par',
        ]);
    }

    public function test_comando_no_recalcula_si_analisis_admin_off(): void
    {
        config(['cotiz.mercadopublico.analisis_admin_habilitado' => false]);

        OrganismoObservacion::query()->create([
            'rut_organismo' => '76888777-2',
            'nombre' => 'Sin analisis',
        ]);

        $this->artisan('organismo:analizar-perfiles', ['--sin-sync' => true])
            ->assertSuccessful();

        $org = OrganismoObservacion::query()->where('rut_organismo', '76888777-2')->first();
        $this->assertNull($org->observacion_automatica_en);
    }

    public function test_perfil_automatico_detecta_marcas(): void
    {
        /** @var OrganismoPerfilAutomaticoService $svc */
        $svc = app(OrganismoPerfilAutomaticoService::class);

        $perfil = $svc->armarPerfil(collect([
            'Toner Brother TN-2370 original',
            'Resma carta HP premium',
            'Cartucho Brother compatible',
            'Tinta Epson original',
        ]));

        $this->assertSame(4, $perfil['casos']);
        $this->assertNotNull($perfil['texto']);
        $this->assertStringContainsString('Brother', $perfil['texto']);
    }

    public function test_ejecutivo_no_accede_al_mantenedor(): void
    {
        $ejecutivo = User::factory()->create([
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $this->actingAs($ejecutivo)
            ->get(route('admin.organismos-observaciones.index'))
            ->assertForbidden();
    }
}
