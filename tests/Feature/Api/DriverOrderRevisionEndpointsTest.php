<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\ShoppingOrder;
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

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 22000,
            'delivery_fee_source' => 'manual',
            'manual_delivery_fee' => 22000,
            'manual_delivery_fee_reason' => 'Rute sistem kurang akurat.',
            'total_price' => 22000,
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

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'delivery_fee' => 12000,
            'delivery_fee_source' => 'manual',
            'manual_delivery_fee' => 8000,
            'manual_delivery_fee_reason' => 'Barang besar dan perlu bantuan.',
            'total_price' => 12000,
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

        ShoppingOrder::query()->create([
            'order_id' => $order->id,
            'failed_attempt_count' => 3,
            'item_surcharge' => 0,
            'overweight_surcharge' => 0,
            'cancellation_penalty' => 0,
            'has_overweight_item' => false,
            'recalculation_version' => 0,
        ]);

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
            'payment_status' => 'PENDING',
            'amount' => 5000,
        ]);
    }

    public function test_shopping_can_confirm_picked_up_after_receipt_total_and_proof_are_ready(): void
    {
        [$driverUser, $driver] = $this->createDriver();
        $order = $this->createAssignedOrder($driver, 'SHOPPING', 'ARRIVED_MERCHANT', 6000);

        ShoppingOrder::query()->create([
            'order_id' => $order->id,
            'failed_attempt_count' => 0,
            'item_surcharge' => 0,
            'overweight_surcharge' => 0,
            'cancellation_penalty' => 0,
            'has_overweight_item' => false,
            'recalculation_version' => 0,
            'pricing_snapshot' => [
                'driver_shopping_total_amount' => 25000,
            ],
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
            'verification_mode' => 'AUTO_24H',
            'verification_status' => 'PENDING',
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

        ShoppingOrder::query()->create([
            'order_id' => $order->id,
            'failed_attempt_count' => 0,
            'item_surcharge' => 0,
            'overweight_surcharge' => 0,
            'cancellation_penalty' => 0,
            'has_overweight_item' => false,
            'recalculation_version' => 0,
        ]);

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

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'subtotal' => 56000,
            'total_price' => 61000,
        ]);
        $this->assertDatabaseHas('shopping_orders', [
            'order_id' => $order->id,
        ]);

        $shoppingOrder = ShoppingOrder::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(56000.0, (float) $shoppingOrder->pricing_snapshot['driver_shopping_total_amount']);

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

    private function createAssignedOrder(Driver $driver, string $serviceCode, string $statusCode, int $amount): Order
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
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => $amount,
        ]);

        return $order;
    }
}
