<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Models\OrderStatusHistory;
use App\Models\ServiceFeeRule;
use App\Models\ShoppingOrder;
use Illuminate\Support\Facades\DB;

class ShoppingPricingService
{
    public function __construct(
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderRealtimeBroadcaster $realtimeBroadcaster,
    ) {}

    /**
     * @param  iterable<int, \App\Models\OrderItem|array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function calculateForItems(
        int $serviceTypeId,
        iterable $items,
        float $deliveryFee,
        float $cancellationPenalty = 0.0,
        ?float $subtotalOverride = null,
        bool $penaltyOnly = false,
    ): array {
        $itemBlockRule = $this->getRuleConfig($serviceTypeId, 'ITEM_BLOCK_SURCHARGE');
        $overweightRule = $this->getRuleConfig($serviceTypeId, 'OVERWEIGHT_FLAT_SURCHARGE');

        $subtotal = 0.0;
        $totalItemQuantity = 0;
        $hasOverweightItem = false;

        foreach ($items as $item) {
            $isAvailable = $this->itemBool($item, 'is_available', true);
            if (! $isAvailable) {
                continue;
            }

            $quantity = max(1, $this->itemInt($item, 'quantity', 1));
            $unitPrice = max(0.0, $this->itemFloat($item, 'unit_price', 0.0));

            $subtotal += round($unitPrice * $quantity, 2);
            $totalItemQuantity += $quantity;

            if ($this->itemBool($item, 'is_heavy', false)) {
                $hasOverweightItem = true;
            }
        }

        $subtotal = round($subtotal, 2);
        $itemSurcharge = $this->calculateItemSurcharge($totalItemQuantity, $itemBlockRule);
        $overweightSurcharge = $hasOverweightItem ? (float) ($overweightRule['surcharge'] ?? 0) : 0.0;

        if ($subtotalOverride !== null && $subtotalOverride > 0) {
            $subtotal = round($subtotalOverride, 2);
        }

        if ($penaltyOnly && $cancellationPenalty > 0) {
            $subtotal = 0.0;
            $deliveryFee = 0.0;
            $itemSurcharge = 0.0;
            $overweightSurcharge = 0.0;
            $hasOverweightItem = false;
        }

        $serviceFee = round($itemSurcharge + $overweightSurcharge + $cancellationPenalty, 2);
        $totalPrice = round($subtotal + $deliveryFee + $serviceFee, 2);
        $feeBreakdown = $this->feeBreakdown(
            $totalItemQuantity,
            $itemSurcharge,
            $overweightSurcharge,
            $cancellationPenalty,
            $itemBlockRule,
        );

        return [
            'item_count' => $totalItemQuantity,
            'subtotal' => $subtotal,
            'delivery_fee' => round($deliveryFee, 2),
            'item_surcharge' => round($itemSurcharge, 2),
            'overweight_surcharge' => round($overweightSurcharge, 2),
            'cancellation_penalty' => round($cancellationPenalty, 2),
            'service_fee' => $serviceFee,
            'total_price' => $totalPrice,
            'has_overweight_item' => $hasOverweightItem,
            'fee_breakdown' => $feeBreakdown,
        ];
    }

    public function recalculate(
        Order $order,
        int $changedByUserId,
        string $triggerType,
        bool $writeHistory = true,
        ?string $historyNote = null,
    ): Order {
        $order->loadMissing(['items', 'shoppingOrder', 'serviceType']);

        $shoppingOrder = $order->shoppingOrder;
        if (! $shoppingOrder instanceof ShoppingOrder) {
            throw new ApiException('Data shopping order tidak ditemukan.', 500);
        }
        $shoppingOrder->refresh();
        $previousSnapshot = is_array($shoppingOrder->pricing_snapshot)
            ? $shoppingOrder->pricing_snapshot
            : [];

        $oldSubtotal = (float) $order->subtotal;
        $oldDeliveryFee = (float) $order->delivery_fee;
        $oldServiceFee = (float) $order->service_fee;
        $oldTotalPrice = (float) $order->total_price;
        $deliveryFee = (float) $order->delivery_fee;
        $cancellationPenalty = (float) $shoppingOrder->cancellation_penalty;
        $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));
        $penaltyOnly = $cancellationPenalty > 0 && $statusCode === 'CANCELLED_WITH_FEE';
        $subtotalOverride = $penaltyOnly ? null : $this->driverShoppingTotalAmountFromSnapshot($shoppingOrder);

        $pricing = $this->calculateForItems(
            (int) $order->service_type_id,
            $order->items,
            $deliveryFee,
            $cancellationPenalty,
            $subtotalOverride,
            $penaltyOnly,
        );

        $nextVersion = (int) $shoppingOrder->recalculation_version + 1;
        $pricingSnapshot = [
            ...$pricing,
            'recalculation_version' => $nextVersion,
        ];
        if (isset($previousSnapshot['shopping_route'])) {
            $pricingSnapshot['shopping_route'] = $previousSnapshot['shopping_route'];
        }
        if ($subtotalOverride !== null && $subtotalOverride > 0) {
            $pricingSnapshot['driver_shopping_total_amount'] = round($subtotalOverride, 2);
        }
        if ($cancellationPenalty > 0) {
            $pricingSnapshot['penalty_base_delivery_fee'] = round(
                is_numeric($previousSnapshot['penalty_base_delivery_fee'] ?? null)
                    ? (float) $previousSnapshot['penalty_base_delivery_fee']
                    : $oldDeliveryFee,
                2
            );
        }

        $shoppingOrder->update([
            'item_surcharge' => $pricing['item_surcharge'],
            'overweight_surcharge' => $pricing['overweight_surcharge'],
            'has_overweight_item' => $pricing['has_overweight_item'],
            'recalculation_version' => $nextVersion,
            'last_recalculated_at' => now(),
            'pricing_snapshot' => $pricingSnapshot,
        ]);

        $orderUpdates = [
            'subtotal' => $pricing['subtotal'],
            'service_fee' => $pricing['service_fee'],
            'total_price' => $pricing['total_price'],
        ];

        if ($penaltyOnly) {
            $orderUpdates['delivery_fee'] = 0;
        }

        $order->update($orderUpdates);

        $this->orderPaymentService->syncPendingCodAmount($order->refresh());

        OrderLog::query()->create([
            'order_id' => $order->id,
            'log_type' => 'PRICE_RECALCULATION',
            'trigger_type' => $triggerType,
            'old_subtotal' => round($oldSubtotal, 2),
            'new_subtotal' => $pricing['subtotal'],
            'old_delivery_fee' => round($oldDeliveryFee, 2),
            'new_delivery_fee' => $pricing['delivery_fee'],
            'old_service_fee' => round($oldServiceFee, 2),
            'new_service_fee' => $pricing['service_fee'],
            'old_total_price' => round($oldTotalPrice, 2),
            'new_total_price' => $pricing['total_price'],
            'delta_total_price' => round($pricing['total_price'] - $oldTotalPrice, 2),
            'recalculation_version' => $nextVersion,
            'changed_by_user_id' => $changedByUserId,
            'note' => $historyNote ?: 'Rekalkulasi harga order SHOPPING setelah perubahan item.',
            'metadata' => [
                'item_count' => $pricing['item_count'],
                'has_overweight_item' => $pricing['has_overweight_item'],
                'trigger_type' => $triggerType,
            ],
        ]);

        if ($writeHistory) {
            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'event_type' => 'ITEM_UPDATE',
                'changed_by_user_id' => $changedByUserId,
                'note' => $historyNote ?: 'Perubahan item order SHOPPING.',
                'price_snapshot' => [
                    'subtotal' => $pricing['subtotal'],
                    'service_fee' => $pricing['service_fee'],
                    'total_price' => $pricing['total_price'],
                    'recalculation_version' => $nextVersion,
                ],
            ]);
        }

        $freshOrder = $order->refresh()->load([
            'restaurant',
            'orderLocations',
            'items',
            'payments',
            'statusRef',
            'statusHistories.statusRef',
            'shoppingOrder',
            'serviceType',
        ]);

        $this->broadcastContentUpdatedAfterCommit((int) $freshOrder->id, $triggerType, [
            ...$pricing,
            'recalculation_version' => $nextVersion,
            'delivery_fee_source' => $freshOrder->delivery_fee_source ?: 'system',
            'manual_delivery_fee' => $freshOrder->manual_delivery_fee !== null
                ? round((float) $freshOrder->manual_delivery_fee, 2)
                : null,
            'manual_delivery_fee_reason' => $freshOrder->manual_delivery_fee_reason,
            'careful_carry_required' => (bool) ($freshOrder->careful_carry_required ?? false),
        ]);

        return $freshOrder;
    }

    public function calculateCancellationPenalty(Order $order, ShoppingOrder $shoppingOrder): float
    {
        $rule = $this->getRuleConfig((int) $order->service_type_id, 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS');

        $threshold = $this->failedAttemptThresholdForRule($rule);
        $percent = max(0.0, (float) ($rule['penalty_percent_of_delivery_fee'] ?? 50));

        if ((int) $shoppingOrder->failed_attempt_count < $threshold || $percent <= 0) {
            return 0.0;
        }

        $snapshot = is_array($shoppingOrder->pricing_snapshot) ? $shoppingOrder->pricing_snapshot : [];
        $baseDeliveryFee = $snapshot['penalty_base_delivery_fee'] ?? null;
        $deliveryFee = is_numeric($baseDeliveryFee) ? (float) $baseDeliveryFee : (float) $order->delivery_fee;

        return round($deliveryFee * ($percent / 100), 2);
    }

    public function cancellationFailedAttemptThreshold(int $serviceTypeId): int
    {
        return $this->failedAttemptThresholdForRule(
            $this->getRuleConfig($serviceTypeId, 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS')
        );
    }

    public function isCancellationPenaltyEligible(Order $order, ShoppingOrder $shoppingOrder): bool
    {
        return (int) $shoppingOrder->failed_attempt_count >= $this->cancellationFailedAttemptThreshold(
            (int) $order->service_type_id
        );
    }

    public function hasPendingManualPrices(Order $order): bool
    {
        if ($this->hasDriverShoppingTotal($order)) {
            return false;
        }

        $order->loadMissing('items');

        return $order->items->contains(function (OrderItem $item): bool {
            return $item->item_source === 'MANUAL'
                && (bool) $item->is_available
                && (float) $item->unit_price <= 0;
        });
    }

    public function hasDriverShoppingTotal(Order $order): bool
    {
        $order->loadMissing('shoppingOrder');

        return $order->shoppingOrder instanceof ShoppingOrder
            && $this->driverShoppingTotalAmountFromSnapshot($order->shoppingOrder) !== null;
    }

    private function driverShoppingTotalAmountFromSnapshot(ShoppingOrder $shoppingOrder): ?float
    {
        $snapshot = is_array($shoppingOrder->pricing_snapshot) ? $shoppingOrder->pricing_snapshot : [];
        $amount = $snapshot['driver_shopping_total_amount'] ?? null;

        if (! is_numeric($amount) || (float) $amount <= 0) {
            return null;
        }

        return round((float) $amount, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRuleConfig(int $serviceTypeId, string $ruleCode): array
    {
        $rule = ServiceFeeRule::query()
            ->where('service_type_id', $serviceTypeId)
            ->where('rule_code', $ruleCode)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();

        return is_array($rule?->rule_config) ? $rule->rule_config : [];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function calculateItemSurcharge(int $itemCount, array $rule): float
    {
        $freeUntil = max(0, (int) ($rule['free_until_item_count'] ?? 0));
        $blockSize = max(1, (int) ($rule['block_size'] ?? 1));
        $surchargePerBlock = max(0, (float) ($rule['surcharge_per_block'] ?? 0));

        if ($itemCount <= $freeUntil || $surchargePerBlock <= 0) {
            return 0.0;
        }

        $billableItems = $itemCount - $freeUntil;
        $blockCount = (int) ceil($billableItems / $blockSize);

        return round($blockCount * $surchargePerBlock, 2);
    }

    /**
     * @param  array<string, mixed>  $itemBlockRule
     * @return array<int, array<string, mixed>>
     */
    private function feeBreakdown(
        int $itemCount,
        float $itemSurcharge,
        float $overweightSurcharge,
        float $cancellationPenalty,
        array $itemBlockRule,
    ): array {
        $rows = [];
        if ($itemSurcharge > 0) {
            $freeUntil = max(0, (int) ($itemBlockRule['free_until_item_count'] ?? 0));
            $blockSize = max(1, (int) ($itemBlockRule['block_size'] ?? 1));
            $billableItems = max(0, $itemCount - $freeUntil);
            $blockCount = max(1, (int) ceil($billableItems / $blockSize));
            $rows[] = [
                'code' => 'ITEM_BLOCK_SURCHARGE',
                'label' => 'Biaya banyak item',
                'description' => $itemCount.' item, '.$blockCount.' blok tambahan',
                'amount' => round($itemSurcharge, 2),
            ];
        }

        if ($overweightSurcharge > 0) {
            $rows[] = [
                'code' => 'OVERWEIGHT_FLAT_SURCHARGE',
                'label' => 'Item berat',
                'description' => 'Dikenakan sekali per order',
                'amount' => round($overweightSurcharge, 2),
            ];
        }

        if ($cancellationPenalty > 0) {
            $rows[] = [
                'code' => 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS',
                'label' => 'Penalty merchant gagal',
                'description' => '50% dari ongkir setelah batas percobaan gagal',
                'amount' => round($cancellationPenalty, 2),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    private function broadcastContentUpdatedAfterCommit(int $orderId, string $triggerType, array $pricing): void
    {
        $broadcast = fn (): bool => $this->realtimeBroadcaster->orderContentUpdated($orderId, $triggerType, $pricing);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($broadcast);
            return;
        }

        $broadcast();
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function failedAttemptThresholdForRule(array $rule): int
    {
        return max(1, (int) ($rule['failed_attempt_threshold'] ?? 3));
    }

    /**
     * @param  \App\Models\OrderItem|array<string, mixed>  $item
     */
    private function itemFloat(OrderItem|array $item, string $key, float $default): float
    {
        $value = $item instanceof OrderItem ? $item->{$key} : ($item[$key] ?? null);

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param  \App\Models\OrderItem|array<string, mixed>  $item
     */
    private function itemInt(OrderItem|array $item, string $key, int $default): int
    {
        $value = $item instanceof OrderItem ? $item->{$key} : ($item[$key] ?? null);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  \App\Models\OrderItem|array<string, mixed>  $item
     */
    private function itemBool(OrderItem|array $item, string $key, bool $default): bool
    {
        $value = $item instanceof OrderItem ? $item->{$key} : ($item[$key] ?? null);

        return $value === null ? $default : (bool) $value;
    }
}
