<?php

namespace App\Services\Driver\Dispatch;

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
        $target = $this->pickupPointResolver->resolve($order);
        $locationFresh = $this->isDriverLocationFresh($driver->location_updated_at);

        if (
            ! $locationFresh
            || ! $this->distanceCalculator->isValidCoordinatePair($driver->latitude, $driver->longitude)
            || ! $this->distanceCalculator->isValidCoordinatePair($target['latitude'], $target['longitude'])
        ) {
            return $this->unknownMetadata($priorityRank, $locationFresh, $target);
        }

        $meters = $this->distanceCalculator->distanceMeters(
            (float) $driver->latitude,
            (float) $driver->longitude,
            (float) $target['latitude'],
            (float) $target['longitude'],
        );
        $kilometers = round($meters / 1000, 2);

        return [
            'priority_rank' => $priorityRank,
            'distance_to_pickup_meters' => $meters,
            'distance_to_pickup_km' => $kilometers,
            'distance_to_customer_meters' => $meters,
            'distance_to_customer_km' => $kilometers,
            'distance_label' => $this->distanceLabel($kilometers),
            'distance_bucket' => $this->bucketForKilometers($kilometers)->value,
            'distance_target_role' => $target['target_role'] ?? 'customer_pickup',
            'distance_target_label' => $target['target_label'] ?? 'customer',
            'distance_target_address' => $target['address'] ?? null,
            'location_fresh' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unknownMetadata(?int $priorityRank, bool $locationFresh, array $target = []): array
    {
        return [
            'priority_rank' => $priorityRank,
            'distance_to_pickup_meters' => null,
            'distance_to_pickup_km' => null,
            'distance_to_customer_meters' => null,
            'distance_to_customer_km' => null,
            'distance_label' => 'Jarak belum tersedia',
            'distance_bucket' => DriverDistanceBucket::Unknown->value,
            'distance_target_role' => $target['target_role'] ?? 'customer_pickup',
            'distance_target_label' => $target['target_label'] ?? 'customer',
            'distance_target_address' => $target['address'] ?? null,
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

        // Titik yang diukur selalu posisi customer: titik antar untuk Nitip,
        // titik jemput penumpang untuk antar-jemput, alamat pengirim untuk
        // kurir. Menyebutnya "titik jemput" membuat driver Nitip mengira
        // jarak itu menuju merchant.
        return number_format($kilometers, $precision, ',', '.').' km dari customer';
    }
}
