<?php

namespace App\Services\Order;

use App\Enums\OrderStatusCode;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderStatus;

final class OrderStatusResolver
{
    public function resolveStatusId(string $input): int
    {
        $normalized = strtoupper(str_replace([' ', '-'], '_', trim($input)));

        $legacyMap = [
            'CONFIRMED' => 'PENDING',
            'DRIVER_ASSIGNED' => 'DRIVER_ASSIGNED',
            'PICKING_UP' => 'PICKED_UP',
            'ON_DELIVERY' => 'ON_THE_WAY',
            'DELIVERED' => 'DELIVERED',
            'COMPLETED' => 'COMPLETED',
            'CANCELLED' => 'CANCELLED',
        ];

        $statusCode = $legacyMap[$normalized] ?? $normalized;
        $statusId = OrderStatus::query()->where('code', $statusCode)->value('id');

        if (! $statusId) {
            throw new ApiException('Status order tidak valid.', 422);
        }

        return (int) $statusId;
    }

    public function orderStatusCode(Order $order): string
    {
        $status = $order->statusRef;

        return $status !== null
            ? OrderStatusCode::normalize($status->code)
            : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelledOrderUpdateAttributes(string $statusCode, string $cancelledBy, string $reason): array
    {
        return [
            'status_id' => $this->resolveStatusId($statusCode),
            'cancelled_by' => $cancelledBy,
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
        ];
    }
}
