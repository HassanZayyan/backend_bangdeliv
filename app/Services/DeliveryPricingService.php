<?php

namespace App\Services;

class DeliveryPricingService
{
    /**
     * @return array<string, float|int>
     */
    public function calculateFromDistanceMeters(float $distanceMeters): array
    {
        $sanitizedDistanceMeters = max(0.0, $distanceMeters);
        $distanceMetersInt = (int) round($sanitizedDistanceMeters);

        $baseFee = (float) config('bangdeliv.base_delivery_fee', 5000);
        $ratePerKm = (float) config('bangdeliv.delivery_rate_per_km', 2000);

        $billedKm = $distanceMetersInt <= 0
            ? 0
            : (int) ceil($distanceMetersInt / 1000);

        $distanceFee = $billedKm * $ratePerKm;
        $totalFee = $baseFee + $distanceFee;

        return [
            'distance_meters' => $distanceMetersInt,
            'distance_km' => round($distanceMetersInt / 1000, 2),
            'billed_km' => $billedKm,
            'base_fee' => round($baseFee, 2),
            'rate_per_km' => round($ratePerKm, 2),
            'distance_fee' => round($distanceFee, 2),
            'total_fee' => round($totalFee, 2),
        ];
    }

    public function getMaxDistanceKm(): float
    {
        return (float) config('bangdeliv.max_delivery_distance', 15);
    }

    public function getMaxDistanceMeters(): float
    {
        return $this->getMaxDistanceKm() * 1000;
    }

    public function isWithinMaxDistance(float $distanceMeters): bool
    {
        return $distanceMeters <= $this->getMaxDistanceMeters();
    }
}
