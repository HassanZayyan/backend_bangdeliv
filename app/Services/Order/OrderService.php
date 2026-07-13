<?php

namespace App\Services\Order;

use App\Enums\OrderStatusCode;
use App\Events\AdminNotificationUpdated;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderItem;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Admin\AdminNotificationService;
use App\Services\Admin\AdminPaymentProofStatusService;
use App\Services\Driver\Dispatch\DriverCandidateSelector;
use App\Services\Driver\DriverIncomeFeeCalculator;
use App\Services\Driver\DriverOrderPayloadFactory;
use App\Services\Driver\DriverOrderRealtimeService;
use App\Services\Notification\OrderPricingPushNotificationService;
use App\Services\Notification\OrderRealtimeBroadcaster;
use App\Services\Notification\OrderStatusPushNotificationService;
use App\Services\Notification\PaymentProofReminderNotificationService;
use App\Services\Notification\ShoppingItemAvailabilityPushNotificationService;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingFailedTripCompensationService;
use App\Services\Shopping\ShoppingItemChangeRequestService;
use App\Services\Shopping\ShoppingMerchantCandidate;
use App\Services\Shopping\ShoppingMerchantCandidateResolver;
use App\Services\Shopping\ShoppingOrderCapabilityService;
use App\Services\Shopping\ShoppingPickupLocationService;
use App\Services\Shopping\ShoppingPriceNegotiationService;
use App\Services\Shopping\ShoppingReplacementProjectionService;
use App\Services\Shopping\ShoppingRouteService;
use App\Services\Shopping\ShoppingUnavailableItemDecisionService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OrderService
{
    public function __construct(
        private readonly OrderRealtimeBroadcaster $realtimeBroadcaster,
        private readonly OrderPricingPushNotificationService $orderPricingPushNotificationService,
        private readonly PaymentProofReminderNotificationService $paymentProofReminderNotificationService,
        private readonly ShoppingPricingService $shoppingPricingService,
        private readonly ShoppingRouteService $shoppingRouteService,
        private readonly DriverOrderPayloadFactory $driverOrderPayloadFactory,
        private readonly DriverIncomeFeeCalculator $driverIncomeFeeCalculator,
        private readonly DriverOrderRealtimeService $driverOrderRealtimeService,
        private readonly DriverCandidateSelector $driverCandidateSelector,
        private readonly OrderStatusPushNotificationService $orderStatusPushNotificationService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderTransferEvidenceService $transferEvidenceService,
        private readonly OrderEvidenceService $orderEvidenceService,
        private readonly OrderProofPolicyService $proofPolicyService,
        private readonly ShoppingMerchantCandidateResolver $shoppingMerchantCandidateResolver,
        private readonly ShoppingPickupLocationService $shoppingPickupLocationService,
        private readonly ShoppingPriceNegotiationService $shoppingPriceNegotiationService,
        private readonly ShoppingItemChangeRequestService $shoppingItemChangeRequestService,
        private readonly ShoppingOrderCapabilityService $shoppingOrderCapabilityService,
        private readonly ShoppingUnavailableItemDecisionService $shoppingUnavailableItemDecisionService,
        private readonly ShoppingItemAvailabilityPushNotificationService $shoppingItemAvailabilityPushNotificationService,
        private readonly ShoppingFailedTripCompensationService $shoppingFailedTripCompensationService,
        private readonly ShoppingReplacementProjectionService $shoppingReplacementProjectionService,
        private readonly DeliveryFeeNegotiationService $deliveryFeeNegotiationService,
        private readonly DriverArrivalEtaService $driverArrivalEtaService
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
            ->latest('id');

        if (! empty($filters['status'])) {
            $query->where('status_id', $this->resolveStatusId((string) $filters['status']));
        }

        return $query->paginate($perPage);
    }

    public function customerOrderDetail(User $user, int $orderId): Order
    {
        $order = Order::query()
            ->with(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'shoppingReceipt'])
            ->find($orderId);

        if (! $order || $order->user_id !== $user->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        $freshOrder = $this->ensureDisplayRoutePolyline($order)
            ->fresh(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'shoppingReceipt']);

        if (! $freshOrder instanceof Order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        $freshOrder->setAttribute('driver_eta', $this->driverArrivalEtaService->forCustomerTracking($freshOrder));
        $driverAvatarUrl = $this->resolveAvatarUrl($freshOrder->driver?->user?->avatar);
        $freshOrder->setAttribute('driver_avatar_url', $driverAvatarUrl);
        $freshOrder->driver?->user?->setAttribute('avatar_url', $driverAvatarUrl);

        return $freshOrder;
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

            $cancelledStatusId = $this->resolveStatusId($cancelledStatusCode);
            $cancelUpdates = $this->cancelledOrderUpdateAttributes($cancelledStatusCode, 'customer', $reason);
            if ($isShopping) {
            }
            $order->update($cancelUpdates);
            $this->purgeTerminalShoppingUnavailableItems(
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
                $this->syncDriverAvailabilityAfterNonRunningOrder($assignedDriverId);
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

        $this->broadcastContentUpdatedAfterCommit((int) $order->id, 'TRANSFER_EVIDENCE_UPLOADED', [
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

        $order = DB::transaction(function () use ($actor, $orderId, $normalizedFailureType, $reason, $pickupLocationId, $merchantClosedPhoto, &$statusChangeEventPayload): Order {
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

            $activeStatusCode = $this->orderStatusCode($order);
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
                $this->shoppingFailedTripCompensationService->recordFailure(
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
                    $removedItems = $this->unavailableShoppingItemSnapshotsForPickup($order, (int) $pickup->id);
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
                    $this->deleteShoppingItemsBySnapshots($order, $removedItems);
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

            $recalculated = $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                $actor->id,
                'SHOPPING_FAILED_ATTEMPT',
                false,
                $reason
            );

            // Replacement is exposed in ARRIVED_MERCHANT. Transition before
            // the early return that keeps the failed chain open for a direct
            // customer/driver replacement decision.
            if ($pickup !== null && $activeStatusCode === 'DRIVER_ASSIGNED') {
                $recalculated = $this->transitionShoppingOrderToArrivedMerchant(
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
                if ($hasReplacementOption || $this->hasCommittedShoppingMerchant($recalculated)) {
                    return $recalculated;
                }
                $withFee = $this->shoppingFailedTripCompensationService->summary($recalculated)['eligible'];

                return $this->cancelShoppingOrderWithOptionalFee(
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

        $this->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
        $this->paymentProofReminderNotificationService->scheduleForBlockingPaymentStatus($order->refresh());

        return $order;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordCodPaymentByDriver(User $actor, int $orderId, array $payload): Order
    {
        if ($actor->role !== 'driver') {
            throw new ApiException('Hanya driver yang dapat mencatat pembayaran COD di endpoint ini.', 403);
        }

        return $this->recordCodPayment($actor, $orderId, $payload, true);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function codSettlementReport(User $actor, array $filters): array
    {
        if ($actor->role !== 'admin') {
            throw new ApiException('Hanya admin yang dapat melihat laporan settlement COD.', 403);
        }

        $query = OrderPayment::query()
            ->with(['order', 'driver.user', 'recordedBy'])
            ->where('payment_method', 'COD')
            ->where('payment_status', 'PAID');

        if (! empty($filters['driver_id'])) {
            $query->where('driver_id', (int) $filters['driver_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('paid_at', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('paid_at', '<=', (string) $filters['date_to']);
        }

        $payments = $query->latest('paid_at')->get();

        $byDriver = $payments
            ->groupBy('driver_id')
            ->map(function ($rows, $driverId): array {
                $first = $rows->first();

                return [
                    'driver_id' => $driverId ? (int) $driverId : null,
                    'driver_name' => $first?->driver?->user?->name,
                    'payment_count' => $rows->count(),
                    'total_collected' => round((float) $rows->sum('amount'), 2),
                ];
            })
            ->values()
            ->all();

        return [
            'summary' => [
                'payment_count' => $payments->count(),
                'total_collected' => round((float) $payments->sum('amount'), 2),
            ],
            'by_driver' => $byDriver,
            'payments' => $payments->map(function (OrderPayment $payment): array {
                return [
                    'id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'order_number' => $payment->order->order_number,
                    'driver_id' => $payment->driver_id,
                    'driver_name' => $payment->driver?->user?->name,
                    'amount' => (float) $payment->amount,
                    'paid_at' => $payment->paid_at,
                    'recorded_by_user_id' => $payment->recorded_by_user_id,
                    'recorded_by_name' => $payment->recordedBy?->name,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function driverAvailability(User $actor): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);
        $hasRunningOrder = $this->hasRunningDriverOrder($driver->id);

        return $this->serializeDriverAvailability($driver, $hasRunningOrder);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateDriverAvailability(User $actor, bool $isOnline): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        return DB::transaction(function () use ($driver, $isOnline): array {
            $lockedDriver = Driver::query()->lockForUpdate()->find($driver->id);
            if (! $lockedDriver) {
                throw new ApiException('Profil driver tidak ditemukan.', 404);
            }

            $hasRunningOrder = $this->hasRunningDriverOrder($lockedDriver->id);

            if (! $isOnline && $hasRunningOrder) {
                throw new ApiException('Tidak bisa offline saat masih ada order berjalan.', 409);
            }

            $targetStatus = $isOnline
                ? ($hasRunningOrder ? 'busy' : 'available')
                : 'offline';

            if ((string) $lockedDriver->status !== $targetStatus) {
                $lockedDriver->update([
                    'status' => $targetStatus,
                ]);
            }

            $lockedDriver->refresh();

            return $this->serializeDriverAvailability($lockedDriver, $hasRunningOrder);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateCurrentDriverLocation(User $actor, array $payload): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        if (! $this->isDriverAvailableForIncomingOrders($driver)) {
            throw new ApiException('Aktifkan status kerja sebelum mengirim lokasi standby.', 409);
        }

        $latitude = round((float) $payload['latitude'], 7);
        $longitude = round((float) $payload['longitude'], 7);
        $updatedAt = isset($payload['updated_at'])
            ? Carbon::parse((string) $payload['updated_at'])
            : now();

        $driver->update([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_updated_at' => $updatedAt,
        ]);

        return [
            'driver_id' => (int) $driver->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'updated_at' => $updatedAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listDriverOrders(User $actor): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);
        $pendingStatusId = $this->resolveStatusId('PENDING');
        $runningStatusIds = $this->runningDriverOrderStatusIds();

        $incoming = $this->isDriverAvailableForIncomingOrders($driver)
            ? Order::query()
                ->with($this->driverOrderPayloadFactory->relations())
                ->where('status_id', $pendingStatusId)
                ->whereNull('driver_id')
                ->whereDoesntHave('logs', function ($query) use ($actor): void {
                    $query
                        ->where('event_type', 'DRIVER_REJECT')
                        ->where('changed_by_user_id', $actor->id);
                })
                ->latest('id')
                ->limit(30)
                ->get()
            : collect();

        $running = Order::query()
            ->with($this->driverOrderPayloadFactory->relations())
            ->where('driver_id', $driver->id)
            ->whereIn('status_id', $runningStatusIds)
            ->latest('id')
            ->limit(30)
            ->get();

        $incomingPayloads = $incoming
            ->map(fn (Order $order): array => $this->serializeDriverOrderForDisplay(
                $order,
                dispatchMetadata: $this->driverCandidateSelector->dispatchForDriver($order, $driver),
            ))
            ->sort(fn (array $left, array $right): int => $this->compareIncomingDriverOrderPayloads($left, $right));

        return [
            'incoming_orders' => $incomingPayloads
                ->values()
                ->all(),
            'running_orders' => $running
                ->map(fn (Order $order): array => $this->serializeDriverOrderForDisplay($order))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listDriverHistory(User $actor): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);
        $historyStatusIds = $this->resolveStatusIds([
            'COMPLETED',
            'CANCELLED',
            'CANCELLED_WITH_FEE',
        ]);

        $orders = Order::query()
            ->with([
                'user:id,name',
                'serviceType:id,code',
                'statusRef:id,code,display_name',
                'statusHistories.statusRef',
                'orderLocations:id,order_id,location_role,failed_attempt_count',
            ])
            ->where('driver_id', $driver->id)
            ->whereIn('status_id', $historyStatusIds)
            ->latest('id')
            ->limit(100)
            ->get();

        $history = $orders
            ->map(function (Order $order): array {
                $statusCode = strtoupper((string) ($order->statusRef->code ?? ''));
                $date = $order->delivered_at ?? $order->updated_at ?? $order->created_at;
                $deliveryFee = round((float) $order->delivery_fee, 2);
                $serviceFee = round((float) $order->service_fee, 2);
                $driverIncome = $this->driverIncomeFeeCalculator->grossIncomeForOrder($order);
                $incomeBreakdown = $this->driverIncomeFeeCalculator->breakdown($driverIncome);

                return [
                    'id' => $order->order_number ?: (string) $order->id,
                    'order_id' => (int) $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->user->name ?? '-',
                    'date' => $date?->toIso8601String(),
                    'fee' => (int) round($driverIncome),
                    'delivery_fee' => $deliveryFee,
                    'service_fee' => $serviceFee,
                    'driver_income' => $driverIncome,
                    'driver_income_gross' => $incomeBreakdown['gross_income'],
                    'driver_admin_fee_percent' => $incomeBreakdown['admin_fee_percent'],
                    'driver_admin_fee' => $incomeBreakdown['admin_fee'],
                    'driver_income_net' => $incomeBreakdown['net_income'],
                    'total_price' => round((float) $order->total_price, 2),
                    'status' => $this->driverHistoryStatusLabel($statusCode, $order->statusRef?->display_name),
                    'status_code' => $statusCode,
                ];
            })
            ->values()
            ->all();

        return [
            'history_orders' => $history,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function driverOrderDetail(User $actor, int $orderId): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = Order::query()
            ->with($this->driverOrderPayloadFactory->relations())
            ->find($orderId);

        if (! $order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        $statusCode = (string) ($order->statusRef->code ?? '');
        $isIncomingCandidate = $statusCode === 'PENDING' && $order->driver_id === null;
        $isAssignedToCurrentDriver = (int) ($order->driver_id ?? 0) === (int) $driver->id;

        if ($isIncomingCandidate && $this->hasDriverRejectedOrder($order->id, $actor->id)) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        if (! $isIncomingCandidate && ! $isAssignedToCurrentDriver) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        $dispatchMetadata = $isIncomingCandidate
            ? $this->driverCandidateSelector->dispatchForDriver($order, $driver)
            : null;

        return $this->serializeDriverOrderForDisplay($order, includeTimeline: true, dispatchMetadata: $dispatchMetadata);
    }

    /**
     * @param  array<string, mixed>|null  $dispatchMetadata
     * @return array<string, mixed>
     */
    private function serializeDriverOrderForDisplay(
        Order $order,
        bool $includeTimeline = false,
        ?array $dispatchMetadata = null,
    ): array {
        $order = $this->ensureDisplayRoutePolyline($order)
            ->fresh($this->driverOrderPayloadFactory->relations());

        $payload = $this->driverOrderPayloadFactory->serialize($order, includeTimeline: $includeTimeline);

        if ($dispatchMetadata !== null) {
            $payload['dispatch'] = $dispatchMetadata;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareIncomingDriverOrderPayloads(array $left, array $right): int
    {
        $leftDistance = $left['dispatch']['distance_to_pickup_meters'] ?? null;
        $rightDistance = $right['dispatch']['distance_to_pickup_meters'] ?? null;
        $leftKnown = is_numeric($leftDistance);
        $rightKnown = is_numeric($rightDistance);

        if ($leftKnown !== $rightKnown) {
            return $leftKnown ? -1 : 1;
        }

        if ($leftKnown && $rightKnown && (int) $leftDistance !== (int) $rightDistance) {
            return (int) $leftDistance <=> (int) $rightDistance;
        }

        return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
    }

    private function ensureDisplayRoutePolyline(Order $order): Order
    {
        $order->loadMissing(['serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt']);

        if (strtoupper((string) ($order->serviceType->code ?? '')) !== 'SHOPPING') {
            return $order;
        }

        $routeSnapshot = is_array($order->route_snapshot) ? $order->route_snapshot : [];
        $encodedPolyline = trim((string) ($routeSnapshot['encoded_polyline'] ?? ''));
        if ($encodedPolyline !== '') {
            return $order;
        }

        try {
            $this->shoppingRouteService->backfillRouteSnapshot($order);
        } catch (\Throwable $exception) {
            Log::warning('Failed to backfill order route polyline.', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);

            return $order;
        }

        return $order->refresh()->load(['serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt']);
    }

    /**
     * @return array<string, mixed>
     */
    public function acceptByDriver(User $actor, int $orderId): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);
        $statusChangeEventPayload = null;

        $order = DB::transaction(function () use ($driver, $actor, $orderId, &$statusChangeEventPayload): Order {
            $lockedDriver = Driver::query()->lockForUpdate()->find($driver->id);
            if (! $lockedDriver) {
                throw new ApiException('Profil driver tidak ditemukan.', 403);
            }

            $order = Order::query()
                ->with(['statusRef', 'serviceType'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            $statusCode = (string) ($order->statusRef->code ?? '');
            $isAssignedToCurrentDriver = (int) ($order->driver_id ?? 0) === (int) $driver->id;

            if ($statusCode === 'DRIVER_ASSIGNED' && $isAssignedToCurrentDriver) {
                if ($order->assigned_at === null) {
                    $order->update(['assigned_at' => now()]);
                }

                $this->markDriverBusy($lockedDriver);

                return $order->refresh();
            }

            if ($statusCode !== 'PENDING') {
                throw new ApiException('Order tidak dapat diterima pada status saat ini.', 409);
            }

            if ($order->driver_id !== null && ! $isAssignedToCurrentDriver) {
                throw new ApiException('Order sudah diambil driver lain.', 409);
            }

            if ($this->hasDriverRejectedOrder($order->id, $actor->id)) {
                throw new ApiException('Order sudah ditolak oleh driver.', 409);
            }

            if (! $this->isDriverAvailableForIncomingOrders($lockedDriver)) {
                throw new ApiException('Aktifkan status kerja sebelum menerima order.', 409);
            }

            $previousStatusCode = strtoupper((string) ($order->statusRef->code ?? 'PENDING'));

            $assignedStatusId = $this->resolveStatusId('DRIVER_ASSIGNED');
            $order->update([
                'driver_id' => $driver->id,
                'status_id' => $assignedStatusId,
                'assigned_at' => now(),
            ]);

            $statusHistory = OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $assignedStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $actor->id,
                'note' => 'Order diterima oleh driver.',
                'price_snapshot' => [
                    'driver_snapshot' => $this->driverSnapshot($lockedDriver),
                ],
            ]);

            $this->markDriverBusy($lockedDriver);

            $statusChangeEventPayload = $this->buildOrderStatusBroadcastPayload(
                $order->id,
                'DRIVER_ASSIGNED',
                $previousStatusCode,
                $statusHistory,
            );

            return $order;
        });

        $this->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
        $this->driverOrderRealtimeService->broadcastOrderRemoved($order->id, 'accepted');

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

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
        $driver = $this->resolveActiveDriverProfile($actor);
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
            $order = $this->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt'],
            );
            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Keputusan item hanya tersedia untuk order SHOPPING.', 409);
            }
            if ($this->orderStatusCode($order) !== 'ARRIVED_MERCHANT') {
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
            $this->broadcastOrderStatusChanged($statusChangeEventPayload);
            $this->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
            $this->broadcastShoppingNegotiationUpdated((int) $order->id);
        }

        $this->broadcastContentUpdatedAfterCommit((int) $order->id, $action === 'CANCEL_MERCHANT'
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateDriverLocation(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = Order::query()
            ->with(['statusRef'])
            ->find($orderId);

        if (! $order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        if ((int) ($order->driver_id ?? 0) !== (int) $driver->id) {
            throw new ApiException('Order ini tidak ditugaskan kepada driver saat ini.', 403);
        }

        $statusCode = $this->orderStatusCode($order);
        if (! in_array($statusCode, OrderStatusCode::driverLocationTrackableStatuses(), true)) {
            throw new ApiException('Lokasi driver tidak dapat dikirim pada status order saat ini.', 409);
        }

        $latitude = round((float) $payload['latitude'], 7);
        $longitude = round((float) $payload['longitude'], 7);
        $updatedAt = isset($payload['updated_at'])
            ? Carbon::parse((string) $payload['updated_at'])
            : now();

        $driver->update([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_updated_at' => $updatedAt,
        ]);

        $updatedAtIso = $updatedAt->toIso8601String();
        $this->realtimeBroadcaster->driverLocationUpdated(
            (int) $order->id,
            $latitude,
            $longitude,
            $updatedAtIso
        );

        return [
            'order_id' => (int) $order->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'updated_at' => $updatedAtIso,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rejectByDriver(User $actor, int $orderId, ?string $reason): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);
        $statusChangeEventPayload = null;

        $order = DB::transaction(function () use ($driver, $actor, $orderId, $reason, &$statusChangeEventPayload): Order {
            $order = Order::query()
                ->with(['statusRef'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            $statusCode = (string) ($order->statusRef->code ?? '');

            if ($statusCode === 'PENDING' && $order->driver_id === null) {
                $this->recordDriverRejectHistory(
                    $order,
                    $actor->id,
                    $reason ?: 'Order ditolak driver sebelum assignment.',
                );

                return $order;
            }

            $isAssignedToCurrentDriver = (int) ($order->driver_id ?? 0) === (int) $driver->id;
            if ($statusCode === 'DRIVER_ASSIGNED' && $isAssignedToCurrentDriver) {
                $pendingStatusId = $this->resolveStatusId('PENDING');

                $order->update([
                    'driver_id' => null,
                    'status_id' => $pendingStatusId,
                ]);

                $this->syncDriverAvailabilityAfterNonRunningOrder($driver->id);

                $this->recordDriverRejectHistory(
                    $order,
                    $actor->id,
                    $reason ?: 'Driver melepaskan order setelah assignment.',
                );

                $statusHistory = OrderStatusHistory::query()->create([
                    'order_id' => $order->id,
                    'status_id' => $pendingStatusId,
                    'event_type' => 'STATUS_CHANGE',
                    'changed_by_user_id' => $actor->id,
                    'note' => $reason ?: 'Driver melepaskan order setelah assignment.',
                ]);

                $statusChangeEventPayload = $this->buildOrderStatusBroadcastPayload(
                    $order->id,
                    'PENDING',
                    'DRIVER_ASSIGNED',
                    $statusHistory,
                );

                return $order;
            }

            throw new ApiException('Order tidak dapat ditolak pada status saat ini.', 409);
        });

        $this->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->driverOrderRealtimeService->broadcastOrderRemovedForDriverUser(
            $actor->id,
            $order->id,
            'rejected_by_driver',
        );
        if ($statusChangeEventPayload !== null) {
            $this->driverOrderRealtimeService->broadcastOrderAvailable($order);
        }

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function transitionStatusByDriver(
        User $actor,
        int $orderId,
        string $actionCode,
        ?string $targetStatusCode = null,
        ?string $note = null,
    ): array {
        $driver = $this->resolveActiveDriverProfile($actor);
        $statusChangeEventPayload = null;
        $order = DB::transaction(function () use (
            $actor,
            $driver,
            $orderId,
            $actionCode,
            $targetStatusCode,
            $note,
            &$statusChangeEventPayload,
        ): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'rideOrder', 'evidences', 'orderLocations', 'shoppingReceipt'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if ((int) ($order->driver_id ?? 0) !== (int) $driver->id) {
                throw new ApiException('Order ini tidak ditugaskan kepada driver saat ini.', 403);
            }

            $serviceCode = (string) ($order->serviceType->code ?? '');
            $rules = $this->driverOrderPayloadFactory->driverActionRules($serviceCode);

            $normalizedActionCode = strtoupper(str_replace('-', '_', trim($actionCode)));
            $rule = $rules[$normalizedActionCode] ?? null;
            if ($rule === null) {
                throw new ApiException('Aksi driver tidak valid.', 422);
            }

            $currentStatusCode = (string) ($order->statusRef->code ?? '');
            if (! in_array($currentStatusCode, $rule['from'], true)) {
                throw new ApiException('Transisi status tidak valid untuk order ini.', 409);
            }

            $resolvedTargetStatusCode = strtoupper(
                str_replace('-', '_', trim((string) ($targetStatusCode ?? $rule['to'])))
            );

            if ($resolvedTargetStatusCode !== $rule['to']) {
                throw new ApiException('target_status_code tidak sesuai dengan action_code.', 422);
            }

            if (($rule['requires_paid'] ?? false) && ! $this->orderPaymentService->isPaid($order)) {
                $this->paymentProofReminderNotificationService->sendIfNeeded($order, $actor);

                throw new ApiException('Pembayaran belum dicatat.', 409);
            }

            if (($rule['requires_unpaid'] ?? false) && $this->orderPaymentService->isPaid($order)) {
                throw new ApiException('Aksi ini hanya tersedia sebelum pembayaran dicatat.', 409);
            }

            if ($this->deliveryFeeNegotiationService->blocksDriverProgress($order, $normalizedActionCode)) {
                throw new ApiException('Revisi ongkir belum disetujui customer.', 409);
            }

            if (
                strtoupper((string) $serviceCode) === 'SHOPPING' &&
                $normalizedActionCode === 'CONFIRM_PICKED_UP' &&
                ! $this->shoppingPriceNegotiationService->isApproved($order)
            ) {
                throw new ApiException('Harga Nitip belum disetujui customer.', 409);
            }

            if (
                strtoupper((string) $serviceCode) === 'SHOPPING' &&
                $normalizedActionCode === 'CONFIRM_PICKED_UP' &&
                ! $this->shoppingPricingService->hasShoppingReceipt($order)
            ) {
                throw new ApiException('Checkout Nitip belum disimpan.', 409);
            }

            if (
                $this->supportsDriverProofType($serviceCode, 'pickup') &&
                in_array($normalizedActionCode, ['BOARD_PASSENGER', 'CONFIRM_PICKED_UP'], true) &&
                ! $this->orderEvidenceService->hasProof($order, 'pickup')
            ) {
                throw new ApiException('Bukti foto pickup belum diupload.', 409);
            }

            if (
                $this->supportsDriverProofType($serviceCode, 'delivery') &&
                $normalizedActionCode === 'COMPLETE_ORDER' &&
                ! $this->orderEvidenceService->hasProof($order, 'delivery')
            ) {
                throw new ApiException('Bukti foto selesai pengantaran belum diupload.', 409);
            }

            $shoppingCancellationPenalty = null;
            $shoppingCancellationPenaltyBaseDeliveryFee = null;
            if (strtoupper((string) $serviceCode) === 'SHOPPING' && $normalizedActionCode === 'CANCEL_WITH_FEE') {
                if (! $this->shoppingPricingService->isCancellationPenaltyEligible($order)) {
                    throw new ApiException('Order belum memenuhi batas failed attempt untuk dibatalkan dengan fee.', 409);
                }

                if ($this->orderPaymentService->isPaid($order)) {
                    throw new ApiException('Order sudah memiliki pembayaran lunas dan tidak bisa dibatalkan dengan fee.', 409);
                }

                $shoppingCancellationPenaltyBaseDeliveryFee = $this->shoppingPricingService->cancellationPenaltyBaseAmount($order);
                $shoppingCancellationPenalty = $this->shoppingPricingService->calculateCancellationPenalty($order);
                if ($shoppingCancellationPenalty <= 0) {
                    throw new ApiException('Penalty pembatalan belum dapat dihitung.', 409);
                }
            }

            $eventNote = trim((string) $note);
            if ($eventNote === '') {
                $eventNote = $normalizedActionCode === 'REPORT_PACKAGE_INVALID'
                    ? 'Barang tidak sesuai untuk layanan kurir motor.'
                    : 'Driver action '.$normalizedActionCode;
            }

            $targetStatusId = $this->resolveStatusId($resolvedTargetStatusCode);
            $updates = [
                'status_id' => $targetStatusId,
            ];

            if ($shoppingCancellationPenalty !== null) {
            }

            if (in_array($resolvedTargetStatusCode, ['DELIVERED', 'COMPLETED'], true)) {
                $updates['delivered_at'] = now();
            }

            if (in_array($resolvedTargetStatusCode, ['CANCELLED', 'CANCELLED_WITH_FEE'], true)) {
                $updates['cancelled_by'] = 'driver';
                $updates['cancellation_reason'] = $eventNote;
                $updates['cancelled_at'] = now();
            }

            $order->update($updates);
            $this->purgeTerminalShoppingUnavailableItems(
                $order,
                (int) $actor->id,
                $resolvedTargetStatusCode,
                'DRIVER_FINALIZED_SHOPPING_ORDER',
            );
            $this->syncDriverServiceTimestamp($order, $resolvedTargetStatusCode);
            if (! $this->isRunningDriverStatusCode($resolvedTargetStatusCode)) {
                $this->syncDriverAvailabilityAfterNonRunningOrder($driver->id);
            }

            $snapshot = [
                'action_code' => $normalizedActionCode,
                'service_type' => $serviceCode,
                ...($shoppingCancellationPenaltyBaseDeliveryFee !== null ? [
                    'penalty_base_delivery_fee' => round($shoppingCancellationPenaltyBaseDeliveryFee, 2),
                ] : []),
            ];

            $statusHistory = OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $targetStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $actor->id,
                'note' => $eventNote,
                'price_snapshot' => $snapshot,
            ]);

            $statusChangeEventPayload = $this->buildOrderStatusBroadcastPayload(
                $order->id,
                $resolvedTargetStatusCode,
                strtoupper($currentStatusCode),
                $statusHistory,
            );
            if ($shoppingCancellationPenalty !== null) {
                $order = $this->shoppingPricingService->recalculate(
                    $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                    $actor->id,
                    'DRIVER_CANCEL_WITH_FEE',
                    false,
                    $eventNote
                );

                $this->orderPaymentService->setPendingTransferPayment(
                    $order->refresh(),
                    (float) $order->total_price,
                    'DRIVER_CANCEL_WITH_FEE'
                );

                OrderLog::query()->create([
                    'order_id' => $order->id,
                    'log_type' => 'PAYMENT_UPDATE',
                    'trigger_type' => 'SYSTEM_PAYMENT_METHOD_CHANGED_AFTER_FAILED_ATTEMPTS',
                    'changed_by_user_id' => $actor->id,
                    'note' => 'Sistem mengubah pembayaran COD menjadi Transfer setelah merchant gagal tiga kali.',
                    'metadata' => [
                        'payment_method' => OrderPaymentService::METHOD_TRANSFER,
                        'amount' => round((float) $order->total_price, 2),
                        'source' => 'DRIVER_CANCEL_WITH_FEE',
                    ],
                ]);
            }

            return $order;
        });

        $this->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
        $this->paymentProofReminderNotificationService->scheduleForBlockingPaymentStatus($order->refresh());

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function buildOrderStatusBroadcastPayload(
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

    private function transitionShoppingOrderToArrivedMerchant(
        Order $order,
        User $actor,
        string $actionCode,
        ?int $pickupLocationId,
        string $note,
        ?array &$statusChangeEventPayload,
    ): Order {
        $order->loadMissing('statusRef');
        $previousStatusCode = $this->orderStatusCode($order);
        if ($previousStatusCode !== 'DRIVER_ASSIGNED') {
            return $order;
        }

        $targetStatusId = $this->resolveStatusId('ARRIVED_MERCHANT');
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

        $statusChangeEventPayload = $this->buildOrderStatusBroadcastPayload(
            (int) $order->id,
            'ARRIVED_MERCHANT',
            $previousStatusCode,
            $statusHistory
        );

        return $order->refresh()->loadMissing('statusRef');
    }

    /**
     * @param  array<string, bool|int|string|null>|null  $payload
     */
    private function broadcastOrderStatusChanged(?array $payload): void
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
    private function sendOrderStatusPushNotification(Order $order, ?array $payload): void
    {
        if ($payload === null) {
            return;
        }

        $this->orderStatusPushNotificationService->sendOrderStatusNotification($order, $payload);
    }

    private function resolveActiveDriverProfile(User $actor): Driver
    {
        if ($actor->role !== 'driver') {
            throw new ApiException('Akses hanya untuk driver.', 403);
        }

        $driver = Driver::query()->where('user_id', $actor->id)->first();
        if (! $driver) {
            throw new ApiException('Profil driver tidak ditemukan.', 403);
        }

        if ($driver->registration_status !== 'active') {
            throw new ApiException('Akun driver belum aktif.', 403);
        }

        return $driver;
    }

    /**
     * @return array<int, int>
     */
    private function runningDriverOrderStatusIds(): array
    {
        return $this->resolveStatusIdsLenient(OrderStatusCode::runningDriverStatuses());
    }

    private function hasRunningDriverOrder(int $driverId): bool
    {
        $runningStatusIds = $this->runningDriverOrderStatusIds();
        if ($runningStatusIds === []) {
            return false;
        }

        return Order::query()
            ->where('driver_id', $driverId)
            ->whereIn('status_id', $runningStatusIds)
            ->exists();
    }

    private function isRunningDriverStatusCode(string $statusCode): bool
    {
        return in_array(OrderStatusCode::normalize($statusCode), OrderStatusCode::runningDriverStatuses(), true);
    }

    private function isDriverAvailableForIncomingOrders(Driver $driver): bool
    {
        return strtolower(trim((string) ($driver->status ?? 'offline'))) === 'available';
    }

    private function hasDriverRejectedOrder(int $orderId, int $driverUserId): bool
    {
        return OrderLog::query()
            ->where('order_id', $orderId)
            ->where('event_type', 'DRIVER_REJECT')
            ->where('changed_by_user_id', $driverUserId)
            ->exists();
    }

    private function recordDriverRejectHistory(Order $order, int $driverUserId, string $note): void
    {
        $alreadyRejected = $this->hasDriverRejectedOrder((int) $order->id, $driverUserId);
        if ($alreadyRejected) {
            return;
        }

        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => 'DRIVER_REJECT',
            'changed_by_user_id' => $driverUserId,
            'note' => $note,
        ]);
    }

    private function markDriverBusy(Driver $driver): void
    {
        if ((string) $driver->status === 'busy') {
            return;
        }

        $driver->update([
            'status' => 'busy',
        ]);
    }

    private function syncDriverAvailabilityAfterNonRunningOrder(int $driverId): void
    {
        $driver = Driver::query()->find($driverId);
        if (! $driver || (string) $driver->status !== 'busy') {
            return;
        }

        if ($this->hasRunningDriverOrder($driverId)) {
            return;
        }

        $driver->update([
            'status' => 'available',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDriverAvailability(Driver $driver, bool $hasRunningOrder): array
    {
        $status = strtolower(trim((string) ($driver->status ?? 'offline')));
        if (! in_array($status, ['available', 'offline', 'busy'], true)) {
            $status = $hasRunningOrder ? 'busy' : 'offline';
        }

        return [
            'driver_id' => (int) $driver->id,
            'status' => $status,
            'is_online' => in_array($status, ['available', 'busy', 'online'], true),
            'has_running_order' => $hasRunningOrder,
        ];
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, int>
     */
    private function resolveStatusIds(array $codes): array
    {
        $map = OrderStatus::query()
            ->whereIn('code', $codes)
            ->pluck('id', 'code');

        $resolved = [];
        foreach ($codes as $code) {
            $id = $map[$code] ?? null;
            if (! $id) {
                throw new ApiException('Konfigurasi status order belum lengkap.', 500, [
                    'missing_status_code' => $code,
                ]);
            }

            $resolved[] = (int) $id;
        }

        return $resolved;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, int>
     */
    private function resolveStatusIdsLenient(array $codes): array
    {
        $map = OrderStatus::query()
            ->whereIn('code', $codes)
            ->pluck('id', 'code');

        $missingCodes = [];
        $resolved = [];

        foreach ($codes as $code) {
            $id = $map[$code] ?? null;
            if (! $id) {
                $missingCodes[] = $code;

                continue;
            }

            $resolved[] = (int) $id;
        }

        if ($missingCodes !== []) {
            Log::warning('Order status configuration is incomplete for lenient lookup.', [
                'missing_status_codes' => $missingCodes,
                'requested_status_codes' => $codes,
            ]);
        }

        return $resolved;
    }

    private function driverHistoryStatusLabel(string $statusCode, ?string $fallbackDisplayName): string
    {
        return match ($statusCode) {
            'COMPLETED' => 'Selesai',
            'CANCELLED', 'CANCELLED_WITH_FEE' => 'Dibatalkan',
            default => $fallbackDisplayName ?: $statusCode,
        };
    }

    private function syncDriverServiceTimestamp(Order $order, string $targetStatusCode): void
    {
        $serviceCode = strtoupper((string) ($order->serviceType->code ?? ''));
        if ($serviceCode !== 'RIDE') {
            return;
        }

        $rideOrder = $order->rideOrder;
        if (! $rideOrder) {
            /** @var \App\Models\RideOrder $rideOrder */
            $rideOrder = $order->rideOrder()->create();
        }

        if (in_array($targetStatusCode, ['PICKED_UP', 'ON_THE_WAY'], true) && $rideOrder->picked_up_at === null) {
            $rideOrder->update([
                'picked_up_at' => now(),
            ]);
        }

        if (in_array($targetStatusCode, ['ARRIVED_DROPOFF', 'DELIVERED'], true) && $rideOrder->arrived_at === null) {
            $rideOrder->update([
                'arrived_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submitShoppingPriceQuoteByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->lockedAssignedDriverOrder(
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

        $this->broadcastShoppingNegotiationUpdated((int) $order->id);
        $this->notifyShoppingPriceChanged($order, $actor, 'customer', true);

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
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->lockedAssignedDriverOrder(
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

        $this->broadcastShoppingNegotiationUpdated((int) $order->id);
        $this->notifyShoppingBypassTotalChanged($order, $actor);

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
        $driver = $this->resolveActiveDriverProfile($actor);
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
            $order = $this->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt']
            );

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Bypass item hanya tersedia untuk order SHOPPING.', 409);
            }
            if ($this->orderStatusCode($order) !== 'ARRIVED_MERCHANT') {
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

        $this->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
        $this->broadcastContentUpdatedAfterCommit(
            (int) $order->id,
            ShoppingPriceNegotiationService::DRIVER_BYPASS_UNAVAILABLE_ITEMS,
            [
                'pickup_location_id' => $pickupLocationId,
                'event_id' => $bypassEventId,
                'merchant_cancelled' => $merchantCancelled,
            ],
        );
        $this->broadcastShoppingNegotiationUpdated((int) $order->id);
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
        $driver = $this->resolveActiveDriverProfile($actor);
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
            $order = $this->lockedAssignedDriverOrder(
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

            if ($this->orderStatusCode($order) !== 'ARRIVED_MERCHANT') {
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

            $items = $this->withTargetPickupMerchantPayload($order, $itemsPayload, $pickupLocationId);
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
            $this->broadcastContentUpdatedAfterCommit((int) $order->id, 'DRIVER_UNAVAILABLE_ITEMS_REPLACED', [
                'pickup_location_id' => $pickupLocationId,
                'event_id' => $eventId,
            ]);
            $this->broadcastShoppingNegotiationUpdated((int) $order->id);
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
        $this->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
        $this->broadcastShoppingNegotiationUpdated((int) $order->id);
        $this->notifyShoppingPriceChanged($order, $actor, 'driver', false);
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
                $items = $this->withTargetPickupMerchantPayload($order, $items, $targetPickupLocationId);
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
                $this->assertShoppingEditUnavailableTarget($order, $targetPickupLocationId, $items, $action, $itemId);
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

        $this->broadcastContentUpdatedAfterCommit((int) $order->id, 'SHOPPING_ITEM_CHANGE_APPLIED', [
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
        $driver = $this->resolveActiveDriverProfile($actor);
        $action = strtoupper(trim((string) ($payload['action'] ?? '')));

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload, $action): Order {
            $order = $this->lockedAssignedDriverOrder(
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

        $this->broadcastContentUpdatedAfterCommit((int) $order->id, 'SHOPPING_ITEM_CHANGE_RESPONDED', [
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
        $chain = $this->shoppingReplacementProjectionService->forPickup($order, (int) $pickup->id);
        if ((int) $chain['chain_failed_attempt_count'] >= ShoppingReplacementProjectionService::MAX_FAILURES_PER_CHAIN) {
            $removedItems = $this->unavailableShoppingItemSnapshotsForPickup($order, (int) $pickup->id);
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
            $this->deleteShoppingItemsBySnapshots($order, $removedItems);
        }
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

    private function cancelShoppingOrderWithOptionalFee(
        User $actor,
        Order $order,
        string $reason,
        bool $withFee,
        string $recalculationTrigger,
        mixed &$statusChangeEventPayload,
        string $cancelledBy = 'customer',
    ): Order {
        $statusCode = $withFee ? 'CANCELLED_WITH_FEE' : 'CANCELLED';
        $statusId = $this->resolveStatusId($statusCode);
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

        $cancelUpdates = $this->cancelledOrderUpdateAttributes($statusCode, $cancelledBy, $reason);
        $order->update($cancelUpdates);
        $this->purgeTerminalShoppingUnavailableItems(
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

        $statusChangeEventPayload = $this->buildOrderStatusBroadcastPayload(
            $order->id,
            $statusCode,
            $previousStatusCode,
            $statusHistory
        );

        if ($order->driver_id !== null) {
            $this->syncDriverAvailabilityAfterNonRunningOrder((int) $order->driver_id);
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

    private function hasCommittedShoppingMerchant(Order $order): bool
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
        $driver = $this->resolveActiveDriverProfile($actor);
        $statusChangeEventPayload = null;

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $pickupLocationId, &$statusChangeEventPayload): Order {
            $order = $this->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt']
            );

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Konfirmasi merchant buka hanya tersedia untuk order SHOPPING.', 409);
            }

            $statusCode = $this->orderStatusCode($order);
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

            $this->shoppingFailedTripCompensationService->recordCheckpoint(
                $order,
                $pickup,
                (int) $actor->id,
            );

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

        $this->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
        $this->broadcastContentUpdatedAfterCommit((int) $order->id, 'SHOPPING_MERCHANT_OPENED', [
            'pickup_location_id' => $pickupLocationId,
        ]);

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateShoppingItemsByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);
        /** @var array{pickup_location_id: int, item_names: list<string>, event_id: int}|null $unavailableItemNotification */
        $unavailableItemNotification = null;

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload, &$unavailableItemNotification): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'items', 'orderLocations', 'shoppingReceipt'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if ((int) ($order->driver_id ?? 0) !== (int) $driver->id) {
                throw new ApiException('Order ini tidak ditugaskan kepada driver saat ini.', 403);
            }

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Update nota hanya tersedia untuk order SHOPPING.', 409);
            }

            if (! $this->shoppingOrderCapabilityService->canDriverUpdateItemAvailability($order)) {
                throw new ApiException('Item Nitip hanya bisa diperbarui saat driver tiba di merchant.', 409);
            }

            $targetPickupLocationId = isset($payload['pickup_location_id']) && is_numeric($payload['pickup_location_id'])
                ? (int) $payload['pickup_location_id']
                : null;
            if ($targetPickupLocationId !== null && $targetPickupLocationId > 0) {
                $targetPickup = $this->shoppingPickupLocationService->pickupById($order, $targetPickupLocationId);
            } else {
                $targetPickupLocationId = null;
                $targetPickup = null;
            }

            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $requiresRequote = false;
            $affectedPickupIds = [];
            $submittedPickupIds = [];
            $submittedItemIds = [];
            $submittedHasUnavailableItem = false;
            $newUnavailableItemNamesByPickup = [];
            $availabilityEvent = null;

            foreach ($items as $itemPayload) {
                if (! is_array($itemPayload)) {
                    continue;
                }

                $itemId = (int) ($itemPayload['id'] ?? 0);
                if ($itemId <= 0) {
                    continue;
                }

                $item = $order->items()->where('id', $itemId)->lockForUpdate()->first();
                if (! $item) {
                    throw new ApiException('Item belanja tidak ditemukan pada order ini.', 404);
                }

                $itemPickupId = $item->pickup_location_id !== null ? (int) $item->pickup_location_id : null;
                if ($targetPickupLocationId !== null && $itemPickupId !== $targetPickupLocationId) {
                    throw new ApiException('Item tidak sesuai dengan merchant Nitip yang sedang diperbarui.', 422);
                }

                $quantity = array_key_exists('quantity', $itemPayload)
                    ? max(1, (int) $itemPayload['quantity'])
                    : (int) $item->quantity;
                $unitPrice = array_key_exists('unit_price', $itemPayload) && $itemPayload['unit_price'] !== null
                    ? max(0.0, (float) $itemPayload['unit_price'])
                    : (float) $item->unit_price;
                $wasAvailable = (bool) $item->is_available;
                $isAvailable = array_key_exists('is_available', $itemPayload)
                    ? (bool) $itemPayload['is_available']
                    : $wasAvailable;
                $itemChanged = $quantity !== (int) $item->quantity
                    || $unitPrice !== (float) $item->unit_price
                    || $isAvailable !== $wasAvailable;
                $requiresRequote = $requiresRequote || $itemChanged;
                if ($itemChanged && $item->pickup_location_id !== null) {
                    $affectedPickupIds[(int) $item->pickup_location_id] = true;
                }
                if ($itemPickupId !== null) {
                    $submittedPickupIds[$itemPickupId] = true;
                }
                $submittedItemIds[] = (int) $item->id;
                $submittedHasUnavailableItem = $submittedHasUnavailableItem || ! $isAvailable;
                if ($wasAvailable && ! $isAvailable && $itemPickupId !== null) {
                    $itemName = trim((string) ($item->menu_name ?? ''));
                    $newUnavailableItemNamesByPickup[$itemPickupId][] = $itemName !== ''
                        ? $itemName
                        : 'Item Nitip';
                }

                $metadata = is_array($item->metadata) ? $item->metadata : [];
                if ($item->item_source === 'MANUAL') {
                    $metadata['price_status'] = (! $isAvailable)
                        ? 'UNAVAILABLE'
                        : ($unitPrice > 0 ? 'DRIVER_CONFIRMED' : 'PENDING_DRIVER_INPUT');
                }

                $lineSubtotal = $isAvailable ? round($unitPrice * $quantity, 2) : 0.0;

                $item->update([
                    'quantity' => $quantity,
                    'unit_price' => round($unitPrice, 2),
                    'subtotal' => $lineSubtotal,
                    'notes' => array_key_exists('notes', $itemPayload)
                        ? ($itemPayload['notes'] !== null ? (string) $itemPayload['notes'] : null)
                        : $item->notes,
                    'is_available' => $isAvailable,
                    'metadata' => $metadata === [] ? null : $metadata,
                ]);
            }

            if ($targetPickupLocationId === null && count($submittedPickupIds) === 1) {
                $targetPickupLocationId = (int) array_key_first($submittedPickupIds);
                $targetPickup = $this->shoppingPickupLocationService->pickupById($order, $targetPickupLocationId);
            }

            if ($targetPickupLocationId !== null) {
                $targetPickup ??= $this->shoppingPickupLocationService->pickupById($order, $targetPickupLocationId);
                $fulfillmentStatus = strtoupper((string) ($targetPickup->fulfillment_status ?? 'PENDING'));
                if (! in_array($fulfillmentStatus, ['OPEN_CONFIRMED', 'ITEMS_PENDING_CUSTOMER', 'ITEMS_CONFIRMED'], true)) {
                    throw new ApiException('Konfirmasi Resto buka sebelum mengecek item merchant ini.', 409);
                }
            }

            if ($targetPickupLocationId !== null) {
                $availabilityEvent = OrderLog::query()->create([
                    'order_id' => $order->id,
                    'event_type' => 'SHOPPING_ITEM_AVAILABILITY',
                    'trigger_type' => 'DRIVER_CONFIRMED_ITEM_AVAILABILITY',
                    'changed_by_user_id' => $actor->id,
                    'note' => 'Driver mencatat ketersediaan item merchant.',
                    'metadata' => [
                        'pickup_location_id' => $targetPickupLocationId,
                        'item_ids' => $submittedItemIds,
                        'has_unavailable_item' => $submittedHasUnavailableItem,
                    ],
                ]);

                $targetPickup->update([
                    'fulfillment_status' => $submittedHasUnavailableItem
                        ? 'ITEMS_PENDING_CUSTOMER'
                        : 'ITEMS_CONFIRMED',
                ]);
            }

            if ($requiresRequote) {
                foreach (array_keys($affectedPickupIds) as $pickupLocationId) {
                    $this->shoppingPriceNegotiationService->record(
                        $order,
                        ShoppingPriceNegotiationService::DRIVER_ITEM_CHANGE_REQUIRES_REQUOTE,
                        $actor->id,
                        'Driver memperbarui ketersediaan item. Harga Nitip perlu dikirim ulang.',
                        [
                            'status' => 'NEEDS_REQUOTE',
                            'pickup_location_id' => $pickupLocationId,
                        ]
                    );
                }
            }

            $newUnavailableItemNames = $targetPickupLocationId !== null
                ? ($newUnavailableItemNamesByPickup[$targetPickupLocationId] ?? [])
                : [];
            $shouldNotifyUnavailableItems = $availabilityEvent !== null && $newUnavailableItemNames !== [];

            $freshOrder = $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                $actor->id,
                'DRIVER_RECEIPT_UPDATE',
                true,
                'Driver memperbarui ketersediaan item Nitip.',
                ! $shouldNotifyUnavailableItems
            );

            if ($shouldNotifyUnavailableItems) {
                $unavailableItemNotification = [
                    'pickup_location_id' => (int) $targetPickupLocationId,
                    'item_names' => $newUnavailableItemNames,
                    'event_id' => (int) $availabilityEvent->id,
                ];
            }

            return $freshOrder;
        });

        if (is_array($unavailableItemNotification)) {
            $this->shoppingItemAvailabilityPushNotificationService->sendUnavailableItems(
                $order,
                $unavailableItemNotification['pickup_location_id'],
                $unavailableItemNotification['item_names'],
                $unavailableItemNotification['event_id'],
            );
        }

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateDeliveryFeeOverride(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->lockedAssignedDriverOrder($orderId, $driver->id, ['statusRef', 'serviceType', 'payments', 'payment', 'evidences', 'courierOrder']);

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
                $failedTripCompensation = $this->shoppingPricingService->feeLineAmount(
                    $order,
                    'FAILED_TRIP_COMPENSATION'
                );
                $pricingScopeMetadata = [
                    'pricing_scope' => DeliveryFeeNegotiationService::PRICING_SCOPE_SHOPPING_TOTAL_TRANSPORT,
                    'previous_total_transport' => round((float) $order->delivery_fee + $failedTripCompensation, 2),
                    'replaced_delivery_fee' => round((float) $order->delivery_fee, 2),
                    'replaced_failed_trip_compensation' => round($failedTripCompensation, 2),
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

        $this->broadcastDeliveryFeeNegotiationUpdated((int) $order->id);
        $this->notifyDeliveryFeeChanged($order, $actor, 'customer', true);

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
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->lockedAssignedDriverOrder(
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

        $this->broadcastDeliveryFeeNegotiationUpdated((int) $order->id);
        $this->notifyDeliveryFeeChanged($order, $actor, 'customer', false);

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
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->lockedAssignedDriverOrder($orderId, $driver->id, ['statusRef', 'serviceType', 'payments', 'payment', 'evidences', 'courierOrder', 'items', 'shoppingReceipt']);
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

        $this->broadcastDeliveryFeeNegotiationUpdated((int) $order->id);
        $this->notifyDeliveryFeeChanged($order, $actor, 'customer', false);

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

        $this->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
        $this->broadcastDeliveryFeeNegotiationUpdated((int) $order->id);
        $this->notifyDeliveryFeeChanged($order, $actor, 'driver', $action === 'COUNTER');

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
        $statusId = $this->resolveStatusId('CANCELLED');
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

        $order->update($this->cancelledOrderUpdateAttributes('CANCELLED', 'customer', $reason));
        $this->purgeTerminalShoppingUnavailableItems(
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

        $statusChangeEventPayload = $this->buildOrderStatusBroadcastPayload(
            $order->id,
            'CANCELLED',
            $previousStatusCode,
            $statusHistory
        );

        if ($order->driver_id !== null) {
            $this->syncDriverAvailabilityAfterNonRunningOrder((int) $order->driver_id);
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

        return $this->refreshTotalsAfterDeliveryFeeChange(
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function uploadProof(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->lockedAssignedDriverOrder($orderId, $driver->id, ['statusRef', 'serviceType']);
            $photo = $payload['photo'] ?? null;
            if (! $photo instanceof UploadedFile) {
                throw new ApiException('Foto bukti wajib diupload.', 422);
            }

            $type = $this->normalizeProofType((string) ($payload['type'] ?? ''));
            $serviceCode = (string) ($order->serviceType->code ?? '');
            if ($this->isLifecycleProofType($type) && ! $this->supportsDriverProofType($serviceCode, $type)) {
                throw new ApiException($this->unsupportedProofMessage($type, $serviceCode), 422);
            }

            $evidenceType = $this->evidenceTypeForProof($type);
            $this->orderEvidenceService->storeAndRecordDriverEvidence(
                $order,
                $photo,
                (int) $actor->id,
                $evidenceType,
                'proofs',
                $payload['note'] ?? null,
            );

            OrderLog::query()->create([
                'order_id' => $order->id,
                'log_type' => 'SYSTEM_EVENT',
                'trigger_type' => 'ORDER_PROOF_UPLOADED',
                'changed_by_user_id' => $actor->id,
                'note' => 'Driver upload bukti '.$type.'.',
                'metadata' => [
                    'proof_type' => $type,
                    'evidence_type' => $evidenceType,
                    'pickup_location_id' => $payload['pickup_location_id'] ?? null,
                ],
            ]);

            return $order->refresh();
        });

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateShoppingCheckout(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'items', 'shoppingReceipt']
            );

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Checkout nitip hanya tersedia untuk order SHOPPING.', 409);
            }

            if (! $this->shoppingOrderCapabilityService->canDriverUploadReceipt($order)) {
                throw new ApiException('Checkout Nitip hanya bisa disimpan saat driver tiba di merchant.', 409);
            }

            if (! $this->shoppingPriceNegotiationService->isApproved($order)) {
                throw new ApiException('Harga Nitip harus disetujui customer sebelum checkout disimpan.', 409);
            }

            if ($this->shoppingItemChangeRequestService->hasPending($order)) {
                throw new ApiException('Request perubahan item harus diselesaikan sebelum checkout disimpan.', 409);
            }

            if ($this->deliveryFeeNegotiationService->hasPendingApproval($order)) {
                throw new ApiException('Revisi ongkir harus disetujui customer sebelum checkout disimpan.', 409);
            }

            if (array_key_exists('delivery_fee_override', $payload) && $payload['delivery_fee_override'] !== null) {
                throw new ApiException('Revisi ongkir Nitip harus dikirim sebagai proposal ongkir.', 409);
            }

            $receiptPhoto = $payload['receipt_photo'] ?? null;
            if ($order->shoppingReceipt !== null && ! ($receiptPhoto instanceof UploadedFile)) {
                return $order->refresh();
            }

            $shoppingTotal = $this->shoppingPricingService->approvedShoppingSubtotalAmount($order);
            if ($shoppingTotal === null || $shoppingTotal <= 0) {
                throw new ApiException('Harga Nitip approved belum valid untuk checkout.', 409);
            }

            $order->shoppingReceipt()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'total_amount' => $shoppingTotal,
                    'recorded_by_user_id' => $actor->id,
                    'recorded_at' => now(),
                ]
            );
            $order->unsetRelation('shoppingReceipt');
            $order = $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt', 'orderLocations']),
                $actor->id,
                'SHOPPING_CHECKOUT_SAVED',
                true,
                'Driver menyimpan checkout Nitip.'
            );
            $this->syncPendingPaymentAmount($order->refresh());

            $order->orderLocations()
                ->where('location_role', 'PICKUP')
                ->whereIn('fulfillment_status', ['PRICE_APPROVED'])
                ->update([
                    'fulfillment_status' => 'COMPLETED',
                ]);

            if ($receiptPhoto instanceof UploadedFile) {
                $this->orderEvidenceService->storeAndRecordDriverEvidence(
                    $order,
                    $receiptPhoto,
                    (int) $actor->id,
                    'SHOPPING_RECEIPT',
                    'receipts',
                );
            }

            return $order->refresh();
        });

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function confirmTransferPaymentByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->lockedAssignedDriverOrder($orderId, $driver->id, [
                'statusRef',
                'serviceType',
                'payment',
                'payments',
                'evidences',
            ]);
            $paymentProofFeedback = app(AdminPaymentProofStatusService::class)->feedbackForOrder($order);
            if (($paymentProofFeedback['status'] ?? null) === 'rejected') {
                throw new ApiException('Bukti QRIS ditolak. Tunggu customer mengirim bukti baru.', 409);
            }
            $proofStatuses = app(AdminPaymentProofStatusService::class);
            $paymentProofLogs = $proofStatuses->decisionLogsForOrder($order);
            $pendingProof = $proofStatuses->latestPaymentTransferProofForOrder($order);
            $pendingProofDecision = $pendingProof instanceof OrderEvidence
                ? $proofStatuses->decisionFor($pendingProof, $order->payment, $paymentProofLogs)
                : null;

            $amount = round((float) ($payload['amount'] ?? $order->total_price), 2);
            $expectedAmount = round((float) $order->total_price, 2);

            if ($amount <= 0) {
                throw new ApiException('Nominal QRIS harus lebih dari 0.', 422);
            }

            $paidAt = isset($payload['paid_at'])
                ? Carbon::parse((string) $payload['paid_at'])
                : now();

            $this->orderPaymentService->markPaid(
                $order,
                OrderPaymentService::METHOD_TRANSFER,
                $amount,
                $actor->id,
                $driver->id,
                $paidAt,
                [
                    'recorded_by_role' => $actor->role,
                    'source' => 'DRIVER_QRIS_CONFIRMATION',
                    'expected_amount' => $expectedAmount,
                ],
            );

            if ($pendingProof instanceof OrderEvidence && ($pendingProofDecision['status'] ?? null) === 'pending') {
                $this->logDriverPaymentProofApproval($order, $actor, $pendingProof);
            }

            OrderLog::query()->create([
                'order_id' => $order->id,
                'log_type' => 'PAYMENT_UPDATE',
                'trigger_type' => 'QRIS_PAYMENT_RECORDED_BY_DRIVER',
                'changed_by_user_id' => $actor->id,
                'note' => 'Driver mencatat pembayaran QRIS secara manual.',
                'metadata' => [
                    'paid_amount' => $amount,
                    'expected_amount' => $expectedAmount,
                    'recorded_by_role' => $actor->role,
                ],
            ]);

            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => 'PAYMENT_UPDATE',
                'changed_by_user_id' => $actor->id,
                'note' => 'Pembayaran QRIS berhasil dicatat.',
                'metadata' => [
                    'paid_amount' => $amount,
                    'payment_status' => 'paid',
                    'payment_method' => 'TRANSFER',
                ],
            ]);

            return $order->refresh();
        });

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function rejectTransferPaymentByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);
        $reason = trim((string) ($payload['rejection_reason'] ?? ''));
        if ($reason === '') {
            throw new ApiException('Alasan penolakan wajib diisi.', 422, [
                'rejection_reason' => ['Alasan penolakan wajib diisi.'],
            ]);
        }

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $reason): Order {
            $order = $this->lockedAssignedDriverOrder($orderId, $driver->id, [
                'statusRef',
                'serviceType',
                'payment',
                'payments',
                'evidences',
            ]);

            $proofStatuses = app(AdminPaymentProofStatusService::class);
            $payment = $order->payment;
            if ($proofStatuses->isPaidPayment($payment)) {
                throw new ApiException('Pembayaran pesanan ini sudah tercatat lunas.', 409);
            }

            $logs = $proofStatuses->decisionLogsForOrder($order);
            $proof = $proofStatuses->latestPaymentTransferProofForOrder($order);
            if (! $proof instanceof OrderEvidence) {
                throw new ApiException('Bukti QRIS tidak ditemukan. Tunggu customer mengirim bukti baru.', 409);
            }

            $decision = $proofStatuses->decisionFor($proof, $payment, $logs);
            if (($decision['status'] ?? null) !== 'pending') {
                throw new ApiException('Bukti QRIS ini sudah tidak menunggu verifikasi.', 409);
            }

            if (! $this->orderEvidenceService->publicEvidenceFileExists($order, $proof)) {
                throw new ApiException('File bukti QRIS tidak ditemukan. Minta customer mengirim bukti baru.', 409);
            }

            $deletedEvidenceId = (int) $proof->id;
            $this->logDriverPaymentProofRejection($order, $actor, $deletedEvidenceId, $reason);

            if (! $this->orderEvidenceService->deletePublicEvidenceFile($order, $proof)) {
                throw new ApiException('Bukti QRIS gagal dihapus dari storage.', 500);
            }

            $proof->delete();

            return $order->refresh();
        });

        $this->broadcastContentUpdatedAfterCommit((int) $order->id, AdminPaymentProofStatusService::REJECTED_TRIGGER, [
            'payment_status' => 'unpaid',
            'payment_method' => OrderPaymentService::METHOD_TRANSFER,
        ]);
        broadcast(new AdminNotificationUpdated(app(AdminNotificationService::class)->summary()));

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    private function logDriverPaymentProofApproval(Order $order, User $actor, OrderEvidence $proof): void
    {
        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => 'PAYMENT_UPDATE',
            'trigger_type' => AdminPaymentProofStatusService::APPROVED_TRIGGER,
            'changed_by_user_id' => $actor->id,
            'note' => 'Bukti QRIS disetujui driver.',
            'metadata' => [
                'order_evidence_id' => (int) $proof->id,
                'payment_proof_status' => 'approved',
            ],
        ]);
    }

    private function logDriverPaymentProofRejection(Order $order, User $actor, int $deletedEvidenceId, string $reason): void
    {
        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => 'PAYMENT_UPDATE',
            'trigger_type' => AdminPaymentProofStatusService::REJECTED_TRIGGER,
            'changed_by_user_id' => $actor->id,
            'note' => $reason,
            'metadata' => [
                'deleted_evidence_id' => $deletedEvidenceId,
                'payment_proof_status' => 'rejected',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addShoppingItem(User $user, int $orderId, array $payload): Order
    {
        return $this->addShoppingItems($user, $orderId, [$payload]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function addShoppingItems(User $user, int $orderId, array $items): Order
    {
        return DB::transaction(function () use ($user, $orderId, $items): Order {
            $order = $this->getEditableShoppingOrder($user, $orderId);

            if ($items === []) {
                throw new ApiException('Minimal satu item belanja wajib ditambahkan.', 422);
            }

            $routeChanged = $this->applyShoppingItemAdditions(
                $order,
                $items,
                allowNewMerchant: true
            );

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                $user->id,
                $routeChanged ? 'SHOPPING_ROUTE_UPDATED' : 'CUSTOMER_ADD_ITEM',
                true,
                $routeChanged
                    ? 'Customer menambahkan merchant/item belanja sehingga rute dihitung ulang.'
                    : 'Customer menambahkan item belanja.'
            );
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateShoppingItem(User $user, int $orderId, int $itemId, array $payload): Order
    {
        return DB::transaction(function () use ($user, $orderId, $itemId, $payload): Order {
            $order = $this->getEditableShoppingOrder($user, $orderId);
            $item = $order->items()->where('id', $itemId)->first();

            if (! $item) {
                throw new ApiException('Item order tidak ditemukan.', 404);
            }

            $quantity = (int) $payload['quantity'];
            $unitPrice = (float) $item->unit_price;
            $menuName = (string) $item->menu_name;

            if ($item->item_source === 'MANUAL') {
                if (array_key_exists('menu_name', $payload) && $payload['menu_name'] !== null) {
                    $menuName = (string) $payload['menu_name'];
                }
            }

            $lineSubtotal = round($unitPrice * $quantity, 2);

            $item->update([
                'menu_name' => $menuName,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'subtotal' => $lineSubtotal,
                'notes' => array_key_exists('notes', $payload) ? ($payload['notes'] !== null ? (string) $payload['notes'] : null) : $item->notes,
            ]);

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                $user->id,
                'CUSTOMER_UPDATE_ITEM',
                true,
                'Customer memperbarui item belanja.'
            );
        });
    }

    public function removeShoppingItem(User $user, int $orderId, int $itemId): Order
    {
        return DB::transaction(function () use ($user, $orderId, $itemId): Order {
            $order = $this->getEditableShoppingOrder($user, $orderId);
            $item = $order->items()->where('id', $itemId)->first();

            if (! $item) {
                throw new ApiException('Item order tidak ditemukan.', 404);
            }

            if ($order->items()->count() <= 1) {
                throw new ApiException('Order belanja harus memiliki minimal satu item.', 409);
            }

            $pickupLocationId = $item->pickup_location_id !== null ? (int) $item->pickup_location_id : null;
            $item->delete();
            $routeChanged = false;

            if ($pickupLocationId !== null && ! $order->items()->where('pickup_location_id', $pickupLocationId)->exists()) {
                $pickup = OrderLocation::query()
                    ->where('order_id', $order->id)
                    ->where('id', $pickupLocationId)
                    ->where('location_role', 'PICKUP')
                    ->first();

                if ($pickup) {
                    $pickup->delete();
                    $this->syncPrimaryRestaurantFromFirstPickup($order);
                    $this->shoppingRouteService->applyRouteToOrder($order->refresh()->load(['orderLocations.restaurant']));
                    $routeChanged = true;
                }
            }

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                $user->id,
                $routeChanged ? 'SHOPPING_ROUTE_UPDATED' : 'CUSTOMER_REMOVE_ITEM',
                true,
                $routeChanged
                    ? 'Customer menghapus item terakhir pada merchant sehingga rute dihitung ulang.'
                    : 'Customer menghapus item belanja.'
            );
        });
    }

    private function resolveStatusId(string $input): int
    {
        $normalized = strtoupper(str_replace([' ', '-'], '_', trim($input)));

        $legacyMap = [
            'CONFIRMED' => 'PENDING',
            'DRIVER_ASSIGNED' => 'DRIVER_ASSIGNED',
            'PICKING_UP' => 'PICKED_UP',
            'ON_DELIVERY' => 'ON_THE_WAY',
            'DELIVERED' => 'DELIVERED',
            'COMPLETED' => 'COMPLETED',
            'CANCELLED' => 'CANCELLED',
        ];

        $statusCode = $legacyMap[$normalized] ?? $normalized;
        $statusId = OrderStatus::query()->where('code', $statusCode)->value('id');

        if (! $statusId) {
            throw new ApiException('Status order tidak valid.', 422);
        }

        return (int) $statusId;
    }

    private function getEditableShoppingOrder(User $user, int $orderId): Order
    {
        $order = Order::query()
            ->with(['items', 'statusRef', 'serviceType', 'restaurant', 'orderLocations.restaurant', 'shoppingReceipt'])
            ->find($orderId);

        if (! $order || $order->user_id !== $user->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        if (($order->serviceType->code ?? null) !== 'SHOPPING') {
            throw new ApiException('Perubahan item hanya diizinkan untuk order SHOPPING.', 409);
        }

        if (! $this->shoppingOrderCapabilityService->canCustomerDirectEditItems($order)) {
            throw new ApiException('Item tidak bisa diubah pada status order saat ini.', 409);
        }

        return $order;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function withTargetPickupMerchantPayload(Order $order, array $items, int $targetPickupLocationId): array
    {
        if ($items === []) {
            return [];
        }

        $merchantPayload = $this->shoppingPickupLocationService->merchantPayloadFromPickup(
            $this->shoppingPickupLocationService->pickupById($order, $targetPickupLocationId)
        );

        return array_map(function (array $item) use ($merchantPayload): array {
            $hasMerchantPayload = (isset($item['merchant_id']) && is_numeric($item['merchant_id']))
                || (isset($item['merchant_place']) && is_array($item['merchant_place']));

            return $hasMerchantPayload ? $item : [...$merchantPayload, ...$item];
        }, $items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertShoppingEditUnavailableTarget(
        Order $order,
        int $targetPickupLocationId,
        array $items,
        string $action,
        ?int $itemId,
    ): void {
        $order->loadMissing(['orderLocations.restaurant', 'items']);
        $pickup = $order->orderLocations->first(
            fn (OrderLocation $location): bool => (int) $location->id === $targetPickupLocationId
                && strtoupper((string) $location->location_role) === 'PICKUP'
        );
        if (! $pickup instanceof OrderLocation) {
            throw new ApiException('Merchant yang ingin diedit tidak valid.', 422);
        }

        $hasUnavailableItem = $order->items->contains(
            fn (OrderItem $item): bool => (int) ($item->pickup_location_id ?? 0) === $targetPickupLocationId
                && ! (bool) ($item->is_available ?? true)
        );
        if (! $hasUnavailableItem) {
            throw new ApiException('Edit merchant hanya tersedia jika ada item yang tidak tersedia.', 409);
        }

        if (in_array($action, ['UPDATE', 'REMOVE'], true)) {
            $targetItem = $order->items->first(fn (OrderItem $item): bool => (int) $item->id === (int) $itemId);
            if (! $targetItem instanceof OrderItem || (int) ($targetItem->pickup_location_id ?? 0) !== $targetPickupLocationId) {
                throw new ApiException('Item yang ingin diubah tidak sesuai merchant.', 422);
            }
            if ($action === 'REMOVE' && (bool) ($targetItem->is_available ?? true)) {
                throw new ApiException('Lanjut tanpa item hanya tersedia untuk item yang tidak tersedia.', 409);
            }
            if ($action === 'REMOVE') {
                $this->shoppingUnavailableItemDecisionService->assertCanContinueWithoutItem(
                    $order,
                    $targetPickupLocationId,
                    (int) $itemId
                );
            }
        }

        if ($action === 'CANCEL_MERCHANT') {
            return;
        }

        foreach ($items as $payload) {
            $candidate = $this->shoppingMerchantCandidateResolver->resolve($order, $payload);
            $resolvedPickup = $this->shoppingPickupLocationService->resolveForCandidate($order, $candidate);
            if (! $resolvedPickup instanceof OrderLocation || (int) $resolvedPickup->id !== $targetPickupLocationId) {
                throw new ApiException('Edit item tidak tersedia hanya boleh untuk merchant yang sama.', 409);
            }
        }
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
                $menu = $this->resolveShoppingMenu($restaurant, $payload);
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
    private function applyShoppingItemAdditions(
        Order $order,
        array $items,
        bool $allowNewMerchant = true,
    ): bool {
        $statusCode = $this->orderStatusCode($order);
        $routeChanged = false;
        $pickupLocationsByCandidateKey = [];

        foreach ($items as $payload) {
            $candidate = $this->shoppingMerchantCandidateResolver->resolve($order, $payload);
            $candidateKey = $candidate->key();
            $pickupLocation = $pickupLocationsByCandidateKey[$candidateKey] ?? null;

            if (! $pickupLocation) {
                $pickupLocation = $this->shoppingPickupLocationService->resolveForCandidate($order, $candidate);
            }

            if (! $pickupLocation) {
                if (! $allowNewMerchant || ! in_array($statusCode, ['PENDING', 'DRIVER_ASSIGNED'], true)) {
                    throw new ApiException('Merchant baru hanya bisa ditambahkan sebelum driver mulai belanja.', 409);
                }

                if ($this->shoppingPickupLocationService->activePickupCount($order) >= 3) {
                    throw new ApiException('Maksimal merchant Nitip adalah 3.', 422);
                }

                $pickupLocation = $this->shoppingPickupLocationService->createForCandidate($order, $candidate);
                $routeChanged = true;
            }

            $pickupLocationsByCandidateKey[$candidateKey] = $pickupLocation;

            $order->items()->create([
                ...$this->shoppingItemCreatePayload($candidate, $payload),
                'pickup_location_id' => $pickupLocation->id,
                'is_available' => true,
            ]);
        }

        if ($routeChanged) {
            $this->shoppingRouteService->applyRouteToOrder($order->refresh()->load(['orderLocations.restaurant']));
        }

        return $routeChanged;
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
            $this->applyShoppingItemAdditions(
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
    private function unavailableShoppingItemSnapshotsForPickup(Order $order, int $pickupLocationId): array
    {
        return $order->items()
            ->where('pickup_location_id', $pickupLocationId)
            ->where('is_available', false)
            ->lockForUpdate()
            ->get()
            ->map(fn (OrderItem $item): array => $this->shoppingUnavailableItemAuditSnapshot($item))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     */
    private function deleteShoppingItemsBySnapshots(Order $order, array $snapshots): void
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

    private function purgeTerminalShoppingUnavailableItems(
        Order $order,
        ?int $actorUserId,
        string $terminalStatusCode,
        string $triggerType,
    ): void {
        $order->loadMissing('serviceType');
        if (
            strtoupper((string) ($order->serviceType?->code ?? '')) !== 'SHOPPING'
            || ! in_array(strtoupper($terminalStatusCode), ['COMPLETED', 'CANCELLED', 'CANCELLED_WITH_FEE'], true)
        ) {
            return;
        }

        $items = $order->items()
            ->where('is_available', false)
            ->lockForUpdate()
            ->get();
        if ($items->isEmpty()) {
            return;
        }

        foreach ($items->groupBy(fn (OrderItem $item): string => (string) ($item->pickup_location_id ?? 'none')) as $group) {
            $snapshots = $group
                ->map(fn (OrderItem $item): array => $this->shoppingUnavailableItemAuditSnapshot($item))
                ->values()
                ->all();
            $pickupLocationId = $group->first()?->pickup_location_id;

            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => 'SHOPPING_ITEM_AVAILABILITY',
                'trigger_type' => $triggerType,
                'changed_by_user_id' => $actorUserId,
                'note' => 'Item tidak tersedia dihapus saat order Nitip mencapai status terminal.',
                'metadata' => [
                    'terminal_status_code' => strtoupper($terminalStatusCode),
                    'pickup_location_id' => $pickupLocationId !== null ? (int) $pickupLocationId : null,
                    'removed_items' => $snapshots,
                ],
            ]);
        }

        $items->each->delete();
        $order->unsetRelation('items');
    }

    /**
     * @return array<string, mixed>
     */
    private function shoppingUnavailableItemAuditSnapshot(OrderItem $item): array
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];

        return [
            'id' => (int) $item->id,
            'pickup_location_id' => $item->pickup_location_id !== null ? (int) $item->pickup_location_id : null,
            'name' => trim((string) ($item->menu_name ?? '')) ?: 'Item Nitip',
            'quantity' => max(1, (int) $item->quantity),
            'unit_price' => round((float) $item->unit_price, 2),
            'subtotal' => round((float) $item->subtotal, 2),
            'price_status' => $metadata['price_status'] ?? null,
            'failure_reason' => $metadata['failure_reason'] ?? null,
        ];
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function shoppingItemCreatePayload(ShoppingMerchantCandidate $candidate, array $payload): array
    {
        $itemSource = strtoupper((string) ($payload['item_source'] ?? 'MANUAL'));
        $quantity = max(1, (int) ($payload['quantity'] ?? 1));
        $notes = isset($payload['notes']) && trim((string) $payload['notes']) !== ''
            ? trim((string) $payload['notes'])
            : null;

        if ($itemSource === 'MENU_DB') {
            $merchant = $candidate->restaurant;
            if (! $merchant instanceof Restaurant) {
                throw new ApiException('Menu database hanya tersedia untuk merchant resmi BangDeliv.', 422);
            }

            $menu = $this->resolveShoppingMenu($merchant, $payload);
            if ($menu->price === null) {
                return [
                    'menu_id' => null,
                    'item_source' => 'MANUAL',
                    'menu_name' => (string) $menu->name,
                    'quantity' => $quantity,
                    'unit_price' => 0,
                    'subtotal' => 0,
                    'notes' => $notes,
                    'metadata' => [
                        ...$candidate->metadata(),
                        'price_status' => 'PENDING_DRIVER_INPUT',
                        'source' => 'CUSTOMER_MENU_DB_PENDING_PRICE',
                        'catalog_menu_id' => (int) $menu->id,
                    ],
                ];
            }

            $unitPrice = round((float) $menu->price, 2);

            return [
                'menu_id' => (int) $menu->id,
                'item_source' => 'MENU_DB',
                'menu_name' => (string) $menu->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => round($unitPrice * $quantity, 2),
                'notes' => $notes,
                'metadata' => [
                    ...$candidate->metadata(),
                    'price_status' => 'CONFIRMED',
                    'source' => 'CUSTOMER_MENU_DB',
                ],
            ];
        }

        $menuName = trim((string) ($payload['menu_name'] ?? ''));
        if ($menuName === '') {
            throw new ApiException('Nama item manual wajib diisi.', 422);
        }

        return [
            'menu_id' => null,
            'item_source' => 'MANUAL',
            'menu_name' => $menuName,
            'quantity' => $quantity,
            'unit_price' => 0,
            'subtotal' => 0,
            'notes' => $notes,
            'metadata' => [
                ...$candidate->metadata(),
                'price_status' => 'PENDING_DRIVER_INPUT',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveShoppingMenu(Restaurant $merchant, array $payload): Menu
    {
        $menuId = isset($payload['menu_id']) && is_numeric($payload['menu_id'])
            ? (int) $payload['menu_id']
            : 0;

        if ($menuId < 1) {
            throw new ApiException('Menu wajib dipilih untuk item katalog.', 422);
        }

        $menu = Menu::query()
            ->whereKey($menuId)
            ->where('restaurant_id', $merchant->id)
            ->where('is_available', true)
            ->first();

        if (! $menu) {
            throw new ApiException('Menu tidak ditemukan atau tidak sesuai merchant.', 422);
        }

        return $menu;
    }

    private function syncPrimaryRestaurantFromFirstPickup(Order $order): void
    {
        $order->unsetRelation('restaurant');
    }

    /**
     * @param  array<int, string>  $relations
     */
    private function lockedAssignedDriverOrder(int $orderId, int $driverId, array $relations = []): Order
    {
        $order = Order::query()
            ->with(array_values(array_unique($relations)))
            ->lockForUpdate()
            ->find($orderId);

        if (! $order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        if ((int) ($order->driver_id ?? 0) !== $driverId) {
            throw new ApiException('Order ini tidak ditugaskan kepada driver saat ini.', 403);
        }

        return $order;
    }

    private function refreshTotalsAfterDeliveryFeeChange(
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

        $this->broadcastContentUpdatedAfterCommit((int) $order->id, $triggerType, [
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

    private function broadcastShoppingNegotiationUpdated(int $orderId): bool
    {
        return $this->realtimeBroadcaster->orderContentUpdated($orderId, 'SHOPPING_NEGOTIATION_UPDATED');
    }

    private function broadcastDeliveryFeeNegotiationUpdated(int $orderId): bool
    {
        return $this->realtimeBroadcaster->orderContentUpdated($orderId, 'DELIVERY_FEE_NEGOTIATION_UPDATED');
    }

    private function notifyShoppingPriceChanged(
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

    private function notifyShoppingBypassTotalChanged(Order $order, User $actor): void
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

    private function notifyDeliveryFeeChanged(
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

    private function syncPendingPaymentAmount(Order $order): void
    {
        $this->orderPaymentService->syncPendingAmount($order);
    }

    /**
     * @return array{status_id:int,cancelled_by:string,cancellation_reason:string,cancelled_at:\Illuminate\Support\Carbon}
     */
    private function cancelledOrderUpdateAttributes(string $statusCode, string $cancelledBy, string $reason): array
    {
        return [
            'status_id' => $this->resolveStatusId($statusCode),
            'cancelled_by' => $cancelledBy,
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function driverSnapshot(Driver $driver): array
    {
        $driver->loadMissing('user');
        $user = $driver->user;

        return [
            'driver_id' => (int) $driver->id,
            'user_id' => (int) $driver->user_id,
            'name' => $user->name,
            'phone' => $user->phone,
            'vehicle_type' => $driver->vehicle_type,
            'vehicle_brand' => $driver->vehicle_brand,
            'vehicle_model' => $driver->vehicle_model,
            'vehicle_plate' => $driver->vehicle_plate,
        ];
    }

    private function orderStatusCode(Order $order): string
    {
        $status = $order->statusRef;

        return $status !== null
            ? OrderStatusCode::normalize($status->code)
            : '';
    }

    private function supportsDriverProofType(string $serviceCode, string $type): bool
    {
        return $this->proofPolicyService->supportsDriverProofType($serviceCode, $type);
    }

    private function unsupportedProofMessage(string $type, string $serviceCode): string
    {
        return $this->proofPolicyService->unsupportedProofMessage($type, $serviceCode);
    }

    private function isLifecycleProofType(string $type): bool
    {
        return $this->proofPolicyService->isLifecycleProofType($type);
    }

    private function normalizeProofType(string $type): string
    {
        return $this->proofPolicyService->normalizeProofType($type);
    }

    private function evidenceTypeForProof(string $type): string
    {
        return $this->proofPolicyService->evidenceTypeForProof($type);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordCodPayment(User $actor, int $orderId, array $payload, bool $enforceAssignedDriver): Order
    {
        return DB::transaction(function () use ($actor, $orderId, $payload, $enforceAssignedDriver): Order {
            $order = Order::query()
                ->with(['statusRef', 'driver.user', 'serviceType'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if ($this->orderPaymentService->isPaid($order)) {
                throw new ApiException('Pembayaran order ini sudah tercatat.', 409);
            }

            if (strtoupper((string) ($order->payment_method ?? 'COD')) === OrderPaymentService::METHOD_TRANSFER) {
                throw new ApiException('Order ini menggunakan pembayaran QRIS. Gunakan pencatatan QRIS.', 409);
            }

            $serviceCode = strtoupper((string) ($order->serviceType->code ?? ''));
            $statusCode = strtoupper((string) ($order->statusRef->code ?? ''));
            $isCourierPickupCollection = $serviceCode === 'COURIER' && $statusCode === 'ARRIVED_PICKUP';
            $isDeliveredCollection = $serviceCode !== 'COURIER' && $statusCode === 'DELIVERED';

            if (! $isCourierPickupCollection && ! $isDeliveredCollection) {
                $message = $serviceCode === 'COURIER'
                    ? 'Pembayaran COD courier hanya bisa dicatat saat driver tiba di pickup.'
                    : 'Pembayaran COD hanya bisa dicatat setelah order berstatus DELIVERED.';

                throw new ApiException($message, 409);
            }

            $orderDriverId = (int) ($order->driver_id ?? 0);
            if ($enforceAssignedDriver) {
                $driver = Driver::query()->where('user_id', $actor->id)->first();
                if (! $driver) {
                    throw new ApiException('Profil driver tidak ditemukan.', 403);
                }

                if ($orderDriverId === 0 || (int) $driver->id !== $orderDriverId) {
                    throw new ApiException('Order ini tidak ditugaskan kepada driver saat ini.', 403);
                }
            }

            $amount = round((float) $payload['amount'], 2);
            $expectedAmount = round((float) $order->total_price, 2);

            if ($amount !== $expectedAmount) {
                throw new ApiException('Nominal COD harus sama persis dengan total order.', 422, [
                    'expected_amount' => $expectedAmount,
                    'submitted_amount' => $amount,
                ]);
            }

            $paidAt = isset($payload['paid_at'])
                ? Carbon::parse((string) $payload['paid_at'])
                : now();

            $this->orderPaymentService->markPaid(
                $order,
                OrderPaymentService::METHOD_COD,
                $amount,
                $actor->id,
                $orderDriverId > 0 ? $orderDriverId : null,
                $paidAt,
                [
                    'recorded_by_role' => $actor->role,
                    'source' => $isCourierPickupCollection
                        ? 'COURIER_PICKUP_COLLECTION'
                        : ($enforceAssignedDriver ? 'DRIVER_COLLECTION' : 'ADMIN_MANUAL_RECORD'),
                    'extra' => $payload['metadata'] ?? null,
                ],
            );

            OrderLog::query()->create([
                'order_id' => $order->id,
                'log_type' => 'PAYMENT_UPDATE',
                'trigger_type' => $enforceAssignedDriver ? 'COD_PAYMENT_RECORDED_BY_DRIVER' : 'COD_PAYMENT_RECORDED_BY_ADMIN',
                'changed_by_user_id' => $actor->id,
                'note' => $payload['note'] ?? 'Pencatatan pembayaran COD.',
                'metadata' => [
                    'paid_amount' => $amount,
                    'expected_amount' => $expectedAmount,
                    'recorded_by_role' => $actor->role,
                ],
            ]);

            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => 'PAYMENT_UPDATE',
                'changed_by_user_id' => $actor->id,
                'note' => 'Pembayaran COD berhasil dicatat.',
                'metadata' => [
                    'paid_amount' => $amount,
                    'payment_status' => 'paid',
                ],
            ]);

            return $order->refresh()->load([
                'restaurant',
                'orderLocations',
                'items',
                'statusRef',
                'statusHistories.statusRef',
                'shoppingReceipt',
                'payments',
            ]);
        });
    }
}
