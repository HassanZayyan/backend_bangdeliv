<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLocation;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alur end-to-end model revisi: tiga toko/resto gagal terverifikasi (driver di
 * radius + foto bukti) menyentuh kuota tiga -> order siap dibatalkan berbiaya,
 * dengan fee = 0,5 x O(d_max). d_max = jarak rute terjauh customer -> toko
 * gagal. Pembatalannya sendiri menunggu konfirmasi driver supaya basis ongkir
 * sempat dikoreksi sebelum customer ditagih.
 */
class ShoppingCancellationWithFeeEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_verified_failed_stores_await_driver_cancellation_decision(): void
    {
        [$order, $lastResponse] = $this->failAllStores();

        // Order sengaja dibiarkan aktif: fee 50% baru ditagihkan setelah driver
        // mengonfirmasi lewat CANCEL_WITH_FEE.
        $lastResponse->assertOk()
            ->assertJsonPath('data.status_ref.code', 'ARRIVED_MERCHANT');

        $this->getJson('/api/v1/driver/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.pricing.can_cancel_with_fee', true)
            ->assertJsonPath('data.shopping_capabilities.awaits_driver_cancellation_fee_review', true)
            ->assertJsonPath('data.shopping_capabilities.can_customer_cancel_shopping_order', false)
            ->assertJsonFragment(['action_code' => 'CANCEL_WITH_FEE']);
    }

    public function test_customer_sees_estimate_before_confirmation_then_flag_clears_after(): void
    {
        [$order] = $this->failAllStores();
        $customer = User::query()->findOrFail($order->user_id);
        $driverUser = User::query()->where('role', 'driver')->firstOrFail();

        // PRA-KONFIRMASI: customer melihat ESTIMASI fee (tanpa QRIS), flag
        // "menunggu driver" masih ON, dan semua toko sudah terminal.
        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.status_ref.code', 'ARRIVED_MERCHANT')
            ->assertJsonPath('data.shopping_capabilities.awaits_driver_cancellation_fee_review', true)
            ->assertJsonPath('data.shopping_capabilities.all_merchants_terminal', true)
            ->assertJsonPath('data.shopping_cancellation_fee.is_eligible', true)
            ->assertJsonPath('data.shopping_cancellation_fee.is_confirmed', false)
            ->assertJsonPath('data.shopping_cancellation_fee.requires_qris', false)
            ->assertJsonPath(
                'data.shopping_cancellation_fee.estimated_amount',
                fn ($amount): bool => (float) $amount === 5500.0,
            );

        // Driver mengonfirmasi fee 50%.
        Sanctum::actingAs($driverUser);
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'CANCEL_WITH_FEE',
            'target_status_code' => 'CANCELLED_WITH_FEE',
            'note' => 'Semua toko tutup, order dibatalkan.',
        ])->assertOk();

        // PASCA-KONFIRMASI: flag "menunggu" MATI (perbaikan urutan "menghitung"),
        // fee terkonfirmasi dan siap dibayar via QRIS.
        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.status_ref.code', 'CANCELLED_WITH_FEE')
            ->assertJsonPath('data.shopping_capabilities.awaits_driver_cancellation_fee_review', false)
            ->assertJsonPath('data.shopping_cancellation_fee.is_confirmed', true)
            ->assertJsonPath('data.shopping_cancellation_fee.requires_qris', true);
    }

    public function test_driver_cancel_with_fee_charges_half_of_dmax_route_fee(): void
    {
        [$order] = $this->failAllStores();

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'CANCEL_WITH_FEE',
            'target_status_code' => 'CANCELLED_WITH_FEE',
            'note' => 'Semua toko tutup, order dibatalkan.',
        ])->assertOk();

        $order->refresh()->load('statusRef');
        $this->assertSame('CANCELLED_WITH_FEE', (string) $order->statusRef->code);
        $this->assertSame(0.0, (float) $order->delivery_fee);
        $this->assertSame(5500.0, (float) $order->service_fee);
        $this->assertSame(5500.0, (float) $order->total_price);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_method' => 'TRANSFER',
        ]);
    }

    public function test_driver_can_correct_the_base_delivery_fee_before_charging_customer(): void
    {
        [$order] = $this->failAllStores();

        // Basis ongkir otomatis 11.000 dinilai meleset; driver mengoreksinya ke
        // 20.000 sehingga customer ditagih 10.000, bukan 5.500.
        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'CANCEL_WITH_FEE',
            'target_status_code' => 'CANCELLED_WITH_FEE',
            'note' => 'Ongkir otomatis tidak akurat, rute sebenarnya lebih jauh.',
            'cancellation_penalty_base_delivery_fee' => 20000,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(10000.0, (float) $order->service_fee);
        $this->assertSame(10000.0, (float) $order->total_price);
    }

    public function test_driver_cancel_with_fee_requires_a_reason_when_base_is_corrected(): void
    {
        [$order] = $this->failAllStores();

        $this->postJson('/api/v1/driver/orders/'.$order->id.'/status-transition', [
            'action_code' => 'CANCEL_WITH_FEE',
            'target_status_code' => 'CANCELLED_WITH_FEE',
            'cancellation_penalty_base_delivery_fee' => 20000,
        ])->assertStatus(422);

        // Order tetap aktif -- tidak ada tagihan yang terlanjur dikirim.
        $this->assertSame(
            'ARRIVED_MERCHANT',
            (string) $order->refresh()->load('statusRef')->statusRef->code,
        );
    }

    /**
     * Gagalkan ketiga toko/resto dengan bukti foto sehingga kuota tiga tercapai
     * dan kompensasi trip gagal menjadi eligible.
     *
     * @return array{0: Order, 1: \Illuminate\Testing\TestResponse}
     */
    private function failAllStores(): array
    {
        Storage::fake('public');
        // Radius dilonggarkan agar kegagalan terverifikasi tanpa harus persis
        // di titik toko (sama seperti mode peragaan).
        Config::set('bangdeliv.failed_trip.verification_radius_meters', 50000);
        // Semua rute customer -> toko dikembalikan 3.000 m -> d_max = 3.000 m
        // -> O(3 km) = 11.000 -> fee 5.500.
        Http::fake([
            'https://routes.googleapis.com/directions/v2:computeRoutes*' => Http::response([
                'routes' => [[
                    'distanceMeters' => 3000,
                    'duration' => '300s',
                    'legs' => [['distanceMeters' => 3000, 'duration' => '300s']],
                ]],
            ]),
        ]);
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        [$driverUser, $order, $pickups] = $this->arrangeOrder();
        Sanctum::actingAs($driverUser);

        $lastResponse = null;
        foreach ($pickups as $pickup) {
            $lastResponse = $this->post('/api/v1/orders/'.$order->id.'/attempt-failed', [
                'failure_type' => 'PICKUP',
                'reason' => 'Tempat tutup saat driver tiba.',
                'pickup_location_id' => $pickup->id,
                'merchant_closed_photo' => UploadedFile::fake()->image('closed.jpg'),
            ]);
        }

        return [$order, $lastResponse];
    }

    /**
     * @return array{0: User, 1: Order, 2: array<int, OrderLocation>}
     */
    private function arrangeOrder(): array
    {
        $driverUser = User::factory()->create(['role' => 'driver']);
        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'H '.random_int(1000, 9999).' FEE',
            'registration_status' => 'active',
            'status' => 'busy',
            // Lokasi driver segar di sekitar area layanan (dalam radius lebar).
            'latitude' => -7.010,
            'longitude' => 110.410,
            'location_updated_at' => now(),
        ]));

        $order = Order::query()->create([
            'order_number' => 'BD-FEE-'.strtoupper(substr(md5((string) random_int(1, 999999)), 0, 8)),
            'user_id' => User::factory()->create(['role' => 'customer'])->id,
            'service_type_id' => (int) ServiceType::query()->where('code', 'SHOPPING')->value('id'),
            'driver_id' => $driver->id,
            'delivery_address' => 'rt 1 rw 3, Bejalen',
            'delivery_latitude' => -7.050,
            'delivery_longitude' => 110.450,
            'subtotal' => 0,
            'delivery_fee' => 11000,
            'service_fee' => 0,
            'total_amount' => 11000,
            'total_price' => 11000,
            'status_id' => (int) OrderStatus::query()->where('code', 'ARRIVED_MERCHANT')->value('id'),
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);

        $pickups = [];
        foreach ([['Toko A', -7.001], ['Toko B', -7.011], ['Toko C', -7.021]] as $index => [$label, $lat]) {
            $pickup = OrderLocation::query()->create([
                'order_id' => $order->id,
                'location_role' => 'PICKUP',
                'label' => $label,
                'full_address' => $label,
                'latitude' => $lat,
                'longitude' => 110.401,
                'sequence_no' => $index + 1,
                'fulfillment_status' => 'ITEMS_CONFIRMED',
            ]);
            OrderItem::query()->create([
                'order_id' => $order->id,
                'pickup_location_id' => $pickup->id,
                'item_source' => 'MANUAL',
                'menu_name' => 'Item '.$label,
                'quantity' => 1,
                'unit_price' => 10000,
                'subtotal' => 10000,
                'is_available' => true,
            ]);
            $pickups[] = $pickup;
        }

        OrderLocation::query()->create([
            'order_id' => $order->id,
            'location_role' => 'DROPOFF',
            'label' => 'Titik Antar',
            'full_address' => 'rt 1 rw 3, Bejalen',
            'latitude' => -7.050,
            'longitude' => 110.450,
            'sequence_no' => 99,
            'fulfillment_status' => 'PENDING',
        ]);

        return [$driverUser, $order, $pickups];
    }
}
