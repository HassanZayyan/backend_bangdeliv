<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderItem;
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
    /**
     * @var array<int, string>
     */
    private array $editableShoppingStatuses = ['PENDING', 'DRIVER_ASSIGNED'];

    /**
     * @var array<int, string>
     */
    private array $failedAttemptRecordableStatuses = ['PENDING', 'DRIVER_ASSIGNED', 'PICKED_UP', 'ON_THE_WAY'];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateCustomerOrders(User $user, array $filters): LengthAwarePaginator
    {
        $perPage = isset($filters['per_page']) ? min((int) $filters['per_page'], 50) : 10;

        $query = Order::query()
            ->where('user_id', $user->id)
            ->with(['restaurant', 'items', 'statusRef', 'serviceType'])
            ->latest('id');

        if (!empty($filters['status'])) {
            $query->where('status_id', $this->resolveStatusId((string) $filters['status']));
        }

        return $query->paginate($perPage);
    }

    public function customerOrderDetail(User $user, int $orderId): Order
    {
        $order = Order::query()
            ->with(['restaurant', 'driver.user', 'address', 'items', 'orderLocations', 'statusRef', 'statusHistories.statusRef', 'serviceType'])
            ->find($orderId);

        if (!$order || $order->user_id !== $user->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        return $order;
    }

    public function cancelByCustomer(User $user, int $orderId, string $reason): Order
    {
        return DB::transaction(function () use ($user, $orderId, $reason): Order {
            $order = Order::query()
                ->with(['statusRef', 'shoppingOrder', 'serviceType'])
                ->lockForUpdate()
                ->find($orderId);

            if (!$order || $order->user_id !== $user->id) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            $activeStatusCode = $order->statusRef?->code;
            if (!in_array($activeStatusCode, ['PENDING', 'DRIVER_ASSIGNED'], true)) {
                throw new ApiException('Order tidak bisa dibatalkan pada status saat ini.', 409);
            }

            $isShopping = ($order->serviceType?->code ?? null) === 'SHOPPING';
            $cancellationPenalty = 0.0;
            $cancelledStatusCode = 'CANCELLED';

            if ($isShopping) {
                $shoppingOrder = ShoppingOrder::query()
                    ->where('order_id', $order->id)
                    ->lockForUpdate()
                    ->first();

                if (!$shoppingOrder) {
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

            if ($isShopping) {
                $order = $this->recalculateShoppingOrder(
                    $order->fresh(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
                    $user->id,
                    $cancellationPenalty > 0 ? 'CUSTOMER_CANCEL_WITH_FEE' : 'CUSTOMER_CANCEL',
                    false
                );
            }

            return $order->fresh(['restaurant', 'items', 'statusRef', 'statusHistories.statusRef', 'shoppingOrder']);
        });
    }

    public function recordFailedAttempt(User $actor, int $orderId, string $failureType, string $reason): Order
    {
        $normalizedFailureType = strtoupper(trim($failureType));
        $allowedFailureTypes = ['DRIVER_ASSIGNMENT', 'PICKUP', 'DELIVERY'];

        if (!in_array($normalizedFailureType, $allowedFailureTypes, true)) {
            throw new ApiException('Tipe kegagalan tidak valid.', 422);
        }

        if (!in_array($actor->role, ['driver', 'admin'], true)) {
            throw new ApiException('Hanya driver atau admin yang dapat mencatat failed attempt.', 403);
        }

        return DB::transaction(function () use ($actor, $orderId, $normalizedFailureType, $reason): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'shoppingOrder'])
                ->lockForUpdate()
                ->find($orderId);

            if (!$order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if (($order->serviceType?->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Failed attempt hanya berlaku untuk order SHOPPING.', 409);
            }

            $activeStatusCode = $order->statusRef?->code;
            if (!in_array($activeStatusCode, $this->failedAttemptRecordableStatuses, true)) {
                throw new ApiException('Failed attempt tidak bisa dicatat pada status order saat ini.', 409);
            }

            if ($actor->role === 'driver') {
                if ($normalizedFailureType === 'DRIVER_ASSIGNMENT') {
                    throw new ApiException('Driver tidak dapat mencatat kegagalan assignment.', 403);
                }

                $driver = Driver::query()->where('user_id', $actor->id)->first();
                if (!$driver) {
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

            if (!$shoppingOrder) {
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

            return $order->fresh(['restaurant', 'address', 'items', 'statusRef', 'statusHistories.statusRef', 'shoppingOrder']);
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

        if (!empty($filters['driver_id'])) {
            $query->where('driver_id', (int) $filters['driver_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('paid_at', '>=', (string) $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('paid_at', '<=', (string) $filters['date_to']);
        }

        $payments = $query->latest('paid_at')->get();

        $byDriver = $payments
            ->groupBy('driver_id')
            ->map(function ($rows, $driverId): array {
                $first = $rows->first();

                return [
                    'driver_id' => $driverId !== null ? (int) $driverId : null,
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
                    'order_number' => $payment->order?->order_number,
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
            if (!$lockedDriver) {
                throw new ApiException('Profil driver tidak ditemukan.', 404);
            }

            $hasRunningOrder = $this->hasRunningDriverOrder($lockedDriver->id);

            if (!$isOnline && $hasRunningOrder) {
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

        $incoming = Order::query()
            ->with($this->driverOrderRelations())
            ->where('status_id', $pendingStatusId)
            ->whereNull('driver_id')
            ->latest('id')
            ->limit(30)
            ->get();

        $running = Order::query()
            ->with($this->driverOrderRelations())
            ->where('driver_id', $driver->id)
            ->whereIn('status_id', $runningStatusIds)
            ->latest('id')
            ->limit(30)
            ->get();

        return [
            'incoming_orders' => $incoming
                ->map(fn (Order $order): array => $this->serializeDriverOrder($order))
                ->values()
                ->all(),
            'running_orders' => $running
                ->map(fn (Order $order): array => $this->serializeDriverOrder($order))
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
                $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));
                $date = $order->delivered_at ?? $order->updated_at ?? $order->created_at;

                return [
                    'id' => $order->order_number ?: (string) $order->id,
                    'customer_name' => $order->user?->name ?? '-',
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
            ->with($this->driverOrderRelations())
            ->find($orderId);

        if (!$order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        $statusCode = (string) ($order->statusRef?->code ?? '');
        $isIncomingCandidate = $statusCode === 'PENDING' && $order->driver_id === null;
        $isAssignedToCurrentDriver = (int) ($order->driver_id ?? 0) === (int) $driver->id;

        if (!$isIncomingCandidate && !$isAssignedToCurrentDriver) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        return $this->serializeDriverOrder($order, includeTimeline: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function acceptByDriver(User $actor, int $orderId): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($driver, $actor, $orderId): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType'])
                ->lockForUpdate()
                ->find($orderId);

            if (!$order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            $statusCode = (string) ($order->statusRef?->code ?? '');
            $isAssignedToCurrentDriver = (int) ($order->driver_id ?? 0) === (int) $driver->id;

            if ($statusCode === 'DRIVER_ASSIGNED' && $isAssignedToCurrentDriver) {
                return $order;
            }

            if ($statusCode !== 'PENDING') {
                throw new ApiException('Order tidak dapat diterima pada status saat ini.', 409);
            }

            if ($order->driver_id !== null && !$isAssignedToCurrentDriver) {
                throw new ApiException('Order sudah diambil driver lain.', 409);
            }

            $assignedStatusId = $this->resolveStatusId('DRIVER_ASSIGNED');
            $order->update([
                'driver_id' => $driver->id,
                'status_id' => $assignedStatusId,
            ]);

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $assignedStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $actor->id,
                'note' => 'Order diterima oleh driver.',
            ]);

            return $order;
        });

        return $this->serializeDriverOrder(
            $order->fresh($this->driverOrderRelations()),
            includeTimeline: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rejectByDriver(User $actor, int $orderId, ?string $reason): array
    {
        $driver = $this->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($driver, $actor, $orderId, $reason): Order {
            $order = Order::query()
                ->with(['statusRef'])
                ->lockForUpdate()
                ->find($orderId);

            if (!$order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            $statusCode = (string) ($order->statusRef?->code ?? '');

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

                OrderStatusHistory::query()->create([
                    'order_id' => $order->id,
                    'status_id' => $pendingStatusId,
                    'event_type' => 'STATUS_CHANGE',
                    'changed_by_user_id' => $actor->id,
                    'note' => $reason ?: 'Driver melepaskan order setelah assignment.',
                ]);

                return $order;
            }

            throw new ApiException('Order tidak dapat ditolak pada status saat ini.', 409);
        });

        return $this->serializeDriverOrder(
            $order->fresh($this->driverOrderRelations()),
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

        $order = DB::transaction(function () use (
            $actor,
            $driver,
            $orderId,
            $actionCode,
            $targetStatusCode,
            $note,
            $latitude,
            $longitude,
        ): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'rideOrder'])
                ->lockForUpdate()
                ->find($orderId);

            if (!$order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if ((int) ($order->driver_id ?? 0) !== (int) $driver->id) {
                throw new ApiException('Order ini tidak ditugaskan kepada driver saat ini.', 403);
            }

            $serviceCode = (string) ($order->serviceType?->code ?? '');
            $rules = $this->driverActionRules($serviceCode);

            $normalizedActionCode = strtoupper(str_replace('-', '_', trim($actionCode)));
            $rule = $rules[$normalizedActionCode] ?? null;
            if ($rule === null) {
                throw new ApiException('Aksi driver tidak valid.', 422);
            }

            $currentStatusCode = (string) ($order->statusRef?->code ?? '');
            if (!in_array($currentStatusCode, $rule['from'], true)) {
                throw new ApiException('Transisi status tidak valid untuk order ini.', 409);
            }

            $resolvedTargetStatusCode = strtoupper(
                str_replace('-', '_', trim((string) ($targetStatusCode ?? $rule['to'])))
            );

            if ($resolvedTargetStatusCode !== $rule['to']) {
                throw new ApiException('target_status_code tidak sesuai dengan action_code.', 422);
            }

            if (($rule['requires_paid'] ?? false) && (string) $order->payment_status !== 'paid') {
                throw new ApiException('Order belum bisa diselesaikan sebelum pembayaran COD tercatat.', 409);
            }

            $targetStatusId = $this->resolveStatusId($resolvedTargetStatusCode);
            $updates = [
                'status_id' => $targetStatusId,
            ];

            if (in_array($resolvedTargetStatusCode, ['DELIVERED', 'COMPLETED'], true)) {
                $updates['delivered_at'] = now();
            }

            $order->update($updates);
            $this->syncDriverServiceTimestamp($order, $resolvedTargetStatusCode);

            $eventNote = trim((string) $note);
            if ($eventNote === '') {
                $eventNote = 'Driver action '.$normalizedActionCode;
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

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $targetStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $actor->id,
                'note' => $eventNote,
                'price_snapshot' => $snapshot,
            ]);

            return $order;
        });

        return $this->serializeDriverOrder(
            $order->fresh($this->driverOrderRelations()),
            includeTimeline: true,
        );
    }

    private function resolveActiveDriverProfile(User $actor): Driver
    {
        if ($actor->role !== 'driver') {
            throw new ApiException('Akses hanya untuk driver.', 403);
        }

        $driver = Driver::query()->where('user_id', $actor->id)->first();
        if (!$driver) {
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
        return $this->resolveStatusIdsLenient([
            'DRIVER_ASSIGNED',
            'ARRIVED_MERCHANT',
            'ARRIVED_PICKUP',
            'PICKED_UP',
            'ON_THE_WAY',
            'ARRIVED_DROPOFF',
            'DELIVERED',
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function serializeDriverAvailability(Driver $driver, bool $hasRunningOrder): array
    {
        $status = strtolower(trim((string) ($driver->status ?? 'offline')));
        if (!in_array($status, ['available', 'offline', 'busy'], true)) {
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
            if (!$id) {
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
            if (!$id) {
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function driverActionRules(string $serviceCode): array
    {
        $upperServiceCode = strtoupper($serviceCode);

        $pickupArrivalStatus = $upperServiceCode === 'SHOPPING'
            ? 'ARRIVED_MERCHANT'
            : 'ARRIVED_PICKUP';
        $pickupArrivalLabel = $upperServiceCode === 'SHOPPING'
            ? 'Tiba di Merchant'
            : 'Tiba di Titik Jemput';

        return [
            'ARRIVE_PICKUP' => [
                'label' => $pickupArrivalLabel,
                'from' => ['DRIVER_ASSIGNED'],
                'to' => $pickupArrivalStatus,
            ],
            'CONFIRM_PICKED_UP' => [
                'label' => 'Konfirmasi Pickup',
                'from' => [$pickupArrivalStatus],
                'to' => 'PICKED_UP',
            ],
            'START_DELIVERY' => [
                'label' => 'Mulai Antar',
                'from' => ['PICKED_UP'],
                'to' => 'ON_THE_WAY',
            ],
            'ARRIVE_DROPOFF' => [
                'label' => 'Tiba di Tujuan',
                'from' => ['ON_THE_WAY'],
                'to' => 'ARRIVED_DROPOFF',
            ],
            'CONFIRM_DELIVERED' => [
                'label' => 'Konfirmasi Terkirim',
                'from' => ['ARRIVED_DROPOFF'],
                'to' => 'DELIVERED',
            ],
            'COMPLETE_ORDER' => [
                'label' => 'Selesaikan Order',
                'from' => ['DELIVERED'],
                'to' => 'COMPLETED',
                'requires_paid' => true,
            ],
        ];
    }

    /**
     * @return array<int, string|array<string, mixed>>
     */
    private function driverOrderRelations(): array
    {
        return [
            'user:id,name,phone',
            'serviceType:id,code,display_name',
            'statusRef:id,code,display_name',
            'address:id,full_address,detail,latitude,longitude',
            'restaurant:id,name,address,latitude,longitude',
            'rideOrder:id,order_id,picked_up_at,arrived_at',
            'courierOrder:id,order_id,package_description,requires_photo_evidence',
            'items:id,order_id,quantity',
            'orderLocations:id,order_id,location_role,full_address,latitude,longitude,sequence_no',
            'statusHistories' => function ($query): void {
                $query
                    ->with('statusRef:id,code,display_name')
                    ->orderBy('created_at');
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDriverOrder(Order $order, bool $includeTimeline = false): array
    {
        $serviceCode = strtoupper((string) ($order->serviceType?->code ?? ''));
        $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));
        $paymentStatus = strtolower((string) ($order->payment_status ?? 'unpaid'));

        $pickup = $this->resolvePickupPoint($order, $serviceCode);
        $dropoff = $this->resolveDropoffPoint($order, $serviceCode);
        $availableActions = $this->resolveAvailableDriverActions(
            $serviceCode,
            $statusCode,
            $paymentStatus,
        );

        $acceptedAt = $order->statusHistories
            ->first(fn (OrderStatusHistory $history): bool =>
                strtoupper((string) ($history->statusRef?->code ?? '')) === 'DRIVER_ASSIGNED'
            );

        $itemCount = (int) $order->items->sum('quantity');
        if ($itemCount < 1) {
            $itemCount = 1;
        }

        $payload = [
            'id' => (string) $order->id,
            'order_number' => $order->order_number,
            'service_type_code' => $serviceCode,
            'service_type_name' => $order->serviceType?->display_name,
            'customer_name' => $order->user?->name ?? '-',
            'customer_phone' => $order->user?->phone,
            'pickup_address' => $pickup['address'],
            'pickup_latitude' => $pickup['latitude'],
            'pickup_longitude' => $pickup['longitude'],
            'dropoff_address' => $dropoff['address'],
            'dropoff_latitude' => $dropoff['latitude'],
            'dropoff_longitude' => $dropoff['longitude'],
            'fee' => (int) round((float) $order->delivery_fee),
            'item_count' => $itemCount,
            'eta_minutes' => $this->estimateEtaMinutes($order),
            'accepted_at' => $acceptedAt?->created_at?->format('H:i'),
            'status_code' => $statusCode,
            'status_display_name' => $order->statusRef?->display_name,
            'payment_status' => $paymentStatus,
            'payment_method' => $order->payment_method,
            'notes' => $order->notes,
            'available_actions' => $availableActions,
        ];

        if ($includeTimeline) {
            $payload['status_timeline'] = $this->serializeStatusTimeline($order);
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeStatusTimeline(Order $order): array
    {
        return $order->statusHistories
            ->sortBy('created_at')
            ->map(function (OrderStatusHistory $history): array {
                return [
                    'status_code' => strtoupper((string) ($history->statusRef?->code ?? '')),
                    'status_display_name' => $history->statusRef?->display_name,
                    'event_type' => strtoupper((string) $history->event_type),
                    'note' => $history->note,
                    'created_at' => $history->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, float|string|null>
     */
    private function resolvePickupPoint(Order $order, string $serviceCode): array
    {
        if ($serviceCode === 'SHOPPING') {
            return [
                'address' => $order->restaurant?->address ?? '-',
                'latitude' => $this->toFloatOrNull($order->restaurant?->latitude),
                'longitude' => $this->toFloatOrNull($order->restaurant?->longitude),
            ];
        }

        if ($serviceCode === 'COURIER') {
            $pickup = $order->orderLocations
                ->first(fn ($location): bool => strtoupper((string) $location->location_role) === 'PICKUP');

            return [
                'address' => $pickup?->full_address ?? ($order->address?->full_address ?? '-'),
                'latitude' => $this->toFloatOrNull($pickup?->latitude ?? $order->address?->latitude),
                'longitude' => $this->toFloatOrNull($pickup?->longitude ?? $order->address?->longitude),
            ];
        }

        return [
            'address' => $order->address?->full_address ?? '-',
            'latitude' => $this->toFloatOrNull($order->address?->latitude),
            'longitude' => $this->toFloatOrNull($order->address?->longitude),
        ];
    }

    /**
     * @return array<string, float|string|null>
     */
    private function resolveDropoffPoint(Order $order, string $serviceCode): array
    {
        if ($serviceCode === 'COURIER') {
            $dropoff = $order->orderLocations
                ->first(fn ($location): bool => strtoupper((string) $location->location_role) === 'DROPOFF');

            return [
                'address' => $dropoff?->full_address ?? $order->delivery_address,
                'latitude' => $this->toFloatOrNull($dropoff?->latitude ?? $order->delivery_latitude),
                'longitude' => $this->toFloatOrNull($dropoff?->longitude ?? $order->delivery_longitude),
            ];
        }

        return [
            'address' => $order->delivery_address,
            'latitude' => $this->toFloatOrNull($order->delivery_latitude),
            'longitude' => $this->toFloatOrNull($order->delivery_longitude),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveAvailableDriverActions(
        string $serviceCode,
        string $statusCode,
        string $paymentStatus,
    ): array {
        $actions = [];
        $rules = $this->driverActionRules($serviceCode);

        foreach ($rules as $actionCode => $rule) {
            if (!in_array($statusCode, $rule['from'], true)) {
                continue;
            }

            $requiresPaid = (bool) ($rule['requires_paid'] ?? false);
            $blocked = $requiresPaid && $paymentStatus !== 'paid';

            $actions[] = [
                'action_code' => $actionCode,
                'label' => $rule['label'],
                'target_status_code' => $rule['to'],
                'blocked' => $blocked,
                'blocked_reason' => $blocked
                    ? 'Pembayaran COD belum dicatat.'
                    : null,
            ];
        }

        if ($statusCode === 'DELIVERED' && $paymentStatus !== 'paid') {
            $actions[] = [
                'action_code' => 'COLLECT_COD',
                'label' => 'Catat Pembayaran COD',
                'target_status_code' => null,
                'blocked' => false,
                'blocked_reason' => null,
            ];
        }

        return $actions;
    }

    private function driverHistoryStatusLabel(string $statusCode, ?string $fallbackDisplayName): string
    {
        return match ($statusCode) {
            'COMPLETED' => 'Selesai',
            'CANCELLED', 'CANCELLED_WITH_FEE' => 'Dibatalkan',
            default => $fallbackDisplayName ?: $statusCode,
        };
    }

    private function estimateEtaMinutes(Order $order): int
    {
        if ($order->estimated_delivery === null) {
            return 0;
        }

        $minutes = now()->diffInMinutes($order->estimated_delivery, false);
        return $minutes > 0 ? $minutes : 0;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function syncDriverServiceTimestamp(Order $order, string $targetStatusCode): void
    {
        $serviceCode = strtoupper((string) ($order->serviceType?->code ?? ''));
        if ($serviceCode !== 'RIDE') {
            return;
        }

        $rideOrder = $order->rideOrder;
        if (!$rideOrder) {
            $rideOrder = $order->rideOrder()->create([
                'notes' => $order->notes,
            ]);
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

                if (!$menu || !$menu->is_available) {
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
                $order->fresh(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
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
            $item = $order->items()->where('id', $itemId)->first();

            if (!$item) {
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
                $order->fresh(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
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

            if (!$item) {
                throw new ApiException('Item order tidak ditemukan.', 404);
            }

            if ($order->items()->count() <= 1) {
                throw new ApiException('Order belanja harus memiliki minimal satu item.', 409);
            }

            $item->delete();

            return $this->recalculateShoppingOrder(
                $order->fresh(['items', 'shoppingOrder', 'statusRef', 'serviceType']),
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

        if (!$statusId) {
            throw new ApiException('Status order tidak valid.', 422);
        }

        return (int) $statusId;
    }

    private function getEditableShoppingOrder(User $user, int $orderId): Order
    {
        $order = Order::query()
            ->with(['items', 'shoppingOrder', 'statusRef', 'serviceType'])
            ->find($orderId);

        if (!$order || $order->user_id !== $user->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        if (($order->serviceType?->code ?? null) !== 'SHOPPING') {
            throw new ApiException('Perubahan item hanya diizinkan untuk order SHOPPING.', 409);
        }

        $statusCode = $order->statusRef?->code;
        if (!in_array($statusCode, $this->editableShoppingStatuses, true)) {
            throw new ApiException('Item tidak bisa diubah pada status order saat ini.', 409);
        }

        if (!$order->shoppingOrder) {
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
        if (!$shoppingOrder instanceof ShoppingOrder) {
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
            'total_amount' => $newTotalPrice,
            'total_price' => $newTotalPrice,
        ]);

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

        return $order->fresh(['restaurant', 'address', 'items', 'statusRef', 'statusHistories.statusRef', 'shoppingOrder']);
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

        return round($blockCount * $surchargePerBlock, 2);
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

        return round($deliveryFee * ($percent / 100), 2);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordCodPayment(User $actor, int $orderId, array $payload, bool $enforceAssignedDriver): Order
    {
        return DB::transaction(function () use ($actor, $orderId, $payload, $enforceAssignedDriver): Order {
            $order = Order::query()
                ->with(['statusRef', 'driver.user'])
                ->lockForUpdate()
                ->find($orderId);

            if (!$order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if (($order->payment_method ?? 'COD') !== 'COD') {
                throw new ApiException('Order ini bukan metode pembayaran COD.', 409);
            }

            if ((string) $order->payment_status === 'paid') {
                throw new ApiException('Pembayaran order ini sudah tercatat.', 409);
            }

            if (($order->statusRef?->code ?? null) !== 'DELIVERED') {
                throw new ApiException('Pembayaran COD hanya bisa dicatat setelah order berstatus DELIVERED.', 409);
            }

            $orderDriverId = (int) ($order->driver_id ?? 0);
            if ($enforceAssignedDriver) {
                $driver = Driver::query()->where('user_id', $actor->id)->first();
                if (!$driver) {
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

            OrderPayment::query()->create([
                'order_id' => $order->id,
                'payment_method' => 'COD',
                'payment_status' => 'PAID',
                'amount' => $amount,
                'recorded_by_user_id' => $actor->id,
                'driver_id' => $orderDriverId > 0 ? $orderDriverId : null,
                'paid_at' => $paidAt,
                'note' => $payload['note'] ?? null,
                'metadata' => [
                    'recorded_by_role' => $actor->role,
                    'source' => $enforceAssignedDriver ? 'DRIVER_COLLECTION' : 'ADMIN_MANUAL_RECORD',
                    'extra' => $payload['metadata'] ?? null,
                ],
            ]);

            $order->update([
                'payment_status' => 'paid',
                'paid_amount' => $amount,
                'paid_by_user_id' => $actor->id,
                'paid_at' => $paidAt,
            ]);

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

            return $order->fresh([
                'restaurant',
                'address',
                'items',
                'statusRef',
                'statusHistories.statusRef',
                'shoppingOrder',
                'payments',
            ]);
        });
    }
}
