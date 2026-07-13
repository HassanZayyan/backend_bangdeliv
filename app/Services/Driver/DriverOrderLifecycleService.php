<?php

namespace App\Services\Driver;

use App\Models\Driver;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

class DriverOrderLifecycleService
{
    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function constrainRunningOrders(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereHas('statusRef', function (Builder $statusQuery): void {
                    $statusQuery->whereIn('code', [
                        'DRIVER_ASSIGNED',
                        'ARRIVED_MERCHANT',
                        'ARRIVED_PICKUP',
                        'PICKED_UP',
                        'ON_THE_WAY',
                        'ARRIVED_DROPOFF',
                        'DELIVERED',
                    ]);
                })
                ->orWhere(function (Builder $cancelledWithFeeQuery): void {
                    $cancelledWithFeeQuery
                        ->whereHas('statusRef', fn (Builder $statusQuery) => $statusQuery->where('code', 'CANCELLED_WITH_FEE'))
                        ->whereDoesntHave('payments', fn (Builder $paymentQuery) => $paymentQuery->where('payment_status', 'PAID'));
                });
        });
    }

    public function hasRunningOrder(int $driverId): bool
    {
        $query = Order::query()->where('driver_id', $driverId);

        return $this->constrainRunningOrders($query)->exists();
    }

    public function syncAvailabilityAfterNonRunningOrder(int $driverId): void
    {
        $driver = Driver::query()->find($driverId);
        if (! $driver || (string) $driver->status !== 'busy') {
            return;
        }

        if ($this->hasRunningOrder($driverId)) {
            return;
        }

        $driver->update([
            'status' => 'available',
        ]);
    }
}
