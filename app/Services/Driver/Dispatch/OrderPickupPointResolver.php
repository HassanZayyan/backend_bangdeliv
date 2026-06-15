<?php

namespace App\Services\Driver\Dispatch;

use App\Enums\ServiceTypeCode;
use App\Models\Order;

class OrderPickupPointResolver
{
    /**
     * @return array{address: string, latitude: float|null, longitude: float|null}
     */
    public function resolve(Order $order): array
    {
        $serviceCode = ServiceTypeCode::normalize((string) ($order->serviceType->code ?? ''));

        if ($serviceCode === ServiceTypeCode::Shopping->value) {
            $pickup = $order->orderLocations
                ->filter(fn ($location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
                ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
                ->first();

            return [
                'address' => $pickup?->full_address ?? $order->restaurant?->address ?? '-',
                'latitude' => $this->toFloatOrNull($pickup?->latitude ?? $order->restaurant?->latitude),
                'longitude' => $this->toFloatOrNull($pickup?->longitude ?? $order->restaurant?->longitude),
            ];
        }

        $pickup = $order->orderLocations
            ->first(fn ($location): bool => strtoupper((string) $location->location_role) === 'PICKUP');

        return [
            'address' => $pickup?->full_address ?? '-',
            'latitude' => $this->toFloatOrNull($pickup?->latitude),
            'longitude' => $this->toFloatOrNull($pickup?->longitude),
        ];
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
