<?php

namespace App\Services;

use App\Events\DriverLocationUpdated;
use App\Events\OrderChatMessageSent;
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
        float $heading,
        string $updatedAt,
    ): bool {
        return $this->safelyBroadcast(
            new DriverLocationUpdated($orderId, $latitude, $longitude, $heading, $updatedAt),
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
     * @param  array<string, mixed>  $context
     */
    private function safelyBroadcast(object $event, string $eventName, array $context): bool
    {
        try {
            broadcast($event);

            return true;
        } catch (\Throwable $exception) {
            Log::warning("Broadcast {$eventName} gagal.", [
                ...$context,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
