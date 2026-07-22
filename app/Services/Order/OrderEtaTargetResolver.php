<?php

namespace App\Services\Order;

use App\Enums\OrderStatusCode;
use App\Enums\ServiceTypeCode;
use App\Models\Order;
use App\Models\OrderLocation;
use App\Services\Driver\Dispatch\OrderPickupPointResolver;

class OrderEtaTargetResolver
{
    public function __construct(
        private readonly OrderPickupPointResolver $pickupPointResolver
    ) {}

    /**
     * @return array{target: string, target_label: string, latitude: float, longitude: float}|null
     */
    public function resolve(Order $order): ?array
    {
        $serviceCode = ServiceTypeCode::normalize((string) ($order->serviceType->code ?? ''));
        $statusCode = OrderStatusCode::normalize((string) ($order->statusRef->code ?? ''));

        if (
            $statusCode === OrderStatusCode::DriverAssigned->value &&
            in_array($serviceCode, [ServiceTypeCode::Ride->value, ServiceTypeCode::Courier->value], true)
        ) {
            return $this->pickupTarget($order);
        }

        if (
            $serviceCode === ServiceTypeCode::Shopping->value &&
            $statusCode === OrderStatusCode::OnTheWay->value
        ) {
            return $this->dropoffTarget($order);
        }

        return null;
    }

    /**
     * @return array{target: string, target_label: string, latitude: float, longitude: float}|null
     */
    private function pickupTarget(Order $order): ?array
    {
        $pickup = $this->pickupPointResolver->resolve($order);

        return $this->target(
            target: 'PICKUP',
            targetLabel: 'Titik jemput',
            latitude: $pickup['latitude'] ?? null,
            longitude: $pickup['longitude'] ?? null,
        );
    }

    /**
     * @return array{target: string, target_label: string, latitude: float, longitude: float}|null
     */
    private function dropoffTarget(Order $order): ?array
    {
        $dropoff = $order->orderLocations
            ->first(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'DROPOFF');

        return $this->target(
            target: 'DROPOFF',
            targetLabel: 'Alamat customer',
            latitude: $dropoff?->latitude ?? $order->delivery_latitude,
            longitude: $dropoff?->longitude ?? $order->delivery_longitude,
        );
    }

    /**
     * @return array{target: string, target_label: string, latitude: float, longitude: float}|null
     */
    private function target(
        string $target,
        string $targetLabel,
        mixed $latitude,
        mixed $longitude,
    ): ?array {
        $latitude = $this->coordinateOrNull($latitude, min: -90, max: 90);
        $longitude = $this->coordinateOrNull($longitude, min: -180, max: 180);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'target' => $target,
            'target_label' => $targetLabel,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    public function coordinateOrNull(mixed $value, float $min, float $max): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $coordinate = (float) $value;
        if ($coordinate < $min || $coordinate > $max) {
            return null;
        }

        return $coordinate;
    }
}
