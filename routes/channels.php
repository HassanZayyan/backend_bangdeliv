<?php

use App\Models\Driver;
use App\Models\Order;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('order.tracking.{orderId}', function ($user, $orderId) {
    $order = Order::with('driver')->find($orderId);
    if (! $order) {
        return false;
    }

    // Allow if user is the customer who placed the order or the driver assigned to it
    return (int) $user->id === (int) $order->user_id ||
           ((int) $order->driver_id > 0 && (int) $user->id === (int) $order->driver?->user_id);
});

Broadcast::channel('driver.orders.user.{userId}', function ($user, $userId) {
    if ((int) $user->id !== (int) $userId || $user->role !== 'driver') {
        return false;
    }

    return Driver::query()
        ->where('user_id', $user->id)
        ->where('registration_status', 'active')
        ->exists();
});
