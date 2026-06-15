<?php

namespace App\Services\Dispatch;

class DriverDistanceCalculator
{
    private const EARTH_RADIUS_METERS = 6371000;

    public function isValidCoordinatePair(mixed $latitude, mixed $longitude): bool
    {
        if ($latitude === null || $longitude === null) {
            return false;
        }

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return false;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        return $lat >= -90
            && $lat <= 90
            && $lng >= -180
            && $lng <= 180
            && ! ($lat === 0.0 && $lng === 0.0);
    }

    public function distanceMeters(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude,
    ): int {
        $fromLat = deg2rad($fromLatitude);
        $toLat = deg2rad($toLatitude);
        $deltaLat = deg2rad($toLatitude - $fromLatitude);
        $deltaLng = deg2rad($toLongitude - $fromLongitude);

        $a = sin($deltaLat / 2) ** 2
            + cos($fromLat) * cos($toLat) * sin($deltaLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return (int) round(self::EARTH_RADIUS_METERS * $c);
    }
}
