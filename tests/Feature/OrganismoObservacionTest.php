<?php

namespace Tests\Feature;

use App\Models\OportunidadEncontrada;
use App\Models\OrganismoObservacion;
use App\Models\User;
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

    public function test_listado_incorpora_organismos_desde_oportunidades(): void
    {
        OportunidadEncontrada::query()->create([
            'codigo' => 'CA-TEST-1',
            'nombre' => 'Compra prueba',
            'organismo' => 'Hospital Demo',
            'rut_organismo' => '76123456-K',
            'fecha_busqueda' => now()->toDateString(),
            'indice_region_config' => 0,
        ]);

        // RUT solo dígitos: PHP castea a int si se usa como key de array; no debe romper PG.
        OportunidadEncontrada::query()->create([
            'codigo' => 'CA-TEST-2',
            'nombre' => 'Compra numerica',
            'organismo' => 'Municipalidad Digitos',
            'rut_organismo' => '61602245',
            'fecha_busqueda' => now()->toDateString(),
            'indice_region_config' => 0,
        ]);

        $this->actingAs($this->superadmin())
            ->get(route('admin.organismos-observaciones.index'))
            ->assertOk()
            ->assertSee('Hospital Demo')
            ->assertSee('76123456-K')
            ->assertSee('Municipalidad Digitos')
            ->assertSee('61602245');

        $this->assertDatabaseHas('organismo_observaciones', [
            'rut_organismo' => '76123456-K',
            'nombre' => 'Hospital Demo',
        ]);
        $this->assertDatabaseHas('organismo_observaciones', [
            'rut_organismo' => '61602245',
            'nombre' => 'Municipalidad Digitos',
        ]);
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

    public function test_api_recibe_graba_desde_par(): void
    {
        $response = $this->withBasicAuth('api_user', 'api_pass')
            ->postJson('/api/v1/organismo-observacion', [
                'accion' => 'graba',
                'replicacion' => true,
                'rut_organismo' => '76111222-3',
                'nombre' => 'Organismo Par',
                'observacion' => 'Tip admin remoto',
                'observacion_automatica' => 'Según 5 CA: suele adjudicar marca reconocida (Brother).',
                'observacion_automatica_casos' => 5,
                'campos' => ['admin', 'auto'],
            ]);

        $response->assertOk()->assertJson(['resultado' => 'OK']);

        $this->assertDatabaseHas('organismo_observaciones', [
            'rut_organismo' => '76111222-3',
            'nombre' => 'Organismo Par',
            'observacion' => 'Tip admin remoto',
            'observacion_automatica_casos' => 5,
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
