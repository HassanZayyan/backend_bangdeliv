<?php

namespace Tests\Feature\Api;

use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderLog;
use App\Models\OrderPayment;
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

class OrderPricingPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_shopping_price_quote_sends_push_to_customer_only(): void
    {
        [$driverUser, , $customer, $order] = $this->createAssignedOrder('SHOPPING', 'ARRIVED_MERCHANT');
        $this->createToken($customer, 'customer-price-token');
        $this->createToken($driverUser, 'driver-price-token');

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) use ($order): bool {
                $payload = json_decode(json_encode($message), true);

                return $tokens === ['customer-price-token']
                    && $payload['notification']['title'] === 'Harga perlu persetujuan'
                    && $payload['data']['type'] === 'order_price_changed'
                    && $payload['data']['order_id'] === (string) $order->id
                    && $payload['data']['change_type'] === 'DRIVER_PRICE_QUOTED'
                    && $payload['data']['recipient_role'] === 'customer'
                    && $payload['data']['requires_response'] === '1'
                    && $payload['data']['amount'] === '25000.00'
                    && $payload['data']['focus'] === 'shopping_price'
                    && $payload['data']['route'] === "/orders/{$order->id}/track?focus=shopping_price"
                    && $payload['android']['notification']['channel_id'] === 'bangdeliv_order_status_high';
            })
            ->andReturn($this->successfulReport(['customer-price-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/shopping/price-quote', [
            'amount' => 25000,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shopping_negotiation.status', 'PENDING_CUSTOMER');
    }

    public function test_customer_shopping_counter_sends_push_to_driver_only(): void
    {
        [$driverUser, , $customer, $order] = $this->createAssignedOrder('SHOPPING', 'ARRIVED_MERCHANT');
        $this->createToken($customer, 'customer-price-token');
        $this->createToken($driverUser, 'driver-price-token');
        $this->seedShoppingQuote($order, $driverUser, 25000);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) use ($order): bool {
                $payload = json_decode(json_encode($message), true);

                return $tokens === ['driver-price-token']
                    && $payload['notification']['title'] === 'Customer mengirim tawaran'
                    && $payload['data']['type'] === 'order_price_changed'
                    && $payload['data']['change_type'] === 'CUSTOMER_PRICE_COUNTERED'
                    && $payload['data']['recipient_role'] === 'driver'
                    && $payload['data']['requires_response'] === '1'
                    && $payload['data']['amount'] === '22000.00'
                    && $payload['data']['route'] === "/driver/orders/{$order->id}/active";
            })
            ->andReturn($this->successfulReport(['driver-price-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders/'.$order->id.'/shopping/price-quote/respond', [
            'action' => 'COUNTER',
            'counter_amount' => 22000,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shopping_negotiation.status', 'PENDING_DRIVER');
    }

    public function test_driver_delivery_fee_quote_sends_customer_push(): void
    {
        [$driverUser, , $customer, $order] = $this->createAssignedOrder('RIDE', 'DRIVER_ASSIGNED', 12000);
        $this->createToken($customer, 'customer-fee-token');
        $this->createToken($driverUser, 'driver-fee-token');

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) use ($order): bool {
                $payload = json_decode(json_encode($message), true);

                return $tokens === ['customer-fee-token']
                    && $payload['notification']['title'] === 'Revisi ongkir perlu persetujuan'
                    && $payload['data']['change_type'] === 'DRIVER_FEE_QUOTED'
                    && $payload['data']['recipient_role'] === 'customer'
                    && $payload['data']['requires_response'] === '1'
                    && $payload['data']['amount'] === '15000.00'
                    && $payload['data']['focus'] === 'delivery_fee'
                    && $payload['data']['route'] === "/orders/{$order->id}/track?focus=delivery_fee";
            })
            ->andReturn($this->successfulReport(['customer-fee-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 15000,
            'reason' => 'Rute aktual lebih jauh.',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'PENDING_CUSTOMER');
    }

    public function test_customer_delivery_fee_approval_sends_informative_push_to_driver(): void
    {
        [$driverUser, , $customer, $order] = $this->createAssignedOrder('RIDE', 'DRIVER_ASSIGNED', 12000);
        $this->createToken($driverUser, 'driver-fee-token');
        $this->seedDeliveryFeeQuote($order, $driverUser, 15000);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) use ($order): bool {
                $payload = json_decode(json_encode($message), true);

                return $tokens === ['driver-fee-token']
                    && $payload['notification']['title'] === 'Harga disetujui'
                    && $payload['data']['change_type'] === 'CUSTOMER_FEE_APPROVED'
                    && $payload['data']['recipient_role'] === 'driver'
                    && $payload['data']['requires_response'] === '0'
                    && $payload['data']['amount'] === '15000.00'
                    && $payload['data']['route'] === "/driver/orders/{$order->id}/active";
            })
            ->andReturn($this->successfulReport(['driver-fee-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders/'.$order->id.'/delivery-fee-override/respond', [
            'action' => 'APPROVE',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'APPROVED');
    }

    public function test_pricing_push_deactivates_invalid_token_without_failing_endpoint(): void
    {
        [$driverUser, , $customer, $order] = $this->createAssignedOrder('RIDE', 'DRIVER_ASSIGNED', 12000);
        $this->createToken($customer, 'unknown-customer-price-token');

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->andReturn($this->unknownTokenReport(['unknown-customer-price-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 15000,
            'reason' => 'Rute aktual lebih jauh.',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $customer->id,
            'token' => 'unknown-customer-price-token',
            'is_active' => false,
        ]);
    }

    public function test_pricing_push_failure_does_not_fail_endpoint(): void
    {
        [$driverUser, , $customer, $order] = $this->createAssignedOrder('RIDE', 'DRIVER_ASSIGNED', 12000);
        $this->createToken($customer, 'customer-price-token');

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->andThrow(new \RuntimeException('Firebase sedang tidak tersedia.'));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 15000,
            'reason' => 'Rute aktual lebih jauh.',
        ])->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_unpaid_qris_blocked_driver_action_sends_throttled_payment_reminder(): void
    {
        [$driverUser, , $customer, $order] = $this->createAssignedOrder('RIDE', 'DELIVERED', 12000);
        $this->createToken($customer, 'customer-payment-token');
        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
            'amount' => 12000,
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging
            ->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function ($message, $tokens) use ($order): bool {
                $payload = json_decode(json_encode($message), true);

                return $tokens === ['customer-payment-token']
                    && $payload['notification']['title'] === 'Upload bukti QRIS'
                    && $payload['data']['type'] === 'payment_proof_required'
                    && $payload['data']['order_id'] === (string) $order->id
                    && $payload['data']['recipient_role'] === 'customer'
                    && $payload['data']['route'] === "/orders/{$order->id}/track?focus=payment"
                    && $payload['android']['notification']['channel_id'] === 'bangdeliv_order_status_high';
            })
            ->andReturn($this->successfulReport(['customer-payment-token']));
        $this->app->instance(Messaging::class, $messaging);

        Sanctum::actingAs($driverUser);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
                'action_code' => 'COMPLETE_ORDER',
                'target_status_code' => 'COMPLETED',
            ])->assertStatus(409)
                ->assertJsonPath('message', 'Pembayaran belum dicatat.');
        }
    }

    public function test_payment_reminder_skips_cod_and_existing_qris_evidence(): void
    {
        [$driverUser, , , $codOrder] = $this->createAssignedOrder('RIDE', 'DELIVERED', 12000);
        OrderPayment::query()->create([
            'order_id' => $codOrder->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => 12000,
        ]);

        [$transferDriverUser, , , $transferOrder] = $this->createAssignedOrder('RIDE', 'DELIVERED', 13000);
        OrderPayment::query()->create([
            'order_id' => $transferOrder->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
            'amount' => 13000,
        ]);
        OrderEvidence::query()->create([
            'order_id' => $transferOrder->id,
            'evidence_type' => 'PAYMENT_TRANSFER_PHOTO',
            'file_url' => '/storage/orders/payment.jpg',
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->never();
        $this->app->instance(Messaging::class, $messaging);

        foreach ([[$driverUser, $codOrder], [$transferDriverUser, $transferOrder]] as [$actingUser, $order]) {
            Sanctum::actingAs($actingUser);

            $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
                'action_code' => 'COMPLETE_ORDER',
                'target_status_code' => 'COMPLETED',
            ])->assertStatus(409)
                ->assertJsonPath('message', 'Pembayaran belum dicatat.');
        }
    }

    /**
     * @return array{0: User, 1: Driver, 2: User, 3: Order}
     */
    private function createAssignedOrder(
        string $serviceCode,
        string $statusCode,
        int $deliveryFee = 5000,
    ): array {
        $driverUser = User::factory()->create([
            'name' => 'Driver Pricing Push',
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'H '.random_int(1000, 9999).' PPN',
            'license_number' => 'SIMC-PRICE-'.random_int(1000, 9999),
            'registration_status' => 'active',
            'status' => 'busy',
        ]));
        $customer = User::factory()->create(['role' => 'customer']);

        $serviceTypeId = (int) ServiceType::query()->where('code', $serviceCode)->value('id');
        $statusId = (int) OrderStatus::query()->where('code', $statusCode)->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-PPRICE-'.strtoupper(substr(md5($serviceCode.$statusCode.random_int(1, 9999)), 0, 8)),
            'user_id' => $customer->id,
            'service_type_id' => $serviceTypeId,
            'driver_id' => $driver->id,
            'subtotal' => $serviceCode === 'SHOPPING' ? 12000 : 0,
            'delivery_fee' => $deliveryFee,
            'service_fee' => 0,
            'total_price' => ($serviceCode === 'SHOPPING' ? 12000 : 0) + $deliveryFee,
            'status_id' => $statusId,
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

    private function seedShoppingQuote(Order $order, User $driverUser, float $amount): void
    {
        OrderLog::query()->create([
            'order_id' => $order->id,
            'log_type' => 'SHOPPING_NEGOTIATION',
            'trigger_type' => 'DRIVER_PRICE_QUOTED',
            'changed_by_user_id' => $driverUser->id,
            'note' => 'Quote test.',
            'metadata' => [
                'quoted_amount' => $amount,
                'status' => 'PENDING_CUSTOMER',
            ],
        ]);
    }

    private function seedDeliveryFeeQuote(Order $order, User $driverUser, float $amount): void
    {
        OrderLog::query()->create([
            'order_id' => $order->id,
            'log_type' => 'DELIVERY_FEE_NEGOTIATION',
            'trigger_type' => 'DRIVER_FEE_QUOTED',
            'changed_by_user_id' => $driverUser->id,
            'note' => 'Quote test.',
            'metadata' => [
                'old_delivery_fee' => round((float) $order->delivery_fee, 2),
                'quoted_amount' => $amount,
                'status' => 'PENDING_CUSTOMER',
            ],
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
