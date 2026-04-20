<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

class GoogleMapsDistanceMatrixService
{
    /**
     * @return array{distance_meters: int, distance_km: float, distance_text: string, duration_seconds: int, duration_text: string}
     */
    public function resolveRoute(float $originLat, float $originLng, float $destinationLat, float $destinationLng): array
    {
        $apiKey = (string) config('bangdeliv.google_maps_api_key');

        if ($apiKey === '') {
            throw new ApiException('Konfigurasi API Google Maps belum tersedia.', 500);
        }

        $response = Http::timeout((int) config('bangdeliv.distance_matrix.timeout_seconds', 8))
            ->acceptJson()
            ->get((string) config('bangdeliv.distance_matrix.endpoint', 'https://maps.googleapis.com/maps/api/distancematrix/json'), [
                'origins' => $this->formatCoordinate($originLat).','.$this->formatCoordinate($originLng),
                'destinations' => $this->formatCoordinate($destinationLat).','.$this->formatCoordinate($destinationLng),
                'mode' => (string) config('bangdeliv.distance_matrix.mode', 'driving'),
                'language' => (string) config('bangdeliv.distance_matrix.language', 'id'),
                'region' => (string) config('bangdeliv.distance_matrix.region', 'id'),
                'key' => $apiKey,
            ]);

        if (!$response->successful()) {
            throw new ApiException('Layanan kalkulasi rute sedang tidak tersedia.', 503);
        }

        $payload = $response->json();
        $status = (string) ($payload['status'] ?? 'UNKNOWN_ERROR');

        if ($status !== 'OK') {
            throw new ApiException('Gagal menghitung rute perjalanan. Coba beberapa saat lagi.', 503);
        }

        $element = $payload['rows'][0]['elements'][0] ?? null;
        if (!is_array($element)) {
            throw new ApiException('Respons kalkulasi rute tidak lengkap.', 503);
        }

        $elementStatus = (string) ($element['status'] ?? 'UNKNOWN_ERROR');
        if ($elementStatus === 'ZERO_RESULTS') {
            throw new ApiException('Rute tidak ditemukan untuk lokasi jemput dan tujuan.', 422);
        }

        if ($elementStatus !== 'OK') {
            throw new ApiException('Rute tidak valid untuk diproses.', 422);
        }

        $distanceMeters = isset($element['distance']['value'])
            ? (int) $element['distance']['value']
            : null;
        $durationSeconds = isset($element['duration']['value'])
            ? (int) $element['duration']['value']
            : null;

        if ($distanceMeters === null || $durationSeconds === null) {
            throw new ApiException('Respons kalkulasi rute tidak lengkap.', 503);
        }

        return [
            'distance_meters' => max(0, $distanceMeters),
            'distance_km' => round(max(0, $distanceMeters) / 1000, 2),
            'distance_text' => (string) ($element['distance']['text'] ?? number_format(max(0, $distanceMeters) / 1000, 2).' km'),
            'duration_seconds' => max(0, $durationSeconds),
            'duration_text' => (string) ($element['duration']['text'] ?? ''),
        ];
    }

    private function formatCoordinate(float $value): string
    {
        return number_format($value, 8, '.', '');
    }
}
