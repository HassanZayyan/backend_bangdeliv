<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Order\OrderNumberGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_global_daily_sequence_with_wib_date(): void
    {
        $this->travelTo(Carbon::create(2026, 6, 29, 10, 15, 0, 'Asia/Jakarta'));

        $generator = app(OrderNumberGenerator::class);

        $first = $generator->next();
        $this->assertSame('BD-290626-001', $first);
        $this->createOrder($first);

        $second = $generator->next();
        $this->assertSame('BD-290626-002', $second);
        $this->createOrder($second);

        $third = $generator->next();
        $this->assertSame('BD-290626-003', $third);
    }

    public function test_resets_sequence_on_next_wib_day(): void
    {
        $generator = app(OrderNumberGenerator::class);

        $this->travelTo(Carbon::create(2026, 6, 29, 23, 59, 0, 'Asia/Jakarta'));
        $first = $generator->next();
        $this->assertSame('BD-290626-001', $first);
        $this->createOrder($first);

        $this->travelTo(Carbon::create(2026, 6, 30, 0, 1, 0, 'Asia/Jakarta'));
        $this->assertSame('BD-300626-001', $generator->next());
    }

    public function test_continues_after_three_digits_when_daily_sequence_exceeds_999(): void
    {
        $this->travelTo(Carbon::create(2026, 6, 29, 12, 0, 0, 'Asia/Jakarta'));
        $this->createOrder('BD-290626-999');

        $this->assertSame('BD-290626-1000', app(OrderNumberGenerator::class)->next());
    }

    public function test_skips_existing_order_number_collision(): void
    {
        $this->travelTo(Carbon::create(2026, 6, 29, 12, 0, 0, 'Asia/Jakarta'));
        $this->createOrder('BD-290626-001');

        $this->assertSame('BD-290626-002', app(OrderNumberGenerator::class)->next());
    }

    private function createOrder(string $orderNumber): Order
    {
        $user = User::factory()->create([
            'role' => 'customer',
        ]);

        return Order::query()->create([
            'order_number' => $orderNumber,
            'user_id' => $user->id,
            'service_type_id' => ServiceType::query()->where('code', 'RIDE')->value('id'),
            'delivery_fee' => 0,
            'total_price' => 0,
            'status_id' => OrderStatus::query()->where('code', 'PENDING')->value('id'),
        ]);
    }
}
