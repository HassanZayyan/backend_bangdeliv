<?php

namespace App\Services;

use App\Events\DriverLocationUpdated;
use App\Events\OrderChatMessageSent;
use App\Events\OrderContentUpdated;
use App\Events\OrderStatusChanged;
use Illuminate\Support\Facades\Log;

class OrderRealtimeBroadcaster
{
    /**
     * @param  array<string, mixed>  $message
     */
    public function orderChatMessageSent(int $orderId, array $message): bool
    {
        return $this->safelyBroadcast(
            new OrderChatMessageSent($orderId, $message),
            'OrderChatMessageSent',
            [
                'order_id' => $orderId,
                'message_id' => $message['id'] ?? null,
            ],
        );
    }

    public function driverLocationUpdated(
        int $orderId,
        float $latitude,
        float $longitude,
        string $updatedAt,
    ): bool {
        return $this->safelyBroadcast(
            new DriverLocationUpdated($orderId, $latitude, $longitude, $updatedAt),
            'DriverLocationUpdated',
            [
                'order_id' => $orderId,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
        );
    }

    public function orderStatusChanged(
        int $orderId,
        string $statusCode,
        ?string $previousStatusCode,
        ?int $historyId,
        string $changedAt,
        ?int $changedAtMs,
        ?string $statusLabel,
        ?bool $isTerminal,
    ): bool {
        return $this->safelyBroadcast(
            new OrderStatusChanged(
                $orderId,
                $statusCode,
                $previousStatusCode,
                $historyId,
                $changedAt,
                $changedAtMs,
                $statusLabel,
                $isTerminal,
            ),
            'OrderStatusChanged',
            [
                'order_id' => $orderId,
                'status_code' => $statusCode,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    public function orderContentUpdated(int $orderId, string $changeType, array $pricing = []): bool
    {
        return $this->safelyBroadcast(
            new OrderContentUpdated($orderId, $changeType, $pricing, now()->toIso8601String()),
            'OrderContentUpdated',
            [
                'order_id' => $orderId,
                'change_type' => $changeType,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function safelyBroadcast(object $event, string $eventName, array $context): bool
    {
        $startedAt = microtime(true);

        try {
            broadcast($event);

            Log::debug("Broadcast {$eventName} terkirim.", [
                ...$context,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::warning("Broadcast {$eventName} gagal.", [
                ...$context,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
