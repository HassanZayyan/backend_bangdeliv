<?php

namespace App\Services\Shopping;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLocation;

class ShoppingUnavailableItemDecisionService
{
    /**
     * @return array<string, bool>
     */
    public function actionsForPickup(Order $order, int $pickupLocationId): array
    {
        $hasUnavailableItems = $this->hasUnavailableItems($order, $pickupLocationId);

        return [
            'can_edit' => $hasUnavailableItems,
            'can_continue_without_item' => $hasUnavailableItems
                && $this->hasAvailableItemsAfterRemovingUnavailable($order, $pickupLocationId),
            'can_cancel_merchant' => $hasUnavailableItems,
        ];
    }

    public function assertCanContinueWithoutItem(Order $order, int $pickupLocationId, int $itemId): void
    {
        $order->loadMissing(['items', 'orderLocations']);

        $pickup = $this->pickupById($order, $pickupLocationId);
        if (! $pickup instanceof OrderLocation) {
            throw new ApiException('Merchant yang ingin diedit tidak valid.', 422);
        }

        $item = $order->items->first(
            fn (OrderItem $candidate): bool => (int) $candidate->id === $itemId
        );
        if (! $item instanceof OrderItem || (int) ($item->pickup_location_id ?? 0) !== $pickupLocationId) {
            throw new ApiException('Item yang ingin dilewati tidak sesuai merchant.', 422);
        }

        if ((bool) ($item->is_available ?? true)) {
            throw new ApiException('Lanjut tanpa item hanya tersedia untuk item yang tidak tersedia.', 409);
        }

        if (! $this->hasAvailableItemsAfterRemovingUnavailable($order, $pickupLocationId)) {
            throw new ApiException('Merchant hanya punya item tidak tersedia. Pilih edit item atau batal merchant.', 409);
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
