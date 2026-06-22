<?php

namespace Tests\Feature\Api;

use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShoppingItemUnavailablePushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_item_unavailable_update_sends_customer_push_once(): void
    {
        [$driverUser, $driver, $customer, $order] = $this->createShoppingOrder();
        $pickup = $this->createPickup($order);
        $this->createDropoff($order);
        $item = $this->createShoppingItem($order, (int) $pickup->id);

        $this->createToken($customer, 'customer-unavailable-token');
        $this->createToken($driverUser, 'driver-unavailable-token');

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) use ($order, $pickup): bool {
                $payload = json_decode(json_encode($message), true);
                $body = (string) ($payload['notification']['body'] ?? '');
                $eventId = (string) ($payload['data']['event_id'] ?? '');

                return $tokens === ['customer-unavailable-token']
                    && $payload['notification']['title'] === 'Item Nitip tidak tersedia'
                    && str_contains($body, 'Arduino Uno')
                    && str_contains($body, 'Toko Komponen Test')
                    && $payload['data']['type'] === 'shopping_item_unavailable'
                    && $payload['data']['order_id'] === (string) $order->id
                    && $payload['data']['recipient_role'] === 'customer'
                    && $payload['data']['pickup_location_id'] === (string) $pickup->id
                    && $payload['data']['focus'] === 'shopping_price'
                    && $eventId !== ''
                    && ctype_digit($eventId)
                    && $payload['data']['unavailable_item_count'] === '1'
                    && $payload['data']['unavailable_item_names'] === 'Arduino Uno'
                    && $payload['data']['route'] === "/orders/{$order->id}/track?focus=shopping_price&pickup_location_id={$pickup->id}"
                    && $payload['android']['notification']['channel_id'] === 'bangdeliv_order_status_high';
            })
            ->andReturn($this->successfulReport(['customer-unavailable-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($driverUser);

        $this->patchJson('/api/v1/driver/orders/'.$order->id.'/shopping-items', [
            'pickup_location_id' => $pickup->id,
            'items' => [
                [
                    'id' => $item->id,
                    'quantity' => 1,
                    'unit_price' => 12000,
                    'is_available' => false,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shopping_stops.0.fulfillment_status', 'ITEMS_PENDING_CUSTOMER');

        $this->assertDatabaseHas('order_locations', [
            'id' => $pickup->id,
            'fulfillment_status' => 'ITEMS_PENDING_CUSTOMER',
        ]);

        $this->patchJson('/api/v1/driver/orders/'.$order->id.'/shopping-items', [
            'pickup_location_id' => $pickup->id,
            'items' => [
                [
                    'id' => $item->id,
                    'quantity' => 1,
                    'unit_price' => 12000,
                    'is_available' => false,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * @return array{0: User, 1: Driver, 2: User, 3: Order}
     */
    private function createShoppingOrder(): array
    {
        $driverUser = User::factory()->create([
            'name' => 'Driver Item Unavailable Push',
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'H '.random_int(1000, 9999).' IUP',
            'registration_status' => 'active',
            'status' => 'busy',
        ]));
        $customer = User::factory()->create(['role' => 'customer']);

        $shoppingTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');
        $statusId = (int) OrderStatus::query()->where('code', 'ARRIVED_MERCHANT')->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-ITEM-PUSH-'.strtoupper(substr(md5((string) random_int(1, 999999)), 0, 6)),
            'user_id' => $customer->id,
            'service_type_id' => $shoppingTypeId,
            'driver_id' => $driver->id,
            'subtotal' => 12000,
            'delivery_fee' => 6000,
            'service_fee' => 0,
            'total_price' => 18000,
            'status_id' => $statusId,
            'payment_method' => 'COD',
            'payment_status' => 'unpaid',
        ]);

        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => 18000,
        ]);

        return [$driverUser, $driver, $customer, $order];
    }

    private function createPickup(Order $order)
    {
        return $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Toko Komponen Test',
            'full_address' => 'Jl. Komponen Test',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'sequence_no' => 1,
            'fulfillment_status' => 'OPEN_CONFIRMED',
        ]);
    }

    private function createDropoff(Order $order): void
    {
        $order->orderLocations()->create([
            'location_role' => 'DROPOFF',
            'label' => 'Customer Item Unavailable',
            'full_address' => 'Jl. Customer Item Unavailable',
            'latitude' => -7.004,
            'longitude' => 110.404,
            'sequence_no' => 99,
            'fulfillment_status' => 'PENDING',
        ]);
    }

    private function createShoppingItem(Order $order, int $pickupLocationId): OrderItem
    {
        return OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickupLocationId,
            'item_source' => 'MANUAL',
            'menu_name' => 'Arduino Uno',
            'quantity' => 1,
            'unit_price' => 12000,
            'subtotal' => 12000,
            'is_available' => true,
        ]);
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
