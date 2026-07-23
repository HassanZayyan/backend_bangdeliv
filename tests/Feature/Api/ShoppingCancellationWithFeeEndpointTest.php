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
 * radius + foto bukti) menyentuh kuota tiga -> order batal berbiaya, dengan fee
 * = 0,5 x O(d_max). d_max = jarak rute terjauh customer -> toko gagal.
 */
class ShoppingCancellationWithFeeEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_verified_failed_stores_cancel_with_fee_from_dmax(): void
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

        $lastResponse->assertOk()
            ->assertJsonPath('data.status_ref.code', 'CANCELLED_WITH_FEE')
            ->assertJsonPath('data.delivery_fee', '0.00')
            ->assertJsonPath('data.service_fee', '5500.00')
            ->assertJsonPath('data.total_price', '5500.00')
            ->assertJsonPath('data.payment_method', 'TRANSFER');
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
