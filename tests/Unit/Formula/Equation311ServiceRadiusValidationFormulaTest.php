<?php

namespace Tests\Unit\Formula;

use App\Exceptions\ApiException;
use App\Services\Geo\BangDelivServiceAreaService;
use Tests\TestCase;

class Equation311ServiceRadiusValidationFormulaTest extends TestCase
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bangdeliv.service_area.center.latitude' => 0.0,
            'bangdeliv.service_area.center.longitude' => 10.0,
            'bangdeliv.service_area.radius_km' => 50.0,
            'bangdeliv.service_area.name' => 'Pusat Uji BangDeliv',
            'bangdeliv.service_area.address' => 'Alamat pusat uji',
        ]);
    }

    public function test_49999_and_50000_meters_are_valid_but_50001_meters_is_outside(): void
    {
        $service = new BangDelivServiceAreaService;
        $insideLatitude = $this->latitudeAtDistanceMeters(49999.0);
        $boundaryLatitude = $this->latitudeAtDistanceMeters(50000.0);
        $outsideLatitude = $this->latitudeAtDistanceMeters(50001.0);

        $this->assertTrue($service->isWithinRadius($insideLatitude, 10.0));
        $this->assertTrue($service->isWithinRadius($boundaryLatitude, 10.0));
        $this->assertFalse($service->isWithinRadius($outsideLatitude, 10.0));

        $service->assertPointWithinRadius($insideLatitude, 10.0, 'tujuan', '49.999 meter');
        $service->assertPointWithinRadius($boundaryLatitude, 10.0, 'tujuan', '50.000 meter');
    }

    public function test_outside_point_throws_422_with_distance_limit_metadata(): void
    {
        $service = new BangDelivServiceAreaService;

        try {
            $service->assertPointWithinRadius(
                $this->latitudeAtDistanceMeters(50001.0),
                10.0,
                'tujuan',
                '50.001 meter'
            );
            $this->fail('Expected a service-area distance-limit exception.');
        } catch (ApiException $exception) {
            $errors = $exception->errors();

            $this->assertSame(422, $exception->status());
            $this->assertSame(BangDelivServiceAreaService::ERROR_DISTANCE_LIMIT, $errors['code'] ?? null);
            $this->assertSame('tujuan', $errors['point_role'] ?? null);
            $this->assertSame('50.001 meter', $errors['point_label'] ?? null);
            $this->assertSame(50.0, $errors['service_area_radius_km'] ?? null);
            $this->assertSame('Pusat Uji BangDeliv', $errors['service_area_center']['name'] ?? null);
            $this->assertSame('Alamat pusat uji', $errors['service_area_center']['address'] ?? null);
        }
    }

    public function test_invalid_coordinates_throw_422_before_radius_is_evaluated(): void
    {
        $service = new BangDelivServiceAreaService;
        $invalidCoordinates = [
            'null latitude' => [null, 10.0],
            'non-numeric latitude' => ['invalid', 10.0],
            'latitude above range' => [90.0001, 10.0],
            'longitude above range' => [0.5, 180.0001],
            'zero coordinate pair' => [0.0, 0.0],
        ];

        foreach ($invalidCoordinates as $case => [$latitude, $longitude]) {
            try {
                $service->assertPointWithinRadius($latitude, $longitude, 'tujuan', $case);
                $this->fail('Expected invalid-coordinate exception for '.$case.'.');
            } catch (ApiException $exception) {
                $this->assertSame(422, $exception->status(), $case);
                $this->assertSame('SERVICE_AREA_INVALID_COORDINATE', $exception->errors()['code'] ?? null, $case);
                $this->assertSame('tujuan', $exception->errors()['point_role'] ?? null, $case);
                $this->assertSame($case, $exception->errors()['point_label'] ?? null, $case);
            }
        }
    }

    private function latitudeAtDistanceMeters(float $distanceMeters): float
    {
        return rad2deg($distanceMeters / self::EARTH_RADIUS_METERS);
    }
}
