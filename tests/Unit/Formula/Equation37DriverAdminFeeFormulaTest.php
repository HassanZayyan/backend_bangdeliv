<?php

namespace Tests\Unit\Formula;

use App\Services\Driver\DriverIncomeFeeCalculator;
use Tests\TestCase;

class Equation37DriverAdminFeeFormulaTest extends TestCase
{
    public function test_default_ten_percent_admin_fee_is_applied_to_normalized_gross_income(): void
    {
        config(['bangdeliv.driver_admin_fee_percent' => 10]);

        $breakdown = app(DriverIncomeFeeCalculator::class)->breakdown(15000);

        $this->assertSame(15000.0, $breakdown['gross_income']);
        $this->assertSame(10.0, $breakdown['admin_fee_percent']);
        $this->assertSame(1500.0, $breakdown['admin_fee']);
    }

    public function test_manual_percentage_is_normalized_to_two_decimal_places(): void
    {
        $calculator = app(DriverIncomeFeeCalculator::class);

        $manual = $calculator->breakdown(15000, 12.5);
        $rounded = $calculator->breakdown(10000, 12.555);

        $this->assertSame(12.5, $manual['admin_fee_percent']);
        $this->assertSame(1875.0, $manual['admin_fee']);
        $this->assertSame(12.56, $rounded['admin_fee_percent']);
        $this->assertSame(1256.0, $rounded['admin_fee']);
    }

    public function test_percentage_is_clamped_between_zero_and_one_hundred(): void
    {
        $calculator = app(DriverIncomeFeeCalculator::class);

        $belowMinimum = $calculator->breakdown(15000, -20);
        $aboveMaximum = $calculator->breakdown(15000, 120);

        $this->assertSame(0.0, $belowMinimum['admin_fee_percent']);
        $this->assertSame(0.0, $belowMinimum['admin_fee']);
        $this->assertSame(100.0, $aboveMaximum['admin_fee_percent']);
        $this->assertSame(15000.0, $aboveMaximum['admin_fee']);
    }

    public function test_admin_fee_is_rounded_to_the_nearest_whole_rupiah(): void
    {
        $breakdown = app(DriverIncomeFeeCalculator::class)->breakdown(15005, 10);

        $this->assertSame(1500.5, $breakdown['gross_income'] * $breakdown['admin_fee_percent'] / 100);
        $this->assertSame(1501.0, $breakdown['admin_fee']);
    }
}
