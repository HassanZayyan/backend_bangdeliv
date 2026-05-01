<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $orderId,
        public string $statusCode,
        public ?string $previousStatusCode,
        public ?int $historyId,
        public string $changedAt,
        public ?int $changedAtMs,
    ) {
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order.tracking.'.$this->orderId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'status_code' => $this->statusCode,
            'previous_status_code' => $this->previousStatusCode,
            'history_id' => $this->historyId,
            'changed_at' => $this->changedAt,
            'changed_at_ms' => $this->changedAtMs,
        ];
    }
}