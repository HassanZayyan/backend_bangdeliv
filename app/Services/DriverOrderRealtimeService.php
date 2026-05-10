<?php

namespace App\Services;

use App\Events\DriverOrderAvailable;
use App\Events\DriverOrderRemoved;
use App\Models\Driver;
use App\Models\Order;
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

        foreach ($this->availableDriverUserIds() as $driverUserId) {
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

    /**
     * @return array<int, int>
     */
    private function availableDriverUserIds(): array
    {
        return Driver::query()
            ->where('registration_status', 'active')
            ->where('status', 'available')
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
        try {
            broadcast($event);
        } catch (\Throwable $exception) {
            Log::warning("Broadcast {$eventName} gagal.", [
                ...$context,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
