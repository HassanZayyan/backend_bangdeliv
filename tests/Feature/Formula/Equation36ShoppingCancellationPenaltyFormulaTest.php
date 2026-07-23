<?php

namespace Tests\Feature\Formula;

use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderStatus;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingReplacementProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Persamaan (6) model revisi: P = Pm (snapshot manual) | 0,5 x O(d_max) bila
 * g>=3 dan d_max>0 | 0. d_max = jarak rute terjauh customer -> toko/resto gagal
 * TERVERIFIKASI. Basis ongkir terdaftar (Bp) dan max(P, C) dihapus.
 */
class Equation36ShoppingCancellationPenaltyFormulaTest extends TestCase
{
    use RefreshDatabase;

    private int $orderSequence = 0;

    public function test_no_penalty_below_three_failures(): void
    {
        $service = app(ShoppingPricingService::class);
        $order = $this->createShoppingOrder();
        $this->addFailedStores($order, [[1200, true], [3000, true]]);

        $this->assertSame(2, $service->failedAttemptCount($order));
        $this->assertSame(0.0, $service->calculateCancellationPenalty($order));
    }

    public function test_penalty_is_half_of_route_to_the_farthest_verified_store(): void
    {
        $service = app(ShoppingPricingService::class);
        $order = $this->createShoppingOrder();
        // Tiga toko gagal terverifikasi; jarak rute customer->toko 1.200/2.000/3.000.
        // d_max = 3.000 -> O(3 km) = 11.000 -> P = 5.500.
        $this->addFailedStores($order, [[1200, true], [2000, true], [3000, true]]);

        $this->assertSame(3, $service->failedAttemptCount($order));
        $this->assertSame(5500.0, $service->calculateCancellationPenalty($order));
    }

    public function test_unverified_failures_yield_zero_penalty(): void
    {
        $service = app(ShoppingPricingService::class);
        $order = $this->createShoppingOrder();
        // Tiga toko gagal tetapi tak satu pun terverifikasi -> d_max=0 -> P=0.
        $this->addFailedStores($order, [[1200, false], [2000, false], [3000, false]]);

        $this->assertSame(3, $service->failedAttemptCount($order));
        $this->assertSame(0.0, $service->calculateCancellationPenalty($order));
    }

    public function test_valid_manual_snapshot_has_highest_precedence(): void
    {
        $service = app(ShoppingPricingService::class);
        $order = $this->createShoppingOrder('CANCELLED_WITH_FEE');
        $this->addFailedStores($order, [[1200, true], [2000, true], [3000, true]]);
        // Snapshot manual driver menang atas 0,5 x O(d_max).
        $this->recordLog(
            $order,
            [
                'pricing_scope' => ShoppingPricingService::PRICING_SCOPE_SHOPPING_CANCELLATION_BASE_50_PERCENT,
                'cancellation_penalty_base_delivery_fee' => 100000,
                'cancellation_penalty' => 33333.33,
            ],
            ShoppingPricingService::MANUAL_CANCELLATION_TRIGGER,
        );

        $this->assertSame(33333.33, $service->calculateCancellationPenalty($order));
    }

    public function test_negative_penalty_base_is_normalized_to_zero(): void
    {
        $this->assertSame(
            0.0,
            app(ShoppingPricingService::class)->calculateCancellationPenaltyFromBase(-1234.56),
        );
    }

    private function createShoppingOrder(string $statusCode = 'ARRIVED_MERCHANT'): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $order = Order::query()->create([
            'order_number' => sprintf('BD-E36-%04d', ++$this->orderSequence),
            'user_id' => $customer->id,
            'service_type_id' => ServiceType::query()->where('code', 'SHOPPING')->value('id'),
            'delivery_fee' => 10000,
            'total_price' => 10000,
            'status_id' => OrderStatus::query()->where('code', $statusCode)->value('id'),
        ]);

        $order->orderLocations()->create([
            'location_role' => 'DROPOFF',
            'label' => 'Titik Antar',
            'full_address' => 'Jl. Antar',
            'latitude' => -7.320,
            'longitude' => 110.470,
            'sequence_no' => 99,
            'fulfillment_status' => 'PENDING',
        ]);

        return $order;
    }

    /**
     * @param  array<int, array{0: int, 1: bool}>  $stores  [jarak rute customer->toko, terverifikasi]
     */
    private function addFailedStores(Order $order, array $stores): void
    {
        $sequence = (int) $order->orderLocations()->where('location_role', 'PICKUP')->max('sequence_no');

        foreach ($stores as [$routeMeters, $verified]) {
            $sequence++;
            $pickup = $order->orderLocations()->create([
                'location_role' => 'PICKUP',
                'label' => 'Toko '.$sequence,
                'full_address' => 'Jl. Toko '.$sequence,
                'latitude' => -7.30 - ($sequence / 1000),
                'longitude' => 110.46 + ($sequence / 1000),
                'sequence_no' => $sequence,
                'fulfillment_status' => 'FAILED',
                'failed_attempt_count' => 1,
            ]);

            // Event kegagalan dengan jarak rute customer->toko yang dibekukan.
            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => ShoppingReplacementProjectionService::FAILED_TRIP_EVENT,
                'trigger_type' => 'SHOPPING_FAILED_TRIP_RECORDED',
                'note' => 'Toko tutup.',
                'metadata' => [
                    'pickup_location_id' => $pickup->id,
                    'customer_route_distance_meters' => $routeMeters,
                    'verified_for_compensation' => $verified,
                ],
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
