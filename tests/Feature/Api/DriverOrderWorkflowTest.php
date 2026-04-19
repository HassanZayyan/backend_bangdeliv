<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_accept_pending_order(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver Workflow',
            'email' => 'driver.workflow@example.com',
            'phone' => '081211119991',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 4567 WFL',
            'license_number' => 'SIMC-WFL-2026',
            'registration_status' => 'active',
            'status' => 'available',
        ]);

        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $shoppingTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');
        $pendingStatusId = (int) OrderStatus::query()->where('code', 'PENDING')->value('id');
        $assignedStatusId = (int) OrderStatus::query()->where('code', 'DRIVER_ASSIGNED')->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-DRV-ACC-0001',
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $shoppingTypeId,
            'driver_id' => null,
            'address_id' => null,
            'delivery_address' => 'Jl. Merdeka No. 7, Semarang',
            'delivery_latitude' => -7.005145,
            'delivery_longitude' => 110.438125,
            'subtotal' => 10000,
            'delivery_fee' => 7000,
            'service_fee' => 0,
            'total_amount' => 17000,
            'total_price' => 17000,
            'status_id' => $pendingStatusId,
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/accept');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_code', 'DRIVER_ASSIGNED');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'driver_id' => $driver->id,
            'status_id' => $assignedStatusId,
        ]);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status_id' => $assignedStatusId,
            'event_type' => 'STATUS_CHANGE',
            'changed_by_user_id' => $driverUser->id,
        ]);
    }

    public function test_driver_history_returns_completed_order(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver History',
            'email' => 'driver.history@example.com',
            'phone' => '081211119992',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 9991 HST',
            'license_number' => 'SIMC-HST-2026',
            'registration_status' => 'active',
            'status' => 'available',
        ]);

        $customer = User::factory()->create([
            'name' => 'Customer Riwayat',
            'role' => 'customer',
        ]);

        $shoppingTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');
        $completedStatusId = (int) OrderStatus::query()->where('code', 'COMPLETED')->value('id');

        Order::query()->create([
            'order_number' => 'BD-DRV-HST-0001',
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $shoppingTypeId,
            'driver_id' => $driver->id,
            'address_id' => null,
            'delivery_address' => 'Jl. Pandanaran No. 3, Semarang',
            'delivery_latitude' => -6.993200,
            'delivery_longitude' => 110.420300,
            'subtotal' => 15000,
            'delivery_fee' => 6000,
            'service_fee' => 0,
            'total_amount' => 21000,
            'total_price' => 21000,
            'status_id' => $completedStatusId,
            'payment_status' => 'paid',
            'payment_method' => 'COD',
            'delivered_at' => now()->subMinutes(10),
        ]);

        Sanctum::actingAs($driverUser);

        $response = $this->getJson('/api/v1/driver/history');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.history_orders.0.customer_name', 'Customer Riwayat')
            ->assertJsonPath('data.history_orders.0.status', 'Selesai');
    }
}
