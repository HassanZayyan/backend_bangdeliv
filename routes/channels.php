<?php

use App\Models\Driver;
use App\Models\Order;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

$logBroadcastAuth = static function (string $channel, $user, array $context): void {
    if (! config('app.debug')) {
        return;
    }

    Log::debug('Broadcast auth '.$channel, [
        'user_id' => (int) ($user->id ?? 0),
        'user_role' => (string) ($user->role ?? ''),
        ...$context,
    ]);
};

Broadcast::channel('App.Models.User.{id}', function ($user, $id) use ($logBroadcastAuth) {
    $allowed = (int) $user->id === (int) $id;
    $logBroadcastAuth('App.Models.User', $user, [
        'channel_user_id' => (int) $id,
        'allowed' => $allowed,
    ]);

    return $allowed;
}, ['guards' => ['sanctum']]);

Broadcast::channel('order.tracking.{orderId}', function ($user, $orderId) use ($logBroadcastAuth) {
    $order = Order::with('driver')->find($orderId);
    if (! $order) {
        $logBroadcastAuth('order.tracking', $user, [
            'order_id' => (int) $orderId,
            'allowed' => false,
            'reason' => 'order_not_found',
        ]);

        return false;
    }

    $assignedDriverUserId = (int) ($order->driver?->user_id ?? 0);
    $allowed = (int) $user->id === (int) $order->user_id ||
        ($assignedDriverUserId > 0 && (int) $user->id === $assignedDriverUserId);

    $logBroadcastAuth('order.tracking', $user, [
        'order_id' => (int) $order->id,
        'order_user_id' => (int) $order->user_id,
        'assigned_driver_user_id' => $assignedDriverUserId,
        'allowed' => $allowed,
    ]);

    return $allowed;
}, ['guards' => ['sanctum']]);

Broadcast::channel('driver.orders.user.{userId}', function ($user, $userId) use ($logBroadcastAuth) {
    $driver = Driver::query()
        ->where('user_id', $user->id)
        ->first();

    $allowed = (int) $user->id === (int) $userId &&
        $user->role === 'driver' &&
        $driver?->registration_status === 'active';

    $logBroadcastAuth('driver.orders.user', $user, [
        'channel_user_id' => (int) $userId,
        'driver_registration_status' => $driver?->registration_status,
        'driver_status' => $driver?->status,
        'allowed' => $allowed,
    ]);

    if ((int) $user->id !== (int) $userId || $user->role !== 'driver') {
        return false;
    }

    return $allowed;
}, ['guards' => ['sanctum']]);
