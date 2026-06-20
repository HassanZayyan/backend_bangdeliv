<?php

namespace App\Support;

final class GeoDistance
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    public static function isValidCoordinatePair(
        mixed $latitude,
        mixed $longitude,
        bool $rejectZeroPair = true
    ): bool {
        if ($latitude === null || $longitude === null) {
            return false;
        }

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return false;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return false;
        }

        return ! ($rejectZeroPair && $lat === 0.0 && $lng === 0.0);
    }

    public static function meters(
        float $originLatitude,
        float $originLongitude,
        float $targetLatitude,
        float $targetLongitude
    ): float {
        $originLatitudeRad = deg2rad($originLatitude);
        $targetLatitudeRad = deg2rad($targetLatitude);
        $deltaLatitudeRad = deg2rad($targetLatitude - $originLatitude);
        $deltaLongitudeRad = deg2rad($targetLongitude - $originLongitude);

        $haversine = sin($deltaLatitudeRad / 2) ** 2
            + cos($originLatitudeRad) * cos($targetLatitudeRad) * sin($deltaLongitudeRad / 2) ** 2;
        $safeHaversine = min(1.0, max(0.0, $haversine));

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($safeHaversine), sqrt(1 - $safeHaversine));
    }

    public static function roundedMeters(
        float $originLatitude,
        float $originLongitude,
        float $targetLatitude,
        float $targetLongitude
    ): int {
        return (int) round(self::meters(
            $originLatitude,
            $originLongitude,
            $targetLatitude,
            $targetLongitude
        ));
    }
}
