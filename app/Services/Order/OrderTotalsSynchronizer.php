<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderLog;
use App\Services\Pricing\ShoppingPricingService;

final class OrderTotalsSynchronizer
{
    public function __construct(
        private readonly ShoppingPricingService $shoppingPricingService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderRealtimeNotifier $orderRealtimeNotifier
    ) {}

    public function refreshTotalsAfterDeliveryFeeChange(
        Order $order,
        int $actorId,
        string $triggerType,
        string $note,
        float $oldDeliveryFee,
        float $oldTotalPrice,
        ?string $reason = null,
        ?string $changedByRole = null,
    ): Order {
        $order->loadMissing(['serviceType', 'items', 'shoppingReceipt', 'courierOrder']);
        $newProjectedTotal = round($oldTotalPrice + ((float) $order->delivery_fee - $oldDeliveryFee), 2);
        $eventMetadata = [
            'old_delivery_fee' => round($oldDeliveryFee, 2),
            'new_delivery_fee' => round((float) $order->delivery_fee, 2),
            'old_total_price' => round($oldTotalPrice, 2),
            'new_total_price' => $newProjectedTotal,
            'delivery_fee_source' => $order->delivery_fee_source ?: 'system',
        ];

        if ($reason !== null && trim($reason) !== '') {
            $eventMetadata['reason'] = trim($reason);
        }

        if ($changedByRole !== null && trim($changedByRole) !== '') {
            $eventMetadata['changed_by_role'] = trim($changedByRole);
        }

        $event = OrderLog::query()->create([
            'order_id' => $order->id,
            'log_type' => 'PRICE_RECALCULATION',
            'trigger_type' => $triggerType,
            'changed_by_user_id' => $actorId,
            'note' => $note,
            'metadata' => $eventMetadata,
        ]);

        $this->shoppingPricingService->recordPriceChange($event, [
            'SUBTOTAL' => $this->shoppingPricingService->subtotalAmount($order),
            'DELIVERY_FEE' => round($oldDeliveryFee, 2),
            'SERVICE_FEE' => $this->shoppingPricingService->serviceFeeAmount($order),
            'TOTAL_PRICE' => round($oldTotalPrice, 2),
        ], [
            'SUBTOTAL' => $this->shoppingPricingService->subtotalAmount($order),
            'DELIVERY_FEE' => round((float) $order->delivery_fee, 2),
            'SERVICE_FEE' => $this->shoppingPricingService->serviceFeeAmount($order),
            'TOTAL_PRICE' => $newProjectedTotal,
        ]);

        if (($order->serviceType->code ?? null) === 'SHOPPING') {
            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                $actorId,
                $triggerType,
                true,
                $note
            );
        }

        $nextTotalPrice = round((float) $order->delivery_fee, 2);
        $order->update([
            'total_price' => $nextTotalPrice,
        ]);

        $this->syncPendingPaymentAmount($order->refresh());

        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => 'PRICE_UPDATE',
            'changed_by_user_id' => $actorId,
            'note' => $note,
            'metadata' => [
                'delivery_fee' => round((float) $order->delivery_fee, 2),
                'delivery_fee_source' => $order->delivery_fee_source ?: 'system',
                'total_price' => $nextTotalPrice,
                'reason' => $reason,
            ],
        ]);

        $this->orderRealtimeNotifier->broadcastContentUpdatedAfterCommit((int) $order->id, $triggerType, [
            'price_event_id' => (int) $event->id,
            'delivery_fee' => round((float) $order->delivery_fee, 2),
            'delivery_fee_source' => $order->delivery_fee_source ?: 'system',
            'delivery_fee_change_note' => $eventMetadata['reason'] ?? null,
            'old_total_price' => round($oldTotalPrice, 2),
            'new_total_price' => $nextTotalPrice,
            'total_price' => $nextTotalPrice,
        ]);

        return $order->refresh();
    }

    public function syncPendingPaymentAmount(Order $order): void
    {
        $this->orderPaymentService->syncPendingAmount($order);
    }
}
