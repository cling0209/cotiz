<?php

namespace Tests\Feature;

use App\Models\OportunidadPalabraClave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OportunidadParaCotizarBusquedaTest extends TestCase
{
    use RefreshDatabase;

    public function test_paso_devuelve_solo_publicadas_hoy(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'America/Santiago'));

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil*' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'items' => [
                        [
                            'codigo' => '1000-1-COT26',
                            'nombre' => 'Hoy',
                            'fechas' => [
                                'fecha_publicacion' => '2026-07-15T09:00:00-04:00',
                                'fecha_cierre' => '2026-07-16T18:00:00-04:00',
                            ],
                            'montos' => ['monto_disponible_clp' => 500000],
                            'institucion' => ['region' => 13, 'comuna' => 'Santiago'],
                        ],
                        [
                            'codigo' => '1000-2-COT26',
                            'nombre' => 'Ayer',
                            'fechas' => [
                                'fecha_publicacion' => '2026-07-14T09:00:00-04:00',
                                'fecha_cierre' => '2026-07-16T18:00:00-04:00',
                            ],
                            'montos' => ['monto_disponible_clp' => 900000],
                            'institucion' => ['region' => 13, 'comuna' => 'Santiago'],
                        ],
                    ],
                    'paginacion' => [],
                ],
            ], 200),
        ]);

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
            ->postJson(route('admin.oportunidades.para-cotizar.paso'), [
                'frase' => 'aseo',
                'region' => 13,
                'indice' => 0,
                'total_pasos' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('terminado', true)
            ->assertJsonCount(1, 'nuevos')
            ->assertJsonPath('nuevos.0.codigo', '1000-1-COT26')
            ->assertJsonPath('fin_label', now()->format('H:i:s'))
            ->assertJsonPath('consulta.metodo', 'GET')
            ->assertJsonPath('consulta.parametros.q', 'aseo')
            ->assertJsonPath('consulta.parametros.region', 13)
            ->assertJsonPath('consulta.parametros.estado', 'publicada')
            ->assertJsonPath('consulta.total_api', 2)
            ->assertJsonPath('consulta.total_publicadas_hoy', 1)
            ->assertJsonPath('guardadas', 1);

        $this->assertDatabaseHas('oportunidad_encontradas', [
            'codigo' => '1000-1-COT26',
            'fecha_busqueda' => '2026-07-15',
        ]);
        $this->assertDatabaseMissing('oportunidad_encontradas', [
            'codigo' => '1000-2-COT26',
        ]);

        Carbon::setTestNow();
    }

    public function test_paso_error_incluye_consulta_debug(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13],
        ]);

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil*' => Http::response([], 503),
        ]);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.paso'), [
                'frase' => 'aseo',
                'region' => 13,
            ])
            ->assertStatus(502)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('consulta.metodo', 'GET')
            ->assertJsonPath('consulta.parametros.q', 'aseo')
            ->assertJsonPath('consulta.parametros.region', 13);
    }

    public function test_iniciar_requiere_palabras(): void
    {
        config(['cotiz.mercadopublico.ticket' => 'ticket-test']);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.iniciar'))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_iniciar_ordena_pasos_region_luego_palabra(): void
    {
        config([
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.regiones' => [13, 5],
        ]);

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
            ->postJson(route('admin.oportunidades.para-cotizar.iniciar'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('total_pasos', 4)
            ->assertJsonPath('pasos.0.region', 13)
            ->assertJsonPath('pasos.0.frase', 'papel')
            ->assertJsonPath('pasos.1.region', 13)
            ->assertJsonPath('pasos.1.frase', 'aseo')
            ->assertJsonPath('pasos.2.region', 5)
            ->assertJsonPath('pasos.2.frase', 'papel')
            ->assertJsonPath('pasos.3.region', 5)
            ->assertJsonPath('pasos.3.frase', 'aseo');
    }

    public function test_paso_omite_codigos_ya_en_lista(): void
    {
        config([
            'app.timezone' => 'America/Santiago',
            'cotiz.mercadopublico.ticket' => 'ticket-test',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.regiones' => [13],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'America/Santiago'));

        Http::fake([
            'api2.mercadopublico.cl/v2/compra-agil*' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'items' => [
                        [
                            'codigo' => '1000-1-COT26',
                            'nombre' => 'Ya listada',
                            'fechas' => [
                                'fecha_publicacion' => '2026-07-15T09:00:00-04:00',
                                'fecha_cierre' => '2026-07-16T18:00:00-04:00',
                            ],
                            'montos' => ['monto_disponible_clp' => 500000],
                            'institucion' => ['region' => 13, 'comuna' => 'Santiago'],
                        ],
                        [
                            'codigo' => '1000-3-COT26',
                            'nombre' => 'Nueva',
                            'fechas' => [
                                'fecha_publicacion' => '2026-07-15T10:00:00-04:00',
                                'fecha_cierre' => '2026-07-16T18:00:00-04:00',
                            ],
                            'montos' => ['monto_disponible_clp' => 300000],
                            'institucion' => ['region' => 13, 'comuna' => 'Santiago'],
                        ],
                    ],
                    'paginacion' => [],
                ],
            ], 200),
        ]);

        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.paso'), [
                'frase' => 'papel',
                'region' => 13,
                'codigos_excluidos' => ['1000-1-COT26'],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'nuevos')
            ->assertJsonPath('nuevos.0.codigo', '1000-3-COT26');

        Carbon::setTestNow();
    }
}
