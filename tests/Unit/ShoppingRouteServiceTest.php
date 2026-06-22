<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Services\Geo\BangDelivServiceAreaService;
use App\Services\Maps\GoogleMapsDistanceMatrixService;
use App\Services\Pricing\DeliveryPricingService;
use App\Services\Shopping\ShoppingDeliveryFeeLockResolver;
use App\Services\Shopping\ShoppingRouteService;
use Tests\TestCase;

class ShoppingRouteServiceTest extends TestCase
{
    public function test_single_merchant_route_rejects_dropoff_too_close_without_calling_maps(): void
    {
        $maps = new class extends GoogleMapsDistanceMatrixService
        {
            public bool $called = false;

            public function resolveRoute(float $originLat, float $originLng, float $destinationLat, float $destinationLng): array
            {
                $this->called = true;

                return [];
            }
        };

        $service = new ShoppingRouteService(
            $maps,
            new DeliveryPricingService,
            new ShoppingDeliveryFeeLockResolver,
            new BangDelivServiceAreaService
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Titik antar terlalu dekat dengan merchant. Pilih titik antar yang berbeda.');

        try {
            $service->calculateForPoints(
                [[
                    'label' => 'Merchant Test',
                    'latitude' => -7.328900,
                    'longitude' => 110.500100,
                ]],
                [
                    'label' => 'Titik Antar',
                    'latitude' => -7.328900,
                    'longitude' => 110.500100,
                ],
            );
        } finally {
            $this->assertFalse($maps->called);
        }
    }

    public function test_route_longer_than_fifty_kilometers_is_allowed_when_points_are_inside_radius(): void
    {
        $maps = new class extends GoogleMapsDistanceMatrixService
        {
            public function resolveRoute(float $originLat, float $originLng, float $destinationLat, float $destinationLng): array
            {
                return [
                    'distance_meters' => 75_100,
                    'distance_km' => 75.1,
                    'distance_text' => '75,1 km',
                    'duration_seconds' => 3600,
                    'duration_text' => '1 jam',
                    'route_provider' => 'distance_matrix',
                    'route_status' => 'OK',
                ];
            }
        };

        $service = new ShoppingRouteService(
            $maps,
            new DeliveryPricingService,
            new ShoppingDeliveryFeeLockResolver,
            new BangDelivServiceAreaService
        );

        $route = $service->calculateForPoints(
            [[
                'label' => 'Merchant Dekat Pusat',
                'latitude' => -7.319916770351389,
                'longitude' => 110.46393594806243,
            ]],
            [
                'label' => 'Titik Antar Dekat Pusat',
                'latitude' => -7.320300,
                'longitude' => 110.464300,
            ],
        );

        $this->assertSame(75_100, $route['distance_meters']);
        $this->assertSame(75.1, $route['distance_km']);
    }

    public function test_rejects_point_outside_bang_deliv_service_radius_without_calling_maps(): void
    {
        $maps = new class extends GoogleMapsDistanceMatrixService
        {
            public bool $called = false;

            public function resolveRoute(float $originLat, float $originLng, float $destinationLat, float $destinationLng): array
            {
                $this->called = true;

                return [];
            }
        };

        $service = new ShoppingRouteService(
            $maps,
            new DeliveryPricingService,
            new ShoppingDeliveryFeeLockResolver,
            new BangDelivServiceAreaService
        );

        try {
            $service->calculateForPoints(
                [[
                    'label' => 'Merchant Jakarta',
                    'latitude' => -6.175392,
                    'longitude' => 106.827153,
                ]],
                [
                    'label' => 'Titik Antar Dekat Pusat',
                    'latitude' => -7.320300,
                    'longitude' => 110.464300,
                ],
            );

            $this->fail('Expected service area distance limit exception.');
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->status());
            $this->assertSame(BangDelivServiceAreaService::ERROR_DISTANCE_LIMIT, $exception->errors()['code'] ?? null);
            $this->assertSame('merchant', $exception->errors()['point_role'] ?? null);
            $this->assertStringContainsString('melebihi batas layanan', $exception->getMessage());
            $this->assertFalse($maps->called);
        }
    }
}
