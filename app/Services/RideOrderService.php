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
    public function __construct(
        private readonly GoogleMapsGeocodingService $geocodingService,
        private readonly GoogleMapsDistanceMatrixService $distanceMatrixService,
        private readonly DeliveryPricingService $deliveryPricingService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly DriverOrderRealtimeService $driverOrderRealtimeService
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $user, array $payload): Order
    {
        $pickupAddress = null;
        $pickupAddressIdRaw = $payload['address_id'] ?? null;
        if ($pickupAddressIdRaw !== null && $pickupAddressIdRaw !== '') {
            $pickupAddress = Address::query()
                ->where('id', (int) $pickupAddressIdRaw)
                ->where('user_id', $user->id)
                ->first();

            if (! $pickupAddress) {
                throw new ApiException('Alamat jemput tidak ditemukan.', 404);
            }
        }

        $rideServiceTypeId = ServiceType::query()->where('code', 'RIDE')->value('id');
        $pendingStatusId = OrderStatus::query()->where('code', 'PENDING')->value('id');

        if (! $rideServiceTypeId || ! $pendingStatusId) {
            throw new ApiException('Konfigurasi service type atau status order belum lengkap.', 500);
        }

        $resolvedDestination = $this->resolveDestinationForCreate($payload);
        $destinationLatitude = $resolvedDestination['latitude'];
        $destinationLongitude = $resolvedDestination['longitude'];
        $normalizedDestinationAddress = trim((string) $resolvedDestination['formatted_address']);

        $pickupLatitude = $pickupAddress !== null && isset($pickupAddress->latitude)
            ? (float) $pickupAddress->latitude
            : null;
        $pickupLongitude = $pickupAddress !== null && isset($pickupAddress->longitude)
            ? (float) $pickupAddress->longitude
            : null;

        if ($pickupLatitude === null || $pickupLongitude === null) {
            $pickupLatitude = array_key_exists('pickup_latitude', $payload)
                && $payload['pickup_latitude'] !== null
                && $payload['pickup_latitude'] !== ''
                ? (float) $payload['pickup_latitude']
                : null;
            $pickupLongitude = array_key_exists('pickup_longitude', $payload)
                && $payload['pickup_longitude'] !== null
                && $payload['pickup_longitude'] !== ''
                ? (float) $payload['pickup_longitude']
                : null;
        }

        if (($pickupLatitude === null) xor ($pickupLongitude === null)) {
            throw new ApiException('Koordinat jemput tidak lengkap.', 422, [
                'pickup_latitude' => ['Latitude dan longitude jemput wajib diisi berpasangan.'],
                'pickup_longitude' => ['Latitude dan longitude jemput wajib diisi berpasangan.'],
            ]);
        }

        if ($pickupLatitude !== null && $pickupLongitude !== null) {
            if ($pickupLatitude < -90 || $pickupLatitude > 90 || $pickupLongitude < -180 || $pickupLongitude > 180) {
                throw new ApiException('Koordinat jemput tidak valid.', 422, [
                    'pickup_latitude' => ['Latitude atau longitude jemput di luar rentang yang diizinkan.'],
                    'pickup_longitude' => ['Latitude atau longitude jemput di luar rentang yang diizinkan.'],
                ]);
            }
        }

        if ($pickupLatitude === null || $pickupLongitude === null) {
            throw new ApiException('Koordinat alamat jemput belum tersedia. Perbarui Alamat Saya terlebih dahulu.', 422);
        }

        $pickupAddressText = $pickupAddress !== null
            ? trim((string) $pickupAddress->full_address)
            : trim((string) ($payload['pickup_address'] ?? ''));
        if ($pickupAddressText === '') {
            $pickupAddressText = sprintf('Pin %.6f, %.6f', $pickupLatitude, $pickupLongitude);
        }

        $route = $this->distanceMatrixService->resolveRoute(
            $pickupLatitude,
            $pickupLongitude,
            $destinationLatitude,
            $destinationLongitude,
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
        $estimatedMinutes = $this->estimateTravelMinutes((int) $route['duration_seconds']);
        $paymentMethod = $this->orderPaymentService->normalizePaymentMethod(
            isset($payload['payment_method']) ? (string) $payload['payment_method'] : null
        );

        $subtotal = 0.0;
        $deliveryFee = (float) $pricing['total_fee'];
        $serviceFee = 0.0;
        $totalAmount = $subtotal + $deliveryFee + $serviceFee;
        $routeSnapshot = [
            ...$route,
            'delivery_fee' => round($deliveryFee, 2),
            'delivery_pricing' => $pricing,
        ];

        $order = DB::transaction(function () use (
            $user,
            $pickupAddress,
            $rideServiceTypeId,
            $pendingStatusId,
            $normalizedDestinationAddress,
            $destinationLatitude,
            $destinationLongitude,
            $distanceKm,
            $route,
            $subtotal,
            $deliveryFee,
            $serviceFee,
            $totalAmount,
            $routeSnapshot,
            $estimatedMinutes,
            $pickupAddressText,
            $pickupLatitude,
            $pickupLongitude,
            $paymentMethod
        ): Order {
            $order = Order::query()->create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'restaurant_id' => null,
                'service_type_id' => $rideServiceTypeId,
                'subtotal' => round($subtotal, 2),
                'delivery_fee' => round($deliveryFee, 2),
                'service_fee' => round($serviceFee, 2),
                'delivery_distance_km' => round($distanceKm, 2),
                'delivery_distance_text' => (string) ($route['distance_text'] ?? number_format($distanceKm, 2).' km'),
                'route_snapshot' => $routeSnapshot,
                'total_price' => round($totalAmount, 2),
                'status_id' => $pendingStatusId,
                'estimated_delivery' => Carbon::now()->addMinutes($estimatedMinutes),
            ]);

            $this->orderPaymentService->ensurePendingPayment($order, $paymentMethod);

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $pendingStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $user->id,
                'note' => 'Order Antar Jemput dibuat oleh customer.',
            ]);

            RideOrder::query()->create([
                'order_id' => $order->id,
            ]);

            $order->orderLocations()->createMany([
                [
                    'location_role' => 'PICKUP',
                    'label' => 'Pickup',
                    'contact_name' => $pickupAddress?->recipient_name ?? $user->name,
                    'contact_phone' => $pickupAddress?->phone ?? $user->phone,
                    'full_address' => $pickupAddressText,
                    'latitude' => round($pickupLatitude, 8),
                    'longitude' => round($pickupLongitude, 8),
                    'sequence_no' => 1,
                ],
                [
                    'location_role' => 'DROPOFF',
                    'label' => 'Dropoff',
                    'contact_name' => null,
                    'contact_phone' => null,
                    'full_address' => $normalizedDestinationAddress,
                    'latitude' => round($destinationLatitude, 8),
                    'longitude' => round($destinationLongitude, 8),
                    'sequence_no' => 2,
                ],
            ]);

            return $order->fresh([
                'orderLocations',
                'statusRef',
                'statusHistories.statusRef',
                'rideOrder',
                'serviceType',
                'payments',
            ]);
        });

        $this->driverOrderRealtimeService->broadcastOrderAvailable($order);

        return $order;
    }

    /**
     * @return array{latitude: float, longitude: float, formatted_address: string}
     */
    public function validateDestination(string $destinationAddress): array
    {
        $normalizedAddress = trim($destinationAddress);

        if ($normalizedAddress === '') {
            throw new ApiException('Alamat tujuan wajib diisi.', 422, [
                'destination_address' => ['Alamat tujuan wajib diisi.'],
            ]);
        }

        $resolvedDestination = $this->geocodingService->resolvePlace($normalizedAddress);

        if ($resolvedDestination === null) {
            throw new ApiException('Alamat tujuan tidak valid atau tidak ditemukan di peta.', 422, [
                'destination_address' => ['Alamat tujuan tidak valid atau tidak ditemukan di peta.'],
            ]);
        }

        return $resolvedDestination;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{latitude: float, longitude: float, formatted_address: string}
     */
    private function resolveDestinationForCreate(array $payload): array
    {
        $destinationAddress = trim((string) ($payload['destination_address'] ?? ''));

        if ($destinationAddress === '') {
            throw new ApiException('Alamat tujuan wajib diisi.', 422, [
                'destination_address' => ['Alamat tujuan wajib diisi.'],
            ]);
        }

        $hasLatitude = array_key_exists('destination_latitude', $payload)
            && $payload['destination_latitude'] !== null
            && $payload['destination_latitude'] !== '';
        $hasLongitude = array_key_exists('destination_longitude', $payload)
            && $payload['destination_longitude'] !== null
            && $payload['destination_longitude'] !== '';

        if ($hasLatitude xor $hasLongitude) {
            throw new ApiException('Koordinat tujuan tidak lengkap.', 422, [
                'destination_latitude' => ['Latitude dan longitude tujuan wajib diisi berpasangan.'],
                'destination_longitude' => ['Latitude dan longitude tujuan wajib diisi berpasangan.'],
            ]);
        }

        if ($hasLatitude && $hasLongitude) {
            $latitude = (float) $payload['destination_latitude'];
            $longitude = (float) $payload['destination_longitude'];

            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                throw new ApiException('Koordinat tujuan tidak valid.', 422, [
                    'destination_latitude' => ['Latitude atau longitude tujuan di luar rentang yang diizinkan.'],
                    'destination_longitude' => ['Latitude atau longitude tujuan di luar rentang yang diizinkan.'],
                ]);
            }

            return [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'formatted_address' => $destinationAddress,
            ];
        }

        return $this->validateDestination($destinationAddress);
    }

    private function generateOrderNumber(): string
    {
        do {
            $candidate = 'BDR-'.now()->format('ymd').'-'.random_int(1000, 9999);
        } while (Order::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }

    private function estimateTravelMinutes(int $durationSeconds): int
    {
        $minutes = (int) ceil(max(0, $durationSeconds) / 60);

        return max(20, min(180, $minutes + 10));
    }
}
