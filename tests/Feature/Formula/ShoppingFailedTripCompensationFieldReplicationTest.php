<?php

namespace Tests\Feature\Formula;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Driver\DriverIncomeFeeCalculator;
use App\Services\Maps\GoogleMapsDistanceMatrixService;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingFailedTripCompensationService;
use App\Services\Shopping\ShoppingReplacementProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Replikasi order Nitip nomor 35 (23 Juli 2026) memakai koordinat merchant dan
 * jarak rute yang benar-benar dikembalikan Routes API di lapangan.
 *
 * Order itu mencatat tiga kegagalan merchant tetapi kompensasi perjalanan gagal
 * tetap Rp0. Test ini mengunci dua hal sekaligus:
 *
 *  1. Rumus benar. Bila ketiga kegagalan terjadi saat driver memang berada di
 *     lokasi, kompensasi yang muncul adalah 0,5 x O(3.351 m) = Rp5.500, ongkir
 *     tetap Rp11.000, dan pendapatan driver naik menjadi Rp16.500 bruto.
 *  2. Nol di lapangan bukan salah rumus. Dengan posisi driver yang sebenarnya
 *     (Semarang, ~29,7 km dari merchant Salatiga) verifikasi kehadiran gagal,
 *     sehingga kompensasi memang harus nol -- dan alasannya kini tercatat.
 *
 * Jarak rute per segmen diambil apa adanya dari order_events order 35, jadi
 * angka rupiah di sini bukan hasil simulasi jarak melainkan hasil perhitungan
 * atas data lapangan.
 */
class ShoppingFailedTripCompensationFieldReplicationTest extends TestCase
{
    use RefreshDatabase;

    /** Posisi driver saat menguji dari rumah (event 368 order 35). */
    private const DRIVER_FIELD_LATITUDE = -7.0545321;

    private const DRIVER_FIELD_LONGITUDE = 110.4359288;

    /** Koordinat merchant dari tabel order_locations order 35. */
    private const MERCHANTS = [
        'biron' => ['Soto Pak Biron', -7.314, 110.477],
        'geprek' => ['Warung Geprek Mbak Nur', -7.320, 110.465],
        'aa' => ['Waroeng AA', -7.321, 110.464],
        'gundul' => ['Nasi Goreng Pak Gundul Prapatan Sraten', -7.320, 110.466],
        'bakmi' => ['Bakmi Remaja 3', -7.32054670, 110.47382503],
        'sate' => ['Sate Ayam Cak Sabari', -7.320, 110.471],
    ];

    /** Jarak rute per segmen, persis seperti tercatat di order_events. */
    private const ROUTE_METERS = [
        'biron' => 36030,
        'geprek' => 2237,
        'aa' => 233,
        'gundul' => 305,
        'bakmi' => 881,
        'sate' => 347,
    ];

    private const REGISTERED_DELIVERY_FEE = 11000.0;

    public function test_driver_on_site_earns_five_thousand_five_hundred_compensation(): void
    {
        [$order, $driver, $pickups] = $this->replicateOrder35(driverStaysAtHome: false);

        $service = app(ShoppingFailedTripCompensationService::class);
        $summary = $service->summary($order->refresh());

        $this->assertTrue($summary['eligible']);
        $this->assertSame(3, $summary['verified_failed_trip_count']);
        $this->assertSame(
            2237 + 233 + 881,
            $summary['failed_distance_meters'],
            'D = Biron->Geprek + Geprek->Waroeng AA + Gundul->Bakmi'
        );
        $this->assertSame(11000.0, $summary['base_route_fee'], 'O(3.351 m) = 5.000 + 3 x 2.000');
        $this->assertSame(5500.0, $summary['amount'], '50% x O(D)');

        // Persamaan (5) untuk pesanan yang BERLANJUT: service_fee = P + C,
        // dengan P = 0 karena order tidak dibatalkan.
        $pricing = app(ShoppingPricingService::class)->calculateForItems(
            (int) $order->service_type_id,
            [['quantity' => 1, 'unit_price' => 30000.0]],
            self::REGISTERED_DELIVERY_FEE,
            cancellationPenalty: 0.0,
            failedTripCompensation: $summary['amount'],
        );

        $this->assertSame(30000.0, $pricing['subtotal']);
        $this->assertSame(11000.0, $pricing['delivery_fee'], 'ongkir tidak terpengaruh kompensasi');
        $this->assertSame(5500.0, $pricing['service_fee']);
        $this->assertSame(46500.0, $pricing['total_price'], 'lapangan mencatat 41.000 tanpa kompensasi');

        // Pendapatan driver: ongkir + kompensasi, dipotong admin 10%.
        $gross = app(DriverIncomeFeeCalculator::class)->grossIncomeForOrder($order->refresh());
        $breakdown = app(DriverIncomeFeeCalculator::class)->breakdown($gross);

        $this->assertSame(16500.0, $gross);
        $this->assertSame(1650.0, $breakdown['admin_fee']);
        $this->assertSame(14850.0, $breakdown['net_income']);

        unset($driver, $pickups);
    }

    public function test_field_position_thirty_kilometres_away_records_why_it_was_rejected(): void
    {
        [$order, , $pickups] = $this->replicateOrder35(driverStaysAtHome: true);

        $service = app(ShoppingFailedTripCompensationService::class);
        $summary = $service->summary($order->refresh());

        $this->assertFalse($summary['eligible'], 'inilah yang terjadi di lapangan');
        $this->assertSame(0, $summary['verified_failed_trip_count']);
        $this->assertSame(0.0, $summary['amount']);

        // Diagnostik baru: alasannya tercatat, tidak lagi perlu ditebak.
        foreach (['geprek', 'aa', 'bakmi'] as $key) {
            $event = $this->failedTripEventFor($order, $pickups[$key]->id);
            $verification = data_get($event->metadata, 'verification');

            $this->assertSame('OUTSIDE_RADIUS', $verification['unverified_reason'], $key);
            $this->assertSame(200, $verification['radius_meters']);
            $this->assertTrue($verification['has_evidence'], 'foto bukti tetap terunggah');
            $this->assertTrue($verification['driver_location_is_fresh'], 'GPS bukan penyebabnya');
            $this->assertGreaterThan(29000, $verification['driver_distance_meters'], $key);
            $this->assertLessThan(30000, $verification['driver_distance_meters'], $key);
        }
    }

    public function test_widened_radius_lets_the_same_field_position_be_verified(): void
    {
        Config::set('bangdeliv.failed_trip.verification_radius_meters', 50000);

        [$order] = $this->replicateOrder35(driverStaysAtHome: true);

        $summary = app(ShoppingFailedTripCompensationService::class)->summary($order->refresh());

        $this->assertTrue($summary['eligible'], 'radius 50 km menutup jarak Semarang-Salatiga');
        $this->assertSame(3, $summary['verified_failed_trip_count']);
        $this->assertSame(5500.0, $summary['amount'], 'nominalnya sama dengan uji di lokasi');
    }

    public function test_every_failure_emits_a_compensation_snapshot_even_when_unverified(): void
    {
        [$order] = $this->replicateOrder35(driverStaysAtHome: true);

        $snapshots = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::COMPENSATION_EVENT)
            ->orderBy('id')
            ->get();

        // Sebelumnya hanya satu event terbit untuk tiga kegagalan, karena
        // ringkasannya tidak berubah (0/0). Justru kasus itu yang perlu terlihat.
        $this->assertCount(3, $snapshots);
        $this->assertSame(
            [1, 2, 3],
            $snapshots->map(fn (OrderLog $log): int => (int) data_get($log->metadata, 'failed_trip_count'))->all()
        );
        $this->assertStringContainsString('0 dari 3 kegagalan terverifikasi', (string) $snapshots->last()->note);
    }

    /**
     * Menjalankan ulang urutan kunjungan order 35: Biron (buka) -> Geprek
     * (gagal) -> Waroeng AA (gagal) -> Gundul (buka) -> Bakmi (gagal) -> Sate
     * (buka). Merchant pertama sengaja yang BUKA, persis seperti di lapangan,
     * sehingga posisi driver terpakai oleh checkpoint yang tidak gagal.
     *
     * @return array{0: Order, 1: Driver, 2: array<string, OrderLocation>}
     */
    private function replicateOrder35(bool $driverStaysAtHome): array
    {
        $this->app->instance(
            GoogleMapsDistanceMatrixService::class,
            new FieldRouteDistances(self::MERCHANTS, self::ROUTE_METERS)
        );

        $driver = $this->createDriver();
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
        $evidenceId = 18;

        // ['kunci merchant', gagal?]
        $itinerary = [['biron', false], ['geprek', true], ['aa', true], ['gundul', false], ['bakmi', true], ['sate', false]];

        foreach ($itinerary as [$key, $isFailure]) {
            $pickup = $pickups[$key];
            $this->moveDriver(
                $driver,
                $driverStaysAtHome ? self::DRIVER_FIELD_LATITUDE : (float) $pickup->latitude,
                $driverStaysAtHome ? self::DRIVER_FIELD_LONGITUDE : (float) $pickup->longitude,
            );

            if (! $isFailure) {
                $service->recordCheckpoint($order, $pickup, null);

                continue;
            }

            // Predikat yang sama dengan CustomerOrderService::recordFailedAttempt:
            // failure_type PICKUP && foto bukti ada && driver di dalam radius.
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

        return [$order, $driver, $pickups];
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

    private function moveDriver(Driver $driver, float $latitude, float $longitude): void
    {
        $driver->update([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_updated_at' => now(),
        ]);
    }

    private function createDriver(): Driver
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
        ]));
    }

    private function createOrder(Driver $driver): Order
    {
        return Order::query()->create([
            'order_number' => 'BD-230726-'.strtoupper(substr(md5((string) random_int(1, 999999)), 0, 6)),
            'user_id' => User::factory()->create(['role' => 'customer'])->id,
            'service_type_id' => (int) ServiceType::query()->where('code', 'SHOPPING')->value('id'),
            'driver_id' => $driver->id,
            'delivery_address' => 'rt 2 rw 1, Sraten, Kec. Tuntang',
            'delivery_latitude' => -7.32006027,
            'delivery_longitude' => 110.47065206,
            'subtotal' => 30000,
            'delivery_fee' => self::REGISTERED_DELIVERY_FEE,
            'service_fee' => 0,
            'total_amount' => 41000,
            'total_price' => 41000,
            'status_id' => (int) OrderStatus::query()->where('code', 'ARRIVED_MERCHANT')->value('id'),
            'payment_status' => 'unpaid',
            'payment_method' => 'COD',
        ]);
    }
}

/**
 * Mengembalikan jarak rute yang tercatat di lapangan alih-alih memanggil
 * Google, dikunci pada koordinat tujuan tiap segmen.
 */
class FieldRouteDistances extends GoogleMapsDistanceMatrixService
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

        throw new \RuntimeException('Segmen rute di luar skenario lapangan order 35.');
    }
}
