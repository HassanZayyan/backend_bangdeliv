<?php

namespace App\Services\Admin;

use App\Events\AdminNotificationUpdated;
use App\Events\OrderContentUpdated;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderLog;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\Order\OrderPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminPaymentProofVerificationService
{
    public function __construct(
        private readonly OrderPaymentService $orderPaymentService,
        private readonly AdminNotificationService $notificationService,
        private readonly AdminPaymentProofStatusService $proofStatuses,
    ) {}

    public function approve(User $admin, Order $order, OrderEvidence $evidence): Order
    {
        $order = DB::transaction(function () use ($admin, $order, $evidence): Order {
            [$lockedOrder, $evidence, $payment] = $this->lockedPendingProofContext($order, $evidence, true);
            $amount = (float) ($payment?->amount ?? $lockedOrder->total_price);

            $this->orderPaymentService->markPaid(
                $lockedOrder,
                OrderPaymentService::METHOD_TRANSFER,
                $amount,
                (int) $admin->id,
                $lockedOrder->driver_id,
                now(),
                [
                    'source' => 'ADMIN_QRIS_PROOF_APPROVAL',
                    'order_evidence_id' => (int) $evidence->id,
                ],
            );

            $this->logVerification($lockedOrder, $admin, $evidence, AdminPaymentProofStatusService::APPROVED_TRIGGER, 'Bukti QRIS disetujui admin.');

            return $lockedOrder->refresh();
        });

        $this->broadcastChanges((int) $order->id, AdminPaymentProofStatusService::APPROVED_TRIGGER);

        return $order;
    }

    public function reject(User $admin, Order $order, OrderEvidence $evidence, string $reason): Order
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Alasan penolakan wajib diisi.',
            ]);
        }

        $order = DB::transaction(function () use ($admin, $order, $evidence, $reason): Order {
            [$lockedOrder, $evidence] = $this->lockedPendingProofContext($order, $evidence);

            $this->logVerification($lockedOrder, $admin, $evidence, AdminPaymentProofStatusService::REJECTED_TRIGGER, $reason);

            return $lockedOrder->refresh();
        });

        $this->broadcastChanges((int) $order->id, AdminPaymentProofStatusService::REJECTED_TRIGGER);

        return $order;
    }

    /**
     * @return array{0: Order, 1: OrderEvidence, 2: OrderPayment|null}
     */
    private function lockedPendingProofContext(Order $order, OrderEvidence $evidence, bool $withDriver = false): array
    {
        $evidence = $this->lockedEvidence($order, $evidence);

        if ($this->proofStatuses->hasDecision($evidence, AdminPaymentProofStatusService::REJECTED_TRIGGER)) {
            throw ValidationException::withMessages([
                'proof' => 'Bukti QRIS ini sudah ditolak. Minta customer mengirim bukti baru.',
            ]);
        }

        $relations = ['payments'];
        if ($withDriver) {
            $relations[] = 'driver';
        }

        $lockedOrder = Order::query()
            ->with($relations)
            ->lockForUpdate()
            ->findOrFail($order->id);
        $payment = $lockedOrder->payments->first();

        if ($this->proofStatuses->isPaidPayment($payment)) {
            throw ValidationException::withMessages([
                'proof' => 'Pembayaran pesanan ini sudah tercatat lunas.',
            ]);
        }

        return [$lockedOrder, $evidence, $payment];
    }

    private function lockedEvidence(Order $order, OrderEvidence $evidence): OrderEvidence
    {
        $lockedEvidence = OrderEvidence::query()
            ->whereKey($evidence->id)
            ->where('order_id', $order->id)
            ->where('evidence_type', AdminPaymentProofStatusService::PAYMENT_TRANSFER_EVIDENCE_TYPE)
            ->lockForUpdate()
            ->first();

        if (! $lockedEvidence instanceof OrderEvidence) {
            throw ValidationException::withMessages([
                'proof' => 'Bukti QRIS tidak ditemukan untuk pesanan ini.',
            ]);
        }

        return $lockedEvidence;
    }

    private function logVerification(
        Order $order,
        User $admin,
        OrderEvidence $evidence,
        string $trigger,
        string $note,
    ): void {
        OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => 'PAYMENT_UPDATE',
            'trigger_type' => $trigger,
            'changed_by_user_id' => $admin->id,
            'note' => $note,
            'metadata' => [
                'order_evidence_id' => (int) $evidence->id,
                'payment_proof_status' => $trigger === AdminPaymentProofStatusService::APPROVED_TRIGGER ? 'approved' : 'rejected',
            ],
        ]);
    }

    private function broadcastChanges(int $orderId, string $changeType): void
    {
        broadcast(new OrderContentUpdated(
            $orderId,
            $changeType,
            [],
            now()->toIso8601String(),
        ));

        broadcast(new AdminNotificationUpdated($this->notificationService->summary()));
    }
}
