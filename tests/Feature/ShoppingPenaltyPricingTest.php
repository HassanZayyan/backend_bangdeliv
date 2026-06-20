<?php

namespace Tests\Feature;

use App\Models\ServiceType;
use App\Services\Pricing\ShoppingPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShoppingPenaltyPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_block_surcharge_is_no_longer_applied(): void
    {
        $service = app(ShoppingPricingService::class);
        $serviceTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');

        $this->assertSame(0.0, $service->calculateForItems($serviceTypeId, $this->items(6), 0)['item_surcharge']);
        $this->assertSame(0.0, $service->calculateForItems($serviceTypeId, $this->items(7), 0)['item_surcharge']);
        $this->assertSame(0.0, $service->calculateForItems($serviceTypeId, $this->items(19), 0)['item_surcharge']);
    }

    public function test_overweight_surcharge_is_no_longer_applied(): void
    {
        $service = app(ShoppingPricingService::class);
        $serviceTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');

        $pricing = $service->calculateForItems($serviceTypeId, [
            ...$this->items(1),
            ...$this->items(1),
        ], 5000);

        $this->assertSame(0.0, $pricing['overweight_surcharge']);
        $this->assertSame(false, $pricing['has_overweight_item']);
        $this->assertSame(5000.0, $pricing['total_price']);
        $this->assertSame([], $pricing['fee_breakdown']);
    }

    public function test_explicit_cancellation_penalty_is_the_only_service_fee(): void
    {
        $service = app(ShoppingPricingService::class);
        $serviceTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');

        $pricing = $service->calculateForItems(
            $serviceTypeId,
            $this->items(1),
            0,
            cancellationPenalty: 3000,
            penaltyOnly: true,
        );

        $this->assertSame(3000.0, $pricing['cancellation_penalty']);
        $this->assertSame(3000.0, $pricing['service_fee']);
        $this->assertSame(3000.0, $pricing['total_price']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(int $count): array
    {
        return array_map(
            fn (int $index): array => [
                'quantity' => 1,
                'unit_price' => 0,
                'is_available' => true,
            ],
            range(1, $count)
        );
    }
}
