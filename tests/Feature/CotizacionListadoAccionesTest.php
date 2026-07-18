<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaMpSeguimiento;
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

    public function test_no_puede_enviar_cotizacion_recibida_por_api(): void
    {
        Http::fake();

        $nota = $this->crearNota([
            'usuario' => 'ejecutivo',
            'enviadoapi' => 0,
            'notaorigen' => 99,
            'sistema' => 'Reicol',
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.cotizaciones.enviar', $nota->nronota));

        $response->assertRedirect(route('admin.cotizaciones.index'));
        $response->assertSessionHas('error', 'No se puede enviar una cotización recibida de otra instancia.');
        $this->assertDatabaseHas('notas', [
            'nronota' => $nota->nronota,
            'enviadoapi' => 0,
        ]);
        Http::assertNothingSent();
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

    public function test_listado_muestra_alerta_segundo_llamado_solo_del_ejecutivo_logueado(): void
    {
        $propia = $this->crearNota([
            'nronota' => 201,
            'usuario' => 'ejecutivo',
            'encargado' => '201-1-COT26',
        ]);
        NotaMpSeguimiento::query()->create([
            'nronota' => $propia->nronota,
            'codigo_proceso' => '201-1-COT26',
            'estado_mp_codigo' => 'publicada',
            'estado_mp_glosa' => 'Publicada',
            'organismo' => 'Municipalidad Test',
            'resultado_propio' => 'pendiente',
            'finalizado' => false,
            'convocatoria_estado' => 2,
            'convocatoria_descripcion' => 'Segundo llamado',
            'fecha_cierre_segundo_llamado' => '2026-07-11 21:38:00',
            'ultimo_consultado_en' => now(),
        ]);

        $ajena = $this->crearNota([
            'nronota' => 202,
            'usuario' => 'otro',
            'encargado' => '202-1-COT26',
        ]);
        NotaMpSeguimiento::query()->create([
            'nronota' => $ajena->nronota,
            'codigo_proceso' => '202-1-COT26',
            'estado_mp_codigo' => 'publicada',
            'estado_mp_glosa' => 'Publicada',
            'organismo' => 'Municipalidad Test',
            'resultado_propio' => 'pendiente',
            'finalizado' => false,
            'convocatoria_estado' => 2,
            'convocatoria_descripcion' => 'Segundo llamado',
            'fecha_cierre_segundo_llamado' => '2026-07-12 10:00:00',
            'ultimo_consultado_en' => now(),
        ]);

        $primerLlamado = $this->crearNota([
            'nronota' => 203,
            'usuario' => 'ejecutivo',
            'encargado' => '203-1-COT26',
        ]);
        NotaMpSeguimiento::query()->create([
            'nronota' => $primerLlamado->nronota,
            'codigo_proceso' => '203-1-COT26',
            'estado_mp_codigo' => 'publicada',
            'estado_mp_glosa' => 'Publicada',
            'organismo' => 'Municipalidad Test',
            'resultado_propio' => 'pendiente',
            'finalizado' => false,
            'convocatoria_estado' => 1,
            'convocatoria_descripcion' => 'Primer llamado',
            'ultimo_consultado_en' => now(),
        ]);

        $response = $this->actingAs($this->ejecutivo)->get(route('admin.cotizaciones.index'));

        $response->assertOk();
        $response->assertSee('hay 1 cotización lista para postular a segundo llamado.', false);
        $response->assertSee('Atención:', false);
        $response->assertSee('#201', false);
        $response->assertSee('201-1-COT26', false);
        $response->assertSee('cierre 2° llamado: 11/07/2026 21:38', false);
        $response->assertSee('2° llamado', false);
        $response->assertDontSee('#202', false);
    }

    public function test_listado_no_muestra_alerta_segundo_llamado_sin_coincidencias(): void
    {
        $this->crearNota(['nronota' => 210, 'usuario' => 'ejecutivo']);

        $response = $this->actingAs($this->ejecutivo)->get(route('admin.cotizaciones.index'));

        $response->assertOk();
        $response->assertDontSee('postular a segundo llamado', false);
    }

    public function test_admin_ve_columna_y_filtro_estado_mp(): void
    {
        $nota = $this->crearNota([
            'nronota' => 301,
            'usuario' => 'ejecutivo',
            'fecha' => now()->toDateString(),
        ]);
        NotaMpSeguimiento::query()->create([
            'nronota' => $nota->nronota,
            'codigo_proceso' => '301-1-COT26',
            'estado_mp_codigo' => 'proveedor_seleccionado',
            'estado_mp_glosa' => 'Proveedor seleccionado',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
            'ultimo_consultado_en' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.cotizaciones.index', [
            'fechadesde' => now()->subDay()->toDateString(),
            'fechahasta' => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('Estado MP', false);
        $response->assertSee('name="estado_mp"', false);
        $response->assertSee('Cerrada', false);
    }

    public function test_cerrada_muestra_columna_ganador_propio_con_destello_sin_rut(): void
    {
        config(['cotiz.empresa_rut' => '76.356.855-5']);

        $nota = $this->crearNota([
            'nronota' => 305,
            'usuario' => 'ejecutivo',
            'fecha' => now()->toDateString(),
            'encargado' => '305-GANADA',
        ]);
        NotaMpSeguimiento::query()->create([
            'nronota' => $nota->nronota,
            'codigo_proceso' => '305-GANADA',
            'resultado_propio' => 'cerrada',
            'rut_ganador' => '76356855-5',
            'razon_social_ganador' => 'Comercializadora Reicol SPA',
            'finalizado' => true,
            'ultimo_consultado_en' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.cotizaciones.index', [
            'fechadesde' => now()->subDay()->toDateString(),
            'fechahasta' => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('>Ganador propio</th>', false);
        $response->assertSee('badge-ganador-propio-destello', false);
        $response->assertSee('value="ganada_propio"', false);
        $response->assertDontSee('76356855', false);
        $response->assertDontSee('76.356.855', false);
    }

    public function test_filtro_ganada_propio_solo_muestra_ganadoras(): void
    {
        config(['cotiz.empresa_rut' => '76.356.855-5']);

        $propia = $this->crearNota([
            'nronota' => 306,
            'usuario' => 'ejecutivo',
            'fecha' => now()->toDateString(),
            'encargado' => '306-PROPIA',
        ]);
        NotaMpSeguimiento::query()->create([
            'nronota' => $propia->nronota,
            'codigo_proceso' => '306-PROPIA',
            'resultado_propio' => 'cerrada',
            'rut_ganador' => '76.356.855-5',
            'finalizado' => true,
            'ultimo_consultado_en' => now(),
        ]);

        $ajena = $this->crearNota([
            'nronota' => 307,
            'usuario' => 'ejecutivo',
            'fecha' => now()->toDateString(),
            'encargado' => '307-AJENA',
        ]);
        NotaMpSeguimiento::query()->create([
            'nronota' => $ajena->nronota,
            'codigo_proceso' => '307-AJENA',
            'resultado_propio' => 'cerrada',
            'rut_ganador' => '11.111.111-1',
            'finalizado' => true,
            'ultimo_consultado_en' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.cotizaciones.index', [
            'fechadesde' => now()->subDay()->toDateString(),
            'fechahasta' => now()->toDateString(),
            'estado_mp' => 'ganada_propio',
        ]));

        $response->assertOk();
        $response->assertSee('306-PROPIA', false);
        $response->assertDontSee('307-AJENA', false);
    }

    public function test_ejecutivo_no_ve_columna_ni_filtro_estado_mp(): void
    {
        $this->crearNota([
            'nronota' => 302,
            'usuario' => 'ejecutivo',
            'fecha' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->ejecutivo)->get(route('admin.cotizaciones.index', [
            'fechadesde' => now()->subDay()->toDateString(),
            'fechahasta' => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertDontSee('filtro-estado-mp', false);
        $response->assertDontSee('>Estado MP<', false);
    }

    public function test_pame_ve_filtro_estado_mp_y_puede_filtrar(): void
    {
        config(['cotiz.mercadopublico.oportunidades_viewers' => ['pame']]);

        $pame = User::factory()->create([
            'username' => 'pame',
            'nombre' => 'Pame',
            'apellidop' => 'G',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $cerrada = $this->crearNota([
            'nronota' => 303,
            'usuario' => 'pame',
            'fecha' => now()->toDateString(),
            'encargado' => '303-CERRADA',
        ]);
        NotaMpSeguimiento::query()->create([
            'nronota' => $cerrada->nronota,
            'codigo_proceso' => '303-CERRADA',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
            'ultimo_consultado_en' => now(),
        ]);

        $pendiente = $this->crearNota([
            'nronota' => 304,
            'usuario' => 'pame',
            'fecha' => now()->toDateString(),
            'encargado' => '304-PEND',
        ]);
        NotaMpSeguimiento::query()->create([
            'nronota' => $pendiente->nronota,
            'codigo_proceso' => '304-PEND',
            'resultado_propio' => 'pendiente',
            'finalizado' => false,
            'ultimo_consultado_en' => now(),
        ]);

        $response = $this->actingAs($pame)->get(route('admin.cotizaciones.index', [
            'fechadesde' => now()->subDay()->toDateString(),
            'fechahasta' => now()->toDateString(),
            'estado_mp' => 'cerrada',
        ]));

        $response->assertOk();
        $response->assertSee('Estado MP', false);
        $response->assertSee('303-CERRADA', false);
        $response->assertDontSee('304-PEND', false);
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
