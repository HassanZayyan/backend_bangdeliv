<?php

namespace App\Services;

use App\Events\DriverOrderAvailable;
use App\Events\DriverOrderRemoved;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\Log;

class DriverOrderRealtimeService
{
    public function __construct(private readonly DriverOrderPayloadFactory $payloadFactory) {}

    public function broadcastOrderAvailable(Order $order): void
    {
        $freshOrder = $order->fresh($this->payloadFactory->relations());
        if (! $freshOrder) {
            return;
        }

        $payload = $this->payloadFactory->serialize($freshOrder);

        foreach ($this->availableDriverUserIds($freshOrder) as $driverUserId) {
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
        $rejectedDriverUserIds = $order === null
            ? []
            : OrderStatusHistory::query()
                ->where('order_id', $order->id)
                ->where('event_type', 'DRIVER_REJECT')
                ->whereNotNull('changed_by_user_id')
                ->pluck('changed_by_user_id')
                ->map(fn ($userId): int => (int) $userId)
                ->filter(fn (int $userId): bool => $userId > 0)
                ->values()
                ->all();

        return Driver::query()
            ->where('registration_status', 'active')
            ->where('status', 'available')
            ->when($rejectedDriverUserIds !== [], function ($query) use ($rejectedDriverUserIds): void {
                $query->whereNotIn('user_id', $rejectedDriverUserIds);
            })
            ->whereHas('user', function ($query): void {
                $query
                    ->where('role', 'driver')
                    ->where('is_active', true)
                    ->where('is_blacklisted', false);
            })
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
