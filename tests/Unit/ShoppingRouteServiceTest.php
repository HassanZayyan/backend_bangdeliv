<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
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
            new ShoppingDeliveryFeeLockResolver
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
}
