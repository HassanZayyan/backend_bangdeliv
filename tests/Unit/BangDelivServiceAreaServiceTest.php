<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Services\Geo\BangDelivServiceAreaService;
use Tests\TestCase;

class BangDelivServiceAreaServiceTest extends TestCase
{
    public function test_center_point_is_inside_service_radius(): void
    {
        $service = app(BangDelivServiceAreaService::class);

        $this->assertTrue($service->isWithinRadius(-7.319916770351389, 110.46393594806243));
        $this->assertSame(50.0, $service->radiusKm());
    }

    public function test_radius_is_measured_from_bang_deliv_center(): void
    {
        config([
            'bangdeliv.service_area.center.latitude' => 0,
            'bangdeliv.service_area.center.longitude' => 0,
            'bangdeliv.service_area.radius_km' => 50,
        ]);

        $service = app(BangDelivServiceAreaService::class);

        $this->assertTrue($service->isWithinRadius(0.449, 0));
        $this->assertFalse($service->isWithinRadius(0.451, 0));
    }

    public function test_outside_point_throws_distance_limit_metadata(): void
    {
        $service = app(BangDelivServiceAreaService::class);

        try {
            $service->assertPointWithinRadius(-6.175392, 106.827153, 'tujuan', 'Monas Jakarta');
            $this->fail('Expected distance limit exception.');
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->status());
            $this->assertSame(BangDelivServiceAreaService::ERROR_DISTANCE_LIMIT, $exception->errors()['code'] ?? null);
            $this->assertSame('tujuan', $exception->errors()['point_role'] ?? null);
            $this->assertSame(50.0, $exception->errors()['service_area_radius_km'] ?? null);
            $this->assertStringContainsString('melebihi batas layanan', $exception->getMessage());
        }
    }
}
