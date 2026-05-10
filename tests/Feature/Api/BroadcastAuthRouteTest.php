<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BroadcastAuthRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.key', 'test-reverb-key');
        Config::set('broadcasting.connections.reverb.secret', 'test-reverb-secret');
        Config::set('broadcasting.connections.reverb.app_id', 'test-reverb-app');
        Config::set('broadcasting.connections.reverb.options.host', '127.0.0.1');
        Config::set('broadcasting.connections.reverb.options.port', 8080);
        Config::set('broadcasting.connections.reverb.options.scheme', 'http');
        Config::set('broadcasting.connections.reverb.options.useTLS', false);

        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    public function test_broadcast_auth_route_uses_api_sanctum_middleware(): void
    {
        $route = collect(Route::getRoutes())->first(function ($route): bool {
            return $route->uri() === 'broadcasting/auth' &&
                in_array('POST', $route->methods(), true);
        });

        $this->assertNotNull($route);
        $this->assertContains('api', $route->middleware());
        $this->assertContains('auth:sanctum', $route->middleware());
        $this->assertNotContains('web', $route->middleware());
    }

    public function test_customer_can_authorize_own_order_tracking_channel(): void
    {
        [$customer, , , $order] = $this->createAssignedOrder();

        $this->withToken($customer->createToken('broadcast-test')->plainTextToken)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-order.tracking.'.$order->id,
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_assigned_driver_can_authorize_order_tracking_and_driver_orders_channels(): void
    {
        [, $driverUser, , $order] = $this->createAssignedOrder();

        $this->withToken($driverUser->createToken('broadcast-test')->plainTextToken)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-order.tracking.'.$order->id,
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->withToken($driverUser->createToken('broadcast-test-2')->plainTextToken)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-driver.orders.user.'.$driverUser->id,
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_unrelated_user_cannot_authorize_private_order_channel(): void
    {
        [, , , $order] = $this->createAssignedOrder();
        $unrelatedCustomer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $this->withToken($unrelatedCustomer->createToken('broadcast-test')->plainTextToken)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-order.tracking.'.$order->id,
            ])->assertForbidden();
    }

    public function test_inactive_driver_cannot_authorize_driver_orders_channel(): void
    {
        $driverUser = User::factory()->create([
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'H 4321 RTA',
            'license_number' => 'SIM-REALTIME-INACTIVE',
            'registration_status' => 'pending',
            'status' => 'offline',
        ]));

        $this->withToken($driverUser->createToken('broadcast-test')->plainTextToken)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-driver.orders.user.'.$driverUser->id,
            ])->assertForbidden();
    }

    public function test_unauthenticated_request_cannot_authorize_private_channel(): void
    {
        [, , , $order] = $this->createAssignedOrder();

        $this->withHeaders(['Accept' => 'application/json'])
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-order.tracking.'.$order->id,
            ])->assertUnauthorized();
    }

    /**
     * @return array{0: User, 1: User, 2: Driver, 3: Order}
     */
    private function createAssignedOrder(): array
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $driverUser = User::factory()->create([
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'H 1234 RTA',
            'license_number' => 'SIM-REALTIME-AUTH',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

        $serviceTypeId = (int) ServiceType::query()->where('code', 'RIDE')->value('id');
        $statusId = (int) OrderStatus::query()->where('code', 'DRIVER_ASSIGNED')->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-RTA-'.strtoupper(substr(md5((string) microtime(true)), 0, 8)),
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $serviceTypeId,
            'driver_id' => $driver->id,
            'subtotal' => 0,
            'delivery_fee' => 12000,
            'service_fee' => 0,
            'delivery_distance_km' => 2.5,
            'delivery_distance_text' => '2.5 km',
            'total_price' => 12000,
            'status_id' => $statusId,
            'estimated_delivery' => now()->addMinutes(20),
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);

        return [$customer, $driverUser, $driver, $order];
    }
}
