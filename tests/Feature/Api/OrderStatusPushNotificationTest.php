<?php

namespace Tests\Feature\Api;

use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class OrderStatusPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_status_transition_sends_tracking_push_to_customer(): void
    {
        [$driverUser, $driver, $customer, $order] = $this->createRideOrder('DRIVER_ASSIGNED');

        DeviceToken::query()->create([
            'user_id' => $customer->id,
            'token' => 'customer-status-token',
            'device_type' => 'android',
            'is_active' => true,
        ]);
        DeviceToken::query()->create([
            'user_id' => $driverUser->id,
            'token' => 'driver-status-token',
            'device_type' => 'android',
            'is_active' => true,
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) use ($order): bool {
                $payload = json_decode(json_encode($message), true);

                $body = (string) ($payload['notification']['body'] ?? '');

                return $tokens === ['customer-status-token']
                    && $payload['notification']['title'] === 'Driver tiba di titik jemput'
                    && $body === 'Driver sudah tiba di titik jemput. Silakan bersiap untuk berangkat.'
                    && ! str_contains($body, (string) $order->order_number)
                    && $payload['data']['type'] === 'order_status_changed'
                    && $payload['data']['order_id'] === (string) $order->id
                    && $payload['data']['status_code'] === 'ARRIVED_PICKUP'
                    && $payload['data']['route'] === "/orders/{$order->id}/track"
                    && $payload['android']['notification']['channel_id'] === 'bangdeliv_order_status_high';
            })
            ->andReturn($this->successfulReport(['customer-status-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'ARRIVE_PICKUP',
            'target_status_code' => 'ARRIVED_PICKUP',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_code', 'ARRIVED_PICKUP');
    }

    public function test_invalid_status_push_token_is_deactivated_without_failing_transition(): void
    {
        [$driverUser, , $customer, $order] = $this->createRideOrder('DRIVER_ASSIGNED');

        DeviceToken::query()->create([
            'user_id' => $customer->id,
            'token' => 'unknown-customer-status-token',
            'device_type' => 'android',
            'is_active' => true,
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->andReturn($this->unknownTokenReport(['unknown-customer-status-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'ARRIVE_PICKUP',
            'target_status_code' => 'ARRIVED_PICKUP',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $customer->id,
            'token' => 'unknown-customer-status-token',
            'is_active' => false,
        ]);
    }

    /**
     * @return array{0: User, 1: Driver, 2: User, 3: Order}
     */
    private function createRideOrder(string $statusCode): array
    {
        $driverUser = User::factory()->create([
            'name' => 'Driver Status Push',
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'H 1234 PUSH',
            'registration_status' => 'active',
            'status' => 'busy',
        ]));
        $customer = User::factory()->create(['role' => 'customer']);

        $rideTypeId = (int) ServiceType::query()->where('code', 'RIDE')->value('id');
        $statusId = (int) OrderStatus::query()->where('code', $statusCode)->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-PUSH-'.strtoupper(substr(md5($statusCode.random_int(1, 9999)), 0, 8)),
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $rideTypeId,
            'driver_id' => $driver->id,
            'address_id' => null,
            'delivery_address' => 'Jl. Tujuan Status Push',
            'delivery_latitude' => -7.001234,
            'delivery_longitude' => 110.401234,
            'subtotal' => 0,
            'delivery_fee' => 15000,
            'service_fee' => 0,
            'total_amount' => 15000,
            'total_price' => 15000,
            'status_id' => $statusId,
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);

        return [$driverUser, $driver, $customer, $order];
    }

    /**
     * @param  list<string>  $tokens
     */
    private function successfulReport(array $tokens): MulticastSendReport
    {
        return MulticastSendReport::withItems(array_map(
            fn (string $token): SendReport => SendReport::success(
                MessageTarget::with(MessageTarget::TOKEN, $token),
                ['name' => 'projects/test/messages/'.md5($token)],
            ),
            $tokens,
        ));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function unknownTokenReport(array $tokens): MulticastSendReport
    {
        return MulticastSendReport::withItems(array_map(
            fn (string $token): SendReport => SendReport::failure(
                MessageTarget::with(MessageTarget::TOKEN, $token),
                NotFound::becauseTokenNotFound($token),
            ),
            $tokens,
        ));
    }
}
