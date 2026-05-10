<?php

namespace Tests\Feature\Api;

use App\Events\DriverOrderAvailable;
use App\Events\DriverOrderRemoved;
use App\Events\OrderStatusChanged;
use App\Models\CourierOrder;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\DriverOrderRealtimeService;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
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

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 4567 WFL',
            'license_number' => 'SIMC-WFL-2026',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

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

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'status' => 'busy',
        ]);
    }

    public function test_offline_driver_does_not_receive_incoming_orders_but_keeps_running_orders(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('offline-list');
        $driver->update(['status' => 'offline']);

        $pendingOrder = $this->createShoppingOrder(null, 'PENDING');
        $runningOrder = $this->createShoppingOrder($driver, 'DRIVER_ASSIGNED');

        Sanctum::actingAs($driverUser);

        $response = $this->getJson('/api/v1/driver/orders');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertCount(0, $response->json('data.incoming_orders'));
        $this->assertCount(1, $response->json('data.running_orders'));
        $this->assertSame((string) $runningOrder->id, (string) $response->json('data.running_orders.0.id'));
        $this->assertDatabaseHas('orders', [
            'id' => $pendingOrder->id,
            'driver_id' => null,
        ]);
    }

    public function test_available_driver_receives_incoming_orders(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('available-list');
        $driver->update(['status' => 'available']);

        $pendingOrder = $this->createShoppingOrder(null, 'PENDING');

        Sanctum::actingAs($driverUser);

        $response = $this->getJson('/api/v1/driver/orders');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertCount(1, $response->json('data.incoming_orders'));
        $this->assertSame((string) $pendingOrder->id, (string) $response->json('data.incoming_orders.0.id'));
    }

    public function test_driver_order_available_event_uses_same_payload_as_driver_orders_api(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('realtime-available');
        $driver->update(['status' => 'available']);

        $order = $this->createShoppingOrder(null, 'PENDING');
        $capturedPayload = null;

        Event::fake([DriverOrderAvailable::class]);

        app(DriverOrderRealtimeService::class)->broadcastOrderAvailable($order);

        Event::assertDispatched(DriverOrderAvailable::class, function (DriverOrderAvailable $event) use ($driverUser, &$capturedPayload): bool {
            $capturedPayload = $event->order;

            return (int) $event->driverUserId === (int) $driverUser->id;
        });

        Sanctum::actingAs($driverUser);

        $response = $this->getJson('/api/v1/driver/orders');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEquals($response->json('data.incoming_orders.0'), $capturedPayload);
        $this->assertSame((string) $order->id, (string) ($capturedPayload['id'] ?? ''));
    }

    public function test_accepting_order_broadcasts_removed_to_other_available_drivers(): void
    {
        [$acceptingDriverUser, $acceptingDriver] = $this->createActiveDriver('realtime-accepting');
        $acceptingDriver->update(['status' => 'available']);

        [$otherDriverUser, $otherDriver] = $this->createActiveDriver('realtime-other');
        $otherDriver->update(['status' => 'available']);

        $order = $this->createShoppingOrder(null, 'PENDING');

        Event::fake([DriverOrderRemoved::class]);
        Sanctum::actingAs($acceptingDriverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/accept');

        $response->assertOk()
            ->assertJsonPath('success', true);

        Event::assertDispatched(DriverOrderRemoved::class, function (DriverOrderRemoved $event) use ($otherDriverUser, $order): bool {
            return (int) $event->driverUserId === (int) $otherDriverUser->id &&
                (int) $event->orderId === (int) $order->id &&
                $event->reason === 'accepted';
        });
    }

    public function test_offline_driver_cannot_accept_pending_order(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('offline-accept');
        $driver->update(['status' => 'offline']);

        $order = $this->createShoppingOrder(null, 'PENDING');

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/accept');

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Aktifkan status kerja sebelum menerima order.');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'driver_id' => null,
        ]);

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'status' => 'offline',
        ]);
    }

    public function test_driver_becomes_available_after_completing_last_running_order(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('complete-availability');
        $driver->update(['status' => 'busy']);

        $order = $this->createCourierOrderForDriver($driver, 'DELIVERED', 21000);
        OrderPayment::query()
            ->where('order_id', $order->id)
            ->update([
                'payment_status' => 'PAID',
                'driver_id' => $driver->id,
                'recorded_by_user_id' => $driverUser->id,
                'paid_at' => now(),
            ]);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'COMPLETE_ORDER',
            'target_status_code' => 'COMPLETED',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_code', 'COMPLETED');

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'status' => 'available',
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

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 3333 BRC',
            'license_number' => 'SIMC-BRC-2026',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

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

    public function test_driver_location_update_still_saves_when_realtime_broadcast_fails(): void
    {
        $this->useFailingBroadcaster();

        $driverUser = User::query()->create([
            'name' => 'Driver Location',
            'email' => 'driver.location@example.com',
            'phone' => '081211119996',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 4444 LOC',
            'license_number' => 'SIMC-LOC-2026',
            'registration_status' => 'active',
            'status' => 'busy',
        ]));

        $customer = User::factory()->create(['role' => 'customer']);
        $rideTypeId = (int) ServiceType::query()->where('code', 'RIDE')->value('id');
        $assignedStatusId = (int) OrderStatus::query()->where('code', 'DRIVER_ASSIGNED')->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-DRV-LOC-0001',
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $rideTypeId,
            'driver_id' => $driver->id,
            'address_id' => null,
            'delivery_address' => 'Jl. Lokasi Driver No. 1',
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

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/location', [
            'latitude' => -7.123456,
            'longitude' => 110.654321,
            'heading' => 45.5,
        ])->assertOk()
            ->assertJsonPath('data.location_saved', true)
            ->assertJsonPath('data.broadcasted', false)
            ->assertJsonPath('data.order_id', $order->id);

        $driver->refresh();
        $this->assertEqualsWithDelta(-7.123456, (float) $driver->current_latitude, 0.000001);
        $this->assertEqualsWithDelta(110.654321, (float) $driver->current_longitude, 0.000001);

        $cached = Cache::get('driver_location:'.$driver->id);
        $this->assertIsArray($cached);
        $this->assertSame($order->id, $cached['order_id']);
        $this->assertSame(-7.123456, $cached['latitude']);
        $this->assertSame(110.654321, $cached['longitude']);
        $this->assertSame(45.5, $cached['heading']);
    }

    public function test_driver_cod_collection_enables_complete_order(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver COD',
            'email' => 'driver.cod@example.com',
            'phone' => '081211119997',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 5555 COD',
            'license_number' => 'SIMC-COD-2026',
            'registration_status' => 'active',
            'status' => 'busy',
        ]));

        $customer = User::factory()->create(['role' => 'customer']);
        $rideTypeId = (int) ServiceType::query()->where('code', 'RIDE')->value('id');
        $deliveredStatusId = (int) OrderStatus::query()->where('code', 'DELIVERED')->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-DRV-COD-0001',
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $rideTypeId,
            'driver_id' => $driver->id,
            'address_id' => null,
            'subtotal' => 0,
            'delivery_fee' => 25000,
            'service_fee' => 0,
            'total_amount' => 25000,
            'total_price' => 25000,
            'status_id' => $deliveredStatusId,
        ]);

        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => 25000,
        ]);

        Sanctum::actingAs($driverUser);

        $detailResponse = $this->getJson('/api/v1/driver/orders/'.$order->id);

        $detailResponse->assertOk()
            ->assertJsonPath('data.total_price', 25000)
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.available_actions.0.action_code', 'COMPLETE_ORDER')
            ->assertJsonPath('data.available_actions.0.blocked', true)
            ->assertJsonPath('data.available_actions.0.blocked_reason', 'Pembayaran COD belum dicatat.')
            ->assertJsonPath('data.available_actions.1.action_code', 'COLLECT_COD');

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'COMPLETE_ORDER',
            'target_status_code' => 'COMPLETED',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Pembayaran COD belum dicatat.');

        $this->postJson('/api/v1/orders/'.$order->id.'/payment/collect-cod', [
            'amount' => 25000,
            'note' => 'Tunai diterima driver.',
        ])->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PAID',
            'amount' => 25000,
            'recorded_by_user_id' => $driverUser->id,
            'driver_id' => $driver->id,
        ]);

        $paidDetailResponse = $this->getJson('/api/v1/driver/orders/'.$order->id);

        $paidDetailResponse->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.available_actions.0.action_code', 'COMPLETE_ORDER')
            ->assertJsonPath('data.available_actions.0.blocked', false);

        $this->assertCount(1, $paidDetailResponse->json('data.available_actions'));

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'COMPLETE_ORDER',
            'target_status_code' => 'COMPLETED',
        ])->assertOk()
            ->assertJsonPath('data.status_code', 'COMPLETED');
    }

    public function test_driver_cannot_pickup_courier_before_pickup_payment_is_paid(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('courier-gate');
        $order = $this->createCourierOrderForDriver($driver, 'ARRIVED_PICKUP', 18000);

        Sanctum::actingAs($driverUser);

        $detailResponse = $this->getJson('/api/v1/driver/orders/'.$order->id);

        $detailResponse->assertOk()
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.package_description', 'dokumen kontrak')
            ->assertJsonPath('data.available_actions.0.action_code', 'CONFIRM_PICKED_UP')
            ->assertJsonPath('data.available_actions.0.blocked', true)
            ->assertJsonPath('data.available_actions.1.action_code', 'REPORT_PACKAGE_INVALID')
            ->assertJsonPath('data.available_actions.2.action_code', 'COLLECT_COD');

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'CONFIRM_PICKED_UP',
            'target_status_code' => 'PICKED_UP',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Pembayaran COD belum dicatat.');
    }

    public function test_driver_can_collect_courier_cod_at_pickup_then_pickup_package(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('courier-paid');
        $order = $this->createCourierOrderForDriver($driver, 'ARRIVED_PICKUP', 19000);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/orders/'.$order->id.'/payment/collect-cod', [
            'amount' => 19000,
            'note' => 'Tunai diterima saat pickup.',
        ])->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('COURIER_PICKUP_COLLECTION', $payment->metadata['source'] ?? null);

        $paidDetailResponse = $this->getJson('/api/v1/driver/orders/'.$order->id);

        $paidDetailResponse->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.available_actions.0.action_code', 'CONFIRM_PICKED_UP')
            ->assertJsonPath('data.available_actions.0.blocked', false);

        $actionCodes = collect($paidDetailResponse->json('data.available_actions'))
            ->pluck('action_code')
            ->all();
        $this->assertNotContains('COLLECT_COD', $actionCodes);
        $this->assertNotContains('REPORT_PACKAGE_INVALID', $actionCodes);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'CONFIRM_PICKED_UP',
            'target_status_code' => 'PICKED_UP',
        ])->assertOk()
            ->assertJsonPath('data.status_code', 'PICKED_UP');
    }

    public function test_driver_cannot_collect_courier_cod_before_arrived_pickup(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('courier-early');
        $order = $this->createCourierOrderForDriver($driver, 'DRIVER_ASSIGNED', 20000);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/orders/'.$order->id.'/payment/collect-cod', [
            'amount' => 20000,
            'note' => 'Terlalu awal.',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Pembayaran COD courier hanya bisa dicatat saat driver tiba di pickup.');
    }

    public function test_driver_can_cancel_courier_at_pickup_when_package_invalid(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('courier-invalid');
        $order = $this->createCourierOrderForDriver($driver, 'ARRIVED_PICKUP', 21000);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'REPORT_PACKAGE_INVALID',
            'target_status_code' => 'CANCELLED',
            'note' => 'Barang lebih besar dari deskripsi dan tidak muat motor.',
        ])->assertOk()
            ->assertJsonPath('data.status_code', 'CANCELLED');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'cancelled_by' => 'driver',
            'cancellation_reason' => 'Barang lebih besar dari deskripsi dan tidak muat motor.',
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

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 9991 HST',
            'license_number' => 'SIMC-HST-2026',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

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

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 1111 AVL',
            'license_number' => 'SIMC-AVL-2026',
            'registration_status' => 'active',
            'status' => 'offline',
        ]));

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

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 2222 BSY',
            'license_number' => 'SIMC-BSY-2026',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

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

    /**
     * @return array{0: User, 1: Driver}
     */
    private function createActiveDriver(string $suffix): array
    {
        $driverUser = User::factory()->create([
            'name' => 'Driver '.ucwords(str_replace('-', ' ', $suffix)),
            'email' => $suffix.'@driver.test',
            'phone' => '0899'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'H '.random_int(1000, 9999).' TST',
            'license_number' => 'SIMC-'.strtoupper($suffix),
            'registration_status' => 'active',
            'status' => 'busy',
        ]));

        return [$driverUser, $driver];
    }

    private function createShoppingOrder(?Driver $driver, string $statusCode): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $shoppingTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');
        $statusId = (int) OrderStatus::query()->where('code', $statusCode)->value('id');

        return Order::query()->create([
            'order_number' => 'BD-SHP-'.strtoupper(substr(md5($statusCode.random_int(1, 999999)), 0, 8)),
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $shoppingTypeId,
            'driver_id' => $driver?->id,
            'address_id' => null,
            'delivery_address' => 'Jl. Test Driver Order No. '.random_int(1, 99),
            'delivery_latitude' => -7.001234,
            'delivery_longitude' => 110.401234,
            'subtotal' => 12000,
            'delivery_fee' => 6000,
            'service_fee' => 0,
            'total_amount' => 18000,
            'total_price' => 18000,
            'status_id' => $statusId,
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);
    }

    private function createCourierOrderForDriver(Driver $driver, string $statusCode, int $amount): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $courierTypeId = (int) ServiceType::query()->where('code', 'COURIER')->value('id');
        $statusId = (int) OrderStatus::query()->where('code', $statusCode)->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-COU-'.strtoupper(substr(md5($statusCode.$amount.random_int(1, 9999)), 0, 8)),
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $courierTypeId,
            'driver_id' => $driver->id,
            'address_id' => null,
            'subtotal' => 0,
            'delivery_fee' => $amount,
            'service_fee' => 0,
            'total_amount' => $amount,
            'total_price' => $amount,
            'status_id' => $statusId,
        ]);

        CourierOrder::query()->create([
            'order_id' => $order->id,
            'package_description' => 'dokumen kontrak',
            'estimated_weight_kg' => 1.5,
            'package_length_cm' => 30,
            'package_width_cm' => 20,
            'package_height_cm' => 5,
            'package_size_class' => 'SMALL',
            'package_safety_status' => 'ALLOWED',
            'package_safety_flags' => [],
            'package_safety_reason' => 'Paket aman untuk layanan kurir motor.',
            'requires_photo_evidence' => true,
        ]);

        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => $amount,
        ]);

        return $order;
    }

    private function useFailingBroadcaster(): void
    {
        $previousDefault = Config::get('broadcasting.default');

        Broadcast::extend('failing-test', function (): BroadcasterContract {
            return new class implements BroadcasterContract
            {
                public function auth($request)
                {
                    return null;
                }

                public function validAuthenticationResponse($request, $result)
                {
                    return $result;
                }

                public function broadcast(array $channels, $event, array $payload = []): void
                {
                    throw new BroadcastException('Forced broadcast failure.');
                }
            };
        });

        Config::set('broadcasting.default', 'failing-test');
        Broadcast::forgetDrivers();

        $this->beforeApplicationDestroyed(function () use ($previousDefault): void {
            Config::set('broadcasting.default', $previousDefault);
            Broadcast::forgetDrivers();
        });
    }
}
