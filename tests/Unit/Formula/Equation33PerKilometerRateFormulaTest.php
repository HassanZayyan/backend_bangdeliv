<?php

namespace Tests\Unit\Formula;

use App\Services\Pricing\DeliveryPricingService;
use Tests\TestCase;

class Equation33PerKilometerRateFormulaTest extends TestCase
{
    public function test_equation_33_selects_the_rate_from_raw_distance_at_each_tier_boundary(): void
    {
        config([
            'bangdeliv.delivery_rate_0_10_per_km' => 2000,
            'bangdeliv.delivery_rate_10_25_per_km' => 2500,
            'bangdeliv.delivery_rate_25_50_per_km' => 3000,
        ]);

        $service = app(DeliveryPricingService::class);
        $cases = [
            [9999, 2000.0],
            [10000, 2500.0],
            [24999, 2500.0],
            [25000, 3000.0],
        ];

        foreach ($cases as [$distanceMeters, $expectedRate]) {
            $quote = $service->calculateFromDistanceMeters($distanceMeters);

            $this->assertSame($expectedRate, $quote['rate_per_km'], 'rate for '.$distanceMeters.' meters');
        }
    }
}
