<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

class GoogleMapsGeocodingService
{
    /**
     * @return array{latitude: float, longitude: float, formatted_address: string}|null
     */
    public function resolveAddress(string $address): ?array
    {
        $apiKey = (string) config('bangdeliv.google_maps_api_key');

        if ($apiKey === '') {
            throw new ApiException('Konfigurasi API Google Maps belum tersedia.', 500);
        }

        $response = Http::timeout((int) config('bangdeliv.geocoding.timeout_seconds', 8))
            ->acceptJson()
            ->get((string) config('bangdeliv.geocoding.endpoint', 'https://maps.googleapis.com/maps/api/geocode/json'), [
                'address' => $address,
                'language' => (string) config('bangdeliv.geocoding.language', 'id'),
                'region' => (string) config('bangdeliv.geocoding.region', 'id'),
                'key' => $apiKey,
            ]);

        if (!$response->successful()) {
            throw new ApiException('Layanan validasi alamat sedang tidak tersedia.', 503);
        }

        $payload = $response->json();
        $status = (string) ($payload['status'] ?? 'UNKNOWN_ERROR');

        if ($status === 'ZERO_RESULTS') {
            return null;
        }

        if ($status !== 'OK') {
            throw new ApiException('Gagal memvalidasi alamat tujuan. Coba beberapa saat lagi.', 503);
        }

        $firstResult = $payload['results'][0] ?? null;
        $location = $firstResult['geometry']['location'] ?? null;

        if (!is_array($location) || !isset($location['lat'], $location['lng'])) {
            throw new ApiException('Respons geocoding tidak lengkap.', 503);
        }

        return [
            'latitude' => (float) $location['lat'],
            'longitude' => (float) $location['lng'],
            'formatted_address' => (string) ($firstResult['formatted_address'] ?? $address),
        ];
    }
}
