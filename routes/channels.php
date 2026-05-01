<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Order;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('order.tracking.{orderId}', function ($user, $orderId) {
    $order = Order::with('driver')->find($orderId);
    if (!$order) {
        return false;
    }
    // Allow if user is the customer who placed the order or the driver assigned to it
    return (int) $user->id === (int) $order->user_id || 
           ((int) $order->driver_id > 0 && (int) $user->id === (int) $order->driver?->user_id);
});
