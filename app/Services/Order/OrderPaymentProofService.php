<?php

namespace App\Services\Order;

use App\Events\AdminNotificationUpdated;
use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderLog;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\Admin\AdminNotificationService;
use App\Services\Admin\AdminPaymentProofStatusService;
use App\Services\Driver\DriverOrderLifecycleService;
use App\Services\Driver\DriverOrderPayloadFactory;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class OrderPaymentProofService
{
    public function __construct(
        private readonly OrderPaymentService $orderPaymentService,
        private readonly OrderEvidenceService $orderEvidenceService,
        private readonly OrderProofPolicyService $proofPolicyService,
        private readonly DriverOrderPayloadFactory $driverOrderPayloadFactory,
        private readonly DriverOrderLifecycleService $driverOrderLifecycleService,
        private readonly OrderRealtimeNotifier $orderRealtimeNotifier,
        private readonly DriverOrderResolver $driverOrderResolver
    ) {}

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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function uploadProof(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder($orderId, $driver->id, ['statusRef', 'serviceType']);
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
    public function confirmTransferPaymentByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder($orderId, $driver->id, [
                'statusRef',
                'serviceType',
                'payment',
                'payments',
                'evidences',
            ]);

            $this->assertDriverCanRecordTransferPayment($order);

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

        $this->driverOrderLifecycleService->syncAvailabilityAfterNonRunningOrder($driver->id);

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function bypassRejectedTransferPaymentByDriver(User $actor, int $orderId): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder($orderId, $driver->id, [
                'statusRef',
                'serviceType',
                'payment',
                'payments',
                'evidences',
            ]);

            $this->assertDriverCanRecordTransferPayment($order);

            $paymentProofFeedback = app(AdminPaymentProofStatusService::class)->feedbackForOrder($order);
            if (($paymentProofFeedback['status'] ?? null) !== 'rejected') {
                throw new ApiException('Bypass QRIS hanya tersedia setelah bukti ditolak dan sebelum ada bukti baru.', 409);
            }

            $amount = round((float) $order->total_price, 2);
            if ($amount <= 0) {
                throw new ApiException('Nominal QRIS harus lebih dari 0.', 422);
            }

            $rejectedProofId = data_get($paymentProofFeedback, 'proof_id');
            $rejectionReason = trim((string) data_get($paymentProofFeedback, 'reason', ''));

            $this->orderPaymentService->markPaid(
                $order,
                OrderPaymentService::METHOD_TRANSFER,
                $amount,
                $actor->id,
                $driver->id,
                now(),
                [
                    'recorded_by_role' => $actor->role,
                    'source' => 'DRIVER_QRIS_REJECTION_BYPASS',
                    'expected_amount' => $amount,
                    'bypassed_by_driver' => true,
                    'rejected_proof_id' => $rejectedProofId,
                    'rejection_reason' => $rejectionReason !== '' ? $rejectionReason : null,
                ],
            );

            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => 'PAYMENT_UPDATE',
                'trigger_type' => 'QRIS_PAYMENT_RECORDED_BY_DRIVER_BYPASS',
                'changed_by_user_id' => $actor->id,
                'note' => 'Driver membypass bukti QRIS yang ditolak.',
                'metadata' => [
                    'paid_amount' => $amount,
                    'expected_amount' => $amount,
                    'recorded_by_role' => $actor->role,
                    'bypassed_by_driver' => true,
                    'rejected_proof_id' => $rejectedProofId,
                    'rejection_reason' => $rejectionReason !== '' ? $rejectionReason : null,
                ],
            ]);

            return $order->refresh();
        });

        $this->driverOrderLifecycleService->syncAvailabilityAfterNonRunningOrder($driver->id);

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    private function assertDriverCanRecordTransferPayment(Order $order): void
    {
        if ($this->orderPaymentService->isPaid($order)) {
            throw new ApiException('Pembayaran order ini sudah tercatat.', 409);
        }

        if ($this->orderPaymentService->currentMethod($order) !== OrderPaymentService::METHOD_TRANSFER) {
            throw new ApiException('Order ini menggunakan pembayaran COD. Gunakan pencatatan COD.', 409);
        }

        $serviceCode = strtoupper((string) ($order->serviceType->code ?? ''));
        $statusCode = strtoupper((string) ($order->statusRef->code ?? ''));
        $isCourierPickupConfirmation = $serviceCode === 'COURIER' && $statusCode === 'ARRIVED_PICKUP';
        $isDeliveredConfirmation = $serviceCode !== 'COURIER' && $statusCode === 'DELIVERED';
        $isShoppingCancellationFeeConfirmation =
            $serviceCode === 'SHOPPING' && $statusCode === 'CANCELLED_WITH_FEE';

        if (! $isCourierPickupConfirmation && ! $isDeliveredConfirmation && ! $isShoppingCancellationFeeConfirmation) {
            $message = $serviceCode === 'COURIER'
                ? 'Pembayaran QRIS courier hanya bisa dicatat saat driver tiba di pickup.'
                : 'Pembayaran QRIS hanya bisa dicatat setelah order berstatus DELIVERED.';

            throw new ApiException($message, 409);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function rejectTransferPaymentByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
        $reason = trim((string) ($payload['rejection_reason'] ?? ''));
        if ($reason === '') {
            throw new ApiException('Alasan penolakan wajib diisi.', 422, [
                'rejection_reason' => ['Alasan penolakan wajib diisi.'],
            ]);
        }

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $reason): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder($orderId, $driver->id, [
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

        $this->orderRealtimeNotifier->broadcastContentUpdatedAfterCommit((int) $order->id, AdminPaymentProofStatusService::REJECTED_TRIGGER, [
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
