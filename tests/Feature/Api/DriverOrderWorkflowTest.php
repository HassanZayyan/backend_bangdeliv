<?php

namespace Tests\Feature\Api;

use App\Events\DriverLocationUpdated;
use App\Events\DriverOrderAvailable;
use App\Events\DriverOrderRemoved;
use App\Events\OrderStatusChanged;
use App\Models\Address;
use App\Models\CourierOrder;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderItem;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Driver\Dispatch\DriverDispatchMetadataFactory;
use App\Services\Driver\DriverOrderRealtimeService;
use Database\Seeders\AccessAccountSeeder;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_order_availability_events_are_immediate_broadcasts(): void
    {
        $available = new DriverOrderAvailable(10, ['id' => 1]);
        $removed = new DriverOrderRemoved(10, 1, 'accepted_by_other_driver');

        $this->assertInstanceOf(ShouldBroadcast::class, $available);
        $this->assertInstanceOf(ShouldBroadcastNow::class, $available);
        $this->assertInstanceOf(ShouldBroadcast::class, $removed);
        $this->assertInstanceOf(ShouldBroadcastNow::class, $removed);
    }

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
            'status_id' => $assignedStatusId,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'driver_id' => $driver->id,
        ]);
        $this->assertNotNull($order->fresh()->assigned_at);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status_id' => $assignedStatusId,
            'changed_by_user_id' => $driverUser->id,
        ]);
        $acceptEvent = OrderStatusHistory::query()
            ->where('order_id', $order->id)
            ->where('status_id', $assignedStatusId)
            ->firstOrFail();
        $driverSnapshot = $acceptEvent->price_snapshot['driver_snapshot'] ?? [];
        $this->assertSame($driver->id, $driverSnapshot['driver_id'] ?? null);
        $this->assertSame($driverUser->id, $driverSnapshot['user_id'] ?? null);
        $this->assertSame('Driver Workflow', $driverSnapshot['name'] ?? null);
        $this->assertSame('081211119991', $driverSnapshot['phone'] ?? null);
        $this->assertSame('B 4567 WFL', $driverSnapshot['vehicle_plate'] ?? null);

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
        $this->assertDatabaseMissing('orders', [
            'id' => $pendingOrder->id,
            'driver_id' => $driver->id,
        ]);
    }

    public function test_assigned_driver_can_update_live_location_for_tracking(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('live-location');
        $order = $this->createShoppingOrder($driver, 'DRIVER_ASSIGNED');

        Event::fake([DriverLocationUpdated::class]);
        Sanctum::actingAs($driverUser);

        $response = $this->patchJson('/api/v1/driver/orders/'.$order->id.'/location', [
            'latitude' => -7.0551234,
            'longitude' => 110.4359876,
            'updated_at' => '2026-06-08T14:10:00+07:00',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.latitude', -7.0551234)
            ->assertJsonPath('data.longitude', 110.4359876)
            ->assertJsonMissingPath('data.heading');

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'latitude' => -7.0551234,
            'longitude' => 110.4359876,
        ]);

        Event::assertDispatched(
            DriverLocationUpdated::class,
            fn (DriverLocationUpdated $event): bool => (int) $event->orderId === (int) $order->id
                && (float) $event->latitude === -7.0551234
                && (float) $event->longitude === 110.4359876
        );
    }

    public function test_driver_location_update_rejects_other_driver_and_non_trackable_status(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('live-location-owner');
        [$otherDriverUser] = $this->createActiveDriver('live-location-other');
        $runningOrder = $this->createShoppingOrder($driver, 'DRIVER_ASSIGNED');
        $pendingOrder = $this->createShoppingOrder(null, 'PENDING');

        Sanctum::actingAs($otherDriverUser);

        $this->patchJson('/api/v1/driver/orders/'.$runningOrder->id.'/location', [
            'latitude' => -7.0551234,
            'longitude' => 110.4359876,
        ])
            ->assertForbidden();

        Sanctum::actingAs($driverUser);

        $this->patchJson('/api/v1/driver/orders/'.$pendingOrder->id.'/location', [
            'latitude' => -7.0551234,
            'longitude' => 110.4359876,
        ])
            ->assertForbidden();

        $deliveredOrder = $this->createShoppingOrder($driver, 'DELIVERED');

        $this->patchJson('/api/v1/driver/orders/'.$deliveredOrder->id.'/location', [
            'latitude' => -7.0551234,
            'longitude' => 110.4359876,
        ])
            ->assertConflict();
    }

    public function test_customer_order_detail_includes_latest_driver_location(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('customer-location');
        $order = $this->createShoppingOrder($driver, 'DRIVER_ASSIGNED');

        Sanctum::actingAs($driverUser);

        $this->patchJson('/api/v1/driver/orders/'.$order->id.'/location', [
            'latitude' => -7.0560001,
            'longitude' => 110.4320002,
        ])->assertOk();

        Sanctum::actingAs(User::query()->findOrFail($order->user_id));

        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.driver.latitude', '-7.0560001')
            ->assertJsonPath('data.driver.longitude', '110.4320002')
            ->assertJsonMissingPath('data.driver.heading');
    }

    public function test_customer_order_detail_derives_zaky_driver_contact_from_driver_user_relation(): void
    {
        $this->seed(AccessAccountSeeder::class);

        $driverUser = User::query()->where('email', 'zaky@gmail.com')->firstOrFail();
        $driver = Driver::query()->where('user_id', $driverUser->id)->firstOrFail();
        $order = $this->createShoppingOrder($driver, 'DRIVER_ASSIGNED');

        Sanctum::actingAs(User::query()->findOrFail($order->user_id));

        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.driver.user.name', 'Zaky Driver')
            ->assertJsonPath('data.driver.user.phone', '081399990002');
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

    public function test_driver_order_payloads_include_customer_avatar_url(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/customer.jpg', 'customer-avatar');

        [$driverUser, $driver] = $this->createActiveDriver('customer-avatar');
        $driver->update(['status' => 'available']);
        $order = $this->createShoppingOrder(null, 'PENDING');
        $order->user()->update(['avatar' => 'avatars/customer.jpg']);

        Sanctum::actingAs($driverUser);

        $listResponse = $this->getJson('/api/v1/driver/orders');
        $listResponse->assertOk();
        $this->assertStringContainsString(
            '/storage/avatars/customer.jpg',
            (string) $listResponse->json('data.incoming_orders.0.customer_avatar_url')
        );

        $detailResponse = $this->getJson('/api/v1/driver/orders/'.$order->id);
        $detailResponse->assertOk();
        $this->assertStringContainsString(
            '/storage/avatars/customer.jpg',
            (string) $detailResponse->json('data.customer_avatar_url')
        );

        $acceptResponse = $this->postJson('/api/v1/driver/orders/'.$order->id.'/accept');
        $acceptResponse->assertOk();
        $this->assertStringContainsString(
            '/storage/avatars/customer.jpg',
            (string) $acceptResponse->json('data.customer_avatar_url')
        );
    }

    public function test_customer_order_detail_includes_driver_avatar_url(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/driver.jpg', 'driver-avatar');

        [$driverUser, $driver] = $this->createActiveDriver('driver-avatar');
        $driverUser->update(['avatar' => 'avatars/driver.jpg']);
        $order = $this->createShoppingOrder($driver, 'DRIVER_ASSIGNED');
        $customer = $order->user()->firstOrFail();

        Sanctum::actingAs($customer);

        $response = $this->getJson('/api/v1/orders/'.$order->id);
        $response->assertOk();
        $this->assertStringContainsString(
            '/storage/avatars/driver.jpg',
            (string) $response->json('data.driver_avatar_url')
        );
        $this->assertStringContainsString(
            '/storage/avatars/driver.jpg',
            (string) $response->json('data.driver.user.avatar_url')
        );
    }

    public function test_available_driver_can_update_standby_location_without_order(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('standby-location');
        $driver->update(['status' => 'available']);

        Sanctum::actingAs($driverUser);

        $response = $this->patchJson('/api/v1/driver/location', [
            'latitude' => -7.3305001,
            'longitude' => 110.5084002,
            'updated_at' => '2026-06-15T10:00:00+07:00',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.driver_id', $driver->id)
            ->assertJsonPath('data.latitude', -7.3305001)
            ->assertJsonPath('data.longitude', 110.5084002);

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'latitude' => -7.3305001,
            'longitude' => 110.5084002,
        ]);

        $driver->update(['status' => 'offline']);

        $this->patchJson('/api/v1/driver/location', [
            'latitude' => -7.3305001,
            'longitude' => 110.5084002,
        ])->assertConflict();
    }

    public function test_driver_orders_include_dispatch_metadata_and_prioritize_nearest_customer_target(): void
    {
        Config::set('bangdeliv.dispatch.fresh_location_minutes', 10);

        [$driverUser, $driver] = $this->createActiveDriver('dispatch-list');
        $driver->update([
            'status' => 'available',
            'latitude' => -7.3305,
            'longitude' => 110.5084,
            'location_updated_at' => now(),
        ]);

        $nearOrder = $this->createShoppingOrder(null, 'PENDING');
        $this->createPickupLocation($nearOrder, -7.4500, 110.6500, 'Merchant Jauh');
        $this->createDropoffLocation($nearOrder, -7.3310, 110.5090, 'Customer Dekat Salatiga');

        $farOrder = $this->createShoppingOrder(null, 'PENDING');
        $this->createPickupLocation($farOrder, -7.3310, 110.5090, 'Merchant Dekat');
        $this->createDropoffLocation($farOrder, -7.4500, 110.6500, 'Customer Jauh Kabupaten Semarang');

        Sanctum::actingAs($driverUser);

        $response = $this->getJson('/api/v1/driver/orders');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.incoming_orders.0.id', (string) $nearOrder->id)
            ->assertJsonPath('data.incoming_orders.0.dispatch.distance_bucket', 'NEAR')
            ->assertJsonPath('data.incoming_orders.0.dispatch.priority_rank', 1)
            ->assertJsonPath('data.incoming_orders.0.dispatch.distance_target_role', 'customer_pickup')
            ->assertJsonPath('data.incoming_orders.0.dispatch.distance_target_label', 'titik jemput')
            ->assertJsonPath('data.incoming_orders.0.dispatch.location_fresh', true);

        $this->assertSame((string) $farOrder->id, (string) $response->json('data.incoming_orders.1.id'));
        $this->assertIsNumeric($response->json('data.incoming_orders.0.dispatch.distance_to_pickup_km'));
        $this->assertIsNumeric($response->json('data.incoming_orders.0.dispatch.distance_to_customer_km'));
        $this->assertStringContainsString('dari titik jemput', (string) $response->json('data.incoming_orders.0.dispatch.distance_label'));
        $this->assertLessThan(
            $response->json('data.incoming_orders.1.dispatch.distance_to_pickup_km'),
            $response->json('data.incoming_orders.0.dispatch.distance_to_pickup_km')
        );
    }

    public function test_dispatch_distance_targets_customer_order_point_for_all_service_types(): void
    {
        [, $driver] = $this->createActiveDriver('dispatch-target');
        $driver->update([
            'latitude' => -7.3305,
            'longitude' => 110.5084,
            'location_updated_at' => now(),
        ]);

        $factory = app(DriverDispatchMetadataFactory::class);

        $rideOrder = $this->createRideOrderForDriver($driver, 'DRIVER_ASSIGNED');
        $this->createPickupLocation($rideOrder, -7.3310, 110.5090, 'Ride Pickup Dekat');
        $this->createDropoffLocation($rideOrder, -7.4500, 110.6500, 'Ride Dropoff Jauh');

        $courierOrder = $this->createCourierOrderForDriver($driver, 'DRIVER_ASSIGNED', 20000);
        $this->createPickupLocation($courierOrder, -7.3310, 110.5090, 'Courier Pickup Dekat');
        $this->createDropoffLocation($courierOrder, -7.4500, 110.6500, 'Courier Dropoff Jauh');

        $shoppingOrder = $this->createShoppingOrder($driver, 'PENDING');
        $this->createShoppingPickupWithItem($shoppingOrder, 1, 'Merchant 1 Jauh', -7.4500, 110.6500, 'Item Merchant 1');
        $this->createShoppingPickupWithItem($shoppingOrder, 2, 'Merchant 2 Jauh', -7.4600, 110.6600, 'Item Merchant 2');
        $this->createShoppingPickupWithItem($shoppingOrder, 3, 'Merchant 3 Jauh', -7.4700, 110.6700, 'Item Merchant 3');
        $this->createDropoffLocation($shoppingOrder, -7.3310, 110.5090, 'Customer Dropoff Dekat');

        foreach ([$rideOrder, $courierOrder, $shoppingOrder] as $order) {
            $metadata = $factory->forDriver($order->fresh(['serviceType', 'orderLocations']), $driver);

            $this->assertSame('customer_pickup', $metadata['distance_target_role']);
            $this->assertSame('titik jemput', $metadata['distance_target_label']);
            $this->assertStringContainsString('dari titik jemput', (string) $metadata['distance_label']);
            $this->assertLessThan(1, (float) $metadata['distance_to_pickup_km']);
        }
    }

    public function test_customer_order_detail_includes_driver_eta_for_ride_assigned_order(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-google-key');
        Config::set('bangdeliv.dispatch.fresh_location_minutes', 10);
        $this->fakeEtaRouteResponse(durationSeconds: 480, distanceMeters: 2100);

        [, $driver] = $this->createActiveDriver('ride-eta');
        $driver->update([
            'latitude' => -7.3305,
            'longitude' => 110.5084,
            'location_updated_at' => now(),
        ]);

        $order = $this->createRideOrderForDriver($driver, 'DRIVER_ASSIGNED');
        $this->createPickupLocation($order, -7.3310, 110.5090, 'Rumah Customer');
        $this->createDropoffLocation($order, -7.3400, 110.5200, 'Kampus Tujuan');

        Sanctum::actingAs($order->user);

        $response = $this->getJson('/api/v1/orders/'.$order->id);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.driver_eta.target', 'PICKUP')
            ->assertJsonPath('data.driver_eta.target_label', 'Titik jemput')
            ->assertJsonPath('data.driver_eta.duration_seconds', 480)
            ->assertJsonPath('data.driver_eta.duration_text', '8 menit')
            ->assertJsonPath('data.driver_eta.distance_meters', 2100)
            ->assertJsonPath('data.driver_eta.distance_text', '2.10 km')
            ->assertJsonPath('data.driver_eta.location_fresh', true)
            ->assertJsonPath('data.driver_eta.route_provider', 'routes_api');
    }

    public function test_customer_order_detail_hides_driver_eta_after_ride_pickup_arrival(): void
    {
        [, $driver] = $this->createActiveDriver('ride-arrived-eta');
        $driver->update([
            'latitude' => -7.3305,
            'longitude' => 110.5084,
            'location_updated_at' => now(),
        ]);

        $order = $this->createRideOrderForDriver($driver, 'ARRIVED_PICKUP');
        $this->createPickupLocation($order, -7.3310, 110.5090, 'Rumah Customer');

        Sanctum::actingAs($order->user);

        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.driver_eta', null);
    }

    public function test_customer_order_detail_includes_driver_eta_for_courier_assigned_order(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-google-key');
        $this->fakeEtaRouteResponse(durationSeconds: 660, distanceMeters: 3200);

        [, $driver] = $this->createActiveDriver('courier-eta');
        $driver->update([
            'latitude' => -7.3305,
            'longitude' => 110.5084,
            'location_updated_at' => now(),
        ]);

        $order = $this->createCourierOrderForDriver($driver, 'DRIVER_ASSIGNED', 20000);
        $this->createPickupLocation($order, -7.3310, 110.5090, 'Pickup Paket');
        $this->createDropoffLocation($order, -7.3400, 110.5200, 'Tujuan Paket');

        Sanctum::actingAs($order->user);

        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.driver_eta.target', 'PICKUP')
            ->assertJsonPath('data.driver_eta.duration_seconds', 660)
            ->assertJsonPath('data.driver_eta.distance_meters', 3200);
    }

    public function test_customer_order_detail_includes_shopping_eta_only_when_driver_heads_to_customer(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-google-key');
        $this->fakeEtaRouteResponse(durationSeconds: 720, distanceMeters: 4100);

        [, $driver] = $this->createActiveDriver('shopping-eta');
        $driver->update([
            'latitude' => -7.3305,
            'longitude' => 110.5084,
            'location_updated_at' => now(),
        ]);

        $assignedOrder = $this->createShoppingOrder($driver, 'DRIVER_ASSIGNED');
        $this->createPickupLocation($assignedOrder, -7.3310, 110.5090, 'Merchant');
        $this->createDropoffLocation($assignedOrder, -7.3400, 110.5200, 'Alamat Customer');

        Sanctum::actingAs($assignedOrder->user);

        $this->getJson('/api/v1/orders/'.$assignedOrder->id)
            ->assertOk()
            ->assertJsonPath('data.driver_eta', null);

        $onTheWayOrder = $this->createShoppingOrder($driver, 'ON_THE_WAY');
        $this->createPickupLocation($onTheWayOrder, -7.3310, 110.5090, 'Merchant');
        $this->createDropoffLocation($onTheWayOrder, -7.3400, 110.5200, 'Alamat Customer');

        Sanctum::actingAs($onTheWayOrder->user);

        $this->getJson('/api/v1/orders/'.$onTheWayOrder->id)
            ->assertOk()
            ->assertJsonPath('data.driver_eta.target', 'DROPOFF')
            ->assertJsonPath('data.driver_eta.target_label', 'Alamat customer')
            ->assertJsonPath('data.driver_eta.duration_seconds', 720);
    }

    public function test_customer_order_detail_hides_driver_eta_when_driver_location_is_stale(): void
    {
        Config::set('bangdeliv.dispatch.fresh_location_minutes', 10);

        [, $driver] = $this->createActiveDriver('stale-eta');
        $driver->update([
            'latitude' => -7.3305,
            'longitude' => 110.5084,
            'location_updated_at' => now()->subMinutes(30),
        ]);

        $order = $this->createRideOrderForDriver($driver, 'DRIVER_ASSIGNED');
        $this->createPickupLocation($order, -7.3310, 110.5090, 'Rumah Customer');

        Sanctum::actingAs($order->user);

        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.driver_eta', null);
    }

    public function test_rejected_pending_order_is_hidden_and_reject_is_idempotent(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('reject-hide');
        $driver->update(['status' => 'available']);

        $order = $this->createShoppingOrder(null, 'PENDING');

        Event::fake([DriverOrderRemoved::class]);
        Sanctum::actingAs($driverUser);

        $firstReject = $this->postJson('/api/v1/driver/orders/'.$order->id.'/reject', [
            'reason' => 'Tidak bisa ambil order ini.',
        ]);

        $firstReject->assertOk()
            ->assertJsonPath('success', true);

        Event::assertDispatched(DriverOrderRemoved::class, function (DriverOrderRemoved $event) use ($driverUser, $order): bool {
            return (int) $event->driverUserId === (int) $driverUser->id
                && (int) $event->orderId === (int) $order->id
                && $event->reason === 'rejected_by_driver';
        });

        $listResponse = $this->getJson('/api/v1/driver/orders');
        $listResponse->assertOk()
            ->assertJsonPath('success', true);
        $this->assertCount(0, $listResponse->json('data.incoming_orders'));

        $detailResponse = $this->getJson('/api/v1/driver/orders/'.$order->id);
        $detailResponse->assertNotFound();

        $secondReject = $this->postJson('/api/v1/driver/orders/'.$order->id.'/reject');
        $secondReject->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'DRIVER_REJECT')
            ->where('changed_by_user_id', $driverUser->id)
            ->count());
    }

    public function test_driver_order_available_event_skips_driver_that_rejected_order(): void
    {
        [$rejectedDriverUser, $rejectedDriver] = $this->createActiveDriver('realtime-rejected');
        $rejectedDriver->update(['status' => 'available']);

        [$otherDriverUser, $otherDriver] = $this->createActiveDriver('realtime-still-eligible');
        $otherDriver->update(['status' => 'available']);

        $order = $this->createShoppingOrder(null, 'PENDING');
        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => 'DRIVER_REJECT',
            'changed_by_user_id' => $rejectedDriverUser->id,
            'note' => 'Order ditolak driver.',
        ]);

        Event::fake([DriverOrderAvailable::class]);

        app(DriverOrderRealtimeService::class)->broadcastOrderAvailable($order);

        Event::assertNotDispatched(DriverOrderAvailable::class, function (DriverOrderAvailable $event) use ($rejectedDriverUser): bool {
            return (int) $event->driverUserId === (int) $rejectedDriverUser->id;
        });
        Event::assertDispatched(DriverOrderAvailable::class, function (DriverOrderAvailable $event) use ($otherDriverUser, $order): bool {
            return (int) $event->driverUserId === (int) $otherDriverUser->id
                && (int) ($event->order['id'] ?? 0) === (int) $order->id;
        });
    }

    public function test_driver_order_available_event_includes_driver_specific_dispatch_metadata(): void
    {
        [$nearDriverUser, $nearDriver] = $this->createActiveDriver('dispatch-near');
        $nearDriver->update([
            'status' => 'available',
            'latitude' => -7.3305,
            'longitude' => 110.5084,
            'location_updated_at' => now(),
        ]);

        [$farDriverUser, $farDriver] = $this->createActiveDriver('dispatch-far');
        $farDriver->update([
            'status' => 'available',
            'latitude' => -7.5000,
            'longitude' => 110.7000,
            'location_updated_at' => now(),
        ]);

        $order = $this->createShoppingOrder(null, 'PENDING');
        $this->createPickupLocation($order, -7.3310, 110.5090, 'Pickup Dekat');
        $this->createDropoffLocation($order, -7.3310, 110.5090, 'Customer Dekat');

        Event::fake([DriverOrderAvailable::class]);

        app(DriverOrderRealtimeService::class)->broadcastOrderAvailable($order);

        Event::assertDispatched(DriverOrderAvailable::class, function (DriverOrderAvailable $event) use ($nearDriverUser, $order): bool {
            return (int) $event->driverUserId === (int) $nearDriverUser->id
                && (int) ($event->order['id'] ?? 0) === (int) $order->id
                && ($event->order['dispatch']['priority_rank'] ?? null) === 1
                && ($event->order['dispatch']['distance_bucket'] ?? null) === 'NEAR';
        });

        Event::assertDispatched(DriverOrderAvailable::class, function (DriverOrderAvailable $event) use ($farDriverUser, $order): bool {
            return (int) $event->driverUserId === (int) $farDriverUser->id
                && (int) ($event->order['id'] ?? 0) === (int) $order->id
                && ($event->order['dispatch']['priority_rank'] ?? null) === 2
                && ($event->order['dispatch']['distance_bucket'] ?? null) === 'FAR';
        });
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

    public function test_driver_order_available_broadcast_failure_is_swallowed(): void
    {
        $this->useFailingBroadcaster();

        [$driverUser, $driver] = $this->createActiveDriver('realtime-failure');
        $driver->update(['status' => 'available']);

        $order = $this->createShoppingOrder(null, 'PENDING');

        app(DriverOrderRealtimeService::class)->broadcastOrderAvailable($order);

        $this->assertDatabaseMissing('orders', [
            'id' => $order->id,
            'driver_id' => $driver->id,
        ]);
    }

    public function test_customer_created_ride_order_broadcasts_available_order_to_active_driver(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('ride-realtime');
        $driver->update(['status' => 'available']);

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $address = Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Kampus',
            'recipient_name' => 'Customer Ride',
            'phone' => '081234560001',
            'full_address' => 'Politeknik Negeri Semarang',
            'latitude' => -7.051234,
            'longitude' => 110.433456,
            'is_default' => true,
        ]);

        Config::set('bangdeliv.google_maps_api_key', 'test-google-key');
        Http::fake([
            'https://maps.googleapis.com/maps/api/distancematrix/json*' => Http::response([
                'status' => 'OK',
                'rows' => [[
                    'elements' => [[
                        'status' => 'OK',
                        'distance' => ['value' => 2200, 'text' => '2.2 km'],
                        'duration' => ['value' => 600, 'text' => '10 menit'],
                    ]],
                ]],
            ]),
        ]);
        Event::fake([DriverOrderAvailable::class]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/ride', [
            'address_id' => $address->id,
            'destination_address' => 'FISIP Undip',
            'destination_latitude' => -7.047500,
            'destination_longitude' => 110.441000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $orderId = (int) $response->json('data.id');
        Event::assertDispatched(DriverOrderAvailable::class, function (DriverOrderAvailable $event) use ($driverUser, $orderId): bool {
            return (int) $event->driverUserId === (int) $driverUser->id
                && (int) ($event->order['id'] ?? 0) === $orderId
                && ($event->order['route']['distance_meters'] ?? null) === 2200
                && ($event->order['route']['route_provider'] ?? null) === 'distance_matrix';
        });
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

    public function test_second_driver_cannot_accept_order_already_assigned_to_first_driver(): void
    {
        [$firstDriverUser, $firstDriver] = $this->createActiveDriver('race-first');
        $firstDriver->update(['status' => 'available']);

        [$secondDriverUser, $secondDriver] = $this->createActiveDriver('race-second');
        $secondDriver->update(['status' => 'available']);

        $order = $this->createShoppingOrder(null, 'PENDING');

        Sanctum::actingAs($firstDriverUser);
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/accept')
            ->assertOk()
            ->assertJsonPath('data.status_code', 'DRIVER_ASSIGNED');

        Sanctum::actingAs($secondDriverUser);
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/accept')
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Order tidak dapat diterima pada status saat ini.');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'driver_id' => $firstDriver->id,
        ]);
        $this->assertDatabaseMissing('orders', [
            'id' => $order->id,
            'driver_id' => $secondDriver->id,
        ]);
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

        $this->assertDatabaseMissing('orders', [
            'id' => $order->id,
            'driver_id' => $driver->id,
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
        $this->createOrderProof($order, $driver, 'DELIVERY_PHOTO');

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
            ->assertJsonPath('data.available_actions.0.blocked_reason', 'Pembayaran belum dicatat.')
            ->assertJsonPath('data.available_actions.1.action_code', 'COLLECT_COD');

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'COMPLETE_ORDER',
            'target_status_code' => 'COMPLETED',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Pembayaran belum dicatat.');

        $this->postJson('/api/v1/orders/'.$order->id.'/payment/collect-cod', [
            'amount' => 25000,
            'note' => 'Tunai diterima driver.',
        ])->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status_id' => $deliveredStatusId,
        ]);

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
            ->assertJsonPath('data.status_code', 'DELIVERED')
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
            ->assertJsonPath('message', 'Pembayaran belum dicatat.');
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
            ->assertJsonPath('data.available_actions.0.blocked', true)
            ->assertJsonPath('data.available_actions.0.blocked_reason', 'Bukti foto pickup belum diupload.');

        $this->createOrderProof($order, $driver, 'PICKUP_PHOTO');
        $proofedDetailResponse = $this->getJson('/api/v1/driver/orders/'.$order->id);

        $proofedDetailResponse->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.available_actions.0.action_code', 'CONFIRM_PICKED_UP')
            ->assertJsonPath('data.available_actions.0.blocked', false);

        $actionCodes = collect($proofedDetailResponse->json('data.available_actions'))
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
        $this->assertNotNull($order->fresh()->cancelled_at);
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
            'registration_status' => 'active',
            'status' => 'available',
        ]));

        $customer = User::factory()->create([
            'name' => 'Customer Riwayat',
            'role' => 'customer',
        ]);

        $shoppingTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');
        $completedStatusId = (int) OrderStatus::query()->where('code', 'COMPLETED')->value('id');

        $order = Order::query()->create([
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
            'service_fee' => 2500,
            'total_amount' => 23500,
            'total_price' => 23500,
            'status_id' => $completedStatusId,
            'payment_status' => 'paid',
            'payment_method' => 'COD',
            'delivered_at' => now()->subMinutes(10),
        ]);

        Sanctum::actingAs($driverUser);

        $response = $this->getJson('/api/v1/driver/history');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.history_orders.0.id', 'BD-DRV-HST-0001')
            ->assertJsonPath('data.history_orders.0.order_id', $order->id)
            ->assertJsonPath('data.history_orders.0.order_number', 'BD-DRV-HST-0001')
            ->assertJsonPath('data.history_orders.0.customer_name', 'Customer Riwayat')
            ->assertJsonPath('data.history_orders.0.delivery_fee', 6000)
            ->assertJsonPath('data.history_orders.0.service_fee', 0)
            ->assertJsonPath('data.history_orders.0.driver_income', 6000)
            ->assertJsonPath('data.history_orders.0.driver_income_gross', 6000)
            ->assertJsonPath('data.history_orders.0.driver_admin_fee_percent', 10)
            ->assertJsonPath('data.history_orders.0.driver_admin_fee', 600)
            ->assertJsonPath('data.history_orders.0.driver_income_net', 5400)
            ->assertJsonPath('data.history_orders.0.total_price', 23500)
            ->assertJsonPath('data.history_orders.0.status', 'Selesai');

        $this->getJson('/api/v1/driver/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.id', (string) $order->id)
            ->assertJsonPath('data.order_number', 'BD-DRV-HST-0001')
            ->assertJsonPath('data.driver_income_gross', 6000)
            ->assertJsonPath('data.driver_admin_fee', 600)
            ->assertJsonPath('data.driver_income_net', 5400)
            ->assertJsonPath('data.status_code', 'COMPLETED');

        [$otherDriverUser] = $this->createActiveDriver('history-other');
        Sanctum::actingAs($otherDriverUser);

        $this->getJson('/api/v1/driver/orders/'.$order->id)
            ->assertNotFound();
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

    public function test_driver_cannot_finish_shopping_before_manual_prices_are_filled(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('shopping-pending-price');
        $order = $this->createShoppingOrder($driver, 'ARRIVED_MERCHANT');
        $pickup = $this->createPickupLocation($order, -7.002, 110.402, 'Merchant Test');

        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Telur 1 kg',
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'is_available' => true,
        ]);

        $this->approveShoppingQuoteForTest($order, $driverUser, $order->user);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'CONFIRM_PICKED_UP',
            'target_status_code' => 'PICKED_UP',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Checkout Nitip belum disimpan.');
    }

    public function test_driver_bulk_updates_shopping_receipt_prices_and_recalculates_cod(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('shopping-receipt');
        $order = $this->createShoppingOrder($driver, 'ARRIVED_MERCHANT');
        $pickup = $this->createPickupLocation($order, -7.001, 110.401, 'Resto Receipt');
        $pickup->update(['fulfillment_status' => 'OPEN_CONFIRMED']);
        $this->createDropoffLocation($order, -7.004, 110.404, 'Customer Receipt');

        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Telur 1 kg',
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'is_available' => true,
            'metadata' => ['price_status' => 'PENDING_DRIVER_INPUT'],
        ]);

        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => 18000,
        ]);

        Sanctum::actingAs($driverUser);

        $response = $this->patchJson('/api/v1/driver/orders/'.$order->id.'/shopping-items', [
            'items' => [
                [
                    'id' => $item->id,
                    'quantity' => 2,
                    'unit_price' => 15000,
                    'is_available' => true,
                    'notes' => 'Harga dari nota',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pricing.subtotal', 30000)
            ->assertJsonPath('data.pricing.service_fee', 0)
            ->assertJsonPath('data.pricing.overweight_surcharge', 0)
            ->assertJsonPath('data.pricing.total_price', 36000)
            ->assertJsonPath('data.has_pending_shopping_prices', false);

        $this->assertDatabaseHas('shopping_order_items', [
            'id' => $item->id,
            'quantity' => 2,
            'unit_price' => 15000,
            'subtotal' => 30000,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'total_price' => 36000,
        ]);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 36000,
        ]);

        $this->assertTrue(OrderLog::query()
            ->where('order_id', $order->id)
            ->where('trigger_type', 'DRIVER_RECEIPT_UPDATE')
            ->exists());
    }

    public function test_driver_can_bypass_pending_customer_shopping_price_quote(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('shopping-bypass');
        $order = $this->createShoppingOrder($driver, 'ARRIVED_MERCHANT');
        $pickup = $this->createPickupLocation($order, -7.001, 110.401, 'Resto Bypass');
        $pickup->update(['fulfillment_status' => 'ITEMS_CONFIRMED']);
        $this->createDropoffLocation($order, -7.004, 110.404, 'Customer Bypass');

        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Ayam geprek',
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'is_available' => true,
        ]);

        Sanctum::actingAs($driverUser);

        $quote = $this->postJson('/api/v1/driver/orders/'.$order->id.'/shopping/price-quote', [
            'pickup_location_id' => $pickup->id,
            'amount' => 20000,
        ]);

        $quote->assertOk()
            ->assertJsonPath('data.shopping_negotiation.status', 'PENDING_CUSTOMER')
            ->assertJsonPath('data.shopping_negotiation.quoted_amount', 20000);

        Sanctum::actingAs($driverUser);

        $approved = $this->postJson('/api/v1/driver/orders/'.$order->id.'/shopping/price-quote/bypass', [
            'pickup_location_id' => $pickup->id,
        ]);

        $approved->assertOk()
            ->assertJsonPath('data.shopping_negotiation.status', 'APPROVED')
            ->assertJsonPath('data.shopping_negotiation.approved_amount', 20000)
            ->assertJsonPath('data.shopping_negotiation.checkout_allowed', true);

        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'SHOPPING_NEGOTIATION',
            'trigger_type' => 'MERCHANT_PRICE_APPROVED_BY_DRIVER_BYPASS',
        ]);
    }

    public function test_customer_cancel_merchant_records_failed_attempt_without_fee_before_threshold(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('shopping-cancel-merchant');
        $order = $this->createShoppingOrder($driver, 'ARRIVED_MERCHANT');
        $pickup = $this->createPickupLocation($order, -7.001, 110.401, 'Resto Cancel Merchant');
        $pickup->update(['fulfillment_status' => 'ITEMS_CONFIRMED']);
        $secondPickup = OrderLocation::query()->create([
            'order_id' => $order->id,
            'restaurant_id' => null,
            'location_role' => 'PICKUP',
            'label' => 'Resto Masih Aktif',
            'full_address' => 'Resto Masih Aktif',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'sequence_no' => 2,
            'fulfillment_status' => 'ITEMS_CONFIRMED',
        ]);
        $this->createDropoffLocation($order, -7.004, 110.404, 'Customer Cancel Merchant');

        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Ayam geprek',
            'quantity' => 1,
            'unit_price' => 12000,
            'subtotal' => 12000,
            'is_available' => true,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $secondPickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Es teh',
            'quantity' => 1,
            'unit_price' => 6000,
            'subtotal' => 6000,
            'is_available' => true,
        ]);

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/shopping/price-quote', [
            'pickup_location_id' => $pickup->id,
            'amount' => 20000,
        ])->assertOk();

        Sanctum::actingAs($order->user);
        $response = $this->postJson('/api/v1/orders/'.$order->id.'/shopping/price-quote/respond', [
            'action' => 'CANCEL_MERCHANT',
            'pickup_location_id' => $pickup->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status_ref.code', 'ARRIVED_MERCHANT');

        $failedStop = collect($response->json('data.shopping_stops'))
            ->firstWhere('pickup_location_id', $pickup->id);
        $this->assertSame('FAILED', $failedStop['fulfillment_status'] ?? null);
        $this->assertSame(1, $failedStop['failed_attempt_count'] ?? null);

        $this->assertDatabaseHas('order_locations', [
            'id' => $pickup->id,
            'failed_attempt_count' => 1,
        ]);

        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'FAILED_ATTEMPT_INCREMENT',
        ]);
    }

    public function test_customer_cancel_merchant_at_third_attempt_creates_cancelled_with_fee_payment(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('shopping-cancel-fee');
        $order = $this->createShoppingOrder($driver, 'ARRIVED_MERCHANT');
        $pickup = $this->createPickupLocation($order, -7.001, 110.401, 'Resto Cancel Fee');
        $this->createDropoffLocation($order, -7.004, 110.404, 'Customer Cancel Fee');
        $pickup->update([
            'failed_attempt_count' => 2,
            'fulfillment_status' => 'ITEMS_CONFIRMED',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Ayam geprek',
            'quantity' => 1,
            'unit_price' => 12000,
            'subtotal' => 12000,
            'is_available' => true,
        ]);

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/shopping/price-quote', [
            'pickup_location_id' => $pickup->id,
            'amount' => 20000,
        ])->assertOk();

        Sanctum::actingAs($order->user);
        $response = $this->postJson('/api/v1/orders/'.$order->id.'/shopping/price-quote/respond', [
            'action' => 'CANCEL_MERCHANT',
            'pickup_location_id' => $pickup->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status_ref.code', 'CANCELLED_WITH_FEE')
            ->assertJsonPath('data.delivery_fee', '0.00')
            ->assertJsonPath('data.service_fee', '3000.00')
            ->assertJsonPath('data.total_price', '3000.00')
            ->assertJsonPath('data.payment_method', 'TRANSFER')
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->assertDatabaseHas('order_locations', [
            'id' => $pickup->id,
            'failed_attempt_count' => 3,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
            'amount' => 3000,
        ]);
    }

    public function test_driver_failed_pickups_preserve_last_delivery_fee_for_half_fee_cancellation(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-google-key');
        Config::set('bangdeliv.routes.optimize_shopping_waypoints', false);
        $this->fakeEtaRouteResponse(durationSeconds: 120, distanceMeters: 1000);

        [$driverUser, $driver] = $this->createActiveDriver('shopping-preserve-fee');
        $order = $this->createShoppingOrder($driver, 'ARRIVED_MERCHANT');
        $order->forceFill([
            'subtotal' => 30000,
            'delivery_fee' => 30000,
            'total_price' => 60000,
        ])->save();

        $pickups = [
            $this->createShoppingPickupWithItem($order, 1, 'Tempat Tutup 1', -7.001, 110.401, 'Item 1'),
            $this->createShoppingPickupWithItem($order, 2, 'Tempat Tutup 2', -7.011, 110.411, 'Item 2'),
            $this->createShoppingPickupWithItem($order, 3, 'Tempat Tutup 3', -7.021, 110.421, 'Item 3'),
        ];
        $this->createDropoffLocation($order, -7.050, 110.450, 'Customer Tiga Tempat Tutup');

        Sanctum::actingAs($driverUser);

        foreach (array_slice($pickups, 0, 2) as $pickup) {
            $response = $this->postJson('/api/v1/orders/'.$order->id.'/attempt-failed', [
                'failure_type' => 'PICKUP',
                'reason' => 'Tempat tutup saat driver tiba.',
                'pickup_location_id' => $pickup->id,
            ]);

            $response->assertOk()
                ->assertJsonPath('data.status_ref.code', 'ARRIVED_MERCHANT');
            $this->assertSame(30000.0, round((float) $order->refresh()->delivery_fee, 2));
        }

        $finalResponse = $this->postJson('/api/v1/orders/'.$order->id.'/attempt-failed', [
            'failure_type' => 'PICKUP',
            'reason' => 'Tempat tutup saat driver tiba.',
            'pickup_location_id' => $pickups[2]->id,
        ]);

        $finalResponse->assertOk()
            ->assertJsonPath('data.status_ref.code', 'CANCELLED_WITH_FEE')
            ->assertJsonPath('data.delivery_fee', '0.00')
            ->assertJsonPath('data.service_fee', '15000.00')
            ->assertJsonPath('data.total_price', '15000.00')
            ->assertJsonPath('data.payment_method', 'TRANSFER')
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 0,
            'total_price' => 15000,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
            'amount' => 15000,
        ]);
        $this->assertTrue($this->orderHasPenaltyBaseLog($order, 30000));
    }

    public function test_customer_cancelled_places_preserve_last_delivery_fee_for_half_fee_cancellation(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-google-key');
        Config::set('bangdeliv.routes.optimize_shopping_waypoints', false);
        $this->fakeEtaRouteResponse(durationSeconds: 120, distanceMeters: 1000);

        [$driverUser, $driver] = $this->createActiveDriver('shopping-customer-preserve-fee');
        $order = $this->createShoppingOrder($driver, 'ARRIVED_MERCHANT');
        $order->forceFill([
            'subtotal' => 30000,
            'delivery_fee' => 30000,
            'total_price' => 60000,
        ])->save();

        $pickups = [
            $this->createShoppingPickupWithItem($order, 1, 'Tempat Batal 1', -7.001, 110.401, 'Item A'),
            $this->createShoppingPickupWithItem($order, 2, 'Tempat Batal 2', -7.011, 110.411, 'Item B'),
            $this->createShoppingPickupWithItem($order, 3, 'Tempat Batal 3', -7.021, 110.421, 'Item C'),
        ];
        $this->createDropoffLocation($order, -7.050, 110.450, 'Customer Batal Tiga Tempat');

        foreach ($pickups as $index => $pickup) {
            Sanctum::actingAs($driverUser);
            $this->postJson('/api/v1/driver/orders/'.$order->id.'/shopping/price-quote', [
                'pickup_location_id' => $pickup->id,
                'amount' => 10000,
            ])->assertOk();

            Sanctum::actingAs($order->user);
            $response = $this->postJson('/api/v1/orders/'.$order->id.'/shopping/price-quote/respond', [
                'action' => 'CANCEL_MERCHANT',
                'pickup_location_id' => $pickup->id,
            ]);

            if ($index < 2) {
                $response->assertOk()
                    ->assertJsonPath('data.status_ref.code', 'ARRIVED_MERCHANT');
                $this->assertSame(30000.0, round((float) $order->refresh()->delivery_fee, 2));
            } else {
                $response->assertOk()
                    ->assertJsonPath('data.status_ref.code', 'CANCELLED_WITH_FEE')
                    ->assertJsonPath('data.delivery_fee', '0.00')
                    ->assertJsonPath('data.service_fee', '15000.00')
                    ->assertJsonPath('data.total_price', '15000.00')
                    ->assertJsonPath('data.payment_method', 'TRANSFER')
                    ->assertJsonPath('data.payment_status', 'unpaid');
            }
        }

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 0,
            'total_price' => 15000,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
            'amount' => 15000,
        ]);
        $this->assertTrue($this->orderHasPenaltyBaseLog($order, 30000));
    }

    public function test_driver_can_cancel_shopping_with_fee_after_three_failed_pickups(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('shopping-closed');
        $order = $this->createShoppingOrder($driver, 'ARRIVED_MERCHANT');
        $order->forceFill([
            'delivery_fee' => 15000,
            'total_price' => 27000,
        ])->save();

        $merchant = \App\Models\Restaurant::query()->create([
            'name' => 'Resto Tutup Test',
            'slug' => 'resto-tutup-test-'.strtolower(str()->random(6)),
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Merchant Tutup',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '0812'.random_int(10000000, 99999999),
        ]);

        $pickup = $order->orderLocations()->create([
            'restaurant_id' => $merchant->id,
            'location_role' => 'PICKUP',
            'label' => $merchant->name,
            'full_address' => $merchant->address,
            'latitude' => $merchant->latitude,
            'longitude' => $merchant->longitude,
            'sequence_no' => 1,
            'fulfillment_status' => 'ITEMS_CONFIRMED',
        ]);

        $order->orderLocations()->create([
            'location_role' => 'DROPOFF',
            'label' => 'Customer',
            'full_address' => 'Jl. Customer Cancel Fee',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'sequence_no' => 2,
        ]);

        $pickup->update(['failed_attempt_count' => 2]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Ayam Geprek',
            'quantity' => 1,
            'unit_price' => 12000,
            'subtotal' => 12000,
            'is_available' => true,
        ]);

        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => 27000,
        ]);

        Sanctum::actingAs($driverUser);

        $failedResponse = $this->postJson('/api/v1/orders/'.$order->id.'/attempt-failed', [
            'failure_type' => 'PICKUP',
            'reason' => 'Merchant tutup saat driver tiba.',
            'pickup_location_id' => $pickup->id,
        ]);

        $failedResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_ref.code', 'CANCELLED_WITH_FEE');

        $this->assertDatabaseHas('order_locations', [
            'id' => $pickup->id,
            'failed_attempt_count' => 3,
        ]);

        $this->assertDatabaseHas('order_locations', [
            'id' => $pickup->id,
            'fulfillment_status' => 'FAILED',
        ]);

        $this->assertDatabaseHas('shopping_order_items', [
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'is_available' => false,
            'subtotal' => 0,
        ]);

        $detailResponse = $this->getJson('/api/v1/driver/orders/'.$order->id);
        $detailResponse->assertOk()
            ->assertJsonPath('data.status_code', 'CANCELLED_WITH_FEE')
            ->assertJsonPath('data.fee', 7500)
            ->assertJsonPath('data.delivery_fee', 0)
            ->assertJsonPath('data.pricing.service_fee', 7500)
            ->assertJsonPath('data.pricing.total_price', 7500)
            ->assertJsonPath('data.pricing.failed_attempt_count', 3)
            ->assertJsonPath('data.shopping_stops.0.fulfillment_status', 'FAILED');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 0,
            'total_price' => 7500,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'cancelled_by' => 'driver',
        ]);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
            'amount' => 7500,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'trigger_type' => 'SYSTEM_PAYMENT_METHOD_CHANGED_AFTER_FAILED_ATTEMPTS',
        ]);

        $runningAfterCancel = $this->getJson('/api/v1/driver/orders');
        $runningAfterCancel->assertOk();
        $this->assertTrue(
            collect($runningAfterCancel->json('data.running_orders'))
                ->contains(fn (array $runningOrder): bool => (string) $runningOrder['id'] === (string) $order->id
                    && (int) ($runningOrder['fee'] ?? 0) === 7500)
        );

        $cancelledDetailResponse = $this->getJson('/api/v1/driver/orders/'.$order->id);
        $cancelledDetailResponse->assertOk()
            ->assertJsonPath('data.status_code', 'CANCELLED_WITH_FEE')
            ->assertJsonPath('data.payment_status', 'unpaid');
        $this->assertNotContains(
            'COMPLETE_ORDER',
            collect($cancelledDetailResponse->json('data.available_actions'))->pluck('action_code')->all()
        );

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'COMPLETE_ORDER',
            'target_status_code' => 'COMPLETED',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Pembayaran belum dicatat.');

        $this->postJson('/api/v1/orders/'.$order->id.'/payment/transfer/confirm', [
            'amount' => 7500,
        ])->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $paidDetailResponse = $this->getJson('/api/v1/driver/orders/'.$order->id);
        $paidDetailResponse->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');
        $this->assertContains(
            'COMPLETE_ORDER',
            collect($paidDetailResponse->json('data.available_actions'))->pluck('action_code')->all()
        );

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'COMPLETE_ORDER',
            'target_status_code' => 'COMPLETED',
            'note' => 'Penalty sudah dibayar customer.',
        ])->assertOk()
            ->assertJsonPath('data.status_code', 'COMPLETED');

        $runningAfterComplete = $this->getJson('/api/v1/driver/orders');
        $runningAfterComplete->assertOk();
        $this->assertFalse(
            collect($runningAfterComplete->json('data.running_orders'))
                ->contains(fn (array $runningOrder): bool => (string) $runningOrder['id'] === (string) $order->id)
        );

        $historyResponse = $this->getJson('/api/v1/driver/history');
        $historyResponse->assertOk();
        $this->assertTrue(
            collect($historyResponse->json('data.history_orders'))
                ->contains(fn (array $historyOrder): bool => (string) $historyOrder['id'] === (string) ($order->order_number ?: $order->id)
                    && (int) ($historyOrder['fee'] ?? 0) === 7500)
        );
    }

    public function test_locked_delivery_fee_sets_cancelled_with_fee_driver_income(): void
    {
        [$driverUser, $driver] = $this->createActiveDriver('shopping-locked-fee');
        $order = $this->createShoppingOrder($driver, 'ARRIVED_MERCHANT');
        $order->forceFill([
            'delivery_fee' => 20000,
            'delivery_fee_source' => 'driver_manual',
            'total_price' => 32000,
        ])->save();

        $pickup = $this->createPickupLocation($order, -7.002, 110.402, 'Merchant Locked Fee');
        $this->createDropoffLocation($order, -7.003, 110.403, 'Customer Locked Fee');
        $pickup->update([
            'failed_attempt_count' => 2,
            'fulfillment_status' => 'ITEMS_CONFIRMED',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Ayam Geprek',
            'quantity' => 1,
            'unit_price' => 12000,
            'subtotal' => 12000,
            'is_available' => true,
        ]);

        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => 'DELIVERY_FEE_NEGOTIATION',
            'trigger_type' => 'CUSTOMER_FEE_APPROVED',
            'changed_by_user_id' => $order->user_id,
            'note' => 'Customer menyetujui revisi ongkir.',
            'metadata' => [
                'approved_amount' => 20000,
                'final_amount' => 20000,
                'delivery_fee_source' => 'driver_manual',
                'status' => 'APPROVED',
            ],
            'created_at' => now(),
        ]);

        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => 32000,
        ]);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/attempt-failed', [
            'failure_type' => 'PICKUP',
            'reason' => 'Merchant tutup saat driver tiba.',
            'pickup_location_id' => $pickup->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status_ref.code', 'CANCELLED_WITH_FEE')
            ->assertJsonPath('data.delivery_fee', '0.00')
            ->assertJsonPath('data.service_fee', '10000.00')
            ->assertJsonPath('data.total_price', '10000.00');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 0,
            'total_price' => 10000,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
            'amount' => 10000,
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

    private function createRideOrderForDriver(Driver $driver, string $statusCode): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $rideTypeId = (int) ServiceType::query()->where('code', 'RIDE')->value('id');
        $statusId = (int) OrderStatus::query()->where('code', $statusCode)->value('id');

        return Order::query()->create([
            'order_number' => 'BD-RDE-'.strtoupper(substr(md5($statusCode.random_int(1, 999999)), 0, 8)),
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $rideTypeId,
            'driver_id' => $driver->id,
            'address_id' => null,
            'delivery_address' => 'Jl. Tujuan Ride No. '.random_int(1, 99),
            'delivery_latitude' => -7.3400,
            'delivery_longitude' => 110.5200,
            'subtotal' => 0,
            'delivery_fee' => 12000,
            'service_fee' => 0,
            'total_amount' => 12000,
            'total_price' => 12000,
            'status_id' => $statusId,
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);
    }

    private function createPickupLocation(Order $order, float $latitude, float $longitude, string $label): OrderLocation
    {
        return OrderLocation::query()->create([
            'order_id' => $order->id,
            'restaurant_id' => null,
            'location_role' => 'PICKUP',
            'label' => $label,
            'full_address' => $label,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'sequence_no' => 1,
            'fulfillment_status' => 'PENDING',
        ]);
    }

    private function createShoppingPickupWithItem(
        Order $order,
        int $sequenceNo,
        string $label,
        float $latitude,
        float $longitude,
        string $itemName,
    ): OrderLocation {
        $pickup = OrderLocation::query()->create([
            'order_id' => $order->id,
            'restaurant_id' => null,
            'location_role' => 'PICKUP',
            'label' => $label,
            'full_address' => $label,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'sequence_no' => $sequenceNo,
            'fulfillment_status' => 'ITEMS_CONFIRMED',
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => $itemName,
            'quantity' => 1,
            'unit_price' => 10000,
            'subtotal' => 10000,
            'is_available' => true,
        ]);

        return $pickup;
    }

    private function orderHasPenaltyBaseLog(Order $order, float $amount): bool
    {
        return OrderLog::query()
            ->where('order_id', $order->id)
            ->get(['metadata'])
            ->contains(fn (OrderLog $event): bool => round((float) data_get($event->metadata ?? [], 'penalty_base_delivery_fee'), 2) === round($amount, 2));
    }

    private function createDropoffLocation(Order $order, float $latitude, float $longitude, string $label): OrderLocation
    {
        return OrderLocation::query()->create([
            'order_id' => $order->id,
            'restaurant_id' => null,
            'location_role' => 'DROPOFF',
            'label' => $label,
            'full_address' => $label,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'sequence_no' => 99,
            'fulfillment_status' => 'PENDING',
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
        ]);

        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => $amount,
        ]);

        return $order;
    }

    private function createOrderProof(Order $order, Driver $driver, string $evidenceType): OrderEvidence
    {
        return OrderEvidence::query()->create([
            'order_id' => $order->id,
            'user_id' => (int) $driver->user_id,
            'evidence_type' => $evidenceType,
            'file_url' => 'http://localhost/storage/test-proof.jpg',
            'uploaded_at' => now(),
        ]);
    }

    private function approveShoppingQuoteForTest(Order $order, User $driverUser, User $customer, float $amount = 12000): void
    {
        $pickup = $order->orderLocations()->where('location_role', 'PICKUP')->first();

        $quote = OrderLog::query()->create([
            'order_id' => $order->id,
            'log_type' => 'SHOPPING_NEGOTIATION',
            'trigger_type' => 'DRIVER_PRICE_QUOTED',
            'changed_by_user_id' => $driverUser->id,
            'note' => 'Quote test.',
            'metadata' => [
                'pickup_location_id' => $pickup?->id,
                'quoted_amount' => $amount,
                'approved_amount' => null,
                'status' => 'PENDING_CUSTOMER',
            ],
        ]);

        OrderLog::query()->create([
            'order_id' => $order->id,
            'log_type' => 'SHOPPING_NEGOTIATION',
            'trigger_type' => 'CUSTOMER_PRICE_APPROVED',
            'changed_by_user_id' => $customer->id,
            'note' => 'Approval test.',
            'metadata' => [
                'quote_log_id' => $quote->id,
                'pickup_location_id' => $pickup?->id,
                'quoted_amount' => $amount,
                'approved_amount' => $amount,
                'status' => 'APPROVED',
            ],
        ]);
    }

    private function fakeEtaRouteResponse(int $durationSeconds, int $distanceMeters): void
    {
        Http::fake([
            'https://routes.googleapis.com/directions/v2:computeRoutes*' => Http::response([
                'routes' => [[
                    'distanceMeters' => $distanceMeters,
                    'duration' => $durationSeconds.'s',
                    'polyline' => [
                        'encodedPolyline' => 'eta-polyline',
                    ],
                    'legs' => [[
                        'distanceMeters' => $distanceMeters,
                        'duration' => $durationSeconds.'s',
                    ]],
                ]],
            ]),
        ]);
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
