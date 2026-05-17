<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderContentUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $pricing
     */
    public function __construct(
        public int $orderId,
        public string $changeType,
        public array $pricing,
        public string $updatedAt,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order.tracking.'.$this->orderId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.content.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'change_type' => $this->changeType,
            'pricing' => $this->pricing,
            'updated_at' => $this->updatedAt,
        ];
    }
}
