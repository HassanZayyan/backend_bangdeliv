<?php

namespace App\Services\Order;

use App\Enums\OrderStatusCode;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderLog;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Driver\Dispatch\DriverCandidateSelector;
use App\Services\Driver\DriverIncomeFeeCalculator;
use App\Services\Driver\DriverOrderLifecycleService;
use App\Services\Driver\DriverOrderPayloadFactory;
use App\Services\Driver\DriverOrderRealtimeService;
use App\Services\Notification\OrderRealtimeBroadcaster;
use App\Services\Notification\PaymentProofReminderNotificationService;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingPriceNegotiationService;
use App\Services\Shopping\ShoppingRouteService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DriverOrderWorkflowService
{
    public function __construct(
        private readonly OrderRealtimeBroadcaster $realtimeBroadcaster,
        private readonly PaymentProofReminderNotificationService $paymentProofReminderNotificationService,
        private readonly ShoppingPricingService $shoppingPricingService,
        private readonly ShoppingRouteService $shoppingRouteService,
        private readonly DriverOrderPayloadFactory $driverOrderPayloadFactory,
        private readonly DriverOrderLifecycleService $driverOrderLifecycleService,
        private readonly DriverIncomeFeeCalculator $driverIncomeFeeCalculator,
        private readonly DriverOrderRealtimeService $driverOrderRealtimeService,
        private readonly DriverCandidateSelector $driverCandidateSelector,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderEvidenceService $orderEvidenceService,
        private readonly OrderProofPolicyService $proofPolicyService,
        private readonly ShoppingPriceNegotiationService $shoppingPriceNegotiationService,
        private readonly DeliveryFeeNegotiationService $deliveryFeeNegotiationService,
        private readonly OrderStatusResolver $orderStatusResolver,
        private readonly OrderRealtimeNotifier $orderRealtimeNotifier,
        private readonly DriverOrderResolver $driverOrderResolver,
        private readonly ShoppingUnavailableItemPurger $shoppingUnavailableItemPurger
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function driverAvailability(User $actor): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
        $hasRunningOrder = $this->hasRunningDriverOrder($driver->id);

        return $this->serializeDriverAvailability($driver, $hasRunningOrder);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateDriverAvailability(User $actor, bool $isOnline): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

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
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

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
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
        $pendingStatusId = $this->orderStatusResolver->resolveStatusId('PENDING');

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

        $runningQuery = Order::query()
            ->with($this->driverOrderPayloadFactory->relations())
            ->where('driver_id', $driver->id);
        $running = $this->driverOrderLifecycleService
            ->constrainRunningOrders($runningQuery)
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
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
        $orders = Order::query()
            ->with([
                'user:id,name',
                'serviceType:id,code',
                'statusRef:id,code,display_name',
                'statusHistories.statusRef',
                'orderLocations:id,order_id,location_role,failed_attempt_count',
            ])
            ->where('driver_id', $driver->id)
            ->where(function ($query): void {
                $query
                    ->whereHas('statusRef', fn ($statusQuery) => $statusQuery->whereIn('code', ['COMPLETED', 'CANCELLED']))
                    ->orWhere(function ($cancelledWithFeeQuery): void {
                        $cancelledWithFeeQuery
                            ->whereHas('statusRef', fn ($statusQuery) => $statusQuery->where('code', 'CANCELLED_WITH_FEE'))
                            ->whereHas('payments', fn ($paymentQuery) => $paymentQuery->where('payment_status', 'PAID'));
                    });
            })
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
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

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

    public function ensureDisplayRoutePolyline(Order $order): Order
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
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
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

            $assignedStatusId = $this->orderStatusResolver->resolveStatusId('DRIVER_ASSIGNED');
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

            $statusChangeEventPayload = $this->orderRealtimeNotifier->buildOrderStatusBroadcastPayload(
                $order->id,
                'DRIVER_ASSIGNED',
                $previousStatusCode,
                $statusHistory,
            );

            return $order;
        });

        $this->orderRealtimeNotifier->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->orderRealtimeNotifier->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
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
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

        $order = Order::query()
            ->with(['statusRef'])
            ->find($orderId);

        if (! $order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        if ((int) ($order->driver_id ?? 0) !== (int) $driver->id) {
            throw new ApiException('Order ini tidak ditugaskan kepada driver saat ini.', 403);
        }

        $statusCode = $this->orderStatusResolver->orderStatusCode($order);
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
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
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
                $pendingStatusId = $this->orderStatusResolver->resolveStatusId('PENDING');

                $order->update([
                    'driver_id' => null,
                    'status_id' => $pendingStatusId,
                ]);

                $this->driverOrderLifecycleService->syncAvailabilityAfterNonRunningOrder($driver->id);

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

                $statusChangeEventPayload = $this->orderRealtimeNotifier->buildOrderStatusBroadcastPayload(
                    $order->id,
                    'PENDING',
                    'DRIVER_ASSIGNED',
                    $statusHistory,
                );

                return $order;
            }

            throw new ApiException('Order tidak dapat ditolak pada status saat ini.', 409);
        });

        $this->orderRealtimeNotifier->broadcastOrderStatusChanged($statusChangeEventPayload);
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
        ?float $cancellationPenaltyBaseDeliveryFee = null,
    ): array {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
        $statusChangeEventPayload = null;
        $order = DB::transaction(function () use (
            $actor,
            $driver,
            $orderId,
            $actionCode,
            $targetStatusCode,
            $note,
            $cancellationPenaltyBaseDeliveryFee,
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

            $isShoppingCancellationWithFee = strtoupper((string) $serviceCode) === 'SHOPPING'
                && $normalizedActionCode === 'CANCEL_WITH_FEE';
            if ($cancellationPenaltyBaseDeliveryFee !== null && ! $isShoppingCancellationWithFee) {
                throw new ApiException('Basis ongkir pembatalan hanya berlaku untuk pembatalan Nitip dengan fee 50%.', 422);
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
                $this->proofPolicyService->supportsDriverProofType($serviceCode, 'pickup') &&
                in_array($normalizedActionCode, ['BOARD_PASSENGER', 'CONFIRM_PICKED_UP'], true) &&
                ! $this->orderEvidenceService->hasProof($order, 'pickup')
            ) {
                throw new ApiException('Bukti foto pickup belum diupload.', 409);
            }

            if (
                $this->proofPolicyService->supportsDriverProofType($serviceCode, 'delivery') &&
                $normalizedActionCode === 'COMPLETE_ORDER' &&
                ! $this->orderEvidenceService->hasProof($order, 'delivery')
            ) {
                throw new ApiException('Bukti foto selesai pengantaran belum diupload.', 409);
            }

            $shoppingCancellationPenalty = null;
            $shoppingCancellationPenaltyBaseDeliveryFee = null;
            $shoppingPreviousCancellationPenaltyBaseDeliveryFee = null;
            $shoppingPreviousCancellationPenalty = null;
            $hasManualCancellationPricing = false;
            if ($isShoppingCancellationWithFee) {
                if (! $this->shoppingPricingService->isCancellationPenaltyEligible($order)) {
                    throw new ApiException('Order belum memenuhi batas failed attempt untuk dibatalkan dengan fee.', 409);
                }

                if ($this->orderPaymentService->isPaid($order)) {
                    throw new ApiException('Order sudah memiliki pembayaran lunas dan tidak bisa dibatalkan dengan fee.', 409);
                }

                $shoppingPreviousCancellationPenaltyBaseDeliveryFee = $this->shoppingPricingService->cancellationPenaltyBaseAmount($order);
                $shoppingPreviousCancellationPenalty = $this->shoppingPricingService->calculateCancellationPenalty($order);
                $hasManualCancellationPricing = $cancellationPenaltyBaseDeliveryFee !== null;
                if ($hasManualCancellationPricing) {
                    if (trim((string) $note) === '') {
                        throw new ApiException('Alasan koreksi ongkir pembatalan wajib diisi.', 422);
                    }

                    $shoppingCancellationPenaltyBaseDeliveryFee = round(max(0.0, (float) $cancellationPenaltyBaseDeliveryFee), 2);
                    $shoppingCancellationPenalty = $this->shoppingPricingService->calculateCancellationPenaltyFromBase(
                        $shoppingCancellationPenaltyBaseDeliveryFee,
                    );
                } else {
                    $shoppingCancellationPenaltyBaseDeliveryFee = $shoppingPreviousCancellationPenaltyBaseDeliveryFee;
                    $shoppingCancellationPenalty = $shoppingPreviousCancellationPenalty;
                }
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

            $targetStatusId = $this->orderStatusResolver->resolveStatusId($resolvedTargetStatusCode);
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
            $this->shoppingUnavailableItemPurger->purgeTerminalShoppingUnavailableItems(
                $order,
                (int) $actor->id,
                $resolvedTargetStatusCode,
                'DRIVER_FINALIZED_SHOPPING_ORDER',
            );
            $this->syncDriverServiceTimestamp($order, $resolvedTargetStatusCode);
            if (! $this->isRunningDriverStatusCode($resolvedTargetStatusCode)) {
                $this->driverOrderLifecycleService->syncAvailabilityAfterNonRunningOrder($driver->id);
            }

            $snapshot = [
                'action_code' => $normalizedActionCode,
                'service_type' => $serviceCode,
                ...($shoppingCancellationPenaltyBaseDeliveryFee !== null ? [
                    'penalty_base_delivery_fee' => round($shoppingCancellationPenaltyBaseDeliveryFee, 2),
                    'cancellation_penalty' => round((float) $shoppingCancellationPenalty, 2),
                    'cancellation_penalty_percent' => $this->shoppingPricingService->cancellationPenaltyPercent(),
                ] : []),
                ...($hasManualCancellationPricing ? [
                    'pricing_scope' => ShoppingPricingService::PRICING_SCOPE_SHOPPING_CANCELLATION_BASE_50_PERCENT,
                    'previous_penalty_base_delivery_fee' => round((float) $shoppingPreviousCancellationPenaltyBaseDeliveryFee, 2),
                    'previous_cancellation_penalty' => round((float) $shoppingPreviousCancellationPenalty, 2),
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

            $statusChangeEventPayload = $this->orderRealtimeNotifier->buildOrderStatusBroadcastPayload(
                $order->id,
                $resolvedTargetStatusCode,
                strtoupper($currentStatusCode),
                $statusHistory,
            );
            if ($hasManualCancellationPricing) {
                OrderLog::query()->create([
                    'order_id' => $order->id,
                    'event_type' => 'PRICE_UPDATE',
                    'trigger_type' => ShoppingPricingService::MANUAL_CANCELLATION_TRIGGER,
                    'changed_by_user_id' => $actor->id,
                    'note' => $eventNote,
                    'metadata' => [
                        'pricing_scope' => ShoppingPricingService::PRICING_SCOPE_SHOPPING_CANCELLATION_BASE_50_PERCENT,
                        'previous_cancellation_penalty_base_delivery_fee' => round((float) $shoppingPreviousCancellationPenaltyBaseDeliveryFee, 2),
                        'cancellation_penalty_base_delivery_fee' => round((float) $shoppingCancellationPenaltyBaseDeliveryFee, 2),
                        'cancellation_penalty_percent' => $this->shoppingPricingService->cancellationPenaltyPercent(),
                        'previous_cancellation_penalty' => round((float) $shoppingPreviousCancellationPenalty, 2),
                        'cancellation_penalty' => round((float) $shoppingCancellationPenalty, 2),
                        'reason' => $eventNote,
                    ],
                ]);
            }
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

        $this->orderRealtimeNotifier->broadcastOrderStatusChanged($statusChangeEventPayload);
        $this->orderRealtimeNotifier->sendOrderStatusPushNotification($order, $statusChangeEventPayload);
        $this->paymentProofReminderNotificationService->scheduleForBlockingPaymentStatus($order->refresh());

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    private function hasRunningDriverOrder(int $driverId): bool
    {
        return $this->driverOrderLifecycleService->hasRunningOrder($driverId);
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
}
