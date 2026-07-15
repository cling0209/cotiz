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

        $user = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);
        OportunidadPalabraClave::query()->create(['frase' => 'aseo', 'created_by' => $user->id]);

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
            ->assertJsonPath('fin_label', now()->format('H:i:s'));

        Carbon::setTestNow();
    }

    public function test_iniciar_requiere_palabras(): void
    {
        config(['cotiz.mercadopublico.ticket' => 'ticket-test']);

        $user = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.iniciar'))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }
}
