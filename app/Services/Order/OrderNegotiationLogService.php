<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderLog;
use Carbon\CarbonInterface;

class OrderNegotiationLogService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $eventType,
        Order $order,
        string $triggerType,
        ?int $actorId,
        ?string $note = null,
        array $metadata = [],
    ): OrderLog {
        return OrderLog::query()->create([
            'order_id' => $order->id,
            'log_type' => $eventType,
            'trigger_type' => $triggerType,
            'changed_by_user_id' => $actorId,
            'note' => $note,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function latest(string $eventType, Order|int $order): ?OrderLog
    {
        $orderId = $order instanceof Order ? (int) $order->id : $order;

        return OrderLog::query()
            ->where('order_id', $orderId)
            ->where('event_type', $eventType)
            ->latest('id')
            ->first();
    }

    public function iso(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }

    public function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    public function boolOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
    }
}
