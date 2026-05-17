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

    /**
     * @param  array<int, array{id?: int, label: string, latitude: float, longitude: float}>  $pickupPoints
     * @param  array{id?: int, label: string, latitude: float, longitude: float}  $dropoffPoint
     * @return array<string, mixed>
     */
    public function resolveOptimizedShoppingRoute(array $pickupPoints, array $dropoffPoint, int $maxOriginCandidates): array
    {
        if ($pickupPoints === []) {
            throw new ApiException('Minimal satu merchant belanja wajib tersedia.', 422);
        }

        if (count($pickupPoints) === 1) {
            $route = $this->resolveRoute(
                (float) $pickupPoints[0]['latitude'],
                (float) $pickupPoints[0]['longitude'],
                (float) $dropoffPoint['latitude'],
                (float) $dropoffPoint['longitude'],
            );

            return [
                ...$route,
                'ordered_pickup_location_ids' => array_values(array_filter([
                    isset($pickupPoints[0]['id']) ? (int) $pickupPoints[0]['id'] : null,
                ])),
                'segments' => [[
                    'from_label' => $pickupPoints[0]['label'],
                    'to_label' => $dropoffPoint['label'],
                    'distance_meters' => $route['distance_meters'],
                    'distance_km' => $route['distance_km'],
                    'distance_text' => $route['distance_text'],
                    'duration_seconds' => $route['duration_seconds'],
                    'duration_text' => $route['duration_text'],
                ]],
                'encoded_polyline' => null,
                'route_provider' => 'distance_matrix',
            ];
        }

        $limit = max(1, min(count($pickupPoints), $maxOriginCandidates));
        $best = null;

        foreach (array_slice($pickupPoints, 0, $limit) as $originIndex => $origin) {
            $remaining = array_values(array_filter(
                $pickupPoints,
                static fn (array $point, int $index): bool => $index !== $originIndex,
                ARRAY_FILTER_USE_BOTH
            ));

            try {
                $candidate = $this->resolveOptimizedRouteForOrigin($origin, $remaining, $dropoffPoint);
            } catch (ApiException $exception) {
                Log::warning('Optimized shopping route candidate failed.', [
                    'origin_label' => $origin['label'],
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            if (
                $best === null ||
                (int) $candidate['duration_seconds'] < (int) $best['duration_seconds'] ||
                (
                    (int) $candidate['duration_seconds'] === (int) $best['duration_seconds'] &&
                    (int) $candidate['distance_meters'] < (int) $best['distance_meters']
                )
            ) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            throw new ApiException('Rute optimal belanja belum dapat dihitung.', 503);
        }

        return $best;
    }

    private function formatCoordinate(float $value): string
    {
        return number_format($value, 8, '.', '');
    }

    /**
     * @param  array{id?: int, label: string, latitude: float, longitude: float}  $origin
     * @param  array<int, array{id?: int, label: string, latitude: float, longitude: float}>  $intermediates
     * @param  array{id?: int, label: string, latitude: float, longitude: float}  $destination
     * @return array<string, mixed>
     */
    private function resolveOptimizedRouteForOrigin(array $origin, array $intermediates, array $destination): array
    {
        $apiKey = (string) config('bangdeliv.google_maps_api_key');

        if ($apiKey === '') {
            throw new ApiException('Konfigurasi API Google Maps belum tersedia.', 500);
        }

        $travelMode = strtoupper((string) config('bangdeliv.routes.travel_mode', 'TWO_WHEELER'));
        if (! in_array($travelMode, ['DRIVE', 'TWO_WHEELER'], true)) {
            $travelMode = 'TWO_WHEELER';
        }

        try {
            return $this->postOptimizedRoute($apiKey, $origin, $intermediates, $destination, $travelMode);
        } catch (ApiException $exception) {
            if ($travelMode !== 'TWO_WHEELER') {
                throw $exception;
            }

            return $this->postOptimizedRoute($apiKey, $origin, $intermediates, $destination, 'DRIVE');
        }
    }

    /**
     * @param  array{id?: int, label: string, latitude: float, longitude: float}  $origin
     * @param  array<int, array{id?: int, label: string, latitude: float, longitude: float}>  $intermediates
     * @param  array{id?: int, label: string, latitude: float, longitude: float}  $destination
     * @return array<string, mixed>
     */
    private function postOptimizedRoute(
        string $apiKey,
        array $origin,
        array $intermediates,
        array $destination,
        string $travelMode,
    ): array {
        $routingPreference = strtoupper((string) config('bangdeliv.routes.routing_preference', 'TRAFFIC_AWARE'));
        if ($routingPreference === 'TRAFFIC_AWARE_OPTIMAL') {
            $routingPreference = 'TRAFFIC_AWARE';
        }

        $response = Http::timeout((int) config('bangdeliv.routes.timeout_seconds', 8))
            ->withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,routes.optimizedIntermediateWaypointIndex,routes.legs.distanceMeters,routes.legs.duration',
            ])
            ->acceptJson()
            ->post((string) config('bangdeliv.routes.endpoint', 'https://routes.googleapis.com/directions/v2:computeRoutes'), [
                'origin' => $this->waypointPayload($origin),
                'destination' => $this->waypointPayload($destination),
                'intermediates' => array_map(fn (array $point): array => $this->waypointPayload($point), $intermediates),
                'travelMode' => $travelMode,
                'routingPreference' => $routingPreference,
                'optimizeWaypointOrder' => count($intermediates) > 1,
                'languageCode' => (string) config('bangdeliv.routes.language_code', 'id'),
                'regionCode' => (string) config('bangdeliv.routes.region_code', 'ID'),
                'units' => (string) config('bangdeliv.routes.units', 'METRIC'),
            ]);

        if (! $response->successful()) {
            $payload = $response->json();
            Log::warning('Optimized Routes API failed.', [
                'http_status' => $response->status(),
                'travel_mode' => $travelMode,
                'error_message' => is_array($payload) ? data_get($payload, 'error.message') : null,
            ]);

            throw new ApiException('Layanan kalkulasi rute optimal sedang tidak tersedia.', 503);
        }

        $payload = $response->json();
        $route = $payload['routes'][0] ?? null;
        if (! is_array($route)) {
            throw new ApiException('Rute optimal tidak ditemukan untuk titik belanja.', 422);
        }

        $distanceMeters = isset($route['distanceMeters']) ? (int) $route['distanceMeters'] : null;
        $durationSeconds = $this->parseDurationSeconds($route['duration'] ?? null);
        if ($distanceMeters === null || $durationSeconds === null) {
            throw new ApiException('Respons kalkulasi rute optimal tidak lengkap.', 503);
        }

        $optimizedIndexes = $route['optimizedIntermediateWaypointIndex'] ?? [];
        if (! is_array($optimizedIndexes) || count($optimizedIndexes) !== count($intermediates)) {
            $optimizedIndexes = array_keys($intermediates);
        }

        $orderedIntermediates = [];
        foreach ($optimizedIndexes as $index) {
            if (isset($intermediates[(int) $index])) {
                $orderedIntermediates[] = $intermediates[(int) $index];
            }
        }

        $orderedPickups = [$origin, ...$orderedIntermediates];
        $orderedPoints = [$origin, ...$orderedIntermediates, $destination];

        return [
            'distance_meters' => max(0, $distanceMeters),
            'distance_km' => round(max(0, $distanceMeters) / 1000, 2),
            'distance_text' => number_format(round(max(0, $distanceMeters) / 1000, 2), 2).' km',
            'duration_seconds' => max(0, $durationSeconds),
            'duration_text' => $this->formatDurationText($durationSeconds),
            'ordered_pickup_location_ids' => array_values(array_filter(array_map(
                static fn (array $point): ?int => isset($point['id']) ? (int) $point['id'] : null,
                $orderedPickups
            ))),
            'segments' => $this->segmentsFromRouteLegs($route['legs'] ?? [], $orderedPoints),
            'encoded_polyline' => isset($route['polyline']['encodedPolyline'])
                ? (string) $route['polyline']['encodedPolyline']
                : null,
            'route_provider' => 'routes_api',
            'routing_preference' => $routingPreference,
            'travel_mode' => $travelMode,
        ];
    }

    /**
     * @param  array{id?: int, label: string, latitude: float, longitude: float}  $point
     * @return array<string, mixed>
     */
    private function waypointPayload(array $point): array
    {
        return [
            'location' => [
                'latLng' => [
                    'latitude' => (float) $point['latitude'],
                    'longitude' => (float) $point['longitude'],
                ],
            ],
        ];
    }

    /**
     * @param  mixed  $rawLegs
     * @param  array<int, array{id?: int, label: string, latitude: float, longitude: float}>  $orderedPoints
     * @return array<int, array<string, mixed>>
     */
    private function segmentsFromRouteLegs(mixed $rawLegs, array $orderedPoints): array
    {
        if (! is_array($rawLegs)) {
            return [];
        }

        $segments = [];
        foreach (array_values($rawLegs) as $index => $leg) {
            if (! is_array($leg) || ! isset($orderedPoints[$index], $orderedPoints[$index + 1])) {
                continue;
            }

            $distanceMeters = isset($leg['distanceMeters']) ? max(0, (int) $leg['distanceMeters']) : 0;
            $durationSeconds = $this->parseDurationSeconds($leg['duration'] ?? null) ?? 0;

            $segments[] = [
                'from_label' => $orderedPoints[$index]['label'],
                'to_label' => $orderedPoints[$index + 1]['label'],
                'distance_meters' => $distanceMeters,
                'distance_km' => round($distanceMeters / 1000, 2),
                'distance_text' => number_format(round($distanceMeters / 1000, 2), 2).' km',
                'duration_seconds' => $durationSeconds,
                'duration_text' => $this->formatDurationText($durationSeconds),
            ];
        }

        return $segments;
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
