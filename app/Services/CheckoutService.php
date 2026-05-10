<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
use App\Models\ServiceType;
use App\Models\ShoppingOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly GoogleMapsDistanceMatrixService $distanceMatrixService,
        private readonly DeliveryPricingService $deliveryPricingService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly DriverOrderRealtimeService $driverOrderRealtimeService
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function checkout(User $user, array $payload): Order
    {
        $cart = $this->cartService->getActiveCart($user);

        if (! $cart || $cart->items->isEmpty()) {
            throw new ApiException('Cart kosong. Tambahkan menu terlebih dahulu.', 409);
        }

        $address = Address::query()
            ->where('id', $payload['address_id'])
            ->where('user_id', $user->id)
            ->first();

        if (! $address) {
            throw new ApiException('Alamat tidak ditemukan.', 404);
        }

        $restaurant = Restaurant::query()->find($cart->restaurant_id);
        if (! $restaurant || $restaurant->status !== 'active') {
            throw new ApiException('Restoran tidak aktif.', 409);
        }

        foreach ($cart->items as $item) {
            if (! $item->menu || ! $item->menu->is_available) {
                throw new ApiException('Ada menu yang tidak tersedia. Perbarui cart dan coba lagi.', 409);
            }
        }

        $restaurantLatitude = isset($restaurant->latitude) ? (float) $restaurant->latitude : null;
        $restaurantLongitude = isset($restaurant->longitude) ? (float) $restaurant->longitude : null;
        $deliveryLatitude = isset($address->latitude) ? (float) $address->latitude : null;
        $deliveryLongitude = isset($address->longitude) ? (float) $address->longitude : null;

        if (
            $restaurantLatitude === null ||
            $restaurantLongitude === null ||
            $deliveryLatitude === null ||
            $deliveryLongitude === null
        ) {
            throw new ApiException('Koordinat restoran atau alamat pengantaran belum lengkap.', 422);
        }

        $route = $this->distanceMatrixService->resolveRoute(
            $restaurantLatitude,
            $restaurantLongitude,
            $deliveryLatitude,
            $deliveryLongitude,
        );

        $distanceMeters = (float) $route['distance_meters'];
        $distanceKm = (float) $route['distance_km'];

        if (! $this->deliveryPricingService->isWithinMaxDistance($distanceMeters)) {
            throw new ApiException(sprintf(
                'Jarak %.2f km melebihi batas layanan %.2f km.',
                $distanceKm,
                $this->deliveryPricingService->getMaxDistanceKm()
            ), 422);
        }

        $pricing = $this->deliveryPricingService->calculateFromDistanceMeters($distanceMeters);
        $routeMinutes = $this->estimateTravelMinutes((int) $route['duration_seconds']);

        $subtotal = $cart->items->sum(fn ($item) => (float) $item->menu->price * $item->quantity);

        $deliveryFee = (float) $pricing['total_fee'];
        $serviceFee = 0.0;
        $totalAmount = $subtotal + $deliveryFee + $serviceFee;
        $shoppingServiceTypeId = ServiceType::query()->where('code', 'SHOPPING')->value('id');
        $pendingStatusId = OrderStatus::query()->where('code', 'PENDING')->value('id');

        if (! $shoppingServiceTypeId || ! $pendingStatusId) {
            throw new ApiException('Konfigurasi service type atau status order belum lengkap.', 500);
        }

        $order = DB::transaction(function () use (
            $user,
            $cart,
            $address,
            $restaurant,
            $subtotal,
            $deliveryFee,
            $serviceFee,
            $totalAmount,
            $shoppingServiceTypeId,
            $pendingStatusId,
            $distanceKm,
            $route,
            $routeMinutes,
        ): Order {
            $order = Order::query()->create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'restaurant_id' => $restaurant->id,
                'service_type_id' => $shoppingServiceTypeId,
                'subtotal' => round($subtotal, 2),
                'delivery_fee' => round($deliveryFee, 2),
                'service_fee' => round($serviceFee, 2),
                'delivery_distance_km' => round($distanceKm, 2),
                'delivery_distance_text' => (string) ($route['distance_text'] ?? number_format($distanceKm, 2).' km'),
                'total_price' => round($totalAmount, 2),
                'status_id' => $pendingStatusId,
                'estimated_delivery' => Carbon::now()->addMinutes((int) $restaurant->estimated_prep_time + $routeMinutes),
            ]);

            $this->orderPaymentService->ensurePendingCodPayment($order);

            foreach ($cart->items as $item) {
                $unitPrice = (float) $item->menu->price;
                $qty = (int) $item->quantity;

                $order->items()->create([
                    'menu_id' => $item->menu_id,
                    'item_source' => 'MENU_DB',
                    'menu_name' => $item->menu->name,
                    'quantity' => $qty,
                    'unit_price' => round($unitPrice, 2),
                    'subtotal' => round($unitPrice * $qty, 2),
                    'line_service_fee' => 0,
                    'line_total' => round($unitPrice * $qty, 2),
                    'notes' => $item->notes,
                    'is_available' => true,
                    'is_heavy' => false,
                ]);
            }

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $pendingStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $user->id,
                'note' => 'Order dibuat oleh customer melalui checkout.',
            ]);

            ShoppingOrder::query()->create([
                'order_id' => $order->id,
                'failed_attempt_count' => 0,
                'item_surcharge' => 0,
                'overweight_surcharge' => 0,
                'cancellation_penalty' => 0,
                'has_overweight_item' => false,
                'recalculation_version' => 0,
            ]);

            $order->orderLocations()->createMany([
                [
                    'location_role' => 'PICKUP',
                    'label' => 'Restaurant',
                    'contact_name' => $restaurant->name,
                    'contact_phone' => $restaurant->phone,
                    'full_address' => $restaurant->address,
                    'latitude' => $restaurant->latitude,
                    'longitude' => $restaurant->longitude,
                    'sequence_no' => 1,
                ],
                [
                    'location_role' => 'DROPOFF',
                    'label' => $address->label,
                    'contact_name' => $address->recipient_name,
                    'contact_phone' => $address->phone,
                    'full_address' => trim($address->full_address.' '.($address->detail ?? '')),
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                    'sequence_no' => 2,
                ],
            ]);

            $cart->items()->delete();
            $cart->touch();

            return $order->fresh(['restaurant', 'orderLocations', 'items', 'payments', 'statusRef', 'statusHistories', 'shoppingOrder']);
        });

        $this->driverOrderRealtimeService->broadcastOrderAvailable($order);

        return $order;
    }

    private function generateOrderNumber(): string
    {
        do {
            $candidate = 'BD-'.now()->format('ymd').'-'.random_int(1000, 9999);
        } while (Order::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }

    private function estimateTravelMinutes(int $durationSeconds): int
    {
        $minutes = (int) ceil(max(0, $durationSeconds) / 60);

        return max(10, min(120, $minutes));
    }
}
