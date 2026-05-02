<?php

namespace Tests\Feature\Api;

use App\Events\OrderStatusChanged;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    public function test_driver_status_transition_broadcasts_realtime_status_payload(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $driverUser = User::query()->create([
            'name' => 'Driver Broadcast',
            'email' => 'driver.broadcast@example.com',
            'phone' => '081211119995',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 3333 BRC',
            'license_number' => 'SIMC-BRC-2026',
            'registration_status' => 'active',
            'status' => 'available',
        ]);

        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $rideTypeId = (int) ServiceType::query()->where('code', 'RIDE')->value('id');
        $assignedStatusId = (int) OrderStatus::query()->where('code', 'DRIVER_ASSIGNED')->value('id');
        $arrivedPickupStatusId = (int) OrderStatus::query()->where('code', 'ARRIVED_PICKUP')->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-DRV-BRC-0001',
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $rideTypeId,
            'driver_id' => $driver->id,
            'address_id' => null,
            'delivery_address' => 'Jl. Tujuan Broadcast No. 8',
            'delivery_latitude' => -7.001200,
            'delivery_longitude' => 110.401200,
            'subtotal' => 0,
            'delivery_fee' => 15000,
            'service_fee' => 0,
            'total_amount' => 15000,
            'total_price' => 15000,
            'status_id' => $assignedStatusId,
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'ARRIVE_PICKUP',
            'target_status_code' => 'ARRIVED_PICKUP',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_code', 'ARRIVED_PICKUP');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status_id' => $arrivedPickupStatusId,
        ]);

        Event::assertDispatched(OrderStatusChanged::class, function (OrderStatusChanged $event) use ($order): bool {
            return $event->orderId === $order->id
                && $event->statusCode === 'ARRIVED_PICKUP'
                && $event->previousStatusCode === 'DRIVER_ASSIGNED'
                && $event->statusLabel === 'Driver Tiba di Titik Jemput'
                && $event->isTerminal === false
                && $event->historyId !== null;
        });
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

    public function test_driver_can_toggle_availability_online_and_offline(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver Availability',
            'email' => 'driver.availability@example.com',
            'phone' => '081211119993',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 1111 AVL',
            'license_number' => 'SIMC-AVL-2026',
            'registration_status' => 'active',
            'status' => 'offline',
        ]);

        Sanctum::actingAs($driverUser);

        $onlineResponse = $this->patchJson('/api/v1/driver/availability', [
            'is_online' => true,
        ]);

        $onlineResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.is_online', true);

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'status' => 'available',
        ]);

        $offlineResponse = $this->patchJson('/api/v1/driver/availability', [
            'is_online' => false,
        ]);

        $offlineResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'offline')
            ->assertJsonPath('data.is_online', false);

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'status' => 'offline',
        ]);
    }

    public function test_driver_cannot_go_offline_when_has_running_order(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver Busy',
            'email' => 'driver.busy@example.com',
            'phone' => '081211119994',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 2222 BSY',
            'license_number' => 'SIMC-BSY-2026',
            'registration_status' => 'active',
            'status' => 'available',
        ]);

        $customer = User::factory()->create([
            'role' => 'customer',
        ]);

        $shoppingTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');
        $assignedStatusId = (int) OrderStatus::query()->where('code', 'DRIVER_ASSIGNED')->value('id');

        Order::query()->create([
            'order_number' => 'BD-DRV-BSY-0001',
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $shoppingTypeId,
            'driver_id' => $driver->id,
            'address_id' => null,
            'delivery_address' => 'Jl. Busy Driver No. 1',
            'delivery_latitude' => -7.001100,
            'delivery_longitude' => 110.401100,
            'subtotal' => 12000,
            'delivery_fee' => 6000,
            'service_fee' => 0,
            'total_amount' => 18000,
            'total_price' => 18000,
            'status_id' => $assignedStatusId,
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);

        Sanctum::actingAs($driverUser);

        $response = $this->patchJson('/api/v1/driver/availability', [
            'is_online' => false,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Tidak bisa offline saat masih ada order berjalan.');

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'status' => 'available',
        ]);
    }
}
