<?php

namespace App\Services\Pricing;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Services\Notification\OrderPricingPushNotificationService;
use App\Services\Notification\OrderRealtimeBroadcaster;
use App\Services\Order\DeliveryFeeNegotiationService;
use App\Services\Order\OrderPaymentService;
use App\Services\Shopping\ShoppingDeliveryFeeLockResolver;
use App\Services\Shopping\ShoppingFailedTripCompensationService;
use App\Services\Shopping\ShoppingPriceNegotiationService;
use Illuminate\Support\Facades\DB;

class ShoppingPricingService
{
    public const PRICING_SCOPE_SHOPPING_CANCELLATION_BASE_50_PERCENT = 'SHOPPING_CANCELLATION_BASE_50_PERCENT';

    public const MANUAL_CANCELLATION_TRIGGER = 'DRIVER_MANUAL_CANCELLATION_BASE';

    private const CANCELLATION_PENALTY = 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS';

    private const CANCELLATION_FAILED_ATTEMPT_THRESHOLD = 3;

    public const CANCELLATION_PENALTY_PERCENT = 50.0;

    public function __construct(
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderRealtimeBroadcaster $realtimeBroadcaster,
        private readonly OrderPricingPushNotificationService $pricingPushNotificationService,
        private readonly ShoppingDeliveryFeeLockResolver $deliveryFeeLockResolver,
        private readonly ShoppingFailedTripCompensationService $failedTripCompensationService,
    ) {}

    public function subtotalAmount(Order $order): float
    {
        $order->loadMissing(['serviceType', 'statusRef', 'items']);

        if (strtoupper((string) ($order->serviceType?->code ?? '')) !== 'SHOPPING') {
            return 0.0;
        }

        if (strtoupper((string) ($order->statusRef?->code ?? '')) === 'CANCELLED_WITH_FEE') {
            return 0.0;
        }

        $approvedSubtotal = $this->approvedShoppingSubtotalAmount($order);
        if ($approvedSubtotal !== null) {
            return $approvedSubtotal;
        }

        return round($order->items
            ->filter(fn (OrderItem $item): bool => (bool) $item->is_available)
            ->sum(fn (OrderItem $item): float => round((float) $item->subtotal, 2)), 2);
    }

    public function serviceFeeAmount(Order $order): float
    {
        $order->loadMissing(['serviceType', 'statusRef', 'items']);

        if (strtoupper((string) ($order->serviceType?->code ?? '')) !== 'SHOPPING') {
            return 0.0;
        }

        $failedTripCompensation = $this->chargeableFailedTripCompensationAmount($order);
        if (strtoupper((string) ($order->statusRef?->code ?? '')) !== 'CANCELLED_WITH_FEE') {
            return $failedTripCompensation;
        }

        $subtotal = $this->subtotalAmount($order);
        $deliveryFee = round((float) $order->delivery_fee, 2);
        $totalPrice = round((float) $order->total_price, 2);
        $snapshotFee = round(max(0.0, $totalPrice - $deliveryFee - $subtotal), 2);
        if ($deliveryFee > 0 && abs($totalPrice - $deliveryFee - $subtotal) <= 0.01) {
            return $snapshotFee;
        }

        $calculatedPenalty = $this->calculateCancellationPenalty($order);

        return max($failedTripCompensation, $calculatedPenalty, $snapshotFee);
    }

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
        float $failedTripCompensation = 0.0,
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
        $failedTripCompensation = round(max(0.0, $failedTripCompensation), 2);

        if ($subtotalOverride !== null && $subtotalOverride > 0) {
            $subtotal = round($subtotalOverride, 2);
        }

        if ($penaltyOnly && $cancellationPenalty > 0) {
            $subtotal = 0.0;
            $deliveryFee = 0.0;
        }

        $serviceFee = $penaltyOnly
            ? max($cancellationPenalty, $failedTripCompensation)
            : round($cancellationPenalty + $failedTripCompensation, 2);
        $totalPrice = round($subtotal + $deliveryFee + $serviceFee, 2);

        return [
            'item_count' => $totalItemQuantity,
            'subtotal' => $subtotal,
            'delivery_fee' => round($deliveryFee, 2),
            'item_surcharge' => 0.0,
            'overweight_surcharge' => 0.0,
            'cancellation_penalty' => round($cancellationPenalty, 2),
            'failed_trip_compensation' => $failedTripCompensation,
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
        bool $notifyTotalChanged = true,
    ): Order {
        $order->loadMissing(['items', 'serviceType', 'statusRef', 'shoppingReceipt']);

        $oldAmounts = $this->pricingAmounts($order);
        $deliveryFeeLock = $this->deliveryFeeLockResolver->resolve($order);
        $isDeliveryFeeLocked = (bool) $deliveryFeeLock['is_locked'] && is_numeric($deliveryFeeLock['amount']);
        $deliveryFee = $isDeliveryFeeLocked
            ? (float) $deliveryFeeLock['amount']
            : (float) $order->delivery_fee;
        $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));
        $penaltyBaseDeliveryFee = $statusCode === 'CANCELLED_WITH_FEE'
            ? $this->cancellationPenaltyBaseDeliveryFee($order)
            : null;
        $cancellationPenalty = $statusCode === 'CANCELLED_WITH_FEE'
            ? $this->calculateCancellationPenalty($order)
            : 0.0;
        $penaltyOnly = $cancellationPenalty > 0 && $statusCode === 'CANCELLED_WITH_FEE';
        $failedTripCompensation = $this->chargeableFailedTripCompensationAmount($order);
        $subtotalOverride = $penaltyOnly ? null : $this->approvedShoppingSubtotalAmount($order);

        $pricing = $this->calculateForItems(
            (int) $order->service_type_id,
            $order->items,
            $deliveryFee,
            $cancellationPenalty,
            $subtotalOverride,
            $penaltyOnly,
            $failedTripCompensation,
        );

        $nextVersion = $this->latestRecalculationVersion($order) + 1;

        $orderUpdates = [
            'service_fee' => $pricing['service_fee'],
            'total_price' => $pricing['total_price'],
        ];

        if ($penaltyOnly) {
            $orderUpdates['delivery_fee'] = $pricing['delivery_fee'];
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
                'failed_trip_compensation' => $pricing['failed_trip_compensation'],
                'trigger_type' => $triggerType,
                'recalculation_version' => $nextVersion,
                ...($penaltyBaseDeliveryFee !== null ? [
                    'penalty_base_delivery_fee' => round($penaltyBaseDeliveryFee, 2),
                ] : []),
            ],
        ]);

        $newAmounts = $this->pricingAmounts($freshForTotals);
        $this->recordPriceChange($event, $oldAmounts, $newAmounts);

        if ($writeHistory) {
            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => 'ITEM_UPDATE',
                'changed_by_user_id' => $changedByUserId,
                'note' => $historyNote ?: 'Perubahan item order SHOPPING.',
                'metadata' => [
                    'subtotal' => $pricing['subtotal'],
                    'service_fee' => $pricing['service_fee'],
                    'failed_trip_compensation' => $pricing['failed_trip_compensation'],
                    'total_price' => $pricing['total_price'],
                    'recalculation_version' => $nextVersion,
                    ...($penaltyBaseDeliveryFee !== null ? [
                        'penalty_base_delivery_fee' => round($penaltyBaseDeliveryFee, 2),
                    ] : []),
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
            'price_event_id' => (int) $event->id,
            'old_total_price' => round((float) ($oldAmounts['TOTAL_PRICE'] ?? 0), 2),
            'new_total_price' => round((float) ($newAmounts['TOTAL_PRICE'] ?? 0), 2),
            'recalculation_version' => $nextVersion,
            'delivery_fee_source' => $freshOrder->delivery_fee_source,
            'delivery_fee_change_note' => $freshOrder->delivery_fee_change_note,
        ]);

        if ($notifyTotalChanged && $event->exists) {
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
        $manualPricing = $this->manualCancellationPricing($order);
        if ($manualPricing !== null) {
            return round((float) $manualPricing['cancellation_penalty'], 2);
        }

        $threshold = self::CANCELLATION_FAILED_ATTEMPT_THRESHOLD;
        $percent = self::CANCELLATION_PENALTY_PERCENT;

        // Penalti selalu memakai basis ongkir terdaftar (Bp). Kompensasi
        // perjalanan gagal tidak lagi mendahului di sini; keduanya dibandingkan
        // lewat max(P, C) pada mode penaltyOnly (Persamaan 5), sehingga
        // pembatalan menagih separuh ongkir yang disepakati -- atau separuh
        // biaya rute gagal bila rute itu ternyata lebih panjang.
        if ($this->failedAttemptCount($order) < $threshold || $percent <= 0) {
            return 0.0;
        }

        $deliveryFee = $this->cancellationPenaltyBaseDeliveryFee($order);

        return round($deliveryFee * ($percent / 100), 2);
    }

    public function calculateCancellationPenaltyFromBase(float $baseDeliveryFee): float
    {
        return round(max(0.0, $baseDeliveryFee) * (self::CANCELLATION_PENALTY_PERCENT / 100), 2);
    }

    public function cancellationPenaltyPercent(): float
    {
        return self::CANCELLATION_PENALTY_PERCENT;
    }

    public function cancellationPenaltyBaseAmount(Order $order): float
    {
        return $this->cancellationPenaltyBaseDeliveryFee($order);
    }

    public function cancellationDriverFeeAmount(Order $order): float
    {
        return $this->storedCancellationPenaltyAmount($order)
            ?? $this->calculateCancellationPenalty($order);
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
        if (strtoupper($code) === 'FAILED_TRIP_COMPENSATION') {
            return $this->chargeableFailedTripCompensationAmount($order);
        }

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
        $lines = [];
        $chargeableFailedTripCompensation = $this->chargeableFailedTripCompensationAmount($order);
        $failedTripLine = $chargeableFailedTripCompensation > 0
            ? $this->failedTripCompensationService->feeLine($order)
            : null;
        if ($failedTripLine !== null) {
            $failedTripLine['amount'] = $chargeableFailedTripCompensation;
            $lines[] = $failedTripLine;
        }

        $penalty = $this->cancellationPenaltyAmount($order);
        if ($penalty <= 0 || $failedTripLine !== null) {
            return $lines;
        }

        $lines[] = [
            'code' => self::CANCELLATION_PENALTY,
            'label' => $this->feeLineLabel(self::CANCELLATION_PENALTY),
            'description' => $this->feeLineDescription(self::CANCELLATION_PENALTY),
            'amount' => $penalty,
        ];

        return $lines;
    }

    public function chargeableFailedTripCompensationAmount(Order $order): float
    {
        if ($this->manualCancellationPricing($order) !== null) {
            return 0.0;
        }

        $lock = $this->deliveryFeeLockResolver->resolve($order);
        if (($lock['pricing_scope'] ?? null) === DeliveryFeeNegotiationService::PRICING_SCOPE_SHOPPING_TOTAL_TRANSPORT) {
            return 0.0;
        }

        return $this->failedTripCompensationService->amount($order);
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
            'SUBTOTAL' => $this->subtotalAmount($order),
            'DELIVERY_FEE' => round((float) $order->delivery_fee, 2),
            'SERVICE_FEE' => $this->serviceFeeAmount($order),
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
            ? ($this->storedCancellationPenaltyAmount($order) ?? $this->calculateCancellationPenalty($order))
            : 0.0;
    }

    private function storedCancellationPenaltyAmount(Order $order): ?float
    {
        $order->loadMissing(['serviceType', 'statusRef']);
        $serviceCode = strtoupper((string) ($order->serviceType?->code ?? ''));
        $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));
        if ($serviceCode !== 'SHOPPING' || $statusCode !== 'CANCELLED_WITH_FEE') {
            return null;
        }

        $subtotal = $this->subtotalAmount($order);
        $deliveryFee = round((float) $order->delivery_fee, 2);
        $totalPrice = round((float) $order->total_price, 2);

        return $deliveryFee > 0 && abs($totalPrice - $deliveryFee - $subtotal) <= 0.01
            ? $deliveryFee
            : null;
    }

    private function cancellationPenaltyBaseDeliveryFee(Order $order): float
    {
        $manualPricing = $this->manualCancellationPricing($order);
        if ($manualPricing !== null) {
            return round((float) $manualPricing['base_delivery_fee'], 2);
        }

        $deliveryFeeLock = $this->deliveryFeeLockResolver->resolve($order);
        if ((bool) ($deliveryFeeLock['is_locked'] ?? false)
            && is_numeric($deliveryFeeLock['amount'] ?? null)
            && (float) $deliveryFeeLock['amount'] > 0
        ) {
            return round((float) $deliveryFeeLock['amount'], 2);
        }

        $recordedBase = OrderLog::query()
            ->where('order_id', $order->id)
            ->latest('id')
            ->get(['metadata'])
            ->map(fn (OrderLog $event): mixed => data_get($event->metadata ?? [], 'penalty_base_delivery_fee'))
            ->first(fn (mixed $amount): bool => is_numeric($amount) && (float) $amount > 0);

        if (is_numeric($recordedBase)) {
            return round((float) $recordedBase, 2);
        }

        return round(max(0.0, (float) $order->delivery_fee), 2);
    }

    /**
     * @return array{base_delivery_fee: float, cancellation_penalty: float}|null
     */
    private function manualCancellationPricing(Order $order): ?array
    {
        $order->loadMissing('statusRef');
        if (strtoupper((string) ($order->statusRef?->code ?? '')) !== 'CANCELLED_WITH_FEE') {
            return null;
        }

        $event = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('trigger_type', self::MANUAL_CANCELLATION_TRIGGER)
            ->latest('id')
            ->first();
        $metadata = $event instanceof OrderLog && is_array($event->metadata)
            ? $event->metadata
            : [];
        if (($metadata['pricing_scope'] ?? null) !== self::PRICING_SCOPE_SHOPPING_CANCELLATION_BASE_50_PERCENT) {
            return null;
        }

        $baseDeliveryFee = $metadata['cancellation_penalty_base_delivery_fee'] ?? null;
        $cancellationPenalty = $metadata['cancellation_penalty'] ?? null;
        if (! is_numeric($baseDeliveryFee) || (float) $baseDeliveryFee <= 0) {
            return null;
        }

        $normalizedBase = round((float) $baseDeliveryFee, 2);
        $normalizedPenalty = is_numeric($cancellationPenalty) && (float) $cancellationPenalty > 0
            ? round((float) $cancellationPenalty, 2)
            : $this->calculateCancellationPenaltyFromBase($normalizedBase);

        return [
            'base_delivery_fee' => $normalizedBase,
            'cancellation_penalty' => $normalizedPenalty,
        ];
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
