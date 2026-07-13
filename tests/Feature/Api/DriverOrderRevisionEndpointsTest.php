<?php

namespace Tests\Feature\Api;

use App\Models\CourierOrder;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderItem;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\Restaurant;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Driver\DriverIncomeFeeCalculator;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingFailedTripCompensationService;
use App\Services\Shopping\ShoppingReplacementProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverOrderRevisionEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_upload_structured_order_proof(): void
    {
        Storage::fake('public');
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'COURIER', 'ARRIVED_PICKUP', 15000);

        Sanctum::actingAs($driverUser);

        $response = $this->post('/api/v1/driver/orders/'.$order->id.'/proofs', [
            'type' => 'pickup',
            'photo' => UploadedFile::fake()->image('pickup.jpg', 800, 600),
            'note' => 'Barang sudah diambil.',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.proofs.0.type', 'pickup');

        $this->assertDatabaseHas('order_evidence', [
            'order_id' => $order->id,
            'user_id' => $driverUser->id,
            'evidence_type' => 'PICKUP_PHOTO',
        ]);
    }

    public function test_ride_transition_does_not_require_lifecycle_proofs(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'ARRIVED_PICKUP', 18000);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'BOARD_PASSENGER',
            'target_status_code' => 'ON_THE_WAY',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_code', 'ON_THE_WAY');
    }

    public function test_driver_lifecycle_proof_is_rejected_for_ride(): void
    {
        Storage::fake('public');
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'ARRIVED_PICKUP', 18000);

        Sanctum::actingAs($driverUser);

        $response = $this->post('/api/v1/driver/orders/'.$order->id.'/proofs', [
            'type' => 'pickup',
            'photo' => UploadedFile::fake()->image('pickup.jpg', 800, 600),
        ], ['Accept' => 'application/json']);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_payment_transfer_proof_is_allowed_for_ride(): void
    {
        Storage::fake('public');
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DELIVERED', 18000);

        Sanctum::actingAs($driverUser);

        $response = $this->post('/api/v1/driver/orders/'.$order->id.'/proofs', [
            'type' => 'payment_transfer',
            'photo' => UploadedFile::fake()->image('transfer.jpg', 800, 600),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.proofs.0.type', 'payment_transfer');
    }

    public function test_shopping_rejects_pickup_and_delivery_proofs(): void
    {
        Storage::fake('public');
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 15000);

        Sanctum::actingAs($driverUser);

        foreach (['pickup', 'delivery'] as $type) {
            $response = $this->post('/api/v1/driver/orders/'.$order->id.'/proofs', [
                'type' => $type,
                'photo' => UploadedFile::fake()->image($type.'.jpg', 800, 600),
            ], ['Accept' => 'application/json']);

            $response->assertUnprocessable()
                ->assertJsonPath('success', false);
        }
    }

    public function test_shopping_accepts_receipt_and_store_closed_proofs(): void
    {
        Storage::fake('public');
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 15000);

        Sanctum::actingAs($driverUser);

        foreach (['receipt', 'store_closed'] as $type) {
            $response = $this->post('/api/v1/driver/orders/'.$order->id.'/proofs', [
                'type' => $type,
                'photo' => UploadedFile::fake()->image($type.'.jpg', 800, 600),
            ], ['Accept' => 'application/json']);

            $response->assertCreated()
                ->assertJsonPath('success', true);
        }

        $this->assertDatabaseHas('order_evidence', [
            'order_id' => $order->id,
            'evidence_type' => 'SHOPPING_RECEIPT',
        ]);
        $this->assertDatabaseHas('order_evidence', [
            'order_id' => $order->id,
            'evidence_type' => 'STORE_CLOSED_PHOTO',
        ]);
    }

    public function test_driver_can_override_delivery_fee_with_audit_reason(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'COURIER', 'DRIVER_ASSIGNED', 15000);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 22000,
            'reason' => 'Rute sistem kurang akurat.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', 15000)
            ->assertJsonPath('data.delivery_fee_source', 'system')
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'PENDING_CUSTOMER')
            ->assertJsonPath('data.delivery_fee_negotiation.quoted_amount', 22000);
        $this->assertArrayNotHasKey('manual_delivery_fee_reason', $response->json('data'));

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 15000,
            'total_price' => 15000,
            'delivery_fee_source' => 'system',
        ]);

        Sanctum::actingAs(User::query()->findOrFail($order->user_id));

        $approve = $this->postJson('/api/v1/orders/'.$order->id.'/delivery-fee-override/respond', [
            'action' => 'APPROVE',
        ]);

        $approve->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', '22000.00')
            ->assertJsonPath('data.delivery_fee_source', 'driver_manual')
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'APPROVED');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 22000,
            'total_price' => 22000,
            'delivery_fee_source' => 'driver_manual',
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 22000,
        ]);
        $event = \App\Models\OrderLog::query()
            ->where('order_id', $order->id)
            ->where('trigger_type', 'CUSTOMER_DELIVERY_FEE_APPROVED')
            ->firstOrFail();
        $this->assertSame('Rute sistem kurang akurat.', $event->metadata['reason'] ?? null);
        $this->assertSame('customer', $event->metadata['changed_by_role'] ?? null);
        $this->assertArrayNotHasKey('recalculation_version', $event->getAttributes());

        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.delivery_fee', '22000.00')
            ->assertJsonPath('data.delivery_fee_source', 'driver_manual')
            ->assertJsonPath('data.delivery_fee_change_note', 'Rute sistem kurang akurat.');
    }

    public function test_courier_manual_delivery_fee_uses_driver_amount_without_extra_surcharge(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'COURIER', 'DRIVER_ASSIGNED', 5000);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 8000,
            'reason' => 'Barang besar dan perlu bantuan.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', 5000)
            ->assertJsonPath('data.delivery_fee_source', 'system')
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'PENDING_CUSTOMER')
            ->assertJsonPath('data.delivery_fee_negotiation.base_amount', 8000)
            ->assertJsonPath('data.delivery_fee_negotiation.quoted_amount', 8000);

        Sanctum::actingAs(User::query()->findOrFail($order->user_id));

        $this->postJson('/api/v1/orders/'.$order->id.'/delivery-fee-override/respond', [
            'action' => 'APPROVE',
        ])->assertOk()
            ->assertJsonPath('data.delivery_fee', '8000.00')
            ->assertJsonPath('data.delivery_fee_source', 'driver_manual');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 8000,
            'total_price' => 8000,
            'delivery_fee_source' => 'driver_manual',
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 8000,
        ]);

        Sanctum::actingAs($driverUser);
        $detail = $this->getJson('/api/v1/driver/orders/'.$order->id)
            ->assertOk();
        $breakdown = collect($detail->json('data.fee_breakdown'));
        $this->assertSame(8000.0, (float) $breakdown->firstWhere('code', 'manual_override')['amount']);
    }

    public function test_courier_delivery_fee_revision_requires_manual_amount(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'COURIER', 'DRIVER_ASSIGNED', 10000);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'reason' => 'Tidak ada nominal.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_courier_delivery_fee_revision_requires_reason(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'COURIER', 'DRIVER_ASSIGNED', 10000);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 12000,
            'reason' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_shopping_rejects_manual_total_transport_before_all_merchants_are_approved(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'DRIVER_ASSIGNED', 15000);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 22500,
            'reason' => 'Belanja banyak dan perlu bantuan.',
        ]);

        $response->assertConflict()
            ->assertJsonPath('success', false);
    }

    public function test_shopping_allows_manual_delivery_fee_until_checkout_is_saved(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 15000);

        $customer = User::query()->findOrFail($order->user_id);
        $firstPickup = $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Toko Pertama',
            'full_address' => 'Jl. Toko Pertama',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'sequence_no' => 1,
            'fulfillment_status' => 'ITEMS_CONFIRMED',
        ]);
        $secondPickup = $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Toko Kedua',
            'full_address' => 'Jl. Toko Kedua',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'sequence_no' => 2,
            'fulfillment_status' => 'ITEMS_CONFIRMED',
        ]);
        foreach ([$firstPickup, $secondPickup] as $index => $pickup) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'pickup_location_id' => $pickup->id,
                'item_source' => 'MANUAL',
                'menu_name' => 'Item '.($index + 1),
                'quantity' => 1,
                'unit_price' => 0,
                'subtotal' => 0,
                'is_available' => true,
            ]);
        }

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 22500,
            'reason' => 'Belum semua toko selesai.',
        ])->assertConflict();

        $firstPickup->update(['fulfillment_status' => 'PRICE_APPROVED']);
        $this->approveShoppingQuoteForTest($order, $driverUser, $customer, 20000, $firstPickup);
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 22500,
            'reason' => 'Baru satu toko selesai.',
        ])->assertConflict();

        $secondPickup->update(['fulfillment_status' => 'PRICE_APPROVED']);
        $this->approveShoppingQuoteForTest($order, $driverUser, $customer, 30000, $secondPickup);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 22500,
            'reason' => 'Semua toko selesai sebelum checkout.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'PENDING_CUSTOMER')
            ->assertJsonPath('data.delivery_fee_negotiation.pricing_scope', 'SHOPPING_TOTAL_TRANSPORT')
            ->assertJsonPath('data.delivery_fee_negotiation.previous_total_transport', 15000);

        $checkoutSavedOrder = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 15000);
        $checkoutCustomer = User::query()->findOrFail($checkoutSavedOrder->user_id);
        $this->approveShoppingQuoteForTest($checkoutSavedOrder, $driverUser, $checkoutCustomer, 50000);
        $checkoutSavedOrder->shoppingReceipt()->create([
            'total_amount' => 50000,
            'recorded_by_user_id' => $driverUser->id,
            'recorded_at' => now(),
        ]);

        $this->postJson('/api/v1/driver/orders/'.$checkoutSavedOrder->id.'/delivery-fee-override', [
            'amount' => 23000,
            'reason' => 'Checkout sudah tersimpan.',
        ])->assertConflict();
    }

    public function test_shopping_all_in_approval_replaces_delivery_fee_and_failed_trip_compensation(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 20000, 'TRANSFER');
        $customer = User::query()->findOrFail($order->user_id);
        $this->createFailedPickup($order, 3);
        $this->approveShoppingQuoteForTest($order, $driverUser, $customer, 50000);

        Sanctum::actingAs($driverUser);
        $proposal = $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 26000,
            'reason' => 'Koreksi final seluruh perjalanan Nitip.',
        ]);

        $proposal->assertOk()
            ->assertJsonPath('data.delivery_fee_negotiation.pricing_scope', 'SHOPPING_TOTAL_TRANSPORT')
            ->assertJsonPath('data.delivery_fee_negotiation.previous_total_transport', 30000)
            ->assertJsonPath('data.delivery_fee_negotiation.replaced_delivery_fee', 20000)
            ->assertJsonPath('data.delivery_fee_negotiation.replaced_failed_trip_compensation', 10000);

        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/orders/'.$order->id.'/delivery-fee-override/respond', [
            'action' => 'APPROVE',
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 26000,
            'total_price' => 76000,
            'delivery_fee_source' => 'driver_manual',
        ]);
        $this->assertSame(0.0, (float) $order->refresh()->service_fee);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 76000,
        ]);

        Sanctum::actingAs($driverUser);
        $detail = $this->getJson('/api/v1/driver/orders/'.$order->id);
        $detail->assertOk()
            ->assertJsonPath('data.delivery_fee_negotiation.active_pricing_scope', 'SHOPPING_TOTAL_TRANSPORT')
            ->assertJsonPath('data.pricing.failed_trip_compensation', 0)
            ->assertJsonPath('data.pricing.service_fee', 0)
            ->assertJsonPath('data.pricing.total_price', 76000);
        $this->assertSame([], $detail->json('data.pricing.fee_breakdown'));
        $this->assertSame(26000.0, app(DriverIncomeFeeCalculator::class)->grossIncomeForOrder($order->refresh()));
    }

    public function test_legacy_shopping_manual_fee_without_scope_keeps_failed_trip_compensation_separate(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 20000);
        $customer = User::query()->findOrFail($order->user_id);
        $this->createFailedPickup($order, 3);
        $this->approveShoppingQuoteForTest($order, $driverUser, $customer, 50000);
        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => 'DELIVERY_FEE_NEGOTIATION',
            'trigger_type' => 'CUSTOMER_FEE_APPROVED',
            'changed_by_user_id' => $customer->id,
            'note' => 'Proposal lama tanpa scope.',
            'metadata' => [
                'approved_amount' => 24000,
                'final_amount' => 24000,
                'status' => 'APPROVED',
            ],
        ]);
        $order->update([
            'delivery_fee' => 24000,
            'delivery_fee_source' => 'driver_manual',
        ]);

        app(ShoppingPricingService::class)->recalculate(
            $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt', 'orderLocations']),
            $driverUser->id,
            'LEGACY_MANUAL_FEE_TEST'
        );

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 24000,
            'total_price' => 86000,
        ]);
        $this->assertSame(12000.0, (float) $order->refresh()->service_fee);
    }

    public function test_shopping_all_in_scope_survives_customer_counter_and_driver_acceptance(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 15000);
        $customer = User::query()->findOrFail($order->user_id);
        $this->approveShoppingQuoteForTest($order, $driverUser, $customer, 50000);

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 25000,
            'reason' => 'Total final perjalanan.',
        ])->assertOk();

        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/orders/'.$order->id.'/delivery-fee-override/respond', [
            'action' => 'COUNTER',
            'counter_amount' => 23000,
        ])->assertOk()
            ->assertJsonPath('data.delivery_fee_negotiation.pricing_scope', 'SHOPPING_TOTAL_TRANSPORT');

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override/accept-counter')
            ->assertOk()
            ->assertJsonPath('data.delivery_fee_negotiation.pricing_scope', 'SHOPPING_TOTAL_TRANSPORT')
            ->assertJsonPath('data.delivery_fee_negotiation.active_pricing_scope', 'SHOPPING_TOTAL_TRANSPORT')
            ->assertJsonPath('data.pricing.failed_trip_compensation', 0)
            ->assertJsonPath('data.pricing.total_price', 73000);
    }

    public function test_driver_can_bypass_pending_delivery_fee_for_all_service_types(): void
    {
        [$driverUser, $driver] = $this->createDriver();

        foreach (['RIDE', 'COURIER', 'SHOPPING'] as $serviceCode) {
            $isShopping = $serviceCode === 'SHOPPING';
            $order = $this->createAssignedOrder(
                $driver,
                $serviceCode,
                $isShopping ? 'ARRIVED_MERCHANT' : 'DRIVER_ASSIGNED',
                15000
            );
            if ($isShopping) {
                $this->approveShoppingQuoteForTest(
                    $order,
                    $driverUser,
                    User::query()->findOrFail($order->user_id),
                    50000
                );
            }

            Sanctum::actingAs($driverUser);

            $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
                'amount' => 21000,
                'reason' => 'Customer tidak respons saat uji coba.',
            ])->assertOk()
                ->assertJsonPath('data.delivery_fee_negotiation.status', 'PENDING_CUSTOMER');

            $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override/bypass');

            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.delivery_fee_negotiation.status', 'APPROVED')
                ->assertJsonPath('data.delivery_fee_negotiation.approved_amount', 21000);
            if ($isShopping) {
                $response
                    ->assertJsonPath('data.delivery_fee_negotiation.pricing_scope', 'SHOPPING_TOTAL_TRANSPORT')
                    ->assertJsonPath('data.delivery_fee_negotiation.active_pricing_scope', 'SHOPPING_TOTAL_TRANSPORT')
                    ->assertJsonPath('data.pricing.failed_trip_compensation', 0);
            }

            $expectedTotal = $isShopping ? 71000 : 21000;

            $this->assertDatabaseHas('orders', [
                'id' => $order->id,
                'delivery_fee' => 21000,
                'total_price' => $expectedTotal,
                'delivery_fee_source' => 'driver_manual',
            ]);
            $this->assertDatabaseHas('order_payments', [
                'order_id' => $order->id,
                'payment_status' => 'PENDING',
                'amount' => $expectedTotal,
            ]);

            $event = OrderLog::query()
                ->where('order_id', $order->id)
                ->where('event_type', 'DELIVERY_FEE_NEGOTIATION')
                ->where('trigger_type', 'DRIVER_FEE_APPROVED_BY_DRIVER_BYPASS')
                ->firstOrFail();
            $this->assertTrue((bool) ($event->metadata['bypassed_by_driver'] ?? false));
            $this->assertSame($driverUser->id, (int) $event->changed_by_user_id);

            Sanctum::actingAs(User::query()->findOrFail($order->user_id));
            $this->getJson('/api/v1/orders/'.$order->id)
                ->assertOk()
                ->assertJsonPath('data.delivery_fee_change_note', 'Customer tidak respons saat uji coba.');
        }
    }

    public function test_driver_delivery_fee_bypass_requires_pending_customer_quote(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DRIVER_ASSIGNED', 15000);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override/bypass')
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Belum ada revisi ongkir yang menunggu persetujuan customer.');
    }

    public function test_shopping_cancel_with_fee_uses_current_delivery_fee_as_penalty_base(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 10000);
        $order->update([
            'delivery_fee_source' => 'driver_manual',
        ]);

        $this->createFailedPickup($order, 3);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'CANCEL_WITH_FEE',
            'target_status_code' => 'CANCELLED_WITH_FEE',
            'note' => 'Merchant gagal tiga kali.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status_code', 'CANCELLED_WITH_FEE')
            ->assertJsonPath('data.pricing.cancellation_penalty', 5000)
            ->assertJsonPath('data.pricing.delivery_fee', 0)
            ->assertJsonPath('data.pricing.total_price', 5000);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
            'amount' => 5000,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'trigger_type' => 'SYSTEM_PAYMENT_METHOD_CHANGED_AFTER_FAILED_ATTEMPTS',
        ]);
    }

    public function test_driver_can_set_full_delivery_fee_base_for_shopping_half_fee_cancellation(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 10000);
        $this->createFailedPickup($order, 3);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'CANCEL_WITH_FEE',
            'target_status_code' => 'CANCELLED_WITH_FEE',
            'note' => 'Rute aktual lebih jauh dari estimasi sistem.',
            'cancellation_penalty_base_delivery_fee' => 100000,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status_code', 'CANCELLED_WITH_FEE')
            ->assertJsonPath('data.pricing.delivery_fee', 0)
            ->assertJsonPath('data.pricing.service_fee', 50000)
            ->assertJsonPath('data.pricing.cancellation_penalty', 50000)
            ->assertJsonPath('data.pricing.failed_trip_compensation', 0)
            ->assertJsonPath('data.pricing.total_price', 50000);

        $detail = $this->getJson('/api/v1/driver/orders/'.$order->id);
        $detail->assertOk()
            ->assertJsonPath('data.pricing.cancellation_penalty_base_delivery_fee', 100000)
            ->assertJsonPath('data.pricing.cancellation_penalty_percent', 50)
            ->assertJsonPath('data.driver_income_gross', 50000);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 0,
            'total_price' => 50000,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
            'amount' => 50000,
        ]);

        $audit = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('trigger_type', ShoppingPricingService::MANUAL_CANCELLATION_TRIGGER)
            ->firstOrFail();
        $this->assertSame(ShoppingPricingService::PRICING_SCOPE_SHOPPING_CANCELLATION_BASE_50_PERCENT, data_get($audit->metadata, 'pricing_scope'));
        $this->assertSame(100000.0, (float) data_get($audit->metadata, 'cancellation_penalty_base_delivery_fee'));
        $this->assertSame(50.0, (float) data_get($audit->metadata, 'cancellation_penalty_percent'));
        $this->assertSame(50000.0, (float) data_get($audit->metadata, 'cancellation_penalty'));
        $this->assertSame('Rute aktual lebih jauh dari estimasi sistem.', data_get($audit->metadata, 'reason'));
        $this->assertSame($driverUser->id, $audit->changed_by_user_id);
    }

    public function test_manual_shopping_cancellation_base_requires_reason_and_correct_action(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $shopping = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 10000);
        $this->createFailedPickup($shopping, 3);
        $ride = $this->createAssignedOrder($driver, 'RIDE', 'DRIVER_ASSIGNED', 10000);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/driver/orders/'.$shopping->id.'/status-transition', [
            'action_code' => 'CANCEL_WITH_FEE',
            'target_status_code' => 'CANCELLED_WITH_FEE',
            'cancellation_penalty_base_delivery_fee' => 100000,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Alasan koreksi ongkir pembatalan wajib diisi.');

        $this->postJson('/api/v1/driver/orders/'.$ride->id.'/status-transition', [
            'action_code' => 'ARRIVE_PICKUP',
            'cancellation_penalty_base_delivery_fee' => 100000,
            'note' => 'Tidak boleh dipakai untuk ride.',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Basis ongkir pembatalan hanya berlaku untuk pembatalan Nitip dengan fee 50%.');
    }

    public function test_cancel_with_fee_is_rejected_after_payment_paid(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 10000);
        OrderPayment::query()
            ->where('order_id', $order->id)
            ->update([
                'payment_method' => 'TRANSFER',
                'payment_status' => 'PAID',
                'amount' => 10000,
                'paid_at' => now(),
            ]);

        $this->createFailedPickup($order, 3);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'CANCEL_WITH_FEE',
            'target_status_code' => 'CANCELLED_WITH_FEE',
            'note' => 'Merchant gagal tiga kali.',
        ]);

        $response->assertConflict()
            ->assertJsonPath('success', false);
    }

    public function test_customer_cannot_change_payment_method_after_order_created_for_all_service_types(): void
    {
        [, $driver] = $this->createDriver();

        foreach (['RIDE', 'COURIER', 'SHOPPING'] as $serviceCode) {
            $order = $this->createAssignedOrder($driver, $serviceCode, 'DRIVER_ASSIGNED', 18000);
            $customer = User::query()->findOrFail($order->user_id);

            Sanctum::actingAs($customer);

            $response = $this->patchJson('/api/v1/orders/'.$order->id.'/payment-method', [
                'payment_method' => 'TRANSFER',
            ]);

            $response->assertConflict()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Metode pembayaran sudah dikunci saat order dibuat dan tidak bisa diubah.');

            $this->assertDatabaseHas('order_payments', [
                'order_id' => $order->id,
                'payment_method' => 'COD',
                'payment_status' => 'PENDING',
                'amount' => 18000,
            ]);

            $transferOrder = $this->createAssignedOrder(
                $driver,
                $serviceCode,
                'DRIVER_ASSIGNED',
                18000,
                'TRANSFER',
            );
            $transferCustomer = User::query()->findOrFail($transferOrder->user_id);

            Sanctum::actingAs($transferCustomer);

            $reverseResponse = $this->patchJson('/api/v1/orders/'.$transferOrder->id.'/payment-method', [
                'payment_method' => 'COD',
            ]);

            $reverseResponse->assertConflict()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'Metode pembayaran sudah dikunci saat order dibuat dan tidak bisa diubah.');

            $this->assertDatabaseHas('order_payments', [
                'order_id' => $transferOrder->id,
                'payment_method' => 'TRANSFER',
                'payment_status' => 'PENDING',
                'amount' => 18000,
            ]);
        }
    }

    public function test_customer_cannot_upload_transfer_evidence_for_cod_order(): void
    {
        Storage::fake('public');
        [, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DRIVER_ASSIGNED', 18000);
        $customer = User::query()->findOrFail($order->user_id);

        Sanctum::actingAs($customer);

        $response = $this->post('/api/v1/orders/'.$order->id.'/payment/transfer/evidence', [
            'photo' => UploadedFile::fake()->image('transfer.jpg', 800, 600),
            'note' => 'Transfer manual.',
        ], ['Accept' => 'application/json']);

        $response->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Bukti QRIS hanya bisa diupload untuk order dengan metode pembayaran QRIS.');

        $this->assertDatabaseMissing('order_evidence', [
            'order_id' => $order->id,
            'evidence_type' => 'PAYMENT_TRANSFER_PHOTO',
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => 18000,
        ]);
    }

    public function test_customer_can_upload_transfer_evidence_for_transfer_order(): void
    {
        Storage::fake('public');
        [, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DRIVER_ASSIGNED', 18000, 'TRANSFER');
        $customer = User::query()->findOrFail($order->user_id);

        Sanctum::actingAs($customer);

        $uploadResponse = $this->post('/api/v1/orders/'.$order->id.'/payment/transfer/evidence', [
            'photo' => UploadedFile::fake()->image('transfer.jpg', 800, 600),
            'note' => 'Transfer manual.',
        ], ['Accept' => 'application/json']);

        $uploadResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_method', 'TRANSFER')
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->assertDatabaseHas('order_evidence', [
            'order_id' => $order->id,
            'evidence_type' => 'PAYMENT_TRANSFER_PHOTO',
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
            'amount' => 18000,
        ]);
    }

    public function test_driver_detail_includes_customer_transfer_evidence_for_all_service_types(): void
    {
        Storage::fake('public');
        [$driverUser, $driver] = $this->createDriver();

        foreach (['RIDE', 'COURIER', 'SHOPPING'] as $serviceCode) {
            $order = $this->createAssignedOrder(
                $driver,
                $serviceCode,
                'DRIVER_ASSIGNED',
                18000,
                'TRANSFER',
            );
            $customer = User::query()->findOrFail($order->user_id);

            Sanctum::actingAs($customer);

            $this->post('/api/v1/orders/'.$order->id.'/payment/transfer/evidence', [
                'photo' => UploadedFile::fake()->image(
                    strtolower($serviceCode).'-transfer.jpg',
                    800,
                    600,
                ),
                'note' => 'Transfer '.$serviceCode,
            ], ['Accept' => 'application/json'])->assertCreated();

            Sanctum::actingAs($driverUser);

            $detailResponse = $this->getJson('/api/v1/driver/orders/'.$order->id);

            $detailResponse->assertOk()
                ->assertJsonPath('data.payment_method', 'TRANSFER')
                ->assertJsonPath('data.proofs.0.type', 'payment_transfer')
                ->assertJsonPath('data.proofs.0.status', 'pending')
                ->assertJsonPath('data.proofs.0.note', 'Transfer '.$serviceCode);

            $this->assertNotEmpty($detailResponse->json('data.proofs.0.photo_url'));
            $this->assertNotEmpty($detailResponse->json('data.proofs.0.uploaded_at'));
        }
    }

    public function test_shopping_can_confirm_picked_up_after_receipt_total_and_proof_are_ready(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 6000);
        $pickup = $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Merchant Test',
            'full_address' => 'Jl. Merchant Test',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'sequence_no' => 1,
            'fulfillment_status' => 'PENDING',
        ]);

        $order->shoppingReceipt()->create([
            'total_amount' => 25000,
            'recorded_by_user_id' => $driverUser->id,
            'recorded_at' => now(),
        ]);
        $this->approveShoppingQuoteForTest($order, $driverUser, $order->user, 25000);

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

        OrderEvidence::query()->create([
            'order_id' => $order->id,
            'user_id' => $driverUser->id,
            'evidence_type' => 'SHOPPING_RECEIPT',
            'file_url' => 'http://localhost/storage/orders/'.$order->id.'/receipts/receipt.jpg',
            'uploaded_at' => now(),
        ]);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'CONFIRM_PICKED_UP',
            'target_status_code' => 'PICKED_UP',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_code', 'PICKED_UP');
    }

    public function test_shopping_checkout_uses_existing_receipt_proof_and_receipt_total(): void
    {
        Storage::fake('public');
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 5000);
        $pickup = $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Merchant Test',
            'full_address' => 'Jl. Merchant Test',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'sequence_no' => 1,
            'fulfillment_status' => 'PENDING',
        ]);

        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Sepatu',
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'is_available' => true,
            'metadata' => ['price_status' => 'PENDING_DRIVER_INPUT'],
        ]);
        $this->approveShoppingQuoteForTest($order, $driverUser, $order->user, 56000);

        Sanctum::actingAs($driverUser);

        $proofResponse = $this->post('/api/v1/driver/orders/'.$order->id.'/proofs', [
            'type' => 'receipt',
            'photo' => UploadedFile::fake()->image('receipt.jpg', 800, 600),
        ], ['Accept' => 'application/json']);

        $proofResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.proofs.0.type', 'receipt');

        $checkoutResponse = $this->patchJson('/api/v1/driver/orders/'.$order->id.'/shopping-checkout', [
            'shopping_total_amount' => 56000,
            'items' => [
                [
                    'id' => $item->id,
                    'quantity' => 1,
                    'is_available' => true,
                ],
            ],
        ]);

        $checkoutResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pricing.subtotal', 56000)
            ->assertJsonPath('data.pricing.has_pending_manual_prices', false)
            ->assertJsonPath('data.has_pending_shopping_prices', false)
            ->assertJsonPath('data.shopping_capabilities.has_checkout_saved', true);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'total_price' => 61000,
        ]);
        $this->assertDatabaseHas('shopping_order_receipts', [
            'order_id' => $order->id,
            'total_amount' => 56000,
            'recorded_by_user_id' => $driverUser->id,
        ]);

        $actions = collect($checkoutResponse->json('data.available_actions'));
        $confirmAction = $actions->firstWhere('action_code', 'CONFIRM_PICKED_UP');
        $this->assertIsArray($confirmAction);
        $this->assertFalse((bool) ($confirmAction['blocked'] ?? true));
        $this->assertSame('', (string) ($confirmAction['blocked_reason'] ?? ''));

        $detailResponse = $this->getJson('/api/v1/driver/orders/'.$order->id);

        $detailResponse->assertOk()
            ->assertJsonPath('data.pricing.subtotal', 56000)
            ->assertJsonPath('data.pricing.has_pending_manual_prices', false)
            ->assertJsonPath('data.has_pending_shopping_prices', false)
            ->assertJsonPath('data.shopping_capabilities.has_checkout_saved', true);

        $detailAction = collect($detailResponse->json('data.available_actions'))
            ->firstWhere('action_code', 'CONFIRM_PICKED_UP');
        $this->assertIsArray($detailAction);
        $this->assertFalse((bool) ($detailAction['blocked'] ?? true));
        $this->assertSame('', (string) ($detailAction['blocked_reason'] ?? ''));

        $logCount = OrderLog::query()->where('order_id', $order->id)->count();
        $secondCheckoutResponse = $this->patchJson('/api/v1/driver/orders/'.$order->id.'/shopping-checkout', [
            'shopping_total_amount' => 56000,
            'items' => [
                [
                    'id' => $item->id,
                    'quantity' => 1,
                    'is_available' => true,
                ],
            ],
        ]);

        $secondCheckoutResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shopping_capabilities.has_checkout_saved', true);
        $this->assertSame($logCount, OrderLog::query()->where('order_id', $order->id)->count());
    }

    public function test_ride_accepts_manual_delivery_fee_for_normal_negotiation(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DRIVER_ASSIGNED', 18000);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 27000,
            'reason' => 'Tidak berlaku untuk ride.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'PENDING_CUSTOMER')
            ->assertJsonPath('data.delivery_fee_negotiation.quoted_amount', 27000);
    }

    public function test_customer_counter_delivery_fee_requires_driver_approval(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DRIVER_ASSIGNED', 15000);
        $customer = User::query()->findOrFail($order->user_id);

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 22000,
            'reason' => 'Rute lebih jauh dari estimasi.',
        ])->assertOk()
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'PENDING_CUSTOMER');

        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/orders/'.$order->id.'/delivery-fee-override/respond', [
            'action' => 'COUNTER',
            'counter_amount' => 19000,
        ])->assertOk()
            ->assertJsonPath('data.delivery_fee', '15000.00')
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'PENDING_DRIVER')
            ->assertJsonPath('data.delivery_fee_negotiation.counter_amount', 19000);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 15000,
            'total_price' => 15000,
        ]);

        Sanctum::actingAs($driverUser);
        $this->getJson('/api/v1/driver/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'PENDING_DRIVER')
            ->assertJsonPath('data.delivery_fee_negotiation.counter_amount', 19000)
            ->assertJsonPath('data.delivery_fee_negotiation.can_driver_accept_counter', true);

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override/accept-counter')
            ->assertOk()
            ->assertJsonPath('data.delivery_fee', 19000)
            ->assertJsonPath('data.delivery_fee_source', 'driver_manual')
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'APPROVED');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 19000,
            'total_price' => 19000,
            'delivery_fee_source' => 'driver_manual',
        ]);
    }

    public function test_pending_delivery_fee_revision_blocks_driver_progress(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DRIVER_ASSIGNED', 15000);

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 22000,
            'reason' => 'Titik jemput berubah.',
        ])->assertOk();

        $detail = $this->getJson('/api/v1/driver/orders/'.$order->id)
            ->assertOk();
        $action = collect($detail->json('data.available_actions'))
            ->firstWhere('action_code', 'ARRIVE_PICKUP');
        $this->assertIsArray($action);
        $this->assertTrue((bool) ($action['blocked'] ?? false));
        $this->assertStringContainsString('Revisi ongkir', (string) ($action['blocked_reason'] ?? ''));

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'ARRIVE_PICKUP',
            'target_status_code' => 'ARRIVED_PICKUP',
        ])->assertConflict()
            ->assertJsonPath('success', false);
    }

    public function test_delivery_fee_revision_is_rejected_after_cutoff_or_payment_proof(): void
    {
        Storage::fake('public');
        [$driverUser, $driver] = $this->createDriver();
        $lateOrder = $this->createAssignedOrder($driver, 'COURIER', 'ARRIVED_PICKUP', 15000);

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/driver/orders/'.$lateOrder->id.'/delivery-fee-override', [
            'amount' => 22000,
            'reason' => 'Terlambat edit.',
        ])->assertConflict()
            ->assertJsonPath('success', false);

        $transferOrder = $this->createAssignedOrder($driver, 'RIDE', 'DRIVER_ASSIGNED', 15000, 'TRANSFER');
        $customer = User::query()->findOrFail($transferOrder->user_id);

        Sanctum::actingAs($customer);
        $this->post('/api/v1/orders/'.$transferOrder->id.'/payment/transfer/evidence', [
            'photo' => UploadedFile::fake()->image('qris.jpg', 800, 600),
        ], ['Accept' => 'application/json'])->assertCreated();

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/driver/orders/'.$transferOrder->id.'/delivery-fee-override', [
            'amount' => 22000,
            'reason' => 'Bukti sudah upload.',
        ])->assertConflict()
            ->assertJsonPath('success', false);
    }

    public function test_customer_can_cancel_order_from_delivery_fee_revision_without_fee(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'COURIER', 'DRIVER_ASSIGNED', 15000);
        $customer = User::query()->findOrFail($order->user_id);

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 22000,
            'reason' => 'Paket lebih besar dari estimasi.',
        ])->assertOk();

        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/orders/'.$order->id.'/delivery-fee-override/respond', [
            'action' => 'CANCEL_ORDER',
        ])->assertOk()
            ->assertJsonPath('data.status_ref.code', 'CANCELLED')
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'CANCELLED_ORDER');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'cancelled_by' => 'customer',
            'cancellation_reason' => 'Customer membatalkan order karena menolak revisi ongkir.',
        ]);
        $this->assertNotNull($order->fresh()->cancelled_at);
    }

    public function test_driver_can_record_transfer_payment(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DELIVERED', 18000, 'TRANSFER');

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/payment/transfer/confirm', [
            'amount' => 18000,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_code', 'DELIVERED')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method', 'TRANSFER');
        $this->assertSame(1, OrderPayment::query()->where('order_id', $order->id)->count());

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status_id' => OrderStatus::query()->where('code', 'DELIVERED')->value('id'),
        ]);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PAID',
            'amount' => 18000,
            'recorded_by_user_id' => $driverUser->id,
            'driver_id' => $driver->id,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'PAYMENT_UPDATE',
            'trigger_type' => 'QRIS_PAYMENT_RECORDED_BY_DRIVER',
            'changed_by_user_id' => $driverUser->id,
            'note' => 'Driver mencatat pembayaran QRIS secara manual.',
        ]);
    }

    public function test_driver_can_reject_transfer_payment_proof_like_admin(): void
    {
        Storage::fake('public');
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DELIVERED', 18000, 'TRANSFER');
        $proofPath = 'orders/'.$order->id.'/payments/proof.jpg';
        Storage::disk('public')->put($proofPath, 'proof image');
        $proof = OrderEvidence::query()->create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'evidence_type' => 'PAYMENT_TRANSFER_PHOTO',
            'file_url' => '/storage/'.$proofPath,
            'uploaded_at' => now(),
            'notes' => 'Bukti QRIS customer.',
        ]);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/orders/'.$order->id.'/payment/transfer/reject', [
            'rejection_reason' => 'Nominal tidak sesuai.',
        ])->assertOk()
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.payment_proof_feedback.status', 'rejected')
            ->assertJsonPath('data.payment_proof_feedback.reason', 'Nominal tidak sesuai.');

        Storage::disk('public')->assertMissing($proofPath);
        $this->assertDatabaseMissing('order_evidence', [
            'id' => $proof->id,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
        ]);

        $event = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('trigger_type', 'PAYMENT_PROOF_REJECTED')
            ->firstOrFail();
        $this->assertSame('Nominal tidak sesuai.', $event->note);
        $this->assertSame($proof->id, (int) data_get($event->metadata, 'deleted_evidence_id'));
        $this->assertSame('rejected', data_get($event->metadata, 'payment_proof_status'));
        $this->assertNull(data_get($event->metadata, 'file_url'));
    }

    public function test_driver_reject_transfer_payment_requires_reason_and_assigned_driver(): void
    {
        Storage::fake('public');
        [$driverUser, $driver] = $this->createDriver();
        [$otherDriverUser] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DELIVERED', 18000, 'TRANSFER');
        $proofPath = 'orders/'.$order->id.'/payments/proof.jpg';
        Storage::disk('public')->put($proofPath, 'proof image');
        OrderEvidence::query()->create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'evidence_type' => 'PAYMENT_TRANSFER_PHOTO',
            'file_url' => '/storage/'.$proofPath,
            'uploaded_at' => now(),
            'notes' => 'Bukti QRIS customer.',
        ]);

        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/orders/'.$order->id.'/payment/transfer/reject', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rejection_reason');

        Sanctum::actingAs($otherDriverUser);
        $this->postJson('/api/v1/orders/'.$order->id.'/payment/transfer/reject', [
            'rejection_reason' => 'Bukan bukti order ini.',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Order ini tidak ditugaskan kepada driver saat ini.');
    }

    public function test_driver_cannot_record_transfer_payment_after_reject_until_customer_uploads_new_proof(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DELIVERED', 18000, 'TRANSFER');

        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => 'PAYMENT_UPDATE',
            'trigger_type' => 'PAYMENT_PROOF_REJECTED',
            'changed_by_user_id' => $driverUser->id,
            'note' => 'Nominal tidak sesuai.',
            'metadata' => [
                'deleted_evidence_id' => 99,
                'payment_proof_status' => 'rejected',
            ],
        ]);

        Sanctum::actingAs($driverUser);

        $this->postJson('/api/v1/orders/'.$order->id.'/payment/transfer/confirm', [
            'amount' => 18000,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Bukti QRIS ditolak. Tunggu customer mengirim bukti baru.');

        $newProof = OrderEvidence::query()->create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'evidence_type' => 'PAYMENT_TRANSFER_PHOTO',
            'file_url' => '/storage/orders/'.$order->id.'/payments/proof-new.jpg',
            'uploaded_at' => now(),
            'notes' => 'Bukti QRIS customer terbaru.',
        ]);
        $this->assertSame('pending', $order->fresh(['evidences', 'payment'])->payment_proof_feedback['status']);

        $this->postJson('/api/v1/orders/'.$order->id.'/payment/transfer/confirm', [
            'amount' => 18000,
        ])->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_proof_feedback.status', 'approved');

        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'trigger_type' => 'PAYMENT_PROOF_APPROVED',
            'changed_by_user_id' => $driverUser->id,
            'note' => 'Bukti QRIS disetujui driver.',
        ]);
        $approvalEvent = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('trigger_type', 'PAYMENT_PROOF_APPROVED')
            ->firstOrFail();
        $this->assertSame($newProof->id, (int) data_get($approvalEvent->metadata, 'order_evidence_id'));
        $this->assertSame('approved', data_get($approvalEvent->metadata, 'payment_proof_status'));
    }

    public function test_driver_can_bypass_all_unavailable_items_for_a_merchant(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 15000);
        $pickup = $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Merchant Bypass',
            'full_address' => 'Jl. Merchant Bypass',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'sequence_no' => 1,
            'fulfillment_status' => 'ITEMS_PENDING_CUSTOMER',
            'failed_attempt_count' => 0,
        ]);
        $availableItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Item tersedia',
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal' => 20000,
            'is_available' => true,
        ]);
        $unavailableItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Item kosong',
            'quantity' => 2,
            'unit_price' => 12000,
            'subtotal' => 0,
            'is_available' => false,
        ]);

        Sanctum::actingAs($driverUser);

        $detail = $this->getJson('/api/v1/driver/orders/'.$order->id);
        $detail->assertOk()
            ->assertJsonPath('data.shopping_stops.0.unavailable_item_actions.can_driver_bypass', true);

        $response = $this->postJson(
            '/api/v1/driver/orders/'.$order->id.'/shopping-stops/'.$pickup->id.'/unavailable-items/bypass'
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shopping_stops.0.fulfillment_status', 'ITEMS_CONFIRMED')
            ->assertJsonPath('data.shopping_stops.0.unavailable_item_actions.can_driver_bypass', false);
        $this->assertDatabaseHas('shopping_order_items', ['id' => $availableItem->id]);
        $this->assertDatabaseMissing('shopping_order_items', ['id' => $unavailableItem->id]);
        $event = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('trigger_type', 'DRIVER_BYPASS_UNAVAILABLE_ITEMS')
            ->where('event_type', 'SHOPPING_ITEM_AVAILABILITY')
            ->firstOrFail();
        $this->assertFalse((bool) data_get($event->metadata, 'merchant_cancelled'));
        $this->assertSame($unavailableItem->id, (int) data_get($event->metadata, 'items.0.id'));

        $this->postJson(
            '/api/v1/driver/orders/'.$order->id.'/shopping-stops/'.$pickup->id.'/unavailable-items/bypass'
        )->assertStatus(409);
        $this->assertSame(1, OrderLog::query()
            ->where('order_id', $order->id)
            ->where('trigger_type', 'DRIVER_BYPASS_UNAVAILABLE_ITEMS')
            ->where('event_type', 'SHOPPING_ITEM_AVAILABILITY')
            ->count());
    }

    public function test_driver_can_remove_selected_unavailable_items_and_keep_pending_until_all_resolved(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 15000);
        $pickup = $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Merchant Pilih Item',
            'full_address' => 'Jl. Merchant Pilih Item',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'sequence_no' => 1,
            'fulfillment_status' => 'ITEMS_PENDING_CUSTOMER',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Item tersedia',
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal' => 20000,
            'is_available' => true,
        ]);
        $unavailableItems = collect(['Kosong satu', 'Kosong dua', 'Kosong tiga'])
            ->map(fn (string $name): OrderItem => OrderItem::query()->create([
                'order_id' => $order->id,
                'pickup_location_id' => $pickup->id,
                'item_source' => 'MANUAL',
                'menu_name' => $name,
                'quantity' => 1,
                'unit_price' => 0,
                'subtotal' => 0,
                'is_available' => false,
            ]));

        Sanctum::actingAs($driverUser);
        $endpoint = '/api/v1/driver/orders/'.$order->id.'/shopping-stops/'.$pickup->id.'/unavailable-items/decision';
        $this->getJson('/api/v1/driver/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.shopping_stops.0.unavailable_item_actions.can_driver_continue_without_item', true)
            ->assertJsonPath('data.shopping_stops.0.unavailable_item_actions.can_driver_cancel_merchant', true);

        $this->postJson($endpoint, [
            'action' => 'REMOVE',
            'item_ids' => $unavailableItems->take(2)->pluck('id')->all(),
        ])->assertOk()
            ->assertJsonPath('data.shopping_stops.0.fulfillment_status', 'ITEMS_PENDING_CUSTOMER');
        $this->assertDatabaseHas('shopping_order_items', ['id' => $unavailableItems[2]->id]);

        $this->postJson($endpoint, [
            'action' => 'REMOVE',
            'item_ids' => [$unavailableItems[2]->id],
        ])->assertOk()
            ->assertJsonPath('data.shopping_stops.0.fulfillment_status', 'ITEMS_CONFIRMED');
        $this->assertSame(2, OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'SHOPPING_ITEM_CHANGE_REQUEST')
            ->where('trigger_type', 'DRIVER_UNAVAILABLE_ITEMS_REMOVED')
            ->count());
        $this->postJson($endpoint, [
            'action' => 'REMOVE',
            'item_ids' => [$unavailableItems[2]->id],
        ])->assertStatus(409)
            ->assertJsonPath('errors.code', 'STATE_CHANGED')
            ->assertJsonPath('errors.latest_order.id', (string) $order->id)
            ->assertJsonPath('errors.latest_order.shopping_stops.0.fulfillment_status', 'ITEMS_CONFIRMED');
    }

    public function test_driver_can_cancel_place_because_items_are_unavailable(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 15000);
        $pickup = $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Merchant Batal Item',
            'full_address' => 'Jl. Merchant Batal Item',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'sequence_no' => 1,
            'fulfillment_status' => 'ITEMS_PENDING_CUSTOMER',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Item kosong',
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'is_available' => false,
        ]);

        Sanctum::actingAs($driverUser);
        $endpoint = '/api/v1/driver/orders/'.$order->id.'/shopping-stops/'.$pickup->id.'/unavailable-items/decision';
        $this->postJson($endpoint, ['action' => 'CANCEL_MERCHANT'])
            ->assertOk()
            ->assertJsonPath('data.status_code', 'CANCELLED');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'cancelled_by' => 'driver',
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'trigger_type' => 'DRIVER_CANCEL_UNAVAILABLE_MERCHANT',
            'changed_by_user_id' => $driverUser->id,
        ]);
        $this->assertDatabaseMissing('order_events', [
            'order_id' => $order->id,
            'trigger_type' => 'MERCHANT_CLOSED',
        ]);
    }

    public function test_driver_can_replace_unavailable_items_at_the_same_store_idempotently(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 19000);
        $pickup = $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Toko Suku Cadang',
            'full_address' => 'Jl. Suku Cadang',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'sequence_no' => 1,
            'fulfillment_status' => 'ITEMS_PENDING_CUSTOMER',
        ]);
        $availableItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Oli tersedia',
            'quantity' => 1,
            'unit_price' => 15000,
            'subtotal' => 15000,
            'is_available' => true,
        ]);
        $unavailableItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Busi kosong',
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'is_available' => false,
        ]);
        $payload = ['items' => [[
            'item_source' => 'MANUAL',
            'menu_name' => 'Busi alternatif',
            'quantity' => 2,
            'notes' => 'Merek setara',
        ]]];
        $endpoint = '/api/v1/driver/orders/'.$order->id.'/shopping-stops/'.$pickup->id.'/unavailable-items/replace';

        Sanctum::actingAs($driverUser);

        $this->getJson('/api/v1/driver/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.shopping_stops.0.unavailable_item_actions.can_driver_replace_unavailable_items', true);
        $this->withHeader('Idempotency-Key', 'replace-items-empty')
            ->postJson($endpoint, ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
        $response = $this->withHeader('Idempotency-Key', 'replace-items-driver-1')
            ->postJson($endpoint, $payload);

        $response->assertOk()
            ->assertJsonPath('data.shopping_stops.0.fulfillment_status', 'ITEMS_CONFIRMED')
            ->assertJsonPath('data.shopping_stops.0.unavailable_item_actions.can_driver_replace_unavailable_items', false);
        $this->assertDatabaseHas('shopping_order_items', ['id' => $availableItem->id]);
        $this->assertDatabaseMissing('shopping_order_items', ['id' => $unavailableItem->id]);
        $this->assertDatabaseHas('shopping_order_items', [
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'menu_name' => 'Busi alternatif',
            'quantity' => 2,
            'is_available' => true,
        ]);
        $this->assertSame(19000.0, (float) $order->refresh()->delivery_fee);
        $event = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('trigger_type', 'DRIVER_UNAVAILABLE_ITEMS_REPLACED')
            ->firstOrFail();
        $this->assertSame($driverUser->id, (int) $event->changed_by_user_id);
        $this->assertSame('Busi kosong', data_get($event->metadata, 'old_items.0.name'));
        $this->assertSame('Busi alternatif', data_get($event->metadata, 'items.0.name'));

        $this->withHeader('Idempotency-Key', 'replace-items-driver-1')
            ->postJson($endpoint, $payload)
            ->assertOk();
        $this->assertSame(1, OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'SHOPPING_ITEM_AVAILABILITY')
            ->where('trigger_type', 'DRIVER_UNAVAILABLE_ITEMS_REPLACED')
            ->count());
        $this->assertSame(1, OrderItem::query()
            ->where('order_id', $order->id)
            ->where('menu_name', 'Busi alternatif')
            ->count());

        $changedPayload = $payload;
        $changedPayload['items'][0]['menu_name'] = 'Payload lain';
        $this->withHeader('Idempotency-Key', 'replace-items-driver-1')
            ->postJson($endpoint, $changedPayload)
            ->assertStatus(422);
        $this->withHeader('Idempotency-Key', 'replace-items-driver-2')
            ->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'STATE_CHANGED');
    }

    public function test_driver_bypass_of_last_unavailable_merchant_uses_existing_cancellation_fee_rule(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 18000);
        $pickup = $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Merchant Terakhir',
            'full_address' => 'Jl. Merchant Terakhir',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'sequence_no' => 1,
            'fulfillment_status' => 'ITEMS_PENDING_CUSTOMER',
            'failed_attempt_count' => 2,
        ]);
        $unavailableItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Satu-satunya item',
            'quantity' => 1,
            'unit_price' => 22000,
            'subtotal' => 0,
            'is_available' => false,
        ]);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson(
            '/api/v1/driver/orders/'.$order->id.'/shopping-stops/'.$pickup->id.'/unavailable-items/bypass'
        );

        $response->assertOk()
            ->assertJsonPath('data.status_code', 'CANCELLED_WITH_FEE');
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'cancelled_by' => 'driver',
        ]);
        $this->assertDatabaseHas('order_locations', [
            'id' => $pickup->id,
            'fulfillment_status' => 'ABANDONED_AFTER_LIMIT',
            'failed_attempt_count' => 3,
        ]);
        $this->assertDatabaseMissing('shopping_order_items', ['id' => $unavailableItem->id]);
        $abandonedEvent = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::CHAIN_ABANDONED_EVENT)
            ->firstOrFail();
        $this->assertSame($unavailableItem->id, (int) data_get($abandonedEvent->metadata, 'removed_items.0.id'));
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'trigger_type' => 'DRIVER_BYPASS_UNAVAILABLE_ITEMS_WITH_FEE',
        ]);
    }

    public function test_other_driver_cannot_bypass_unavailable_items(): void
    {
        [, $driver] = $this->createDriver();
        [$otherDriverUser] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 15000);
        $pickup = $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Merchant',
            'full_address' => 'Jl. Merchant',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'sequence_no' => 1,
            'fulfillment_status' => 'ITEMS_PENDING_CUSTOMER',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Item kosong',
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'is_available' => false,
        ]);

        Sanctum::actingAs($otherDriverUser);

        $this->postJson(
            '/api/v1/driver/orders/'.$order->id.'/shopping-stops/'.$pickup->id.'/unavailable-items/bypass'
        )->assertStatus(403)
            ->assertJsonPath('message', 'Order ini tidak ditugaskan kepada driver saat ini.');

        $this->withHeader('Idempotency-Key', 'wrong-driver-replace')
            ->postJson(
                '/api/v1/driver/orders/'.$order->id.'/shopping-stops/'.$pickup->id.'/unavailable-items/replace',
                ['items' => [[
                    'item_source' => 'MANUAL',
                    'menu_name' => 'Item pengganti',
                    'quantity' => 1,
                ]]],
            )
            ->assertStatus(403)
            ->assertJsonPath('message', 'Order ini tidak ditugaskan kepada driver saat ini.');

        $this->postJson(
            '/api/v1/driver/orders/'.$order->id.'/shopping-stops/'.$pickup->id.'/unavailable-items/decision',
            ['action' => 'CANCEL_MERCHANT'],
        )->assertStatus(403)
            ->assertJsonPath('message', 'Order ini tidak ditugaskan kepada driver saat ini.');
    }

    public function test_customer_replaces_unavailable_merchant_with_event_projection_and_idempotency(): void
    {
        [, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 5000);
        $pickup = $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Merchant Lama',
            'full_address' => 'Jl. Merchant Lama',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'sequence_no' => 1,
            'fulfillment_status' => 'ITEMS_PENDING_CUSTOMER',
        ]);
        $order->orderLocations()->create([
            'location_role' => 'DROPOFF',
            'label' => 'Customer',
            'full_address' => 'Jl. Customer',
            'latitude' => -7.015,
            'longitude' => 110.415,
            'sequence_no' => 2,
        ]);
        $oldItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Ayam lama',
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'is_available' => false,
        ]);
        $replacement = Restaurant::query()->create([
            'name' => 'Merchant Pengganti',
            'slug' => 'merchant-pengganti-'.uniqid(),
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Merchant Pengganti',
            'latitude' => -7.008,
            'longitude' => 110.408,
        ]);
        config()->set('bangdeliv.google_maps_api_key', 'test-key');
        Http::fake([
            '*' => Http::response([
                'routes' => [[
                    'distanceMeters' => 3500,
                    'duration' => '600s',
                    'polyline' => ['encodedPolyline' => 'encoded'],
                ]],
            ]),
        ]);

        Sanctum::actingAs($order->user);
        $payload = [
            'expected_version' => 0,
            'merchant_id' => $replacement->id,
            'items' => [[
                'item_source' => 'MANUAL',
                'menu_name' => 'Ayam pengganti',
                'quantity' => 2,
            ]],
        ];

        $this->postJson('/api/v1/orders/'.$order->id.'/shopping-stops/'.$pickup->id.'/replacement-preview', $payload)
            ->assertOk()
            ->assertJsonPath('data.chain_id', 'pickup:'.$pickup->id)
            ->assertJsonPath('data.next_attempt_no', 2);

        $endpoint = '/api/v1/orders/'.$order->id.'/shopping-stops/'.$pickup->id.'/replace';
        $response = $this->withHeader('Idempotency-Key', 'replace-customer-1')->postJson($endpoint, $payload);
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shopping_stops.0.chain_attempt_no', 2)
            ->assertJsonPath('data.delivery_fee_negotiation.note', 'Ongkir diperbarui karena toko/resto diganti.');
        $this->assertDatabaseHas('order_locations', ['id' => $pickup->id, 'fulfillment_status' => 'REPLACED']);
        $this->assertDatabaseMissing('shopping_order_items', ['id' => $oldItem->id]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'SHOPPING_MERCHANT_REPLACEMENT',
            'trigger_type' => 'CUSTOMER_REPLACED_SHOPPING_MERCHANT',
        ]);
        $replacementEvent = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::REPLACEMENT_EVENT)
            ->firstOrFail();
        $this->assertSame($oldItem->id, (int) data_get($replacementEvent->metadata, 'old_items.0.id'));
        $this->assertSame(1, OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'SHOPPING_MERCHANT_REPLACEMENT')
            ->count());

        $this->withHeader('Idempotency-Key', 'replace-customer-1')->postJson($endpoint, $payload)
            ->assertOk();
        $this->assertSame(1, OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'SHOPPING_MERCHANT_REPLACEMENT')
            ->count());
        $changedPayload = $payload;
        $changedPayload['items'][0]['menu_name'] = 'Payload berbeda';
        $this->withHeader('Idempotency-Key', 'replace-customer-1')->postJson($endpoint, $changedPayload)
            ->assertStatus(422);
        $this->withHeader('Idempotency-Key', 'replace-customer-2')->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'STATE_CHANGED');
    }

    public function test_three_global_failed_trips_activate_compensation_without_cancelling_replacement_chains(): void
    {
        [, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 18000);
        $chainIds = [];

        foreach (['A', 'B', 'C'] as $index => $name) {
            $source = $order->orderLocations()->create([
                'location_role' => 'PICKUP',
                'label' => 'Resto '.$name,
                'full_address' => 'Jl. '.$name,
                'latitude' => -7.00 - ($index * 0.01),
                'longitude' => 110.40 + ($index * 0.01),
                'sequence_no' => $index + 1,
                'fulfillment_status' => 'FAILED',
                'failed_attempt_count' => 1,
            ]);
            $replacement = $order->orderLocations()->create([
                'location_role' => 'PICKUP',
                'label' => 'Resto Pengganti '.$name,
                'full_address' => 'Jl. Pengganti '.$name,
                'latitude' => -7.05 - ($index * 0.01),
                'longitude' => 110.45 + ($index * 0.01),
                'sequence_no' => $index + 4,
                'fulfillment_status' => 'PRICE_APPROVED',
                'failed_attempt_count' => 0,
            ]);
            $chainId = 'pickup:'.$source->id;
            $chainIds[] = $chainId;
            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => ShoppingReplacementProjectionService::FAILED_TRIP_EVENT,
                'metadata' => [
                    'pickup_location_id' => $source->id,
                    'chain_id' => $chainId,
                    'distance_meters' => 2000,
                    'verified_for_compensation' => true,
                ],
            ]);
            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => ShoppingReplacementProjectionService::REPLACEMENT_EVENT,
                'metadata' => [
                    'chain_id' => $chainId,
                    'attempt_no' => 2,
                    'source_pickup_location_id' => $source->id,
                    'replacement_pickup_location_id' => $replacement->id,
                ],
            ]);
        }

        $snapshot = app(ShoppingReplacementProjectionService::class)->snapshot($order->refresh());
        $compensation = app(ShoppingFailedTripCompensationService::class)->summary($order->refresh());

        $this->assertSame(3, $snapshot['order_failed_trip_count']);
        $this->assertSame(3, $snapshot['verified_failed_trip_count']);
        $this->assertTrue($snapshot['compensation_eligible']);
        foreach ($chainIds as $chainId) {
            $this->assertSame(1, $snapshot['chains'][$chainId]['failed_attempt_count']);
            $this->assertFalse($snapshot['chains'][$chainId]['is_abandoned']);
        }
        $this->assertTrue($compensation['eligible']);
        $this->assertGreaterThan(0, $compensation['amount']);
        $this->assertSame(
            round(18000 + (float) $compensation['amount'], 2),
            app(DriverIncomeFeeCalculator::class)->grossIncomeForOrder($order->refresh()),
        );
        $this->assertSame('ARRIVED_MERCHANT', $order->refresh()->statusRef->code);
        $this->assertSame(3, $order->orderLocations()->where('fulfillment_status', 'PRICE_APPROVED')->count());
    }

    public function test_third_failure_abandons_only_its_replacement_chain_projection(): void
    {
        [, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 18000);
        $pickups = collect(['A', 'D', 'E'])->map(function (string $name, int $index) use ($order) {
            return $order->orderLocations()->create([
                'location_role' => 'PICKUP',
                'label' => 'Resto '.$name,
                'full_address' => 'Jl. '.$name,
                'latitude' => -7.00 - ($index * 0.01),
                'longitude' => 110.40 + ($index * 0.01),
                'sequence_no' => $index + 1,
                'fulfillment_status' => $index === 2 ? 'ABANDONED_AFTER_LIMIT' : 'REPLACED',
                'failed_attempt_count' => 1,
            ]);
        })->values();
        $chainId = 'pickup:'.$pickups[0]->id;

        foreach ($pickups as $index => $pickup) {
            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => ShoppingReplacementProjectionService::FAILED_TRIP_EVENT,
                'metadata' => [
                    'pickup_location_id' => $pickup->id,
                    'chain_id' => $chainId,
                    'distance_meters' => 1000,
                    'verified_for_compensation' => true,
                ],
            ]);
            if ($index < 2) {
                OrderLog::query()->create([
                    'order_id' => $order->id,
                    'event_type' => ShoppingReplacementProjectionService::REPLACEMENT_EVENT,
                    'metadata' => [
                        'chain_id' => $chainId,
                        'attempt_no' => $index + 2,
                        'source_pickup_location_id' => $pickup->id,
                        'replacement_pickup_location_id' => $pickups[$index + 1]->id,
                    ],
                ]);
            }
        }

        $snapshot = app(ShoppingReplacementProjectionService::class)->snapshot($order->refresh());

        $this->assertSame(3, $snapshot['chains'][$chainId]['failed_attempt_count']);
        $this->assertTrue($snapshot['chains'][$chainId]['is_abandoned']);
        $this->assertFalse($snapshot['pickups'][$pickups[2]->id]['can_replace_merchant']);
        $this->assertSame(3, $snapshot['order_failed_trip_count']);
        $this->assertSame('ARRIVED_MERCHANT', $order->refresh()->statusRef->code);
    }

    public function test_terminal_shopping_order_disables_stale_merchant_replacement_capability(): void
    {
        [, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 10000);
        $this->createFailedPickup($order, 1);
        $pickup = $order->orderLocations()->where('location_role', 'PICKUP')->firstOrFail();
        $projection = app(ShoppingReplacementProjectionService::class);

        $activeSnapshot = $projection->snapshot($order->fresh(['orderLocations', 'statusRef']));
        $this->assertTrue($activeSnapshot['pickups'][$pickup->id]['can_replace_merchant']);

        $order->update([
            'status_id' => OrderStatus::query()->where('code', 'CANCELLED_WITH_FEE')->value('id'),
        ]);
        $terminalSnapshot = $projection->snapshot($order->fresh(['orderLocations', 'statusRef']));

        $this->assertFalse($terminalSnapshot['pickups'][$pickup->id]['can_replace_merchant']);
        $this->assertSame('Order sudah berakhir.', $terminalSnapshot['pickups'][$pickup->id]['replacement_block_reason']);
    }

    /**
     * @return array{0: User, 1: Driver}
     */
    private function createDriver(): array
    {
        $driverUser = User::query()->create([
            'name' => 'Driver Revision',
            'email' => 'driver.revision.'.uniqid().'@example.test',
            'phone' => '0899'.random_int(10000000, 99999999),
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'vehicle_type' => 'motorcycle',
            'vehicle_brand' => 'Honda',
            'vehicle_model' => 'Beat',
            'vehicle_plate' => 'H '.random_int(1000, 9999).' REV',
            'vehicle_color' => 'Hitam',
            'registration_status' => 'active',
            'status' => 'busy',
            'is_available' => true,
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        return [$driverUser, $driver];
    }

    private function createAssignedOrder(
        Driver $driver,
        string $serviceCode,
        string $statusCode,
        int $amount,
        string $paymentMethod = 'COD',
    ): Order {
        $customer = User::factory()->create(['role' => 'customer']);
        $serviceTypeId = (int) ServiceType::query()->where('code', $serviceCode)->value('id');
        $statusId = (int) OrderStatus::query()->where('code', $statusCode)->value('id');

        $order = Order::query()->create([
            'order_number' => 'BD-REV-'.strtoupper(substr(md5($serviceCode.$statusCode.random_int(1, 9999)), 0, 8)),
            'user_id' => $customer->id,
            'restaurant_id' => null,
            'service_type_id' => $serviceTypeId,
            'driver_id' => $driver->id,
            'subtotal' => 0,
            'delivery_fee' => $amount,
            'service_fee' => 0,
            'total_price' => $amount,
            'status_id' => $statusId,
        ]);

        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => strtoupper($paymentMethod) === 'TRANSFER' ? 'TRANSFER' : 'COD',
            'payment_status' => 'PENDING',
            'amount' => $amount,
        ]);

        if ($serviceCode === 'COURIER') {
            CourierOrder::query()->create([
                'order_id' => $order->id,
                'package_description' => 'Paket test',
            ]);
        }

        return $order;
    }

    private function approveShoppingQuoteForTest(
        Order $order,
        User $driverUser,
        User $customer,
        float $amount,
        ?OrderLocation $pickup = null,
    ): void {
        $pickup ??= $order->orderLocations()->where('location_role', 'PICKUP')->first();

        $quote = OrderLog::query()->create([
            'order_id' => $order->id,
            'log_type' => 'SHOPPING_NEGOTIATION',
            'trigger_type' => 'DRIVER_PRICE_QUOTED',
            'changed_by_user_id' => $driverUser->id,
            'note' => 'Quote test.',
            'metadata' => [
                'pickup_location_id' => $pickup?->id,
                'quoted_amount' => $amount,
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

    private function createFailedPickup(Order $order, int $failedAttemptCount): void
    {
        $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Merchant',
            'full_address' => 'Jl. Merchant Failed',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'sequence_no' => 1,
            'fulfillment_status' => 'FAILED',
            'failed_attempt_count' => $failedAttemptCount,
        ]);
    }
}
