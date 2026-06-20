<?php

namespace Tests\Unit;

use App\Support\GeoDistance;
use PHPUnit\Framework\TestCase;

class GeoDistanceTest extends TestCase
{
    public function test_distance_is_zero_for_same_coordinate(): void
    {
        $this->assertSame(0.0, GeoDistance::meters(-7.001, 110.401, -7.001, 110.401));
        $this->assertSame(0, GeoDistance::roundedMeters(-7.001, 110.401, -7.001, 110.401));
    }

    public function test_distance_uses_haversine_meter_estimate(): void
    {
        $meters = GeoDistance::meters(-7.001, 110.401, -7.004, 110.404);

        $this->assertGreaterThan(450, $meters);
        $this->assertLessThan(500, $meters);
        $this->assertSame((int) round($meters), GeoDistance::roundedMeters(-7.001, 110.401, -7.004, 110.404));
    }

    public function test_coordinate_pair_validation_rejects_invalid_and_zero_pairs(): void
    {
        $this->assertTrue(GeoDistance::isValidCoordinatePair(-7.001, 110.401));
        $this->assertFalse(GeoDistance::isValidCoordinatePair(null, 110.401));
        $this->assertFalse(GeoDistance::isValidCoordinatePair(-91, 110.401));
        $this->assertFalse(GeoDistance::isValidCoordinatePair(-7.001, 181));
        $this->assertFalse(GeoDistance::isValidCoordinatePair(0, 0));
        $this->assertTrue(GeoDistance::isValidCoordinatePair(0, 0, rejectZeroPair: false));
    }
}
