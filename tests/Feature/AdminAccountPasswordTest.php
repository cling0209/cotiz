<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_change_password_with_valid_rules(): void
    {
        $admin = User::factory()->create([
            'correo' => 'admin@example.com',
            'perfil' => User::PERFIL_SUPERADMIN,
            'password' => 'Admin123!Secure',
        ]);

        $response = $this->actingAs($admin)->put(route('admin.account.password.update'), [
            'current_password' => 'Admin123!Secure',
            'password' => 'nueva1',
            'password_confirmation' => 'nueva1',
        ]);

        $response->assertRedirect(route('admin.account.password'));
        $response->assertSessionHas('success');

        $admin->refresh();
        $this->assertTrue(password_verify('nueva1', $admin->password));
    }

    public function test_ejecutivo_can_change_password(): void
    {
        $user = User::factory()->create([
            'perfil' => User::PERFIL_EJECUTIVO,
            'password' => 'Ejec123!Secure',
        ]);

        $response = $this->actingAs($user)->put(route('admin.account.password.update'), [
            'current_password' => 'Ejec123!Secure',
            'password' => 'nueva2',
            'password_confirmation' => 'nueva2',
        ]);

        $response->assertRedirect(route('admin.account.password'));
        $response->assertSessionHas('success');
    }

    public function test_admin_password_requires_letters_and_numbers(): void
    {
        $admin = User::factory()->create([
            'password' => 'Admin123!Secure',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $response = $this->actingAs($admin)->from(route('admin.account.password'))->put(route('admin.account.password.update'), [
            'current_password' => 'Admin123!Secure',
            'password' => 'sololetras',
            'password_confirmation' => 'sololetras',
        ]);

        $response->assertRedirect(route('admin.account.password'));
        $response->assertSessionHasErrors('password');
    }

    public function test_admin_password_rejects_too_long_value(): void
    {
        $admin = User::factory()->create([
            'password' => 'Admin123!Secure',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        $tooLong = 'a1'.str_repeat('x', 19);

        $response = $this->actingAs($admin)->from(route('admin.account.password'))->put(route('admin.account.password.update'), [
            'current_password' => 'Admin123!Secure',
            'password' => $tooLong,
            'password_confirmation' => $tooLong,
        ]);

        $response->assertRedirect(route('admin.account.password'));
        $response->assertSessionHasErrors('password');
    }
}
