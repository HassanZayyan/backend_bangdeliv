<?php

namespace App\Services\Driver\Dispatch;

use App\Enums\ServiceTypeCode;
use App\Models\Order;

class OrderPickupPointResolver
{
    private const CUSTOMER_PICKUP_ROLE = 'customer_pickup';

    private const CUSTOMER_PICKUP_LABEL = 'customer';

    /**
     * @return array{address: string, latitude: float|null, longitude: float|null, target_role: string, target_label: string}
     */
    public function resolve(Order $order): array
    {
        $serviceCode = ServiceTypeCode::normalize((string) ($order->serviceType->code ?? ''));

        if ($serviceCode === ServiceTypeCode::Shopping->value) {
            $dropoff = $order->orderLocations
                ->filter(fn ($location): bool => strtoupper((string) $location->location_role) === 'DROPOFF')
                ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
                ->first();

            return [
                'address' => $dropoff?->full_address ?? $this->orderAttribute($order, 'delivery_address') ?? '-',
                'latitude' => $this->toFloatOrNull($dropoff?->latitude ?? $this->orderAttribute($order, 'delivery_latitude')),
                'longitude' => $this->toFloatOrNull($dropoff?->longitude ?? $this->orderAttribute($order, 'delivery_longitude')),
                'target_role' => self::CUSTOMER_PICKUP_ROLE,
                'target_label' => self::CUSTOMER_PICKUP_LABEL,
            ];
        }

        $pickup = $order->orderLocations
            ->filter(fn ($location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
            ->first();

        return [
            'address' => $pickup?->full_address ?? '-',
            'latitude' => $this->toFloatOrNull($pickup?->latitude),
            'longitude' => $this->toFloatOrNull($pickup?->longitude),
            'target_role' => self::CUSTOMER_PICKUP_ROLE,
            'target_label' => self::CUSTOMER_PICKUP_LABEL,
        ];
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function orderAttribute(Order $order, string $key): mixed
    {
        return $order->getRawOriginal($key) ?? $order->getAttribute($key);
    }
}
