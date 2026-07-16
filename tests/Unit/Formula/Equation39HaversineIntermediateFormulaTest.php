<?php

namespace Tests\Unit\Formula;

use App\Support\GeoDistance;
use PHPUnit\Framework\TestCase;

class Equation39HaversineIntermediateFormulaTest extends TestCase
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    public function test_known_coordinate_pair_produces_half_haversine_intermediate_value(): void
    {
        $originLatitude = 0.0;
        $originLongitude = 10.0;
        $targetLatitude = 60.0;
        $targetLongitude = 100.0;

        $rawHaversine = $this->rawHaversine(
            $originLatitude,
            $originLongitude,
            $targetLatitude,
            $targetLongitude
        );
        $safeHaversine = min(1.0, max(0.0, $rawHaversine));
        $expectedMeters = self::EARTH_RADIUS_METERS
            * 2
            * atan2(sqrt($safeHaversine), sqrt(1 - $safeHaversine));

        $this->assertEqualsWithDelta(0.5, $rawHaversine, 1.0E-15);
        $this->assertEqualsWithDelta(0.5, $safeHaversine, 1.0E-15);
        $this->assertEqualsWithDelta(
            $expectedMeters,
            GeoDistance::meters($originLatitude, $originLongitude, $targetLatitude, $targetLongitude),
            0.001
        );
    }

    public function test_intermediate_value_is_clamped_before_public_distance_is_calculated(): void
    {
        $originLatitude = -85.126441;
        $originLongitude = 140.498953;
        $targetLatitude = 85.126440995;
        $targetLongitude = -39.501047007999944;

        $rawHaversine = $this->rawHaversine(
            $originLatitude,
            $originLongitude,
            $targetLatitude,
            $targetLongitude
        );
        $safeHaversine = min(1.0, max(0.0, $rawHaversine));
        $actualMeters = GeoDistance::meters(
            $originLatitude,
            $originLongitude,
            $targetLatitude,
            $targetLongitude
        );

        $this->assertGreaterThan(1.0, $rawHaversine);
        $this->assertSame(1.0, $safeHaversine);
        $this->assertTrue(is_finite($actualMeters));
        $this->assertEqualsWithDelta(M_PI * self::EARTH_RADIUS_METERS, $actualMeters, 0.001);
    }

    private function rawHaversine(
        float $originLatitude,
        float $originLongitude,
        float $targetLatitude,
        float $targetLongitude
    ): float {
        $originLatitudeRad = deg2rad($originLatitude);
        $targetLatitudeRad = deg2rad($targetLatitude);
        $deltaLatitudeRad = deg2rad($targetLatitude - $originLatitude);
        $deltaLongitudeRad = deg2rad($targetLongitude - $originLongitude);

        return sin($deltaLatitudeRad / 2) ** 2
            + cos($originLatitudeRad) * cos($targetLatitudeRad) * sin($deltaLongitudeRad / 2) ** 2;
    }
}
