<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
use App\Models\ShoppingOrder;
use App\Models\User;
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
        private readonly ShoppingPricingService $shoppingPricingService,
        private readonly ShoppingRouteService $shoppingRouteService,
        private readonly DriverOrderPayloadFactory $driverOrderPayloadFactory,
        private readonly DriverOrderRealtimeService $driverOrderRealtimeService,
        private readonly OrderStatusPushNotificationService $orderStatusPushNotificationService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderTransferEvidenceService $transferEvidenceService
    ) {}

    /**
     * @var array<int, string>
     */
    private array $editableShoppingStatuses = ['PENDING', 'DRIVER_ASSIGNED', 'ARRIVED_MERCHANT'];

    /**
     * @var array<int, string>
     */
    private array $failedAttemptRecordableStatuses = ['PENDING', 'DRIVER_ASSIGNED', 'ARRIVED_MERCHANT', 'PICKED_UP', 'ON_THE_WAY'];

    /**
     * @var array<int, string>
     */
    private const RUNNING_DRIVER_ORDER_STATUS_CODES = [
        'DRIVER_ASSIGNED',
        'ARRIVED_MERCHANT',
        'ARRIVED_PICKUP',
        'PICKED_UP',
        'ON_THE_WAY',
        'ARRIVED_DROPOFF',
        'DELIVERED',
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateCustomerOrders(User $user, array $filters): LengthAwarePaginator
    {
        $perPage = isset($filters['per_page']) ? min((int) $filters['per_page'], 50) : 10;

        $query = Order::query()
            ->where('user_id', $user->id)
            ->with(['restaurant', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'serviceType', 'courierOrder', 'shoppingOrder'])
            ->latest('id');

        if (! empty($filters['status'])) {
            $query->where('status_id', $this->resolveStatusId((string) $filters['status']));
        }

        return $query->paginate($perPage);
    }

    public function customerOrderDetail(User $user, int $orderId): Order
    {
        $order = Order::query()
            ->with(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'shoppingOrder'])
            ->find($orderId);

        if (! $order || $order->user_id !== $user->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        return $this->ensureDisplayRoutePolyline($order)
            ->fresh(['restaurant', 'driver.user', 'items', 'orderLocations.restaurant', 'payments', 'evidences', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder', 'shoppingOrder']);
    }

    public function cancelByCustomer(User $user, int $orderId, string $reason): Order
    {
        $shouldBroadcastDriverOrderRemoved = false;

        $order = DB::transaction(function () use ($user, $orderId, $reason, &$shouldBroadcastDriverOrderRemoved): Order {
            $order = Order::query()
                ->with(['statusRef', 'shoppingOrder', 'serviceType'])
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
                $shoppingOrder = ShoppingOrder::query()
                    ->where('order_id', $order->id)
                    ->lockForUpdate()
                    ->first();

                if (! $shoppingOrder) {
                    $shoppingOrder = ShoppingOrder::query()->create([
                        'order_id' => $order->id,
                        'failed_attempt_count' => 0,
                        'item_surcharge' => 0,
                        'overweight_surcharge' => 0,
                        'cancellation_penalty' => 0,
                        'has_overweight_item' => false,
                        'recalculation_version' => 0,
                    ]);
                }

                $cancellationPenalty = $this->shoppingPricingService->calculateCancellationPenalty($order, $shoppingOrder);
                $shoppingOrder->update([
                    'cancellation_penalty' => round($cancellationPenalty, 2),
                ]);

                if ($cancellationPenalty > 0) {
                    $cancelledStatusCode = 'CANCELLED_WITH_FEE';
                }
            }

            $cancelledStatusId = $this->resolveStatusId($cancelledStatusCode);

            $order->update([
                'status_id' => $cancelledStatusId,
                'cancellation_reason' => $reason,
                'cancelled_by' => 'customer',
            ]);

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
                    $order->refresh()->load(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
                    $user->id,
                    $cancellationPenalty > 0 ? 'CUSTOMER_CANCEL_WITH_FEE' : 'CUSTOMER_CANCEL',
                    false
                );
            }

            return $order->refresh()->load(['restaurant', 'items', 'statusRef', 'statusHistories.statusRef', 'shoppingOrder']);
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
        $method = $this->orderPaymentService->normalizePaymentMethod((string) ($payload['payment_method'] ?? ''));

        $order = DB::transaction(function () use ($user, $orderId, $method): Order {
            $order = Order::query()
                ->with(['statusRef', 'payments'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order || (int) $order->user_id !== (int) $user->id) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if ($order->statusRef?->is_terminal === true) {
                throw new ApiException('Metode pembayaran tidak bisa diubah setelah order selesai atau dibatalkan.', 409);
            }

            if ($this->orderHasPaidPayment($order)) {
                throw new ApiException('Metode pembayaran tidak bisa diubah setelah pembayaran lunas.', 409);
            }

            $payment = $this->orderPaymentService->ensurePendingPayment($order, $method);

            OrderLog::query()->create([
                'order_id' => $order->id,
                'log_type' => 'PAYMENT_UPDATE',
                'trigger_type' => 'CUSTOMER_PAYMENT_METHOD_CHANGED',
                'changed_by_user_id' => $user->id,
                'note' => 'Customer mengubah metode pembayaran menjadi '.$method.'.',
                'metadata' => [
                    'payment_method' => $method,
                    'amount' => round((float) $payment->amount, 2),
                ],
            ]);

            return $order->refresh();
        });

        $this->broadcastContentUpdatedAfterCommit((int) $order->id, 'PAYMENT_METHOD_UPDATED', [
            'total_price' => round((float) $order->total_price, 2),
            'payment_method' => $method,
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
            'shoppingOrder',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function uploadTransferEvidenceByCustomer(User $user, int $orderId, array $payload): Order
    {
        $order = DB::transaction(function () use ($user, $orderId, $payload): Order {
            $order = Order::query()
                ->with(['payments', 'statusRef'])
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
                throw new ApiException('Foto bukti transfer wajib diupload.', 422);
            }

            $this->orderPaymentService->ensurePendingPayment($order, OrderPaymentService::METHOD_TRANSFER);
            $this->transferEvidenceService->storeAndRecord(
                $order,
                $photo,
                null,
                $payload['note'] ?? 'Bukti transfer dari customer.'
            );

            OrderLog::query()->create([
                'order_id' => $order->id,
                'log_type' => 'PAYMENT_UPDATE',
                'trigger_type' => 'CUSTOMER_TRANSFER_EVIDENCE_UPLOADED',
                'changed_by_user_id' => $user->id,
                'note' => 'Customer upload bukti transfer.',
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
            'shoppingOrder',
        ]);
    }

    public function recordFailedAttempt(
        User $actor,
        int $orderId,
        string $failureType,
        string $reason,
        ?int $pickupLocationId = null,
    ): Order
    {
        $normalizedFailureType = strtoupper(trim($failureType));
        $allowedFailureTypes = ['DRIVER_ASSIGNMENT', 'PICKUP', 'DELIVERY'];

        if (! in_array($normalizedFailureType, $allowedFailureTypes, true)) {
            throw new ApiException('Tipe kegagalan tidak valid.', 422);
        }

        if (! in_array($actor->role, ['driver', 'admin'], true)) {
            throw new ApiException('Hanya driver atau admin yang dapat mencatat failed attempt.', 403);
        }

        $order = DB::transaction(function () use ($actor, $orderId, $normalizedFailureType, $reason, $pickupLocationId): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'shoppingOrder', 'orderLocations', 'items'])
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

            if ($pickup !== null && strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')) === 'FAILED') {
                throw new ApiException('Merchant ini sudah ditandai tutup/gagal pickup.', 409);
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

            $shoppingOrder = ShoppingOrder::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $shoppingOrder) {
                $shoppingOrder = ShoppingOrder::query()->create([
                    'order_id' => $order->id,
                    'failed_attempt_count' => 0,
                    'item_surcharge' => 0,
                    'overweight_surcharge' => 0,
                    'cancellation_penalty' => 0,
                    'has_overweight_item' => false,
                    'recalculation_version' => 0,
                ]);
            }

            $nextFailedAttemptCount = min(255, (int) $shoppingOrder->failed_attempt_count + 1);
            $shoppingOrder->update([
                'failed_attempt_count' => $nextFailedAttemptCount,
            ]);

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
                    ->each(function ($item) use ($reason): void {
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

            $this->shoppingRouteService->applyRouteToOrder($order->refresh()->load(['orderLocations.restaurant', 'items', 'shoppingOrder']));

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'event_type' => 'FAILED_ATTEMPT_INCREMENT',
                'changed_by_user_id' => $actor->id,
                'note' => $reason,
                'price_snapshot' => [
                    'failure_type' => $normalizedFailureType,
                    'failed_attempt_count' => $nextFailedAttemptCount,
                    'pickup_location_id' => $pickup?->id ?? $pickupLocationId,
                    'fulfillment_status' => $pickup !== null ? 'FAILED' : null,
                ],
            ]);

            OrderLog::query()->create([
                'order_id' => $order->id,
                'log_type' => 'SYSTEM_EVENT',
                'trigger_type' => 'FAILED_ATTEMPT_'.$normalizedFailureType,
                'recalculation_version' => (int) $shoppingOrder->recalculation_version,
                'changed_by_user_id' => $actor->id,
                'note' => $reason,
                'metadata' => [
                    'failure_type' => $normalizedFailureType,
                    'failed_attempt_count' => $nextFailedAttemptCount,
                    'actor_role' => $actor->role,
                    'pickup_location_id' => $pickup?->id ?? $pickupLocationId,
                ],
            ]);

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
                $actor->id,
                'SHOPPING_FAILED_ATTEMPT',
                false,
                $reason
            );
        });

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
                    'note' => $payment->note,
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

        return [
            'incoming_orders' => $incoming
                ->map(fn (Order $order): array => $this->serializeDriverOrderForDisplay($order))
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
            ->with(['user:id,name', 'statusRef:id,code,display_name'])
            ->where('driver_id', $driver->id)
            ->whereIn('status_id', $historyStatusIds)
            ->latest('id')
            ->limit(100)
            ->get();

        $history = $orders
            ->map(function (Order $order): array {
                $statusCode = strtoupper((string) ($order->statusRef->code ?? ''));
                $date = $order->delivered_at ?? $order->updated_at ?? $order->created_at;

                return [
                    'id' => $order->order_number ?: (string) $order->id,
                    'customer_name' => $order->user->name ?? '-',
                    'date' => $date?->toIso8601String(),
                    'fee' => (int) round((float) $order->delivery_fee),
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

        return $this->serializeDriverOrderForDisplay($order, includeTimeline: true);
    }

    private function serializeDriverOrderForDisplay(Order $order, bool $includeTimeline = false): array
    {
        $order = $this->ensureDisplayRoutePolyline($order)
            ->fresh($this->driverOrderPayloadFactory->relations());

        return $this->driverOrderPayloadFactory->serialize($order, includeTimeline: $includeTimeline);
    }

    private function ensureDisplayRoutePolyline(Order $order): Order
    {
        $order->loadMissing(['serviceType', 'orderLocations.restaurant', 'items', 'shoppingOrder']);

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

        return $order->refresh()->load(['serviceType', 'orderLocations.restaurant', 'items', 'shoppingOrder']);
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
                $this->markDriverBusy($lockedDriver);

                return $order;
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
            ]);

            $statusHistory = OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $assignedStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $actor->id,
                'note' => 'Order diterima oleh driver.',
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
        ?float $latitude = null,
        ?float $longitude = null,
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
            $latitude,
            $longitude,
            &$statusChangeEventPayload,
        ): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'rideOrder', 'shoppingOrder', 'evidences'])
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
                throw new ApiException('Pembayaran belum dicatat.', 409);
            }

            if (($rule['requires_unpaid'] ?? false) && $this->orderHasPaidPayment($order)) {
                throw new ApiException('Aksi ini hanya tersedia sebelum pembayaran dicatat.', 409);
            }

            if (
                strtoupper((string) $serviceCode) === 'SHOPPING' &&
                $normalizedActionCode === 'CONFIRM_PICKED_UP' &&
                ! $this->shoppingPricingService->hasDriverShoppingTotal($order)
            ) {
                throw new ApiException('Total belanja di struk belum diisi.', 409);
            }

            if (
                $this->supportsDriverProofType($serviceCode, 'pickup') &&
                in_array($normalizedActionCode, ['BOARD_PASSENGER', 'CONFIRM_PICKED_UP'], true) &&
                ! $this->orderHasProof($order, 'pickup')
            ) {
                throw new ApiException('Bukti foto pickup belum diupload.', 409);
            }

            if (
                strtoupper((string) $serviceCode) === 'SHOPPING' &&
                $normalizedActionCode === 'CONFIRM_PICKED_UP' &&
                ! $this->orderHasProof($order, 'receipt')
            ) {
                throw new ApiException('Foto struk belanja belum diupload.', 409);
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
                $shoppingOrder = $order->shoppingOrder;
                if (! $shoppingOrder instanceof ShoppingOrder) {
                    throw new ApiException('Data shopping order tidak ditemukan.', 500);
                }

                if (! $this->shoppingPricingService->isCancellationPenaltyEligible($order, $shoppingOrder)) {
                    throw new ApiException('Order belum memenuhi batas failed attempt untuk dibatalkan dengan fee.', 409);
                }

                if ($this->orderHasPaidPayment($order)) {
                    throw new ApiException('Order sudah memiliki pembayaran lunas dan tidak bisa dibatalkan dengan fee.', 409);
                }

                $shoppingCancellationPenalty = $this->shoppingPricingService->calculateCancellationPenalty($order, $shoppingOrder);
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

            if ($latitude !== null && $longitude !== null) {
                $snapshot['driver_location'] = [
                    'latitude' => round($latitude, 8),
                    'longitude' => round($longitude, 8),
                ];
            }

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
                if ($order->shoppingOrder instanceof ShoppingOrder) {
                    $snapshot = is_array($order->shoppingOrder->pricing_snapshot)
                        ? $order->shoppingOrder->pricing_snapshot
                        : [];
                    $snapshot['penalty_base_delivery_fee'] = round((float) $order->delivery_fee, 2);
                    $order->shoppingOrder->update([
                        'cancellation_penalty' => round($shoppingCancellationPenalty, 2),
                        'pricing_snapshot' => $snapshot,
                    ]);
                }

                $order = $this->shoppingPricingService->recalculate(
                    $order->refresh()->load(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
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
            }

            return $order;
        });

        $this->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->sendOrderStatusPushNotification($order, $statusChangeEventPayload);

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
        return $this->resolveStatusIdsLenient(self::RUNNING_DRIVER_ORDER_STATUS_CODES);
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
        return in_array(strtoupper(trim($statusCode)), self::RUNNING_DRIVER_ORDER_STATUS_CODES, true);
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

        if ($targetStatusCode === 'PICKED_UP' && $rideOrder->picked_up_at === null) {
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
    public function updateShoppingItemsByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'shoppingOrder', 'items'])
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

            $statusCode = strtoupper((string) ($order->statusRef->code ?? ''));
            if (! in_array($statusCode, ['DRIVER_ASSIGNED', 'ARRIVED_MERCHANT'], true)) {
                throw new ApiException('Harga nota hanya bisa diperbarui sebelum belanja selesai.', 409);
            }

            if (! $order->shoppingOrder) {
                $order->shoppingOrder()->create([
                    'failed_attempt_count' => 0,
                    'item_surcharge' => 0,
                    'overweight_surcharge' => 0,
                    'cancellation_penalty' => 0,
                    'has_overweight_item' => false,
                    'recalculation_version' => 0,
                ]);
            }

            $receiptNote = trim((string) ($payload['receipt_note'] ?? ''));
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

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

                $metadata = is_array($item->metadata) ? $item->metadata : [];
                if ($item->item_source === 'MANUAL') {
                    $metadata['price_status'] = (! $isAvailable)
                        ? 'UNAVAILABLE'
                        : ($unitPrice > 0 ? 'DRIVER_CONFIRMED' : 'PENDING_DRIVER_INPUT');
                }
                if ($receiptNote !== '') {
                    $metadata['receipt_note'] = $receiptNote;
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

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
                $actor->id,
                'DRIVER_RECEIPT_UPDATE',
                true,
                $receiptNote !== '' ? $receiptNote : 'Driver memperbarui harga nota belanja.'
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
            $order = $this->lockedAssignedDriverOrder($orderId, $driver->id, ['statusRef', 'serviceType', 'shoppingOrder', 'items']);

            $manualAmount = array_key_exists('amount', $payload) && $payload['amount'] !== null
                ? round(max(0.0, (float) $payload['amount']), 2)
                : null;
            $reason = trim((string) ($payload['reason'] ?? ''));
            $carefulCarryRequired = array_key_exists('careful_carry_required', $payload)
                ? (bool) $payload['careful_carry_required']
                : (bool) ($order->careful_carry_required ?? false);
            $serviceCode = (string) ($order->serviceType->code ?? '');

            if (! $this->supportsCarefulCarry($serviceCode)) {
                if (array_key_exists('careful_carry_required', $payload) && (bool) $payload['careful_carry_required']) {
                    throw new ApiException('Perlu 2 orang hanya tersedia untuk order kurir dan titip belanja.', 422);
                }

                $carefulCarryRequired = false;
            }

            if ($manualAmount !== null && $manualAmount <= 0) {
                throw new ApiException('Nominal ongkir manual harus lebih dari 0.', 422);
            }

            if ($manualAmount !== null && $reason === '') {
                throw new ApiException('Alasan edit ongkir wajib diisi.', 422);
            }

            $nextDeliveryFee = $manualAmount !== null
                ? $this->deliveryFeeWithCarefulCarry($manualAmount, $carefulCarryRequired)
                : $this->systemDeliveryFeeWithCarefulCarry($order, $carefulCarryRequired);
            $oldDeliveryFee = (float) $order->delivery_fee;
            $oldTotalPrice = (float) $order->total_price;

            $order->update([
                'delivery_fee' => $nextDeliveryFee,
                'delivery_fee_source' => $manualAmount !== null ? 'manual' : 'system',
                'manual_delivery_fee' => $manualAmount,
                'manual_delivery_fee_reason' => $manualAmount !== null ? $reason : null,
                'careful_carry_required' => $carefulCarryRequired,
            ]);

            $order = $this->refreshTotalsAfterDeliveryFeeChange(
                $order->refresh(),
                $actor->id,
                'DRIVER_DELIVERY_FEE_OVERRIDE',
                $reason !== '' ? $reason : 'Driver memperbarui ongkir.',
                $oldDeliveryFee,
                $oldTotalPrice,
            );

            return $order;
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
                'verification_mode' => 'AUTO_24H',
                'verification_status' => 'PENDING',
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
        $itemUpdatePayload = [
            'items' => $payload['items'] ?? [],
            'receipt_note' => $payload['receipt_note'] ?? null,
        ];

        $this->updateShoppingItemsByDriver($actor, $orderId, $itemUpdatePayload);
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'shoppingOrder', 'items']
            );

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Checkout nitip hanya tersedia untuk order SHOPPING.', 409);
            }

            $oldDeliveryFee = (float) $order->delivery_fee;
            $oldTotalPrice = (float) $order->total_price;

            if (array_key_exists('delivery_fee_override', $payload) && $payload['delivery_fee_override'] !== null) {
                $manualDeliveryFee = round(max(0.0, (float) $payload['delivery_fee_override']), 2);
                if ($manualDeliveryFee <= 0) {
                    throw new ApiException('Ongkir checkout harus lebih dari 0.', 422);
                }

                $order->update([
                    'delivery_fee' => $this->deliveryFeeWithCarefulCarry(
                        $manualDeliveryFee,
                        (bool) ($order->careful_carry_required ?? false)
                    ),
                    'delivery_fee_source' => 'manual',
                    'manual_delivery_fee' => $manualDeliveryFee,
                    'manual_delivery_fee_reason' => 'Ongkir diedit saat checkout nitip.',
                ]);

                $order = $this->refreshTotalsAfterDeliveryFeeChange(
                    $order->refresh(),
                    $actor->id,
                    'DRIVER_SHOPPING_CHECKOUT_DELIVERY_FEE',
                    'Driver mengubah ongkir saat checkout nitip.',
                    $oldDeliveryFee,
                    $oldTotalPrice,
                );
            }

            if (array_key_exists('shopping_total_amount', $payload) && $payload['shopping_total_amount'] !== null) {
                $shoppingTotal = round(max(0.0, (float) $payload['shopping_total_amount']), 2);
                if ($shoppingTotal <= 0) {
                    throw new ApiException('Total belanja harus lebih dari 0.', 422);
                }

                $totalPrice = round($shoppingTotal + (float) $order->delivery_fee + (float) $order->service_fee, 2);
                $order->update([
                    'subtotal' => $shoppingTotal,
                    'total_price' => $totalPrice,
                ]);

                if ($order->shoppingOrder instanceof ShoppingOrder) {
                    $snapshot = is_array($order->shoppingOrder->pricing_snapshot)
                        ? $order->shoppingOrder->pricing_snapshot
                        : [];
                    $snapshot['subtotal'] = $shoppingTotal;
                    $snapshot['total_price'] = $totalPrice;
                    $snapshot['driver_shopping_total_amount'] = $shoppingTotal;
                    $order->shoppingOrder->update([
                        'pricing_snapshot' => $snapshot,
                    ]);
                }

                $this->markShoppingItemsConfirmedByReceiptTotal($order);

                $this->syncPendingPaymentAmount($order->refresh());
            }

            $receiptPhoto = $payload['receipt_photo'] ?? null;
            if ($receiptPhoto instanceof UploadedFile) {
                $fileUrl = $this->storeOrderPhoto($receiptPhoto, $order->id, 'receipts');
                OrderEvidence::query()->create([
                    'order_id' => $order->id,
                    'driver_id' => $driver->id,
                    'evidence_type' => 'SHOPPING_RECEIPT',
                    'file_url' => $fileUrl,
                    'verification_mode' => 'AUTO_24H',
                    'verification_status' => 'PENDING',
                    'uploaded_at' => now(),
                    'notes' => $payload['receipt_note'] ?? null,
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
                throw new ApiException('Nominal transfer harus lebih dari 0.', 422);
            }

            $paidAt = isset($payload['paid_at'])
                ? Carbon::parse((string) $payload['paid_at'])
                : now();

            OrderPayment::query()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'payment_method' => 'TRANSFER',
                    'payment_status' => 'PAID',
                    'amount' => $amount,
                    'recorded_by_user_id' => $actor->id,
                    'driver_id' => $driver->id,
                    'paid_at' => $paidAt,
                    'note' => $payload['note'] ?? null,
                    'metadata' => [
                        'recorded_by_role' => $actor->role,
                        'source' => 'DRIVER_TRANSFER_CONFIRMATION',
                        'expected_amount' => $expectedAmount,
                    ],
                ],
            );

            OrderLog::query()->create([
                'order_id' => $order->id,
                'log_type' => 'PAYMENT_UPDATE',
                'trigger_type' => 'TRANSFER_PAYMENT_RECORDED_BY_DRIVER',
                'changed_by_user_id' => $actor->id,
                'note' => $payload['note'] ?? 'Pencatatan pembayaran transfer.',
                'metadata' => [
                    'paid_amount' => $amount,
                    'expected_amount' => $expectedAmount,
                ],
            ]);

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'event_type' => 'PAYMENT_UPDATE',
                'changed_by_user_id' => $actor->id,
                'note' => 'Pembayaran transfer berhasil dicatat.',
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
    public function addShoppingItem(User $user, int $orderId, array $payload, ?int $replacementForPickupLocationId = null): Order
    {
        return $this->addShoppingItems($user, $orderId, [$payload], $replacementForPickupLocationId);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function addShoppingItems(User $user, int $orderId, array $items, ?int $replacementForPickupLocationId = null): Order
    {
        return DB::transaction(function () use ($user, $orderId, $items, $replacementForPickupLocationId): Order {
            $order = $this->getEditableShoppingOrder($user, $orderId);

            if ($items === []) {
                throw new ApiException('Minimal satu item belanja wajib ditambahkan.', 422);
            }

            $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));
            $routeChanged = false;
            $replacementPickup = $replacementForPickupLocationId !== null
                ? $this->resolveReplacementPickup($order, $replacementForPickupLocationId)
                : null;
            $pickupLocationsByMerchantId = [];

            foreach ($items as $payload) {
                if (! is_array($payload)) {
                    continue;
                }

                $merchant = $this->resolveManualItemMerchant($order, $payload);
                $merchantId = (int) $merchant->id;
                $pickupLocation = $pickupLocationsByMerchantId[$merchantId] ?? null;

                if (! $pickupLocation) {
                    $pickupLocation = $this->resolvePickupLocationForMerchant($order, $merchant);
                }

                if (! $pickupLocation) {
                    if (! in_array($statusCode, ['PENDING', 'DRIVER_ASSIGNED'], true) && ! $replacementPickup instanceof OrderLocation) {
                        throw new ApiException('Merchant baru hanya bisa ditambahkan sebelum driver mulai belanja.', 409);
                    }

                    $pickupLocation = $this->createPickupLocationForMerchant($order, $merchant);
                    $routeChanged = true;
                }

                $pickupLocationsByMerchantId[$merchantId] = $pickupLocation;

                $order->items()->create([
                    'menu_id' => null,
                    'pickup_location_id' => $pickupLocation->id,
                    'item_source' => 'MANUAL',
                    'menu_name' => (string) $payload['menu_name'],
                    'quantity' => (int) $payload['quantity'],
                    'unit_price' => 0,
                    'subtotal' => 0,
                    'notes' => isset($payload['notes']) ? (string) $payload['notes'] : null,
                    'metadata' => ['price_status' => 'PENDING_DRIVER_INPUT'],
                    'is_available' => true,
                    'is_heavy' => false,
                ]);
            }

            if ($routeChanged) {
                $this->shoppingRouteService->applyRouteToOrder($order->refresh()->load(['orderLocations.restaurant']));
            }

            if ($replacementPickup instanceof OrderLocation) {
                $replacementPickup->update([
                    'fulfillment_status' => 'REPLACED',
                    'resolved_at' => now(),
                ]);
                $this->syncPrimaryRestaurantFromFirstPickup($order);

                OrderStatusHistory::query()->create([
                    'order_id' => $order->id,
                    'status_id' => $order->status_id,
                    'event_type' => 'SHOPPING_STOP_RESOLVED',
                    'changed_by_user_id' => $user->id,
                    'note' => 'Customer menambahkan merchant pengganti untuk '.$replacementPickup->contact_name.'.',
                    'price_snapshot' => [
                        'pickup_location_id' => (int) $replacementPickup->id,
                        'resolution' => 'REPLACED',
                    ],
                ]);

                $this->shoppingRouteService->applyRouteToOrder($order->refresh()->load(['orderLocations.restaurant', 'items', 'shoppingOrder']));
                $routeChanged = true;
            }

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
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
            /** @var \App\Models\OrderItem|null $item */
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
                $order->refresh()->load(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
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
                $order->refresh()->load(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
                $user->id,
                $routeChanged ? 'SHOPPING_ROUTE_UPDATED' : 'CUSTOMER_REMOVE_ITEM',
                true,
                $routeChanged
                    ? 'Customer menghapus item terakhir pada merchant sehingga rute dihitung ulang.'
                    : 'Customer menghapus item belanja.'
            );
        });
    }

    public function skipFailedShoppingStop(User $user, int $orderId, int $pickupLocationId): Order
    {
        return DB::transaction(function () use ($user, $orderId, $pickupLocationId): Order {
            $order = $this->getEditableShoppingOrder($user, $orderId);

            $pickup = $order->orderLocations
                ->first(fn (OrderLocation $location): bool => (int) $location->id === $pickupLocationId);

            if (! $pickup || strtoupper((string) $pickup->location_role) !== 'PICKUP') {
                throw new ApiException('Merchant/pickup order tidak valid.', 422);
            }

            if (strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')) !== 'FAILED') {
                throw new ApiException('Merchant ini belum ditandai tutup/gagal pickup.', 409);
            }

            $availableItemsOutsideStop = $order->items
                ->contains(fn ($item): bool => (int) ($item->pickup_location_id ?? 0) !== $pickupLocationId && (bool) $item->is_available);
            if (! $availableItemsOutsideStop) {
                throw new ApiException('Tidak bisa lanjut tanpa item belanja lain. Tambahkan merchant pengganti atau batalkan order.', 409);
            }

            $pickup->update([
                'fulfillment_status' => 'SKIPPED',
                'resolved_at' => now(),
            ]);

            $this->syncPrimaryRestaurantFromFirstPickup($order);
            $this->shoppingRouteService->applyRouteToOrder($order->refresh()->load(['orderLocations.restaurant', 'items', 'shoppingOrder']));

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'event_type' => 'SHOPPING_STOP_RESOLVED',
                'changed_by_user_id' => $user->id,
                'note' => 'Customer memilih lanjut tanpa merchant '.$pickup->contact_name.'.',
                'price_snapshot' => [
                    'pickup_location_id' => $pickupLocationId,
                    'resolution' => 'SKIPPED',
                ],
            ]);

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
                $user->id,
                'CUSTOMER_SKIP_FAILED_MERCHANT',
                false,
                'Customer memilih lanjut tanpa merchant yang tutup.'
            );
        });
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
            ->with(['items', 'shoppingOrder', 'statusRef', 'serviceType', 'restaurant', 'orderLocations.restaurant'])
            ->find($orderId);

        if (! $order || $order->user_id !== $user->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        if (($order->serviceType->code ?? null) !== 'SHOPPING') {
            throw new ApiException('Perubahan item hanya diizinkan untuk order SHOPPING.', 409);
        }

        $statusCode = $order->statusRef?->code;
        if (! in_array($statusCode, $this->editableShoppingStatuses, true)) {
            throw new ApiException('Item tidak bisa diubah pada status order saat ini.', 409);
        }

        if (! $order->shoppingOrder) {
            $order->shoppingOrder()->create([
                'failed_attempt_count' => 0,
                'item_surcharge' => 0,
                'overweight_surcharge' => 0,
                'cancellation_penalty' => 0,
                'has_overweight_item' => false,
                'recalculation_version' => 0,
            ]);

            $order->load('shoppingOrder');
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveManualItemMerchant(Order $order, array $payload): Restaurant
    {
        if (isset($payload['merchant_id']) && is_numeric($payload['merchant_id'])) {
            $merchant = Restaurant::query()
                ->where('status', 'active')
                ->whereKey((int) $payload['merchant_id'])
                ->first();

            if (! $merchant) {
                throw new ApiException('Merchant tidak ditemukan atau tidak aktif.', 404);
            }

            return $merchant;
        }

        if ($order->restaurant instanceof Restaurant && $order->restaurant->status === 'active') {
            return $order->restaurant;
        }

        $pickup = $order->orderLocations
            ->first(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP' && $location->restaurant instanceof Restaurant);

        if ($pickup?->restaurant instanceof Restaurant && $pickup->restaurant->status === 'active') {
            return $pickup->restaurant;
        }

        throw new ApiException('Merchant wajib dipilih untuk item manual.', 422);
    }

    private function resolveReplacementPickup(Order $order, int $pickupLocationId): OrderLocation
    {
        $pickup = $order->orderLocations
            ->first(fn (OrderLocation $location): bool => (int) $location->id === $pickupLocationId);

        if (! $pickup || strtoupper((string) $pickup->location_role) !== 'PICKUP') {
            throw new ApiException('Merchant pengganti tidak valid.', 422);
        }

        if (strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')) !== 'FAILED') {
            throw new ApiException('Merchant ini belum ditandai tutup/gagal pickup.', 409);
        }

        return $pickup;
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

    private function createPickupLocationForMerchant(Order $order, Restaurant $merchant): OrderLocation
    {
        $maxSequence = (int) $order->orderLocations()->max('sequence_no');
        if ($order->restaurant_id === null) {
            $order->update(['restaurant_id' => $merchant->id]);
        }

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

    private function syncPrimaryRestaurantFromFirstPickup(Order $order): void
    {
        $firstPickup = $order->orderLocations()
            ->where('location_role', 'PICKUP')
            ->whereNotNull('restaurant_id')
            ->where(function ($query): void {
                $query
                    ->whereNull('fulfillment_status')
                    ->orWhereNotIn('fulfillment_status', ['FAILED', 'SKIPPED', 'REPLACED']);
            })
            ->orderBy('sequence_no')
            ->orderBy('id')
            ->first();

        $order->update([
            'restaurant_id' => $firstPickup?->restaurant_id,
        ]);
    }

    /**
     * @param  array<int, string>  $relations
     */
    private function lockedAssignedDriverOrder(int $orderId, int $driverId, array $relations = []): Order
    {
        $order = Order::query()
            ->with($relations)
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
    ): Order {
        $order->loadMissing(['serviceType', 'shoppingOrder', 'items']);

        OrderLog::query()->create([
            'order_id' => $order->id,
            'log_type' => 'PRICE_RECALCULATION',
            'trigger_type' => $triggerType,
            'old_delivery_fee' => round($oldDeliveryFee, 2),
            'new_delivery_fee' => round((float) $order->delivery_fee, 2),
            'old_total_price' => round($oldTotalPrice, 2),
            'new_total_price' => round((float) $order->total_price, 2),
            'delta_total_price' => round(((float) $order->delivery_fee) - $oldDeliveryFee, 2),
            'changed_by_user_id' => $actorId,
            'note' => $note,
            'metadata' => [
                'delivery_fee_source' => $order->delivery_fee_source ?: 'system',
                'manual_delivery_fee' => $order->manual_delivery_fee,
                'manual_delivery_fee_reason' => $order->manual_delivery_fee_reason,
                'careful_carry_required' => (bool) ($order->careful_carry_required ?? false),
            ],
        ]);

        if (($order->serviceType->code ?? null) === 'SHOPPING' && $order->shoppingOrder instanceof ShoppingOrder) {
            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
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
            ],
        ]);

        $this->broadcastContentUpdatedAfterCommit((int) $order->id, $triggerType, [
            'delivery_fee' => round((float) $order->delivery_fee, 2),
            'delivery_fee_source' => $order->delivery_fee_source ?: 'system',
            'manual_delivery_fee' => $order->manual_delivery_fee !== null
                ? round((float) $order->manual_delivery_fee, 2)
                : null,
            'manual_delivery_fee_reason' => $order->manual_delivery_fee_reason,
            'careful_carry_required' => (bool) ($order->careful_carry_required ?? false),
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

    private function syncPendingPaymentAmount(Order $order): void
    {
        OrderPayment::query()
            ->where('order_id', $order->id)
            ->where('payment_status', 'PENDING')
            ->update([
                'amount' => round((float) $order->total_price, 2),
            ]);
    }

    private function systemDeliveryFeeWithCarefulCarry(Order $order, bool $carefulCarryRequired): float
    {
        $route = $order->route_snapshot;
        $systemFee = is_array($route) && is_numeric(data_get($route, 'delivery_pricing.total_fee'))
            ? (float) data_get($route, 'delivery_pricing.total_fee')
            : (float) $order->delivery_fee;

        return $this->deliveryFeeWithCarefulCarry($systemFee, $carefulCarryRequired);
    }

    private function deliveryFeeWithCarefulCarry(float $baseDeliveryFee, bool $carefulCarryRequired): float
    {
        $baseDeliveryFee = max(0.0, $baseDeliveryFee);

        if (! $carefulCarryRequired) {
            return round($baseDeliveryFee, 2);
        }

        return round($baseDeliveryFee + ($baseDeliveryFee * 0.5), 2);
    }

    private function supportsCarefulCarry(string $serviceCode): bool
    {
        return in_array(strtoupper($serviceCode), ['COURIER', 'SHOPPING'], true);
    }

    private function supportsDriverProofType(string $serviceCode, string $type): bool
    {
        return match (strtoupper($serviceCode)) {
            'COURIER' => in_array($type, ['pickup', 'delivery'], true),
            'SHOPPING' => in_array($type, ['receipt', 'store_closed'], true),
            default => false,
        };
    }

    private function unsupportedProofMessage(string $type, string $serviceCode): string
    {
        $service = strtoupper($serviceCode);

        if ($service === 'SHOPPING' && in_array($type, ['pickup', 'delivery'], true)) {
            return 'Bukti pengambilan dan diterima hanya tersedia untuk order kurir.';
        }

        if ($service === 'COURIER' && in_array($type, ['receipt', 'store_closed'], true)) {
            return 'Bukti struk dan toko tutup hanya tersedia untuk order titip belanja.';
        }

        return 'Bukti foto order tidak tersedia untuk layanan ini.';
    }

    private function isLifecycleProofType(string $type): bool
    {
        return in_array($type, ['pickup', 'delivery', 'receipt', 'store_closed'], true);
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
        $normalized = strtolower(str_replace('-', '_', trim($type)));
        $allowed = ['pickup', 'delivery', 'receipt', 'store_closed', 'payment_transfer'];

        if (! in_array($normalized, $allowed, true)) {
            throw new ApiException('Tipe bukti tidak valid.', 422);
        }

        return $normalized;
    }

    private function evidenceTypeForProof(string $type): string
    {
        return match ($type) {
            'pickup' => 'PICKUP_PHOTO',
            'delivery' => 'DELIVERY_PHOTO',
            'receipt' => 'SHOPPING_RECEIPT',
            'store_closed' => 'STORE_CLOSED_PHOTO',
            'payment_transfer' => 'PAYMENT_TRANSFER_PHOTO',
            default => throw new ApiException('Tipe bukti tidak valid.', 422),
        };
    }

    private function orderHasProof(Order $order, string $type): bool
    {
        $evidenceTypes = match ($type) {
            'pickup' => ['PICKUP_PHOTO'],
            'delivery' => ['DELIVERY_PHOTO', 'COURIER_DELIVERY_PHOTO', 'COURIER_RECEIVER_PHOTO'],
            'receipt' => ['SHOPPING_RECEIPT'],
            'store_closed' => ['STORE_CLOSED_PHOTO'],
            'payment_transfer' => ['PAYMENT_TRANSFER_PHOTO'],
            default => [],
        };

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

    private function markShoppingItemsConfirmedByReceiptTotal(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if (strtoupper((string) $item->item_source) !== 'MANUAL' || ! (bool) $item->is_available) {
                continue;
            }

            $metadata = is_array($item->metadata) ? $item->metadata : [];
            $metadata['price_status'] = 'RECEIPT_TOTAL_CONFIRMED';
            $item->update(['metadata' => $metadata]);
        }
    }

    private function orderHasPaidPayment(Order $order): bool
    {
        if ($order->relationLoaded('payments')) {
            return $order->payments->contains(
                fn (OrderPayment $payment): bool => strtoupper((string) $payment->payment_status) === 'PAID'
            );
        }

        return $order->payments()
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
                throw new ApiException('Order ini menggunakan pembayaran transfer. Gunakan pencatatan transfer.', 409);
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

            OrderPayment::query()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'payment_method' => 'COD',
                    'payment_status' => 'PAID',
                    'amount' => $amount,
                    'recorded_by_user_id' => $actor->id,
                    'driver_id' => $orderDriverId > 0 ? $orderDriverId : null,
                    'paid_at' => $paidAt,
                    'note' => $payload['note'] ?? null,
                    'metadata' => [
                        'recorded_by_role' => $actor->role,
                        'source' => $isCourierPickupCollection
                            ? 'COURIER_PICKUP_COLLECTION'
                            : ($enforceAssignedDriver ? 'DRIVER_COLLECTION' : 'ADMIN_MANUAL_RECORD'),
                        'extra' => $payload['metadata'] ?? null,
                    ],
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
                'shoppingOrder',
                'payments',
            ]);
        });
    }

}
