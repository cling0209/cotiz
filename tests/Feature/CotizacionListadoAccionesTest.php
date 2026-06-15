<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CotizacionListadoAccionesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ejecutivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->ejecutivo = User::factory()->create([
            'username' => 'ejecutivo',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
    }

    public function test_superadmin_puede_aceptar_cotizacion(): void
    {
        $nota = $this->crearNota(['usuario' => 'ejecutivo', 'estado' => '']);

        $response = $this->actingAs($this->admin)->post(route('admin.cotizaciones.aceptar', $nota->nronota));

        $response->assertRedirect(route('admin.cotizaciones.index'));
        $this->assertDatabaseHas('notas', [
            'nronota' => $nota->nronota,
            'estado' => 'aceptada',
            'estadousuario' => 'admin',
        ]);
    }

    public function test_ejecutivo_no_puede_aceptar_cotizacion(): void
    {
        $nota = $this->crearNota(['usuario' => 'ejecutivo', 'estado' => '']);

        $response = $this->actingAs($this->ejecutivo)->post(route('admin.cotizaciones.aceptar', $nota->nronota));

        $response->assertForbidden();
    }

    public function test_enviar_marca_enviadoapi_cuando_api_responde_ok(): void
    {
        config([
            'cotiz.api_nota_envio.url' => 'https://api.test/nota',
            'cotiz.api_nota_envio.user' => 'apiuser',
            'cotiz.api_nota_envio.password' => 'secret',
        ]);

        Http::fake([
            'https://api.test/nota' => Http::response(['resultado' => 'OK'], 200),
        ]);

        $nota = $this->crearNota(['usuario' => 'ejecutivo', 'enviadoapi' => 0]);

        $response = $this->actingAs($this->ejecutivo)->post(route('admin.cotizaciones.enviar', $nota->nronota));

        $response->assertRedirect(route('admin.cotizaciones.index'));
        $this->assertDatabaseHas('notas', [
            'nronota' => $nota->nronota,
            'enviadoapi' => 1,
        ]);
    }

    public function test_enviar_relay_usa_usuario_logueado_no_credencial_api(): void
    {
        config([
            'cotiz.api_nota.url' => 'https://destino.test/api/v1/nota',
            'cotiz.api_nota.user' => 'api_nota_user',
            'cotiz.api_nota.password' => 'api_nota_secret',
            'cotiz.api_nota_envio.url' => '',
            'products.image_base_url' => '',
        ]);

        Http::fake([
            'destino.test/*' => Http::response(['resultado' => 'OK', 'nronota' => 88], 200),
        ]);

        $nota = $this->crearNota(['usuario' => 'ejecutivo', 'enviadoapi' => 0]);

        Maeprod::query()->create([
            'prod_item' => 'DEMO001',
            'prod_nombre' => 'Papel bond',
            'prod_familia' => 'PAPEL',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
        ]);

        DB::table('notasdetalle')->insert([
            'nronota' => $nota->nronota,
            'prod_item' => 'DEMO001',
            'prod_valor' => 1000,
            'cantidad' => 1,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 800,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.cotizaciones.enviar', $nota->nronota));

        $response->assertRedirect(route('admin.cotizaciones.index'));

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://destino.test/api/v1/nota'
                && ($data['accion'] ?? '') === 'graba_resumen'
                && ($data['usuario'] ?? '') === 'admin';
        });
    }

    public function test_export_aceptadas_requiere_superadmin(): void
    {
        $this->actingAs($this->ejecutivo)
            ->get(route('admin.cotizaciones.export.aceptadas'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.export.aceptadas'))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_export_sin_codigo_softland_vacio_sin_cotizaciones_aceptadas(): void
    {
        $nota = $this->crearNota(['usuario' => 'ejecutivo', 'estado' => null]);

        Maeprod::query()->create([
            'prod_item' => 'SINCOD02',
            'prod_nombre' => 'PRODUCTO PENDIENTE',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'prod_item_softland' => null,
        ]);

        DB::table('notasdetalle')->insert([
            'nronota' => $nota->nronota,
            'prod_item' => 'SINCOD02',
            'prod_valor' => 1000,
            'cantidad' => 1,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 800,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.cotizaciones.export.sin-codigo-softland'))
            ->assertRedirect(route('admin.cotizaciones.index'))
            ->assertSessionHas('warning');
    }

    public function test_export_sin_codigo_softland_incluye_producto_aceptado_sin_softland(): void
    {
        $nota = $this->crearNota(['usuario' => 'ejecutivo', 'estado' => 'aceptada']);

        Maeprod::query()->create([
            'prod_item' => 'SINCOD01',
            'prod_nombre' => 'PRODUCTO SIN SOFTLAND',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'prod_item_softland' => null,
        ]);

        DB::table('notasdetalle')->insert([
            'nronota' => $nota->nronota,
            'prod_item' => 'SINCOD01',
            'prod_valor' => 1000,
            'cantidad' => 1,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 800,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.cotizaciones.export.sin-codigo-softland'));

        $response->assertOk();
        $contenido = $response->streamedContent();
        $this->assertStringContainsString('SINCOD01', $contenido);
        $this->assertStringContainsString('PRODUCTO SIN SOFTLAND', $contenido);
    }

    private function crearNota(array $attrs = []): Nota
    {
        return Nota::query()->create(array_merge([
            'nronota' => 100,
            'descripcion' => 'Test',
            'fecha' => now()->toDateString(),
            'usuario' => 'ejecutivo',
            'empresa' => 'Cliente Test',
            'encargado' => 'COT-TEST-001',
            'nota_softland' => 10000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ], $attrs));
    }
}
