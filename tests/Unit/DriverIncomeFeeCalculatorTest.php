<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Driver\DriverIncomeFeeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverIncomeFeeCalculatorTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_cancelled_ride_yields_zero_gross_income_even_with_delivery_fee(): void
    {
        // Order dibatalkan (customer menolak revisi ongkir) — trip tidak terjadi,
        // meski delivery_fee (ongkir pra-negosiasi) masih terisi 15.000.
        $order = $this->makeOrder('RIDE', 'CANCELLED', deliveryFee: 15000, totalPrice: 15000);

        $this->assertSame(0.0, app(DriverIncomeFeeCalculator::class)->grossIncomeForOrder($order));
    }

    public function test_completed_ride_gross_income_equals_delivery_fee(): void
    {
        $order = $this->makeOrder('RIDE', 'COMPLETED', deliveryFee: 15000, totalPrice: 15000);

        $this->assertSame(15000.0, app(DriverIncomeFeeCalculator::class)->grossIncomeForOrder($order));
    }

    private function makeOrder(string $serviceCode, string $statusCode, float $deliveryFee, float $totalPrice): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);

        return Order::query()->create([
            'order_number' => 'BD-INCOME-'.$statusCode.'-'.random_int(1000, 9999),
            'user_id' => $customer->id,
            'service_type_id' => ServiceType::query()->where('code', $serviceCode)->value('id'),
            'delivery_fee' => $deliveryFee,
            'total_price' => $totalPrice,
            'status_id' => OrderStatus::query()->where('code', $statusCode)->value('id'),
        ]);
    }
}
