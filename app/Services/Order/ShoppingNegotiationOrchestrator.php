<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Driver\DriverOrderLifecycleService;
use App\Services\Driver\DriverOrderPayloadFactory;
use App\Services\Notification\OrderPricingPushNotificationService;
use App\Services\Notification\PaymentProofReminderNotificationService;
use App\Services\Notification\ShoppingItemAvailabilityPushNotificationService;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingFailedTripCompensationService;
use App\Services\Shopping\ShoppingItemChangeRequestService;
use App\Services\Shopping\ShoppingMerchantCandidateResolver;
use App\Services\Shopping\ShoppingOrderCapabilityService;
use App\Services\Shopping\ShoppingPickupLocationService;
use App\Services\Shopping\ShoppingPriceNegotiationService;
use App\Services\Shopping\ShoppingReplacementProjectionService;
use App\Services\Shopping\ShoppingRouteService;
use App\Services\Shopping\ShoppingUnavailableItemDecisionService;
use Illuminate\Support\Facades\DB;

final class ShoppingNegotiationOrchestrator
{
    public function __construct(
        private readonly OrderRealtimeNotifier $orderRealtimeNotifier,
        private readonly OrderPricingPushNotificationService $orderPricingPushNotificationService,
        private readonly PaymentProofReminderNotificationService $paymentProofReminderNotificationService,
        private readonly ShoppingPricingService $shoppingPricingService,
        private readonly ShoppingRouteService $shoppingRouteService,
        private readonly DriverOrderPayloadFactory $driverOrderPayloadFactory,
        private readonly DriverOrderLifecycleService $driverOrderLifecycleService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly ShoppingMerchantCandidateResolver $shoppingMerchantCandidateResolver,
        private readonly ShoppingPickupLocationService $shoppingPickupLocationService,
        private readonly ShoppingPriceNegotiationService $shoppingPriceNegotiationService,
        private readonly ShoppingItemChangeRequestService $shoppingItemChangeRequestService,
        private readonly ShoppingOrderCapabilityService $shoppingOrderCapabilityService,
        private readonly ShoppingUnavailableItemDecisionService $shoppingUnavailableItemDecisionService,
        private readonly ShoppingItemAvailabilityPushNotificationService $shoppingItemAvailabilityPushNotificationService,
        private readonly ShoppingFailedTripCompensationService $shoppingFailedTripCompensationService,
        private readonly ShoppingReplacementProjectionService $shoppingReplacementProjectionService,
        private readonly OrderStatusResolver $orderStatusResolver,
        private readonly DriverOrderResolver $driverOrderResolver,
        private readonly ShoppingUnavailableItemPurger $shoppingUnavailableItemPurger,
        private readonly ShoppingOrderItemEditService $shoppingOrderItemEditService
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function decideUnavailableShoppingItemsByDriver(
        User $actor,
        int $orderId,
        int $pickupLocationId,
        array $payload,
    ): array {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
        $action = strtoupper(trim((string) ($payload['action'] ?? '')));
        $itemIds = collect(is_array($payload['item_ids'] ?? null) ? $payload['item_ids'] : [])
            ->filter(fn (mixed $candidate): bool => is_numeric($candidate) && (int) $candidate > 0)
            ->map(fn (mixed $candidate): int => (int) $candidate)
            ->unique()
            ->values()
            ->all();
        $statusChangeEventPayload = null;

        $order = DB::transaction(function () use (
            $actor,
            $driver,
            $orderId,
            $pickupLocationId,
            $action,
            $itemIds,
            &$statusChangeEventPayload,
        ): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt'],
            );
            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Keputusan item hanya tersedia untuk order SHOPPING.', 409);
            }
            if ($this->orderStatusResolver->orderStatusCode($order) !== 'ARRIVED_MERCHANT') {
                throw new ApiException('Keputusan item hanya tersedia saat driver berada di toko/resto.', 409);
            }

            $pickup = $this->shoppingPickupLocationService->pickupById($order, $pickupLocationId);
            if (strtoupper((string) ($pickup->fulfillment_status ?? '')) !== 'ITEMS_PENDING_CUSTOMER') {
                throw new ApiException('Keputusan item sudah diselesaikan pihak lain.', 409, [
                    'code' => 'STATE_CHANGED',
                ]);
            }

            $lockedItems = $order->items()->lockForUpdate()->get();
            $order->setRelation('items', $lockedItems);
            $actions = $this->shoppingUnavailableItemDecisionService->actionsForPickup($order, $pickupLocationId);

            if ($action === 'CANCEL_MERCHANT') {
                if (! (bool) ($actions['can_driver_cancel_merchant'] ?? false)) {
                    throw new ApiException('Toko/resto ini tidak lagi dapat dibatalkan.', 409, [
                        'code' => 'STATE_CHANGED',
                    ]);
                }

                return $this->cancelShoppingMerchant(
                    actor: $actor,
                    order: $order,
                    pickup: $pickup,
                    reason: 'Driver membatalkan toko/resto karena item tidak tersedia.',
                    failureType: 'DRIVER_CANCEL_UNAVAILABLE_MERCHANT',
                    negotiationTrigger: 'DRIVER_CANCEL_UNAVAILABLE_MERCHANT',
                    cancellationWithFeeTrigger: 'DRIVER_CANCEL_UNAVAILABLE_MERCHANT_WITH_FEE',
                    cancellationLastMerchantTrigger: 'DRIVER_CANCEL_LAST_UNAVAILABLE_MERCHANT',
                    cancelledBy: 'driver',
                    statusChangeEventPayload: $statusChangeEventPayload,
                );
            }

            if ($action !== 'REMOVE') {
                throw new ApiException('Aksi keputusan item driver tidak valid.', 422);
            }
            if (! (bool) ($actions['can_driver_continue_without_item'] ?? false)) {
                throw new ApiException('Item tidak tersedia sudah diselesaikan pihak lain.', 409, [
                    'code' => 'STATE_CHANGED',
                ]);
            }

            return $this->applyImmediateUnavailableItemRemoval(
                order: $order,
                actor: $actor,
                itemIds: $itemIds,
                pickupLocationId: $pickupLocationId,
                trigger: ShoppingItemChangeRequestService::DRIVER_UNAVAILABLE_ITEMS_REMOVED,
                note: 'Driver melanjutkan tanpa item tidak tersedia yang dipilih.',
            );
        });

        if ($action === 'CANCEL_MERCHANT') {
            $this->orderRealtimeNotifier->broadcastOrderStatusChanged($statusChangeEventPayload);
            $this->orderRealtimeNotifier->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
            $this->orderRealtimeNotifier->broadcastShoppingNegotiationUpdated((int) $order->id);
        }

        $this->orderRealtimeNotifier->broadcastContentUpdatedAfterCommit((int) $order->id, $action === 'CANCEL_MERCHANT'
            ? 'DRIVER_CANCEL_UNAVAILABLE_MERCHANT'
            : ShoppingItemChangeRequestService::DRIVER_UNAVAILABLE_ITEMS_REMOVED, [
                'pickup_location_id' => $pickupLocationId,
                'requires_driver_response' => false,
            ]);
        $this->orderPricingPushNotificationService->sendPriceChanged(
            $order,
            'customer',
            $action === 'CANCEL_MERCHANT'
                ? 'DRIVER_CANCEL_UNAVAILABLE_MERCHANT'
                : ShoppingItemChangeRequestService::DRIVER_UNAVAILABLE_ITEMS_REMOVED,
            (float) $order->total_price,
            false,
            $actor,
        );
        $this->paymentProofReminderNotificationService->scheduleForBlockingPaymentStatus($order->refresh());

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function transitionShoppingOrderToArrivedMerchant(
        Order $order,
        User $actor,
        string $actionCode,
        ?int $pickupLocationId,
        string $note,
        ?array &$statusChangeEventPayload,
    ): Order {
        $order->loadMissing('statusRef');
        $previousStatusCode = $this->orderStatusResolver->orderStatusCode($order);
        if ($previousStatusCode !== 'DRIVER_ASSIGNED') {
            return $order;
        }

        $targetStatusId = $this->orderStatusResolver->resolveStatusId('ARRIVED_MERCHANT');
        $order->update(['status_id' => $targetStatusId]);

        $priceSnapshot = ['action_code' => $actionCode];
        if ($pickupLocationId !== null) {
            $priceSnapshot['pickup_location_id'] = $pickupLocationId;
        }

        $statusHistory = OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status_id' => $targetStatusId,
            'event_type' => 'STATUS_CHANGE',
            'changed_by_user_id' => $actor->id,
            'note' => $note,
            'price_snapshot' => $priceSnapshot,
        ]);

        $statusChangeEventPayload = $this->orderRealtimeNotifier->buildOrderStatusBroadcastPayload(
            (int) $order->id,
            'ARRIVED_MERCHANT',
            $previousStatusCode,
            $statusHistory
        );

        return $order->refresh()->loadMissing('statusRef');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submitShoppingPriceQuoteByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'orderLocations']
            );

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Quote harga hanya tersedia untuk order SHOPPING.', 409);
            }

            if (! $this->shoppingOrderCapabilityService->canDriverSubmitShoppingQuote($order)) {
                throw new ApiException('Quote harga hanya bisa dikirim saat driver tiba di merchant dan tidak ada request item pending.', 409);
            }

            $amount = round((float) ($payload['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw new ApiException('Nominal harga merchant harus lebih dari 0.', 422);
            }

            $pickupLocationId = isset($payload['pickup_location_id']) && is_numeric($payload['pickup_location_id'])
                ? (int) $payload['pickup_location_id']
                : 0;
            if ($pickupLocationId <= 0) {
                throw new ApiException('Merchant harga Nitip wajib dipilih.', 422);
            }

            $pickup = $this->shoppingPickupLocationService->pickupById($order, $pickupLocationId);
            if (strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')) !== 'ITEMS_CONFIRMED') {
                throw new ApiException('Harga merchant hanya bisa dikirim setelah item merchant fix.', 409);
            }

            $note = trim((string) ($payload['note'] ?? ''));
            $trigger = $this->shoppingPriceNegotiationService->nextDriverQuoteTrigger($order, $pickup->id);

            $this->shoppingPriceNegotiationService->record(
                $order,
                $trigger,
                $actor->id,
                $note !== '' ? $note : 'Driver mengirim quote harga Nitip.',
                [
                    'pickup_location_id' => $pickup->id,
                    'quoted_amount' => $amount,
                    'amount' => $amount,
                    'status' => 'PENDING_CUSTOMER',
                ]
            );

            $pickup->update([
                'fulfillment_status' => 'PRICE_PENDING_CUSTOMER',
            ]);

            return $order->refresh();
        });

        $this->orderRealtimeNotifier->broadcastShoppingNegotiationUpdated((int) $order->id);
        $this->orderRealtimeNotifier->notifyShoppingPriceChanged($order, $actor, 'customer', true);

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function bypassShoppingPriceQuoteByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'orderLocations', 'items', 'shoppingReceipt']
            );

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Bypass harga hanya tersedia untuk order SHOPPING.', 409);
            }

            $pickupLocationId = isset($payload['pickup_location_id']) && is_numeric($payload['pickup_location_id'])
                ? (int) $payload['pickup_location_id']
                : 0;
            if ($pickupLocationId <= 0) {
                throw new ApiException('Merchant harga Nitip wajib dipilih.', 422);
            }

            $pickup = $this->shoppingPickupLocationService->pickupById($order, $pickupLocationId);
            if (strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')) !== 'PRICE_PENDING_CUSTOMER') {
                throw new ApiException('Bypass hanya tersedia untuk harga merchant yang menunggu customer.', 409);
            }

            $snapshot = $this->shoppingPriceNegotiationService->snapshotForPickup($order, $pickupLocationId);
            if (($snapshot['status'] ?? null) !== 'PENDING_CUSTOMER') {
                throw new ApiException('Belum ada harga merchant yang menunggu customer.', 409);
            }

            $quotedAmount = round((float) ($snapshot['quoted_amount'] ?? 0), 2);
            if ($quotedAmount <= 0) {
                throw new ApiException('Nominal quote harga tidak valid.', 409);
            }

            $note = trim((string) ($payload['note'] ?? ''));
            $this->shoppingPriceNegotiationService->record(
                $order,
                ShoppingPriceNegotiationService::MERCHANT_PRICE_APPROVED_BY_DRIVER_BYPASS,
                $actor->id,
                $note !== '' ? $note : 'Driver melanjutkan harga merchant tanpa respons customer.',
                [
                    'quote_log_id' => $snapshot['quote_log_id'] ?? null,
                    'pickup_location_id' => $pickupLocationId,
                    'quoted_amount' => $quotedAmount,
                    'approved_amount' => $quotedAmount,
                    'status' => 'APPROVED',
                    'bypassed_by_driver' => true,
                ]
            );

            $pickup->update([
                'fulfillment_status' => 'PRICE_APPROVED',
            ]);

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt', 'orderLocations']),
                $actor->id,
                'MERCHANT_PRICE_APPROVED_BY_DRIVER_BYPASS',
                true,
                'Harga merchant dilanjutkan oleh driver tanpa respons customer.',
                false
            );
        });

        $this->orderRealtimeNotifier->broadcastShoppingNegotiationUpdated((int) $order->id);
        $this->orderRealtimeNotifier->notifyShoppingBypassTotalChanged($order, $actor);

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function bypassUnavailableShoppingItemsByDriver(
        User $actor,
        int $orderId,
        int $pickupLocationId,
    ): array {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
        $statusChangeEventPayload = null;
        $bypassedItemNames = [];
        $bypassEventId = 0;
        $merchantCancelled = false;

        $order = DB::transaction(function () use (
            $actor,
            $driver,
            $orderId,
            $pickupLocationId,
            &$statusChangeEventPayload,
            &$bypassedItemNames,
            &$bypassEventId,
            &$merchantCancelled,
        ): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt']
            );

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Bypass item hanya tersedia untuk order SHOPPING.', 409);
            }
            if ($this->orderStatusResolver->orderStatusCode($order) !== 'ARRIVED_MERCHANT') {
                throw new ApiException('Bypass item hanya tersedia saat driver berada di merchant.', 409);
            }

            $pickup = $this->shoppingPickupLocationService->pickupById($order, $pickupLocationId);
            if (strtoupper((string) ($pickup->fulfillment_status ?? '')) !== 'ITEMS_PENDING_CUSTOMER') {
                throw new ApiException('Bypass hanya tersedia untuk item yang menunggu keputusan customer.', 409);
            }

            $lockedItems = $order->items()->lockForUpdate()->get();
            $order->setRelation('items', $lockedItems);
            $pickupItems = $this->shoppingItemsForPickup($order, $pickup);
            $unavailableItems = $pickupItems
                ->filter(fn (OrderItem $item): bool => ! (bool) ($item->is_available ?? true))
                ->values();

            if ($unavailableItems->isEmpty()) {
                throw new ApiException('Item tidak tersedia sudah diselesaikan.', 409);
            }

            $availableItems = $pickupItems
                ->filter(fn (OrderItem $item): bool => (bool) ($item->is_available ?? true))
                ->values();
            $itemSnapshots = $unavailableItems
                ->map(fn (OrderItem $item): array => [
                    'id' => (int) $item->id,
                    'name' => trim((string) ($item->menu_name ?? '')) ?: 'Item Nitip',
                    'quantity' => (int) $item->quantity,
                    'unit_price' => round((float) $item->unit_price, 2),
                    'subtotal' => round((float) $item->subtotal, 2),
                    'is_available' => (bool) $item->is_available,
                ])
                ->all();
            $bypassedItemNames = array_values(array_map(
                static fn (array $item): string => (string) $item['name'],
                $itemSnapshots,
            ));
            $merchantCancelled = $availableItems->isEmpty();

            $bypassLog = OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => 'SHOPPING_ITEM_AVAILABILITY',
                'trigger_type' => ShoppingPriceNegotiationService::DRIVER_BYPASS_UNAVAILABLE_ITEMS,
                'changed_by_user_id' => $actor->id,
                'note' => $merchantCancelled
                    ? 'Driver membypass semua item tidak tersedia dan membatalkan merchant.'
                    : 'Driver melanjutkan order tanpa item yang tidak tersedia.',
                'metadata' => [
                    'pickup_location_id' => (int) $pickup->id,
                    'merchant_name' => $pickup->restaurant_id !== null
                        ? ($pickup->restaurant->name ?? $pickup->label)
                        : $pickup->label,
                    'items' => $itemSnapshots,
                    'merchant_cancelled' => $merchantCancelled,
                    'fulfillment_status_before' => 'ITEMS_PENDING_CUSTOMER',
                    'fulfillment_status_after' => $merchantCancelled ? 'FAILED' : 'ITEMS_CONFIRMED',
                ],
            ]);
            $bypassEventId = (int) $bypassLog->id;

            if ($merchantCancelled) {
                return $this->cancelShoppingMerchant(
                    actor: $actor,
                    order: $order,
                    pickup: $pickup,
                    reason: 'Semua item merchant tidak tersedia dan dibypass driver.',
                    failureType: ShoppingPriceNegotiationService::DRIVER_BYPASS_UNAVAILABLE_ITEMS,
                    negotiationTrigger: ShoppingPriceNegotiationService::DRIVER_BYPASS_UNAVAILABLE_ITEMS,
                    cancellationWithFeeTrigger: 'DRIVER_BYPASS_UNAVAILABLE_ITEMS_WITH_FEE',
                    cancellationLastMerchantTrigger: 'DRIVER_BYPASS_LAST_UNAVAILABLE_MERCHANT',
                    cancelledBy: 'driver',
                    statusChangeEventPayload: $statusChangeEventPayload,
                );
            }

            foreach ($unavailableItems as $item) {
                $item->delete();
            }
            $pickup->update(['fulfillment_status' => 'ITEMS_CONFIRMED']);

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt', 'orderLocations']),
                (int) $actor->id,
                ShoppingPriceNegotiationService::DRIVER_BYPASS_UNAVAILABLE_ITEMS,
                true,
                'Driver melanjutkan order tanpa item yang tidak tersedia.',
                false,
            );
        });

        $this->orderRealtimeNotifier->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->orderRealtimeNotifier->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
        $this->orderRealtimeNotifier->broadcastContentUpdatedAfterCommit(
            (int) $order->id,
            ShoppingPriceNegotiationService::DRIVER_BYPASS_UNAVAILABLE_ITEMS,
            [
                'pickup_location_id' => $pickupLocationId,
                'event_id' => $bypassEventId,
                'merchant_cancelled' => $merchantCancelled,
            ],
        );
        $this->orderRealtimeNotifier->broadcastShoppingNegotiationUpdated((int) $order->id);
        $this->shoppingItemAvailabilityPushNotificationService->sendUnavailableItemsBypassed(
            $order,
            $pickupLocationId,
            $bypassedItemNames,
            $bypassEventId,
            $merchantCancelled,
        );
        $this->paymentProofReminderNotificationService->scheduleForBlockingPaymentStatus($order->refresh());

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function replaceUnavailableShoppingItemsByDriver(
        User $actor,
        int $orderId,
        int $pickupLocationId,
        array $payload,
        string $idempotencyKey,
    ): array {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 100) {
            throw new ApiException('Idempotency-Key wajib diisi dan maksimal 100 karakter.', 422);
        }

        $itemsPayload = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $fingerprint = $this->shoppingUnavailableItemReplacementFingerprint($itemsPayload);
        $eventId = 0;
        $oldItemNames = [];
        $newItemNames = [];
        $idempotentReplay = false;

        $order = DB::transaction(function () use (
            $actor,
            $driver,
            $orderId,
            $pickupLocationId,
            $itemsPayload,
            $idempotencyKey,
            $fingerprint,
            &$eventId,
            &$oldItemNames,
            &$newItemNames,
            &$idempotentReplay,
        ): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt']
            );

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Ganti item hanya tersedia untuk order Nitip.', 409);
            }

            $replay = OrderLog::query()
                ->where('order_id', $order->id)
                ->where('event_type', 'SHOPPING_ITEM_AVAILABILITY')
                ->where('trigger_type', 'DRIVER_UNAVAILABLE_ITEMS_REPLACED')
                ->orderByDesc('id')
                ->get()
                ->first(fn (OrderLog $event): bool => (string) data_get($event->metadata, 'idempotency_key') === $idempotencyKey);
            if ($replay instanceof OrderLog) {
                if ((string) data_get($replay->metadata, 'payload_fingerprint') !== $fingerprint) {
                    throw new ApiException('Idempotency-Key sudah dipakai untuk payload ganti item yang berbeda.', 422);
                }

                $eventId = (int) $replay->id;
                $idempotentReplay = true;

                return $order->refresh();
            }

            if ($this->orderStatusResolver->orderStatusCode($order) !== 'ARRIVED_MERCHANT') {
                throw new ApiException('Ganti item hanya tersedia saat driver berada di toko/resto.', 409, [
                    'code' => 'STATE_CHANGED',
                ]);
            }

            $pickup = OrderLocation::query()
                ->where('order_id', $order->id)
                ->whereKey($pickupLocationId)
                ->lockForUpdate()
                ->first();
            if (! $pickup instanceof OrderLocation || strtoupper((string) $pickup->location_role) !== 'PICKUP') {
                throw new ApiException('Toko/resto order tidak valid.', 422);
            }
            if (strtoupper((string) ($pickup->fulfillment_status ?? '')) !== 'ITEMS_PENDING_CUSTOMER') {
                throw new ApiException('Keputusan item toko/resto sudah diselesaikan.', 409, [
                    'code' => 'STATE_CHANGED',
                ]);
            }

            $lockedItems = $order->items()->lockForUpdate()->get();
            $order->setRelation('items', $lockedItems);
            $pickupItems = $this->shoppingItemsForPickup($order, $pickup);
            $unavailableItems = $pickupItems
                ->filter(fn (OrderItem $item): bool => ! (bool) ($item->is_available ?? true))
                ->values();
            if ($unavailableItems->isEmpty()) {
                throw new ApiException('Item tidak tersedia sudah diselesaikan pihak lain.', 409, [
                    'code' => 'STATE_CHANGED',
                ]);
            }

            $oldItemSnapshots = $unavailableItems
                ->map(fn (OrderItem $item): array => [
                    'id' => (int) $item->id,
                    'name' => (string) $item->menu_name,
                    'quantity' => (int) $item->quantity,
                    'is_available' => false,
                ])
                ->values()
                ->all();
            $oldItemNames = array_values(array_filter(array_map(
                static fn (array $item): string => trim($item['name']),
                $oldItemSnapshots,
            )));
            $beforeItemIds = $lockedItems->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $items = $this->shoppingOrderItemEditService->withTargetPickupMerchantPayload($order, $itemsPayload, $pickupLocationId);
            $items = $this->enrichShoppingItemChangeRequestItems($order, $items);
            $this->applyApprovedShoppingItemChange(
                $order,
                $actor,
                'ADD',
                $items,
                null,
                $pickupLocationId,
            );

            $pickup->update(['fulfillment_status' => 'ITEMS_CONFIRMED']);
            $createdItems = $order->items()
                ->where('pickup_location_id', $pickupLocationId)
                ->whereNotIn('id', $beforeItemIds)
                ->orderBy('id')
                ->get();
            $newItemSnapshots = $createdItems
                ->map(fn (OrderItem $item): array => [
                    'id' => (int) $item->id,
                    'name' => (string) $item->menu_name,
                    'quantity' => (int) $item->quantity,
                    'item_source' => (string) $item->item_source,
                    'menu_id' => $item->menu_id !== null ? (int) $item->menu_id : null,
                ])
                ->values()
                ->all();
            $newItemNames = array_values(array_filter(array_map(
                static fn (array $item): string => trim($item['name']),
                $newItemSnapshots,
            )));

            $event = OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => 'SHOPPING_ITEM_AVAILABILITY',
                'trigger_type' => 'DRIVER_UNAVAILABLE_ITEMS_REPLACED',
                'changed_by_user_id' => $actor->id,
                'note' => 'Driver mengganti item tidak tersedia pada toko/resto yang sama.',
                'metadata' => [
                    'pickup_location_id' => $pickupLocationId,
                    'actor_role' => 'driver',
                    'old_items' => $oldItemSnapshots,
                    'items' => $newItemSnapshots,
                    'idempotency_key' => $idempotencyKey,
                    'payload_fingerprint' => $fingerprint,
                    'fulfillment_status_before' => 'ITEMS_PENDING_CUSTOMER',
                    'fulfillment_status_after' => 'ITEMS_CONFIRMED',
                ],
            ]);
            $eventId = (int) $event->id;

            $this->shoppingPriceNegotiationService->record(
                $order,
                ShoppingPriceNegotiationService::SHOPPING_ITEM_CHANGE_REQUIRES_REQUOTE,
                $actor->id,
                'Driver mengganti item tidak tersedia. Harga toko/resto perlu dikirim ulang.',
                [
                    'status' => 'NEEDS_REQUOTE',
                    'request_log_id' => $eventId,
                    'request_kind' => 'EDIT_UNAVAILABLE',
                    'pickup_location_id' => $pickupLocationId,
                    'actor_role' => 'driver',
                ]
            );

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt', 'orderLocations']),
                $actor->id,
                'DRIVER_UNAVAILABLE_ITEMS_REPLACED',
                true,
                'Driver mengganti item tidak tersedia pada toko/resto yang sama.',
                false,
            );
        });

        if (! $idempotentReplay) {
            $this->orderRealtimeNotifier->broadcastContentUpdatedAfterCommit((int) $order->id, 'DRIVER_UNAVAILABLE_ITEMS_REPLACED', [
                'pickup_location_id' => $pickupLocationId,
                'event_id' => $eventId,
            ]);
            $this->orderRealtimeNotifier->broadcastShoppingNegotiationUpdated((int) $order->id);
            $this->orderPricingPushNotificationService->sendPriceChanged(
                $order,
                'customer',
                'DRIVER_UNAVAILABLE_ITEMS_REPLACED',
                (float) $order->total_price,
                false,
                $actor,
            );
            $this->shoppingItemAvailabilityPushNotificationService->sendUnavailableItemsReplaced(
                $order,
                $pickupLocationId,
                $oldItemNames,
                $newItemNames,
                $eventId,
            );
        }

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function respondShoppingPriceQuoteByCustomer(User $actor, int $orderId, array $payload): Order
    {
        $action = strtoupper(trim((string) ($payload['action'] ?? '')));
        $statusChangeEventPayload = null;

        $order = DB::transaction(function () use ($actor, $orderId, $payload, $action, &$statusChangeEventPayload): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'orderLocations', 'items', 'shoppingReceipt'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order || (int) $order->user_id !== (int) $actor->id) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Negosiasi harga hanya tersedia untuk order SHOPPING.', 409);
            }

            return match ($action) {
                'APPROVE' => $this->approveShoppingQuoteByCustomer($actor, $order, $payload),
                'CANCEL_MERCHANT' => $this->cancelShoppingMerchantByCustomer($actor, $order, $payload, $statusChangeEventPayload),
                default => throw new ApiException('Aksi respons quote tidak valid.', 422),
            };
        });

        return $this->finalizeCustomerShoppingMerchantDecision($order, $actor, $statusChangeEventPayload);
    }

    private function finalizeCustomerShoppingMerchantDecision(Order $order, User $actor, mixed $statusChangeEventPayload): Order
    {
        $this->orderRealtimeNotifier->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->orderRealtimeNotifier->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
        $this->orderRealtimeNotifier->broadcastShoppingNegotiationUpdated((int) $order->id);
        $this->orderRealtimeNotifier->notifyShoppingPriceChanged($order, $actor, 'driver', false);
        $this->paymentProofReminderNotificationService->scheduleForBlockingPaymentStatus($order->refresh());

        $freshOrder = $order->fresh(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'shoppingReceipt']);
        if (! $freshOrder instanceof Order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        return $freshOrder;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function requestShoppingItemChange(User $actor, int $orderId, array $payload): Order
    {
        $action = strtoupper((string) ($payload['action'] ?? 'ADD'));
        $statusChangeEventPayload = null;

        $order = DB::transaction(function () use ($actor, $orderId, $payload, $action, &$statusChangeEventPayload): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'items', 'orderLocations.restaurant', 'shoppingReceipt'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order || (int) $order->user_id !== (int) $actor->id) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if ($this->shoppingItemChangeRequestService->hasPending($order)) {
                throw new ApiException('Masih ada request perubahan item yang menunggu respons driver.', 409);
            }

            $requestKind = strtoupper((string) ($payload['request_kind'] ?? ''));
            if ($requestKind === '') {
                $requestKind = 'EDIT_UNAVAILABLE';
            }

            if (! in_array($action, ['ADD', 'UPDATE', 'REMOVE', 'CANCEL_MERCHANT'], true)) {
                throw new ApiException('Aksi perubahan item tidak valid.', 422);
            }

            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $itemId = isset($payload['item_id']) && is_numeric($payload['item_id'])
                ? (int) $payload['item_id']
                : null;
            $itemIds = collect(is_array($payload['item_ids'] ?? null) ? $payload['item_ids'] : [])
                ->filter(fn (mixed $candidate): bool => is_numeric($candidate) && (int) $candidate > 0)
                ->map(fn (mixed $candidate): int => (int) $candidate)
                ->unique()
                ->values()
                ->all();
            if ($action === 'REMOVE' && $itemIds === [] && $itemId !== null) {
                $itemIds = [$itemId];
            }
            if ($action === 'REMOVE' && $itemId === null && $itemIds !== []) {
                $itemId = $itemIds[0];
            }

            if ($action === 'ADD' && $items === []) {
                throw new ApiException('Minimal satu item belanja wajib diajukan.', 422);
            }

            if ($action === 'UPDATE' && ($itemId ?? 0) <= 0) {
                throw new ApiException('Item yang ingin diubah wajib dipilih.', 422);
            }
            if ($action === 'REMOVE' && $itemIds === []) {
                throw new ApiException('Minimal satu item yang ingin dilewati wajib dipilih.', 422);
            }

            $targetPickupLocationId = isset($payload['target_pickup_location_id']) && is_numeric($payload['target_pickup_location_id'])
                ? (int) $payload['target_pickup_location_id']
                : null;

            if ($requestKind === 'EDIT_UNAVAILABLE' && $targetPickupLocationId !== null && in_array($action, ['ADD', 'UPDATE'], true)) {
                $items = $this->shoppingOrderItemEditService->withTargetPickupMerchantPayload($order, $items, $targetPickupLocationId);
            }

            if ($requestKind === 'EDIT_UNAVAILABLE') {
                if (! $this->shoppingOrderCapabilityService->canCustomerEditUnavailableItems($order)) {
                    throw new ApiException('Keputusan item toko/resto sudah berubah.', 409, [
                        'code' => 'STATE_CHANGED',
                    ]);
                }
                if ($targetPickupLocationId === null) {
                    throw new ApiException('Merchant yang ingin diedit wajib dipilih.', 422);
                }
                $this->shoppingOrderItemEditService->assertShoppingEditUnavailableTarget($order, $targetPickupLocationId, $items, $action, $itemId);
            } else {
                throw new ApiException('Jenis request perubahan item tidak valid.', 422);
            }

            if ($action === 'CANCEL_MERCHANT') {
                return $this->cancelShoppingMerchantByCustomer(
                    $actor,
                    $order,
                    ['pickup_location_id' => $targetPickupLocationId],
                    $statusChangeEventPayload
                );
            }

            $note = trim((string) ($payload['note'] ?? ''));
            if ($action === 'REMOVE') {
                return $this->applyImmediateUnavailableItemRemoval(
                    order: $order,
                    actor: $actor,
                    itemIds: $itemIds,
                    pickupLocationId: (int) $targetPickupLocationId,
                    trigger: 'CUSTOMER_UNAVAILABLE_ITEMS_REMOVED',
                    note: $note !== '' ? $note : 'Customer melanjutkan tanpa item tidak tersedia.',
                );
            }

            $items = $this->enrichShoppingItemChangeRequestItems($order, $items);

            return $this->applyImmediateShoppingUnavailableItemDecision(
                $order,
                $actor,
                $action,
                $items,
                $itemId,
                (int) $targetPickupLocationId,
                $requestKind,
                $note !== '' ? $note : null
            );
        });

        if ($action === 'CANCEL_MERCHANT') {
            return $this->finalizeCustomerShoppingMerchantDecision($order, $actor, $statusChangeEventPayload);
        }

        $this->orderRealtimeNotifier->broadcastContentUpdatedAfterCommit((int) $order->id, 'SHOPPING_ITEM_CHANGE_APPLIED', [
            'requires_driver_response' => false,
        ]);
        $this->orderPricingPushNotificationService->sendPriceChanged(
            $order,
            'driver',
            'SHOPPING_ITEM_CHANGE_APPLIED',
            (float) $order->total_price,
            false,
            $actor,
        );

        return $order->fresh(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'shoppingReceipt']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function respondShoppingItemChangeByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
        $action = strtoupper(trim((string) ($payload['action'] ?? '')));

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload, $action): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'items', 'orderLocations.restaurant', 'shoppingReceipt']
            );

            if (! $this->shoppingOrderCapabilityService->isShopping($order)) {
                throw new ApiException('Request perubahan item hanya tersedia untuk order SHOPPING.', 409);
            }

            $pendingRequest = $this->shoppingItemChangeRequestService->pending($order);
            if (! $pendingRequest) {
                throw new ApiException('Tidak ada request perubahan item yang menunggu respons.', 409);
            }

            $note = trim((string) ($payload['note'] ?? ''));

            if ($action === 'REJECT') {
                $this->shoppingItemChangeRequestService->reject(
                    $order,
                    (int) $actor->id,
                    $pendingRequest,
                    $note !== '' ? $note : null
                );

                return $order->refresh();
            }

            if ($action !== 'APPROVE') {
                throw new ApiException('Aksi respons request item tidak valid.', 422);
            }

            $rawMetadata = $pendingRequest->getAttribute('metadata');
            $metadata = is_array($rawMetadata) ? $rawMetadata : [];
            $requestAction = strtoupper((string) ($metadata['action'] ?? 'ADD'));
            $requestKind = strtoupper((string) ($metadata['request_kind'] ?? 'EDIT_UNAVAILABLE'));
            $items = is_array($metadata['items'] ?? null) ? $metadata['items'] : [];
            $itemId = isset($metadata['item_id']) && is_numeric($metadata['item_id'])
                ? (int) $metadata['item_id']
                : null;
            $targetPickupLocationId = isset($metadata['target_pickup_location_id']) && is_numeric($metadata['target_pickup_location_id'])
                ? (int) $metadata['target_pickup_location_id']
                : null;

            $this->applyApprovedShoppingItemChange($order, $actor, $requestAction, $items, $itemId, $targetPickupLocationId);
            $this->shoppingItemChangeRequestService->approve(
                $order,
                (int) $actor->id,
                $pendingRequest,
                $note !== '' ? $note : null
            );

            if ($targetPickupLocationId !== null) {
                $this->shoppingPickupLocationService->pickupById($order, $targetPickupLocationId)->update([
                    'fulfillment_status' => 'ITEMS_CONFIRMED',
                ]);
            }

            $affectedPickupIds = $this->affectedPickupIdsForApprovedShoppingChange(
                $order->refresh()->load(['items', 'orderLocations.restaurant']),
                $requestAction,
                $items,
                $itemId,
                $targetPickupLocationId
            );
            foreach ($affectedPickupIds as $pickupLocationId) {
                $this->shoppingPriceNegotiationService->record(
                    $order,
                    ShoppingPriceNegotiationService::SHOPPING_ITEM_CHANGE_REQUIRES_REQUOTE,
                    $actor->id,
                    'Perubahan item disetujui driver. Driver perlu mengirim harga Nitip baru.',
                    [
                        'status' => 'NEEDS_REQUOTE',
                        'request_log_id' => (int) $pendingRequest->id,
                        'request_kind' => $requestKind,
                        'pickup_location_id' => $pickupLocationId,
                    ]
                );
            }

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                $actor->id,
                'SHOPPING_ITEM_CHANGE_APPROVED',
                true,
                'Driver menyetujui request perubahan item customer.'
            );
        });

        $this->orderRealtimeNotifier->broadcastContentUpdatedAfterCommit((int) $order->id, 'SHOPPING_ITEM_CHANGE_RESPONDED', [
            'approved' => $action === 'APPROVE',
        ]);
        $this->orderPricingPushNotificationService->sendPriceChanged(
            $order,
            'customer',
            $action === 'APPROVE' ? 'SHOPPING_ITEM_CHANGE_APPROVED' : 'SHOPPING_ITEM_CHANGE_REJECTED',
            (float) $order->total_price,
            false,
            $actor,
        );

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  list<int>  $itemIds
     */
    private function applyImmediateUnavailableItemRemoval(
        Order $order,
        User $actor,
        array $itemIds,
        int $pickupLocationId,
        string $trigger,
        string $note,
    ): Order {
        $this->shoppingUnavailableItemDecisionService->assertCanContinueWithoutItems(
            $order,
            $pickupLocationId,
            $itemIds,
        );

        $items = $order->items()
            ->where('pickup_location_id', $pickupLocationId)
            ->whereIn('id', $itemIds)
            ->lockForUpdate()
            ->get();
        if ($items->count() !== count($itemIds)) {
            throw new ApiException('Keputusan item sudah diselesaikan pihak lain.', 409, [
                'code' => 'STATE_CHANGED',
            ]);
        }

        $removedItems = $items->map(fn (OrderItem $item): array => [
            'id' => (int) $item->id,
            'name' => trim((string) ($item->menu_name ?? '')) ?: 'Item Nitip',
            'quantity' => max(1, (int) $item->quantity),
        ])->values()->all();
        foreach ($items as $item) {
            $item->delete();
        }

        $hasRemainingUnavailable = $order->items()
            ->where('pickup_location_id', $pickupLocationId)
            ->where('is_available', false)
            ->exists();
        $fulfillmentStatus = $hasRemainingUnavailable
            ? 'ITEMS_PENDING_CUSTOMER'
            : 'ITEMS_CONFIRMED';
        $pickup = $this->shoppingPickupLocationService->pickupById(
            $order->refresh()->load(['orderLocations']),
            $pickupLocationId,
        );
        $pickup->update(['fulfillment_status' => $fulfillmentStatus]);

        $appliedLog = $this->shoppingItemChangeRequestService->recordApplied(
            $order,
            (int) $actor->id,
            [
                'action' => 'REMOVE',
                'request_kind' => 'EDIT_UNAVAILABLE',
                'target_pickup_location_id' => $pickupLocationId,
                'item_ids' => $itemIds,
                'removed_items' => $removedItems,
                'actor_role' => (string) $actor->role,
                'fulfillment_status_after' => $fulfillmentStatus,
            ],
            $note,
            $trigger,
        );

        $this->shoppingPriceNegotiationService->record(
            $order,
            ShoppingPriceNegotiationService::SHOPPING_ITEM_CHANGE_REQUIRES_REQUOTE,
            (int) $actor->id,
            $note,
            [
                'status' => 'NEEDS_REQUOTE',
                'request_log_id' => (int) $appliedLog->id,
                'request_kind' => 'EDIT_UNAVAILABLE',
                'pickup_location_id' => $pickupLocationId,
                'item_ids' => $itemIds,
                'fulfillment_status_after' => $fulfillmentStatus,
            ],
        );

        return $this->shoppingPricingService->recalculate(
            $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt', 'orderLocations']),
            (int) $actor->id,
            $trigger,
            true,
            $note,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function applyImmediateShoppingUnavailableItemDecision(
        Order $order,
        User $actor,
        string $action,
        array $items,
        ?int $itemId,
        int $targetPickupLocationId,
        string $requestKind,
        ?string $note = null,
    ): Order {
        $this->applyApprovedShoppingItemChange(
            $order,
            $actor,
            $action,
            $items,
            $itemId,
            $targetPickupLocationId
        );

        $pickup = $this->shoppingPickupLocationService->pickupById($order->refresh()->load(['orderLocations']), $targetPickupLocationId);
        $pickup->update([
            'fulfillment_status' => 'ITEMS_CONFIRMED',
        ]);

        $appliedLog = $this->shoppingItemChangeRequestService->recordApplied(
            $order,
            (int) $actor->id,
            [
                'action' => $action,
                'request_kind' => $requestKind,
                'target_pickup_location_id' => $targetPickupLocationId,
                'items' => $items,
                'item_id' => $itemId,
            ],
            $note ?: 'Customer memperbarui item tidak tersedia. Driver perlu mengirim harga Nitip baru.'
        );

        $affectedPickupIds = $this->affectedPickupIdsForApprovedShoppingChange(
            $order->refresh()->load(['items', 'orderLocations.restaurant']),
            $action,
            $items,
            $itemId,
            $targetPickupLocationId
        );

        foreach ($affectedPickupIds as $pickupLocationId) {
            $this->shoppingPriceNegotiationService->record(
                $order,
                ShoppingPriceNegotiationService::SHOPPING_ITEM_CHANGE_REQUIRES_REQUOTE,
                $actor->id,
                'Customer memperbarui item tidak tersedia. Driver perlu mengirim harga Nitip baru.',
                [
                    'status' => 'NEEDS_REQUOTE',
                    'request_log_id' => (int) $appliedLog->id,
                    'request_kind' => $requestKind,
                    'pickup_location_id' => $pickupLocationId,
                ]
            );
        }

        return $this->shoppingPricingService->recalculate(
            $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
            $actor->id,
            'SHOPPING_ITEM_CHANGE_APPLIED',
            true,
            'Customer memperbarui item tidak tersedia.'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function approveShoppingQuoteByCustomer(User $actor, Order $order, array $payload): Order
    {
        $pickupLocationId = isset($payload['pickup_location_id']) && is_numeric($payload['pickup_location_id'])
            ? (int) $payload['pickup_location_id']
            : 0;
        if ($pickupLocationId <= 0) {
            throw new ApiException('Merchant harga Nitip wajib dipilih.', 422);
        }

        $pickup = $this->shoppingPickupLocationService->pickupById($order, $pickupLocationId);
        $snapshot = $this->shoppingPriceNegotiationService->snapshotForPickup($order, $pickupLocationId);
        if (($snapshot['status'] ?? null) !== 'PENDING_CUSTOMER') {
            throw new ApiException('Belum ada quote harga yang menunggu persetujuan customer.', 409);
        }

        $quotedAmount = round((float) ($snapshot['quoted_amount'] ?? 0), 2);
        if ($quotedAmount <= 0) {
            throw new ApiException('Nominal quote harga tidak valid.', 409);
        }

        $this->shoppingPriceNegotiationService->record(
            $order,
            ShoppingPriceNegotiationService::CUSTOMER_PRICE_APPROVED,
            $actor->id,
            'Customer menyetujui quote harga Nitip.',
            [
                'quote_log_id' => $snapshot['quote_log_id'] ?? null,
                'pickup_location_id' => $pickupLocationId,
                'quoted_amount' => $quotedAmount,
                'approved_amount' => $quotedAmount,
                'status' => 'APPROVED',
            ]
        );

        $pickup->update([
            'fulfillment_status' => 'PRICE_APPROVED',
        ]);

        return $this->shoppingPricingService->recalculate(
            $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt', 'orderLocations']),
            $actor->id,
            'SHOPPING_PRICE_APPROVED',
            true,
            'Customer menyetujui quote harga Nitip.'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cancelShoppingMerchantByCustomer(User $actor, Order $order, array $payload, mixed &$statusChangeEventPayload): Order
    {
        $pickup = $this->resolveShoppingNegotiationPickup(
            $order,
            isset($payload['pickup_location_id']) ? (int) $payload['pickup_location_id'] : null
        );
        if (! $pickup instanceof OrderLocation) {
            throw new ApiException('Merchant/pickup order tidak valid.', 422);
        }

        return $this->cancelShoppingMerchant(
            actor: $actor,
            order: $order,
            pickup: $pickup,
            reason: 'Resto tutup/order batal.',
            failureType: 'CUSTOMER_CANCEL_MERCHANT',
            negotiationTrigger: ShoppingPriceNegotiationService::CUSTOMER_CANCEL_MERCHANT,
            cancellationWithFeeTrigger: 'CUSTOMER_CANCEL_MERCHANT_WITH_FEE',
            cancellationLastMerchantTrigger: 'CUSTOMER_CANCEL_LAST_MERCHANT',
            cancelledBy: 'customer',
            statusChangeEventPayload: $statusChangeEventPayload,
        );
    }

    private function cancelShoppingMerchant(
        User $actor,
        Order $order,
        OrderLocation $pickup,
        string $reason,
        string $failureType,
        string $negotiationTrigger,
        string $cancellationWithFeeTrigger,
        string $cancellationLastMerchantTrigger,
        string $cancelledBy,
        mixed &$statusChangeEventPayload,
    ): Order {
        $penaltyBaseDeliveryFee = round(max(0.0, (float) $order->delivery_fee), 2);

        $this->markShoppingPickupFailed($order, $pickup, $reason);
        $this->shoppingFailedTripCompensationService->recordFailure(
            $order,
            $pickup,
            (int) $actor->id,
            $reason,
            $failureType,
            $this->shoppingFailedTripCompensationService->isDriverWithinMerchantRadius($order, $pickup),
        );

        $order->refresh()->load(['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt']);
        // Model kuota flat: toko/resto gagal cukup FAILED; tak ada lagi status
        // ABANDONED_AFTER_LIMIT per-rantai. Pembatalan ditangani jalur "semua
        // toko/resto gagal".
        $failedAttemptCount = $this->shoppingPricingService->failedAttemptCount($order);

        $this->shoppingRouteService->applyRouteToOrder(
            $order,
            $penaltyBaseDeliveryFee,
            $failureType
        );

        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => 'FAILED_ATTEMPT_INCREMENT',
            'changed_by_user_id' => $actor->id,
            'note' => $reason,
            'metadata' => [
                'failure_type' => $failureType,
                'failed_attempt_count' => $failedAttemptCount,
                'pickup_location_id' => $pickup->id,
                'fulfillment_status' => 'FAILED',
                'failure_reason' => $reason,
                'failed_at' => now()->toIso8601String(),
                'penalty_base_delivery_fee' => $penaltyBaseDeliveryFee,
            ],
        ]);

        $this->shoppingPriceNegotiationService->record(
            $order,
            $negotiationTrigger,
            $actor->id,
            $reason,
            [
                'pickup_location_id' => $pickup->id,
                'failed_attempt_count' => $failedAttemptCount,
                'status' => 'CANCELLED_MERCHANT',
                'penalty_base_delivery_fee' => $penaltyBaseDeliveryFee,
            ]
        );

        $order = $this->shoppingPricingService->recalculate(
            $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
            $actor->id,
            $failureType,
            false,
            $reason
        );

        $hasActivePickup = $this->shoppingPickupLocationService->hasActivePickupWithAvailableItems($order);
        $withFee = (bool) $this->shoppingFailedTripCompensationService->summary($order)['eligible'];
        if (! $hasActivePickup && ! $this->hasCommittedShoppingMerchant($order)) {
            return $this->cancelShoppingOrderWithOptionalFee(
                $actor,
                $order,
                'Semua merchant Nitip batal/gagal.',
                $withFee,
                $withFee ? $cancellationWithFeeTrigger : $cancellationLastMerchantTrigger,
                $statusChangeEventPayload,
                $cancelledBy,
            );
        }

        return $order->refresh()->load(['statusRef', 'serviceType', 'orderLocations', 'items', 'shoppingReceipt']);
    }

    public function cancelShoppingOrderWithOptionalFee(
        User $actor,
        Order $order,
        string $reason,
        bool $withFee,
        string $recalculationTrigger,
        mixed &$statusChangeEventPayload,
        string $cancelledBy = 'customer',
    ): Order {
        $statusCode = $withFee ? 'CANCELLED_WITH_FEE' : 'CANCELLED';
        $statusId = $this->orderStatusResolver->resolveStatusId($statusCode);
        $previousStatusCode = strtoupper((string) ($order->statusRef->code ?? ''));
        $penalty = 0.0;
        $penaltyBaseDeliveryFee = null;

        if ($withFee) {
            $penaltyBaseDeliveryFee = $this->shoppingPricingService->cancellationPenaltyBaseAmount($order);
            $penalty = $this->shoppingPricingService->calculateCancellationPenalty($order);
            if ($penalty <= 0) {
                throw new ApiException('Penalty pembatalan belum dapat dihitung.', 409);
            }
        }

        $cancelUpdates = $this->orderStatusResolver->cancelledOrderUpdateAttributes($statusCode, $cancelledBy, $reason);
        $order->update($cancelUpdates);
        $this->shoppingUnavailableItemPurger->purgeTerminalShoppingUnavailableItems(
            $order,
            (int) $actor->id,
            $statusCode,
            'TERMINAL_SHOPPING_UNAVAILABLE_ITEMS_PURGED',
        );

        $statusHistory = OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status_id' => $statusId,
            'event_type' => 'STATUS_CHANGE',
            'changed_by_user_id' => $actor->id,
            'note' => $reason,
            'price_snapshot' => [
                'cancellation_penalty' => round($penalty, 2),
                ...($penaltyBaseDeliveryFee !== null ? [
                    'penalty_base_delivery_fee' => round($penaltyBaseDeliveryFee, 2),
                ] : []),
                'source' => $recalculationTrigger,
            ],
        ]);

        $statusChangeEventPayload = $this->orderRealtimeNotifier->buildOrderStatusBroadcastPayload(
            $order->id,
            $statusCode,
            $previousStatusCode,
            $statusHistory
        );

        if ($order->driver_id !== null) {
            $this->driverOrderLifecycleService->syncAvailabilityAfterNonRunningOrder((int) $order->driver_id);
        }

        $order = $this->shoppingPricingService->recalculate(
            $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
            $actor->id,
            $recalculationTrigger,
            false,
            $reason
        );

        if ($withFee) {
            $this->orderPaymentService->setPendingTransferPayment(
                $order->refresh(),
                (float) $order->total_price,
                $recalculationTrigger
            );

            OrderLog::query()->create([
                'order_id' => $order->id,
                'log_type' => 'PAYMENT_UPDATE',
                'trigger_type' => 'SYSTEM_PAYMENT_METHOD_CHANGED_AFTER_FAILED_ATTEMPTS',
                'changed_by_user_id' => $actor->id,
                'note' => 'Sistem membuat pembayaran QRIS untuk fee pembatalan Nitip.',
                'metadata' => [
                    'payment_method' => OrderPaymentService::METHOD_TRANSFER,
                    'amount' => round((float) $order->total_price, 2),
                    'source' => $recalculationTrigger,
                ],
            ]);
        }

        return $order->refresh()->load(['statusRef', 'serviceType', 'orderLocations', 'items', 'shoppingReceipt']);
    }

    private function resolveShoppingNegotiationPickup(Order $order, ?int $pickupLocationId): ?OrderLocation
    {
        $order->loadMissing('orderLocations');
        if ($pickupLocationId === null || $pickupLocationId <= 0) {
            $snapshot = $this->shoppingPriceNegotiationService->snapshot($order);
            $snapshotPickupId = is_array($snapshot) ? (int) ($snapshot['pickup_location_id'] ?? 0) : 0;
            $pickupLocationId = $snapshotPickupId > 0 ? $snapshotPickupId : null;
        }

        $pickup = null;
        if ($pickupLocationId !== null) {
            $pickup = $order->orderLocations->first(
                fn (OrderLocation $location): bool => (int) $location->id === $pickupLocationId
            );
            if (! $pickup || strtoupper((string) $pickup->location_role) !== 'PICKUP') {
                throw new ApiException('Merchant/pickup order tidak valid.', 422);
            }
        }

        if (! $pickup instanceof OrderLocation) {
            $pickup = $order->orderLocations
                ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
                ->first(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP');
        }

        if ($pickup instanceof OrderLocation && strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')) === 'FAILED') {
            throw new ApiException('Merchant ini sudah ditandai Resto tutup/order batal.', 409);
        }

        return $pickup instanceof OrderLocation ? $pickup : null;
    }

    private function markShoppingPickupFailed(Order $order, ?OrderLocation $pickup, string $reason): void
    {
        if (! $pickup instanceof OrderLocation) {
            throw new ApiException('Merchant/pickup order tidak valid.', 422);
        }

        $pickup->update([
            'fulfillment_status' => 'FAILED',
            'failed_attempt_count' => min(255, (int) ($pickup->failed_attempt_count ?? 0) + 1),
        ]);

        $order->items()
            ->where('pickup_location_id', $pickup->id)
            ->get()
            ->each(function (OrderItem $item) use ($reason): void {
                $metadata = is_array($item->metadata) ? $item->metadata : [];
                $metadata['price_status'] = 'UNAVAILABLE';
                $metadata['failure_reason'] = $reason;

                $item->update([
                    'is_available' => false,
                    'unit_price' => 0,
                    'subtotal' => 0,
                    'metadata' => $metadata,
                ]);
            });
    }

    public function hasCommittedShoppingMerchant(Order $order): bool
    {
        $order->loadMissing('orderLocations');

        return $order->orderLocations->contains(function (OrderLocation $location): bool {
            if (strtoupper((string) $location->location_role) !== 'PICKUP') {
                return false;
            }

            return in_array(strtoupper((string) ($location->fulfillment_status ?? '')), [
                'PRICE_APPROVED',
                'COMPLETED',
            ], true);
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, OrderItem>
     */
    private function shoppingItemsForPickup(Order $order, OrderLocation $pickup): \Illuminate\Support\Collection
    {
        $order->loadMissing(['items', 'orderLocations']);
        $firstPickup = $order->orderLocations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
            ->first();

        return $order->items->filter(function (OrderItem $item) use ($order, $pickup, $firstPickup): bool {
            if ($item->pickup_location_id !== null) {
                return (int) $item->pickup_location_id === (int) $pickup->id;
            }

            return $firstPickup instanceof OrderLocation
                && (int) $firstPickup->id === (int) $pickup->id
                && $pickup->restaurant_id !== null
                && (int) $order->restaurant_id === (int) $pickup->restaurant_id;
        })->values();
    }

    public function markShoppingMerchantOpen(User $actor, int $orderId, int $pickupLocationId): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
        $statusChangeEventPayload = null;

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $pickupLocationId, &$statusChangeEventPayload): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt']
            );

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Konfirmasi merchant buka hanya tersedia untuk order SHOPPING.', 409);
            }

            $statusCode = $this->orderStatusResolver->orderStatusCode($order);
            if (! in_array($statusCode, ['DRIVER_ASSIGNED', 'ARRIVED_MERCHANT'], true)) {
                throw new ApiException('Merchant hanya bisa dikonfirmasi buka saat driver menuju atau tiba di merchant.', 409);
            }

            $pickup = $this->shoppingPickupLocationService->pickupById($order, $pickupLocationId);
            $fulfillmentStatus = strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING'));
            if (in_array($fulfillmentStatus, ['FAILED', 'SKIPPED', 'REPLACED', 'COMPLETED', 'ABANDONED_AFTER_LIMIT'], true)) {
                throw new ApiException('Merchant ini sudah selesai atau batal.', 409);
            }

            if ($fulfillmentStatus === 'PENDING') {
                $pickup->update([
                    'fulfillment_status' => 'OPEN_CONFIRMED',
                ]);
            }

            // Checkpoint rute per-titik dihapus: fee tidak lagi mengakumulasi
            // segmen, melainkan memakai jarak customer -> toko/resto terjauh
            // yang dibekukan saat kegagalan.

            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => 'SHOPPING_MERCHANT',
                'trigger_type' => 'MERCHANT_OPEN_CONFIRMED',
                'changed_by_user_id' => $actor->id,
                'note' => 'Driver mengonfirmasi merchant buka.',
                'metadata' => [
                    'pickup_location_id' => (int) $pickup->id,
                    'fulfillment_status' => 'OPEN_CONFIRMED',
                ],
            ]);

            $order = $this->transitionShoppingOrderToArrivedMerchant(
                $order,
                $actor,
                'MERCHANT_OPEN_CONFIRMED',
                (int) $pickup->id,
                'Driver mulai memproses merchant Nitip.',
                $statusChangeEventPayload
            );

            return $order->refresh()->load(['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt']);
        });

        $this->orderRealtimeNotifier->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->orderRealtimeNotifier->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
        $this->orderRealtimeNotifier->broadcastContentUpdatedAfterCommit((int) $order->id, 'SHOPPING_MERCHANT_OPENED', [
            'pickup_location_id' => $pickupLocationId,
        ]);

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function enrichShoppingItemChangeRequestItems(Order $order, array $items): array
    {
        return array_map(function (array $payload) use ($order): array {
            $candidate = $this->shoppingMerchantCandidateResolver->resolve($order, $payload);
            $itemSource = strtoupper((string) ($payload['item_source'] ?? 'MANUAL'));
            $restaurant = $candidate->restaurant;
            $enriched = [
                ...$payload,
                'merchant_name' => $candidate->name,
                'merchant_address' => $candidate->address,
                'merchant_type' => $restaurant instanceof Restaurant
                    ? (string) $restaurant->merchant_type
                    : $this->merchantTypeFromPlaceTypes($candidate->placeTypes),
                'merchant_latitude' => $candidate->latitude,
                'merchant_longitude' => $candidate->longitude,
            ];

            if ($restaurant instanceof Restaurant) {
                $enriched['merchant_id'] = (int) $restaurant->id;
            } elseif ($candidate->placeId !== null && $candidate->placeId !== '') {
                $enriched['merchant_place_id'] = $candidate->placeId;
            }

            if ($itemSource === 'MENU_DB' && $restaurant instanceof Restaurant) {
                $menu = $this->shoppingOrderItemEditService->resolveShoppingMenu($restaurant, $payload);
                $enriched['menu_name'] = (string) $menu->name;
                if ($menu->price === null) {
                    $enriched['item_source'] = 'MANUAL';
                    $enriched['menu_id'] = null;
                    $enriched['unit_price'] = 0;
                    $enriched['metadata'] = [
                        ...$candidate->metadata(),
                        'price_status' => 'PENDING_DRIVER_INPUT',
                        'source' => 'CUSTOMER_MENU_DB_PENDING_PRICE',
                        'catalog_menu_id' => (int) $menu->id,
                    ];
                } else {
                    $enriched['menu_id'] = (int) $menu->id;
                    $enriched['unit_price'] = round((float) $menu->price, 2);
                }
            }

            if ($itemSource !== 'MENU_DB') {
                $menuName = trim((string) ($payload['menu_name'] ?? ''));
                if ($menuName !== '') {
                    $enriched['menu_name'] = $menuName;
                }
            }

            return $enriched;
        }, $items);
    }

    /**
     * @param  array<int, string>  $placeTypes
     */
    private function merchantTypeFromPlaceTypes(array $placeTypes): string
    {
        $normalized = collect($placeTypes)
            ->map(fn (mixed $type): string => strtolower(trim((string) $type)))
            ->filter()
            ->values();

        if ($normalized->contains(fn (string $type): bool => in_array($type, ['convenience_store', 'supermarket', 'grocery_or_supermarket'], true))) {
            return 'convenience_store';
        }

        if ($normalized->contains(fn (string $type): bool => in_array($type, ['restaurant', 'food', 'meal_takeaway', 'cafe'], true))) {
            return 'restaurant';
        }

        return 'other';
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, int>
     */
    private function affectedPickupIdsForApprovedShoppingChange(
        Order $order,
        string $action,
        array $items,
        ?int $itemId,
        ?int $targetPickupLocationId,
    ): array {
        $affected = [];

        if ($targetPickupLocationId !== null) {
            $affected[$targetPickupLocationId] = $targetPickupLocationId;
        }

        if ($action === 'ADD') {
            foreach ($items as $payload) {
                $candidate = $this->shoppingMerchantCandidateResolver->resolve($order, $payload);
                $pickup = $this->shoppingPickupLocationService->resolveForCandidate($order, $candidate);
                if ($pickup instanceof OrderLocation) {
                    $affected[(int) $pickup->id] = (int) $pickup->id;
                }
            }
        }

        if (in_array($action, ['UPDATE', 'REMOVE'], true) && $itemId !== null) {
            $item = $order->items->first(fn (OrderItem $item): bool => (int) $item->id === $itemId);
            if ($item instanceof OrderItem && $item->pickup_location_id !== null) {
                $affected[(int) $item->pickup_location_id] = (int) $item->pickup_location_id;
            }
        }

        return array_values($affected);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function applyApprovedShoppingItemChange(
        Order $order,
        User $actor,
        string $action,
        array $items,
        ?int $itemId,
        ?int $targetPickupLocationId = null,
    ): void {
        if ($action === 'ADD') {
            $this->shoppingOrderItemEditService->applyShoppingItemAdditions(
                $order,
                $items,
                allowNewMerchant: false
            );

            if ($targetPickupLocationId !== null) {
                $this->removeUnavailableShoppingItemsForPickup($order, $targetPickupLocationId);
            }

            return;
        }

        if ($action === 'UPDATE') {
            $item = $order->items()->whereKey($itemId)->lockForUpdate()->first();
            if (! $item) {
                throw new ApiException('Item order tidak ditemukan.', 404);
            }

            $payload = $items[0] ?? [];
            $quantity = isset($payload['quantity']) ? max(1, (int) $payload['quantity']) : (int) $item->quantity;
            $menuName = (string) $item->menu_name;
            if (strtoupper((string) $item->item_source) === 'MANUAL' && array_key_exists('menu_name', $payload)) {
                $menuName = trim((string) $payload['menu_name']);
            }

            $item->update([
                'menu_name' => $menuName !== '' ? $menuName : $item->menu_name,
                'quantity' => $quantity,
                'subtotal' => round((float) $item->unit_price * $quantity, 2),
                'notes' => array_key_exists('notes', $payload) ? ($payload['notes'] !== null ? (string) $payload['notes'] : null) : $item->notes,
            ]);

            return;
        }

        if ($action === 'REMOVE') {
            $item = $order->items()->whereKey($itemId)->lockForUpdate()->first();
            if (! $item) {
                throw new ApiException('Item order tidak ditemukan.', 404);
            }
            if ($order->items()->count() <= 1) {
                throw new ApiException('Order belanja harus memiliki minimal satu item.', 409);
            }

            $item->delete();
        }
    }

    private function removeUnavailableShoppingItemsForPickup(Order $order, int $pickupLocationId): void
    {
        $order->items()
            ->where('pickup_location_id', $pickupLocationId)
            ->where('is_available', false)
            ->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function unavailableShoppingItemSnapshotsForPickup(Order $order, int $pickupLocationId): array
    {
        return $order->items()
            ->where('pickup_location_id', $pickupLocationId)
            ->where('is_available', false)
            ->lockForUpdate()
            ->get()
            ->map(fn (OrderItem $item): array => $this->shoppingUnavailableItemPurger->shoppingUnavailableItemAuditSnapshot($item))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     */
    public function deleteShoppingItemsBySnapshots(Order $order, array $snapshots): void
    {
        $itemIds = collect($snapshots)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        if ($itemIds === []) {
            return;
        }

        $order->items()->whereIn('id', $itemIds)->delete();
        $order->unsetRelation('items');
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function shoppingUnavailableItemReplacementFingerprint(array $items): string
    {
        $normalized = collect($items)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'item_source' => strtoupper(trim((string) ($item['item_source'] ?? 'MANUAL'))),
                'menu_id' => isset($item['menu_id']) && is_numeric($item['menu_id'])
                    ? (int) $item['menu_id']
                    : null,
                'menu_name' => trim((string) ($item['menu_name'] ?? '')),
                'quantity' => max(1, min(99, (int) ($item['quantity'] ?? 1))),
                'notes' => trim((string) ($item['notes'] ?? '')) ?: null,
            ])
            ->values()
            ->all();

        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{status_id:int,cancelled_by:string,cancellation_reason:string,cancelled_at:\Illuminate\Support\Carbon}
     */
}
