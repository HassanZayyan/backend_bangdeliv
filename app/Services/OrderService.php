<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrderService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateCustomerOrders(User $user, array $filters): LengthAwarePaginator
    {
        $perPage = isset($filters['per_page']) ? min((int) $filters['per_page'], 50) : 10;

        $query = Order::query()
            ->where('user_id', $user->id)
            ->with(['restaurant', 'items'])
            ->latest('id');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    public function customerOrderDetail(User $user, int $orderId): Order
    {
        $order = Order::query()
            ->with(['restaurant', 'driver.user', 'address', 'items', 'statusHistories'])
            ->find($orderId);

        if (!$order || $order->user_id !== $user->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        return $order;
    }

    public function cancelByCustomer(User $user, int $orderId, string $reason): Order
    {
        $order = Order::query()->find($orderId);

        if (!$order || $order->user_id !== $user->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        if (!in_array($order->status, ['confirmed', 'driver_assigned'], true)) {
            throw new ApiException('Order tidak bisa dibatalkan pada status saat ini.', 409);
        }

        $order->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_by' => 'customer',
        ]);

        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'status' => 'cancelled',
            'changed_by_user_id' => $user->id,
            'note' => $reason,
        ]);

        return $order->fresh(['restaurant', 'items', 'statusHistories']);
    }
}
