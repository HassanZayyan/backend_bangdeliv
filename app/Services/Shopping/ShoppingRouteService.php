<?php

namespace App\Services\Shopping;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderLocation;
use App\Models\Restaurant;
use App\Services\Geo\BangDelivServiceAreaService;
use App\Services\Maps\GoogleMapsDistanceMatrixService;
use App\Services\Pricing\DeliveryPricingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ShoppingRouteService
{
    private const MINIMUM_ROUTE_DISTANCE_METERS = 20;

    public function __construct(
        private readonly GoogleMapsDistanceMatrixService $distanceMatrixService,
        private readonly DeliveryPricingService $deliveryPricingService,
        private readonly ShoppingDeliveryFeeLockResolver $deliveryFeeLockResolver,
        private readonly BangDelivServiceAreaService $serviceAreaService,
    ) {}

    /**
     * @param  array<string, mixed>  $delivery
     * @return array<string, mixed>
     */
    public function calculateForMerchantAndDelivery(Restaurant $merchant, array $delivery): array
    {
        return $this->calculateForPoints(
            [[
                'label' => $merchant->name,
                'latitude' => $merchant->latitude,
                'longitude' => $merchant->longitude,
            ]],
            [
                'label' => $delivery['address'] ?? 'Titik Antar',
                'latitude' => $delivery['latitude'] ?? null,
                'longitude' => $delivery['longitude'] ?? null,
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $pickupPoints
     * @param  array<string, mixed>  $dropoffPoint
     * @return array<string, mixed>
     */
    public function calculateForPoints(array $pickupPoints, array $dropoffPoint): array
    {
        if ($pickupPoints === []) {
            throw new ApiException('Minimal satu merchant belanja wajib tersedia.', 422);
        }

        $points = [];
        foreach ($pickupPoints as $pickupPoint) {
            $points[] = $this->normalizePoint($pickupPoint, 'Koordinat merchant belum lengkap.');
        }
        $points[] = $this->normalizePoint($dropoffPoint, 'Koordinat titik antar belum lengkap.');
        $orderedPickupLocationIds = array_values(array_filter(array_map(
            static fn (array $point): ?int => isset($point['id']) ? (int) $point['id'] : null,
            array_slice($points, 0, -1)
        )));

        $this->assertShoppingPointsWithinServiceArea(
            array_slice($points, 0, -1),
            $points[count($points) - 1]
        );

        if (count($points) === 2) {
            $this->assertSingleShoppingRouteSeparated($points[0], $points[1]);
        }

        $segments = [];
        $totalDistanceMeters = 0;
        $totalDurationSeconds = 0;
        $encodedPolyline = null;
        $routeProvider = 'distance_matrix';
        $routingPreference = null;
        $travelMode = null;
        $routeStatus = 'OK';

        for ($index = 0; $index < count($points) - 1; $index++) {
            $from = $points[$index];
            $to = $points[$index + 1];
            $route = $this->distanceMatrixService->resolveRoute(
                $from['latitude'],
                $from['longitude'],
                $to['latitude'],
                $to['longitude'],
            );

            $distanceMeters = (int) ($route['distance_meters'] ?? 0);
            $durationSeconds = (int) ($route['duration_seconds'] ?? 0);
            $totalDistanceMeters += max(0, $distanceMeters);
            $totalDurationSeconds += max(0, $durationSeconds);
            $routeProvider = (string) ($route['route_provider'] ?? $routeProvider);
            $routingPreference = $route['routing_preference'] ?? $routingPreference;
            $travelMode = $route['travel_mode'] ?? $travelMode;
            $routeStatus = (string) ($route['route_status'] ?? $routeStatus);
            if (count($points) === 2 && is_string($route['encoded_polyline'] ?? null) && trim((string) $route['encoded_polyline']) !== '') {
                $encodedPolyline = (string) $route['encoded_polyline'];
            }

            $segments[] = [
                'from_label' => $from['label'],
                'to_label' => $to['label'],
                'distance_meters' => max(0, $distanceMeters),
                'distance_km' => round(max(0, $distanceMeters) / 1000, 2),
                'distance_text' => (string) ($route['distance_text'] ?? ''),
                'duration_seconds' => max(0, $durationSeconds),
                'duration_text' => (string) ($route['duration_text'] ?? ''),
            ];
        }

        if (count($points) > 2) {
            $this->assertTotalRouteDistanceNotTooShort($totalDistanceMeters);
        }

        $deliveryPricing = $this->deliveryPricingService->calculateFromDistanceMeters((float) $totalDistanceMeters);
        $distanceKm = round($totalDistanceMeters / 1000, 2);

        return [
            'distance_meters' => $totalDistanceMeters,
            'distance_km' => $distanceKm,
            'distance_text' => number_format($distanceKm, 2, ',', '.').' km',
            'duration_seconds' => $totalDurationSeconds,
            'duration_text' => $this->formatDurationText($totalDurationSeconds),
            'delivery_fee' => (float) $deliveryPricing['total_fee'],
            'delivery_pricing' => $deliveryPricing,
            'segments' => $segments,
            'ordered_pickup_location_ids' => $orderedPickupLocationIds,
            'encoded_polyline' => $encodedPolyline,
            'route_provider' => $routeProvider,
            'routing_preference' => $routingPreference,
            'travel_mode' => $travelMode,
            'route_status' => $routeStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function applyRouteToOrder(
        Order $order,
        ?float $preservedDeliveryFee = null,
        ?string $preservedDeliveryFeeSource = null,
    ): ?array {
        $order->refresh()->load(['orderLocations.restaurant']);
        $preservedDeliveryFee = is_numeric($preservedDeliveryFee) && $preservedDeliveryFee > 0
            ? round((float) $preservedDeliveryFee, 2)
            : null;
        $deliveryFeeLock = $this->deliveryFeeLockResolver->resolve($order);
        $lockedDeliveryFee = $preservedDeliveryFee ?? ((bool) $deliveryFeeLock['is_locked']
            ? (float) $deliveryFeeLock['amount']
            : null);
        $deliveryFeeLockSource = $preservedDeliveryFee !== null
            ? ($preservedDeliveryFeeSource ?: 'PRESERVED_DELIVERY_FEE')
            : $deliveryFeeLock['source'];

        if ($this->activePickupLocations($order)->isEmpty()) {
            $this->storeRouteSnapshot($order, [
                'distance_meters' => null,
                'distance_km' => null,
                'distance_text' => null,
                'duration_seconds' => null,
                'duration_text' => null,
                'delivery_fee' => round($lockedDeliveryFee ?? (float) $order->delivery_fee, 2),
                'segments' => [],
                'ordered_pickup_location_ids' => [],
                'encoded_polyline' => null,
                'route_provider' => 'none',
                'route_status' => 'NO_ACTIVE_PICKUPS',
                ...($lockedDeliveryFee !== null ? [
                    'delivery_fee_locked' => true,
                    'delivery_fee_lock_source' => $deliveryFeeLockSource,
                ] : []),
            ]);

            return null;
        }

        $route = $this->calculateForOrder($order);
        $this->resequenceStops($order, $route['ordered_pickup_location_ids'] ?? null);

        if ($lockedDeliveryFee !== null) {
            $route['delivery_fee'] = round($lockedDeliveryFee, 2);
            $route['delivery_fee_locked'] = true;
            $route['delivery_fee_lock_source'] = $deliveryFeeLockSource;
        }

        $order->update([
            'delivery_fee' => round((float) $route['delivery_fee'], 2),
        ]);

        $this->storeRouteSnapshot($order, $route);

        return $route;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function backfillRouteSnapshot(Order $order): ?array
    {
        $order->refresh()->load(['orderLocations.restaurant', 'items']);

        if ($this->activePickupLocations($order)->isEmpty()) {
            return null;
        }

        $route = $this->calculateForOrder($order);
        $route['delivery_fee'] = round((float) $order->delivery_fee, 2);

        $existingRoute = is_array($order->route_snapshot) ? $order->route_snapshot : [];
        if (is_array($existingRoute['delivery_pricing'] ?? null)) {
            $route['delivery_pricing'] = $existingRoute['delivery_pricing'];
        }

        $this->storeRouteSnapshot($order, $route);

        return $route;
    }

    /**
     * @return array<string, mixed>
     */
    public function calculateForOrder(Order $order): array
    {
        $order->loadMissing(['orderLocations.restaurant']);

        $pickups = $this->activePickupLocations($order);

        $dropoff = $order->orderLocations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'DROPOFF')
            ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
            ->last();

        if (! $dropoff instanceof OrderLocation) {
            throw new ApiException('Titik antar order belum tersedia.', 422);
        }

        $pickupPoints = $pickups->map(fn (OrderLocation $location): array => $this->pointFromLocation($location))->all();
        $dropoffPoint = $this->pointFromLocation($dropoff);

        $this->assertShoppingPointsWithinServiceArea($pickupPoints, $dropoffPoint);

        if ((bool) config('bangdeliv.routes.optimize_shopping_waypoints', true) && count($pickupPoints) > 1) {
            try {
                $route = $this->distanceMatrixService->resolveOptimizedShoppingRoute(
                    $pickupPoints,
                    $dropoffPoint,
                    (int) config('bangdeliv.routes.shopping_route_max_origin_candidates', 8)
                );

                $routeDistanceMeters = (int) ($route['distance_meters'] ?? 0);
                $this->assertTotalRouteDistanceNotTooShort($routeDistanceMeters);
                $route['delivery_pricing'] = $this->deliveryPricingService->calculateFromDistanceMeters(
                    (float) $routeDistanceMeters
                );
                $route['delivery_fee'] = (float) $route['delivery_pricing']['total_fee'];

                return $route;
            } catch (ApiException $exception) {
                Log::warning('Optimized shopping route failed; falling back to sequential route.', [
                    'order_id' => $order->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $this->calculateForPoints($pickupPoints, $dropoffPoint);
    }

    /**
     * @param  array<int, int>|null  $orderedPickupLocationIds
     */
    public function resequenceStops(Order $order, ?array $orderedPickupLocationIds = null): void
    {
        $locations = $order->orderLocations()->orderBy('sequence_no')->orderBy('id')->get();
        $pickups = $locations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->values();
        $dropoffs = $locations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'DROPOFF')
            ->values();

        foreach ($locations as $offset => $location) {
            $location->update(['sequence_no' => 200 + $offset]);
        }

        $orderedPickups = $pickups;
        if ($orderedPickupLocationIds !== null && $orderedPickupLocationIds !== []) {
            $byId = $pickups->keyBy(fn (OrderLocation $location): int => (int) $location->id);
            $activeOrdered = collect($orderedPickupLocationIds)
                ->map(fn (int $id): ?OrderLocation => $byId->get($id))
                ->filter()
                ->values();
            $remaining = $pickups
                ->reject(fn (OrderLocation $location): bool => $activeOrdered->contains(fn (OrderLocation $ordered): bool => (int) $ordered->id === (int) $location->id))
                ->values();
            $orderedPickups = $activeOrdered->concat($remaining)->values();
        }

        $sequence = 1;
        foreach ($orderedPickups as $pickup) {
            $pickup->update(['sequence_no' => $sequence++]);
        }

        foreach ($dropoffs as $dropoff) {
            $dropoff->update(['sequence_no' => $sequence++]);
        }
    }

    /**
     * @return array{label: string, latitude: float, longitude: float}
     */
    private function pointFromLocation(OrderLocation $location): array
    {
        return [
            'id' => (int) $location->id,
            'label' => (string) ($location->label ?: $location->restaurant?->name ?: 'Titik'),
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
        ];
    }

    /**
     * @param  array<string, mixed>  $point
     * @return array{label: string, latitude: float, longitude: float}
     */
    private function normalizePoint(array $point, string $errorMessage): array
    {
        $latitude = $point['latitude'] ?? null;
        $longitude = $point['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            throw new ApiException($errorMessage, 422);
        }

        return [
            'id' => isset($point['id']) && is_numeric($point['id']) ? (int) $point['id'] : null,
            'label' => trim((string) ($point['label'] ?? 'Titik')) ?: 'Titik',
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
        ];
    }

    /**
     * @param  array<string, mixed>  $dropoffPoint
     */
    public function assertDeliveryPointWithinServiceArea(array $dropoffPoint): void
    {
        $point = $this->normalizePoint($dropoffPoint, 'Koordinat titik antar belum lengkap.');

        $this->serviceAreaService->assertPointWithinRadius(
            $point['latitude'],
            $point['longitude'],
            'titik antar',
            $point['label']
        );
    }

    /**
     * @param  array<int, array{label: string, latitude: float, longitude: float}>  $pickupPoints
     * @param  array{label: string, latitude: float, longitude: float}  $dropoffPoint
     */
    private function assertShoppingPointsWithinServiceArea(array $pickupPoints, array $dropoffPoint): void
    {
        $points = array_map(
            static fn (array $point): array => [
                'role' => 'merchant',
                'label' => $point['label'],
                'latitude' => $point['latitude'],
                'longitude' => $point['longitude'],
            ],
            $pickupPoints
        );

        $points[] = [
            'role' => 'titik antar',
            'label' => $dropoffPoint['label'],
            'latitude' => $dropoffPoint['latitude'],
            'longitude' => $dropoffPoint['longitude'],
        ];

        $this->serviceAreaService->assertPointsWithinRadius($points);
    }

    /**
     * @param  array{label: string, latitude: float, longitude: float}  $pickupPoint
     * @param  array{label: string, latitude: float, longitude: float}  $dropoffPoint
     */
    private function assertSingleShoppingRouteSeparated(array $pickupPoint, array $dropoffPoint): void
    {
        if ($this->roughDistanceMeters(
            $pickupPoint['latitude'],
            $pickupPoint['longitude'],
            $dropoffPoint['latitude'],
            $dropoffPoint['longitude']
        ) < self::MINIMUM_ROUTE_DISTANCE_METERS) {
            throw new ApiException(
                'Titik antar terlalu dekat dengan merchant. Pilih titik antar yang berbeda.',
                422
            );
        }
    }

    private function assertTotalRouteDistanceNotTooShort(int $totalDistanceMeters): void
    {
        if ($totalDistanceMeters < self::MINIMUM_ROUTE_DISTANCE_METERS) {
            throw new ApiException(
                'Titik rute terlalu dekat. Pilih titik antar yang berbeda.',
                422
            );
        }
    }

    private function roughDistanceMeters(
        float $originLatitude,
        float $originLongitude,
        float $destinationLatitude,
        float $destinationLongitude
    ): float {
        $earthRadiusMeters = 6371000.0;
        $originLatitudeRad = deg2rad($originLatitude);
        $destinationLatitudeRad = deg2rad($destinationLatitude);
        $deltaLatitudeRad = deg2rad($destinationLatitude - $originLatitude);
        $deltaLongitudeRad = deg2rad($destinationLongitude - $originLongitude);

        $haversine = sin($deltaLatitudeRad / 2) ** 2
            + cos($originLatitudeRad) * cos($destinationLatitudeRad) * sin($deltaLongitudeRad / 2) ** 2;
        $safeHaversine = min(1.0, max(0.0, $haversine));

        return $earthRadiusMeters * 2 * atan2(sqrt($safeHaversine), sqrt(1 - $safeHaversine));
    }

    /**
     * @return Collection<int, OrderLocation>
     */
    private function activePickupLocations(Order $order): Collection
    {
        $order->loadMissing(['orderLocations.restaurant', 'items']);

        return $order->orderLocations
            ->filter(function (OrderLocation $location) use ($order): bool {
                if (strtoupper((string) $location->location_role) !== 'PICKUP') {
                    return false;
                }

                if (in_array(strtoupper((string) ($location->fulfillment_status ?? 'PENDING')), ['FAILED', 'SKIPPED', 'REPLACED', 'ABANDONED_AFTER_LIMIT'], true)) {
                    return false;
                }

                return $this->hasAvailableItemsAtPickup($order, $location);
            })
            ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
            ->values();
    }

    private function hasAvailableItemsAtPickup(Order $order, OrderLocation $pickup): bool
    {
        $pickupId = (int) $pickup->id;

        return $order->items->contains(function ($item) use ($order, $pickup, $pickupId): bool {
            if (! (bool) $item->is_available) {
                return false;
            }

            if ($item->pickup_location_id !== null) {
                return (int) $item->pickup_location_id === $pickupId;
            }

            return (int) $order->restaurant_id === (int) ($pickup->restaurant_id ?? 0);
        });
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function storeRouteSnapshot(Order $order, array $route): void
    {
        $routeSnapshot = [
            'distance_meters' => $route['distance_meters'] ?? null,
            'distance_km' => $route['distance_km'] ?? null,
            'distance_text' => $route['distance_text'] ?? null,
            'duration_seconds' => $route['duration_seconds'] ?? null,
            'duration_text' => $route['duration_text'] ?? null,
            'delivery_fee' => $route['delivery_fee'] ?? null,
            'segments' => $route['segments'] ?? [],
            'ordered_pickup_location_ids' => $route['ordered_pickup_location_ids'] ?? [],
            'encoded_polyline' => $route['encoded_polyline'] ?? null,
            'route_provider' => $route['route_provider'] ?? null,
            'routing_preference' => $route['routing_preference'] ?? null,
            'travel_mode' => $route['travel_mode'] ?? null,
            'route_status' => $route['route_status'] ?? 'OK',
        ];

        $order->update(['route_snapshot' => $routeSnapshot]);
    }

    private function formatDurationText(int $durationSeconds): string
    {
        $minutes = (int) max(1, ceil(max(0, $durationSeconds) / 60));

        if ($minutes < 60) {
            return $minutes.' menit';
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return $remaining === 0 ? $hours.' jam' : $hours.' jam '.$remaining.' menit';
    }
}
