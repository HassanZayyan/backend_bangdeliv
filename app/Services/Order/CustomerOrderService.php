<?php

namespace App\Services\Order;

use App\Events\AdminNotificationUpdated;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Admin\AdminNotificationService;
use App\Services\Driver\DriverOrderLifecycleService;
use App\Services\Driver\DriverOrderRealtimeService;
use App\Services\Notification\PaymentProofReminderNotificationService;
use App\Services\Notification\ShoppingMerchantFailurePushNotificationService;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingFailedTripCompensationService;
use App\Services\Shopping\ShoppingPickupLocationService;
use App\Services\Shopping\ShoppingReplacementProjectionService;
use App\Services\Shopping\ShoppingRouteService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class CustomerOrderService
{
    public function __construct(
        private readonly PaymentProofReminderNotificationService $paymentProofReminderNotificationService,
        private readonly ShoppingMerchantFailurePushNotificationService $shoppingMerchantFailurePushNotificationService,
        private readonly ShoppingPricingService $shoppingPricingService,
        private readonly ShoppingRouteService $shoppingRouteService,
        private readonly DriverOrderLifecycleService $driverOrderLifecycleService,
        private readonly DriverOrderRealtimeService $driverOrderRealtimeService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderTransferEvidenceService $transferEvidenceService,
        private readonly OrderEvidenceService $orderEvidenceService,
        private readonly ShoppingPickupLocationService $shoppingPickupLocationService,
        private readonly ShoppingFailedTripCompensationService $shoppingFailedTripCompensationService,
        private readonly ShoppingReplacementProjectionService $shoppingReplacementProjectionService,
        private readonly DriverArrivalEtaService $driverArrivalEtaService,
        private readonly OrderStatusResolver $orderStatusResolver,
        private readonly OrderRealtimeNotifier $orderRealtimeNotifier,
        private readonly ShoppingUnavailableItemPurger $shoppingUnavailableItemPurger,
        private readonly ShoppingNegotiationOrchestrator $shoppingNegotiationOrchestrator,
        private readonly DriverOrderWorkflowService $driverOrderWorkflowService
    ) {}

    /**
     * @var array<int, string>
     */
    private array $failedAttemptRecordableStatuses = ['PENDING', 'DRIVER_ASSIGNED', 'ARRIVED_MERCHANT', 'PICKED_UP', 'ON_THE_WAY'];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateCustomerOrders(User $user, array $filters): LengthAwarePaginator
    {
        $perPage = isset($filters['per_page']) ? min((int) $filters['per_page'], 50) : 10;

        $query = Order::query()
            ->where('user_id', $user->id)
            ->with(['restaurant', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'serviceType', 'courierOrder', 'shoppingReceipt'])
            ->withExists([
                'statusHistories as was_cancelled_with_fee' => fn ($historyQuery) => $historyQuery
                    ->whereHas('statusRef', fn ($statusQuery) => $statusQuery->where('code', 'CANCELLED_WITH_FEE')),
            ])
            ->latest('id');

        if (! empty($filters['status'])) {
            $query->where('status_id', $this->orderStatusResolver->resolveStatusId((string) $filters['status']));
        }

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->each(fn (Order $order) => $this->markCancelledWithFeeOutcome($order));

        return $paginator;
    }

    public function customerOrderDetail(User $user, int $orderId): Order
    {
        $order = Order::query()
            ->with(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'shoppingReceipt'])
            ->find($orderId);

        if (! $order || $order->user_id !== $user->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        $freshOrder = $this->driverOrderWorkflowService->ensureDisplayRoutePolyline($order)
            ->fresh(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'shoppingReceipt']);

        if (! $freshOrder instanceof Order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        $freshOrder->setAttribute('driver_eta', $this->driverArrivalEtaService->forCustomerTracking($freshOrder));
        $this->markCancelledWithFeeOutcome($freshOrder);
        $driverAvatarUrl = $this->resolveAvatarUrl($freshOrder->driver?->user?->avatar);
        $freshOrder->setAttribute('driver_avatar_url', $driverAvatarUrl);
        $freshOrder->driver?->user?->setAttribute('avatar_url', $driverAvatarUrl);

        return $freshOrder;
    }

    private function markCancelledWithFeeOutcome(Order $order): void
    {
        $currentStatusCode = strtoupper((string) ($order->statusRef?->code ?? ''));
        $wasCancelledWithFee = $currentStatusCode === 'CANCELLED_WITH_FEE'
            || (bool) $order->getAttribute('was_cancelled_with_fee');

        if (! $wasCancelledWithFee && $order->relationLoaded('statusHistories')) {
            $wasCancelledWithFee = $order->statusHistories->contains(
                fn (OrderStatusHistory $history): bool => strtoupper((string) ($history->statusRef?->code ?? '')) === 'CANCELLED_WITH_FEE'
            );
        }

        $order->setAttribute('was_cancelled_with_fee', $wasCancelledWithFee);
    }

    private function resolveAvatarUrl(?string $avatar): ?string
    {
        $avatar = trim((string) ($avatar ?? ''));
        if ($avatar === '') {
            return null;
        }

        if (str_starts_with($avatar, 'http://') || str_starts_with($avatar, 'https://')) {
            return $avatar;
        }

        $path = ltrim($avatar, '/');
        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function cancelByCustomer(User $user, int $orderId, string $reason): Order
    {
        $shouldBroadcastDriverOrderRemoved = false;

        $order = DB::transaction(function () use ($user, $orderId, $reason, &$shouldBroadcastDriverOrderRemoved): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'orderLocations'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order || $order->user_id !== $user->id) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            $activeStatusCode = $order->statusRef?->code;
            if (! in_array($activeStatusCode, ['PENDING', 'DRIVER_ASSIGNED'], true)) {
                throw new ApiException('Order tidak bisa dibatalkan pada status saat ini.', 409);
            }

            $assignedDriverId = $order->driver_id !== null ? (int) $order->driver_id : null;
            $shouldBroadcastDriverOrderRemoved = $activeStatusCode === 'PENDING' && $assignedDriverId === null;
            $isShopping = ($order->serviceType->code ?? null) === 'SHOPPING';
            $cancellationPenalty = 0.0;
            $cancelledStatusCode = 'CANCELLED';

            if ($isShopping) {
                $cancellationPenalty = $this->shoppingPricingService->calculateCancellationPenalty($order);

                if ($cancellationPenalty > 0) {
                    $cancelledStatusCode = 'CANCELLED_WITH_FEE';
                }
            }

            $cancelledStatusId = $this->orderStatusResolver->resolveStatusId($cancelledStatusCode);
            $cancelUpdates = $this->orderStatusResolver->cancelledOrderUpdateAttributes($cancelledStatusCode, 'customer', $reason);
            if ($isShopping) {
            }
            $order->update($cancelUpdates);
            $this->shoppingUnavailableItemPurger->purgeTerminalShoppingUnavailableItems(
                $order,
                (int) $user->id,
                $cancelledStatusCode,
                'CUSTOMER_CANCELLED_SHOPPING_ORDER',
            );

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $cancelledStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $user->id,
                'note' => $reason,
                'price_snapshot' => [
                    'cancellation_penalty' => round($cancellationPenalty, 2),
                ],
            ]);

            if ($assignedDriverId !== null) {
                $this->driverOrderLifecycleService->syncAvailabilityAfterNonRunningOrder($assignedDriverId);
            }

            if ($isShopping) {
                $order = $this->shoppingPricingService->recalculate(
                    $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                    $user->id,
                    $cancellationPenalty > 0 ? 'CUSTOMER_CANCEL_WITH_FEE' : 'CUSTOMER_CANCEL',
                    false
                );
            }

            return $order->refresh()->load(['restaurant', 'items', 'statusRef', 'statusHistories.statusRef', 'shoppingReceipt']);
        });

        if ($shouldBroadcastDriverOrderRemoved) {
            $this->driverOrderRealtimeService->broadcastOrderRemoved($order->id, 'cancelled');
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updatePaymentMethodByCustomer(User $user, int $orderId, array $payload): Order
    {
        $order = Order::query()
            ->whereKey($orderId)
            ->where('user_id', $user->id)
            ->first();

        if (! $order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        throw new ApiException('Metode pembayaran sudah dikunci saat order dibuat dan tidak bisa diubah.', 409);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function uploadTransferEvidenceByCustomer(User $user, int $orderId, array $payload): Order
    {
        $order = DB::transaction(function () use ($user, $orderId, $payload): Order {
            $order = Order::query()
                ->with(['payments', 'statusRef', 'serviceType'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order || (int) $order->user_id !== (int) $user->id) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if ($this->orderPaymentService->isPaid($order)) {
                throw new ApiException('Pembayaran order sudah lunas.', 409);
            }

            $photo = $payload['photo'] ?? null;
            if (! $photo instanceof UploadedFile) {
                throw new ApiException('Foto bukti QRIS wajib diupload.', 422);
            }

            $paymentMethod = $this->orderPaymentService->currentMethod($order);
            $isCancelledWithFeeShopping =
                strtoupper((string) ($order->serviceType->code ?? '')) === 'SHOPPING' &&
                strtoupper((string) ($order->statusRef->code ?? '')) === 'CANCELLED_WITH_FEE';

            if ($paymentMethod !== OrderPaymentService::METHOD_TRANSFER && ! $isCancelledWithFeeShopping) {
                throw new ApiException('Bukti QRIS hanya bisa diupload untuk order dengan metode pembayaran QRIS.', 409);
            }

            if ($isCancelledWithFeeShopping && $paymentMethod !== OrderPaymentService::METHOD_TRANSFER) {
                $this->orderPaymentService->setPendingTransferPayment(
                    $order,
                    (float) $order->total_price,
                    'SHOPPING_CANCEL_WITH_FEE_TRANSFER_EVIDENCE'
                );
            } else {
                $this->orderPaymentService->ensurePendingPayment($order, OrderPaymentService::METHOD_TRANSFER);
            }

            $this->transferEvidenceService->storeAndRecord(
                $order,
                $photo,
                (int) $user->id,
                $payload['note'] ?? 'Bukti QRIS dari customer.'
            );

            OrderLog::query()->create([
                'order_id' => $order->id,
                'log_type' => 'PAYMENT_UPDATE',
                'trigger_type' => 'CUSTOMER_TRANSFER_EVIDENCE_UPLOADED',
                'changed_by_user_id' => $user->id,
                'note' => 'Customer upload bukti QRIS.',
                'metadata' => [
                    'payment_method' => OrderPaymentService::METHOD_TRANSFER,
                    'payment_proof_status' => 'PENDING',
                ],
            ]);

            return $order->refresh();
        });

        $this->orderRealtimeNotifier->broadcastContentUpdatedAfterCommit((int) $order->id, 'TRANSFER_EVIDENCE_UPLOADED', [
            'total_price' => round((float) $order->total_price, 2),
            'payment_method' => OrderPaymentService::METHOD_TRANSFER,
            'payment_status' => 'unpaid',
        ]);
        broadcast(new AdminNotificationUpdated(app(AdminNotificationService::class)->summary()));

        return $order->fresh([
            'restaurant',
            'driver.user',
            'items',
            'orderLocations.restaurant',
            'payments',
            'evidences',
            'statusRef',
            'statusHistories.statusRef',
            'serviceType',
            'courierOrder',
            'shoppingReceipt',
        ]);
    }

    public function recordFailedAttempt(
        User $actor,
        int $orderId,
        string $failureType,
        string $reason,
        ?int $pickupLocationId = null,
        ?UploadedFile $merchantClosedPhoto = null,
    ): Order {
        $normalizedFailureType = strtoupper(trim($failureType));
        $allowedFailureTypes = ['DRIVER_ASSIGNMENT', 'PICKUP', 'DELIVERY'];
        $statusChangeEventPayload = null;

        if (! in_array($normalizedFailureType, $allowedFailureTypes, true)) {
            throw new ApiException('Tipe kegagalan tidak valid.', 422);
        }

        if (! in_array($actor->role, ['driver', 'admin'], true)) {
            throw new ApiException('Hanya driver atau admin yang dapat mencatat failed attempt.', 403);
        }

        $merchantClosedNotification = null;

        $order = DB::transaction(function () use ($actor, $orderId, $normalizedFailureType, $reason, $pickupLocationId, $merchantClosedPhoto, &$statusChangeEventPayload, &$merchantClosedNotification): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'orderLocations', 'items', 'shoppingReceipt'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Percobaan gagal hanya berlaku untuk order Nitip.', 409);
            }

            $pickup = null;
            if ($pickupLocationId !== null) {
                $pickup = $order->orderLocations
                    ->first(fn (OrderLocation $location): bool => (int) $location->id === $pickupLocationId);

                if (! $pickup || strtoupper((string) $pickup->location_role) !== 'PICKUP') {
                    throw new ApiException('Merchant/pickup order tidak valid.', 422);
                }
            } else {
                $pickup = $order->orderLocations
                    ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
                    ->first(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP');
            }

            if ($pickup !== null && in_array(strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')), ['FAILED', 'SKIPPED', 'REPLACED', 'COMPLETED', 'ABANDONED_AFTER_LIMIT'], true)) {
                throw new ApiException('Merchant ini sudah selesai atau ditandai tutup/gagal pickup.', 409);
            }

            $activeStatusCode = $this->orderStatusResolver->orderStatusCode($order);
            if (! in_array($activeStatusCode, $this->failedAttemptRecordableStatuses, true)) {
                throw new ApiException('Percobaan gagal tidak bisa dicatat pada status order saat ini.', 409);
            }

            if ($actor->role === 'driver') {
                if ($normalizedFailureType === 'DRIVER_ASSIGNMENT') {
                    throw new ApiException('Driver tidak dapat mencatat kegagalan assignment.', 403);
                }

                $driver = Driver::query()->where('user_id', $actor->id)->first();
                if (! $driver) {
                    throw new ApiException('Profil driver tidak ditemukan.', 403);
                }

                if ((int) $order->driver_id !== (int) $driver->id) {
                    throw new ApiException('Order ini tidak ditugaskan kepada driver saat ini.', 403);
                }
            }

            $penaltyBaseDeliveryFee = round(max(0.0, (float) $order->delivery_fee), 2);

            if ($pickup !== null) {
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

                $evidence = null;
                if ($merchantClosedPhoto instanceof UploadedFile) {
                    $evidence = $this->orderEvidenceService->storeAndRecordDriverEvidence(
                        $order,
                        $merchantClosedPhoto,
                        (int) $actor->id,
                        'STORE_CLOSED_PHOTO',
                        'store-closed',
                        $reason,
                    );
                }
                $verifiedForCompensation = $normalizedFailureType === 'PICKUP'
                    && $evidence !== null
                    && $this->shoppingFailedTripCompensationService->isDriverWithinMerchantRadius($order, $pickup);
                $failedTripEvent = $this->shoppingFailedTripCompensationService->recordFailure(
                    $order,
                    $pickup,
                    (int) $actor->id,
                    $reason,
                    'MERCHANT_CLOSED',
                    $verifiedForCompensation,
                    $evidence?->id,
                );

                $order->unsetRelation('orderLocations');
                $order->load('orderLocations');
                $chain = $this->shoppingReplacementProjectionService->forPickup($order, (int) $pickup->id);
                if ((int) $chain['chain_failed_attempt_count'] >= ShoppingReplacementProjectionService::MAX_FAILURES_PER_CHAIN) {
                    $removedItems = $this->shoppingNegotiationOrchestrator->unavailableShoppingItemSnapshotsForPickup($order, (int) $pickup->id);
                    $pickup->update(['fulfillment_status' => 'ABANDONED_AFTER_LIMIT']);
                    OrderLog::query()->create([
                        'order_id' => $order->id,
                        'event_type' => ShoppingReplacementProjectionService::CHAIN_ABANDONED_EVENT,
                        'trigger_type' => 'SHOPPING_REPLACEMENT_CHAIN_LIMIT_REACHED',
                        'changed_by_user_id' => $actor->id,
                        'note' => 'Rantai merchant dihentikan setelah tiga kegagalan.',
                        'metadata' => [
                            'chain_id' => $chain['chain_id'],
                            'pickup_location_id' => (int) $pickup->id,
                            'failed_attempt_count' => (int) $chain['chain_failed_attempt_count'],
                            'removed_items' => $removedItems,
                        ],
                    ]);
                    $this->shoppingNegotiationOrchestrator->deleteShoppingItemsBySnapshots($order, $removedItems);
                }
            }

            $order->refresh()->load(['orderLocations.restaurant', 'items', 'shoppingReceipt']);
            $nextFailedAttemptCount = $this->shoppingPricingService->failedAttemptCount($order);

            $this->shoppingRouteService->applyRouteToOrder(
                $order,
                $penaltyBaseDeliveryFee,
                'FAILED_ATTEMPT_'.$normalizedFailureType
            );

            $pickupLocationIdForAudit = $pickup instanceof OrderLocation
                ? (int) $pickup->id
                : $pickupLocationId;

            OrderLog::query()->create([
                'order_id' => $order->id,
                'log_type' => 'SYSTEM_EVENT',
                'trigger_type' => 'FAILED_ATTEMPT_'.$normalizedFailureType,
                'changed_by_user_id' => $actor->id,
                'note' => $reason,
                'metadata' => [
                    'failure_type' => $normalizedFailureType,
                    'failed_attempt_count' => $nextFailedAttemptCount,
                    'recalculation_version' => $this->shoppingPricingService->latestRecalculationVersion($order),
                    'actor_role' => $actor->role,
                    'pickup_location_id' => $pickupLocationIdForAudit,
                    'fulfillment_status' => $pickup !== null ? 'FAILED' : null,
                    'failure_reason' => $reason,
                    'failed_at' => now()->toIso8601String(),
                    'penalty_base_delivery_fee' => $penaltyBaseDeliveryFee,
                ],
            ]);

            // Notifikasi harga generik dibungkam di sini: peristiwanya sudah
            // dikabarkan lewat satu notifikasi yang memuat nama resto sekaligus
            // total barunya, jadi customer tidak menerima dua pesan beruntun.
            $recalculated = $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                $actor->id,
                'SHOPPING_FAILED_ATTEMPT',
                false,
                $reason,
                notifyTotalChanged: $pickup === null,
            );

            if ($pickup !== null && isset($failedTripEvent)) {
                $chainAfterFailure = $this->shoppingReplacementProjectionService->forPickup(
                    $recalculated,
                    (int) $pickup->id
                );
                $merchantClosedNotification = [
                    'pickup_location_id' => (int) $pickup->id,
                    'new_total_price' => round((float) $recalculated->total_price, 2),
                    'remaining_attempts' => max(0, (int) $chainAfterFailure['chain_failed_attempt_limit']
                        - (int) $chainAfterFailure['chain_failed_attempt_count']),
                    'event_id' => (int) $failedTripEvent->id,
                ];
            }

            // Replacement is exposed in ARRIVED_MERCHANT. Transition before
            // the early return that keeps the failed chain open for a direct
            // customer/driver replacement decision.
            if ($pickup !== null && $activeStatusCode === 'DRIVER_ASSIGNED') {
                $recalculated = $this->shoppingNegotiationOrchestrator->transitionShoppingOrderToArrivedMerchant(
                    $recalculated,
                    $actor,
                    'MERCHANT_CLOSED_CONFIRMED',
                    (int) $pickup->id,
                    'Driver menandai merchant tutup dan mulai memproses order Nitip.',
                    $statusChangeEventPayload
                );
            }

            if ($pickup !== null && ! $this->shoppingPickupLocationService->hasActivePickupWithAvailableItems($recalculated)) {
                $latestProjection = $this->shoppingReplacementProjectionService->forPickup($recalculated, (int) $pickup->id);
                $hasReplacementOption = (bool) ($latestProjection['can_replace_merchant'] ?? false);
                if ($hasReplacementOption || $this->shoppingNegotiationOrchestrator->hasCommittedShoppingMerchant($recalculated)) {
                    return $recalculated;
                }
                // Semua merchant gagal/tutup: tagih penalti pembatalan sesuai
                // nominal terpusat, yang sudah mendahulukan manual pricing ->
                // kompensasi trip gagal -> aturan 50% (>=3 percobaan gagal).
                // Sebelumnya hanya kompensasi trip yang dicek, sehingga order
                // yang sudah 3x gagal batal tanpa fee dan tidak bisa
                // diselesaikan driver. Nilai > 0 juga menjamin
                // cancelShoppingOrderWithOptionalFee() tidak melempar 409.
                $withFee = $this->shoppingPricingService->calculateCancellationPenalty($recalculated) > 0;

                return $this->shoppingNegotiationOrchestrator->cancelShoppingOrderWithOptionalFee(
                    $actor,
                    $recalculated,
                    'Semua merchant Nitip gagal/tutup.',
                    $withFee,
                    $withFee ? 'ALL_SHOPPING_MERCHANTS_FAILED_WITH_FEE' : 'ALL_SHOPPING_MERCHANTS_FAILED',
                    $statusChangeEventPayload,
                    $actor->role === 'driver' ? 'driver' : 'admin'
                );
            }

            return $recalculated;
        });

        $this->orderRealtimeNotifier->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->orderRealtimeNotifier->sendOrderStatusPushNotification($order, $statusChangeEventPayload);

        if (is_array($merchantClosedNotification)) {
            $this->shoppingMerchantFailurePushNotificationService->sendMerchantClosed(
                $order,
                $merchantClosedNotification['pickup_location_id'],
                $merchantClosedNotification['new_total_price'],
                $merchantClosedNotification['remaining_attempts'],
                $merchantClosedNotification['event_id'],
            );
        }

        $this->paymentProofReminderNotificationService->scheduleForBlockingPaymentStatus($order->refresh());

        return $order;
    }

    /**
     * @return array{status_id:int,cancelled_by:string,cancellation_reason:string,cancelled_at:\Illuminate\Support\Carbon}
     */
}
