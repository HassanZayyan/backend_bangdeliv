<?php

namespace App\Services\Maps;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

class GooglePlaceDetailsService
{
    /** @return array{place_id: string, name: string, address: string, latitude: float, longitude: float, types: array<int, string>} */
    public function resolve(string $placeId): array
    {
        $placeId = trim($placeId);
        if ($placeId === '') {
            throw new ApiException('Google Place ID merchant wajib tersedia.', 422);
        }

        $apiKey = (string) config('bangdeliv.google_maps_api_key');
        if ($apiKey === '') {
            throw new ApiException('Konfigurasi API Google Maps belum tersedia.', 500);
        }

        $response = Http::timeout((int) config('bangdeliv.geocoding.timeout_seconds', 8))
            ->acceptJson()
            ->get('https://maps.googleapis.com/maps/api/place/details/json', [
                'place_id' => $placeId,
                'fields' => 'place_id,name,formatted_address,geometry,types',
                'language' => (string) config('bangdeliv.geocoding.language', 'id'),
                'region' => (string) config('bangdeliv.geocoding.region', 'id'),
                'key' => $apiKey,
            ]);

        if (! $response->successful()) {
            throw new ApiException('Verifikasi merchant Google sedang tidak tersedia.', 503);
        }

        $payload = $response->json();
        if (($payload['status'] ?? null) !== 'OK' || ! is_array($payload['result'] ?? null)) {
            throw new ApiException('Merchant Google tidak valid atau sudah tidak tersedia.', 422);
        }

        $result = $payload['result'];
        $latitude = data_get($result, 'geometry.location.lat');
        $longitude = data_get($result, 'geometry.location.lng');
        $name = trim((string) ($result['name'] ?? ''));
        $address = trim((string) ($result['formatted_address'] ?? ''));
        if (! is_numeric($latitude) || ! is_numeric($longitude) || $name === '' || $address === '') {
            throw new ApiException('Detail merchant Google tidak lengkap.', 422);
        }

        return [
            'place_id' => trim((string) ($result['place_id'] ?? $placeId)) ?: $placeId,
            'name' => $name,
            'address' => $address,
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'types' => collect($result['types'] ?? [])
                ->map(fn (mixed $type): string => trim((string) $type))
                ->filter()
                ->take(12)
                ->values()
                ->all(),
        ];
    }
}
