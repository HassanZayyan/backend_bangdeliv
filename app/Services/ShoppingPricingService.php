<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderFeeLine;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Models\OrderStatusHistory;
use App\Models\ServiceFeeRule;
use Illuminate\Support\Facades\DB;

class ShoppingPricingService
{
    private const ITEM_SURCHARGE = 'ITEM_BLOCK_SURCHARGE';

    private const OVERWEIGHT_SURCHARGE = 'OVERWEIGHT_FLAT_SURCHARGE';

    private const CANCELLATION_PENALTY = 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS';

    /**
     * @var array<int, string>
     */
    private array $shoppingFeeCodes = [
        self::ITEM_SURCHARGE,
        self::OVERWEIGHT_SURCHARGE,
        self::CANCELLATION_PENALTY,
    ];

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
        $itemBlockRule = $this->getRuleConfig($serviceTypeId, self::ITEM_SURCHARGE);
        $overweightRule = $this->getRuleConfig($serviceTypeId, self::OVERWEIGHT_SURCHARGE);

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
        $order->loadMissing(['items', 'serviceType', 'statusRef', 'feeLines', 'shoppingReceipt']);

        $oldAmounts = $this->pricingAmounts($order);
        $deliveryFee = (float) $order->delivery_fee;
        $cancellationPenalty = $this->feeLineAmount($order, self::CANCELLATION_PENALTY);
        $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));
        $penaltyOnly = $cancellationPenalty > 0 && $statusCode === 'CANCELLED_WITH_FEE';
        $subtotalOverride = $penaltyOnly ? null : $this->driverShoppingTotalAmount($order);

        $pricing = $this->calculateForItems(
            (int) $order->service_type_id,
            $order->items,
            $deliveryFee,
            $cancellationPenalty,
            $subtotalOverride,
            $penaltyOnly,
        );

        $nextVersion = $this->latestRecalculationVersion($order) + 1;

        $this->syncFeeLines($order, $pricing['fee_breakdown']);

        $orderUpdates = [
            'subtotal' => $pricing['subtotal'],
            'total_price' => $pricing['total_price'],
        ];

        if ($penaltyOnly) {
            $orderUpdates['delivery_fee'] = 0;
        }

        $order->update($orderUpdates);

        $freshForTotals = $order->refresh()->load(['feeLines']);
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

        $this->recordPriceChange($event, $oldAmounts, $this->pricingAmounts($freshForTotals));

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
            'feeLines',

            'shoppingReceipt',
        ]);

        $this->broadcastContentUpdatedAfterCommit((int) $freshOrder->id, $triggerType, [
            ...$pricing,
            'recalculation_version' => $nextVersion,
            'delivery_fee_source' => $freshOrder->delivery_fee_source,
            'delivery_fee_change_note' => $freshOrder->delivery_fee_change_note,
            'careful_carry_required' => false,
        ]);

        return $freshOrder;
    }

    /**
     * @param  array<int, array<string, mixed>>  $feeBreakdown
     */
    public function syncFeeLines(Order $order, array $feeBreakdown): void
    {
        $activeCodes = [];

        foreach ($feeBreakdown as $line) {
            if (! is_array($line)) {
                continue;
            }

            $code = strtoupper((string) ($line['code'] ?? ''));
            $amount = round((float) ($line['amount'] ?? 0), 2);
            if (! in_array($code, $this->shoppingFeeCodes, true) || $amount <= 0) {
                continue;
            }

            $activeCodes[] = $code;

            OrderFeeLine::query()->updateOrCreate(
                [
                    'order_id' => $order->id,
                    'code' => $code,
                ],
                [
                    'label' => (string) ($line['label'] ?? $this->feeLineLabel($code)),
                    'amount' => $amount,
                ]
            );
        }

        OrderFeeLine::query()
            ->where('order_id', $order->id)
            ->whereIn('code', $this->shoppingFeeCodes)
            ->when($activeCodes !== [], fn ($query) => $query->whereNotIn('code', $activeCodes))
            ->delete();

        $order->unsetRelation('feeLines');
    }

    public function calculateCancellationPenalty(Order $order): float
    {
        $rule = $this->getRuleConfig((int) $order->service_type_id, self::CANCELLATION_PENALTY);

        $threshold = $this->failedAttemptThresholdForRule($rule);
        $percent = max(0.0, (float) ($rule['penalty_percent_of_delivery_fee'] ?? 50));

        if ($this->failedAttemptCount($order) < $threshold || $percent <= 0) {
            return 0.0;
        }

        return round((float) $order->delivery_fee * ($percent / 100), 2);
    }

    public function cancellationFailedAttemptThreshold(int $serviceTypeId): int
    {
        return $this->failedAttemptThresholdForRule(
            $this->getRuleConfig($serviceTypeId, self::CANCELLATION_PENALTY)
        );
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
        return $this->driverShoppingTotalAmount($order) !== null;
    }

    public function driverShoppingTotalAmount(Order $order): ?float
    {
        $order->loadMissing('shoppingReceipt');
        $amount = $order->shoppingReceipt?->total_amount;

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
        $order->loadMissing('feeLines');
        $line = $order->feeLines->first(
            fn (OrderFeeLine $line): bool => strtoupper((string) $line->code) === strtoupper($code)
        );

        return round((float) ($line?->amount ?? 0), 2);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function feeBreakdownForOrder(Order $order): array
    {
        $order->loadMissing('feeLines');

        return $order->feeLines
            ->filter(fn (OrderFeeLine $line): bool => (float) $line->amount > 0)
            ->map(fn (OrderFeeLine $line): array => [
                'code' => (string) $line->code,
                'label' => (string) $line->label,
                'description' => $this->feeLineDescription((string) $line->code),
                'amount' => round((float) $line->amount, 2),
            ])
            ->values()
            ->all();
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
                'code' => self::ITEM_SURCHARGE,
                'label' => $this->feeLineLabel(self::ITEM_SURCHARGE),
                'description' => $itemCount.' item, '.$blockCount.' blok tambahan',
                'amount' => round($itemSurcharge, 2),
            ];
        }

        if ($overweightSurcharge > 0) {
            $rows[] = [
                'code' => self::OVERWEIGHT_SURCHARGE,
                'label' => $this->feeLineLabel(self::OVERWEIGHT_SURCHARGE),
                'description' => $this->feeLineDescription(self::OVERWEIGHT_SURCHARGE),
                'amount' => round($overweightSurcharge, 2),
            ];
        }

        if ($cancellationPenalty > 0) {
            $rows[] = [
                'code' => self::CANCELLATION_PENALTY,
                'label' => $this->feeLineLabel(self::CANCELLATION_PENALTY),
                'description' => $this->feeLineDescription(self::CANCELLATION_PENALTY),
                'amount' => round($cancellationPenalty, 2),
            ];
        }

        return $rows;
    }

    private function feeLineLabel(string $code): string
    {
        return match (strtoupper($code)) {
            self::ITEM_SURCHARGE => 'Biaya banyak item',
            self::OVERWEIGHT_SURCHARGE => 'Item berat',
            self::CANCELLATION_PENALTY => 'Penalty merchant gagal',
            default => 'Biaya layanan',
        };
    }

    private function feeLineDescription(string $code): string
    {
        return match (strtoupper($code)) {
            self::ITEM_SURCHARGE => 'Tambahan saat jumlah item melewati batas gratis',
            self::OVERWEIGHT_SURCHARGE => 'Dikenakan sekali per order',
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
