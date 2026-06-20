<?php

namespace App\Services\Order;

use App\Enums\ServiceTypeCode;
use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderLog;
use App\Models\OrderPayment;

class DeliveryFeeNegotiationService
{
    public const EVENT_TYPE = 'DELIVERY_FEE_NEGOTIATION';

    public const DRIVER_FEE_QUOTED = 'DRIVER_FEE_QUOTED';

    public const CUSTOMER_FEE_APPROVED = 'CUSTOMER_FEE_APPROVED';

    public const CUSTOMER_FEE_COUNTERED = 'CUSTOMER_FEE_COUNTERED';

    public const DRIVER_COUNTER_APPROVED = 'DRIVER_COUNTER_APPROVED';

    public const DRIVER_FEE_REQUOTED = 'DRIVER_FEE_REQUOTED';

    public const CUSTOMER_CANCEL_ORDER = 'CUSTOMER_CANCEL_ORDER';

    public function __construct(private readonly OrderNegotiationLogService $negotiationLogs) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Order $order,
        string $triggerType,
        ?int $actorId,
        ?string $note = null,
        array $metadata = [],
    ): OrderLog {
        return $this->negotiationLogs->record(self::EVENT_TYPE, $order, $triggerType, $actorId, $note, $metadata);
    }

    public function latest(Order|int $order): ?OrderLog
    {
        return $this->negotiationLogs->latest(self::EVENT_TYPE, $order);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function snapshot(Order $order): ?array
    {
        if (! $this->supportsOrder($order)) {
            return null;
        }

        $latest = $this->latest($order);
        if (! $latest) {
            return [
                'status' => 'NONE',
                'trigger_type' => null,
                'quoted_amount' => null,
                'counter_amount' => null,
                'approved_amount' => null,
                'old_delivery_fee' => $this->negotiationLogs->floatOrNull($order->delivery_fee),
                'note' => null,
                'updated_at' => null,
                'can_customer_respond' => false,
                'can_driver_submit_quote' => $this->canDriverSubmitQuote($order),
                'can_driver_accept_counter' => false,
                'approval_required' => false,
                'is_pending' => false,
            ];
        }

        return $this->snapshotFromLog($order, $latest);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFromLog(Order $order, OrderLog $log): array
    {
        $trigger = strtoupper((string) $log->trigger_type);
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $status = $this->statusForTrigger($trigger);
        $isPendingCustomer = $status === 'PENDING_CUSTOMER';
        $isPendingDriver = $status === 'PENDING_DRIVER';
        $isApproved = $status === 'APPROVED';

        return [
            'status' => $status,
            'trigger_type' => $trigger,
            'quote_log_id' => $this->negotiationLogs->intOrNull($metadata['quote_log_id'] ?? $log->id),
            'quoted_amount' => $this->negotiationLogs->floatOrNull(
                $metadata['quoted_amount'] ?? $metadata['amount'] ?? null
            ),
            'base_amount' => $this->negotiationLogs->floatOrNull($metadata['base_amount'] ?? null),
            'final_amount' => $this->negotiationLogs->floatOrNull(
                $metadata['final_amount'] ?? $metadata['quoted_amount'] ?? $metadata['amount'] ?? null
            ),
            'counter_amount' => $this->negotiationLogs->floatOrNull($metadata['counter_amount'] ?? null),
            'counter_base_amount' => $this->negotiationLogs->floatOrNull($metadata['counter_base_amount'] ?? null),
            'approved_amount' => $this->negotiationLogs->floatOrNull($metadata['approved_amount'] ?? null),
            'old_delivery_fee' => $this->negotiationLogs->floatOrNull($metadata['old_delivery_fee'] ?? null),
            'note' => $log->note,
            'updated_at' => $this->negotiationLogs->iso($log->created_at),
            'can_customer_respond' => $isPendingCustomer && $this->canCustomerRespond($order),
            'can_driver_submit_quote' => $this->canDriverSubmitQuote($order),
            'can_driver_accept_counter' => $isPendingDriver && $this->canDriverSubmitQuote($order),
            'approval_required' => $isPendingCustomer || $isPendingDriver,
            'is_pending' => $isPendingCustomer || $isPendingDriver,
            'is_approved' => $isApproved,
        ];
    }

    public function hasPendingApproval(Order $order): bool
    {
        $snapshot = $this->snapshot($order);

        return is_array($snapshot) && (bool) ($snapshot['is_pending'] ?? false);
    }

    /**
     * @return array{base_amount: float, final_amount: float}
     */
    public function quoteAmounts(Order $order, ?float $baseAmount): array
    {
        $base = round(max(0.0, $baseAmount ?? $this->defaultDeliveryFee($order)), 2);

        return [
            'base_amount' => $base,
            'final_amount' => $base,
        ];
    }

    public function blocksDriverProgress(Order $order, string $actionCode): bool
    {
        if (! $this->hasPendingApproval($order)) {
            return false;
        }

        $serviceCode = ServiceTypeCode::normalize((string) ($order->serviceType?->code ?? ''));

        return $this->blocksDriverProgressFor($serviceCode, $actionCode);
    }

    public function blocksDriverProgressFor(string $serviceCode, string $actionCode): bool
    {
        $service = ServiceTypeCode::normalize($serviceCode);
        $action = strtoupper(str_replace('-', '_', trim($actionCode)));

        return match ($service) {
            ServiceTypeCode::Ride->value => in_array($action, ['ARRIVE_PICKUP', 'BOARD_PASSENGER'], true),
            ServiceTypeCode::Courier->value => in_array($action, ['ARRIVE_PICKUP', 'CONFIRM_PICKED_UP'], true),
            ServiceTypeCode::Shopping->value => in_array($action, ['ARRIVE_PICKUP', 'CONFIRM_PICKED_UP'], true),
            default => false,
        };
    }

    public function canDriverSubmitQuote(Order $order): bool
    {
        if (! $this->supportsOrder($order) || ! $this->isWithinEditableStatus($order)) {
            return false;
        }

        if ($this->hasPaidPayment($order) || $this->hasPendingTransferEvidence($order)) {
            return false;
        }

        $latest = $this->latest($order);
        if (! $latest) {
            return true;
        }

        return $this->statusForTrigger(strtoupper((string) $latest->trigger_type)) !== 'PENDING_CUSTOMER';
    }

    public function canCustomerRespond(Order $order): bool
    {
        return $this->supportsOrder($order)
            && $this->isWithinEditableStatus($order)
            && ! $this->hasPaidPayment($order)
            && ! $this->hasPendingTransferEvidence($order);
    }

    public function nextDriverQuoteTrigger(Order $order): string
    {
        $latest = $this->latest($order);
        $trigger = strtoupper((string) ($latest?->trigger_type ?? ''));

        return $trigger === self::CUSTOMER_FEE_COUNTERED
            ? self::DRIVER_FEE_REQUOTED
            : self::DRIVER_FEE_QUOTED;
    }

    private function supportsOrder(Order $order): bool
    {
        $serviceCode = ServiceTypeCode::normalize((string) ($order->serviceType?->code ?? ''));

        return in_array($serviceCode, [
            ServiceTypeCode::Ride->value,
            ServiceTypeCode::Courier->value,
            ServiceTypeCode::Shopping->value,
        ], true);
    }

    private function isWithinEditableStatus(Order $order): bool
    {
        $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));

        return $statusCode === 'DRIVER_ASSIGNED';
    }

    private function statusForTrigger(string $trigger): string
    {
        return match ($trigger) {
            self::DRIVER_FEE_QUOTED, self::DRIVER_FEE_REQUOTED => 'PENDING_CUSTOMER',
            self::CUSTOMER_FEE_COUNTERED => 'PENDING_DRIVER',
            self::CUSTOMER_FEE_APPROVED, self::DRIVER_COUNTER_APPROVED => 'APPROVED',
            self::CUSTOMER_CANCEL_ORDER => 'CANCELLED_ORDER',
            default => 'NONE',
        };
    }

    private function defaultDeliveryFee(Order $order): float
    {
        $route = $order->route;
        $routeFee = is_array($route) ? data_get($route, 'delivery_pricing.total_fee') : null;

        return is_numeric($routeFee)
            ? (float) $routeFee
            : (float) $order->delivery_fee;
    }

    private function hasPaidPayment(Order $order): bool
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

    private function hasPendingTransferEvidence(Order $order): bool
    {
        if ($order->relationLoaded('evidences')) {
            return $order->evidences->contains(
                fn (OrderEvidence $evidence): bool => strtoupper((string) $evidence->evidence_type) === 'PAYMENT_TRANSFER_PHOTO'
            );
        }

        return $order->evidences()
            ->where('evidence_type', 'PAYMENT_TRANSFER_PHOTO')
            ->exists();
    }
}
