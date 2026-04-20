<?php

namespace Tests\Unit;

use App\Services\DeliveryPricingService;
use Tests\TestCase;

class DeliveryPricingServiceTest extends TestCase
{
    public function test_it_calculates_fee_with_ceiling_per_kilometer(): void
    {
        config([
            'bangdeliv.base_delivery_fee' => 5000,
            'bangdeliv.delivery_rate_per_km' => 2000,
        ]);

        $service = app(DeliveryPricingService::class);

        $quote950 = $service->calculateFromDistanceMeters(950);
        $quote1200 = $service->calculateFromDistanceMeters(1200);

        $this->assertSame(1, $quote950['billed_km']);
        $this->assertSame(7000.0, $quote950['total_fee']);

        $this->assertSame(2, $quote1200['billed_km']);
        $this->assertSame(9000.0, $quote1200['total_fee']);
    }

    public function test_it_keeps_base_fee_when_distance_is_zero(): void
    {
        config([
            'bangdeliv.base_delivery_fee' => 5000,
            'bangdeliv.delivery_rate_per_km' => 2000,
        ]);

        $service = app(DeliveryPricingService::class);
        $quote = $service->calculateFromDistanceMeters(0);

        $this->assertSame(0, $quote['billed_km']);
        $this->assertSame(5000.0, $quote['total_fee']);
    }
}
