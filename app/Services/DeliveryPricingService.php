<?php

namespace App\Services;

class DeliveryPricingService
{
    private const FLAT_DISTANCE_KM = 1.5;
    private const ROUND_UP_FRACTION = 0.7;

    /**
     * @return array<string, float|int|array<int, array<string, float|int|string>>>
     */
    public function calculateFromDistanceMeters(float $distanceMeters): array
    {
        $sanitizedDistanceMeters = max(0.0, $distanceMeters);
        $distanceMetersInt = (int) round($sanitizedDistanceMeters);
        $distanceKm = round($distanceMetersInt / 1000, 2);

        $baseFee = (float) config('bangdeliv.base_delivery_fee', 5000);
        $ratePerKm = $this->rateForDistanceKm($distanceMetersInt / 1000);

        $billedKm = $distanceKm <= self::FLAT_DISTANCE_KM
            ? 0
            : $this->billableKilometers($distanceMetersInt / 1000);

        $distanceFee = $billedKm * $ratePerKm;
        $totalFee = $baseFee + $distanceFee;

        return [
            'distance_meters' => $distanceMetersInt,
            'distance_km' => $distanceKm,
            'billed_km' => $billedKm,
            'base_fee' => round($baseFee, 2),
            'rate_per_km' => round($ratePerKm, 2),
            'distance_fee' => round($distanceFee, 2),
            'total_fee' => round($totalFee, 2),
            'max_distance_km' => $this->getMaxDistanceKm(),
            'fee_breakdown' => [
                [
                    'code' => 'base_fee',
                    'label' => 'Tarif dasar',
                    'amount' => round($baseFee, 2),
                ],
                [
                    'code' => 'distance_fee',
                    'label' => 'Ongkir jarak',
                    'amount' => round($distanceFee, 2),
                    'billed_km' => $billedKm,
                    'rate_per_km' => round($ratePerKm, 2),
                ],
            ],
        ];
    }

    public function getMaxDistanceKm(): float
    {
        return (float) config('bangdeliv.max_delivery_distance', 50);
    }

    public function getMaxDistanceMeters(): float
    {
        return $this->getMaxDistanceKm() * 1000;
    }

    public function isWithinMaxDistance(float $distanceMeters): bool
    {
        return $distanceMeters <= $this->getMaxDistanceMeters();
    }

    private function billableKilometers(float $distanceKm): int
    {
        if ($distanceKm <= 0) {
            return 0;
        }

        $floor = (int) floor($distanceKm);
        $fraction = $distanceKm - $floor;

        return $fraction >= self::ROUND_UP_FRACTION
            ? (int) ceil($distanceKm)
            : $floor;
    }

    private function rateForDistanceKm(float $distanceKm): float
    {
        if ($distanceKm >= 25) {
            return (float) config('bangdeliv.delivery_rate_25_50_per_km', 3000);
        }

        if ($distanceKm >= 10) {
            return (float) config('bangdeliv.delivery_rate_10_25_per_km', 2500);
        }

        return (float) config('bangdeliv.delivery_rate_0_10_per_km', config('bangdeliv.delivery_rate_per_km', 2000));
    }
}
