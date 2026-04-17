<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\RideOrder;
use App\Models\ServiceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RideOrderService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $user, array $payload): Order
    {
        $pickupAddress = Address::query()
            ->where('id', $payload['address_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$pickupAddress) {
            throw new ApiException('Alamat jemput tidak ditemukan.', 404);
        }

        $rideServiceTypeId = ServiceType::query()->where('code', 'RIDE')->value('id');
        $pendingStatusId = OrderStatus::query()->where('code', 'PENDING')->value('id');

        if (!$rideServiceTypeId || !$pendingStatusId) {
            throw new ApiException('Konfigurasi service type atau status order belum lengkap.', 500);
        }

        $destinationAddress = trim((string) $payload['destination_address']);
        $destinationLatitude = isset($payload['destination_latitude'])
            ? (float) $payload['destination_latitude']
            : 0.0;
        $destinationLongitude = isset($payload['destination_longitude'])
            ? (float) $payload['destination_longitude']
            : 0.0;

        $subtotal = 0.0;
        $deliveryFee = $this->calculateRideFee();
        $serviceFee = 0.0;
        $totalAmount = $subtotal + $deliveryFee + $serviceFee;

        return DB::transaction(function () use (
            $user,
            $pickupAddress,
            $rideServiceTypeId,
            $pendingStatusId,
            $destinationAddress,
            $destinationLatitude,
            $destinationLongitude,
            $subtotal,
            $deliveryFee,
            $serviceFee,
            $totalAmount,
            $payload
        ): Order {
            $order = Order::query()->create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'restaurant_id' => null,
                'service_type_id' => $rideServiceTypeId,
                'address_id' => $pickupAddress->id,
                'delivery_address' => $destinationAddress,
                'delivery_latitude' => round($destinationLatitude, 8),
                'delivery_longitude' => round($destinationLongitude, 8),
                'subtotal' => round($subtotal, 2),
                'delivery_fee' => round($deliveryFee, 2),
                'service_fee' => round($serviceFee, 2),
                'total_amount' => round($totalAmount, 2),
                'total_price' => round($totalAmount, 2),
                'status_id' => $pendingStatusId,
                'payment_status' => 'unpaid',
                'payment_method' => 'COD',
                'notes' => $payload['notes'] ?? null,
                'estimated_delivery' => Carbon::now()->addMinutes(30),
            ]);

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $pendingStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $user->id,
                'note' => 'Order Antar Jemput dibuat oleh customer.',
            ]);

            RideOrder::query()->create([
                'order_id' => $order->id,
                'notes' => $payload['notes'] ?? null,
            ]);

            return $order->fresh([
                'address',
                'statusRef',
                'statusHistories.statusRef',
                'rideOrder',
                'serviceType',
            ]);
        });
    }

    private function generateOrderNumber(): string
    {
        do {
            $candidate = 'BDR-'.now()->format('ymd').'-'.random_int(1000, 9999);
        } while (Order::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }

    private function calculateRideFee(): float
    {
        return (float) config('bangdeliv.min_delivery_fee', 5000);
    }
}
