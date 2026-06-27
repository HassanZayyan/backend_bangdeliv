<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderLog;
use App\Models\OrderPayment;
use App\Services\Order\OrderPaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class AdminPaymentProofStatusService
{
    public const APPROVED_TRIGGER = 'PAYMENT_PROOF_APPROVED';

    public const REJECTED_TRIGGER = 'PAYMENT_PROOF_REJECTED';

    public const PAYMENT_TRANSFER_EVIDENCE_TYPE = 'PAYMENT_TRANSFER_PHOTO';

    /**
     * @return array<int, string>
     */
    public function decisionTriggers(): array
    {
        return [
            self::APPROVED_TRIGGER,
            self::REJECTED_TRIGGER,
        ];
    }

    public function constrainDecisionLogs(Builder|Relation $query): void
    {
        $query
            ->with('changedBy:id,name')
            ->whereIn('trigger_type', $this->decisionTriggers());
    }

    /**
     * @param  Collection<int, OrderLog>|null  $logs
     * @return array<string, mixed>
     */
    public function decisionFor(OrderEvidence $proof, ?OrderPayment $payment = null, ?Collection $logs = null): array
    {
        $logs ??= $this->decisionLogsForProof($proof);

        $approvedLog = $this->latestLogForProof($logs, $proof, self::APPROVED_TRIGGER);
        if ($approvedLog instanceof OrderLog) {
            return [
                'status' => 'approved',
                'label' => 'Disetujui',
                'class' => 'badge-success',
                'decided_by' => $approvedLog->changedBy?->name,
                'decided_at' => $approvedLog->created_at,
                'reason' => null,
                'note' => $approvedLog->note,
            ];
        }

        $rejectedLog = $this->latestLogForProof($logs, $proof, self::REJECTED_TRIGGER);
        if ($rejectedLog instanceof OrderLog) {
            return [
                'status' => 'rejected',
                'label' => 'Ditolak',
                'class' => 'badge-danger',
                'decided_by' => $rejectedLog->changedBy?->name,
                'decided_at' => $rejectedLog->created_at,
                'reason' => $rejectedLog->note,
                'note' => $rejectedLog->note,
            ];
        }

        $payment ??= $this->paymentForProof($proof);
        if ($payment instanceof OrderPayment
            && strtoupper((string) $payment->payment_method) === OrderPaymentService::METHOD_TRANSFER
            && strtoupper((string) $payment->payment_status) === OrderPaymentService::STATUS_PAID
        ) {
            return [
                'status' => 'approved',
                'label' => 'Pembayaran Lunas',
                'class' => 'badge-success',
                'decided_by' => $payment->recordedBy?->name,
                'decided_at' => $payment->paid_at,
                'reason' => null,
                'note' => 'Pembayaran sudah tercatat lunas.',
            ];
        }

        return [
            'status' => 'pending',
            'label' => 'Menunggu Verifikasi',
            'class' => 'badge-warning',
            'decided_by' => null,
            'decided_at' => null,
            'reason' => null,
            'note' => null,
        ];
    }

    /**
     * @param  Collection<int, OrderLog>|null  $logs
     */
    public function hasDecision(OrderEvidence $proof, string $trigger, ?Collection $logs = null): bool
    {
        $logs ??= $this->decisionLogsForProof($proof);

        return $this->latestLogForProof($logs, $proof, $trigger) instanceof OrderLog;
    }

    public function pendingCountForOrder(Order $order): int
    {
        $payment = $this->latestPaymentForOrder($order);
        if (! $this->isPendingTransferPayment($payment)) {
            return 0;
        }

        $logs = $this->decisionLogsForOrder($order);

        return $this->paymentTransferProofsForOrder($order)
            ->filter(fn (OrderEvidence $proof): bool => $this->decisionFor($proof, $payment, $logs)['status'] === 'pending')
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function feedbackForOrder(Order $order): array
    {
        $payment = $this->latestPaymentForOrder($order);
        $logs = $this->decisionLogsForOrder($order);

        if ($this->isPaidPayment($payment)
            && strtoupper((string) $payment?->payment_method) === OrderPaymentService::METHOD_TRANSFER
        ) {
            return [
                'status' => 'approved',
                'label' => 'Disetujui',
                'reason' => null,
                'note' => 'Pembayaran sudah tercatat lunas.',
                'proof_id' => null,
                'decided_by' => $payment->recordedBy?->name,
                'decided_at' => $payment->paid_at,
            ];
        }

        $latestProof = $this->latestPaymentTransferProofForOrder($order);
        if ($latestProof instanceof OrderEvidence) {
            $decision = $this->decisionFor($latestProof, $payment, $logs);

            return [
                'status' => $decision['status'],
                'label' => $decision['label'],
                'reason' => $decision['reason'],
                'note' => $decision['note'],
                'proof_id' => (int) $latestProof->id,
                'decided_by' => $decision['decided_by'],
                'decided_at' => $decision['decided_at'],
            ];
        }

        $latestReject = $this->latestRejectedLogForOrder($order, $logs);
        if ($latestReject instanceof OrderLog) {
            return [
                'status' => 'rejected',
                'label' => 'Ditolak',
                'reason' => $latestReject->note,
                'note' => $latestReject->note,
                'proof_id' => data_get($latestReject->metadata, 'deleted_evidence_id')
                    ?? data_get($latestReject->metadata, 'order_evidence_id'),
                'decided_by' => $latestReject->changedBy?->name,
                'decided_at' => $latestReject->created_at,
            ];
        }

        return [
            'status' => 'none',
            'label' => null,
            'reason' => null,
            'note' => null,
            'proof_id' => null,
            'decided_by' => null,
            'decided_at' => null,
        ];
    }

    /**
     * @return Collection<int, OrderEvidence>
     */
    public function paymentTransferProofsForOrder(Order $order): Collection
    {
        if (! $order->relationLoaded('evidences')) {
            $order->load('evidences');
        }

        return $order->evidences
            ->filter(fn (OrderEvidence $proof): bool => strtoupper((string) $proof->evidence_type) === self::PAYMENT_TRANSFER_EVIDENCE_TYPE)
            ->values();
    }

    public function latestPaymentTransferProofForOrder(Order $order): ?OrderEvidence
    {
        return $this->paymentTransferProofsForOrder($order)
            ->sortByDesc(fn (OrderEvidence $proof): int => (($proof->uploaded_at?->getTimestamp() ?? $proof->created_at?->getTimestamp() ?? 0) * 1000000) + (int) $proof->id)
            ->first();
    }

    /**
     * @return Collection<int, OrderEvidence>
     */
    public function pendingPaymentProofs(): Collection
    {
        return OrderEvidence::query()
            ->with([
                'order.user:id,name,phone',
                'order.payment',
                'order.logs' => fn (Relation $query) => $this->constrainDecisionLogs($query),
            ])
            ->where('evidence_type', self::PAYMENT_TRANSFER_EVIDENCE_TYPE)
            ->whereHas('order.payment', function (Builder $query): void {
                $query
                    ->where('payment_method', OrderPaymentService::METHOD_TRANSFER)
                    ->where('payment_status', OrderPaymentService::STATUS_PENDING);
            })
            ->latest('uploaded_at')
            ->get()
            ->filter(function (OrderEvidence $proof): bool {
                $payment = $proof->order?->payment;
                $logs = $proof->order?->logs;

                return $this->decisionFor($proof, $payment, $logs)['status'] === 'pending';
            })
            ->values();
    }

    /**
     * @return Collection<int, OrderLog>
     */
    public function decisionLogsForOrder(Order $order): Collection
    {
        if ($order->relationLoaded('logs')) {
            return $order->logs
                ->filter(fn (OrderLog $log): bool => in_array($log->trigger_type, $this->decisionTriggers(), true))
                ->values();
        }

        $query = OrderLog::query()->where('order_id', $order->id);
        $this->constrainDecisionLogs($query);

        return $query->latest('created_at')->get();
    }

    public function isPaidPayment(?OrderPayment $payment): bool
    {
        return $payment instanceof OrderPayment
            && strtoupper((string) $payment->payment_status) === OrderPaymentService::STATUS_PAID;
    }

    public function isPendingTransferPayment(?OrderPayment $payment): bool
    {
        return $payment instanceof OrderPayment
            && strtoupper((string) $payment->payment_method) === OrderPaymentService::METHOD_TRANSFER
            && strtoupper((string) $payment->payment_status) === OrderPaymentService::STATUS_PENDING;
    }

    private function latestPaymentForOrder(Order $order): ?OrderPayment
    {
        if ($order->relationLoaded('payment')) {
            return $order->payment;
        }

        if ($order->relationLoaded('payments')) {
            return $order->payments
                ->sortByDesc(fn (OrderPayment $payment): int => $payment->paid_at?->getTimestamp() ?? $payment->created_at?->getTimestamp() ?? 0)
                ->first();
        }

        return $order->payment()->first();
    }

    private function paymentForProof(OrderEvidence $proof): ?OrderPayment
    {
        if ($proof->relationLoaded('order') && $proof->order instanceof Order) {
            return $this->latestPaymentForOrder($proof->order);
        }

        return OrderPayment::query()
            ->with('recordedBy:id,name')
            ->where('order_id', $proof->order_id)
            ->first();
    }

    /**
     * @return Collection<int, OrderLog>
     */
    private function decisionLogsForProof(OrderEvidence $proof): Collection
    {
        $query = OrderLog::query()->where('order_id', $proof->order_id);
        $this->constrainDecisionLogs($query);

        return $query->latest('created_at')->get();
    }

    /**
     * @param  Collection<int, OrderLog>  $logs
     */
    private function latestLogForProof(Collection $logs, OrderEvidence $proof, string $trigger): ?OrderLog
    {
        return $logs
            ->filter(fn (OrderLog $log): bool => $log->trigger_type === $trigger
                && (int) data_get($log->metadata, 'order_evidence_id') === (int) $proof->id)
            ->sortByDesc(fn (OrderLog $log): int => (($log->created_at?->getTimestamp() ?? 0) * 1000000) + (int) $log->id)
            ->first();
    }

    /**
     * @param  Collection<int, OrderLog>  $logs
     */
    private function latestRejectedLogForOrder(Order $order, Collection $logs): ?OrderLog
    {
        return $logs
            ->filter(fn (OrderLog $log): bool => (int) $log->order_id === (int) $order->id
                && $log->trigger_type === self::REJECTED_TRIGGER)
            ->sortByDesc(fn (OrderLog $log): int => (($log->created_at?->getTimestamp() ?? 0) * 1000000) + (int) $log->id)
            ->first();
    }
}
