<?php

namespace App\Services\Shopping;

use App\Models\Order;
use App\Models\OrderLog;

class ShoppingItemChangeRequestService
{
    public const EVENT_TYPE = 'SHOPPING_ITEM_CHANGE_REQUEST';

    public const CUSTOMER_REQUESTED = 'CUSTOMER_ITEM_CHANGE_REQUESTED';

    public const DRIVER_APPROVED = 'DRIVER_ITEM_CHANGE_APPROVED';

    public const DRIVER_REJECTED = 'DRIVER_ITEM_CHANGE_REJECTED';

    public const CUSTOMER_APPLIED = 'CUSTOMER_ITEM_CHANGE_APPLIED';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordRequest(Order $order, int $actorId, array $metadata, ?string $note = null): OrderLog
    {
        return OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => self::EVENT_TYPE,
            'trigger_type' => self::CUSTOMER_REQUESTED,
            'changed_by_user_id' => $actorId,
            'note' => $note ?: 'Customer meminta perubahan item Nitip.',
            'metadata' => [
                ...$metadata,
                'status' => 'PENDING_DRIVER',
            ],
            'created_at' => now(),
        ]);
    }

    public function approve(Order $order, int $actorId, OrderLog $requestLog, ?string $note = null): OrderLog
    {
        return $this->recordResponse($order, $actorId, $requestLog, self::DRIVER_APPROVED, 'APPROVED', $note ?: 'Driver menyetujui perubahan item Nitip.');
    }

    public function reject(Order $order, int $actorId, OrderLog $requestLog, ?string $note = null): OrderLog
    {
        return $this->recordResponse($order, $actorId, $requestLog, self::DRIVER_REJECTED, 'REJECTED', $note ?: 'Driver menolak perubahan item Nitip.');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordApplied(Order $order, int $actorId, array $metadata, ?string $note = null): OrderLog
    {
        return OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => self::EVENT_TYPE,
            'trigger_type' => self::CUSTOMER_APPLIED,
            'changed_by_user_id' => $actorId,
            'note' => $note ?: 'Customer langsung memperbarui item Nitip yang tidak tersedia.',
            'metadata' => [
                ...$metadata,
                'status' => 'APPROVED',
            ],
            'created_at' => now(),
        ]);
    }

    public function latest(Order|int $order): ?OrderLog
    {
        $orderId = $order instanceof Order ? (int) $order->id : $order;

        return OrderLog::query()
            ->where('order_id', $orderId)
            ->where('event_type', self::EVENT_TYPE)
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    public function pending(Order|int $order): ?OrderLog
    {
        $latest = $this->latest($order);

        return $latest !== null && strtoupper((string) $latest->trigger_type) === self::CUSTOMER_REQUESTED
            ? $latest
            : null;
    }

    public function hasPending(Order|int $order): bool
    {
        return $this->pending($order) !== null;
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
        if ($latest === null) {
            return [
                'status' => 'NONE',
                'trigger_type' => null,
                'request_log_id' => null,
                'action' => null,
                'items' => [],
                'item_id' => null,
                'note' => null,
                'updated_at' => null,
                'can_driver_respond' => false,
            ];
        }

        $metadata = is_array($latest->metadata) ? $latest->metadata : [];
        $items = is_array($metadata['items'] ?? null) ? $metadata['items'] : [];
        $trigger = strtoupper((string) $latest->trigger_type);
        $status = match ($trigger) {
            self::CUSTOMER_REQUESTED => 'PENDING_DRIVER',
            self::DRIVER_APPROVED => 'APPROVED',
            self::DRIVER_REJECTED => 'REJECTED',
            default => strtoupper((string) ($metadata['status'] ?? 'NONE')),
        };

        return [
            'status' => $status,
            'trigger_type' => $trigger,
            'request_log_id' => (int) ($metadata['request_log_id'] ?? $latest->id),
            'request_kind' => strtoupper((string) ($metadata['request_kind'] ?? 'EDIT_UNAVAILABLE')),
            'target_pickup_location_id' => is_numeric($metadata['target_pickup_location_id'] ?? null)
                ? (int) $metadata['target_pickup_location_id']
                : null,
            'action' => strtoupper((string) ($metadata['action'] ?? 'ADD')),
            'items' => $items,
            'requested_stops' => $this->requestedStops($order, $metadata, $items),
            'item_id' => is_numeric($metadata['item_id'] ?? null) ? (int) $metadata['item_id'] : null,
            'note' => $latest->note,
            'updated_at' => $latest->created_at?->toIso8601String(),
            'can_driver_respond' => $status === 'PENDING_DRIVER',
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function requestedStops(Order $order, array $metadata, array $items): array
    {
        $requestKind = strtoupper((string) ($metadata['request_kind'] ?? 'EDIT_UNAVAILABLE'));
        $targetPickupLocationId = is_numeric($metadata['target_pickup_location_id'] ?? null)
            ? (int) $metadata['target_pickup_location_id']
            : null;
        $order->loadMissing(['orderLocations.restaurant']);

        $stops = [];
        if ($requestKind === 'EDIT_UNAVAILABLE' && $targetPickupLocationId !== null) {
            $pickup = $order->orderLocations->first(
                fn ($location): bool => (int) $location->id === $targetPickupLocationId
                    && strtoupper((string) $location->location_role) === 'PICKUP'
            );

            $stops[$targetPickupLocationId] = [
                'pickup_location_id' => $targetPickupLocationId,
                'request_kind' => $requestKind,
                'merchant_id' => $pickup?->restaurant_id !== null ? (int) $pickup->restaurant_id : null,
                'merchant_name' => $pickup?->restaurant?->name ?? $pickup?->label ?? 'Merchant',
                'merchant_address' => $pickup?->full_address,
                'merchant_type' => $pickup?->restaurant?->merchant_type,
                'merchant_latitude' => is_numeric($pickup?->latitude) ? (float) $pickup->latitude : null,
                'merchant_longitude' => is_numeric($pickup?->longitude) ? (float) $pickup->longitude : null,
                'items' => [],
            ];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $this->requestedStopKey($item, $targetPickupLocationId);
            if (! isset($stops[$key])) {
                $stops[$key] = [
                    'pickup_location_id' => $targetPickupLocationId,
                    'request_kind' => $requestKind,
                    'merchant_id' => is_numeric($item['merchant_id'] ?? null) ? (int) $item['merchant_id'] : null,
                    'merchant_name' => $this->textOrNull($item['merchant_name'] ?? null) ?? 'Merchant',
                    'merchant_address' => $this->textOrNull($item['merchant_address'] ?? null),
                    'merchant_type' => $this->textOrNull($item['merchant_type'] ?? null),
                    'merchant_latitude' => is_numeric($item['merchant_latitude'] ?? null) ? (float) $item['merchant_latitude'] : null,
                    'merchant_longitude' => is_numeric($item['merchant_longitude'] ?? null) ? (float) $item['merchant_longitude'] : null,
                    'items' => [],
                ];
            }

            $stops[$key]['items'][] = [
                'merchant_id' => is_numeric($item['merchant_id'] ?? null) ? (int) $item['merchant_id'] : null,
                'menu_id' => is_numeric($item['menu_id'] ?? null) ? (int) $item['menu_id'] : null,
                'item_source' => strtoupper((string) ($item['item_source'] ?? 'MANUAL')),
                'name' => $this->textOrNull($item['menu_name'] ?? null)
                    ?? $this->textOrNull($item['name'] ?? null)
                    ?? $this->textOrNull($item['item_name'] ?? null)
                    ?? '-',
                'quantity' => is_numeric($item['quantity'] ?? null) ? max(1, (int) $item['quantity']) : 1,
                'notes' => $this->textOrNull($item['notes'] ?? null),
            ];
        }

        return array_values($stops);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function requestedStopKey(array $item, ?int $targetPickupLocationId): string|int
    {
        if ($targetPickupLocationId !== null) {
            return $targetPickupLocationId;
        }

        if (is_numeric($item['merchant_id'] ?? null)) {
            return 'merchant:'.(int) $item['merchant_id'];
        }

        $placeId = $this->textOrNull($item['merchant_place_id'] ?? null);
        if ($placeId !== null) {
            return 'place:'.$placeId;
        }

        $name = mb_strtolower((string) ($this->textOrNull($item['merchant_name'] ?? null) ?? 'merchant'));
        $latitude = is_numeric($item['merchant_latitude'] ?? null) ? round((float) $item['merchant_latitude'], 6) : '0';
        $longitude = is_numeric($item['merchant_longitude'] ?? null) ? round((float) $item['merchant_longitude'], 6) : '0';

        return 'external:'.$name.':'.$latitude.':'.$longitude;
    }

    private function textOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function recordResponse(
        Order $order,
        int $actorId,
        OrderLog $requestLog,
        string $triggerType,
        string $status,
        string $note,
    ): OrderLog {
        $metadata = is_array($requestLog->metadata) ? $requestLog->metadata : [];

        return OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => self::EVENT_TYPE,
            'trigger_type' => $triggerType,
            'changed_by_user_id' => $actorId,
            'note' => $note,
            'metadata' => [
                ...$metadata,
                'request_log_id' => (int) $requestLog->id,
                'status' => $status,
            ],
            'created_at' => now(),
        ]);
    }
}
