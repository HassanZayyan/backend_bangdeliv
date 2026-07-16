<?php

namespace Tests\Unit\Formula;

use App\Services\Pricing\DeliveryPricingService;
use Tests\TestCase;

class Equation32BillableKilometersFormulaTest extends TestCase
{
    public function test_equation_32_uses_two_decimal_distance_for_the_flat_gate_and_raw_distance_for_floor_or_ceil(): void
    {
        $service = app(DeliveryPricingService::class);

        $cases = [
            [-123.45, 0, 0.0, 0],
            [1504, 1504, 1.5, 0],
            [1504.49, 1504, 1.5, 0],
            [1504.5, 1505, 1.51, 1],
            [1505, 1505, 1.51, 1],
            [3699, 3699, 3.7, 3],
            [3700, 3700, 3.7, 4],
        ];

        foreach ($cases as [$inputMeters, $expectedMeters, $expectedDistanceKm, $expectedBilledKm]) {
            $quote = $service->calculateFromDistanceMeters($inputMeters);

            $this->assertSame($expectedMeters, $quote['distance_meters'], 'normalized meters for '.$inputMeters);
            $this->assertSame($expectedDistanceKm, $quote['distance_km'], 'display distance for '.$inputMeters);
            $this->assertSame($expectedBilledKm, $quote['billed_km'], 'billable kilometers for '.$inputMeters);
        }
    }
}
