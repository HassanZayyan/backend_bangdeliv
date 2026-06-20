<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __invoke(): View
    {
        $maxDeliveryDistanceKm = (float) config('bangdeliv.max_delivery_distance', 50);

        return view('admin.settings.index', [
            'deliveryPricingRows' => [
                [
                    'label' => 'Tarif dasar',
                    'value' => 'Rp '.number_format((int) config('bangdeliv.base_delivery_fee', 5000), 0, ',', '.'),
                    'note' => 'Biaya awal sebelum tarif jarak dihitung.',
                ],
                [
                    'label' => 'Rate 0-10 km',
                    'value' => 'Rp '.number_format((int) config('bangdeliv.delivery_rate_0_10_per_km', 2000), 0, ',', '.').'/km',
                    'note' => 'Dipakai untuk jarak pendek.',
                ],
                [
                    'label' => 'Rate 10-25 km',
                    'value' => 'Rp '.number_format((int) config('bangdeliv.delivery_rate_10_25_per_km', 2500), 0, ',', '.').'/km',
                    'note' => 'Dipakai untuk jarak menengah.',
                ],
                [
                    'label' => 'Rate 25-50 km',
                    'value' => 'Rp '.number_format((int) config('bangdeliv.delivery_rate_25_50_per_km', 3000), 0, ',', '.').'/km',
                    'note' => 'Dipakai untuk jarak jauh.',
                ],
                [
                    'label' => 'Jarak maksimum',
                    'value' => $this->formatDistanceKm($maxDeliveryDistanceKm).' km',
                    'note' => 'Order di luar batas ini ditolak oleh pricing service.',
                ],
            ],
        ]);
    }

    private function formatDistanceKm(float $distanceKm): string
    {
        return floor($distanceKm) === $distanceKm
            ? (string) (int) $distanceKm
            : rtrim(rtrim(number_format($distanceKm, 2, '.', ''), '0'), '.');
    }
}
