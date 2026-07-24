<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Notification\OrderPricingPushNotificationService;
use App\Services\Notification\OrderRealtimeBroadcaster;
use App\Services\Notification\OrderStatusPushNotificationService;
use App\Services\Notification\PaymentProofPushNotificationService;
use App\Services\Shopping\ShoppingPriceNegotiationService;
use Illuminate\Support\Facades\DB;

final class OrderRealtimeNotifier
{
    public function __construct(
        private readonly OrderRealtimeBroadcaster $realtimeBroadcaster,
        private readonly OrderStatusPushNotificationService $orderStatusPushNotificationService,
        private readonly OrderPricingPushNotificationService $orderPricingPushNotificationService,
        private readonly ShoppingPriceNegotiationService $shoppingPriceNegotiationService,
        private readonly DeliveryFeeNegotiationService $deliveryFeeNegotiationService,
        private readonly PaymentProofPushNotificationService $paymentProofPushNotificationService,
    ) {}

    public function buildOrderStatusBroadcastPayload(
        int $orderId,
        string $statusCode,
        ?string $previousStatusCode,
        OrderStatusHistory $history,
    ): array {
        $history->loadMissing('statusRef');

        $changedAt = $history->created_at ?? now();
        $status = $history->statusRef;

        return [
            'order_id' => $orderId,
            'status_code' => strtoupper($statusCode),
            'previous_status_code' => $previousStatusCode !== null ? strtoupper($previousStatusCode) : null,
            'history_id' => (int) $history->id,
            'changed_at' => $changedAt->toIso8601String(),
            'changed_at_ms' => ((int) $changedAt->getTimestamp()) * 1000,
            'status_label' => $status?->display_name,
            'is_terminal' => $status?->is_terminal !== null ? (bool) $status->is_terminal : null,
        ];
    }

    /**
     * @param  array<string, bool|int|string|null>|null  $payload
     */
    public function broadcastOrderStatusChanged(?array $payload): void
    {
        if ($payload === null) {
            return;
        }

        $this->realtimeBroadcaster->orderStatusChanged(
            (int) $payload['order_id'],
            (string) $payload['status_code'],
            isset($payload['previous_status_code']) ? (string) $payload['previous_status_code'] : null,
            isset($payload['history_id']) ? (int) $payload['history_id'] : null,
            (string) $payload['changed_at'],
            isset($payload['changed_at_ms']) ? (int) $payload['changed_at_ms'] : null,
            isset($payload['status_label']) ? (string) $payload['status_label'] : null,
            isset($payload['is_terminal']) ? (bool) $payload['is_terminal'] : null,
        );
    }

    /**
     * @param  array<string, bool|int|string|null>|null  $payload
     */
    public function sendOrderStatusPushNotification(Order $order, ?array $payload): void
    {
        if ($payload === null) {
            return;
        }

        $this->orderStatusPushNotificationService->sendOrderStatusNotification($order, $payload);
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    public function broadcastContentUpdatedAfterCommit(int $orderId, string $triggerType, array $pricing): void
    {
        $broadcast = fn (): bool => $this->realtimeBroadcaster->orderContentUpdated($orderId, $triggerType, $pricing);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($broadcast);

            return;
        }

        $broadcast();
    }

    public function broadcastShoppingNegotiationUpdated(int $orderId): bool
    {
        return $this->realtimeBroadcaster->orderContentUpdated($orderId, 'SHOPPING_NEGOTIATION_UPDATED');
    }

    public function broadcastDeliveryFeeNegotiationUpdated(int $orderId): bool
    {
        return $this->realtimeBroadcaster->orderContentUpdated($orderId, 'DELIVERY_FEE_NEGOTIATION_UPDATED');
    }

    public function notifyShoppingPriceChanged(
        Order $order,
        User $actor,
        string $recipientRole,
        bool $requiresResponse,
    ): void {
        $freshOrder = $order->fresh(['user', 'driver.user', 'serviceType', 'statusRef']);
        if (! $freshOrder instanceof Order) {
            return;
        }

        $snapshot = $this->shoppingPriceNegotiationService->snapshot($freshOrder);
        if (! is_array($snapshot)) {
            return;
        }

        $this->orderPricingPushNotificationService->sendPriceChanged(
            order: $freshOrder,
            recipientRole: $recipientRole,
            changeType: (string) ($snapshot['trigger_type'] ?? 'SHOPPING_PRICE_UPDATED'),
            amount: $this->negotiationDisplayAmount($snapshot),
            requiresResponse: $requiresResponse,
            actor: $actor,
            priceEventId: $this->negotiationEventId($snapshot),
            pickupLocationId: $this->negotiationPickupLocationId($snapshot),
        );
    }

    public function notifyShoppingBypassTotalChanged(Order $order, User $actor): void
    {
        $freshOrder = $order->fresh(['user', 'driver.user', 'serviceType', 'statusRef']);
        if (! $freshOrder instanceof Order) {
            return;
        }

        $snapshot = $this->shoppingPriceNegotiationService->latest($freshOrder);

        $this->orderPricingPushNotificationService->sendPriceChanged(
            order: $freshOrder,
            recipientRole: 'customer',
            changeType: 'SHOPPING_TOTAL_UPDATED_BY_DRIVER_BYPASS',
            amount: round((float) $freshOrder->total_price, 2),
            requiresResponse: false,
            actor: $actor,
            priceEventId: $snapshot?->id,
        );
    }

    public function notifyDeliveryFeeChanged(
        Order $order,
        User $actor,
        string $recipientRole,
        bool $requiresResponse,
    ): void {
        $freshOrder = $order->fresh(['user', 'driver.user', 'serviceType', 'statusRef']);
        if (! $freshOrder instanceof Order) {
            return;
        }

        $snapshot = $this->deliveryFeeNegotiationService->snapshot($freshOrder);
        if (! is_array($snapshot)) {
            return;
        }

        $this->orderPricingPushNotificationService->sendPriceChanged(
            order: $freshOrder,
            recipientRole: $recipientRole,
            changeType: (string) ($snapshot['trigger_type'] ?? 'DELIVERY_FEE_UPDATED'),
            amount: $this->negotiationDisplayAmount($snapshot),
            requiresResponse: $requiresResponse,
            actor: $actor,
            priceEventId: $this->negotiationEventId($snapshot),
        );
    }

    /** Customer mengunggah bukti QRIS -> beri tahu driver untuk memverifikasi. */
    public function notifyPaymentProofUploaded(Order $order, User $actor): void
    {
        $freshOrder = $order->fresh(['user', 'driver.user']);
        if ($freshOrder instanceof Order) {
            $this->paymentProofPushNotificationService->notifyProofUploadedToDriver($freshOrder, $actor);
        }
    }

    /** Driver menolak bukti QRIS -> beri tahu customer untuk mengirim ulang. */
    public function notifyPaymentProofRejected(Order $order, User $actor, ?string $reason = null): void
    {
        $freshOrder = $order->fresh(['user', 'driver.user']);
        if ($freshOrder instanceof Order) {
            $this->paymentProofPushNotificationService->notifyProofRejectedToCustomer($freshOrder, $actor, $reason);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function negotiationDisplayAmount(array $snapshot): float
    {
        foreach (['approved_amount', 'counter_amount', 'quoted_amount', 'amount'] as $key) {
            if (isset($snapshot[$key]) && is_numeric($snapshot[$key])) {
                return round((float) $snapshot[$key], 2);
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function negotiationEventId(array $snapshot): ?int
    {
        $id = $snapshot['quote_log_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function negotiationPickupLocationId(array $snapshot): ?int
    {
        $id = $snapshot['pickup_location_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}
