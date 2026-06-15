<?php

namespace App\Services\Dispatch;

use App\Enums\DriverDistanceBucket;
use App\Models\Driver;
use App\Models\Order;
use Carbon\CarbonInterface;

class DriverDispatchMetadataFactory
{
    public function __construct(
        private readonly DriverDistanceCalculator $distanceCalculator,
        private readonly OrderPickupPointResolver $pickupPointResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forDriver(Order $order, Driver $driver, ?int $priorityRank = null): array
    {
        $pickup = $this->pickupPointResolver->resolve($order);
        $locationFresh = $this->isDriverLocationFresh($driver->location_updated_at);

        if (
            ! $locationFresh
            || ! $this->distanceCalculator->isValidCoordinatePair($driver->latitude, $driver->longitude)
            || ! $this->distanceCalculator->isValidCoordinatePair($pickup['latitude'], $pickup['longitude'])
        ) {
            return $this->unknownMetadata($priorityRank, $locationFresh);
        }

        $meters = $this->distanceCalculator->distanceMeters(
            (float) $driver->latitude,
            (float) $driver->longitude,
            (float) $pickup['latitude'],
            (float) $pickup['longitude'],
        );
        $kilometers = round($meters / 1000, 2);

        return [
            'priority_rank' => $priorityRank,
            'distance_to_pickup_meters' => $meters,
            'distance_to_pickup_km' => $kilometers,
            'distance_label' => $this->distanceLabel($kilometers),
            'distance_bucket' => $this->bucketForKilometers($kilometers)->value,
            'location_fresh' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unknownMetadata(?int $priorityRank, bool $locationFresh): array
    {
        return [
            'priority_rank' => $priorityRank,
            'distance_to_pickup_meters' => null,
            'distance_to_pickup_km' => null,
            'distance_label' => 'Jarak belum tersedia',
            'distance_bucket' => DriverDistanceBucket::Unknown->value,
            'location_fresh' => $locationFresh,
        ];
    }

    private function isDriverLocationFresh(?CarbonInterface $updatedAt): bool
    {
        if ($updatedAt === null) {
            return false;
        }

        $freshMinutes = max(1, (int) config('bangdeliv.dispatch.fresh_location_minutes', 10));

        return $updatedAt->greaterThanOrEqualTo(now()->subMinutes($freshMinutes));
    }

    private function bucketForKilometers(float $kilometers): DriverDistanceBucket
    {
        $nearKm = (float) config('bangdeliv.dispatch.near_km', 3);
        $mediumKm = (float) config('bangdeliv.dispatch.medium_km', 7);

        if ($kilometers <= $nearKm) {
            return DriverDistanceBucket::Near;
        }

        if ($kilometers <= $mediumKm) {
            return DriverDistanceBucket::Medium;
        }

        return DriverDistanceBucket::Far;
    }

    private function distanceLabel(float $kilometers): string
    {
        $precision = $kilometers < 10 ? 1 : 0;

        return number_format($kilometers, $precision, ',', '.').' km dari titik jemput';
    }
}
