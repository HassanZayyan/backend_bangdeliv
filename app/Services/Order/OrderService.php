<?php

namespace App\Services\Order;

use App\Enums\OrderStatusCode;
use App\Enums\ServiceTypeCode;
use App\Exceptions\ApiException;
use App\Models\CourierOrder;
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
use App\Services\Driver\Dispatch\DriverCandidateSelector;
use App\Services\Driver\DriverOrderPayloadFactory;
use App\Services\Driver\DriverOrderRealtimeService;
use App\Services\Notification\OrderPricingPushNotificationService;
use App\Services\Notification\OrderRealtimeBroadcaster;
use App\Services\Notification\OrderStatusPushNotificationService;
use App\Services\Notification\PaymentProofReminderNotificationService;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingItemChangeRequestService;
use App\Services\Shopping\ShoppingMerchantCandidate;
use App\Services\Shopping\ShoppingMerchantCandidateResolver;
use App\Services\Shopping\ShoppingOrderCapabilityService;
use App\Services\Shopping\ShoppingPriceNegotiationService;
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
        private readonly DriverOrderRealtimeService $driverOrderRealtimeService,
        private readonly DriverCandidateSelector $driverCandidateSelector,
        private readonly OrderStatusPushNotificationService $orderStatusPushNotificationService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderTransferEvidenceService $transferEvidenceService,
        private readonly OrderProofPolicyService $proofPolicyService,
        private readonly ShoppingMerchantCandidateResolver $shoppingMerchantCandidateResolver,
        private readonly ShoppingPriceNegotiationService $shoppingPriceNegotiationService,
        private readonly ShoppingItemChangeRequestService $shoppingItemChangeRequestService,
        private readonly ShoppingOrderCapabilityService $shoppingOrderCapabilityService,
        private readonly ShoppingUnavailableItemDecisionService $shoppingUnavailableItemDecisionService,
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
            ->with(['restaurant', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'serviceType', 'courierOrder', 'feeLines',  'shoppingReceipt'])
            ->latest('id');

        if (! empty($filters['status'])) {
            $query->where('status_id', $this->resolveStatusId((string) $filters['status']));
        }

        return $query->paginate($perPage);
    }

    public function customerOrderDetail(User $user, int $orderId): Order
    {
        $order = Order::query()
            ->with(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'feeLines',  'shoppingReceipt'])
            ->find($orderId);

        if (! $order || $order->user_id !== $user->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        $freshOrder = $this->ensureDisplayRoutePolyline($order)
            ->fresh(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'feeLines',  'shoppingReceipt']);

        if (! $freshOrder instanceof Order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        $freshOrder->setAttribute('driver_eta', $this->driverArrivalEtaService->forCustomerTracking($freshOrder));

        return $freshOrder;
    }

    public function cancelByCustomer(User $user, int $orderId, string $reason): Order
    {
        $shouldBroadcastDriverOrderRemoved = false;

        $order = DB::transaction(function () use ($user, $orderId, $reason, &$shouldBroadcastDriverOrderRemoved): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'orderLocations', 'feeLines'])
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
                $this->shoppingPricingService->syncFeeLines($order, [[
                    'code' => 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS',
                    'label' => 'Penalty merchant gagal',
                    'amount' => round($cancellationPenalty, 2),
                ]]);

                if ($cancellationPenalty > 0) {
                    $cancelledStatusCode = 'CANCELLED_WITH_FEE';
                }
            }

            $cancelledStatusId = $this->resolveStatusId($cancelledStatusCode);
            $order->update($this->cancelledOrderUpdateAttributes($cancelledStatusCode, 'customer', $reason));

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
                    $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines',  'shoppingReceipt']),
                    $user->id,
                    $cancellationPenalty > 0 ? 'CUSTOMER_CANCEL_WITH_FEE' : 'CUSTOMER_CANCEL',
                    false
                );
            }

            return $order->refresh()->load(['restaurant', 'items', 'statusRef', 'statusHistories.statusRef', 'feeLines',  'shoppingReceipt']);
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

            if ($this->orderHasPaidPayment($order)) {
                throw new ApiException('Pembayaran order sudah lunas.', 409);
            }

            $photo = $payload['photo'] ?? null;
            if (! $photo instanceof UploadedFile) {
                throw new ApiException('Foto bukti QRIS wajib diupload.', 422);
            }

            $paymentMethod = $this->currentPaymentMethod($order);
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
                null,
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
                    'verification_status' => 'PENDING',
                ],
            ]);

            return $order->refresh();
        });

        $this->broadcastContentUpdatedAfterCommit((int) $order->id, 'TRANSFER_EVIDENCE_UPLOADED', [
            'total_price' => round((float) $order->total_price, 2),
            'payment_method' => OrderPaymentService::METHOD_TRANSFER,
            'payment_status' => 'unpaid',
        ]);

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
            'feeLines',

            'shoppingReceipt',
        ]);
    }

    public function recordFailedAttempt(
        User $actor,
        int $orderId,
        string $failureType,
        string $reason,
        ?int $pickupLocationId = null,
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

        $order = DB::transaction(function () use ($actor, $orderId, $normalizedFailureType, $reason, $pickupLocationId, &$statusChangeEventPayload): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'orderLocations', 'items', 'feeLines',  'shoppingReceipt'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Failed attempt hanya berlaku untuk order SHOPPING.', 409);
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

            if ($pickup !== null && in_array(strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')), ['FAILED', 'SKIPPED', 'REPLACED', 'COMPLETED'], true)) {
                throw new ApiException('Merchant ini sudah selesai atau ditandai tutup/gagal pickup.', 409);
            }

            $activeStatusCode = $order->statusRef?->code;
            if (! in_array($activeStatusCode, $this->failedAttemptRecordableStatuses, true)) {
                throw new ApiException('Failed attempt tidak bisa dicatat pada status order saat ini.', 409);
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

            if ($pickup !== null) {
                $pickup->update([
                    'fulfillment_status' => 'FAILED',
                    'failed_attempt_count' => min(255, (int) ($pickup->failed_attempt_count ?? 0) + 1),
                    'failure_reason' => $reason,
                    'failed_at' => now(),
                    'resolved_at' => null,
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

            $order->refresh()->load(['orderLocations.restaurant', 'items', 'feeLines',  'shoppingReceipt']);
            $nextFailedAttemptCount = $this->shoppingPricingService->failedAttemptCount($order);

            $this->shoppingRouteService->applyRouteToOrder($order);

            $pickupLocationIdForAudit = $pickup instanceof OrderLocation
                ? (int) $pickup->id
                : $pickupLocationId;

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'event_type' => 'FAILED_ATTEMPT_INCREMENT',
                'changed_by_user_id' => $actor->id,
                'note' => $reason,
                'price_snapshot' => [
                    'failure_type' => $normalizedFailureType,
                    'failed_attempt_count' => $nextFailedAttemptCount,
                    'pickup_location_id' => $pickupLocationIdForAudit,
                    'fulfillment_status' => $pickup !== null ? 'FAILED' : null,
                ],
            ]);

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
                ],
            ]);

            $recalculated = $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines',  'shoppingReceipt']),
                $actor->id,
                'SHOPPING_FAILED_ATTEMPT',
                false,
                $reason
            );

            if ($pickup !== null && ! $this->hasActiveShoppingPickupWithAvailableItems($recalculated)) {
                $withFee = $this->shoppingPricingService->isCancellationPenaltyEligible($recalculated);

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
     * @param  array<string, mixed>  $payload
     */
    public function recordCodPaymentByAdmin(User $actor, int $orderId, array $payload): Order
    {
        if ($actor->role !== 'admin') {
            throw new ApiException('Hanya admin yang dapat mencatat pembayaran COD di endpoint ini.', 403);
        }

        return $this->recordCodPayment($actor, $orderId, $payload, false);
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
                ->whereDoesntHave('statusHistories', function ($query) use ($actor): void {
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
        ]);

        $orders = Order::query()
            ->with(['user:id,name', 'statusRef:id,code,display_name', 'feeLines', 'statusHistories.statusRef'])
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
                $driverIncome = $this->driverHistoryIncomeAmount($order);

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
                    'total_price' => round((float) $order->total_price, 2),
                    'fee_lines' => $this->shoppingPricingService->feeBreakdownForOrder($order),
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

    private function driverHistoryIncomeAmount(Order $order): float
    {
        $deliveryFee = round((float) $order->delivery_fee, 2);
        if ($deliveryFee > 0) {
            return $deliveryFee;
        }

        $paidAmount = round((float) ($order->paid_amount ?? 0), 2);
        if ($paidAmount > 0) {
            return $paidAmount;
        }

        return round((float) $order->total_price, 2);
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
        $order->loadMissing(['serviceType', 'orderLocations.restaurant', 'items', 'feeLines',  'shoppingReceipt']);

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

        return $order->refresh()->load(['serviceType', 'orderLocations.restaurant', 'items', 'feeLines',  'shoppingReceipt']);
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
        $shouldSchedulePaymentReminder = false;

        $order = DB::transaction(function () use (
            $actor,
            $driver,
            $orderId,
            $actionCode,
            $targetStatusCode,
            $note,
            &$statusChangeEventPayload,
            &$shouldSchedulePaymentReminder,
        ): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'rideOrder', 'evidences', 'orderLocations', 'feeLines',  'shoppingReceipt'])
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

            if (($rule['requires_paid'] ?? false) && ! $this->orderHasPaidPayment($order)) {
                $this->paymentProofReminderNotificationService->sendIfNeeded($order, $actor);

                throw new ApiException('Pembayaran belum dicatat.', 409);
            }

            if (($rule['requires_unpaid'] ?? false) && $this->orderHasPaidPayment($order)) {
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
                ! $this->orderHasProof($order, 'pickup')
            ) {
                throw new ApiException('Bukti foto pickup belum diupload.', 409);
            }

            if (
                $this->supportsDriverProofType($serviceCode, 'delivery') &&
                $normalizedActionCode === 'COMPLETE_ORDER' &&
                ! $this->orderHasProof($order, 'delivery')
            ) {
                throw new ApiException('Bukti foto selesai pengantaran belum diupload.', 409);
            }

            $shoppingCancellationPenalty = null;
            if (strtoupper((string) $serviceCode) === 'SHOPPING' && $normalizedActionCode === 'CANCEL_WITH_FEE') {
                if (! $this->shoppingPricingService->isCancellationPenaltyEligible($order)) {
                    throw new ApiException('Order belum memenuhi batas failed attempt untuk dibatalkan dengan fee.', 409);
                }

                if ($this->orderHasPaidPayment($order)) {
                    throw new ApiException('Order sudah memiliki pembayaran lunas dan tidak bisa dibatalkan dengan fee.', 409);
                }

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

            if (in_array($resolvedTargetStatusCode, ['DELIVERED', 'COMPLETED'], true)) {
                $updates['delivered_at'] = now();
            }

            if (in_array($resolvedTargetStatusCode, ['CANCELLED', 'CANCELLED_WITH_FEE'], true)) {
                $updates['cancelled_by'] = 'driver';
                $updates['cancellation_reason'] = $eventNote;
                $updates['cancelled_at'] = now();
            }

            $order->update($updates);
            $this->syncDriverServiceTimestamp($order, $resolvedTargetStatusCode);
            if (! $this->isRunningDriverStatusCode($resolvedTargetStatusCode)) {
                $this->syncDriverAvailabilityAfterNonRunningOrder($driver->id);
            }

            $snapshot = [
                'action_code' => $normalizedActionCode,
                'service_type' => $serviceCode,
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
            $shouldSchedulePaymentReminder = $resolvedTargetStatusCode === 'DELIVERED';

            if ($shoppingCancellationPenalty !== null) {
                $this->shoppingPricingService->syncFeeLines($order, [[
                    'code' => 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS',
                    'label' => 'Penalty merchant gagal',
                    'amount' => round($shoppingCancellationPenalty, 2),
                ]]);

                $order = $this->shoppingPricingService->recalculate(
                    $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines',  'shoppingReceipt']),
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
        if ($shouldSchedulePaymentReminder) {
            $this->paymentProofReminderNotificationService->scheduleAfterDelivered($order->refresh());
        }

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
        return OrderStatusHistory::query()
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

        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status_id' => $order->status_id,
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

            $pickup = $this->shoppingPickupById($order, $pickupLocationId);
            if (strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')) !== 'ITEMS_CONFIRMED') {
                throw new ApiException('Harga merchant hanya bisa dikirim setelah item merchant fix.', 409);
            }

            $note = trim((string) ($payload['note'] ?? ''));
            $trigger = $this->shoppingPriceNegotiationService->nextDriverQuoteTrigger($order, $pickup?->id);

            $this->shoppingPriceNegotiationService->record(
                $order,
                $trigger,
                $actor->id,
                $note !== '' ? $note : 'Driver mengirim quote harga Nitip.',
                [
                    'pickup_location_id' => $pickup?->id,
                    'quoted_amount' => $amount,
                    'amount' => $amount,
                    'status' => 'PENDING_CUSTOMER',
                ]
            );

            $pickup->update([
                'fulfillment_status' => 'PRICE_PENDING_CUSTOMER',
                'resolved_at' => null,
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
                ['statusRef', 'serviceType', 'orderLocations', 'items', 'feeLines', 'shoppingReceipt']
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

            $pickup = $this->shoppingPickupById($order, $pickupLocationId);
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
                'resolved_at' => now(),
            ]);

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines', 'shoppingReceipt', 'orderLocations']),
                $actor->id,
                'MERCHANT_PRICE_APPROVED_BY_DRIVER_BYPASS',
                true,
                'Harga merchant dilanjutkan oleh driver tanpa respons customer.'
            );
        });

        $this->broadcastShoppingNegotiationUpdated((int) $order->id);
        $this->notifyShoppingPriceChanged($order, $actor, 'customer', false);

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
                ->with(['statusRef', 'serviceType', 'orderLocations', 'items', 'feeLines', 'shoppingReceipt'])
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

        $freshOrder = $order->fresh(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'feeLines',  'shoppingReceipt']);
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
                ->with(['statusRef', 'serviceType', 'items', 'orderLocations.restaurant', 'feeLines', 'shoppingReceipt'])
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

            if ($action === 'ADD' && $items === []) {
                throw new ApiException('Minimal satu item belanja wajib diajukan.', 422);
            }

            if (in_array($action, ['UPDATE', 'REMOVE'], true) && ($itemId ?? 0) <= 0) {
                throw new ApiException('Item yang ingin diubah wajib dipilih.', 422);
            }

            $targetPickupLocationId = isset($payload['target_pickup_location_id']) && is_numeric($payload['target_pickup_location_id'])
                ? (int) $payload['target_pickup_location_id']
                : null;

            if ($requestKind === 'EDIT_UNAVAILABLE' && $targetPickupLocationId !== null && in_array($action, ['ADD', 'UPDATE'], true)) {
                $items = $this->withTargetPickupMerchantPayload($order, $items, $targetPickupLocationId);
            }

            if ($requestKind === 'EDIT_UNAVAILABLE') {
                if (! $this->shoppingOrderCapabilityService->canCustomerEditUnavailableItems($order)) {
                    throw new ApiException('Edit item hanya tersedia saat ada item merchant yang tidak tersedia.', 409);
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

        return $order->fresh(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'feeLines', 'shoppingReceipt']);
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
                ['statusRef', 'serviceType', 'items', 'orderLocations.restaurant', 'feeLines', 'shoppingReceipt']
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
                $this->shoppingPickupById($order, $targetPickupLocationId)->update([
                    'fulfillment_status' => 'ITEMS_CONFIRMED',
                    'resolved_at' => now(),
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
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines', 'shoppingReceipt']),
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

        $pickup = $this->shoppingPickupById($order->refresh()->load(['orderLocations']), $targetPickupLocationId);
        $pickup->update([
            'fulfillment_status' => 'ITEMS_CONFIRMED',
            'resolved_at' => now(),
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
            $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines', 'shoppingReceipt']),
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

        $pickup = $this->shoppingPickupById($order, $pickupLocationId);
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
            'resolved_at' => now(),
        ]);

        return $this->shoppingPricingService->recalculate(
            $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines', 'shoppingReceipt', 'orderLocations']),
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
        $reason = 'Resto tutup/order batal.';

        $this->markShoppingPickupFailedForCustomerCancel($order, $pickup, $reason);

        $order->refresh()->load(['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'feeLines', 'shoppingReceipt']);
        $failedAttemptCount = $this->shoppingPricingService->failedAttemptCount($order);

        $this->shoppingRouteService->applyRouteToOrder($order);

        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status_id' => $order->status_id,
            'event_type' => 'FAILED_ATTEMPT_INCREMENT',
            'changed_by_user_id' => $actor->id,
            'note' => $reason,
            'price_snapshot' => [
                'failure_type' => 'CUSTOMER_CANCEL_MERCHANT',
                'failed_attempt_count' => $failedAttemptCount,
                'pickup_location_id' => $pickup?->id,
                'fulfillment_status' => 'FAILED',
            ],
        ]);

        $this->shoppingPriceNegotiationService->record(
            $order,
            ShoppingPriceNegotiationService::CUSTOMER_CANCEL_MERCHANT,
            $actor->id,
            $reason,
            [
                'pickup_location_id' => $pickup?->id,
                'failed_attempt_count' => $failedAttemptCount,
                'status' => 'CANCELLED_MERCHANT',
            ]
        );

        $order = $this->shoppingPricingService->recalculate(
            $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines', 'shoppingReceipt']),
            $actor->id,
            'CUSTOMER_CANCEL_MERCHANT',
            false,
            $reason
        );

        $hasActivePickup = $this->hasActiveShoppingPickupWithAvailableItems($order);
        $withFee = $this->shoppingPricingService->isCancellationPenaltyEligible($order);
        if (! $hasActivePickup || $withFee) {
            return $this->cancelShoppingOrderWithOptionalFee(
                $actor,
                $order,
                ! $hasActivePickup ? 'Semua merchant Nitip batal/gagal.' : $reason,
                $withFee,
                $withFee ? 'CUSTOMER_CANCEL_MERCHANT_WITH_FEE' : 'CUSTOMER_CANCEL_LAST_MERCHANT',
                $statusChangeEventPayload
            );
        }

        return $order->refresh()->load(['statusRef', 'serviceType', 'orderLocations', 'items', 'feeLines', 'shoppingReceipt']);
    }

    private function cancelShoppingOrderFromNegotiation(User $actor, Order $order, mixed &$statusChangeEventPayload): Order
    {
        $withFee = $this->shoppingPricingService->isCancellationPenaltyEligible($order);

        $this->shoppingPriceNegotiationService->record(
            $order,
            ShoppingPriceNegotiationService::CUSTOMER_CANCEL_ORDER,
            $actor->id,
            'Customer membatalkan order Nitip dari negosiasi harga.',
            [
                'failed_attempt_count' => $this->shoppingPricingService->failedAttemptCount($order),
                'with_fee' => $withFee,
            ]
        );

        return $this->cancelShoppingOrderWithOptionalFee(
            $actor,
            $order,
            $withFee
                ? 'Order Nitip dibatalkan dengan fee setelah merchant gagal.'
                : 'Order Nitip dibatalkan customer.',
            $withFee,
            $withFee ? 'CUSTOMER_CANCEL_ORDER_WITH_FEE' : 'CUSTOMER_CANCEL_ORDER',
            $statusChangeEventPayload
        );
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

        if ($withFee) {
            $penalty = $this->shoppingPricingService->calculateCancellationPenalty($order);
            if ($penalty <= 0) {
                throw new ApiException('Penalty pembatalan belum dapat dihitung.', 409);
            }

            $this->shoppingPricingService->syncFeeLines($order, [[
                'code' => 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS',
                'label' => 'Penalty merchant gagal',
                'amount' => round($penalty, 2),
            ]]);
        }

        $order->update($this->cancelledOrderUpdateAttributes($statusCode, $cancelledBy, $reason));

        $statusHistory = OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status_id' => $statusId,
            'event_type' => 'STATUS_CHANGE',
            'changed_by_user_id' => $actor->id,
            'note' => $reason,
            'price_snapshot' => [
                'cancellation_penalty' => round($penalty, 2),
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
            $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines', 'shoppingReceipt']),
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

        return $order->refresh()->load(['statusRef', 'serviceType', 'orderLocations', 'items', 'feeLines', 'shoppingReceipt']);
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

    private function shoppingPickupById(Order $order, int $pickupLocationId): OrderLocation
    {
        $order->loadMissing('orderLocations');

        $pickup = $order->orderLocations->first(
            fn (OrderLocation $location): bool => (int) $location->id === $pickupLocationId
                && strtoupper((string) $location->location_role) === 'PICKUP'
        );

        if (! $pickup instanceof OrderLocation) {
            throw new ApiException('Merchant/pickup order tidak valid.', 422);
        }

        return $pickup;
    }

    private function markShoppingPickupFailedForCustomerCancel(Order $order, ?OrderLocation $pickup, string $reason): void
    {
        if (! $pickup instanceof OrderLocation) {
            throw new ApiException('Merchant/pickup order tidak valid.', 422);
        }

        $pickup->update([
            'fulfillment_status' => 'FAILED',
            'failed_attempt_count' => min(255, (int) ($pickup->failed_attempt_count ?? 0) + 1),
            'failure_reason' => $reason,
            'failed_at' => now(),
            'resolved_at' => null,
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

    public function markShoppingMerchantOpen(User $actor, int $orderId, int $pickupLocationId): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);
        $statusChangeEventPayload = null;

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $pickupLocationId, &$statusChangeEventPayload): Order {
            $order = $this->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'feeLines', 'shoppingReceipt']
            );

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Konfirmasi merchant buka hanya tersedia untuk order SHOPPING.', 409);
            }

            $statusCode = $this->orderStatusCode($order);
            if (! in_array($statusCode, ['DRIVER_ASSIGNED', 'ARRIVED_MERCHANT'], true)) {
                throw new ApiException('Merchant hanya bisa dikonfirmasi buka saat driver menuju atau tiba di merchant.', 409);
            }

            $pickup = $this->shoppingPickupById($order, $pickupLocationId);
            $fulfillmentStatus = strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING'));
            if (in_array($fulfillmentStatus, ['FAILED', 'SKIPPED', 'REPLACED', 'COMPLETED'], true)) {
                throw new ApiException('Merchant ini sudah selesai atau batal.', 409);
            }

            if ($fulfillmentStatus === 'PENDING') {
                $pickup->update([
                    'fulfillment_status' => 'OPEN_CONFIRMED',
                    'resolved_at' => null,
                ]);
            }

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

            if ($statusCode === 'DRIVER_ASSIGNED') {
                $previousStatusCode = $statusCode;
                $targetStatusId = $this->resolveStatusId('ARRIVED_MERCHANT');
                $order->update(['status_id' => $targetStatusId]);
                $statusHistory = OrderStatusHistory::query()->create([
                    'order_id' => $order->id,
                    'status_id' => $targetStatusId,
                    'event_type' => 'STATUS_CHANGE',
                    'changed_by_user_id' => $actor->id,
                    'note' => 'Driver mulai memproses merchant Nitip.',
                    'price_snapshot' => [
                        'action_code' => 'MERCHANT_OPEN_CONFIRMED',
                        'pickup_location_id' => (int) $pickup->id,
                    ],
                ]);

                $statusChangeEventPayload = $this->buildOrderStatusBroadcastPayload(
                    $order->id,
                    'ARRIVED_MERCHANT',
                    $previousStatusCode,
                    $statusHistory
                );
            }

            return $order->refresh()->load(['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'feeLines', 'shoppingReceipt']);
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

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'items', 'orderLocations', 'feeLines',  'shoppingReceipt'])
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
                $targetPickup = $this->shoppingPickupById($order, $targetPickupLocationId);
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
            $submittedHasHeavyItem = false;

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
                $isAvailable = array_key_exists('is_available', $itemPayload)
                    ? (bool) $itemPayload['is_available']
                    : (bool) $item->is_available;
                $isHeavy = array_key_exists('is_heavy', $itemPayload)
                    ? (bool) $itemPayload['is_heavy']
                    : (bool) $item->is_heavy;
                $itemChanged = $quantity !== (int) $item->quantity
                    || $unitPrice !== (float) $item->unit_price
                    || $isAvailable !== (bool) $item->is_available
                    || $isHeavy !== (bool) $item->is_heavy;
                $requiresRequote = $requiresRequote || $itemChanged;
                if ($itemChanged && $item->pickup_location_id !== null) {
                    $affectedPickupIds[(int) $item->pickup_location_id] = true;
                }
                if ($itemPickupId !== null) {
                    $submittedPickupIds[$itemPickupId] = true;
                }
                $submittedItemIds[] = (int) $item->id;
                $submittedHasUnavailableItem = $submittedHasUnavailableItem || ! $isAvailable;
                $submittedHasHeavyItem = $submittedHasHeavyItem || $isHeavy;

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
                    'is_heavy' => $isHeavy,
                    'metadata' => $metadata === [] ? null : $metadata,
                ]);
            }

            if ($targetPickupLocationId === null && count($submittedPickupIds) === 1) {
                $targetPickupLocationId = (int) array_key_first($submittedPickupIds);
                $targetPickup = $this->shoppingPickupById($order, $targetPickupLocationId);
            }

            if ($targetPickupLocationId !== null) {
                $targetPickup ??= $this->shoppingPickupById($order, $targetPickupLocationId);
                $fulfillmentStatus = strtoupper((string) ($targetPickup->fulfillment_status ?? 'PENDING'));
                if (! in_array($fulfillmentStatus, ['OPEN_CONFIRMED', 'ITEMS_PENDING_CUSTOMER', 'ITEMS_CONFIRMED'], true)) {
                    throw new ApiException('Konfirmasi Resto buka sebelum mengecek item merchant ini.', 409);
                }
            }

            if ($targetPickupLocationId !== null) {
                OrderLog::query()->create([
                    'order_id' => $order->id,
                    'event_type' => 'SHOPPING_ITEM_AVAILABILITY',
                    'trigger_type' => 'DRIVER_CONFIRMED_ITEM_AVAILABILITY',
                    'changed_by_user_id' => $actor->id,
                    'note' => 'Driver mencatat ketersediaan item merchant.',
                    'metadata' => [
                        'pickup_location_id' => $targetPickupLocationId,
                        'item_ids' => $submittedItemIds,
                        'has_unavailable_item' => $submittedHasUnavailableItem,
                        'has_heavy_item' => $submittedHasHeavyItem,
                    ],
                ]);

                $targetPickup->update([
                    'fulfillment_status' => $submittedHasUnavailableItem
                        ? 'ITEMS_PENDING_CUSTOMER'
                        : 'ITEMS_CONFIRMED',
                    'resolved_at' => $submittedHasUnavailableItem ? null : now(),
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

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines',  'shoppingReceipt']),
                $actor->id,
                'DRIVER_RECEIPT_UPDATE',
                true,
                'Driver memperbarui ketersediaan item Nitip.'
            );
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
    public function updateDeliveryFeeOverride(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->lockedAssignedDriverOrder($orderId, $driver->id, ['statusRef', 'serviceType', 'payments', 'payment', 'evidences', 'courierOrder']);

            $manualAmount = array_key_exists('amount', $payload) && $payload['amount'] !== null
                ? round(max(0.0, (float) $payload['amount']), 2)
                : null;
            $reason = trim((string) ($payload['reason'] ?? ''));
            $hasCarefulCarryInput = array_key_exists('careful_carry_required', $payload);
            $carefulCarryRequired = array_key_exists('careful_carry_required', $payload)
                ? (bool) $payload['careful_carry_required']
                : $this->carefulCarryRequired($order);
            $serviceCode = (string) ($order->serviceType->code ?? '');

            if (! $this->supportsCarefulCarry($serviceCode)) {
                if (array_key_exists('careful_carry_required', $payload) && (bool) $payload['careful_carry_required']) {
                    throw new ApiException('Perlu 2 orang hanya tersedia untuk order kurir.', 422);
                }

                $carefulCarryRequired = false;
            }

            if (! $this->deliveryFeeNegotiationService->canDriverSubmitQuote($order)) {
                throw new ApiException('Revisi ongkir tidak tersedia untuk status atau pembayaran order ini.', 409);
            }

            if ($manualAmount !== null && $manualAmount <= 0) {
                throw new ApiException('Nominal ongkir manual harus lebih dari 0.', 422);
            }

            if ($manualAmount === null && ! $hasCarefulCarryInput) {
                throw new ApiException('Nominal ongkir manual harus lebih dari 0.', 422);
            }

            if (
                $manualAmount === null &&
                $carefulCarryRequired === $this->carefulCarryRequired($order)
            ) {
                throw new ApiException('Tidak ada perubahan revisi ongkir.', 422);
            }

            if ($manualAmount !== null && $reason === '') {
                throw new ApiException('Alasan edit ongkir wajib diisi.', 422);
            }

            if ($reason === '') {
                $reason = $carefulCarryRequired
                    ? 'Perlu 2 orang diaktifkan.'
                    : 'Perlu 2 orang dinonaktifkan.';
            }

            $quoteAmounts = $this->deliveryFeeNegotiationService->quoteAmounts(
                $order,
                $manualAmount,
                $carefulCarryRequired
            );
            if ($quoteAmounts['final_amount'] <= 0) {
                throw new ApiException('Nominal ongkir manual harus lebih dari 0.', 422);
            }

            $trigger = $this->deliveryFeeNegotiationService->nextDriverQuoteTrigger($order);
            $this->deliveryFeeNegotiationService->record(
                $order,
                $trigger,
                $actor->id,
                $reason,
                [
                    'old_delivery_fee' => round((float) $order->delivery_fee, 2),
                    'base_amount' => $quoteAmounts['base_amount'],
                    'careful_carry_surcharge' => $quoteAmounts['careful_carry_surcharge'],
                    'quoted_amount' => $quoteAmounts['final_amount'],
                    'final_amount' => $quoteAmounts['final_amount'],
                    'amount' => $quoteAmounts['final_amount'],
                    'careful_carry_required' => $carefulCarryRequired,
                    'delivery_fee_source' => 'driver_manual',
                    'status' => 'PENDING_CUSTOMER',
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
    public function acceptDeliveryFeeCounterByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->lockedAssignedDriverOrder($orderId, $driver->id, ['statusRef', 'serviceType', 'payments', 'payment', 'evidences', 'courierOrder', 'items', 'feeLines', 'shoppingReceipt']);
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
                    'careful_carry_surcharge' => $snapshot['careful_carry_surcharge'] ?? null,
                    'quoted_amount' => $snapshot['quoted_amount'] ?? null,
                    'counter_base_amount' => $snapshot['counter_base_amount'] ?? $counterAmount,
                    'counter_amount' => $counterAmount,
                    'approved_amount' => $counterAmount,
                    'final_amount' => $counterAmount,
                    'careful_carry_required' => $snapshot['careful_carry_required'] ?? $this->carefulCarryRequired($order),
                    'delivery_fee_source' => 'driver_manual',
                    'status' => 'APPROVED',
                ]
            );

            return $this->applyApprovedDeliveryFeeOverride(
                $order,
                $actor->id,
                $counterAmount,
                (bool) ($snapshot['careful_carry_required'] ?? $this->carefulCarryRequired($order)),
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
                ->with(['statusRef', 'serviceType', 'payments', 'payment', 'evidences', 'courierOrder', 'items', 'feeLines', 'shoppingReceipt'])
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

        $freshOrder = $order->fresh(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'feeLines', 'shoppingReceipt']);
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
                'careful_carry_surcharge' => $snapshot['careful_carry_surcharge'] ?? null,
                'quoted_amount' => $quotedAmount,
                'approved_amount' => $quotedAmount,
                'final_amount' => $quotedAmount,
                'careful_carry_required' => $snapshot['careful_carry_required'] ?? $this->carefulCarryRequired($order),
                'delivery_fee_source' => 'driver_manual',
                'status' => 'APPROVED',
            ]
        );

        return $this->applyApprovedDeliveryFeeOverride(
            $order,
            $actor->id,
            $quotedAmount,
            (bool) ($snapshot['careful_carry_required'] ?? $this->carefulCarryRequired($order)),
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

        $carefulCarryRequired = (bool) ($snapshot['careful_carry_required'] ?? $this->carefulCarryRequired($order));
        $counterAmounts = $this->deliveryFeeNegotiationService->quoteAmounts(
            $order,
            $counterAmount,
            $carefulCarryRequired
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
                'careful_carry_surcharge' => $counterAmounts['careful_carry_surcharge'],
                'careful_carry_required' => $carefulCarryRequired,
                'delivery_fee_source' => 'driver_manual',
                'status' => 'PENDING_DRIVER',
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
        bool $carefulCarryRequired,
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
        $this->setCourierCarefulCarryRequired($order->refresh(), $carefulCarryRequired);

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
            $fileUrl = $this->storeOrderPhoto($photo, $order->id, 'proofs');

            OrderEvidence::query()->create([
                'order_id' => $order->id,
                'driver_id' => $driver->id,
                'evidence_type' => $evidenceType,
                'file_url' => $fileUrl,
                'uploaded_at' => now(),
                'notes' => $payload['note'] ?? null,
            ]);

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
                ['statusRef', 'serviceType', 'items', 'feeLines',  'shoppingReceipt']
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
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines', 'shoppingReceipt', 'orderLocations']),
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
                    'resolved_at' => now(),
                ]);

            $receiptPhoto = $payload['receipt_photo'] ?? null;
            if ($receiptPhoto instanceof UploadedFile) {
                $fileUrl = $this->storeOrderPhoto($receiptPhoto, $order->id, 'receipts');
                OrderEvidence::query()->create([
                    'order_id' => $order->id,
                    'driver_id' => $driver->id,
                    'evidence_type' => 'SHOPPING_RECEIPT',
                    'file_url' => $fileUrl,
                    'uploaded_at' => now(),
                    'notes' => null,
                ]);
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
            $order = $this->lockedAssignedDriverOrder($orderId, $driver->id, ['statusRef', 'serviceType']);
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

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'event_type' => 'PAYMENT_UPDATE',
                'changed_by_user_id' => $actor->id,
                'note' => 'Pembayaran QRIS berhasil dicatat.',
                'price_snapshot' => [
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
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines',  'shoppingReceipt']),
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
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines',  'shoppingReceipt']),
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
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines',  'shoppingReceipt']),
                $user->id,
                $routeChanged ? 'SHOPPING_ROUTE_UPDATED' : 'CUSTOMER_REMOVE_ITEM',
                true,
                $routeChanged
                    ? 'Customer menghapus item terakhir pada merchant sehingga rute dihitung ulang.'
                    : 'Customer menghapus item belanja.'
            );
        });
    }

    private function hasActiveShoppingPickupWithAvailableItems(Order $order): bool
    {
        $order->loadMissing(['orderLocations', 'items']);

        return $order->orderLocations
            ->filter(function (OrderLocation $location): bool {
                if (strtoupper((string) $location->location_role) !== 'PICKUP') {
                    return false;
                }

                return ! in_array(
                    strtoupper((string) ($location->fulfillment_status ?? 'PENDING')),
                    ['FAILED', 'SKIPPED', 'REPLACED', 'CANCELLED'],
                    true
                );
            })
            ->contains(function (OrderLocation $pickup) use ($order): bool {
                $pickupId = (int) $pickup->id;

                return $order->items->contains(
                    fn (OrderItem $item): bool => (int) ($item->pickup_location_id ?? 0) === $pickupId
                        && (bool) ($item->is_available ?? true)
                );
            });
    }

    private function activeShoppingPickupCount(Order $order): int
    {
        return OrderLocation::query()
            ->where('order_id', $order->id)
            ->where('location_role', 'PICKUP')
            ->whereNotIn('fulfillment_status', ['FAILED', 'SKIPPED', 'REPLACED', 'CANCELLED'])
            ->count();
    }

    private function resolveStatusId(string $input): int
    {
        $normalized = strtoupper(str_replace([' ', '-'], '_', trim($input)));

        $legacyMap = [
            'CONFIRMED' => 'PENDING',
            'DRIVER_ASSIGNED' => 'DRIVER_ASSIGNED',
            'ITEM_UNAVAILABLE' => 'COMPLAINT',
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
            ->with(['items', 'statusRef', 'serviceType', 'restaurant', 'orderLocations.restaurant', 'feeLines',  'shoppingReceipt'])
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

        $merchantPayload = $this->merchantPayloadFromPickup(
            $this->shoppingPickupById($order, $targetPickupLocationId)
        );

        return array_map(function (array $item) use ($merchantPayload): array {
            $hasMerchantPayload = (isset($item['merchant_id']) && is_numeric($item['merchant_id']))
                || (isset($item['merchant_place']) && is_array($item['merchant_place']));

            return $hasMerchantPayload ? $item : [...$merchantPayload, ...$item];
        }, $items);
    }

    /**
     * @return array<string, mixed>
     */
    private function merchantPayloadFromPickup(OrderLocation $pickup): array
    {
        if ($pickup->restaurant_id !== null) {
            return ['merchant_id' => (int) $pickup->restaurant_id];
        }

        return [
            'merchant_place' => [
                'place_id' => null,
                'name' => $this->pickupMerchantName($pickup),
                'address' => (string) $pickup->full_address,
                'latitude' => (float) $pickup->latitude,
                'longitude' => (float) $pickup->longitude,
                'types' => [],
            ],
        ];
    }

    private function pickupMerchantName(OrderLocation $pickup): string
    {
        foreach ([$pickup->contact_name, $pickup->label] as $candidate) {
            $name = trim((string) ($candidate ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return 'Merchant';
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
            $resolvedPickup = $this->resolvePickupLocationForCandidate($order, $candidate);
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
                $enriched['menu_id'] = (int) $menu->id;
                $enriched['menu_name'] = (string) $menu->name;
                $enriched['unit_price'] = round((float) $menu->price, 2);
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
                $pickup = $this->resolvePickupLocationForCandidate($order, $candidate);
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
                $pickupLocation = $this->resolvePickupLocationForCandidate($order, $candidate);
            }

            if (! $pickupLocation) {
                if (! $allowNewMerchant || ! in_array($statusCode, ['PENDING', 'DRIVER_ASSIGNED'], true)) {
                    throw new ApiException('Merchant baru hanya bisa ditambahkan sebelum driver mulai belanja.', 409);
                }

                if ($this->activeShoppingPickupCount($order) >= 3) {
                    throw new ApiException('Maksimal merchant Nitip adalah 3.', 422);
                }

                $pickupLocation = $this->createPickupLocationForCandidate($order, $candidate);
                $routeChanged = true;
            }

            $pickupLocationsByCandidateKey[$candidateKey] = $pickupLocation;

            $order->items()->create([
                ...$this->shoppingItemCreatePayload($candidate, $payload),
                'pickup_location_id' => $pickupLocation->id,
                'is_available' => true,
                'is_heavy' => false,
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
            throw new ApiException('Menu tidak ditemukan, tidak aktif, atau tidak sesuai merchant.', 422);
        }

        return $menu;
    }

    private function resolvePickupLocationForCandidate(Order $order, ShoppingMerchantCandidate $candidate): ?OrderLocation
    {
        if ($candidate->restaurant instanceof Restaurant) {
            return $this->resolvePickupLocationForMerchant($order, $candidate->restaurant);
        }

        return $this->resolvePickupLocationForExternalPlace($order, $candidate);
    }

    private function resolvePickupLocationForMerchant(Order $order, Restaurant $merchant): ?OrderLocation
    {
        $order->loadMissing(['orderLocations.restaurant']);

        $pickup = $order->orderLocations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->first(function (OrderLocation $location) use ($merchant): bool {
                if ((int) $location->restaurant_id !== (int) $merchant->id) {
                    return false;
                }

                return ! in_array(
                    strtoupper((string) ($location->fulfillment_status ?? 'PENDING')),
                    ['FAILED', 'SKIPPED', 'REPLACED'],
                    true
                );
            });

        if ($pickup instanceof OrderLocation) {
            return $pickup;
        }

        if ((int) $order->restaurant_id === (int) $merchant->id) {
            $legacyPickup = $order->orderLocations
                ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
                ->first(fn (OrderLocation $location): bool => $location->restaurant_id === null);

            if ($legacyPickup instanceof OrderLocation) {
                $legacyPickup->update([
                    'restaurant_id' => $merchant->id,
                    'label' => $legacyPickup->label ?: 'Merchant',
                    'contact_name' => $legacyPickup->contact_name ?: $merchant->name,
                    'contact_phone' => $legacyPickup->contact_phone ?: $merchant->phone,
                    'full_address' => $legacyPickup->full_address ?: $merchant->address,
                    'latitude' => $legacyPickup->latitude ?: $merchant->latitude,
                    'longitude' => $legacyPickup->longitude ?: $merchant->longitude,
                ]);

                return $legacyPickup->refresh();
            }
        }

        return null;
    }

    private function resolvePickupLocationForExternalPlace(Order $order, ShoppingMerchantCandidate $candidate): ?OrderLocation
    {
        $order->loadMissing(['orderLocations.restaurant']);

        return $order->orderLocations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->first(function (OrderLocation $location) use ($candidate): bool {
                if ($location->restaurant_id !== null) {
                    return false;
                }

                if (in_array(
                    strtoupper((string) ($location->fulfillment_status ?? 'PENDING')),
                    ['FAILED', 'SKIPPED', 'REPLACED'],
                    true
                )) {
                    return false;
                }

                $sameName = $this->normalizeShoppingLocationText((string) ($location->contact_name ?? ''))
                    === $candidate->normalizedName();
                if (! $sameName) {
                    return false;
                }

                return $this->roughDistanceMeters(
                    (float) $location->latitude,
                    (float) $location->longitude,
                    $candidate->latitude,
                    $candidate->longitude,
                ) <= 30;
            });
    }

    private function createPickupLocationForCandidate(Order $order, ShoppingMerchantCandidate $candidate): OrderLocation
    {
        if ($candidate->restaurant instanceof Restaurant) {
            return $this->createPickupLocationForMerchant($order, $candidate->restaurant);
        }

        return $this->createPickupLocationForExternalPlace($order, $candidate);
    }

    private function createPickupLocationForMerchant(Order $order, Restaurant $merchant): OrderLocation
    {
        $maxSequence = (int) $order->orderLocations()->max('sequence_no');

        return $order->orderLocations()->create([
            'restaurant_id' => $merchant->id,
            'location_role' => 'PICKUP',
            'label' => 'Merchant',
            'contact_name' => $merchant->name,
            'contact_phone' => $merchant->phone,
            'full_address' => $merchant->address,
            'latitude' => $merchant->latitude,
            'longitude' => $merchant->longitude,
            'sequence_no' => max(1, $maxSequence + 1),
        ]);
    }

    private function createPickupLocationForExternalPlace(Order $order, ShoppingMerchantCandidate $candidate): OrderLocation
    {
        $maxSequence = (int) $order->orderLocations()->max('sequence_no');

        return $order->orderLocations()->create([
            'restaurant_id' => null,
            'location_role' => 'PICKUP',
            'label' => 'Merchant',
            'contact_name' => $candidate->name,
            'contact_phone' => null,
            'full_address' => $candidate->address,
            'latitude' => $candidate->latitude,
            'longitude' => $candidate->longitude,
            'sequence_no' => max(1, $maxSequence + 1),
        ]);
    }

    private function normalizeShoppingLocationText(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function roughDistanceMeters(
        float $originLatitude,
        float $originLongitude,
        float $targetLatitude,
        float $targetLongitude
    ): float {
        $earthRadiusMeters = 6371000.0;
        $originLatitudeRad = deg2rad($originLatitude);
        $targetLatitudeRad = deg2rad($targetLatitude);
        $deltaLatitudeRad = deg2rad($targetLatitude - $originLatitude);
        $deltaLongitudeRad = deg2rad($targetLongitude - $originLongitude);

        $haversine = sin($deltaLatitudeRad / 2) ** 2
            + cos($originLatitudeRad) * cos($targetLatitudeRad) * sin($deltaLongitudeRad / 2) ** 2;
        $safeHaversine = min(1.0, max(0.0, $haversine));

        return $earthRadiusMeters * 2 * atan2(sqrt($safeHaversine), sqrt(1 - $safeHaversine));
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
        $order->loadMissing(['serviceType', 'items', 'feeLines', 'shoppingReceipt', 'courierOrder']);
        $newProjectedTotal = round($oldTotalPrice + ((float) $order->delivery_fee - $oldDeliveryFee), 2);
        $eventMetadata = [
            'old_delivery_fee' => round($oldDeliveryFee, 2),
            'new_delivery_fee' => round((float) $order->delivery_fee, 2),
            'old_total_price' => round($oldTotalPrice, 2),
            'new_total_price' => $newProjectedTotal,
            'delivery_fee_source' => $order->delivery_fee_source ?: 'system',
            'careful_carry_required' => $this->carefulCarryRequired($order),
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
            'SUBTOTAL' => round((float) $order->subtotal, 2),
            'DELIVERY_FEE' => round($oldDeliveryFee, 2),
            'SERVICE_FEE' => round((float) $order->service_fee, 2),
            'TOTAL_PRICE' => round($oldTotalPrice, 2),
        ], [
            'SUBTOTAL' => round((float) $order->subtotal, 2),
            'DELIVERY_FEE' => round((float) $order->delivery_fee, 2),
            'SERVICE_FEE' => round((float) $order->service_fee, 2),
            'TOTAL_PRICE' => $newProjectedTotal,
        ]);

        if (($order->serviceType->code ?? null) === 'SHOPPING') {
            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'feeLines',  'shoppingReceipt']),
                $actorId,
                $triggerType,
                true,
                $note
            );
        }

        $nextTotalPrice = round((float) $order->subtotal + (float) $order->delivery_fee + (float) $order->service_fee, 2);
        $order->update([
            'total_price' => $nextTotalPrice,
        ]);

        $this->syncPendingPaymentAmount($order->refresh());

        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status_id' => $order->status_id,
            'event_type' => 'PRICE_UPDATE',
            'changed_by_user_id' => $actorId,
            'note' => $note,
            'price_snapshot' => [
                'delivery_fee' => round((float) $order->delivery_fee, 2),
                'delivery_fee_source' => $order->delivery_fee_source ?: 'system',
                'total_price' => $nextTotalPrice,
                'reason' => $reason,
            ],
        ]);

        $this->broadcastContentUpdatedAfterCommit((int) $order->id, $triggerType, [
            'delivery_fee' => round((float) $order->delivery_fee, 2),
            'delivery_fee_source' => $order->delivery_fee_source ?: 'system',
            'delivery_fee_change_note' => $eventMetadata['reason'] ?? null,
            'careful_carry_required' => $this->carefulCarryRequired($order),
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

    private function orderServiceCode(Order $order): string
    {
        $serviceType = $order->serviceType;

        return $serviceType !== null
            ? ServiceTypeCode::normalize($serviceType->code)
            : ServiceTypeCode::Unknown->value;
    }

    private function orderStatusCode(Order $order): string
    {
        $status = $order->statusRef;

        return $status !== null
            ? OrderStatusCode::normalize($status->code)
            : '';
    }

    private function supportsCarefulCarry(string $serviceCode): bool
    {
        return ServiceTypeCode::normalize($serviceCode) === ServiceTypeCode::Courier->value;
    }

    private function carefulCarryRequired(Order $order): bool
    {
        $courierOrder = $order->courierOrder;

        return $this->orderServiceCode($order) === ServiceTypeCode::Courier->value
            && $courierOrder instanceof CourierOrder
            && (bool) $courierOrder->careful_carry_required;
    }

    private function setCourierCarefulCarryRequired(Order $order, bool $required): void
    {
        if ($this->orderServiceCode($order) !== ServiceTypeCode::Courier->value) {
            return;
        }

        $order->loadMissing('courierOrder');
        $courierOrder = $order->courierOrder;

        $order->courierOrder()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'package_description' => $courierOrder instanceof CourierOrder
                    ? $courierOrder->package_description
                    : 'Paket kurir',
                'careful_carry_required' => $required,
            ],
        );
        $order->unsetRelation('courierOrder');
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

    private function storeOrderPhoto(UploadedFile $photo, int $orderId, string $folder): string
    {
        $path = $photo->store('orders/'.$orderId.'/'.$folder, 'public');
        if (! is_string($path) || $path === '') {
            throw new ApiException('Upload foto gagal disimpan.', 500);
        }

        return Storage::disk('public')->url($path);
    }

    private function normalizeProofType(string $type): string
    {
        return $this->proofPolicyService->normalizeProofType($type);
    }

    private function evidenceTypeForProof(string $type): string
    {
        return $this->proofPolicyService->evidenceTypeForProof($type);
    }

    private function orderHasProof(Order $order, string $type): bool
    {
        $evidenceTypes = $this->proofPolicyService->evidenceTypesForProof($type);

        if ($evidenceTypes === []) {
            return false;
        }

        if ($order->relationLoaded('evidences')) {
            return $order->evidences->contains(
                fn (OrderEvidence $evidence): bool => in_array(strtoupper((string) $evidence->evidence_type), $evidenceTypes, true)
            );
        }

        return $order->evidences()
            ->whereIn('evidence_type', $evidenceTypes)
            ->exists();
    }

    private function currentPaymentMethod(Order $order): string
    {
        return $this->orderPaymentService->normalizePaymentMethod((string) ($order->payment_method ?? OrderPaymentService::METHOD_COD));
    }

    private function orderHasPaidPayment(Order $order): bool
    {
        if ($order->relationLoaded('payment')) {
            return strtoupper((string) $order->payment?->payment_status) === 'PAID';
        }

        if ($order->relationLoaded('payments')) {
            return $order->payments->contains(
                fn (OrderPayment $payment): bool => strtoupper((string) $payment->payment_status) === 'PAID'
            );
        }

        return $order->payment()
            ->where('payment_status', 'PAID')
            ->exists();
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

            if ($this->orderHasPaidPayment($order)) {
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

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'event_type' => 'PAYMENT_UPDATE',
                'changed_by_user_id' => $actor->id,
                'note' => 'Pembayaran COD berhasil dicatat.',
                'price_snapshot' => [
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
                'feeLines',

                'shoppingReceipt',
                'payments',
            ]);
        });
    }
}
