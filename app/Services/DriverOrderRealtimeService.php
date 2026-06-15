<?php

namespace App\Services;

use App\Events\DriverOrderAvailable;
use App\Events\DriverOrderRemoved;
use App\Models\Order;
use App\Services\Dispatch\DriverCandidateSelector;
use Illuminate\Support\Facades\Log;

class DriverOrderRealtimeService
{
    public function __construct(
        private readonly DriverOrderPayloadFactory $payloadFactory,
        private readonly DriverCandidateSelector $candidateSelector,
    ) {}

    public function broadcastOrderAvailable(Order $order): void
    {
        $freshOrder = $order->fresh($this->payloadFactory->relations());
        if (! $freshOrder) {
            return;
        }

        foreach ($this->candidateSelector->candidatesForOrder($freshOrder) as $candidate) {
            $driverUserId = (int) $candidate['driver']->user_id;
            if ($driverUserId <= 0) {
                continue;
            }

            $payload = [
                ...$this->payloadFactory->serialize($freshOrder),
                'dispatch' => $candidate['dispatch'],
            ];

            $this->safelyBroadcast(
                new DriverOrderAvailable($driverUserId, $payload),
                'DriverOrderAvailable',
                [
                    'driver_user_id' => $driverUserId,
                    'order_id' => $freshOrder->id,
                ],
            );
        }
    }

    public function broadcastOrderRemoved(int|Order $order, ?string $reason = null): void
    {
        $orderId = $order instanceof Order ? (int) $order->id : (int) $order;
        if ($orderId <= 0) {
            return;
        }

        foreach ($this->availableDriverUserIds() as $driverUserId) {
            $this->safelyBroadcast(
                new DriverOrderRemoved($driverUserId, $orderId, $reason),
                'DriverOrderRemoved',
                [
                    'driver_user_id' => $driverUserId,
                    'order_id' => $orderId,
                    'reason' => $reason,
                ],
            );
        }
    }

    public function broadcastOrderRemovedForDriverUser(int $driverUserId, int|Order $order, ?string $reason = null): void
    {
        $orderId = $order instanceof Order ? (int) $order->id : (int) $order;
        if ($driverUserId <= 0 || $orderId <= 0) {
            return;
        }

        $this->safelyBroadcast(
            new DriverOrderRemoved($driverUserId, $orderId, $reason),
            'DriverOrderRemoved',
            [
                'driver_user_id' => $driverUserId,
                'order_id' => $orderId,
                'reason' => $reason,
            ],
        );
    }

    /**
     * @return array<int, int>
     */
    private function availableDriverUserIds(?Order $order = null): array
    {
        return $this->candidateSelector
            ->availableDriverQuery($order)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($userId): int => (int) $userId)
            ->filter(fn (int $userId): bool => $userId > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function safelyBroadcast(object $event, string $eventName, array $context): void
    {
        $startedAt = microtime(true);

        try {
            broadcast($event);

            Log::debug("Broadcast {$eventName} terkirim.", [
                ...$context,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $exception) {
            Log::warning("Broadcast {$eventName} gagal.", [
                ...$context,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
