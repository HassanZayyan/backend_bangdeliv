<?php

namespace Tests\Unit\Formula;

use App\Services\Driver\DriverIncomeFeeCalculator;
use Tests\TestCase;

class Equation38DriverNetIncomeFormulaTest extends TestCase
{
    public function test_net_income_is_gross_income_minus_the_rounded_admin_fee(): void
    {
        $breakdown = app(DriverIncomeFeeCalculator::class)->breakdown(15000, 10);

        $this->assertSame(15000.0, $breakdown['gross_income']);
        $this->assertSame(1500.0, $breakdown['admin_fee']);
        $this->assertSame(13500.0, $breakdown['net_income']);
    }

    public function test_one_hundred_percent_admin_fee_produces_zero_net_income(): void
    {
        $breakdown = app(DriverIncomeFeeCalculator::class)->breakdown(15000, 100);

        $this->assertSame(15000.0, $breakdown['admin_fee']);
        $this->assertSame(0.0, $breakdown['net_income']);
    }

    public function test_negative_gross_income_is_normalized_and_net_income_never_goes_below_zero(): void
    {
        $breakdown = app(DriverIncomeFeeCalculator::class)->breakdown(-2500, 10);

        $this->assertSame(0.0, $breakdown['gross_income']);
        $this->assertSame(0.0, $breakdown['admin_fee']);
        $this->assertSame(0.0, $breakdown['net_income']);
    }

    public function test_net_income_retains_two_decimal_gross_precision_after_whole_rupiah_fee_rounding(): void
    {
        $breakdown = app(DriverIncomeFeeCalculator::class)->breakdown(100.126, 12.5);

        $this->assertSame(100.13, $breakdown['gross_income']);
        $this->assertSame(13.0, $breakdown['admin_fee']);
        $this->assertSame(87.13, $breakdown['net_income']);
    }
}
