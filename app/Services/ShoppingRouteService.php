<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderLocation;
use App\Models\Restaurant;
use Illuminate\Support\Collection;

class ShoppingRouteService
{
    public function __construct(
        private readonly GoogleMapsDistanceMatrixService $distanceMatrixService,
        private readonly DeliveryPricingService $deliveryPricingService,
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

        $segments = [];
        $totalDistanceMeters = 0;
        $totalDurationSeconds = 0;

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

        if (! $this->deliveryPricingService->isWithinMaxDistance((float) $totalDistanceMeters)) {
            throw new ApiException(sprintf(
                'Jarak rute belanja %.2f km melebihi batas layanan %.2f km.',
                $totalDistanceMeters / 1000,
                $this->deliveryPricingService->getMaxDistanceKm()
            ), 422);
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function applyRouteToOrder(Order $order): array
    {
        $this->resequenceStops($order);

        $order->refresh()->load(['orderLocations.restaurant']);
        $route = $this->calculateForOrder($order);
        $routeMinutes = $this->estimateTravelMinutes((int) ($route['duration_seconds'] ?? 0));
        $prepMinutes = $this->maxPrepMinutes($order->orderLocations);

        $order->update([
            'delivery_fee' => round((float) $route['delivery_fee'], 2),
            'delivery_distance_km' => round((float) $route['distance_km'], 2),
            'delivery_distance_text' => (string) $route['distance_text'],
            'estimated_delivery' => now()->addMinutes($prepMinutes + $routeMinutes),
        ]);

        return $route;
    }

    /**
     * @return array<string, mixed>
     */
    public function calculateForOrder(Order $order): array
    {
        $order->loadMissing(['orderLocations.restaurant']);

        $pickups = $order->orderLocations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
            ->values();

        $dropoff = $order->orderLocations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'DROPOFF')
            ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
            ->last();

        if (! $dropoff instanceof OrderLocation) {
            throw new ApiException('Titik antar order belum tersedia.', 422);
        }

        return $this->calculateForPoints(
            $pickups->map(fn (OrderLocation $location): array => $this->pointFromLocation($location))->all(),
            $this->pointFromLocation($dropoff),
        );
    }

    public function resequenceStops(Order $order): void
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

        $sequence = 1;
        foreach ($pickups as $pickup) {
            $pickup->update(['sequence_no' => $sequence++]);
        }

        foreach ($dropoffs as $dropoff) {
            $dropoff->update(['sequence_no' => $sequence++]);
        }
    }

    /**
     * @param  Collection<int, OrderLocation>  $locations
     */
    private function maxPrepMinutes(Collection $locations): int
    {
        $max = $locations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->map(fn (OrderLocation $location): int => max(0, (int) ($location->restaurant?->estimated_prep_time ?? 10)))
            ->max();

        return max(10, (int) ($max ?? 10));
    }

    /**
     * @return array{label: string, latitude: float, longitude: float}
     */
    private function pointFromLocation(OrderLocation $location): array
    {
        return [
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
            'label' => trim((string) ($point['label'] ?? 'Titik')) ?: 'Titik',
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
        ];
    }

    private function estimateTravelMinutes(int $durationSeconds): int
    {
        $minutes = (int) ceil(max(0, $durationSeconds) / 60);

        return max(10, min(180, $minutes));
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
