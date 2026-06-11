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
            'email' => 'admin@carro.local',
            'password' => 'Admin123!Secure',
            'role' => 'admin',
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

    public function test_admin_password_requires_letters_and_numbers(): void
    {
        $admin = User::factory()->create([
            'password' => 'Admin123!Secure',
            'role' => 'admin',
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
            'role' => 'admin',
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
