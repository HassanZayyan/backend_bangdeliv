<?php

namespace App\Services\Shopping;

use App\Models\Order;
use App\Models\OrderLog;
use App\Services\Order\OrderNegotiationLogService;

class ShoppingPriceNegotiationService
{
    public const EVENT_TYPE = 'SHOPPING_NEGOTIATION';

    public const DRIVER_PRICE_QUOTED = 'DRIVER_PRICE_QUOTED';

    public const CUSTOMER_PRICE_APPROVED = 'CUSTOMER_PRICE_APPROVED';

    public const CUSTOMER_PRICE_COUNTERED = 'CUSTOMER_PRICE_COUNTERED';

    public const DRIVER_COUNTER_APPROVED = 'DRIVER_COUNTER_APPROVED';

    public const DRIVER_PRICE_REQUOTED = 'DRIVER_PRICE_REQUOTED';

    public const CUSTOMER_CANCEL_MERCHANT = 'CUSTOMER_CANCEL_MERCHANT';

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
        if (strtoupper((string) ($order->serviceType?->code ?? '')) !== 'SHOPPING') {
            return null;
        }

        $latest = $this->latest($order);
        if (! $latest) {
            return [
                'status' => 'NONE',
                'trigger_type' => null,
                'pickup_location_id' => null,
                'quoted_amount' => null,
                'counter_amount' => null,
                'approved_amount' => null,
                'note' => null,
                'updated_at' => null,
                'can_customer_respond' => false,
                'can_driver_submit_quote' => $this->canDriverSubmitQuote($order),
                'can_driver_accept_counter' => false,
                'checkout_allowed' => false,
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
            'pickup_location_id' => $this->negotiationLogs->intOrNull($metadata['pickup_location_id'] ?? null),
            'quote_log_id' => $this->negotiationLogs->intOrNull($metadata['quote_log_id'] ?? $log->id),
            'quoted_amount' => $this->negotiationLogs->floatOrNull(
                $metadata['quoted_amount'] ?? $metadata['amount'] ?? null
            ),
            'counter_amount' => $this->negotiationLogs->floatOrNull($metadata['counter_amount'] ?? null),
            'approved_amount' => $this->negotiationLogs->floatOrNull($metadata['approved_amount'] ?? null),
            'note' => $log->note,
            'updated_at' => $this->negotiationLogs->iso($log->created_at),
            'can_customer_respond' => $isPendingCustomer,
            'can_driver_submit_quote' => $this->canDriverSubmitQuote($order),
            'can_driver_accept_counter' => $isPendingDriver,
            'checkout_allowed' => $isApproved,
        ];
    }

    public function isApproved(Order $order): bool
    {
        $snapshot = $this->snapshot($order);

        return is_array($snapshot) && (bool) ($snapshot['checkout_allowed'] ?? false);
    }

    public function nextDriverQuoteTrigger(Order $order): string
    {
        $latest = $this->latest($order);
        $trigger = strtoupper((string) ($latest?->trigger_type ?? ''));

        return $trigger === self::CUSTOMER_PRICE_COUNTERED
            ? self::DRIVER_PRICE_REQUOTED
            : self::DRIVER_PRICE_QUOTED;
    }

    private function canDriverSubmitQuote(Order $order): bool
    {
        return strtoupper((string) ($order->serviceType?->code ?? '')) === 'SHOPPING'
            && strtoupper((string) ($order->statusRef?->code ?? '')) === 'ARRIVED_MERCHANT';
    }

    private function statusForTrigger(string $trigger): string
    {
        return match ($trigger) {
            self::DRIVER_PRICE_QUOTED, self::DRIVER_PRICE_REQUOTED => 'PENDING_CUSTOMER',
            self::CUSTOMER_PRICE_COUNTERED => 'PENDING_DRIVER',
            self::CUSTOMER_PRICE_APPROVED, self::DRIVER_COUNTER_APPROVED => 'APPROVED',
            self::CUSTOMER_CANCEL_MERCHANT => 'CANCELLED_MERCHANT',
            self::CUSTOMER_CANCEL_ORDER => 'CANCELLED_ORDER',
            default => 'NONE',
        };
    }
}
