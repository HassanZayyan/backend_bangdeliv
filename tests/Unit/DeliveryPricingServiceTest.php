<?php

namespace Tests\Unit;

use App\Services\DeliveryPricingService;
use Tests\TestCase;

class DeliveryPricingServiceTest extends TestCase
{
    public function test_it_calculates_revised_bangdeliv_delivery_fee_tiers(): void
    {
        config([
            'bangdeliv.base_delivery_fee' => 5000,
            'bangdeliv.delivery_rate_0_10_per_km' => 2000,
            'bangdeliv.delivery_rate_10_25_per_km' => 2500,
            'bangdeliv.delivery_rate_25_50_per_km' => 3000,
            'bangdeliv.max_delivery_distance' => 50,
        ]);

        $service = app(DeliveryPricingService::class);

        $cases = [
            [1.4, 0, 5000.0, 2000.0],
            [1.5, 0, 5000.0, 2000.0],
            [1.6, 1, 7000.0, 2000.0],
            [3.6, 3, 11000.0, 2000.0],
            [3.7, 4, 13000.0, 2000.0],
            [9.9, 10, 25000.0, 2000.0],
            [10.0, 10, 30000.0, 2500.0],
            [24.9, 25, 67500.0, 2500.0],
            [25.0, 25, 80000.0, 3000.0],
            [50.0, 50, 155000.0, 3000.0],
        ];

        foreach ($cases as [$distanceKm, $expectedBillableKm, $expectedTotalFee, $expectedRate]) {
            $quote = $service->calculateFromDistanceMeters($distanceKm * 1000);

            $this->assertSame($expectedBillableKm, $quote['billed_km'], 'billed km for '.$distanceKm.' km');
            $this->assertSame($expectedRate, $quote['rate_per_km'], 'rate for '.$distanceKm.' km');
            $this->assertSame($expectedTotalFee, $quote['total_fee'], 'total fee for '.$distanceKm.' km');
        }
    }

    public function test_it_rejects_distance_above_fifty_kilometers(): void
    {
        config(['bangdeliv.max_delivery_distance' => 50]);

        $service = app(DeliveryPricingService::class);

        $this->assertTrue($service->isWithinMaxDistance(50_000));
        $this->assertFalse($service->isWithinMaxDistance(50_100));
    }
}
