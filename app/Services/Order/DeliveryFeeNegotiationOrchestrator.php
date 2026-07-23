<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Driver\DriverOrderLifecycleService;
use App\Services\Driver\DriverOrderPayloadFactory;
use App\Services\Pricing\ShoppingPricingService;
use Illuminate\Support\Facades\DB;

final class DeliveryFeeNegotiationOrchestrator
{
    public function __construct(
        private readonly DeliveryFeeNegotiationService $deliveryFeeNegotiationService,
        private readonly ShoppingPricingService $shoppingPricingService,
        private readonly DriverOrderPayloadFactory $driverOrderPayloadFactory,
        private readonly DriverOrderLifecycleService $driverOrderLifecycleService,
        private readonly OrderRealtimeNotifier $orderRealtimeNotifier,
        private readonly OrderStatusResolver $orderStatusResolver,
        private readonly DriverOrderResolver $driverOrderResolver,
        private readonly OrderTotalsSynchronizer $orderTotalsSynchronizer,
        private readonly ShoppingUnavailableItemPurger $shoppingUnavailableItemPurger
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateDeliveryFeeOverride(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder($orderId, $driver->id, ['statusRef', 'serviceType', 'payments', 'payment', 'evidences', 'courierOrder']);

            $manualAmount = array_key_exists('amount', $payload) && $payload['amount'] !== null
                ? round(max(0.0, (float) $payload['amount']), 2)
                : null;
            $reason = trim((string) ($payload['reason'] ?? ''));

            if (! $this->deliveryFeeNegotiationService->canDriverSubmitQuote($order)) {
                throw new ApiException('Revisi ongkir tidak tersedia untuk status atau pembayaran order ini.', 409);
            }

            if ($manualAmount !== null && $manualAmount <= 0) {
                throw new ApiException('Nominal ongkir manual harus lebih dari 0.', 422);
            }

            if ($manualAmount === null) {
                throw new ApiException('Nominal ongkir manual harus lebih dari 0.', 422);
            }

            if ($reason === '') {
                throw new ApiException('Alasan edit ongkir wajib diisi.', 422);
            }

            $quoteAmounts = $this->deliveryFeeNegotiationService->quoteAmounts(
                $order,
                $manualAmount
            );
            if ($quoteAmounts['final_amount'] <= 0) {
                throw new ApiException('Nominal ongkir manual harus lebih dari 0.', 422);
            }

            $trigger = $this->deliveryFeeNegotiationService->nextDriverQuoteTrigger($order);
            $pricingScopeMetadata = [];
            if (($order->serviceType->code ?? null) === 'SHOPPING') {
                // Edit ongkir manual berlaku pada pesanan yang berlanjut; tidak
                // ada lagi kompensasi perjalanan gagal yang melebur ke sini.
                $pricingScopeMetadata = [
                    'pricing_scope' => DeliveryFeeNegotiationService::PRICING_SCOPE_SHOPPING_TOTAL_TRANSPORT,
                    'previous_total_transport' => round((float) $order->delivery_fee, 2),
                    'replaced_delivery_fee' => round((float) $order->delivery_fee, 2),
                ];
            }
            $this->deliveryFeeNegotiationService->record(
                $order,
                $trigger,
                $actor->id,
                $reason,
                [
                    'old_delivery_fee' => round((float) $order->delivery_fee, 2),
                    'base_amount' => $quoteAmounts['base_amount'],
                    'quoted_amount' => $quoteAmounts['final_amount'],
                    'final_amount' => $quoteAmounts['final_amount'],
                    'amount' => $quoteAmounts['final_amount'],
                    'delivery_fee_source' => 'driver_manual',
                    'status' => 'PENDING_CUSTOMER',
                    ...$pricingScopeMetadata,
                ]
            );

            return $order->refresh();
        });

        $this->orderRealtimeNotifier->broadcastDeliveryFeeNegotiationUpdated((int) $order->id);
        $this->orderRealtimeNotifier->notifyDeliveryFeeChanged($order, $actor, 'customer', true);

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function bypassDeliveryFeeOverrideByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'payments', 'payment', 'evidences', 'courierOrder', 'items', 'shoppingReceipt']
            );

            $snapshot = $this->deliveryFeeNegotiationService->snapshot($order);
            if (($snapshot['status'] ?? null) !== 'PENDING_CUSTOMER') {
                throw new ApiException('Belum ada revisi ongkir yang menunggu persetujuan customer.', 409);
            }

            if (! $this->deliveryFeeNegotiationService->canCustomerRespond($order)) {
                throw new ApiException('Revisi ongkir tidak tersedia untuk status atau pembayaran order ini.', 409);
            }

            $quotedAmount = round((float) ($snapshot['quoted_amount'] ?? $snapshot['final_amount'] ?? 0), 2);
            if ($quotedAmount <= 0) {
                throw new ApiException('Nominal revisi ongkir tidak valid.', 409);
            }

            $note = trim((string) ($payload['note'] ?? ''));
            $this->deliveryFeeNegotiationService->record(
                $order,
                DeliveryFeeNegotiationService::DRIVER_FEE_APPROVED_BY_DRIVER_BYPASS,
                $actor->id,
                $note !== '' ? $note : 'Driver melanjutkan revisi ongkir tanpa respons customer.',
                [
                    'quote_log_id' => $snapshot['quote_log_id'] ?? null,
                    'old_delivery_fee' => $snapshot['old_delivery_fee'] ?? round((float) $order->delivery_fee, 2),
                    'base_amount' => $snapshot['base_amount'] ?? $quotedAmount,
                    'quoted_amount' => $quotedAmount,
                    'approved_amount' => $quotedAmount,
                    'final_amount' => $quotedAmount,
                    'delivery_fee_source' => 'driver_manual',
                    'status' => 'APPROVED',
                    'bypassed_by_driver' => true,
                    ...$this->deliveryFeePricingScopeMetadata($snapshot),
                ]
            );

            return $this->applyApprovedDeliveryFeeOverride(
                $order,
                $actor->id,
                $quotedAmount,
                DeliveryFeeNegotiationService::DRIVER_FEE_APPROVED_BY_DRIVER_BYPASS,
                'Driver melanjutkan revisi ongkir tanpa respons customer.',
                $note !== '' ? $note : ($snapshot['note'] ?? null),
                'driver'
            );
        });

        $this->orderRealtimeNotifier->broadcastDeliveryFeeNegotiationUpdated((int) $order->id);
        $this->orderRealtimeNotifier->notifyDeliveryFeeChanged($order, $actor, 'customer', false);

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function acceptDeliveryFeeCounterByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder($orderId, $driver->id, ['statusRef', 'serviceType', 'payments', 'payment', 'evidences', 'courierOrder', 'items', 'shoppingReceipt']);
            $snapshot = $this->deliveryFeeNegotiationService->snapshot($order);
            if (($snapshot['status'] ?? null) !== 'PENDING_DRIVER') {
                throw new ApiException('Belum ada tawaran ongkir customer yang perlu disetujui.', 409);
            }

            if (! $this->deliveryFeeNegotiationService->canDriverSubmitQuote($order)) {
                throw new ApiException('Revisi ongkir tidak tersedia untuk status atau pembayaran order ini.', 409);
            }

            $counterAmount = round((float) ($snapshot['counter_amount'] ?? 0), 2);
            if ($counterAmount <= 0) {
                throw new ApiException('Nominal tawaran ongkir customer tidak valid.', 409);
            }

            $note = trim((string) ($payload['note'] ?? ''));
            $this->deliveryFeeNegotiationService->record(
                $order,
                DeliveryFeeNegotiationService::DRIVER_COUNTER_APPROVED,
                $actor->id,
                $note !== '' ? $note : 'Driver menyetujui tawaran ongkir customer.',
                [
                    'quote_log_id' => $snapshot['quote_log_id'] ?? null,
                    'old_delivery_fee' => $snapshot['old_delivery_fee'] ?? round((float) $order->delivery_fee, 2),
                    'base_amount' => $snapshot['base_amount'] ?? null,
                    'quoted_amount' => $snapshot['quoted_amount'] ?? null,
                    'counter_base_amount' => $snapshot['counter_base_amount'] ?? $counterAmount,
                    'counter_amount' => $counterAmount,
                    'approved_amount' => $counterAmount,
                    'final_amount' => $counterAmount,
                    'delivery_fee_source' => 'driver_manual',
                    'status' => 'APPROVED',
                    ...$this->deliveryFeePricingScopeMetadata($snapshot),
                ]
            );

            return $this->applyApprovedDeliveryFeeOverride(
                $order,
                $actor->id,
                $counterAmount,
                'DRIVER_DELIVERY_FEE_COUNTER_APPROVED',
                'Driver menyetujui tawaran ongkir customer.',
                $note !== '' ? $note : ($snapshot['note'] ?? null),
                'driver'
            );
        });

        $this->orderRealtimeNotifier->broadcastDeliveryFeeNegotiationUpdated((int) $order->id);
        $this->orderRealtimeNotifier->notifyDeliveryFeeChanged($order, $actor, 'customer', false);

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function respondDeliveryFeeOverrideByCustomer(User $actor, int $orderId, array $payload): Order
    {
        $action = strtoupper(trim((string) ($payload['action'] ?? '')));
        $statusChangeEventPayload = null;

        $order = DB::transaction(function () use ($actor, $orderId, $payload, $action, &$statusChangeEventPayload): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'payments', 'payment', 'evidences', 'courierOrder', 'items', 'shoppingReceipt'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order || (int) $order->user_id !== (int) $actor->id) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            return match ($action) {
                'APPROVE' => $this->approveDeliveryFeeQuoteByCustomer($actor, $order),
                'COUNTER' => $this->counterDeliveryFeeQuoteByCustomer($actor, $order, $payload),
                'CANCEL_ORDER' => $this->cancelOrderFromDeliveryFeeNegotiation($actor, $order, $statusChangeEventPayload),
                default => throw new ApiException('Aksi respons revisi ongkir tidak valid.', 422),
            };
        });

        $this->orderRealtimeNotifier->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->orderRealtimeNotifier->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
        $this->orderRealtimeNotifier->broadcastDeliveryFeeNegotiationUpdated((int) $order->id);
        $this->orderRealtimeNotifier->notifyDeliveryFeeChanged($order, $actor, 'driver', $action === 'COUNTER');

        $freshOrder = $order->fresh(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'shoppingReceipt']);
        if (! $freshOrder instanceof Order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        return $freshOrder;
    }

    private function approveDeliveryFeeQuoteByCustomer(User $actor, Order $order): Order
    {
        $snapshot = $this->deliveryFeeNegotiationService->snapshot($order);
        if (($snapshot['status'] ?? null) !== 'PENDING_CUSTOMER') {
            throw new ApiException('Belum ada revisi ongkir yang menunggu persetujuan customer.', 409);
        }

        if (! $this->deliveryFeeNegotiationService->canCustomerRespond($order)) {
            throw new ApiException('Revisi ongkir tidak tersedia untuk status atau pembayaran order ini.', 409);
        }

        $quotedAmount = round((float) ($snapshot['quoted_amount'] ?? 0), 2);
        if ($quotedAmount <= 0) {
            throw new ApiException('Nominal revisi ongkir tidak valid.', 409);
        }

        $this->deliveryFeeNegotiationService->record(
            $order,
            DeliveryFeeNegotiationService::CUSTOMER_FEE_APPROVED,
            $actor->id,
            'Customer menyetujui revisi ongkir.',
            [
                'quote_log_id' => $snapshot['quote_log_id'] ?? null,
                'old_delivery_fee' => $snapshot['old_delivery_fee'] ?? round((float) $order->delivery_fee, 2),
                'base_amount' => $snapshot['base_amount'] ?? $quotedAmount,
                'quoted_amount' => $quotedAmount,
                'approved_amount' => $quotedAmount,
                'final_amount' => $quotedAmount,
                'delivery_fee_source' => 'driver_manual',
                'status' => 'APPROVED',
                ...$this->deliveryFeePricingScopeMetadata($snapshot),
            ]
        );

        return $this->applyApprovedDeliveryFeeOverride(
            $order,
            $actor->id,
            $quotedAmount,
            'CUSTOMER_DELIVERY_FEE_APPROVED',
            'Customer menyetujui revisi ongkir.',
            $snapshot['note'] ?? null,
            'customer'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function counterDeliveryFeeQuoteByCustomer(User $actor, Order $order, array $payload): Order
    {
        $snapshot = $this->deliveryFeeNegotiationService->snapshot($order);
        if (($snapshot['status'] ?? null) !== 'PENDING_CUSTOMER') {
            throw new ApiException('Belum ada revisi ongkir yang bisa ditawar.', 409);
        }

        if (! $this->deliveryFeeNegotiationService->canCustomerRespond($order)) {
            throw new ApiException('Revisi ongkir tidak tersedia untuk status atau pembayaran order ini.', 409);
        }

        $counterAmount = round((float) ($payload['counter_amount'] ?? 0), 2);
        if ($counterAmount <= 0) {
            throw new ApiException('Nominal tawaran ongkir harus lebih dari 0.', 422);
        }

        $counterAmounts = $this->deliveryFeeNegotiationService->quoteAmounts(
            $order,
            $counterAmount
        );

        $this->deliveryFeeNegotiationService->record(
            $order,
            DeliveryFeeNegotiationService::CUSTOMER_FEE_COUNTERED,
            $actor->id,
            'Customer mengirim tawaran ongkir.',
            [
                'quote_log_id' => $snapshot['quote_log_id'] ?? null,
                'old_delivery_fee' => $snapshot['old_delivery_fee'] ?? round((float) $order->delivery_fee, 2),
                'quoted_amount' => $snapshot['quoted_amount'] ?? null,
                'counter_base_amount' => $counterAmounts['base_amount'],
                'counter_amount' => $counterAmounts['final_amount'],
                'delivery_fee_source' => 'driver_manual',
                'status' => 'PENDING_DRIVER',
                ...$this->deliveryFeePricingScopeMetadata($snapshot),
            ]
        );

        return $order->refresh();
    }

    private function cancelOrderFromDeliveryFeeNegotiation(User $actor, Order $order, mixed &$statusChangeEventPayload): Order
    {
        $snapshot = $this->deliveryFeeNegotiationService->snapshot($order);
        if (($snapshot['status'] ?? null) !== 'PENDING_CUSTOMER') {
            throw new ApiException('Belum ada revisi ongkir yang bisa dibatalkan.', 409);
        }

        if (! $this->deliveryFeeNegotiationService->canCustomerRespond($order)) {
            throw new ApiException('Order ini tidak bisa dibatalkan dari revisi ongkir.', 409);
        }

        $reason = 'Customer membatalkan order karena menolak revisi ongkir.';
        $statusId = $this->orderStatusResolver->resolveStatusId('CANCELLED');
        $previousStatusCode = strtoupper((string) ($order->statusRef->code ?? ''));

        $this->deliveryFeeNegotiationService->record(
            $order,
            DeliveryFeeNegotiationService::CUSTOMER_CANCEL_ORDER,
            $actor->id,
            $reason,
            [
                'quote_log_id' => $snapshot['quote_log_id'] ?? null,
                'old_delivery_fee' => $snapshot['old_delivery_fee'] ?? round((float) $order->delivery_fee, 2),
                'quoted_amount' => $snapshot['quoted_amount'] ?? null,
                'counter_amount' => $snapshot['counter_amount'] ?? null,
                'status' => 'CANCELLED_ORDER',
            ]
        );

        $order->update($this->orderStatusResolver->cancelledOrderUpdateAttributes('CANCELLED', 'customer', $reason));
        $this->shoppingUnavailableItemPurger->purgeTerminalShoppingUnavailableItems(
            $order,
            (int) $actor->id,
            'CANCELLED',
            'CUSTOMER_CANCELLED_DELIVERY_FEE_NEGOTIATION',
        );

        $statusHistory = OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status_id' => $statusId,
            'event_type' => 'STATUS_CHANGE',
            'changed_by_user_id' => $actor->id,
            'note' => $reason,
            'price_snapshot' => [
                'source' => 'CUSTOMER_CANCEL_DELIVERY_FEE_NEGOTIATION',
            ],
        ]);

        $statusChangeEventPayload = $this->orderRealtimeNotifier->buildOrderStatusBroadcastPayload(
            $order->id,
            'CANCELLED',
            $previousStatusCode,
            $statusHistory
        );

        if ($order->driver_id !== null) {
            $this->driverOrderLifecycleService->syncAvailabilityAfterNonRunningOrder((int) $order->driver_id);
        }

        return $order->refresh();
    }

    private function applyApprovedDeliveryFeeOverride(
        Order $order,
        int $actorId,
        float $amount,
        string $triggerType,
        string $note,
        ?string $reason,
        string $changedByRole,
    ): Order {
        $oldDeliveryFee = (float) $order->delivery_fee;
        $oldTotalPrice = (float) $order->total_price;

        $order->update([
            'delivery_fee' => round($amount, 2),
            'delivery_fee_source' => 'driver_manual',
        ]);

        return $this->orderTotalsSynchronizer->refreshTotalsAfterDeliveryFeeChange(
            $order->refresh()->loadMissing('courierOrder'),
            $actorId,
            $triggerType,
            $note,
            $oldDeliveryFee,
            $oldTotalPrice,
            $reason,
            $changedByRole,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function deliveryFeePricingScopeMetadata(array $snapshot): array
    {
        if (($snapshot['pricing_scope'] ?? null) !== DeliveryFeeNegotiationService::PRICING_SCOPE_SHOPPING_TOTAL_TRANSPORT) {
            return [];
        }

        return array_filter([
            'pricing_scope' => DeliveryFeeNegotiationService::PRICING_SCOPE_SHOPPING_TOTAL_TRANSPORT,
            'previous_total_transport' => $snapshot['previous_total_transport'] ?? null,
            'replaced_delivery_fee' => $snapshot['replaced_delivery_fee'] ?? null,
            'replaced_failed_trip_compensation' => $snapshot['replaced_failed_trip_compensation'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{status_id:int,cancelled_by:string,cancellation_reason:string,cancelled_at:\Illuminate\Support\Carbon}
     */
}
