<?php

namespace Tests\Feature\Api;

use App\Events\OrderChatMessageSent;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_assigned_driver_can_send_and_list_order_chat_messages(): void
    {
        Event::fake([OrderChatMessageSent::class]);

        [$customer, $driverUser, $driver, $order] = $this->createAssignedOrder();

        Sanctum::actingAs($customer);
        $sendResponse = $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Saya sudah menunggu di lobi.',
            'client_message_id' => 'customer-message-1',
        ]);

        $sendResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message.order_id', $order->id)
            ->assertJsonPath('data.message.sender_user_id', $customer->id)
            ->assertJsonPath('data.message.sender_role', 'customer')
            ->assertJsonPath('data.message.body', 'Saya sudah menunggu di lobi.')
            ->assertJsonPath('data.can_send', true);

        $this->assertDatabaseHas('order_chat_messages', [
            'order_id' => $order->id,
            'sender_user_id' => $customer->id,
            'sender_role' => 'customer',
            'body' => 'Saya sudah menunggu di lobi.',
            'client_message_id' => 'customer-message-1',
        ]);

        Event::assertDispatched(OrderChatMessageSent::class, function (OrderChatMessageSent $event) use ($order): bool {
            return $event->orderId === $order->id
                && ($event->message['body'] ?? null) === 'Saya sudah menunggu di lobi.';
        });

        Sanctum::actingAs($driverUser);
        $listResponse = $this->getJson("/api/v1/orders/{$order->id}/chat/messages");

        $listResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.can_send', true)
            ->assertJsonPath('data.messages.0.body', 'Saya sudah menunggu di lobi.')
            ->assertJsonPath('data.messages.0.sender_user_id', $customer->id);

        $this->assertSame($driver->id, $order->driver_id);
    }

    public function test_unrelated_customer_and_driver_cannot_access_order_chat(): void
    {
        [, , , $order] = $this->createAssignedOrder();
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $otherDriverUser = User::factory()->create(['role' => 'driver']);
        Driver::query()->create([
            'user_id' => $otherDriverUser->id,
            'vehicle_plate' => 'B 7788 OTH',
            'license_number' => 'SIM-OTHER-2026',
            'registration_status' => 'active',
            'status' => 'available',
        ]);

        Sanctum::actingAs($otherCustomer);
        $this->getJson("/api/v1/orders/{$order->id}/chat/messages")
            ->assertNotFound();

        Sanctum::actingAs($otherDriverUser);
        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Saya bukan driver order ini.',
        ])->assertNotFound();
    }

    public function test_customer_cannot_send_chat_before_driver_is_assigned(): void
    {
        [$customer, , , $order] = $this->createAssignedOrder(
            driverAssigned: false,
            statusCode: 'PENDING',
        );

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Driver sudah ada?',
        ])->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('order_chat_messages', 0);
    }

    public function test_chat_history_is_readable_but_sending_is_disabled_after_terminal_status(): void
    {
        [$customer, , , $order] = $this->createAssignedOrder(statusCode: 'COMPLETED');

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}/chat/messages")
            ->assertOk()
            ->assertJsonPath('data.can_send', false);

        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Terima kasih.',
        ])->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_client_message_id_prevents_duplicate_messages(): void
    {
        [$customer, , , $order] = $this->createAssignedOrder();

        Sanctum::actingAs($customer);
        $first = $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Pesan sekali saja.',
            'client_message_id' => 'dedupe-1',
        ])->assertCreated();

        $second = $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Pesan sekali saja.',
            'client_message_id' => 'dedupe-1',
        ])->assertCreated();

        $this->assertSame(
            $first->json('data.message.id'),
            $second->json('data.message.id'),
        );
        $this->assertDatabaseCount('order_chat_messages', 1);
    }

    public function test_chat_send_still_persists_when_realtime_broadcast_fails(): void
    {
        $this->useFailingBroadcaster();

        [$customer, , , $order] = $this->createAssignedOrder();

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Tetap tersimpan walau realtime mati.',
            'client_message_id' => 'broadcast-fails-1',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.broadcasted', false)
            ->assertJsonPath('data.message.body', 'Tetap tersimpan walau realtime mati.');

        $this->assertDatabaseHas('order_chat_messages', [
            'order_id' => $order->id,
            'sender_user_id' => $customer->id,
            'body' => 'Tetap tersimpan walau realtime mati.',
            'client_message_id' => 'broadcast-fails-1',
        ]);
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\User, 2: \App\Models\Driver, 3: \App\Models\Order}
     */
    private function createAssignedOrder(
        bool $driverAssigned = true,
        string $statusCode = 'DRIVER_ASSIGNED',
    ): array {
        $customer = User::factory()->create(['role' => 'customer']);
        $driverUser = User::factory()->create(['role' => 'driver']);
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 1234 CHT',
            'license_number' => 'SIM-CHAT-2026',
            'registration_status' => 'active',
            'status' => 'busy',
        ]);

        $serviceTypeId = (int) ServiceType::query()->where('code', 'RIDE')->value('id');
        $statusId = (int) OrderStatus::query()->where('code', $statusCode)->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-CHAT-'.strtoupper(substr(md5((string) microtime(true)), 0, 8)),
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $serviceTypeId,
            'driver_id' => $driverAssigned ? $driver->id : null,
            'subtotal' => 0,
            'delivery_fee' => 12000,
            'service_fee' => 0,
            'delivery_distance_km' => 2.5,
            'delivery_distance_text' => '2.5 km',
            'total_price' => 12000,
            'status_id' => $statusId,
            'estimated_delivery' => now()->addMinutes(25),
        ]);

        return [$customer, $driverUser, $driver, $order];
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
