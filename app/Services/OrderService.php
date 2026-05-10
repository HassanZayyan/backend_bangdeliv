<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\ServiceFeeRule;
use App\Models\ShoppingOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function __construct(
        private readonly OrderRealtimeBroadcaster $realtimeBroadcaster,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly DriverOrderPayloadFactory $driverOrderPayloadFactory,
        private readonly DriverOrderRealtimeService $driverOrderRealtimeService
    ) {}

    /**
     * @var array<int, string>
     */
    private array $editableShoppingStatuses = ['PENDING', 'DRIVER_ASSIGNED'];

    /**
     * @var array<int, string>
     */
    private array $failedAttemptRecordableStatuses = ['PENDING', 'DRIVER_ASSIGNED', 'PICKED_UP', 'ON_THE_WAY'];

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
            ->with(['restaurant', 'items', 'payments', 'statusRef', 'serviceType', 'courierOrder'])
            ->latest('id');

        if (! empty($filters['status'])) {
            $query->where('status_id', $this->resolveStatusId((string) $filters['status']));
        }

        return $query->paginate($perPage);
    }

    public function customerOrderDetail(User $user, int $orderId): Order
    {
        $order = Order::query()
            ->with(['restaurant', 'driver.user', 'items', 'orderLocations', 'payments', 'statusRef', 'statusHistories.statusRef', 'serviceType', 'courierOrder'])
            ->find($orderId);

        if (! $order || $order->user_id !== $user->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        return $order;
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

                $cancellationPenalty = $this->calculateCancellationPenalty($order, $shoppingOrder);
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
                $order = $this->recalculateShoppingOrder(
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

    public function recordFailedAttempt(User $actor, int $orderId, string $failureType, string $reason): Order
    {
        $normalizedFailureType = strtoupper(trim($failureType));
        $allowedFailureTypes = ['DRIVER_ASSIGNMENT', 'PICKUP', 'DELIVERY'];

        if (! in_array($normalizedFailureType, $allowedFailureTypes, true)) {
            throw new ApiException('Tipe kegagalan tidak valid.', 422);
        }

        if (! in_array($actor->role, ['driver', 'admin'], true)) {
            throw new ApiException('Hanya driver atau admin yang dapat mencatat failed attempt.', 403);
        }

        return DB::transaction(function () use ($actor, $orderId, $normalizedFailureType, $reason): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'shoppingOrder'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Failed attempt hanya berlaku untuk order SHOPPING.', 409);
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

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'event_type' => 'FAILED_ATTEMPT_INCREMENT',
                'changed_by_user_id' => $actor->id,
                'note' => $reason,
                'price_snapshot' => [
                    'failure_type' => $normalizedFailureType,
                    'failed_attempt_count' => $nextFailedAttemptCount,
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
                ],
            ]);

            return $order->refresh()->load(['restaurant', 'orderLocations', 'items', 'statusRef', 'statusHistories.statusRef', 'shoppingOrder']);
        });
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
                ->map(fn (Order $order): array => $this->driverOrderPayloadFactory->serialize($order))
                ->values()
                ->all(),
            'running_orders' => $running
                ->map(fn (Order $order): array => $this->driverOrderPayloadFactory->serialize($order))
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

        if (! $isIncomingCandidate && ! $isAssignedToCurrentDriver) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        return $this->driverOrderPayloadFactory->serialize($order, includeTimeline: true);
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
                OrderStatusHistory::query()->create([
                    'order_id' => $order->id,
                    'status_id' => $order->status_id,
                    'event_type' => 'DRIVER_REJECT',
                    'changed_by_user_id' => $actor->id,
                    'note' => $reason ?: 'Order ditolak driver sebelum assignment.',
                ]);

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
                ->with(['statusRef', 'serviceType', 'rideOrder'])
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

            if (($rule['requires_paid'] ?? false) && ! $this->orderHasPaidCodPayment($order)) {
                throw new ApiException('Pembayaran COD belum dicatat.', 409);
            }

            if (($rule['requires_unpaid'] ?? false) && $this->orderHasPaidCodPayment($order)) {
                throw new ApiException('Aksi ini hanya tersedia sebelum pembayaran COD dicatat.', 409);
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

            if ($resolvedTargetStatusCode === 'CANCELLED') {
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

            return $order;
        });

        $this->broadcastOrderStatusChanged($statusChangeEventPayload);

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
     */
    public function addShoppingItem(User $user, int $orderId, array $payload): Order
    {
        return DB::transaction(function () use ($user, $orderId, $payload): Order {
            $order = $this->getEditableShoppingOrder($user, $orderId);

            $itemSource = (string) $payload['item_source'];
            $quantity = (int) $payload['quantity'];
            $isHeavy = (bool) ($payload['is_heavy'] ?? false);
            $notes = isset($payload['notes']) ? (string) $payload['notes'] : null;
            $metadata = $payload['metadata'] ?? null;

            if ($itemSource === 'MENU_DB') {
                $menu = Menu::query()->where('id', (int) $payload['menu_id'])->first();

                if (! $menu || ! $menu->is_available) {
                    throw new ApiException('Menu tidak ditemukan atau tidak tersedia.', 404);
                }

                $menuName = $menu->name;
                $unitPrice = (float) $menu->price;
                $menuId = $menu->id;
            } else {
                $menuName = (string) $payload['menu_name'];
                $unitPrice = (float) $payload['unit_price'];
                $menuId = null;
            }

            $lineSubtotal = round($unitPrice * $quantity, 2);

            $order->items()->create([
                'menu_id' => $menuId,
                'item_source' => $itemSource,
                'menu_name' => $menuName,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'subtotal' => $lineSubtotal,
                'line_service_fee' => 0,
                'line_total' => $lineSubtotal,
                'notes' => $notes,
                'metadata' => $metadata,
                'is_available' => true,
                'is_heavy' => $isHeavy,
            ]);

            return $this->recalculateShoppingOrder(
                $order->refresh()->load(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
                $user->id,
                'CUSTOMER_ADD_ITEM'
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

                if (array_key_exists('unit_price', $payload) && $payload['unit_price'] !== null) {
                    $unitPrice = (float) $payload['unit_price'];
                }
            }

            $lineSubtotal = round($unitPrice * $quantity, 2);

            $item->update([
                'menu_name' => $menuName,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'subtotal' => $lineSubtotal,
                'line_total' => $lineSubtotal,
                'notes' => array_key_exists('notes', $payload) ? ($payload['notes'] !== null ? (string) $payload['notes'] : null) : $item->notes,
                'is_heavy' => array_key_exists('is_heavy', $payload) ? (bool) $payload['is_heavy'] : (bool) $item->is_heavy,
                'metadata' => array_key_exists('metadata', $payload) ? $payload['metadata'] : $item->metadata,
            ]);

            return $this->recalculateShoppingOrder(
                $order->refresh()->load(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
                $user->id,
                'CUSTOMER_UPDATE_ITEM'
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

            $item->delete();

            return $this->recalculateShoppingOrder(
                $order->refresh()->load(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
                $user->id,
                'CUSTOMER_REMOVE_ITEM'
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
            ->with(['items', 'shoppingOrder', 'statusRef', 'serviceType'])
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

    private function recalculateShoppingOrder(Order $order, int $changedByUserId, string $triggerType, bool $writeHistory = true): Order
    {
        $shoppingOrder = $order->shoppingOrder;
        if (! $shoppingOrder instanceof ShoppingOrder) {
            throw new ApiException('Data shopping order tidak ditemukan.', 500);
        }

        $itemBlockRule = $this->getRuleConfig((int) $order->service_type_id, 'ITEM_BLOCK_SURCHARGE');
        $overweightRule = $this->getRuleConfig((int) $order->service_type_id, 'OVERWEIGHT_FLAT_SURCHARGE');

        $oldSubtotal = (float) $order->subtotal;
        $oldServiceFee = (float) $order->service_fee;
        $oldTotalPrice = (float) $order->total_price;
        $deliveryFee = (float) $order->delivery_fee;

        $newSubtotal = round((float) $order->items->sum('subtotal'), 2);
        $totalItemQuantity = (int) $order->items->sum('quantity');
        $hasOverweightItem = $order->items->contains(fn (OrderItem $item) => (bool) $item->is_heavy);

        $itemSurcharge = $this->calculateItemSurcharge($totalItemQuantity, $itemBlockRule);
        $overweightSurcharge = $hasOverweightItem ? (float) ($overweightRule['surcharge'] ?? 0) : 0.0;
        $cancellationPenalty = (float) $shoppingOrder->cancellation_penalty;

        $newServiceFee = round($itemSurcharge + $overweightSurcharge + $cancellationPenalty, 2);
        $newTotalPrice = round($newSubtotal + $deliveryFee + $newServiceFee, 2);

        $nextVersion = (int) $shoppingOrder->recalculation_version + 1;

        $shoppingOrder->update([
            'item_surcharge' => round($itemSurcharge, 2),
            'overweight_surcharge' => round($overweightSurcharge, 2),
            'has_overweight_item' => $hasOverweightItem,
            'recalculation_version' => $nextVersion,
            'last_recalculated_at' => now(),
            'pricing_snapshot' => [
                'item_count' => $totalItemQuantity,
                'subtotal' => $newSubtotal,
                'delivery_fee' => $deliveryFee,
                'item_surcharge' => round($itemSurcharge, 2),
                'overweight_surcharge' => round($overweightSurcharge, 2),
                'cancellation_penalty' => round($cancellationPenalty, 2),
                'service_fee' => $newServiceFee,
                'total_price' => $newTotalPrice,
            ],
        ]);

        $order->update([
            'subtotal' => $newSubtotal,
            'service_fee' => $newServiceFee,
            'total_price' => $newTotalPrice,
        ]);

        $this->orderPaymentService->syncPendingCodAmount($order->refresh());

        OrderLog::query()->create([
            'order_id' => $order->id,
            'log_type' => 'PRICE_RECALCULATION',
            'trigger_type' => $triggerType,
            'old_subtotal' => round($oldSubtotal, 2),
            'new_subtotal' => $newSubtotal,
            'old_delivery_fee' => round($deliveryFee, 2),
            'new_delivery_fee' => round($deliveryFee, 2),
            'old_service_fee' => round($oldServiceFee, 2),
            'new_service_fee' => $newServiceFee,
            'old_total_price' => round($oldTotalPrice, 2),
            'new_total_price' => $newTotalPrice,
            'delta_total_price' => round($newTotalPrice - $oldTotalPrice, 2),
            'recalculation_version' => $nextVersion,
            'changed_by_user_id' => $changedByUserId,
            'note' => 'Rekalkulasi harga order SHOPPING setelah perubahan item.',
            'metadata' => [
                'item_count' => $totalItemQuantity,
                'has_overweight_item' => $hasOverweightItem,
            ],
        ]);

        if ($writeHistory) {
            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'event_type' => 'ITEM_UPDATE',
                'changed_by_user_id' => $changedByUserId,
                'note' => 'Perubahan item order SHOPPING oleh customer.',
                'price_snapshot' => [
                    'subtotal' => $newSubtotal,
                    'service_fee' => $newServiceFee,
                    'total_price' => $newTotalPrice,
                ],
            ]);
        }

        return $order->refresh()->load(['restaurant', 'orderLocations', 'items', 'statusRef', 'statusHistories.statusRef', 'shoppingOrder']);
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
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
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

        return (int) round($blockCount * $surchargePerBlock, 2);
    }

    private function calculateCancellationPenalty(Order $order, ShoppingOrder $shoppingOrder): float
    {
        $rule = $this->getRuleConfig((int) $order->service_type_id, 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS');

        $threshold = max(1, (int) ($rule['failed_attempt_threshold'] ?? 3));
        $percent = max(0.0, (float) ($rule['penalty_percent_of_delivery_fee'] ?? 50));

        if ((int) $shoppingOrder->failed_attempt_count < $threshold || $percent <= 0) {
            return 0.0;
        }

        $deliveryFee = (float) $order->delivery_fee;

        return (int) round($deliveryFee * ($percent / 100), 2);
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

            if ($this->orderHasPaidCodPayment($order)) {
                throw new ApiException('Pembayaran order ini sudah tercatat.', 409);
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

    private function orderHasPaidCodPayment(Order $order): bool
    {
        if ($order->relationLoaded('payments')) {
            return $order->payments->contains(
                fn (OrderPayment $payment): bool => strtoupper((string) $payment->payment_method) === 'COD'
                    && strtoupper((string) $payment->payment_status) === 'PAID'
            );
        }

        return $order->payments()
            ->where('payment_method', 'COD')
            ->where('payment_status', 'PAID')
            ->exists();
    }
}
