<?php

namespace Tests\Unit\Formula;

use App\Support\GeoDistance;
use PHPUnit\Framework\TestCase;

class Equation310HaversineDistanceFormulaTest extends TestCase
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    public function test_same_point_has_zero_haversine_distance(): void
    {
        $this->assertSame(
            0.0,
            GeoDistance::meters(-7.319916770351389, 110.46393594806243, -7.319916770351389, 110.46393594806243)
        );
    }

    public function test_quarter_circumference_uses_earth_radius_in_meters(): void
    {
        $expectedMeters = M_PI * self::EARTH_RADIUS_METERS / 2;

        $this->assertEqualsWithDelta(
            $expectedMeters,
            GeoDistance::meters(0.0, 10.0, 0.0, 100.0),
            0.001
        );
    }

    public function test_antipodal_points_have_half_circumference_distance(): void
    {
        $expectedMeters = M_PI * self::EARTH_RADIUS_METERS;

        $this->assertEqualsWithDelta(
            $expectedMeters,
            GeoDistance::meters(0.0, 10.0, 0.0, -170.0),
            0.001
        );
    }
}
