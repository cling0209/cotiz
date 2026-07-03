<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\NotaMpCorrida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompraAgilResultadosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cotiz.mercadopublico.ticket' => 'test-ticket',
            'cotiz.mercadopublico.resultados_admin_habilitado' => true,
            'cotiz.mercadopublico.resultados_delay_ms' => 0,
            'cotiz.empresa_rut' => '76.779.675-7',
        ]);
    }

    public function test_solo_admin_ve_menu_resultados(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);
        $otro = User::factory()->create(['username' => 'ejecutivo1', 'perfil' => User::PERFIL_SUPERADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.compra-agil.resultados.index'))
            ->assertOk()
            ->assertSee('Resultados Compra Ágil', false);

        $this->actingAs($otro)
            ->get(route('admin.compra-agil.resultados.index'))
            ->assertForbidden();
    }

    public function test_encolar_corrida_procesa_nota_y_guarda_seguimiento(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);
        $nota = Nota::query()->create([
            'nronota' => 501,
            'descripcion' => 'Test resultados MP',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => 'Cliente test',
            'encargado' => '3300-66-COT26',
            'nota_softland' => 50100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil/3300-66-COT26' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'codigo' => '3300-66-COT26',
                    'estado' => ['codigo' => 'proveedor_seleccionado', 'glosa' => 'Proveedor seleccionado'],
                    'id_orden_compra' => 55070937,
                    'institucion' => ['organismo_comprador' => 'Municipalidad'],
                    'proveedores_cotizando' => [
                        [
                            'id_cotizacion' => 1,
                            'rut_proveedor' => '76.779.675-7',
                            'razon_social' => 'INTEGRAMUNDO SPA',
                            'proveedor_seleccionado' => 1,
                            'activo' => 1,
                            'id_oc' => 55070937,
                            'monto_total' => 298809,
                            'productos_cotizados' => [
                                ['codigo_producto' => '14111537', 'descripcion' => 'Etiqueta', 'cantidad' => 5, 'precio_unitario' => 2100, 'monto_total_producto' => 10500],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.iniciar'))
            ->assertOk()
            ->assertJsonPath('estado.en_curso', false);

        $this->assertDatabaseHas('nota_mp_seguimientos', [
            'nronota' => $nota->nronota,
            'rut_ganador' => '76779675-7',
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);

        $this->assertDatabaseHas('nota_mp_corridas', [
            'estado' => 'ok',
            'notas_procesadas' => 1,
            'total_notas' => 1,
        ]);
    }

    public function test_no_permite_dos_corridas_en_paralelo(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now(),
            'estado' => 'running',
            'total_notas' => 5,
            'notas_procesadas' => 1,
            'pendientes_json' => [['nronota' => 1, 'codigo' => '1-1-COT26']],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.iniciar'))
            ->assertStatus(409)
            ->assertJsonPath('estado.en_curso', true);
    }

    public function test_estado_expone_progreso_de_corrida_activa(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now(),
            'estado' => 'running',
            'total_notas' => 10,
            'notas_procesadas' => 3,
            'codigo_actual' => '3300-66-COT26',
            'pendientes_json' => [],
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.compra-agil.resultados.estado'))
            ->assertOk()
            ->assertJsonPath('en_curso', true)
            ->assertJsonPath('procesadas', 3)
            ->assertJsonPath('total', 10)
            ->assertJsonPath('codigo_actual', '3300-66-COT26');
    }

    public function test_cancelar_corrida_en_curso(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        NotaMpCorrida::query()->create([
            'usuario' => 'admin',
            'inicio' => now(),
            'estado' => 'running',
            'total_notas' => 10,
            'notas_procesadas' => 2,
            'codigo_actual' => '3300-66-COT26',
            'pendientes_json' => [['nronota' => 1, 'codigo' => '1-1-COT26']],
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.cancelar'))
            ->assertOk()
            ->assertJsonPath('estado.en_curso', false);

        $this->assertDatabaseHas('nota_mp_corridas', [
            'estado' => 'cancelled',
            'notas_procesadas' => 2,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.compra-agil.resultados.cancelar'))
            ->assertStatus(422);
    }
}
