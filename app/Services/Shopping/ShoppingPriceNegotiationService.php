<?php

namespace App\Services\Shopping;

use App\Models\Order;
use App\Models\OrderLocation;
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

    public const SHOPPING_ITEM_CHANGE_REQUIRES_REQUOTE = 'SHOPPING_ITEM_CHANGE_REQUIRES_REQUOTE';

    public const DRIVER_ITEM_CHANGE_REQUIRES_REQUOTE = 'DRIVER_ITEM_CHANGE_REQUIRES_REQUOTE';

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
    public function snapshotForPickup(Order $order, int $pickupLocationId): ?array
    {
        $latest = $this->latestForPickup($order, $pickupLocationId);

        return $latest !== null
            ? $this->snapshotFromLog($order, $latest, $pickupLocationId)
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function snapshot(Order $order): ?array
    {
        if (strtoupper((string) ($order->serviceType?->code ?? '')) !== 'SHOPPING') {
            return null;
        }

        $merchantQuotes = $this->merchantSnapshots($order);
        $primary = $this->primarySnapshot($merchantQuotes);
        if ($primary === null && ($latest = $this->latest($order)) !== null) {
            $primary = $this->snapshotFromLog($order, $latest);
        }

        $approvedSubtotal = $this->approvedSubtotalFromMerchantQuotes($merchantQuotes);
        $allApproved = $this->allRequiredMerchantQuotesApproved($merchantQuotes);
        if ($merchantQuotes === [] && $primary !== null) {
            $allApproved = strtoupper((string) ($primary['status'] ?? '')) === 'APPROVED';
            $approvedSubtotal = $allApproved ? (float) ($primary['approved_amount'] ?? 0) : 0.0;
        }
        $hasPendingItemChangeRequest = app(ShoppingItemChangeRequestService::class)->hasPending($order);

        if ($primary === null) {
            $primary = [
                'status' => 'NONE',
                'trigger_type' => null,
                'pickup_location_id' => null,
                'merchant_name' => null,
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

        return [
            ...$primary,
            'merchant_quotes' => $merchantQuotes,
            'approved_subtotal' => $approvedSubtotal,
            'all_required_quotes_approved' => $allApproved,
            'checkout_allowed' => $allApproved && ! $hasPendingItemChangeRequest,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFromLog(Order $order, OrderLog $log, ?int $pickupLocationId = null): array
    {
        $trigger = strtoupper((string) $log->trigger_type);
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $status = $this->statusForTrigger($trigger);
        $isPendingCustomer = $status === 'PENDING_CUSTOMER';
        $isPendingDriver = $status === 'PENDING_DRIVER';
        $isApproved = $status === 'APPROVED';
        $hasPendingItemChangeRequest = app(ShoppingItemChangeRequestService::class)->hasPending($order);
        $pickupId = $pickupLocationId ?? $this->negotiationLogs->intOrNull($metadata['pickup_location_id'] ?? null);
        $pickup = $pickupId !== null ? $this->pickupById($order, $pickupId) : null;

        return [
            'status' => $status,
            'trigger_type' => $trigger,
            'pickup_location_id' => $pickupId,
            'merchant_name' => $pickup?->restaurant?->name ?? $pickup?->contact_name ?? $pickup?->label,
            'quote_log_id' => $this->negotiationLogs->intOrNull($metadata['quote_log_id'] ?? $log->id),
            'quoted_amount' => $this->negotiationLogs->floatOrNull(
                $metadata['quoted_amount'] ?? $metadata['amount'] ?? null
            ),
            'counter_amount' => $this->negotiationLogs->floatOrNull($metadata['counter_amount'] ?? null),
            'approved_amount' => $this->negotiationLogs->floatOrNull($metadata['approved_amount'] ?? null),
            'note' => $log->note,
            'updated_at' => $this->negotiationLogs->iso($log->created_at),
            'can_customer_respond' => $isPendingCustomer,
            'can_driver_submit_quote' => $this->canDriverSubmitQuote($order, $pickupId),
            'can_driver_accept_counter' => $isPendingDriver,
            'checkout_allowed' => $isApproved && ! $hasPendingItemChangeRequest,
        ];
    }

    public function isApproved(Order $order): bool
    {
        $snapshot = $this->snapshot($order);

        return is_array($snapshot) && (bool) ($snapshot['checkout_allowed'] ?? false);
    }

    public function approvedSubtotal(Order $order): ?float
    {
        $snapshot = $this->snapshot($order);
        if (! is_array($snapshot)) {
            return null;
        }

        $amount = $this->negotiationLogs->floatOrNull($snapshot['approved_subtotal'] ?? null);

        return $amount !== null && $amount > 0 ? $amount : null;
    }

    public function nextDriverQuoteTrigger(Order $order, ?int $pickupLocationId = null): string
    {
        $latest = $pickupLocationId !== null
            ? $this->latestForPickup($order, $pickupLocationId)
            : $this->latest($order);
        $trigger = strtoupper((string) ($latest?->trigger_type ?? ''));

        return $trigger === self::CUSTOMER_PRICE_COUNTERED
            ? self::DRIVER_PRICE_REQUOTED
            : self::DRIVER_PRICE_QUOTED;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function merchantSnapshots(Order $order): array
    {
        $pickups = $this->activePickupLocations($order);
        if ($pickups === []) {
            return [];
        }

        $logs = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', self::EVENT_TYPE)
            ->orderBy('id')
            ->get();

        $latestByPickup = [];
        foreach ($logs as $log) {
            $metadata = is_array($log->metadata) ? $log->metadata : [];
            $pickupId = $this->negotiationLogs->intOrNull($metadata['pickup_location_id'] ?? null);
            if ($pickupId === null && count($pickups) === 1) {
                $onlyPickup = reset($pickups);
                $pickupId = $onlyPickup instanceof OrderLocation ? (int) $onlyPickup->id : null;
            }

            if ($pickupId === null || ! isset($pickups[$pickupId])) {
                continue;
            }

            $latestByPickup[$pickupId] = $log;
        }

        $snapshots = [];
        foreach ($pickups as $pickup) {
            $pickupId = (int) $pickup->id;
            $snapshot = isset($latestByPickup[$pickupId])
                ? $this->snapshotFromLog($order, $latestByPickup[$pickupId], $pickupId)
                : $this->emptyMerchantSnapshot($order, $pickup);
            $snapshot['has_available_items'] = $this->pickupHasAvailableItems($order, $pickupId);
            $snapshots[] = $snapshot;
        }

        return $snapshots;
    }

    private function canDriverSubmitQuote(Order $order, ?int $pickupLocationId = null): bool
    {
        if ($pickupLocationId !== null && $this->pickupById($order, $pickupLocationId) === null) {
            return false;
        }

        return strtoupper((string) ($order->serviceType?->code ?? '')) === 'SHOPPING'
            && strtoupper((string) ($order->statusRef?->code ?? '')) === 'ARRIVED_MERCHANT'
            && ! app(ShoppingItemChangeRequestService::class)->hasPending($order);
    }

    private function statusForTrigger(string $trigger): string
    {
        return match ($trigger) {
            self::DRIVER_PRICE_QUOTED, self::DRIVER_PRICE_REQUOTED => 'PENDING_CUSTOMER',
            self::CUSTOMER_PRICE_COUNTERED => 'PENDING_DRIVER',
            self::CUSTOMER_PRICE_APPROVED, self::DRIVER_COUNTER_APPROVED => 'APPROVED',
            self::CUSTOMER_CANCEL_MERCHANT => 'CANCELLED_MERCHANT',
            self::CUSTOMER_CANCEL_ORDER => 'CANCELLED_ORDER',
            self::SHOPPING_ITEM_CHANGE_REQUIRES_REQUOTE, self::DRIVER_ITEM_CHANGE_REQUIRES_REQUOTE => 'NEEDS_REQUOTE',
            default => 'NONE',
        };
    }

    private function latestForPickup(Order $order, int $pickupLocationId): ?OrderLog
    {
        return OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', self::EVENT_TYPE)
            ->latest('id')
            ->get()
            ->first(function (OrderLog $log) use ($pickupLocationId): bool {
                $metadata = is_array($log->metadata) ? $log->metadata : [];

                return $this->negotiationLogs->intOrNull($metadata['pickup_location_id'] ?? null) === $pickupLocationId;
            });
    }

    /**
     * @return array<int, OrderLocation>
     */
    private function activePickupLocations(Order $order): array
    {
        $order->loadMissing(['orderLocations.restaurant', 'items']);

        return $order->orderLocations
            ->filter(function (OrderLocation $location): bool {
                if (strtoupper((string) $location->location_role) !== 'PICKUP') {
                    return false;
                }

                $fulfillmentStatus = strtoupper((string) ($location->fulfillment_status ?? 'PENDING'));

                return ! in_array($fulfillmentStatus, ['FAILED', 'REPLACED', 'SKIPPED', 'CANCELLED'], true);
            })
            ->sortBy('sequence_no')
            ->keyBy(fn (OrderLocation $location): int => (int) $location->id)
            ->all();
    }

    private function pickupById(Order $order, int $pickupLocationId): ?OrderLocation
    {
        $pickups = $this->activePickupLocations($order);

        return $pickups[$pickupLocationId] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMerchantSnapshot(Order $order, OrderLocation $pickup): array
    {
        return [
            'status' => 'NONE',
            'trigger_type' => null,
            'pickup_location_id' => (int) $pickup->id,
            'merchant_name' => $pickup->restaurant?->name ?? $pickup->contact_name ?? $pickup->label,
            'quote_log_id' => null,
            'quoted_amount' => null,
            'counter_amount' => null,
            'approved_amount' => null,
            'note' => null,
            'updated_at' => null,
            'can_customer_respond' => false,
            'can_driver_submit_quote' => $this->canDriverSubmitQuote($order, (int) $pickup->id),
            'can_driver_accept_counter' => false,
            'checkout_allowed' => false,
            'has_available_items' => $this->pickupHasAvailableItems($order, (int) $pickup->id),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $merchantQuotes
     * @return array<string, mixed>|null
     */
    private function primarySnapshot(array $merchantQuotes): ?array
    {
        foreach (['PENDING_CUSTOMER', 'PENDING_DRIVER', 'NEEDS_REQUOTE', 'NONE', 'APPROVED'] as $status) {
            $matches = array_values(array_filter(
                $merchantQuotes,
                fn (array $quote): bool => strtoupper((string) ($quote['status'] ?? '')) === $status
                    && (bool) ($quote['has_available_items'] ?? true)
            ));
            if ($matches !== []) {
                return $matches[array_key_last($matches)];
            }
        }

        return $merchantQuotes[array_key_first($merchantQuotes)] ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $merchantQuotes
     */
    private function approvedSubtotalFromMerchantQuotes(array $merchantQuotes): float
    {
        return round(array_reduce(
            $merchantQuotes,
            function (float $total, array $quote): float {
                if (strtoupper((string) ($quote['status'] ?? '')) !== 'APPROVED') {
                    return $total;
                }

                return $total + (float) ($quote['approved_amount'] ?? 0);
            },
            0.0
        ), 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $merchantQuotes
     */
    private function allRequiredMerchantQuotesApproved(array $merchantQuotes): bool
    {
        $required = array_values(array_filter(
            $merchantQuotes,
            fn (array $quote): bool => (bool) ($quote['has_available_items'] ?? true)
        ));
        if ($required === []) {
            return false;
        }

        foreach ($required as $quote) {
            if (strtoupper((string) ($quote['status'] ?? '')) !== 'APPROVED') {
                return false;
            }
        }

        return true;
    }

    private function pickupHasAvailableItems(Order $order, int $pickupLocationId): bool
    {
        $order->loadMissing('items');

        return $order->items->contains(
            fn ($item): bool => (int) ($item->pickup_location_id ?? 0) === $pickupLocationId
                && (bool) ($item->is_available ?? true)
        );
    }
}
