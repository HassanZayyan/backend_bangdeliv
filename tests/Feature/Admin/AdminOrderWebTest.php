<?php

namespace Tests\Feature\Admin;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderLocation;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrderWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_settings_orders_index_and_order_detail(): void
    {
        $admin = $this->admin();
        $order = $this->orderWithTransferProof();

        $this->actingAs($admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Konfigurasi Tarif Ongkos Kirim')
            ->assertSee('Read-only');

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Bukti QRIS pending')
            ->assertDontSee('Semua Layanan');

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Bukti QRIS')
            ->assertSee('Setujui')
            ->assertSee('Tolak');
    }

    public function test_admin_order_search_uses_existing_columns_only(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create([
            'role' => 'customer',
            'name' => 'Customer Searchable',
            'phone' => '081111111111',
        ]);

        $order = $this->baseOrder($customer, 'COURIER', 'PENDING');
        $order->courierOrder()->create([
            'package_description' => 'Dokumen penting',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['q' => '081111111111']))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Customer Searchable');
    }

    public function test_pending_qris_proof_appears_in_admin_notifications(): void
    {
        $admin = $this->admin();
        $order = $this->orderWithTransferProof();

        $this->actingAs($admin)
            ->getJson(route('admin.notifications.pending'))
            ->assertOk()
            ->assertJsonPath('data.pending_payment_proofs', 1)
            ->assertJsonPath('data.items.0.title', '#'.$order->order_number);
    }

    public function test_admin_can_approve_qris_proof_and_mark_payment_paid(): void
    {
        $admin = $this->admin();
        $order = $this->orderWithTransferProof();
        $proof = $order->evidences()->where('evidence_type', 'PAYMENT_TRANSFER_PHOTO')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.orders.payment-proofs.approve', ['order' => $order->id, 'evidence' => $proof->id]))
            ->assertRedirect(route('admin.orders.show', ['order' => $order->id, 'focus' => 'payment-proof']));

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PAID',
            'recorded_by_user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'trigger_type' => 'PAYMENT_PROOF_APPROVED',
            'changed_by_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_reject_qris_proof_without_marking_payment_paid(): void
    {
        $admin = $this->admin();
        $order = $this->orderWithTransferProof();
        $proof = $order->evidences()->where('evidence_type', 'PAYMENT_TRANSFER_PHOTO')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.orders.payment-proofs.reject', ['order' => $order->id, 'evidence' => $proof->id]), [
                'rejection_reason' => 'Nominal tidak sesuai.',
            ])
            ->assertRedirect(route('admin.orders.show', ['order' => $order->id, 'focus' => 'payment-proof']));

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
            'recorded_by_user_id' => null,
        ]);

        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'trigger_type' => 'PAYMENT_PROOF_REJECTED',
            'changed_by_user_id' => $admin->id,
            'note' => 'Nominal tidak sesuai.',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.notifications.pending'))
            ->assertOk()
            ->assertJsonPath('data.pending_payment_proofs', 0);
    }

    public function test_admin_order_mutation_api_routes_are_removed(): void
    {
        $admin = $this->admin();
        $order = $this->orderWithTransferProof();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/orders/'.$order->id.'/attempt-failed', [
            'failure_type' => 'merchant_closed',
            'reason' => 'Tutup.',
        ])->assertNotFound();

        $this->postJson('/api/v1/admin/orders/'.$order->id.'/payment/record-cod', [
            'amount' => 10000,
        ])->assertNotFound();
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'phone' => '081300001111',
        ]);
    }

    private function orderWithTransferProof(): Order
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'name' => 'Customer QRIS',
            'phone' => '081300002222',
        ]);

        $order = $this->baseOrder($customer, 'RIDE', 'DELIVERED');

        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PENDING',
            'amount' => 27500,
        ]);

        OrderEvidence::query()->create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'evidence_type' => 'PAYMENT_TRANSFER_PHOTO',
            'file_url' => '/storage/orders/'.$order->id.'/payments/proof.jpg',
            'uploaded_at' => now(),
            'notes' => 'Bukti QRIS customer.',
        ]);

        return $order->fresh(['evidences', 'payment']);
    }

    private function baseOrder(User $customer, string $serviceCode, string $statusCode): Order
    {
        $driverUser = User::factory()->create([
            'role' => 'driver',
            'name' => 'Driver Web Admin',
            'phone' => '081300003333',
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'H 1234 WEB',
            'registration_status' => 'active',
            'status' => 'busy',
        ]));

        $order = Order::query()->create([
            'order_number' => 'BD-WEB-'.strtoupper($serviceCode).'-'.random_int(1000, 9999),
            'user_id' => $customer->id,
            'service_type_id' => ServiceType::query()->where('code', $serviceCode)->value('id'),
            'driver_id' => $driver->id,
            'delivery_fee' => 27500,
            'total_price' => 27500,
            'status_id' => OrderStatus::query()->where('code', $statusCode)->value('id'),
        ]);

        OrderLocation::query()->create([
            'order_id' => $order->id,
            'location_role' => 'PICKUP',
            'label' => 'Titik jemput',
            'full_address' => 'Jl. Pickup Admin No. 1',
            'latitude' => -7.01,
            'longitude' => 110.41,
            'sequence_no' => 1,
            'fulfillment_status' => 'PENDING',
            'failed_attempt_count' => 0,
        ]);

        OrderLocation::query()->create([
            'order_id' => $order->id,
            'location_role' => 'DROPOFF',
            'label' => 'Tujuan',
            'full_address' => 'Jl. Dropoff Admin No. 2',
            'latitude' => -7.02,
            'longitude' => 110.42,
            'sequence_no' => 2,
            'fulfillment_status' => 'PENDING',
            'failed_attempt_count' => 0,
        ]);

        return $order;
    }
}
