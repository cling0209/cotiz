<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSesionVencidaTest extends TestCase
{
    use RefreshDatabase;

    public function test_llamada_ajax_sin_sesion_responde_401(): void
    {
        $response = $this->postJson(route('admin.oportunidades.para-cotizar.vincular-codigo'), [
            'codigo' => '5060-547-COT26',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_vuelve_a_la_pantalla_donde_vencio_la_sesion(): void
    {
        $user = User::factory()->create([
            'username' => 'ejecutivo1',
            'perfil' => User::PERFIL_EJECUTIVO,
            'password' => 'Ejec123!Secure',
        ]);

        $volver = '/admin/oportunidades/para-cotizar?pagina=2';

        $this->get(route('admin.login').'?volver='.urlencode($volver))->assertOk();

        $response = $this->post(route('admin.login.store'), [
            'username' => $user->username,
            'password' => 'Ejec123!Secure',
        ]);

        $response->assertRedirect(url($volver));
    }

    public function test_login_ignora_destino_de_retorno_externo(): void
    {
        $user = User::factory()->create([
            'username' => 'ejecutivo2',
            'perfil' => User::PERFIL_EJECUTIVO,
            'password' => 'Ejec123!Secure',
        ]);

        $this->get(route('admin.login').'?volver='.urlencode('https://sitio-externo.cl/robar'))
            ->assertOk();

        $response = $this->post(route('admin.login.store'), [
            'username' => $user->username,
            'password' => 'Ejec123!Secure',
        ]);

        $response->assertRedirect(route('admin.cotizaciones.index'));
    }
}
