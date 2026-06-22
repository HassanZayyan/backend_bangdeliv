<?php

namespace Tests\Feature\Api;

use App\Models\CourierOrder;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
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

    public function test_shopping_accepts_manual_delivery_fee_for_normal_negotiation(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'DRIVER_ASSIGNED', 15000);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 22500,
            'reason' => 'Belanja banyak dan perlu bantuan.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee_negotiation.status', 'PENDING_CUSTOMER')
            ->assertJsonPath('data.delivery_fee_negotiation.quoted_amount', 22500);
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
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DELIVERED', 18000);

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

    private function approveShoppingQuoteForTest(Order $order, User $driverUser, User $customer, float $amount): void
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
