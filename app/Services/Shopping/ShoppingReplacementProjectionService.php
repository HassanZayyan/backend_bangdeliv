<?php

namespace App\Services\Shopping;

use App\Models\Order;
use App\Models\OrderLocation;
use App\Models\OrderLog;

class ShoppingReplacementProjectionService
{
    public const REPLACEMENT_EVENT = 'SHOPPING_MERCHANT_REPLACEMENT';

    public const FAILED_TRIP_EVENT = 'SHOPPING_FAILED_TRIP';

    public const CHECKPOINT_EVENT = 'SHOPPING_ROUTE_CHECKPOINT';

    public const CHAIN_ABANDONED_EVENT = 'SHOPPING_REPLACEMENT_CHAIN_ABANDONED';

    public const COMPENSATION_EVENT = 'SHOPPING_FAILED_TRIP_COMPENSATION_UPDATED';

    public const MAX_FAILURES_PER_CHAIN = 3;

    public const COMPENSATION_FAILURE_THRESHOLD = 3;

    /**
     * @return array{
     *   pickups: array<int, array<string, mixed>>,
     *   chains: array<string, array<string, mixed>>,
     *   order_failed_trip_count: int,
     *   verified_failed_trip_count: int,
     *   verified_failed_distance_meters: int,
     *   legacy_failed_trip_count: int,
     *   uses_legacy_failure_fallback: bool,
     *   compensation_eligible: bool,
     *   latest_event_id: int
     * }
     */
    public function snapshot(Order $order): array
    {
        $order->loadMissing(['orderLocations', 'statusRef']);
        $isTerminalOrder = in_array(
            strtoupper((string) ($order->statusRef?->code ?? '')),
            ['COMPLETED', 'CANCELLED', 'CANCELLED_WITH_FEE'],
            true,
        );
        $pickups = $order->orderLocations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->keyBy(fn (OrderLocation $location): int => (int) $location->id);
        $events = OrderLog::query()
            ->where('order_id', $order->id)
            ->whereIn('event_type', [
                self::REPLACEMENT_EVENT,
                self::FAILED_TRIP_EVENT,
                self::CHECKPOINT_EVENT,
                self::CHAIN_ABANDONED_EVENT,
                self::COMPENSATION_EVENT,
                'SHOPPING_ITEM_AVAILABILITY',
            ])
            ->orderBy('id')
            ->get();

        $replacementByTarget = [];
        $replacementBySource = [];
        $chainForPickup = [];
        $attemptForPickup = [];
        $stateVersionForPickup = [];

        foreach ($events as $event) {
            $metadata = $this->metadata($event);
            $eventId = (int) $event->id;
            $sourceId = $this->positiveInt($metadata['source_pickup_location_id'] ?? null);
            $targetId = $this->positiveInt($metadata['replacement_pickup_location_id'] ?? null);
            $pickupId = $this->positiveInt($metadata['pickup_location_id'] ?? null);

            if ($event->event_type === self::REPLACEMENT_EVENT && $sourceId !== null && $targetId !== null) {
                $chainId = $this->text($metadata['chain_id'] ?? null) ?? $this->chainIdForPickup($sourceId);
                $attemptNo = max(2, (int) ($metadata['attempt_no'] ?? 2));
                $replacementByTarget[$targetId] = $metadata + ['event_id' => $eventId];
                $replacementBySource[$sourceId] = $metadata + ['event_id' => $eventId];
                $chainForPickup[$sourceId] ??= $chainId;
                $chainForPickup[$targetId] = $chainId;
                $attemptForPickup[$targetId] = $attemptNo;
                $stateVersionForPickup[$sourceId] = max($stateVersionForPickup[$sourceId] ?? 0, $eventId);
                $stateVersionForPickup[$targetId] = max($stateVersionForPickup[$targetId] ?? 0, $eventId);
            }

            if ($pickupId !== null) {
                $stateVersionForPickup[$pickupId] = max($stateVersionForPickup[$pickupId] ?? 0, $eventId);
            }
        }

        foreach ($pickups as $pickupId => $pickup) {
            $chainForPickup[$pickupId] ??= $this->chainIdForPickup($pickupId);
            $attemptForPickup[$pickupId] ??= isset($replacementByTarget[$pickupId])
                ? max(2, (int) ($replacementByTarget[$pickupId]['attempt_no'] ?? 2))
                : 1;
        }

        $failedByChain = [];
        $failedPickupIds = [];
        $verifiedFailedPickupIds = [];
        $verifiedDistanceMeters = 0;
        $latestEventId = 0;
        foreach ($events as $event) {
            $latestEventId = max($latestEventId, (int) $event->id);
            if ($event->event_type !== self::FAILED_TRIP_EVENT) {
                continue;
            }

            $metadata = $this->metadata($event);
            $pickupId = $this->positiveInt($metadata['pickup_location_id'] ?? null);
            if ($pickupId === null || isset($failedPickupIds[$pickupId])) {
                continue;
            }

            $chainId = $this->text($metadata['chain_id'] ?? null)
                ?? ($chainForPickup[$pickupId] ?? $this->chainIdForPickup($pickupId));
            $failedPickupIds[$pickupId] = true;
            $failedByChain[$chainId][$pickupId] = true;
            if (($metadata['verified_for_compensation'] ?? false) === true) {
                $verifiedFailedPickupIds[$pickupId] = true;
                $verifiedDistanceMeters += max(0, (int) ($metadata['distance_meters'] ?? 0));
            }
        }

        // Backward-compatible fallback for orders created before event projection existed.
        foreach ($pickups as $pickupId => $pickup) {
            if (isset($failedPickupIds[$pickupId]) || (int) ($pickup->failed_attempt_count ?? 0) <= 0) {
                continue;
            }

            $chainId = $chainForPickup[$pickupId] ?? $this->chainIdForPickup($pickupId);
            $failedPickupIds[$pickupId] = true;
            $failedByChain[$chainId][$pickupId] = true;
        }

        $chainSnapshots = [];
        $legacyFailedTripCount = 0;
        $usesLegacyFailureFallback = false;
        foreach ($pickups as $pickup) {
            $storedCount = max(0, (int) ($pickup->failed_attempt_count ?? 0));
            $legacyFailedTripCount += $storedCount;
            $usesLegacyFailureFallback = $usesLegacyFailureFallback || $storedCount > 1;
        }
        foreach (array_unique(array_values($chainForPickup)) as $chainId) {
            $members = array_keys(array_filter(
                $chainForPickup,
                fn (string $candidate): bool => $candidate === $chainId
            ));
            usort($members, fn (int $left, int $right): int => ($attemptForPickup[$left] ?? 1) <=> ($attemptForPickup[$right] ?? 1));
            $failedCount = count($failedByChain[$chainId] ?? []);
            foreach ($members as $memberPickupId) {
                $failedCount = max(
                    $failedCount,
                    max(0, (int) $pickups[$memberPickupId]->failed_attempt_count),
                );
            }
            $latestPickupId = $members !== [] ? (int) end($members) : null;
            $chainSnapshots[$chainId] = [
                'chain_id' => $chainId,
                'pickup_location_ids' => $members,
                'latest_pickup_location_id' => $latestPickupId,
                'failed_attempt_count' => $failedCount,
                'max_failed_attempts' => self::MAX_FAILURES_PER_CHAIN,
                'is_abandoned' => $failedCount >= self::MAX_FAILURES_PER_CHAIN
                    || ($latestPickupId !== null
                        && strtoupper((string) $pickups[$latestPickupId]->fulfillment_status) === 'ABANDONED_AFTER_LIMIT'),
            ];
        }

        $pickupSnapshots = [];
        $globalFailureCount = max(count($failedPickupIds), $legacyFailedTripCount);
        $verifiedFailureCount = count($verifiedFailedPickupIds);
        foreach ($pickups as $pickupId => $pickup) {
            $chainId = $chainForPickup[$pickupId] ?? $this->chainIdForPickup($pickupId);
            $chain = $chainSnapshots[$chainId] ?? [
                'failed_attempt_count' => 0,
                'is_abandoned' => false,
            ];
            $status = strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING'));
            $isCurrent = (int) ($chainSnapshots[$chainId]['latest_pickup_location_id'] ?? 0) === $pickupId;
            $failedCount = (int) $chain['failed_attempt_count'];
            $isUnavailableDecision = $status === 'ITEMS_PENDING_CUSTOMER';
            $isClosedReplaceable = $status === 'FAILED';
            $failureAlreadyRecorded = isset($failedPickupIds[$pickupId]);
            $wouldReachLimit = ! $failureAlreadyRecorded && $failedCount >= self::MAX_FAILURES_PER_CHAIN - 1;
            $canReplace = $isCurrent
                && ! $isTerminalOrder
                && ! $chain['is_abandoned']
                && ($isUnavailableDecision || $isClosedReplaceable)
                && ! ($isUnavailableDecision && $wouldReachLimit)
                && $failedCount < self::MAX_FAILURES_PER_CHAIN;

            $pickupSnapshots[$pickupId] = [
                'chain_id' => $chainId,
                'chain_attempt_no' => (int) ($attemptForPickup[$pickupId] ?? 1),
                'chain_failed_attempt_count' => $failedCount,
                'chain_failed_attempt_limit' => self::MAX_FAILURES_PER_CHAIN,
                'order_failed_trip_count' => $globalFailureCount,
                'verified_failed_trip_count' => $verifiedFailureCount,
                'compensation_eligible' => $verifiedFailureCount >= self::COMPENSATION_FAILURE_THRESHOLD,
                'state_version' => (int) ($stateVersionForPickup[$pickupId] ?? 0),
                'can_replace_merchant' => $canReplace,
                'replacement_block_reason' => $canReplace
                    ? null
                    : ($isTerminalOrder
                        ? 'Order sudah berakhir.'
                        : $this->replacementBlockReason($status, $isCurrent, $chain['is_abandoned'], $wouldReachLimit)),
                'replaced_from_pickup_location_id' => $this->positiveInt($replacementByTarget[$pickupId]['source_pickup_location_id'] ?? null),
                'replacement_pickup_location_id' => $this->positiveInt($replacementBySource[$pickupId]['replacement_pickup_location_id'] ?? null),
                'google_place_id' => $this->text($replacementByTarget[$pickupId]['merchant']['google_place_id'] ?? null),
            ];
        }

        return [
            'pickups' => $pickupSnapshots,
            'chains' => $chainSnapshots,
            'order_failed_trip_count' => $globalFailureCount,
            'verified_failed_trip_count' => $verifiedFailureCount,
            'verified_failed_distance_meters' => $verifiedDistanceMeters,
            'legacy_failed_trip_count' => $legacyFailedTripCount,
            'uses_legacy_failure_fallback' => $usesLegacyFailureFallback,
            'compensation_eligible' => $verifiedFailureCount >= self::COMPENSATION_FAILURE_THRESHOLD,
            'latest_event_id' => $latestEventId,
        ];
    }

    /** @return array<string, mixed> */
    public function forPickup(Order $order, int $pickupLocationId): array
    {
        return $this->snapshot($order)['pickups'][$pickupLocationId] ?? [
            'chain_id' => $this->chainIdForPickup($pickupLocationId),
            'chain_attempt_no' => 1,
            'chain_failed_attempt_count' => 0,
            'chain_failed_attempt_limit' => self::MAX_FAILURES_PER_CHAIN,
            'order_failed_trip_count' => 0,
            'verified_failed_trip_count' => 0,
            'compensation_eligible' => false,
            'state_version' => 0,
            'can_replace_merchant' => false,
            'replacement_block_reason' => 'State merchant belum tersedia.',
        ];
    }

    public function chainIdForPickup(int $pickupLocationId): string
    {
        return 'pickup:'.$pickupLocationId;
    }

    /** @return array<string, mixed> */
    private function metadata(OrderLog $event): array
    {
        $metadata = $event->getAttribute('metadata');

        return is_array($metadata) ? $metadata : [];
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    private function replacementBlockReason(string $status, bool $isCurrent, bool $isAbandoned, bool $wouldReachLimit): string
    {
        if (! $isCurrent) {
            return 'Merchant ini sudah memiliki pengganti.';
        }
        if ($isAbandoned || $wouldReachLimit) {
            return 'Batas tiga kegagalan untuk rantai merchant ini sudah tercapai.';
        }
        if (! in_array($status, ['FAILED', 'ITEMS_PENDING_CUSTOMER'], true)) {
            return 'Ganti merchant hanya tersedia setelah merchant gagal atau ada item tidak tersedia.';
        }

        return 'Ganti merchant tidak tersedia pada state saat ini.';
    }
}
