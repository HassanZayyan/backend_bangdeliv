<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function checkout(User $user, array $payload): Order
    {
        $cart = $this->cartService->getActiveCart($user);

        if (!$cart || $cart->items->isEmpty()) {
            throw new ApiException('Cart kosong. Tambahkan menu terlebih dahulu.', 409);
        }

        $address = Address::query()
            ->where('id', $payload['address_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$address) {
            throw new ApiException('Alamat tidak ditemukan.', 404);
        }

        $restaurant = Restaurant::query()->find($cart->restaurant_id);
        if (!$restaurant || $restaurant->status !== 'active') {
            throw new ApiException('Restoran tidak aktif.', 409);
        }

        foreach ($cart->items as $item) {
            if (!$item->menu || !$item->menu->is_available) {
                throw new ApiException('Ada menu yang tidak tersedia. Perbarui cart dan coba lagi.', 409);
            }
        }

        $subtotal = $cart->items->sum(fn ($item) => (float) $item->menu->price * $item->quantity);

        $deliveryFee = $this->calculateDeliveryFee();
        $totalAmount = $subtotal + $deliveryFee;

        return DB::transaction(function () use ($user, $payload, $cart, $address, $restaurant, $subtotal, $deliveryFee, $totalAmount): Order {
            $order = Order::query()->create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'restaurant_id' => $restaurant->id,
                'address_id' => $address->id,
                'delivery_address' => trim($address->full_address.' '.($address->detail ?? '')),
                'delivery_latitude' => $address->latitude,
                'delivery_longitude' => $address->longitude,
                'subtotal' => round($subtotal, 2),
                'delivery_fee' => round($deliveryFee, 2),
                'total_amount' => round($totalAmount, 2),
                'status' => 'confirmed',
                'payment_status' => 'unpaid',
                'notes' => $payload['notes'] ?? null,
                'estimated_delivery' => Carbon::now()->addMinutes((int) $restaurant->estimated_prep_time + 25),
            ]);

            foreach ($cart->items as $item) {
                $unitPrice = (float) $item->menu->price;
                $qty = (int) $item->quantity;

                $order->items()->create([
                    'menu_id' => $item->menu_id,
                    'menu_name' => $item->menu->name,
                    'quantity' => $qty,
                    'unit_price' => round($unitPrice, 2),
                    'subtotal' => round($unitPrice * $qty, 2),
                    'notes' => $item->notes,
                    'is_available' => true,
                ]);
            }

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status' => 'confirmed',
                'changed_by_user_id' => $user->id,
                'note' => 'Order dibuat oleh customer melalui checkout.',
            ]);

            $cart->items()->delete();
            $cart->touch();

            return $order->fresh(['restaurant', 'address', 'items', 'statusHistories']);
        });
    }

    private function generateOrderNumber(): string
    {
        do {
            $candidate = 'BD-'.now()->format('ymd').'-'.random_int(1000, 9999);
        } while (Order::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }

    private function calculateDeliveryFee(): float
    {
        // Fase 1: gunakan minimum fee sebagai baseline. Distance matrix dapat ditambahkan di fase berikutnya.
        return (float) config('bangdeliv.min_delivery_fee', 5000);
    }
}
