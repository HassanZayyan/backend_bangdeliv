<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLog;

final class ShoppingUnavailableItemPurger
{
    public function purgeTerminalShoppingUnavailableItems(
        Order $order,
        ?int $actorUserId,
        string $terminalStatusCode,
        string $triggerType,
    ): void {
        $order->loadMissing('serviceType');
        if (
            strtoupper((string) ($order->serviceType?->code ?? '')) !== 'SHOPPING'
            || ! in_array(strtoupper($terminalStatusCode), ['COMPLETED', 'CANCELLED', 'CANCELLED_WITH_FEE'], true)
        ) {
            return;
        }

        $items = $order->items()
            ->where('is_available', false)
            ->lockForUpdate()
            ->get();
        if ($items->isEmpty()) {
            return;
        }

        foreach ($items->groupBy(fn (OrderItem $item): string => (string) ($item->pickup_location_id ?? 'none')) as $group) {
            $snapshots = $group
                ->map(fn (OrderItem $item): array => $this->shoppingUnavailableItemAuditSnapshot($item))
                ->values()
                ->all();
            $pickupLocationId = $group->first()?->pickup_location_id;

            OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => 'SHOPPING_ITEM_AVAILABILITY',
                'trigger_type' => $triggerType,
                'changed_by_user_id' => $actorUserId,
                'note' => 'Item tidak tersedia dihapus saat order Nitip mencapai status terminal.',
                'metadata' => [
                    'terminal_status_code' => strtoupper($terminalStatusCode),
                    'pickup_location_id' => $pickupLocationId !== null ? (int) $pickupLocationId : null,
                    'removed_items' => $snapshots,
                ],
            ]);
        }

        $items->each->delete();
        $order->unsetRelation('items');
    }

    /**
     * @return array<string, mixed>
     */
    public function shoppingUnavailableItemAuditSnapshot(OrderItem $item): array
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];

        return [
            'id' => (int) $item->id,
            'pickup_location_id' => $item->pickup_location_id !== null ? (int) $item->pickup_location_id : null,
            'name' => trim((string) ($item->menu_name ?? '')) ?: 'Item Nitip',
            'quantity' => max(1, (int) $item->quantity),
            'unit_price' => round((float) $item->unit_price, 2),
            'subtotal' => round((float) $item->subtotal, 2),
            'price_status' => $metadata['price_status'] ?? null,
            'failure_reason' => $metadata['failure_reason'] ?? null,
        ];
    }
}
