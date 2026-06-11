<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCustomersTest extends TestCase
{
    use RefreshDatabase;

    protected function deleteAsAdmin(User $admin, User $target)
    {
        $this->actingAs($admin)->get(route('admin.customers.index'));

        return $this->delete(route('admin.customers.destroy', $target), [
            '_token' => session()->token(),
        ]);
    }

    public function test_admin_can_list_customers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create([
            'role' => 'customer',
            'email' => 'cliente@test.local',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.customers.index', ['q' => 'cliente@test']));

        $response->assertOk();
        $response->assertSee($customer->email);
    }

    public function test_admin_can_delete_customer_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);

        $response = $this->deleteAsAdmin($admin, $customer);

        $response->assertRedirect(route('admin.customers.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('users', ['id' => $customer->id]);
    }

    public function test_admin_cannot_delete_admin_via_customers_route(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        $response = $this->deleteAsAdmin($admin, $otherAdmin);

        $response->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id]);
    }

    public function test_deleting_customer_preserves_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id,
            'subtotal' => 10000,
            'total' => 10000,
            'shipping_recipient_name' => 'Cliente Test',
            'shipping_phone' => '912345678',
            'shipping_region' => 'Región Metropolitana',
            'shipping_comuna' => 'Santiago',
            'shipping_street' => 'Av. Test',
            'customer_email' => $customer->email,
            'customer_name' => $customer->name,
        ]);

        $this->deleteAsAdmin($admin, $customer);

        $this->assertDatabaseMissing('users', ['id' => $customer->id]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'user_id' => null,
        ]);
    }
}
