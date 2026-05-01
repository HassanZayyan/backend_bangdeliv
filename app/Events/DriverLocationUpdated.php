<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $orderId;
    public $latitude;
    public $longitude;
    public $heading;

    /**
     * Create a new event instance.
     */
    public function __construct($orderId, $latitude, $longitude, $heading)
    {
        $this->orderId = $orderId;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->heading = $heading;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order.tracking.' . $this->orderId),
        ];
    }

    /**
     * Data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'heading' => $this->heading,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
