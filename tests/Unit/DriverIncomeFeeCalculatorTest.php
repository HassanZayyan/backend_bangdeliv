<?php

namespace Tests\Unit;

use App\Services\Driver\DriverIncomeFeeCalculator;
use Tests\TestCase;

class DriverIncomeFeeCalculatorTest extends TestCase
{
    public function test_it_calculates_default_driver_admin_fee_breakdown(): void
    {
        config(['bangdeliv.driver_admin_fee_percent' => 10]);

        $breakdown = app(DriverIncomeFeeCalculator::class)->breakdown(15000);

        $this->assertSame(15000.0, $breakdown['gross_income']);
        $this->assertSame(10.0, $breakdown['admin_fee_percent']);
        $this->assertSame(1500.0, $breakdown['admin_fee']);
        $this->assertSame(13500.0, $breakdown['net_income']);
    }

    public function test_it_supports_zero_and_manual_percentages(): void
    {
        $calculator = app(DriverIncomeFeeCalculator::class);

        $zero = $calculator->breakdown(15000, 0);
        $manual = $calculator->breakdown(15000, 12.5);

        $this->assertSame(0.0, $zero['admin_fee']);
        $this->assertSame(15000.0, $zero['net_income']);
        $this->assertSame(12.5, $manual['admin_fee_percent']);
        $this->assertSame(1875.0, $manual['admin_fee']);
        $this->assertSame(13125.0, $manual['net_income']);
    }
}
