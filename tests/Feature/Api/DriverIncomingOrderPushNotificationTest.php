<?php

namespace Tests\Feature\Api;

use App\Events\DriverOrderAvailable;
use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Driver\DriverOrderRealtimeService;
use App\Services\Notification\DriverIncomingOrderPushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;
use Tests\TestCase;

class DriverIncomingOrderPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_order_sends_push_notification_to_driver_tokens(): void
    {
        [$driverUser, $driver, $customer, $order] = $this->createRideOrder();

        $this->createToken($driverUser, 'driver-incoming-token');
        $this->createToken($customer, 'customer-token');

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) use ($order): bool {
                $payload = json_decode(json_encode($message), true);
                $body = (string) ($payload['notification']['body'] ?? '');

                return $tokens === ['driver-incoming-token']
                    && $payload['notification']['title'] === 'Order masuk'
                    && str_contains($body, 'baru tersedia')
                    && str_contains($body, 'Rp 13.000')
                    && $payload['data']['type'] === 'driver_order_available'
                    && $payload['data']['title'] === 'Order masuk'
                    && $payload['data']['body'] === $body
                    && $payload['data']['order_id'] === (string) $order->id
                    && $payload['data']['order_number'] === $order->order_number
                    && $payload['data']['service_type_code'] === 'RIDE'
                    && $payload['data']['delivery_fee'] === '13000.00'
                    && $payload['data']['route'] === '/driver/orders'
                    && $payload['android']['notification']['channel_id'] === 'bangdeliv_driver_order_high';
            })
            ->andReturn($this->successfulReport(['driver-incoming-token']));
        $this->app->instance(Messaging::class, $messaging);

        $sent = app(DriverIncomingOrderPushNotificationService::class)
            ->sendIncomingOrderNotification($order, $driver);

        $this->assertTrue($sent);
    }

    public function test_driver_order_realtime_broadcast_sends_push_notification_to_driver_candidates(): void
    {
        [$driverUser, $driver, $customer, $order] = $this->createRideOrder();

        $this->createToken($driverUser, 'driver-candidate-token');
        $this->createToken($customer, 'customer-token');

        Event::fake([DriverOrderAvailable::class]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) use ($order): bool {
                $payload = json_decode(json_encode($message), true);

                return $tokens === ['driver-candidate-token']
                    && $payload['data']['type'] === 'driver_order_available'
                    && $payload['data']['title'] === 'Order masuk'
                    && str_contains((string) $payload['data']['body'], 'baru tersedia')
                    && $payload['data']['order_id'] === (string) $order->id
                    && $payload['data']['order_number'] === $order->order_number
                    && $payload['data']['service_type_code'] === 'RIDE'
                    && $payload['data']['route'] === '/driver/orders';
            })
            ->andReturn($this->successfulReport(['driver-candidate-token']));
        $this->app->instance(Messaging::class, $messaging);

        app(DriverOrderRealtimeService::class)->broadcastOrderAvailable($order);

        Event::assertDispatched(
            DriverOrderAvailable::class,
            fn (DriverOrderAvailable $event): bool => (int) $event->driverUserId === (int) $driverUser->id
                && (int) ($event->order['id'] ?? 0) === (int) $order->id,
        );
    }

    /**
     * @return array{0: User, 1: Driver, 2: User, 3: Order}
     */
    private function createRideOrder(): array
    {
        $driverUser = User::factory()->create([
            'name' => 'Driver Incoming Push',
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'H 4321 FCM',
            'registration_status' => 'active',
            'status' => 'available',
        ]));
        $customer = User::factory()->create(['role' => 'customer']);

        $rideTypeId = (int) ServiceType::query()->where('code', 'RIDE')->value('id');
        $pendingStatusId = (int) OrderStatus::query()->where('code', 'PENDING')->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-290626-001',
            'user_id' => $customer->id,
            'service_type_id' => $rideTypeId,
            'delivery_fee' => 13000,
            'delivery_fee_source' => 'system',
            'total_price' => 13000,
            'status_id' => $pendingStatusId,
        ]);

        return [$driverUser, $driver, $customer, $order];
    }

    private function createToken(User $user, string $token): void
    {
        DeviceToken::query()->create([
            'user_id' => $user->id,
            'token' => $token,
            'device_type' => 'android',
            'is_active' => true,
        ]);
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
}
