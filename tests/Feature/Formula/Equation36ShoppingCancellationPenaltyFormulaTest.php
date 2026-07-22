<?php

namespace Tests\Feature\Formula;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Order\DeliveryFeeNegotiationService;
use App\Services\Pricing\ShoppingPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Equation36ShoppingCancellationPenaltyFormulaTest extends TestCase
{
    use RefreshDatabase;

    private int $orderSequence = 0;

    public function test_penalty_starts_after_three_aggregated_pickup_failures(): void
    {
        $service = app(ShoppingPricingService::class);
        $order = $this->createShoppingOrder(10000);

        $this->addFailedPickups($order, [1, 1]);

        $this->assertSame(2, $service->failedAttemptCount($order));
        $this->assertSame(0.0, $service->calculateCancellationPenalty($order));

        $this->addFailedPickups($order, [1]);

        $this->assertSame(3, $service->failedAttemptCount($order));
        $this->assertSame(5000.0, $service->calculateCancellationPenalty($order));
    }

    public function test_locked_base_precedes_recorded_base_and_stored_delivery_fee(): void
    {
        $service = app(ShoppingPricingService::class);
        $recordedBaseOrder = $this->createShoppingOrder(10000);
        $this->addFailedPickups($recordedBaseOrder, [1, 1, 1]);
        $this->recordLog($recordedBaseOrder, [
            'penalty_base_delivery_fee' => 18000,
        ]);

        $this->assertSame(18000.0, $service->cancellationPenaltyBaseAmount($recordedBaseOrder));
        $this->assertSame(9000.0, $service->calculateCancellationPenalty($recordedBaseOrder));

        $lockedBaseOrder = $this->createShoppingOrder(10000);
        $this->addFailedPickups($lockedBaseOrder, [1, 1, 1]);
        $this->recordLog($lockedBaseOrder, [
            'penalty_base_delivery_fee' => 18000,
        ]);
        OrderLog::query()->create([
            'order_id' => $lockedBaseOrder->id,
            'event_type' => DeliveryFeeNegotiationService::EVENT_TYPE,
            'trigger_type' => DeliveryFeeNegotiationService::CUSTOMER_FEE_APPROVED,
            'note' => 'Ongkir hasil persetujuan untuk pembuktian rumus (3.6).',
            'metadata' => [
                'approved_amount' => 30000,
                'status' => 'APPROVED',
            ],
        ]);

        $this->assertSame(30000.0, $service->cancellationPenaltyBaseAmount($lockedBaseOrder));
        $this->assertSame(15000.0, $service->calculateCancellationPenalty($lockedBaseOrder));
    }

    public function test_valid_manual_snapshot_has_highest_precedence(): void
    {
        $service = app(ShoppingPricingService::class);
        $order = $this->createShoppingOrder(10000, 'CANCELLED_WITH_FEE');
        $this->addFailedPickups($order, [3]);
        $this->recordLog(
            $order,
            [
                'pricing_scope' => ShoppingPricingService::PRICING_SCOPE_SHOPPING_CANCELLATION_BASE_50_PERCENT,
                'cancellation_penalty_base_delivery_fee' => 100000,
                'cancellation_penalty' => 33333.33,
            ],
            ShoppingPricingService::MANUAL_CANCELLATION_TRIGGER,
        );

        $this->assertSame(100000.0, $service->cancellationPenaltyBaseAmount($order));
        $this->assertSame(33333.33, $service->calculateCancellationPenalty($order));
    }

    public function test_penalty_always_uses_registered_delivery_fee_base(): void
    {
        $service = app(ShoppingPricingService::class);
        $order = $this->createShoppingOrder(12000);
        $this->addFailedPickups($order, [3]);
        $this->recordLog($order, [
            'penalty_base_delivery_fee' => 80000,
        ]);

        $this->assertSame(40000.0, $service->calculateCancellationPenaltyFromBase(80000));

        // Kompensasi perjalanan gagal tetap dihitung, tetapi tidak lagi
        // mendahului penalti. Keduanya dibandingkan lewat max(P, C) pada
        // Persamaan (5), sehingga penalti memakai basis ongkir terdaftar.
        $this->assertSame(6000.0, $service->chargeableFailedTripCompensationAmount($order));
        $this->assertSame(40000.0, $service->calculateCancellationPenalty($order));
    }

    public function test_negative_penalty_base_is_normalized_to_zero(): void
    {
        $this->assertSame(
            0.0,
            app(ShoppingPricingService::class)->calculateCancellationPenaltyFromBase(-1234.56),
        );
    }

    private function createShoppingOrder(float $deliveryFee, string $statusCode = 'ARRIVED_MERCHANT'): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);

        return Order::query()->create([
            'order_number' => sprintf('BD-E36-%04d', ++$this->orderSequence),
            'user_id' => $customer->id,
            'service_type_id' => ServiceType::query()->where('code', 'SHOPPING')->value('id'),
            'delivery_fee' => $deliveryFee,
            'total_price' => $deliveryFee,
            'status_id' => OrderStatus::query()->where('code', $statusCode)->value('id'),
        ]);
    }

    /** @param array<int, int> $failureCounts */
    private function addFailedPickups(Order $order, array $failureCounts): void
    {
        $sequence = (int) $order->orderLocations()->where('location_role', 'PICKUP')->max('sequence_no');

        foreach ($failureCounts as $failedAttemptCount) {
            $sequence++;
            $order->orderLocations()->create([
                'location_role' => 'PICKUP',
                'label' => 'Merchant '.$sequence,
                'full_address' => 'Jl. Merchant '.$sequence,
                'latitude' => -7.0 - ($sequence / 1000),
                'longitude' => 110.4 + ($sequence / 1000),
                'sequence_no' => $sequence,
                'fulfillment_status' => 'FAILED',
                'failed_attempt_count' => $failedAttemptCount,
            ]);
        }

        $order->unsetRelation('orderLocations');
    }

    /** @param array<string, mixed> $metadata */
    private function recordLog(
        Order $order,
        array $metadata,
        string $triggerType = 'FORMULA_36_TEST',
    ): void {
        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => 'PRICE_RECALCULATION',
            'trigger_type' => $triggerType,
            'note' => 'Data audit untuk pembuktian rumus (3.6).',
            'metadata' => $metadata,
        ]);
    }
}
