<?php

namespace Tests\Unit\Formula;

use App\Services\Pricing\DeliveryPricingService;
use Tests\TestCase;

class Equation31DeliveryFeeFormulaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bangdeliv.base_delivery_fee' => 5000,
            'bangdeliv.delivery_rate_0_10_per_km' => 2000,
            'bangdeliv.delivery_rate_10_25_per_km' => 2500,
            'bangdeliv.delivery_rate_25_50_per_km' => 3000,
        ]);
    }

    public function test_equation_31_calculates_delivery_fee_from_base_fee_billable_kilometers_and_rate(): void
    {
        $service = app(DeliveryPricingService::class);

        $flatQuote = $service->calculateFromDistanceMeters(1500);

        $this->assertSame(0, $flatQuote['billed_km']);
        $this->assertSame(5000.0, $flatQuote['base_fee']);
        $this->assertSame(0.0, $flatQuote['distance_fee']);
        $this->assertSame(5000.0, $flatQuote['total_fee']);

        $multiMerchantRouteMeters = 1200 + 1100 + 1400;
        $routeQuote = $service->calculateFromDistanceMeters($multiMerchantRouteMeters);

        $this->assertSame(3700, $routeQuote['distance_meters']);
        $this->assertSame(4, $routeQuote['billed_km']);
        $this->assertSame(2000.0, $routeQuote['rate_per_km']);
        $this->assertSame(8000.0, $routeQuote['distance_fee']);
        $this->assertSame(13000.0, $routeQuote['total_fee']);
    }
}
