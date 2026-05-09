<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            return $this->resolveRouteWithRoutesApi($apiKey, $originLat, $originLng, $destinationLat, $destinationLng);
        }

        $payload = $response->json();
        $status = (string) ($payload['status'] ?? 'UNKNOWN_ERROR');

        if ($status !== 'OK') {
            Log::warning('Distance Matrix API returned non-OK status; falling back to Routes API.', [
                'status' => $status,
                'error_message' => $payload['error_message'] ?? null,
            ]);

            return $this->resolveRouteWithRoutesApi($apiKey, $originLat, $originLng, $destinationLat, $destinationLng);
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

    /**
     * @return array{distance_meters: int, distance_km: float, distance_text: string, duration_seconds: int, duration_text: string}
     */
    private function resolveRouteWithRoutesApi(
        string $apiKey,
        float $originLat,
        float $originLng,
        float $destinationLat,
        float $destinationLng
    ): array {
        $response = Http::timeout((int) config('bangdeliv.routes.timeout_seconds', 8))
            ->withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration',
            ])
            ->acceptJson()
            ->post((string) config('bangdeliv.routes.endpoint', 'https://routes.googleapis.com/directions/v2:computeRoutes'), [
                'origin' => [
                    'location' => [
                        'latLng' => [
                            'latitude' => $originLat,
                            'longitude' => $originLng,
                        ],
                    ],
                ],
                'destination' => [
                    'location' => [
                        'latLng' => [
                            'latitude' => $destinationLat,
                            'longitude' => $destinationLng,
                        ],
                    ],
                ],
                'travelMode' => (string) config('bangdeliv.routes.travel_mode', 'DRIVE'),
                'routingPreference' => (string) config('bangdeliv.routes.routing_preference', 'TRAFFIC_UNAWARE'),
                'languageCode' => (string) config('bangdeliv.routes.language_code', 'id'),
                'regionCode' => (string) config('bangdeliv.routes.region_code', 'ID'),
                'units' => (string) config('bangdeliv.routes.units', 'METRIC'),
            ]);

        if (!$response->successful()) {
            $payload = $response->json();
            Log::warning('Routes API failed.', [
                'http_status' => $response->status(),
                'error_message' => is_array($payload) ? data_get($payload, 'error.message') : null,
            ]);

            throw new ApiException('Layanan kalkulasi rute sedang tidak tersedia.', 503);
        }

        $payload = $response->json();
        $route = $payload['routes'][0] ?? null;
        if (!is_array($route)) {
            throw new ApiException('Rute tidak ditemukan untuk lokasi jemput dan tujuan.', 422);
        }

        $distanceMeters = isset($route['distanceMeters'])
            ? (int) $route['distanceMeters']
            : null;
        $durationSeconds = $this->parseDurationSeconds($route['duration'] ?? null);

        if ($distanceMeters === null || $durationSeconds === null) {
            throw new ApiException('Respons kalkulasi rute tidak lengkap.', 503);
        }

        return [
            'distance_meters' => max(0, $distanceMeters),
            'distance_km' => round(max(0, $distanceMeters) / 1000, 2),
            'distance_text' => number_format(round(max(0, $distanceMeters) / 1000, 2), 2).' km',
            'duration_seconds' => max(0, $durationSeconds),
            'duration_text' => $this->formatDurationText($durationSeconds),
        ];
    }

    private function parseDurationSeconds(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return max(0, (int) round((float) $value));
        }

        if (!is_string($value)) {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)s$/', trim($value), $match) !== 1) {
            return null;
        }

        return max(0, (int) round((float) $match[1]));
    }

    private function formatDurationText(int $durationSeconds): string
    {
        $minutes = (int) max(1, ceil($durationSeconds / 60));

        if ($minutes < 60) {
            return $minutes.' menit';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes === 0) {
            return $hours.' jam';
        }

        return $hours.' jam '.$remainingMinutes.' menit';
    }
}
