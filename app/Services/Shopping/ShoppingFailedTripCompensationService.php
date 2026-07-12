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

class ShoppingFailedTripCompensationService
{
    private const COMPENSATION_PERCENT = 50.0;

    private const DRIVER_LOCATION_FRESH_MINUTES = 5;

    public function __construct(
        private readonly ShoppingReplacementProjectionService $projection,
        private readonly GoogleMapsDistanceMatrixService $maps,
        private readonly DeliveryPricingService $deliveryPricing,
    ) {}

    public function recordCheckpoint(Order $order, OrderLocation $pickup, ?int $actorId = null): OrderLog
    {
        $existing = $this->checkpointForPickup($order, (int) $pickup->id);
        if ($existing instanceof OrderLog) {
            return $existing;
        }

        $origin = $this->checkpointOrigin($order, $pickup);
        $destination = [
            'pickup_location_id' => (int) $pickup->id,
            'label' => (string) $pickup->label,
            'latitude' => (float) $pickup->latitude,
            'longitude' => (float) $pickup->longitude,
        ];
        $route = $this->resolveSegment($origin, $destination);
        $pickupProjection = $this->projection->forPickup($order, (int) $pickup->id);

        return OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => ShoppingReplacementProjectionService::CHECKPOINT_EVENT,
            'trigger_type' => 'SHOPPING_MERCHANT_ARRIVAL_CHECKPOINT',
            'changed_by_user_id' => $actorId,
            'note' => 'Checkpoint perjalanan merchant Nitip.',
            'metadata' => [
                'pickup_location_id' => (int) $pickup->id,
                'chain_id' => $pickupProjection['chain_id'],
                'chain_attempt_no' => $pickupProjection['chain_attempt_no'],
                'origin' => $origin,
                'destination' => $destination,
                ...$route,
                'recorded_at' => now()->toIso8601String(),
            ],
        ]);
    }

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

        $checkpoint = $this->recordCheckpoint($order, $pickup, $actorId);
        $checkpointMetadataRaw = $checkpoint->getAttribute('metadata');
        $checkpointMetadata = is_array($checkpointMetadataRaw) ? $checkpointMetadataRaw : [];
        $pickupProjection = $this->projection->forPickup($order, (int) $pickup->id);
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
                'checkpoint_event_id' => (int) $checkpoint->id,
                'distance_meters' => max(0, (int) ($checkpointMetadata['distance_meters'] ?? 0)),
                'route_provider' => $checkpointMetadata['route_provider'] ?? 'fallback',
                'route_status' => $checkpointMetadata['route_status'] ?? 'ESTIMATION_FALLBACK',
                'verified_for_compensation' => $verifiedForCompensation,
                'evidence_id' => $evidenceId,
                'failed_at' => now()->toIso8601String(),
            ],
        ]);

        $this->recordCompensationSnapshot($order->refresh(), $actorId);

        return $event;
    }

    /**
     * @return array{
     *   eligible: bool,
     *   verified_failed_trip_count: int,
     *   failed_distance_meters: int,
     *   base_route_fee: float,
     *   percent: float,
     *   amount: float
     * }
     */
    public function summary(Order $order): array
    {
        $projection = $this->projection->snapshot($order);
        $count = (int) $projection['verified_failed_trip_count'];
        $distanceMeters = max(0, (int) $projection['verified_failed_distance_meters']);
        $legacyEligible = $projection['uses_legacy_failure_fallback']
            && $projection['legacy_failed_trip_count'] >= ShoppingReplacementProjectionService::COMPENSATION_FAILURE_THRESHOLD;
        $eligible = $count >= ShoppingReplacementProjectionService::COMPENSATION_FAILURE_THRESHOLD || $legacyEligible;
        if ($legacyEligible && $count < ShoppingReplacementProjectionService::COMPENSATION_FAILURE_THRESHOLD) {
            // Orders created before route-ledger events used the accumulated
            // pickup counter and current delivery fee as their fee basis.
            // Preserve that contract without inventing historical segments.
            $count = $projection['legacy_failed_trip_count'];
            $baseFee = round(max(0.0, (float) $order->delivery_fee), 2);
        } else {
            $pricing = $this->deliveryPricing->calculateFromDistanceMeters((float) $distanceMeters);
            $baseFee = round((float) ($pricing['total_fee'] ?? 0), 2);
        }

        return [
            'eligible' => $eligible,
            'verified_failed_trip_count' => $count,
            'failed_distance_meters' => $distanceMeters,
            'base_route_fee' => $baseFee,
            'percent' => self::COMPENSATION_PERCENT,
            'amount' => $eligible ? round($baseFee * self::COMPENSATION_PERCENT / 100, 2) : 0.0,
        ];
    }

    public function amount(Order $order): float
    {
        return (float) $this->summary($order)['amount'];
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
            'label' => 'Kompensasi perjalanan gagal',
            'description' => '50% tarif estimasi perjalanan merchant gagal',
            'amount' => (float) $summary['amount'],
            'failed_trip_count' => (int) $summary['verified_failed_trip_count'],
            'distance_meters' => (int) $summary['failed_distance_meters'],
            'base_route_fee' => (float) $summary['base_route_fee'],
            'percent' => (float) $summary['percent'],
        ];
    }

    public function isDriverWithinMerchantRadius(Order $order, OrderLocation $pickup, int $meters = 200): bool
    {
        $driver = Driver::query()->find($order->driver_id);
        if (! $driver || $driver->latitude === null || $driver->longitude === null || $driver->location_updated_at === null) {
            return false;
        }
        if ($driver->location_updated_at->lt(now()->subMinutes(self::DRIVER_LOCATION_FRESH_MINUTES))) {
            return false;
        }

        return GeoDistance::meters(
            (float) $driver->latitude,
            (float) $driver->longitude,
            (float) $pickup->latitude,
            (float) $pickup->longitude,
        ) <= max(1, $meters);
    }

    private function recordCompensationSnapshot(Order $order, ?int $actorId): void
    {
        $summary = $this->summary($order);
        $latest = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::COMPENSATION_EVENT)
            ->latest('id')
            ->first();
        $latestMetadataRaw = $latest instanceof OrderLog ? $latest->getAttribute('metadata') : null;
        $latestMetadata = is_array($latestMetadataRaw) ? $latestMetadataRaw : [];
        if (
            (int) ($latestMetadata['verified_failed_trip_count'] ?? -1) === (int) $summary['verified_failed_trip_count']
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
                ? 'Kompensasi perjalanan gagal diperbarui.'
                : 'Perjalanan gagal dicatat; kompensasi belum mencapai batas.',
            'metadata' => $summary + ['updated_at' => now()->toIso8601String()],
        ]);
    }

    private function checkpointForPickup(Order $order, int $pickupLocationId): ?OrderLog
    {
        return OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::CHECKPOINT_EVENT)
            ->orderByDesc('id')
            ->get()
            ->first(fn (OrderLog $event): bool => (int) data_get($event->metadata, 'pickup_location_id', 0) === $pickupLocationId);
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

    /** @return array<string, mixed>|null */
    private function checkpointOrigin(Order $order, OrderLocation $pickup): ?array
    {
        $latest = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::CHECKPOINT_EVENT)
            ->latest('id')
            ->first();
        $destination = $latest instanceof OrderLog ? data_get($latest->metadata, 'destination') : null;
        if (is_array($destination) && isset($destination['latitude'], $destination['longitude'])) {
            return $destination;
        }

        $driver = Driver::query()->find($order->driver_id);
        if (
            $driver
            && $driver->latitude !== null
            && $driver->longitude !== null
            && $driver->location_updated_at?->gte(now()->subMinutes(self::DRIVER_LOCATION_FRESH_MINUTES))
        ) {
            return [
                'label' => 'Lokasi driver',
                'latitude' => (float) $driver->latitude,
                'longitude' => (float) $driver->longitude,
                'recorded_at' => $driver->location_updated_at->toIso8601String(),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $origin
     * @param  array<string, mixed>  $destination
     * @return array<string, mixed>
     */
    private function resolveSegment(?array $origin, array $destination): array
    {
        if (! is_array($origin) || ! isset($origin['latitude'], $origin['longitude'])) {
            return [
                'distance_meters' => 0,
                'duration_seconds' => 0,
                'route_provider' => 'fallback',
                'route_status' => 'ESTIMATION_FALLBACK',
            ];
        }

        $roughMeters = GeoDistance::meters(
            (float) $origin['latitude'],
            (float) $origin['longitude'],
            (float) $destination['latitude'],
            (float) $destination['longitude'],
        );
        if ($roughMeters < 20) {
            return [
                'distance_meters' => 0,
                'duration_seconds' => 0,
                'route_provider' => 'checkpoint',
                'route_status' => 'SAME_LOCATION',
            ];
        }

        try {
            $route = $this->maps->resolveRoute(
                (float) $origin['latitude'],
                (float) $origin['longitude'],
                (float) $destination['latitude'],
                (float) $destination['longitude'],
            );

            return [
                'distance_meters' => max(0, (int) ($route['distance_meters'] ?? 0)),
                'duration_seconds' => max(0, (int) ($route['duration_seconds'] ?? 0)),
                'route_provider' => (string) ($route['route_provider'] ?? 'google_routes'),
                'route_status' => (string) ($route['route_status'] ?? 'OK'),
            ];
        } catch (Throwable) {
            return [
                'distance_meters' => (int) round($roughMeters * 1.25),
                'duration_seconds' => 0,
                'route_provider' => 'haversine_fallback',
                'route_status' => 'ESTIMATION_FALLBACK',
            ];
        }
    }
}
