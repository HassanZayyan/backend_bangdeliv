<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;

final class DriverOrderResolver
{
    public function resolveActiveDriverProfile(User $actor): Driver
    {
        if ($actor->role !== 'driver') {
            throw new ApiException('Akses hanya untuk driver.', 403);
        }

        $driver = Driver::query()->where('user_id', $actor->id)->first();
        if (! $driver) {
            throw new ApiException('Profil driver tidak ditemukan.', 403);
        }

        if ($driver->registration_status !== 'active') {
            throw new ApiException('Akun driver belum aktif.', 403);
        }

        return $driver;
    }

    /**
     * @param  array<int, string>  $relations
     */
    public function lockedAssignedDriverOrder(int $orderId, int $driverId, array $relations = []): Order
    {
        $order = Order::query()
            ->with(array_values(array_unique($relations)))
            ->lockForUpdate()
            ->find($orderId);

        if (! $order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        if ((int) ($order->driver_id ?? 0) !== $driverId) {
            throw new ApiException('Order ini tidak ditugaskan kepada driver saat ini.', 403);
        }

        return $order;
    }
}
