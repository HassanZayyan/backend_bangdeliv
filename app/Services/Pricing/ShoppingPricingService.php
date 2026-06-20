<?php

namespace App\Services\Pricing;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Models\OrderStatusHistory;
use App\Services\Notification\OrderPricingPushNotificationService;
use App\Services\Notification\OrderRealtimeBroadcaster;
use App\Services\Order\OrderPaymentService;
use App\Services\Shopping\ShoppingDeliveryFeeLockResolver;
use App\Services\Shopping\ShoppingPriceNegotiationService;
use Illuminate\Support\Facades\DB;

class ShoppingPricingService
{
    private const CANCELLATION_PENALTY = 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS';

    private const CANCELLATION_FAILED_ATTEMPT_THRESHOLD = 3;

    private const CANCELLATION_PENALTY_PERCENT = 50.0;

    public function __construct(
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderRealtimeBroadcaster $realtimeBroadcaster,
        private readonly OrderPricingPushNotificationService $pricingPushNotificationService,
        private readonly ShoppingDeliveryFeeLockResolver $deliveryFeeLockResolver,
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
        bool $preserveDeliveryFeeWhenPenaltyOnly = false,
    ): array {
        $subtotal = 0.0;
        $totalItemQuantity = 0;

        foreach ($items as $item) {
            $isAvailable = $this->itemBool($item, 'is_available', true);
            if (! $isAvailable) {
                continue;
            }

            $quantity = max(1, $this->itemInt($item, 'quantity', 1));
            $unitPrice = max(0.0, $this->itemFloat($item, 'unit_price', 0.0));

            $subtotal += round($unitPrice * $quantity, 2);
            $totalItemQuantity += $quantity;
        }

        $subtotal = round($subtotal, 2);
        $cancellationPenalty = round(max(0.0, $cancellationPenalty), 2);

        if ($subtotalOverride !== null && $subtotalOverride > 0) {
            $subtotal = round($subtotalOverride, 2);
        }

        if ($penaltyOnly && $cancellationPenalty > 0) {
            $subtotal = 0.0;
            if (! $preserveDeliveryFeeWhenPenaltyOnly) {
                $deliveryFee = 0.0;
            }
        }

        $serviceFee = $cancellationPenalty;
        $totalPrice = round($subtotal + $deliveryFee + $serviceFee, 2);

        return [
            'item_count' => $totalItemQuantity,
            'subtotal' => $subtotal,
            'delivery_fee' => round($deliveryFee, 2),
            'item_surcharge' => 0.0,
            'overweight_surcharge' => 0.0,
            'cancellation_penalty' => round($cancellationPenalty, 2),
            'service_fee' => $serviceFee,
            'total_price' => $totalPrice,
            'has_overweight_item' => false,
            'fee_breakdown' => [],
        ];
    }

    public function recalculate(
        Order $order,
        int $changedByUserId,
        string $triggerType,
        bool $writeHistory = true,
        ?string $historyNote = null,
    ): Order {
        $order->loadMissing(['items', 'serviceType', 'statusRef', 'shoppingReceipt']);

        $oldAmounts = $this->pricingAmounts($order);
        $deliveryFeeLock = $this->deliveryFeeLockResolver->resolve($order);
        $isDeliveryFeeLocked = (bool) $deliveryFeeLock['is_locked'] && is_numeric($deliveryFeeLock['amount']);
        $deliveryFee = $isDeliveryFeeLocked
            ? (float) $deliveryFeeLock['amount']
            : (float) $order->delivery_fee;
        $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));
        $cancellationPenalty = $statusCode === 'CANCELLED_WITH_FEE'
            ? round((float) $order->service_fee, 2)
            : 0.0;
        $penaltyOnly = $cancellationPenalty > 0 && $statusCode === 'CANCELLED_WITH_FEE';
        $subtotalOverride = $penaltyOnly ? null : $this->approvedShoppingSubtotalAmount($order);

        $pricing = $this->calculateForItems(
            (int) $order->service_type_id,
            $order->items,
            $deliveryFee,
            $cancellationPenalty,
            $subtotalOverride,
            $penaltyOnly,
            $isDeliveryFeeLocked,
        );

        $nextVersion = $this->latestRecalculationVersion($order) + 1;

        $orderUpdates = [
            'subtotal' => $pricing['subtotal'],
            'service_fee' => $pricing['service_fee'],
            'total_price' => $pricing['total_price'],
        ];

        if ($penaltyOnly) {
            $orderUpdates['delivery_fee'] = $isDeliveryFeeLocked ? $pricing['delivery_fee'] : 0;
        } elseif ($isDeliveryFeeLocked) {
            $orderUpdates['delivery_fee'] = $pricing['delivery_fee'];
        }

        $order->update($orderUpdates);

        $freshForTotals = $order->refresh();
        $this->orderPaymentService->syncPendingCodAmount($freshForTotals);

        $event = OrderLog::query()->create([
            'order_id' => $order->id,
            'log_type' => 'PRICE_RECALCULATION',
            'trigger_type' => $triggerType,
            'changed_by_user_id' => $changedByUserId,
            'note' => $historyNote ?: 'Rekalkulasi harga order SHOPPING setelah perubahan item.',
            'metadata' => [
                'item_count' => $pricing['item_count'],
                'has_overweight_item' => $pricing['has_overweight_item'],
                'trigger_type' => $triggerType,
                'recalculation_version' => $nextVersion,
            ],
        ]);

        $newAmounts = $this->pricingAmounts($freshForTotals);
        $this->recordPriceChange($event, $oldAmounts, $newAmounts);

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
            'serviceType',
            'shoppingReceipt',
        ]);

        $this->broadcastContentUpdatedAfterCommit((int) $freshOrder->id, $triggerType, [
            ...$pricing,
            'recalculation_version' => $nextVersion,
            'delivery_fee_source' => $freshOrder->delivery_fee_source,
            'delivery_fee_change_note' => $freshOrder->delivery_fee_change_note,
        ]);

        if ($event->exists) {
            $this->notifyTotalChangedAfterCommit(
                $freshOrder,
                $changedByUserId,
                $triggerType,
                (float) ($oldAmounts['TOTAL_PRICE'] ?? 0),
                (float) ($newAmounts['TOTAL_PRICE'] ?? 0),
                (int) $event->id,
            );
        }

        return $freshOrder;
    }

    public function calculateCancellationPenalty(Order $order): float
    {
        $threshold = self::CANCELLATION_FAILED_ATTEMPT_THRESHOLD;
        $percent = self::CANCELLATION_PENALTY_PERCENT;

        if ($this->failedAttemptCount($order) < $threshold || $percent <= 0) {
            return 0.0;
        }

        $deliveryFeeLock = $this->deliveryFeeLockResolver->resolve($order);
        $deliveryFee = (bool) $deliveryFeeLock['is_locked'] && is_numeric($deliveryFeeLock['amount'])
            ? (float) $deliveryFeeLock['amount']
            : (float) $order->delivery_fee;

        return round($deliveryFee * ($percent / 100), 2);
    }

    public function cancellationFailedAttemptThreshold(int $serviceTypeId): int
    {
        return self::CANCELLATION_FAILED_ATTEMPT_THRESHOLD;
    }

    public function isCancellationPenaltyEligible(Order $order): bool
    {
        return $this->failedAttemptCount($order) >= $this->cancellationFailedAttemptThreshold(
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
        return $this->approvedShoppingSubtotalAmount($order) !== null;
    }

    public function hasShoppingReceipt(Order $order): bool
    {
        $order->loadMissing('shoppingReceipt');

        return $order->shoppingReceipt !== null;
    }

    public function driverShoppingTotalAmount(Order $order): ?float
    {
        return $this->approvedShoppingSubtotalAmount($order);
    }

    public function approvedShoppingSubtotalAmount(Order $order): ?float
    {
        $amount = app(ShoppingPriceNegotiationService::class)->approvedSubtotal($order);

        if (! is_numeric($amount) || (float) $amount <= 0) {
            return null;
        }

        return round((float) $amount, 2);
    }

    public function failedAttemptCount(Order $order): int
    {
        $order->loadMissing('orderLocations');

        return (int) $order->orderLocations
            ->filter(fn ($location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->sum(fn ($location): int => (int) ($location->failed_attempt_count ?? 0));
    }

    public function latestRecalculationVersion(Order $order): int
    {
        return (int) OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'PRICE_RECALCULATION')
            ->get(['metadata'])
            ->map(fn (OrderLog $event): int => (int) data_get($event->metadata ?? [], 'recalculation_version', 0))
            ->max();
    }

    public function feeLineAmount(Order $order, string $code): float
    {
        if (strtoupper($code) !== self::CANCELLATION_PENALTY) {
            return 0.0;
        }

        return $this->cancellationPenaltyAmount($order);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function feeBreakdownForOrder(Order $order): array
    {
        $penalty = $this->cancellationPenaltyAmount($order);
        if ($penalty <= 0) {
            return [];
        }

        return [[
            'code' => self::CANCELLATION_PENALTY,
            'label' => $this->feeLineLabel(self::CANCELLATION_PENALTY),
            'description' => $this->feeLineDescription(self::CANCELLATION_PENALTY),
            'amount' => $penalty,
        ]];
    }

    /**
     * @param  array<string, float|int|string|null>  $oldAmounts
     * @param  array<string, float|int|string|null>  $newAmounts
     */
    public function recordPriceChange(OrderLog $event, array $oldAmounts, array $newAmounts): void
    {
        $changes = [];

        foreach (['SUBTOTAL', 'DELIVERY_FEE', 'SERVICE_FEE', 'TOTAL_PRICE'] as $component) {
            $oldAmount = round((float) ($oldAmounts[$component] ?? 0), 2);
            $newAmount = round((float) ($newAmounts[$component] ?? 0), 2);
            $deltaAmount = round($newAmount - $oldAmount, 2);

            if ($deltaAmount == 0.0) {
                continue;
            }

            $changes[$component] = [
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'delta_amount' => $deltaAmount,
            ];
        }

        if ($changes === []) {
            $event->delete();

            return;
        }

        $metadata = is_array($event->metadata) ? $event->metadata : [];
        $metadata['price_changes'] = $changes;
        $event->update(['metadata' => $metadata]);
    }

    /**
     * @return array<string, float>
     */
    public function pricingAmounts(Order $order): array
    {
        return [
            'SUBTOTAL' => round((float) $order->subtotal, 2),
            'DELIVERY_FEE' => round((float) $order->delivery_fee, 2),
            'SERVICE_FEE' => round((float) $order->service_fee, 2),
            'TOTAL_PRICE' => round((float) $order->total_price, 2),
        ];
    }

    private function feeLineLabel(string $code): string
    {
        return match (strtoupper($code)) {
            self::CANCELLATION_PENALTY => 'Penalty merchant gagal',
            default => 'Biaya layanan',
        };
    }

    private function feeLineDescription(string $code): string
    {
        return match (strtoupper($code)) {
            self::CANCELLATION_PENALTY => '50% ongkir setelah batas percobaan gagal',
            default => '',
        };
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

    private function notifyTotalChangedAfterCommit(
        Order $order,
        int $changedByUserId,
        string $triggerType,
        float $oldTotalPrice,
        float $newTotalPrice,
        int $priceEventId,
    ): void {
        $notify = fn (): bool => $this->pricingPushNotificationService->sendOrderTotalChanged(
            $order,
            $changedByUserId,
            $triggerType,
            $oldTotalPrice,
            $newTotalPrice,
            $priceEventId,
        );

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($notify);

            return;
        }

        $notify();
    }

    private function cancellationPenaltyAmount(Order $order): float
    {
        $order->loadMissing('statusRef');
        $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));

        return $statusCode === 'CANCELLED_WITH_FEE'
            ? round((float) $order->service_fee, 2)
            : 0.0;
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
