<?php

namespace App\Services\Shopping;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Services\Maps\GoogleMapsDistanceMatrixService;
use App\Services\Pricing\DeliveryPricingService;
use App\Support\GeoDistance;
use Throwable;

/**
 * Fee pembatalan Nitip (Persamaan 6, model revisi).
 *
 * Fee = 0,5 x O(d_max), dengan d_max = jarak RUTE JALAN terjauh dari lokasi
 * customer ke salah satu toko/resto gagal TERVERIFIKASI. Mesin akumulasi
 * segmen (checkpoint per-titik, compensable_distance, origin_type) sudah
 * dihapus: rute bolak-balik driver tidak lagi diperhitungkan. Yang dipertahankan
 * hanya lapisan verifikasi kehadiran driver (radius + foto + GPS segar) yang
 * menentukan keanggotaan himpunan terverifikasi.
 */
class ShoppingFailedTripCompensationService
{
    private const COMPENSATION_PERCENT = 50.0;

    private const DEFAULT_VERIFICATION_RADIUS_METERS = 200;

    private const DEFAULT_DRIVER_LOCATION_FRESH_MINUTES = 5;

    /** Faktor estimasi rute jalan bila Google tidak tersedia (dari garis lurus). */
    private const HAVERSINE_ROUTE_FACTOR = 1.25;

    public function __construct(
        private readonly ShoppingReplacementProjectionService $projection,
        private readonly GoogleMapsDistanceMatrixService $maps,
        private readonly DeliveryPricingService $deliveryPricing,
    ) {}

    public function recordFailure(
        Order $order,
        OrderLocation $pickup,
        ?int $actorId,
        string $reason,
        string $source,
        bool $verifiedForCompensation,
        ?int $evidenceId = null,
    ): OrderLog {
        $existing = $this->failedTripForPickup($order, (int) $pickup->id);
        if ($existing instanceof OrderLog) {
            return $existing;
        }

        $pickupProjection = $this->projection->forPickup($order, (int) $pickup->id);
        $diagnostics = $this->verificationDiagnostics($order, $pickup);
        // Jarak rute customer -> toko/resto ini, dibekukan saat kegagalan
        // dicatat (basis Persamaan 6). Dibekukan agar tetap benar walau titik
        // antar berubah, dan agar pembatalan tidak memanggil Maps lagi.
        $customerRouteDistanceMeters = $this->customerRouteDistanceMeters($order, $pickup);

        $event = OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => ShoppingReplacementProjectionService::FAILED_TRIP_EVENT,
            'trigger_type' => 'SHOPPING_FAILED_TRIP_RECORDED',
            'changed_by_user_id' => $actorId,
            'note' => $reason,
            'metadata' => [
                'pickup_location_id' => (int) $pickup->id,
                'chain_id' => $pickupProjection['chain_id'],
                'chain_attempt_no' => $pickupProjection['chain_attempt_no'],
                'source' => strtoupper($source),
                'customer_route_distance_meters' => $customerRouteDistanceMeters,
                'verified_for_compensation' => $verifiedForCompensation,
                'verification' => $diagnostics + [
                    'has_evidence' => $evidenceId !== null,
                    'unverified_reason' => $verifiedForCompensation
                        ? null
                        : $this->unverifiedReason($diagnostics, $evidenceId),
                ],
                'evidence_id' => $evidenceId,
                'failed_at' => now()->toIso8601String(),
            ],
        ]);

        $this->recordCompensationSnapshot($order->refresh(), $actorId);

        return $event;
    }

    /**
     * Ringkasan fee pembatalan (Persamaan 6). Fee berlaku hanya bila seluruh
     * toko/resto gagal terverifikasi menyentuh ambang tiga, dan dihitung dari
     * jarak rute terjauh customer -> toko/resto gagal.
     *
     * @return array{
     *   eligible: bool,
     *   verified_failed_trip_count: int,
     *   customer_route_distance_meters: int,
     *   base_route_fee: float,
     *   percent: float,
     *   amount: float
     * }
     */
    public function summary(Order $order): array
    {
        $projection = $this->projection->snapshot($order);
        $count = (int) $projection['verified_failed_trip_count'];
        // Persamaan (6): ambang memakai g = TOTAL kegagalan pickup (semua toko
        // gagal), sedangkan d_max hanya dari kegagalan terverifikasi. Bila g>=3
        // tetapi tak satu pun terverifikasi, d_max = 0 sehingga fee = 0.
        $gTotal = (int) $projection['order_failed_trip_count'];
        $dMax = $this->maxVerifiedCustomerRouteDistanceMeters($order);
        $eligible = $gTotal >= ShoppingReplacementProjectionService::COMPENSATION_FAILURE_THRESHOLD && $dMax > 0;

        // Basis fee kini = ongkir RUTE COMMITTED penuh (semua toko/resto yang
        // didatangi -> antar), identik dengan estimasi chatbot, bukan lagi
        // O(d_max) toko terjauh tunggal. Nilai ini sudah dibekukan sebagai
        // penalty_base_delivery_fee saat toko-toko gagal (= order->delivery_fee
        // committed sebelum semua gugur), jadi tak perlu memanggil Maps ulang.
        // d_max tetap dipakai hanya sebagai gerbang kelayakan (ada kegagalan
        // terverifikasi).
        $baseFee = $eligible ? $this->committedRouteBaseFee($order) : 0.0;

        return [
            'eligible' => $eligible,
            'verified_failed_trip_count' => $count,
            'customer_route_distance_meters' => $dMax,
            'base_route_fee' => $baseFee,
            'percent' => self::COMPENSATION_PERCENT,
            'amount' => $eligible ? round($baseFee * self::COMPENSATION_PERCENT / 100, 2) : 0.0,
        ];
    }

    public function amount(Order $order): float
    {
        return (float) $this->summary($order)['amount'];
    }

    /**
     * Ongkir rute committed yang dibekukan (penalty_base_delivery_fee) saat
     * toko-toko gagal -- sama dengan ongkir order sebelum semua toko gugur, yaitu
     * tarif rute optimal melewati semua toko committed -> antar. Fallback ke
     * ongkir order terkini bila belum ada snapshot.
     */
    private function committedRouteBaseFee(Order $order): float
    {
        $frozen = OrderLog::query()
            ->where('order_id', $order->id)
            ->latest('id')
            ->get(['metadata'])
            ->map(fn (OrderLog $event): mixed => data_get($event->metadata ?? [], 'penalty_base_delivery_fee'))
            ->first(fn (mixed $amount): bool => is_numeric($amount) && (float) $amount > 0);

        if (is_numeric($frozen)) {
            return round((float) $frozen, 2);
        }

        return round(max(0.0, (float) $order->delivery_fee), 2);
    }

    /** @return array<string, mixed>|null */
    public function feeLine(Order $order): ?array
    {
        $summary = $this->summary($order);
        if ((float) $summary['amount'] <= 0) {
            return null;
        }

        return [
            'code' => 'FAILED_TRIP_COMPENSATION',
            'label' => 'Fee pembatalan',
            'description' => '50% ongkir jarak terjauh customer ke toko/resto gagal',
            'amount' => (float) $summary['amount'],
            'failed_trip_count' => (int) $summary['verified_failed_trip_count'],
            'distance_meters' => (int) $summary['customer_route_distance_meters'],
            'base_route_fee' => (float) $summary['base_route_fee'],
            'percent' => (float) $summary['percent'],
        ];
    }

    public function isDriverWithinMerchantRadius(Order $order, OrderLocation $pickup, ?int $meters = null): bool
    {
        return $this->verificationDiagnostics($order, $pickup, $meters)['within_radius'];
    }

    /**
     * Fakta mentah di balik keputusan verifikasi kehadiran driver. Dipakai
     * untuk keputusannya sendiri sekaligus disimpan ke metadata event, supaya
     * kegagalan verifikasi bisa ditelusuri tanpa menebak: jarak sebenarnya,
     * radius yang berlaku, dan umur titik GPS terakhir.
     *
     * @return array{
     *   within_radius: bool,
     *   radius_meters: int,
     *   location_fresh_minutes: int,
     *   has_driver_location: bool,
     *   driver_location_is_fresh: bool,
     *   driver_location_age_seconds: int|null,
     *   driver_distance_meters: int|null
     * }
     */
    public function verificationDiagnostics(Order $order, OrderLocation $pickup, ?int $meters = null): array
    {
        $radiusMeters = max(1, $meters ?? $this->verificationRadiusMeters());
        $freshMinutes = $this->driverLocationFreshMinutes();

        $driver = Driver::query()->find($order->driver_id);
        $hasLocation = $driver
            && $driver->latitude !== null
            && $driver->longitude !== null
            && $driver->location_updated_at !== null;

        if (! $hasLocation) {
            return [
                'within_radius' => false,
                'radius_meters' => $radiusMeters,
                'location_fresh_minutes' => $freshMinutes,
                'has_driver_location' => false,
                'driver_location_is_fresh' => false,
                'driver_location_age_seconds' => null,
                'driver_distance_meters' => null,
            ];
        }

        $isFresh = $driver->location_updated_at->gte(now()->subMinutes($freshMinutes));
        $distanceMeters = GeoDistance::roundedMeters(
            (float) $driver->latitude,
            (float) $driver->longitude,
            (float) $pickup->latitude,
            (float) $pickup->longitude,
        );

        return [
            'within_radius' => $isFresh && $distanceMeters <= $radiusMeters,
            'radius_meters' => $radiusMeters,
            'location_fresh_minutes' => $freshMinutes,
            'has_driver_location' => true,
            'driver_location_is_fresh' => $isFresh,
            'driver_location_age_seconds' => (int) abs(now()->diffInSeconds($driver->location_updated_at)),
            'driver_distance_meters' => $distanceMeters,
        ];
    }

    /**
     * Alasan utama sebuah kegagalan tidak dihitung untuk fee. Urutannya
     * mendahulukan penyebab geografis karena itu yang paling sering terjadi dan
     * paling sulit ditebak dari luar.
     *
     * @param  array<string, mixed>  $diagnostics
     */
    private function unverifiedReason(array $diagnostics, ?int $evidenceId): string
    {
        if (($diagnostics['has_driver_location'] ?? false) !== true) {
            return 'NO_DRIVER_LOCATION';
        }

        if (($diagnostics['driver_location_is_fresh'] ?? false) !== true) {
            return 'STALE_DRIVER_LOCATION';
        }

        if (($diagnostics['within_radius'] ?? false) !== true) {
            return 'OUTSIDE_RADIUS';
        }

        return $evidenceId === null ? 'NO_EVIDENCE' : 'FAILURE_TYPE_NOT_ELIGIBLE';
    }

    /**
     * Radius kehadiran driver agar kegagalan dihitung untuk fee.
     */
    public function verificationRadiusMeters(): int
    {
        return max(1, (int) config(
            'bangdeliv.failed_trip.verification_radius_meters',
            self::DEFAULT_VERIFICATION_RADIUS_METERS
        ));
    }

    private function driverLocationFreshMinutes(): int
    {
        return max(1, (int) config(
            'bangdeliv.failed_trip.driver_location_fresh_minutes',
            self::DEFAULT_DRIVER_LOCATION_FRESH_MINUTES
        ));
    }

    /**
     * Jarak rute jalan terjauh dari lokasi customer ke salah satu toko/resto
     * gagal TERVERIFIKASI (d_max pada Persamaan 6). Diambil dari nilai yang
     * dibekukan di event kegagalan; satu event per pickup.
     */
    private function maxVerifiedCustomerRouteDistanceMeters(Order $order): int
    {
        $events = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::FAILED_TRIP_EVENT)
            ->orderBy('id')
            ->get();

        $seenPickups = [];
        $maxMeters = 0;
        foreach ($events as $event) {
            $metadata = is_array($event->metadata) ? $event->metadata : [];
            $pickupId = (int) ($metadata['pickup_location_id'] ?? 0);
            if ($pickupId <= 0 || isset($seenPickups[$pickupId])) {
                continue;
            }
            $seenPickups[$pickupId] = true;
            if (($metadata['verified_for_compensation'] ?? false) !== true) {
                continue;
            }
            $maxMeters = max($maxMeters, (int) ($metadata['customer_route_distance_meters'] ?? 0));
        }

        return $maxMeters;
    }

    /**
     * Jarak rute jalan customer -> toko/resto. Bila Google tak tersedia, pakai
     * estimasi garis lurus x faktor supaya fee sah tidak hilang saat API mati.
     */
    private function customerRouteDistanceMeters(Order $order, OrderLocation $pickup): int
    {
        $dropoff = $this->dropoffLocation($order);
        if (! $dropoff instanceof OrderLocation
            || $dropoff->latitude === null
            || $dropoff->longitude === null
            || $pickup->latitude === null
            || $pickup->longitude === null
        ) {
            return 0;
        }

        $fromLat = (float) $dropoff->latitude;
        $fromLng = (float) $dropoff->longitude;
        $toLat = (float) $pickup->latitude;
        $toLng = (float) $pickup->longitude;

        $roughMeters = GeoDistance::meters($fromLat, $fromLng, $toLat, $toLng);
        if ($roughMeters < 20) {
            return 0;
        }

        try {
            $route = $this->maps->resolveRoute($fromLat, $fromLng, $toLat, $toLng);

            return max(0, (int) ($route['distance_meters'] ?? 0));
        } catch (Throwable) {
            return (int) round($roughMeters * self::HAVERSINE_ROUTE_FACTOR);
        }
    }

    private function dropoffLocation(Order $order): ?OrderLocation
    {
        $order->loadMissing('orderLocations');

        return $order->orderLocations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'DROPOFF')
            ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
            ->last();
    }

    private function recordCompensationSnapshot(Order $order, ?int $actorId): void
    {
        $summary = $this->summary($order);
        $failedTripCount = (int) $this->projection->snapshot($order)['order_failed_trip_count'];
        $latest = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::COMPENSATION_EVENT)
            ->latest('id')
            ->first();
        $latestMetadataRaw = $latest instanceof OrderLog ? $latest->getAttribute('metadata') : null;
        $latestMetadata = is_array($latestMetadataRaw) ? $latestMetadataRaw : [];
        // Jumlah kegagalan total ikut dibandingkan supaya kegagalan baru yang
        // tidak terverifikasi tetap menerbitkan event. Tanpa ini justru kasus
        // yang paling perlu terlihat -- kegagalan menumpuk tapi fee tetap nol --
        // yang paling sunyi di riwayat order.
        if (
            (int) ($latestMetadata['verified_failed_trip_count'] ?? -1) === (int) $summary['verified_failed_trip_count']
            && (int) ($latestMetadata['failed_trip_count'] ?? -1) === $failedTripCount
            && abs((float) ($latestMetadata['amount'] ?? -1) - (float) $summary['amount']) < 0.01
        ) {
            return;
        }

        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => ShoppingReplacementProjectionService::COMPENSATION_EVENT,
            'trigger_type' => 'SHOPPING_FAILED_TRIP_COMPENSATION_RECALCULATED',
            'changed_by_user_id' => $actorId,
            'note' => (bool) $summary['eligible']
                ? 'Fee pembatalan diperbarui.'
                : sprintf(
                    'Perjalanan gagal dicatat; %d dari %d kegagalan terverifikasi, fee belum mencapai batas.',
                    (int) $summary['verified_failed_trip_count'],
                    $failedTripCount,
                ),
            'metadata' => $summary + [
                'failed_trip_count' => $failedTripCount,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function failedTripForPickup(Order $order, int $pickupLocationId): ?OrderLog
    {
        return OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::FAILED_TRIP_EVENT)
            ->orderByDesc('id')
            ->get()
            ->first(fn (OrderLog $event): bool => (int) data_get($event->metadata, 'pickup_location_id', 0) === $pickupLocationId);
    }
}
