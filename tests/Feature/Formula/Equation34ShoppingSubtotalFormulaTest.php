<?php

namespace Tests\Feature\Formula;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Pricing\ShoppingPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Equation34ShoppingSubtotalFormulaTest extends TestCase
{
    use RefreshDatabase;

    private int $orderSequence = 0;

    public function test_equation_34_sums_stored_subtotals_of_available_items_only(): void
    {
        $order = $this->createOrder('SHOPPING', 'ARRIVED_MERCHANT');
        $firstPickupId = $this->createPickup($order, 1);
        $secondPickupId = $this->createPickup($order, 2);

        $this->createItem($order, $firstPickupId, 'Item tersedia pertama', 2, 7000, 14000, true);
        $this->createItem($order, $firstPickupId, 'Item tidak tersedia', 1, 9000, 9000, false);
        $this->createItem($order, $secondPickupId, 'Item tersedia kedua', 1, 6000, 6000, true);

        $subtotal = app(ShoppingPricingService::class)->subtotalAmount($order->fresh());

        $this->assertSame(20000.0, $subtotal);
    }

    public function test_equation_34_prefers_the_sum_of_approved_active_merchant_quotes(): void
    {
        $order = $this->createOrder('SHOPPING', 'ARRIVED_MERCHANT');
        $firstPickupId = $this->createPickup($order, 1);
        $secondPickupId = $this->createPickup($order, 2);

        $this->createItem($order, $firstPickupId, 'Item merchant pertama', 1, 10000, 10000, true);
        $this->createItem($order, $secondPickupId, 'Item merchant kedua', 1, 8000, 8000, true);
        $this->approveMerchantQuote($order, $firstPickupId, 15500.25);
        $this->approveMerchantQuote($order, $secondPickupId, 9000.25);

        $subtotal = app(ShoppingPricingService::class)->subtotalAmount($order->fresh());

        $this->assertSame(24500.5, $subtotal);
    }

    public function test_equation_34_recalculates_available_line_values_and_accepts_a_positive_approved_override(): void
    {
        $service = app(ShoppingPricingService::class);
        $shoppingTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');
        $items = [
            ['quantity' => 2, 'unit_price' => 7500.555, 'is_available' => true],
            ['quantity' => 0, 'unit_price' => 1200, 'is_available' => true],
            ['quantity' => 5, 'unit_price' => 9999, 'is_available' => false],
        ];

        $calculated = $service->calculateForItems($shoppingTypeId, $items, 0);
        $overridden = $service->calculateForItems($shoppingTypeId, $items, 0, subtotalOverride: 20500.126);

        $this->assertSame(16201.11, $calculated['subtotal']);
        $this->assertSame(20500.13, $overridden['subtotal']);
    }

    public function test_equation_34_returns_zero_for_non_shopping_and_cancelled_with_fee_orders(): void
    {
        $rideOrder = $this->createOrder('RIDE', 'PENDING');
        $cancelledShoppingOrder = $this->createOrder('SHOPPING', 'CANCELLED_WITH_FEE');
        $this->createItem($cancelledShoppingOrder, null, 'Item dibatalkan', 2, 7000, 14000, true);

        $service = app(ShoppingPricingService::class);

        $this->assertSame(0.0, $service->subtotalAmount($rideOrder));
        $this->assertSame(0.0, $service->subtotalAmount($cancelledShoppingOrder->fresh()));
    }

    private function createOrder(string $serviceCode, string $statusCode): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);

        return Order::query()->create([
            'order_number' => sprintf('BD-F34-%02d', ++$this->orderSequence),
            'user_id' => $customer->id,
            'service_type_id' => ServiceType::query()->where('code', $serviceCode)->value('id'),
            'delivery_fee' => 5000,
            'total_price' => 5000,
            'status_id' => OrderStatus::query()->where('code', $statusCode)->value('id'),
        ]);
    }

    private function createPickup(Order $order, int $sequence): int
    {
        return (int) $order->orderLocations()->create([
            'location_role' => 'PICKUP',
            'label' => 'Merchant '.$sequence,
            'full_address' => 'Alamat Merchant '.$sequence,
            'latitude' => -7.0 - ($sequence / 1000),
            'longitude' => 110.4 + ($sequence / 1000),
            'sequence_no' => $sequence,
            'fulfillment_status' => 'ITEMS_CONFIRMED',
        ])->id;
    }

    private function createItem(
        Order $order,
        ?int $pickupLocationId,
        string $name,
        int $quantity,
        float $unitPrice,
        float $subtotal,
        bool $isAvailable,
    ): void {
        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickupLocationId,
            'item_source' => 'MANUAL',
            'menu_name' => $name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'is_available' => $isAvailable,
        ]);
    }

    private function approveMerchantQuote(Order $order, int $pickupLocationId, float $amount): void
    {
        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => 'SHOPPING_NEGOTIATION',
            'trigger_type' => 'CUSTOMER_PRICE_APPROVED',
            'changed_by_user_id' => $order->user_id,
            'note' => 'Quote merchant disetujui untuk pembuktian rumus (3.4).',
            'metadata' => [
                'pickup_location_id' => $pickupLocationId,
                'approved_amount' => $amount,
                'status' => 'APPROVED',
            ],
        ]);
    }
}
