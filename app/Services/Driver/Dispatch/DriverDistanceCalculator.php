<?php

namespace App\Services\Driver\Dispatch;

use App\Support\GeoDistance;

class DriverDistanceCalculator
{
    public function isValidCoordinatePair(mixed $latitude, mixed $longitude): bool
    {
        return GeoDistance::isValidCoordinatePair($latitude, $longitude);
    }

    public function distanceMeters(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude,
    ): int {
        return GeoDistance::roundedMeters(
            $fromLatitude,
            $fromLongitude,
            $toLatitude,
            $toLongitude
        );
    }
}
