<?php

namespace Tests\Feature\Formula;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Maps\GoogleMapsDistanceMatrixService;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingFailedTripCompensationService;
use App\Services\Shopping\ShoppingReplacementProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Replikasi order Nitip nomor 33 (23 Juli 2026): tiga merchant, ketiganya
 * gagal, order dibatalkan dengan biaya.
 *
 * Di lapangan order itu menagih Rp64.000 padahal ongkir terdaftarnya hanya
 * Rp30.000. Penyebabnya: merchant PERTAMA yang dikunjungi langsung gagal,
 * sehingga perjalanan driver dari rumah menuju merchant itu (32.788 m dari
 * total 40.793 m) ikut masuk jarak kompensasi dan melompatkan tarif ke pita
 * >= 25 km.
 *
 * Aturannya sekarang: perjalanan menuju merchant pertama tidak dikompensasi,
 * karena biaya dasar sudah mencakupnya persis seperti pada order yang
 * berhasil. Yang diganti hanya perpindahan antar-merchant yang terbuang.
 */
class ShoppingApproachLegCompensationTest extends TestCase
{
    use RefreshDatabase;

    /** Posisi driver di lapangan, ~26 km dari merchant (event 368 order 33). */
    private const DRIVER_LATITUDE = -7.0545411;

    private const DRIVER_LONGITUDE = 110.4359583;

    /** Urutan kunjungan order 33; ketiganya gagal. */
    private const MERCHANTS = [
        'cumi' => ['Cumi Mbledoss Kesongo', -7.29089117, 110.46479396],
        'baloeng' => ['Baloeng Gajah', -7.297, 110.459],
        'gecok' => ['Gecok JOGO ROSO TLOGO', -7.264, 110.486],
    ];

    /** Jarak rute per segmen, persis seperti tercatat di order_events. */
    private const ROUTE_METERS = [
        'cumi' => 32788,    // rumah driver -> merchant pertama
        'baloeng' => 1250,  // antar-merchant
        'gecok' => 6755,    // antar-merchant
    ];

    private const REGISTERED_DELIVERY_FEE = 30000.0;

    public function test_approach_leg_is_recorded_but_not_compensated(): void
    {
        [$order, $pickups] = $this->replicateOrder33();

        $approach = $this->failedTripEventFor($order, $pickups['cumi']->id);

        $this->assertSame(32788, data_get($approach->metadata, 'distance_meters'), 'jarak asli tetap tersimpan');
        $this->assertSame(0, data_get($approach->metadata, 'compensable_distance_meters'));
        $this->assertSame(
            ShoppingFailedTripCompensationService::ORIGIN_TYPE_DRIVER,
            data_get($approach->metadata, 'origin_type')
        );

        foreach (['baloeng' => 1250, 'gecok' => 6755] as $key => $meters) {
            $event = $this->failedTripEventFor($order, $pickups[$key]->id);

            $this->assertSame($meters, data_get($event->metadata, 'compensable_distance_meters'), $key);
            $this->assertSame(
                ShoppingFailedTripCompensationService::ORIGIN_TYPE_MERCHANT,
                data_get($event->metadata, 'origin_type'),
                $key
            );
        }
    }

    public function test_cancellation_fee_no_longer_exceeds_the_registered_delivery_fee(): void
    {
        [$order] = $this->replicateOrder33();

        $compensation = app(ShoppingFailedTripCompensationService::class);
        $pricing = app(ShoppingPricingService::class);
        $summary = $compensation->summary($order->refresh());

        $this->assertTrue($summary['eligible']);
        $this->assertSame(3, $summary['verified_failed_trip_count']);
        $this->assertSame(1250 + 6755, $summary['failed_distance_meters'], 'hanya perpindahan antar-merchant');
        $this->assertSame(21000.0, $summary['base_route_fee'], 'O(8.005 m) = 5.000 + 8 x 2.000');
        $this->assertSame(10500.0, $summary['amount'], 'lapangan mencatat 64.000');

        $penalty = $pricing->calculateCancellationPenalty($order);
        $this->assertSame(15000.0, $penalty, '0,5 x ongkir terdaftar Rp30.000');

        // Persamaan (5) mode penaltyOnly: yang ditagih adalah max(P, C).
        $charged = $pricing->calculateForItems(
            (int) $order->service_type_id,
            [],
            0.0,
            cancellationPenalty: $penalty,
            penaltyOnly: true,
            failedTripCompensation: $summary['amount'],
        );

        $this->assertSame(15000.0, $charged['total_price']);
        $this->assertLessThanOrEqual(
            self::REGISTERED_DELIVERY_FEE,
            $charged['total_price'],
            'tagihan pembatalan tidak boleh melebihi ongkir order yang selesai'
        );
    }

    public function test_driver_receives_what_the_customer_is_charged(): void
    {
        // Tiga perpindahan antar-merchant sepanjang 20 km membuat kompensasi
        // mengalahkan penalti: O(20.005 m) = 5.000 + 20 x 2.500 = 55.000.
        $order = $this->orderWithMerchantToMerchantFailures([6000, 7000, 7005]);

        $pricing = app(ShoppingPricingService::class);
        $penalty = $pricing->calculateCancellationPenalty($order);
        $compensation = $pricing->chargeableFailedTripCompensationAmount($order);

        $this->assertSame(15000.0, $penalty);
        $this->assertSame(27500.0, $compensation, 'kompensasi mengalahkan penalti');

        // Dulu driver hanya menerima P walau customer ditagih max(P, C).
        $this->assertSame(
            27500.0,
            $pricing->cancellationDriverFeeAmount($order),
            'pendapatan driver mengikuti nominal yang ditagihkan'
        );
    }

    public function test_orders_recorded_before_this_rule_keep_their_full_distance(): void
    {
        // Event lama tidak punya compensable_distance_meters sama sekali.
        $order = $this->orderWithMerchantToMerchantFailures([1200, 800, 900], legacy: true);

        $summary = app(ShoppingFailedTripCompensationService::class)->summary($order);

        $this->assertSame(2900, $summary['failed_distance_meters'], 'jarak penuh, tidak dihitung ulang surut');
    }

    /**
     * @return array{0: Order, 1: array<string, OrderLocation>}
     */
    private function replicateOrder33(): array
    {
        // Radius dilebarkan seperti saat pengujian lapangan, supaya kegagalan
        // tetap terverifikasi walau driver menguji dari 26 km.
        Config::set('bangdeliv.failed_trip.verification_radius_meters', 50000);

        $this->app->instance(
            GoogleMapsDistanceMatrixService::class,
            new Order33RouteDistances(self::MERCHANTS, self::ROUTE_METERS)
        );

        $driver = $this->createDriverAtFieldPosition();
        $order = $this->createOrder($driver);

        $pickups = [];
        $sequence = 1;
        foreach (self::MERCHANTS as $key => [$label, $latitude, $longitude]) {
            $pickups[$key] = OrderLocation::query()->create([
                'order_id' => $order->id,
                'location_role' => 'PICKUP',
                'label' => $label,
                'full_address' => $label,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'sequence_no' => $sequence++,
                'fulfillment_status' => 'PENDING',
            ]);
        }

        $service = app(ShoppingFailedTripCompensationService::class);
        $evidenceId = 11;
        foreach ($pickups as $pickup) {
            $pickup->update(['fulfillment_status' => 'FAILED', 'failed_attempt_count' => 1]);
            $service->recordFailure(
                $order,
                $pickup,
                null,
                'Tempat tutup/order batal saat driver tiba.',
                'MERCHANT_CLOSED',
                $service->isDriverWithinMerchantRadius($order, $pickup),
                $evidenceId++,
            );
        }

        return [$order, $pickups];
    }

    /**
     * Order dengan kegagalan yang seluruhnya berasal dari perpindahan
     * antar-merchant, dibuat langsung sebagai event agar jaraknya pasti.
     *
     * @param  array<int, int>  $distances
     */
    private function orderWithMerchantToMerchantFailures(array $distances, bool $legacy = false): Order
    {
        $order = $this->createOrder($this->createDriverAtFieldPosition());

        foreach ($distances as $index => $meters) {
            $pickup = OrderLocation::query()->create([
                'order_id' => $order->id,
                'location_role' => 'PICKUP',
                'label' => 'Merchant '.($index + 1),
                'full_address' => 'Jl. Merchant '.($index + 1),
                'latitude' => -7.29 - ($index / 1000),
                'longitude' => 110.46 + ($index / 1000),
                'sequence_no' => $index + 1,
                'fulfillment_status' => 'FAILED',
                'failed_attempt_count' => 1,
            ]);

            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => ShoppingReplacementProjectionService::FAILED_TRIP_EVENT,
                'trigger_type' => 'SHOPPING_FAILED_TRIP_RECORDED',
                'note' => 'Tempat tutup saat driver tiba.',
                'metadata' => array_merge([
                    'pickup_location_id' => $pickup->id,
                    'chain_id' => 'pickup:'.$pickup->id,
                    'distance_meters' => $meters,
                    'verified_for_compensation' => true,
                ], $legacy ? [] : [
                    'compensable_distance_meters' => $meters,
                    'origin_type' => ShoppingFailedTripCompensationService::ORIGIN_TYPE_MERCHANT,
                ]),
            ]);
        }

        return $order->refresh();
    }

    private function failedTripEventFor(Order $order, int $pickupLocationId): OrderLog
    {
        $event = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::FAILED_TRIP_EVENT)
            ->get()
            ->first(fn (OrderLog $log): bool => (int) data_get($log->metadata, 'pickup_location_id') === $pickupLocationId);

        $this->assertInstanceOf(OrderLog::class, $event);

        return $event;
    }

    private function createDriverAtFieldPosition(): Driver
    {
        $user = User::query()->create([
            'name' => 'Bang Jek',
            'email' => 'bangjek'.random_int(1, 99999).'@example.com',
            'phone' => '0812'.random_int(10000000, 99999999),
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        return Driver::query()->create($this->driverAttributes([
            'user_id' => $user->id,
            'vehicle_plate' => 'H '.random_int(1000, 9999).' JK',
            'registration_status' => 'active',
            'status' => 'busy',
            'latitude' => self::DRIVER_LATITUDE,
            'longitude' => self::DRIVER_LONGITUDE,
            'location_updated_at' => now(),
        ]));
    }

    private function createOrder(Driver $driver): Order
    {
        return Order::query()->create([
            'order_number' => 'BD-230726-'.strtoupper(substr(md5((string) random_int(1, 999999)), 0, 6)),
            'user_id' => User::factory()->create(['role' => 'customer'])->id,
            'service_type_id' => (int) ServiceType::query()->where('code', 'SHOPPING')->value('id'),
            'driver_id' => $driver->id,
            'delivery_address' => 'rt 1 rw 3, Bejalen, Kec. Ambarawa',
            'delivery_latitude' => -7.28,
            'delivery_longitude' => 110.47,
            'subtotal' => 0,
            'delivery_fee' => self::REGISTERED_DELIVERY_FEE,
            'service_fee' => 0,
            'total_amount' => self::REGISTERED_DELIVERY_FEE,
            'total_price' => self::REGISTERED_DELIVERY_FEE,
            'status_id' => (int) OrderStatus::query()->where('code', 'ARRIVED_MERCHANT')->value('id'),
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);
    }
}

/**
 * Jarak rute order 33, dikunci pada koordinat tujuan tiap segmen.
 */
class Order33RouteDistances extends GoogleMapsDistanceMatrixService
{
    /**
     * @param  array<string, array{0: string, 1: float, 2: float}>  $merchants
     * @param  array<string, int>  $routeMeters
     */
    public function __construct(
        private readonly array $merchants,
        private readonly array $routeMeters,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolveRoute(
        float $originLat,
        float $originLng,
        float $destinationLat,
        float $destinationLng
    ): array {
        foreach ($this->merchants as $key => [, $latitude, $longitude]) {
            if (abs($latitude - $destinationLat) < 0.000001 && abs($longitude - $destinationLng) < 0.000001) {
                return [
                    'distance_meters' => $this->routeMeters[$key],
                    'duration_seconds' => 0,
                    'route_provider' => 'routes_api',
                    'route_status' => 'OK',
                ];
            }
        }

        throw new \RuntimeException('Segmen rute di luar skenario lapangan order 33.');
    }
}
