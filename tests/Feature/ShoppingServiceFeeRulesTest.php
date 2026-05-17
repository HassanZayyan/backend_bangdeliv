<?php

namespace Tests\Feature;

use App\Models\ServiceType;
use App\Services\ShoppingPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShoppingServiceFeeRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_block_surcharge_uses_six_item_blocks_after_free_limit(): void
    {
        $service = app(ShoppingPricingService::class);
        $serviceTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');

        $this->assertSame(0.0, $service->calculateForItems($serviceTypeId, $this->items(11), 0)['item_surcharge']);
        $this->assertSame(2000.0, $service->calculateForItems($serviceTypeId, $this->items(12), 0)['item_surcharge']);
        $this->assertSame(4000.0, $service->calculateForItems($serviceTypeId, $this->items(18), 0)['item_surcharge']);
        $this->assertSame(6000.0, $service->calculateForItems($serviceTypeId, $this->items(24), 0)['item_surcharge']);
    }

    public function test_overweight_surcharge_is_applied_once_per_order(): void
    {
        $service = app(ShoppingPricingService::class);
        $serviceTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');

        $pricing = $service->calculateForItems($serviceTypeId, [
            ...$this->items(1, isHeavy: true),
            ...$this->items(1, isHeavy: true),
        ], 5000);

        $this->assertSame(6000.0, $pricing['overweight_surcharge']);
        $this->assertSame(11000.0, $pricing['total_price']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(int $count, bool $isHeavy = false): array
    {
        return array_map(
            fn (int $index): array => [
                'quantity' => 1,
                'unit_price' => 0,
                'is_available' => true,
                'is_heavy' => $isHeavy,
            ],
            range(1, $count)
        );
    }
}
