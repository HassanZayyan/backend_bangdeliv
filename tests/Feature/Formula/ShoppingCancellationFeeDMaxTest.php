<?php

namespace Tests\Feature\Formula;

use App\Models\Order;
use App\Models\OrderLocation;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Maps\GoogleMapsDistanceMatrixService;
use App\Services\Shopping\ShoppingFailedTripCompensationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Persamaan (6) model revisi: fee pembatalan = 0,5 x O(d_max), dengan d_max =
 * jarak RUTE JALAN terjauh dari lokasi customer ke salah satu toko/resto gagal
 * TERVERIFIKASI. Rute bolak-balik driver tidak diperhitungkan.
 */
class ShoppingCancellationFeeDMaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_fee_is_half_of_route_to_the_farthest_verified_store(): void
    {
        // Tiga toko gagal, jarak rute customer -> toko: 1.200, 2.000, 3.000 m.
        // d_max = 3.000 -> O(3 km) = 5.000 + 3 x 2.000 = 11.000 -> fee 5.500.
        $order = $this->orderWithFailedStores([1200, 2000, 3000], verified: true);

        $summary = app(ShoppingFailedTripCompensationService::class)->summary($order->refresh());

        $this->assertTrue($summary['eligible']);
        $this->assertSame(3, $summary['verified_failed_trip_count']);
        $this->assertSame(3000, $summary['customer_route_distance_meters'], 'd_max = toko terjauh');
        $this->assertSame(11000.0, $summary['base_route_fee'], 'O(3 km)');
        $this->assertSame(5500.0, $summary['amount'], '0,5 x O(d_max)');
    }

    public function test_roundtrip_is_ignored_only_the_farthest_leg_counts(): void
    {
        // Toko terjauh 3.000 m; dua lainnya dekat. Meski driver bolak-balik,
        // fee tetap 0,5 x O(3 km) = 5.500 -- bukan akumulasi segmen.
        $order = $this->orderWithFailedStores([3000, 500, 500], verified: true);

        $this->assertSame(
            5500.0,
            app(ShoppingFailedTripCompensationService::class)->amount($order->refresh()),
        );
    }

    public function test_no_fee_below_three_failures(): void
    {
        $order = $this->orderWithFailedStores([1200, 3000], verified: true);

        $this->assertSame(0.0, app(ShoppingFailedTripCompensationService::class)->amount($order->refresh()));
    }

    public function test_unverified_failures_do_not_count(): void
    {
        // Tiga toko gagal tetapi tak satu pun terverifikasi -> fee 0.
        $order = $this->orderWithFailedStores([1200, 2000, 3000], verified: false);

        $summary = app(ShoppingFailedTripCompensationService::class)->summary($order->refresh());

        $this->assertFalse($summary['eligible']);
        $this->assertSame(0, $summary['customer_route_distance_meters']);
        $this->assertSame(0.0, $summary['amount']);
    }

    /**
     * @param  array<int, int>  $routeDistances  jarak rute customer -> tiap toko (meter)
     */
    private function orderWithFailedStores(array $routeDistances, bool $verified): Order
    {
        // Peta jarak dikunci pada lintang tujuan (toko), supaya recordFailure
        // membekukan jarak rute yang benar tanpa memanggil Google. Fake WAJIB
        // di-inject sebelum service di-resolve.
        $distanceByPickupLat = [];
        foreach ($routeDistances as $index => $meters) {
            $latitude = round(-7.300 - ($index + 1) * 0.01, 4);
            $distanceByPickupLat[number_format($latitude, 4, '.', '')] = $meters;
        }
        $this->app->instance(
            GoogleMapsDistanceMatrixService::class,
            new CustomerRouteDistances($distanceByPickupLat),
        );

        $order = $this->shoppingOrder();
        $this->dropoff($order);
        $service = app(ShoppingFailedTripCompensationService::class);

        foreach ($routeDistances as $index => $meters) {
            $latitude = round(-7.300 - ($index + 1) * 0.01, 4);
            $pickup = OrderLocation::query()->create([
                'order_id' => $order->id,
                'location_role' => 'PICKUP',
                'label' => 'Toko Gagal '.($index + 1),
                'full_address' => 'Jl. Toko '.($index + 1),
                'latitude' => $latitude,
                'longitude' => 110.450,
                'sequence_no' => $index + 1,
                'fulfillment_status' => 'FAILED',
                'failed_attempt_count' => 1,
            ]);

            $service->recordFailure(
                $order,
                $pickup,
                null,
                'Tempat tutup saat driver tiba.',
                'MERCHANT_CLOSED',
                $verified,
            );
        }

        return $order;
    }

    private function shoppingOrder(): Order
    {
        return Order::query()->create([
            'order_number' => 'BD-DMAX-'.strtoupper(substr(md5((string) random_int(1, 999999)), 0, 8)),
            'user_id' => User::factory()->create(['role' => 'customer'])->id,
            'service_type_id' => (int) ServiceType::query()->where('code', 'SHOPPING')->value('id'),
            'delivery_address' => 'rt 1 rw 3, Bejalen, Kec. Ambarawa',
            'delivery_latitude' => -7.32006027,
            'delivery_longitude' => 110.47065206,
            'subtotal' => 0,
            'delivery_fee' => 15000,
            'service_fee' => 0,
            'total_amount' => 15000,
            'total_price' => 15000,
            'status_id' => (int) OrderStatus::query()->where('code', 'ARRIVED_MERCHANT')->value('id'),
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);
    }

    private function dropoff(Order $order): void
    {
        OrderLocation::query()->create([
            'order_id' => $order->id,
            'location_role' => 'DROPOFF',
            'label' => 'Titik Antar',
            'full_address' => 'rt 1 rw 3, Bejalen',
            'latitude' => -7.32006027,
            'longitude' => 110.47065206,
            'sequence_no' => 99,
            'fulfillment_status' => 'PENDING',
        ]);
    }
}

/**
 * Mengembalikan jarak rute customer -> toko dari peta yang dikunci pada
 * lintang tujuan, tanpa memanggil Google.
 */
class CustomerRouteDistances extends GoogleMapsDistanceMatrixService
{
    /**
     * @param  array<string, int>  $distanceByDestinationLat
     */
    public function __construct(private readonly array $distanceByDestinationLat) {}

    public function resolveRoute(float $originLat, float $originLng, float $destinationLat, float $destinationLng): array
    {
        $key = number_format($destinationLat, 4, '.', '');
        $meters = $this->distanceByDestinationLat[$key] ?? 0;

        return [
            'distance_meters' => $meters,
            'distance_km' => round($meters / 1000, 2),
            'distance_text' => number_format($meters / 1000, 2, ',', '.').' km',
            'duration_seconds' => 0,
            'duration_text' => '',
            'route_provider' => 'routes_api',
            'route_status' => 'OK',
        ];
    }
}
