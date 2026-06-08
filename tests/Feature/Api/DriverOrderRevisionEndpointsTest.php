<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderItem;
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
            'driver_id' => $driver->id,
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
            'careful_carry_required' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', 22000)
            ->assertJsonPath('data.delivery_fee_source', 'manual')
            ->assertJsonPath('data.manual_delivery_fee_reason', 'Rute sistem kurang akurat.')
            ->assertJsonPath('data.careful_carry_required', false);

        $this->assertDatabaseHas('order_pricings', [
            'order_id' => $order->id,
            'delivery_fee' => 22000,
            'total_price' => 22000,
        ]);
        $this->assertDatabaseHas('order_delivery_fee_overrides', [
            'order_id' => $order->id,
            'amount' => 22000,
            'reason' => 'Rute sistem kurang akurat.',
            'changed_by_user_id' => $driverUser->id,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 22000,
        ]);
    }

    public function test_courier_careful_carry_uses_manual_fee_as_surcharge_base(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'COURIER', 'DRIVER_ASSIGNED', 5000);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 8000,
            'reason' => 'Barang besar dan perlu bantuan.',
            'careful_carry_required' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', 12000)
            ->assertJsonPath('data.delivery_fee_source', 'manual')
            ->assertJsonPath('data.manual_delivery_fee', 8000)
            ->assertJsonPath('data.careful_carry_required', true);

        $this->assertDatabaseHas('order_pricings', [
            'order_id' => $order->id,
            'delivery_fee' => 12000,
            'total_price' => 12000,
        ]);
        $this->assertDatabaseHas('order_delivery_fee_overrides', [
            'order_id' => $order->id,
            'amount' => 8000,
            'reason' => 'Barang besar dan perlu bantuan.',
            'changed_by_user_id' => $driverUser->id,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 12000,
        ]);

        $breakdown = collect($response->json('data.fee_breakdown'));
        $this->assertSame(4000.0, (float) $breakdown->firstWhere('code', 'careful_carry')['amount']);
        $this->assertSame(8000.0, (float) $breakdown->firstWhere('code', 'manual_override')['amount']);
    }

    public function test_shopping_can_enable_careful_carry_delivery_fee(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'DRIVER_ASSIGNED', 15000);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 22500,
            'reason' => 'Belanja banyak dan perlu bantuan.',
            'careful_carry_required' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', 33750)
            ->assertJsonPath('data.careful_carry_required', true);
    }

    public function test_shopping_cancel_with_fee_uses_current_manual_delivery_fee_as_penalty_base(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 10000);
        $order->update([
            'delivery_fee_source' => 'manual',
            'manual_delivery_fee' => 10000,
            'manual_delivery_fee_reason' => 'Ongkir sudah diedit driver.',
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
            ->assertJsonPath('message', 'Bukti transfer hanya bisa diupload untuk order dengan metode pembayaran Transfer.');

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

        $order->shoppingReceipt()->create([
            'total_amount' => 25000,
            'recorded_by_user_id' => $driverUser->id,
            'recorded_at' => now(),
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Telur 1 kg',
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'is_available' => true,
            'is_heavy' => false,
        ]);

        OrderEvidence::query()->create([
            'order_id' => $order->id,
            'driver_id' => $driver->id,
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

        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Sepatu',
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'is_available' => true,
            'is_heavy' => false,
            'metadata' => ['price_status' => 'PENDING_DRIVER_INPUT'],
        ]);

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
            'receipt_note' => 'item kosong',
            'items' => [
                [
                    'id' => $item->id,
                    'quantity' => 1,
                    'is_available' => true,
                    'is_heavy' => false,
                ],
            ],
        ]);

        $checkoutResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pricing.subtotal', 56000)
            ->assertJsonPath('data.pricing.has_pending_manual_prices', false)
            ->assertJsonPath('data.has_pending_shopping_prices', false);

        $this->assertDatabaseHas('order_pricings', [
            'order_id' => $order->id,
            'subtotal' => 56000,
            'total_price' => 61000,
        ]);
        $this->assertDatabaseHas('shopping_receipts', [
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
            ->assertJsonPath('data.has_pending_shopping_prices', false);

        $detailAction = collect($detailResponse->json('data.available_actions'))
            ->firstWhere('action_code', 'CONFIRM_PICKED_UP');
        $this->assertIsArray($detailAction);
        $this->assertFalse((bool) ($detailAction['blocked'] ?? true));
        $this->assertSame('', (string) ($detailAction['blocked_reason'] ?? ''));
    }

    public function test_ride_rejects_careful_carry_delivery_fee_flag(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DRIVER_ASSIGNED', 18000);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/driver/orders/'.$order->id.'/delivery-fee-override', [
            'amount' => 27000,
            'reason' => 'Tidak berlaku untuk ride.',
            'careful_carry_required' => true,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_driver_can_record_transfer_payment(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'RIDE', 'DELIVERED', 18000);

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/payment/transfer/confirm', [
            'amount' => 18000,
            'note' => 'Transfer BCA sudah dicek.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method', 'TRANSFER');

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PAID',
            'amount' => 18000,
            'recorded_by_user_id' => $driverUser->id,
            'driver_id' => $driver->id,
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
            'license_number' => 'SIMC-REV-'.random_int(1000, 9999),
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
    ): Order
    {
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

        return $order;
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
            'failure_reason' => 'Merchant gagal tiga kali.',
            'failed_at' => now(),
        ]);
    }
}
