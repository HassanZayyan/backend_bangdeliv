<?php

namespace App\Services\Shopping;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLocation;

class ShoppingUnavailableItemDecisionService
{
    public function __construct(private readonly ShoppingReplacementProjectionService $replacementProjection) {}

    /**
     * @return array<string, mixed>
     */
    public function actionsForPickup(Order $order, int $pickupLocationId): array
    {
        $hasUnavailableItems = $this->hasUnavailableItems($order, $pickupLocationId);
        $pickup = $this->pickupById($order, $pickupLocationId);
        $order->loadMissing(['statusRef', 'serviceType']);
        $canDriverBypass = $hasUnavailableItems
            && $order->driver_id !== null
            && strtoupper((string) ($order->serviceType?->code ?? '')) === 'SHOPPING'
            && strtoupper((string) ($order->statusRef?->code ?? '')) === 'ARRIVED_MERCHANT'
            && strtoupper((string) ($pickup?->fulfillment_status ?? '')) === 'ITEMS_PENDING_CUSTOMER';
        $replacement = $this->replacementProjection->forPickup($order, $pickupLocationId);
        $canReplace = (bool) ($replacement['can_replace_merchant'] ?? false);

        return [
            'can_edit' => $hasUnavailableItems,
            'can_continue_without_item' => $hasUnavailableItems
                && $this->hasAvailableItemsAfterRemovingUnavailable($order, $pickupLocationId),
            'can_cancel_merchant' => $hasUnavailableItems,
            'can_driver_bypass' => $canDriverBypass,
            'can_driver_continue_without_item' => $canDriverBypass
                && $this->hasAvailableItemsAfterRemovingUnavailable($order, $pickupLocationId),
            'can_driver_cancel_merchant' => $canDriverBypass,
            'can_driver_replace_unavailable_items' => $canDriverBypass,
            'can_customer_replace_merchant' => $canReplace && $order->user_id !== null,
            'can_driver_replace_merchant' => $canReplace && $order->driver_id !== null,
            'replacement_block_reason' => $replacement['replacement_block_reason'] ?? null,
            'pending_replacement_approval' => $replacement['pending_replacement_approval'] ?? null,
            'chain_id' => $replacement['chain_id'],
            'chain_attempt_no' => $replacement['chain_attempt_no'],
            'chain_failed_attempt_count' => $replacement['chain_failed_attempt_count'],
            'chain_failed_attempt_limit' => $replacement['chain_failed_attempt_limit'],
            'order_failed_trip_count' => $replacement['order_failed_trip_count'],
            'verified_failed_trip_count' => $replacement['verified_failed_trip_count'],
            'compensation_eligible' => $replacement['compensation_eligible'],
            'state_version' => $replacement['state_version'],
        ];
    }

    public function assertCanContinueWithoutItem(Order $order, int $pickupLocationId, int $itemId): void
    {
        $this->assertCanContinueWithoutItems($order, $pickupLocationId, [$itemId]);
    }

    /**
     * @param  list<int>  $itemIds
     */
    public function assertCanContinueWithoutItems(Order $order, int $pickupLocationId, array $itemIds): void
    {
        $order->loadMissing(['items', 'orderLocations']);

        $pickup = $this->pickupById($order, $pickupLocationId);
        if (! $pickup instanceof OrderLocation) {
            throw new ApiException('Merchant yang ingin diedit tidak valid.', 422);
        }

        $normalizedItemIds = collect($itemIds)
            ->filter(fn (mixed $itemId): bool => is_numeric($itemId) && (int) $itemId > 0)
            ->map(fn (mixed $itemId): int => (int) $itemId)
            ->unique()
            ->values();
        if ($normalizedItemIds->isEmpty()) {
            throw new ApiException('Minimal satu item tidak tersedia wajib dipilih.', 422);
        }

        $items = $order->items->filter(
            fn (OrderItem $candidate): bool => $normalizedItemIds->contains((int) $candidate->id)
        );
        if ($items->count() !== $normalizedItemIds->count()) {
            throw new ApiException('Sebagian item yang ingin dilewati sudah berubah.', 409, [
                'code' => 'STATE_CHANGED',
            ]);
        }

        foreach ($items as $item) {
            if ((int) ($item->pickup_location_id ?? 0) !== $pickupLocationId) {
                throw new ApiException('Item yang ingin dilewati tidak sesuai toko/resto.', 422);
            }
            if ((bool) ($item->is_available ?? true)) {
                throw new ApiException('Status item sudah berubah.', 409, [
                    'code' => 'STATE_CHANGED',
                ]);
            }
        }

        if (! $this->hasAvailableItemsAfterRemovingUnavailable($order, $pickupLocationId)) {
            throw new ApiException('Toko/resto hanya punya item tidak tersedia. Pilih ganti item atau batal tempat.', 409, [
                'code' => 'STATE_CHANGED',
            ]);
        }
    }

    private function hasUnavailableItems(Order $order, int $pickupLocationId): bool
    {
        $order->loadMissing('items');

        return $order->items->contains(
            fn (OrderItem $item): bool => (int) ($item->pickup_location_id ?? 0) === $pickupLocationId
                && ! (bool) ($item->is_available ?? true)
        );
    }

    private function hasAvailableItemsAfterRemovingUnavailable(Order $order, int $pickupLocationId): bool
    {
        $order->loadMissing('items');

        return $order->items->contains(
            fn (OrderItem $item): bool => (int) ($item->pickup_location_id ?? 0) === $pickupLocationId
                && (bool) ($item->is_available ?? true)
        );
    }

    private function pickupById(Order $order, int $pickupLocationId): ?OrderLocation
    {
        $order->loadMissing('orderLocations');

        return $order->orderLocations->first(
            fn (OrderLocation $location): bool => (int) $location->id === $pickupLocationId
                && strtoupper((string) $location->location_role) === 'PICKUP'
        );
    }
}
