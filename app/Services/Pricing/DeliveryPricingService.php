<?php

namespace App\Services\Pricing;

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
        $ratePerKm = $this->rateForDistanceMeters($distanceMetersInt);

        $billedKm = $distanceKm <= self::FLAT_DISTANCE_KM
            ? 0
            : $this->billableKilometers($distanceMetersInt);

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

    private function billableKilometers(int $distanceMeters): int
    {
        if ($distanceMeters <= 0) {
            return 0;
        }

        $floor = intdiv($distanceMeters, 1000);
        $sisaMeter = $distanceMeters % 1000;
        $ambangMeter = (int) round(self::ROUND_UP_FRACTION * 1000);

        // Setara dengan ceil() bila pecahan >= ROUND_UP_FRACTION dan floor()
        // bila sebaliknya. Perbandingan dilakukan pada bilangan bulat meter,
        // bukan pada pecahan kilometer bertipe float, karena nilai seperti
        // 12,7 km tidak dapat direpresentasikan persis oleh IEEE-754:
        // 12.7 - 12 menghasilkan 0,6999999999999993 sehingga perbandingan
        // terhadap 0,7 gagal dan jarak justru dibulatkan ke bawah.
        return $sisaMeter >= $ambangMeter
            ? $floor + 1
            : $floor;
    }

    private function rateForDistanceMeters(int $distanceMeters): float
    {
        if ($distanceMeters >= 25_000) {
            return (float) config('bangdeliv.delivery_rate_25_50_per_km', 3000);
        }

        if ($distanceMeters >= 10_000) {
            return (float) config('bangdeliv.delivery_rate_10_25_per_km', 2500);
        }

        return (float) config('bangdeliv.delivery_rate_0_10_per_km', config('bangdeliv.delivery_rate_per_km', 2000));
    }
}
