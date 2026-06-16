<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cotiz.api_nota.user' => 'api_sync_user',
            'cotiz.api_nota.password' => 'api_sync_secret',
            'cotiz.api_usuario.url' => 'https://peer.test/api/v1/usuario',
            'cotiz.sistema' => 'Romulo',
        ]);
    }

    public function test_usuario_api_rejects_without_auth(): void
    {
        $this->postJson('/api/v1/usuario', ['accion' => 'graba'])
            ->assertStatus(401)
            ->assertJson(['resultado' => 'ERROR']);
    }

    public function test_usuario_api_graba_creates_user(): void
    {
        $response = $this->withBasicAuth('api_sync_user', 'api_sync_secret')
            ->postJson('/api/v1/usuario', [
                'accion' => 'graba',
                'replicacion' => true,
                'origen_sistema' => 'Reicol',
                'username' => 'nuevo1',
                'nombre' => 'Nuevo',
                'apellidop' => 'Usuario',
                'correo' => 'nuevo@test.cl',
                'perfil' => User::PERFIL_EJECUTIVO,
                'password' => 'Clave1234',
            ]);

        $response->assertOk();
        $response->assertJson([
            'resultado' => 'OK',
            'created' => true,
            'username' => 'nuevo1',
        ]);

        $this->assertDatabaseHas('users', [
            'username' => 'nuevo1',
            'nombre' => 'Nuevo',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
    }

    public function test_usuario_api_graba_is_idempotent_when_username_exists(): void
    {
        User::factory()->create(['username' => 'exist01']);

        $response = $this->withBasicAuth('api_sync_user', 'api_sync_secret')
            ->postJson('/api/v1/usuario', [
                'accion' => 'graba',
                'username' => 'exist01',
                'nombre' => 'Otro',
                'perfil' => User::PERFIL_EJECUTIVO,
                'password' => 'Clave1234',
            ]);

        $response->assertOk();
        $response->assertJson([
            'resultado' => 'OK',
            'created' => false,
        ]);

        $this->assertSame(1, User::query()->where('username', 'exist01')->count());
    }

    public function test_admin_store_replicates_user_to_peer(): void
    {
        Http::fake([
            'https://peer.test/api/v1/usuario' => Http::response([
                'resultado' => 'OK',
                'mensaje' => 'Usuario creado',
                'username' => 'sync01',
                'created' => true,
            ], 200),
        ]);

        $admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'username' => 'sync01',
            'nombre' => 'Sync',
            'apellidop' => 'Test',
            'correo' => 'sync@test.cl',
            'perfil' => User::PERFIL_EJECUTIVO,
            'password' => 'Clave1234',
            'password_confirmation' => 'Clave1234',
        ])->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://peer.test/api/v1/usuario'
                && $request['accion'] === 'graba'
                && $request['username'] === 'sync01'
                && $request['password'] === 'Clave1234'
                && $request['replicacion'] === true;
        });

        $this->assertDatabaseHas('users', ['username' => 'sync01']);
    }

    public function test_admin_store_shows_warning_when_peer_replication_fails(): void
    {
        Http::fake([
            'https://peer.test/api/v1/usuario' => Http::response([
                'resultado' => 'ERROR',
                'mensaje' => 'Peer caído',
            ], 400),
        ]);

        $admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'username' => 'local01',
            'nombre' => 'Local',
            'perfil' => User::PERFIL_EJECUTIVO,
            'password' => 'Clave1234',
            'password_confirmation' => 'Clave1234',
        ])->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success')
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('users', ['username' => 'local01']);
    }
}
