<?php

namespace Tests\Feature\Api;

use App\Events\OrderChatMessageSent;
use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Laravel\Sanctum\Sanctum;
use Mockery;
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

        Event::assertDispatched(OrderChatMessageSent::class, function (OrderChatMessageSent $event) use ($customer, $order): bool {
            $channels = $event->broadcastOn();
            $usesOrderTrackingChannel = collect($channels)->contains(
                fn ($channel): bool => $channel->name === 'private-order.tracking.'.$order->id
            );

            return $event->orderId === $order->id
                && $usesOrderTrackingChannel
                && ($event->message['sender_user_id'] ?? null) === $customer->id
                && ($event->message['sender_role'] ?? null) === 'customer'
                && ($event->message['body'] ?? null) === 'Saya sudah menunggu di lobi.';
        });

        Sanctum::actingAs($driverUser);
        $listResponse = $this->getJson("/api/v1/orders/{$order->id}/chat/messages");

        $listResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.can_send', true)
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.last_read_message_id', 0)
            ->assertJsonPath('data.messages.0.body', 'Saya sudah menunggu di lobi.')
            ->assertJsonPath('data.messages.0.sender_user_id', $customer->id);

        $this->assertSame($driver->id, $order->driver_id);
    }

    public function test_unread_count_ignores_messages_sent_by_current_user(): void
    {
        [$customer, , , $order] = $this->createAssignedOrder();

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Pesan saya sendiri.',
            'client_message_id' => 'own-message-1',
        ])->assertCreated();

        $this->getJson("/api/v1/orders/{$order->id}/chat/unread")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.last_read_message_id', 0);
    }

    public function test_mark_read_clears_unread_count_for_assigned_participant(): void
    {
        [$customer, $driverUser, , $order] = $this->createAssignedOrder();

        Sanctum::actingAs($customer);
        $messageId = (int) $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Tolong cek titik jemput.',
            'client_message_id' => 'readable-message-1',
        ])->assertCreated()->json('data.message.id');

        Sanctum::actingAs($driverUser);
        $this->getJson("/api/v1/orders/{$order->id}/chat/unread")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.last_read_message_id', 0);

        $this->postJson("/api/v1/orders/{$order->id}/chat/read", [
            'message_id' => $messageId,
        ])->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.last_read_message_id', $messageId);
    }

    public function test_unrelated_user_cannot_read_or_mark_order_chat_unread_state(): void
    {
        [, , , $order] = $this->createAssignedOrder();
        $otherCustomer = User::factory()->create(['role' => 'customer']);

        Sanctum::actingAs($otherCustomer);
        $this->getJson("/api/v1/orders/{$order->id}/chat/unread")
            ->assertNotFound();

        $this->postJson("/api/v1/orders/{$order->id}/chat/read", [
            'message_id' => 1,
        ])->assertNotFound();
    }

    public function test_unrelated_customer_and_driver_cannot_access_order_chat(): void
    {
        [, , , $order] = $this->createAssignedOrder();
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $otherDriverUser = User::factory()->create(['role' => 'driver']);
        Driver::query()->create($this->driverAttributes([
            'user_id' => $otherDriverUser->id,
            'vehicle_plate' => 'B 7788 OTH',
            'license_number' => 'SIM-OTHER-2026',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

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

    public function test_payment_transfer_chat_attachment_stays_chat_only(): void
    {
        Storage::fake('public');
        [$customer, , , $order] = $this->createAssignedOrder();

        Sanctum::actingAs($customer);

        $this->post("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Bukti QRIS customer.',
            'attachment_type' => 'payment_transfer',
            'attachment' => UploadedFile::fake()->image('transfer.jpg', 640, 480),
            'client_message_id' => 'transfer-proof-1',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message.attachment_type', 'payment_transfer');

        $this->assertDatabaseMissing('order_evidence', [
            'order_id' => $order->id,
            'evidence_type' => 'PAYMENT_TRANSFER_PHOTO',
        ]);
    }

    public function test_regular_chat_photo_is_not_recorded_as_payment_transfer_proof(): void
    {
        Storage::fake('public');
        [$customer, , , $order] = $this->createAssignedOrder();

        Sanctum::actingAs($customer);

        $this->post("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Foto order.',
            'attachment_type' => 'image',
            'attachment' => UploadedFile::fake()->image('order-photo.jpg', 640, 480),
            'client_message_id' => 'chat-image-1',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message.attachment_type', 'image');

        $this->assertDatabaseMissing('order_evidence', [
            'order_id' => $order->id,
            'evidence_type' => 'PAYMENT_TRANSFER_PHOTO',
        ]);
    }

    public function test_customer_chat_sends_push_notification_to_assigned_driver_only(): void
    {
        [$customer, $driverUser, , $order] = $this->createAssignedOrder();
        DeviceToken::query()->create([
            'user_id' => $customer->id,
            'token' => 'customer-token',
            'device_type' => 'android',
            'is_active' => true,
        ]);
        DeviceToken::query()->create([
            'user_id' => $driverUser->id,
            'token' => 'driver-token',
            'device_type' => 'android',
            'is_active' => true,
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) use ($customer, $order): bool {
                $payload = json_decode(json_encode($message), true);

                return $tokens === ['driver-token']
                    && $payload['notification']['title'] === 'Pesan dari Customer '.$customer->name
                    && $payload['notification']['body'] === 'Saya sudah menunggu di lobi.'
                    && $payload['data']['type'] === 'order_chat_message'
                    && $payload['data']['order_id'] === (string) $order->id
                    && $payload['data']['sender_user_id'] === (string) $customer->id
                    && $payload['data']['sender_role'] === 'customer'
                    && $payload['data']['route'] === "/orders/{$order->id}/chat";
            })
            ->andReturn($this->successfulReport(['driver-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Saya sudah menunggu di lobi.',
            'client_message_id' => 'push-customer-1',
        ])->assertCreated()
            ->assertJsonPath('success', true);
    }

    public function test_driver_chat_sends_push_notification_to_customer_only(): void
    {
        [$customer, $driverUser, , $order] = $this->createAssignedOrder();
        DeviceToken::query()->create([
            'user_id' => $customer->id,
            'token' => 'customer-token',
            'device_type' => 'android',
            'is_active' => true,
        ]);
        DeviceToken::query()->create([
            'user_id' => $driverUser->id,
            'token' => 'driver-token',
            'device_type' => 'android',
            'is_active' => true,
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) use ($driverUser, $order): bool {
                $payload = json_decode(json_encode($message), true);

                return $tokens === ['customer-token']
                    && $payload['notification']['title'] === 'Pesan dari Driver '.$driverUser->name
                    && $payload['data']['type'] === 'order_chat_message'
                    && $payload['data']['order_id'] === (string) $order->id
                    && $payload['data']['sender_user_id'] === (string) $driverUser->id
                    && $payload['data']['sender_role'] === 'driver'
                    && $payload['data']['route'] === "/orders/{$order->id}/chat";
            })
            ->andReturn($this->successfulReport(['customer-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($driverUser);

        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Saya sudah sampai di titik jemput.',
            'client_message_id' => 'push-driver-1',
        ])->assertCreated()
            ->assertJsonPath('success', true);
    }

    public function test_duplicate_client_message_id_does_not_send_push_twice(): void
    {
        [$customer, $driverUser, , $order] = $this->createAssignedOrder();
        DeviceToken::query()->create([
            'user_id' => $driverUser->id,
            'token' => 'driver-token',
            'device_type' => 'android',
            'is_active' => true,
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->andReturn($this->successfulReport(['driver-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($customer);

        $payload = [
            'body' => 'Pesan ini tidak boleh push dua kali.',
            'client_message_id' => 'dedupe-push-1',
        ];

        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", $payload)
            ->assertCreated();
        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", $payload)
            ->assertCreated();
    }

    public function test_invalid_fcm_token_is_deactivated_without_failing_chat_send(): void
    {
        [$customer, $driverUser, , $order] = $this->createAssignedOrder();
        DeviceToken::query()->create([
            'user_id' => $driverUser->id,
            'token' => 'unknown-driver-token',
            'device_type' => 'android',
            'is_active' => true,
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->andReturn($this->unknownTokenReport(['unknown-driver-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Token tujuan sudah mati tapi chat tetap harus masuk.',
            'client_message_id' => 'invalid-token-push-1',
        ])->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $driverUser->id,
            'token' => 'unknown-driver-token',
            'is_active' => false,
        ]);
    }

    public function test_fcm_exception_does_not_fail_chat_send(): void
    {
        [$customer, $driverUser, , $order] = $this->createAssignedOrder();
        DeviceToken::query()->create([
            'user_id' => $driverUser->id,
            'token' => 'driver-token',
            'device_type' => 'android',
            'is_active' => true,
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->andThrow(new \RuntimeException('Firebase sedang tidak tersedia.'));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", [
            'body' => 'Chat harus tetap tersimpan walau FCM error.',
            'client_message_id' => 'fcm-error-1',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message.body', 'Chat harus tetap tersimpan walau FCM error.');

        $this->assertDatabaseHas('order_chat_messages', [
            'order_id' => $order->id,
            'sender_user_id' => $customer->id,
            'body' => 'Chat harus tetap tersimpan walau FCM error.',
        ]);
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

        $driverUser = $order->driver?->user;
        $this->assertNotNull($driverUser);

        Sanctum::actingAs($driverUser);
        $this->getJson("/api/v1/orders/{$order->id}/chat/unread")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
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
        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 1234 CHT',
            'license_number' => 'SIM-CHAT-2026',
            'registration_status' => 'active',
            'status' => 'busy',
        ]));

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
            'route_snapshot' => [
                'distance_meters' => 2500,
                'distance_km' => 2.5,
                'distance_text' => '2.5 km',
            ],
            'total_price' => 12000,
            'status_id' => $statusId,
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
