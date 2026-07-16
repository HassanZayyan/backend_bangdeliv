<?php

namespace Tests\Unit\Formula;

use App\Services\Pricing\ShoppingPricingService;
use Tests\TestCase;

class Equation35ShoppingTotalPriceFormulaTest extends TestCase
{
    public function test_equation_35_adds_subtotal_effective_delivery_fee_and_all_normal_service_fees(): void
    {
        $pricing = app(ShoppingPricingService::class)->calculateForItems(
            1,
            [
                ['quantity' => 2, 'unit_price' => 10000, 'is_available' => true],
                ['quantity' => 1, 'unit_price' => 5000, 'is_available' => true],
                ['quantity' => 1, 'unit_price' => 9000, 'is_available' => false],
            ],
            13000,
            cancellationPenalty: 1500,
            failedTripCompensation: 2500,
        );

        $this->assertSame(25000.0, $pricing['subtotal']);
        $this->assertSame(13000.0, $pricing['delivery_fee']);
        $this->assertSame(4000.0, $pricing['service_fee']);
        $this->assertSame(42000.0, $pricing['total_price']);
    }

    public function test_equation_35_zeroes_subtotal_and_delivery_fee_and_uses_the_larger_fee_in_penalty_only_mode(): void
    {
        $pricing = app(ShoppingPricingService::class)->calculateForItems(
            1,
            [
                ['quantity' => 2, 'unit_price' => 10000, 'is_available' => true],
            ],
            7000,
            cancellationPenalty: 3000,
            penaltyOnly: true,
            failedTripCompensation: 4000,
        );

        $this->assertSame(0.0, $pricing['subtotal']);
        $this->assertSame(0.0, $pricing['delivery_fee']);
        $this->assertSame(4000.0, $pricing['service_fee']);
        $this->assertSame(4000.0, $pricing['total_price']);
    }
}
