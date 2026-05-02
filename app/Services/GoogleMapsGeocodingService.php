<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $results = $payload['results'] ?? [];
        $selectedResult = $this->selectBestResult($results);
        $location = $selectedResult['geometry']['location'] ?? null;

        if (!is_array($location) || !isset($location['lat'], $location['lng'])) {
            throw new ApiException('Respons geocoding tidak lengkap.', 503);
        }

        $selectedIndex = (int) ($selectedResult['__index'] ?? 0);
        if ($selectedIndex > 0) {
            Log::info('Geocoding selected non-first result due to better confidence.', [
                'address' => $address,
                'selected_index' => $selectedIndex,
                'location_type' => $selectedResult['geometry']['location_type'] ?? null,
                'types' => $selectedResult['types'] ?? [],
            ]);
        }

        return [
            'latitude' => (float) $location['lat'],
            'longitude' => (float) $location['lng'],
            'formatted_address' => (string) ($selectedResult['formatted_address'] ?? $address),
        ];
    }

    /**
     * @return array{latitude: float, longitude: float, formatted_address: string}|null
     */
    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        $apiKey = (string) config('bangdeliv.google_maps_api_key');

        if ($apiKey === '') {
            throw new ApiException('Konfigurasi API Google Maps belum tersedia.', 500);
        }

        $response = Http::timeout((int) config('bangdeliv.geocoding.timeout_seconds', 8))
            ->acceptJson()
            ->get((string) config('bangdeliv.geocoding.endpoint', 'https://maps.googleapis.com/maps/api/geocode/json'), [
                'latlng' => sprintf('%.6f,%.6f', $latitude, $longitude),
                'language' => (string) config('bangdeliv.geocoding.language', 'id'),
                'region' => (string) config('bangdeliv.geocoding.region', 'id'),
                'key' => $apiKey,
            ]);

        if (!$response->successful()) {
            throw new ApiException('Layanan validasi koordinat sedang tidak tersedia.', 503);
        }

        $payload = $response->json();
        $status = (string) ($payload['status'] ?? 'UNKNOWN_ERROR');

        if ($status === 'ZERO_RESULTS') {
            return null;
        }

        if ($status !== 'OK') {
            throw new ApiException('Gagal memvalidasi koordinat lokasi. Coba beberapa saat lagi.', 503);
        }

        $results = $payload['results'] ?? [];
        $selectedResult = $this->selectBestResult($results);
        $location = $selectedResult['geometry']['location'] ?? null;

        if (!is_array($location) || !isset($location['lat'], $location['lng'])) {
            throw new ApiException('Respons reverse geocoding tidak lengkap.', 503);
        }

        $selectedIndex = (int) ($selectedResult['__index'] ?? 0);
        if ($selectedIndex > 0) {
            Log::info('Reverse geocoding selected non-first result due to better confidence.', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'selected_index' => $selectedIndex,
                'location_type' => $selectedResult['geometry']['location_type'] ?? null,
                'types' => $selectedResult['types'] ?? [],
            ]);
        }

        return [
            'latitude' => (float) $location['lat'],
            'longitude' => (float) $location['lng'],
            'formatted_address' => (string) ($selectedResult['formatted_address'] ?? sprintf('Pin %.6f, %.6f', $latitude, $longitude)),
        ];
    }

    /**
     * Reverse-geocode a coordinate AND enrich it with the nearest named place
     * (via Places Nearby Search). Returns an address in the form:
     *   "[Place Name], [Full Address]"  — when a named place is found
     *   "[Full Address]"               — when no named place exists
     *
     * @return array{latitude: float, longitude: float, formatted_address: string}|null
     */
    public function reverseGeocodeWithPlaceName(float $latitude, float $longitude): ?array
    {
        $apiKey = (string) config('bangdeliv.google_maps_api_key');

        if ($apiKey === '') {
            throw new ApiException('Konfigurasi API Google Maps belum tersedia.', 500);
        }

        // 1. Always get the clean street address first via Reverse Geocoding
        $geocoded = $this->reverseGeocode($latitude, $longitude);
        $baseAddress = $geocoded ? trim((string) ($geocoded['formatted_address'] ?? '')) : '';

        // 2. Try Nearby Search to find a named establishment/POI near the pin
        try {
            $nearbyResponse = Http::timeout((int) config('bangdeliv.geocoding.timeout_seconds', 8))
                ->acceptJson()
                ->get('https://maps.googleapis.com/maps/api/place/nearbysearch/json', [
                    'location'  => sprintf('%.6f,%.6f', $latitude, $longitude),
                    'rankby'    => 'distance',
                    'language'  => (string) config('bangdeliv.geocoding.language', 'id'),
                    'key'       => $apiKey,
                    // Broad types that cover most named POIs
                    'type'      => 'establishment',
                ]);

            if ($nearbyResponse->successful()) {
                $nearbyPayload = $nearbyResponse->json();
                $nearbyStatus  = (string) ($nearbyPayload['status'] ?? 'ZERO_RESULTS');

                if ($nearbyStatus === 'OK') {
                    $nearbyResults = is_array($nearbyPayload['results'] ?? null)
                        ? $nearbyPayload['results']
                        : [];

                    // Pick the single closest result (already sorted by distance)
                    foreach ($nearbyResults as $place) {
                        if (!is_array($place)) {
                            continue;
                        }

                        $placeName    = trim((string) ($place['name'] ?? ''));
                        $placeVicinity = trim((string) ($place['vicinity'] ?? ''));

                        // Skip generics / unnamed / transit stops
                        $skipTypes = ['transit_station', 'bus_station', 'subway_station', 'route', 'street_address'];
                        $placeTypes = is_array($place['types'] ?? null) ? $place['types'] : [];
                        $isGeneric  = count(array_intersect($skipTypes, $placeTypes)) > 0;

                        if ($placeName === '' || $isGeneric) {
                            continue;
                        }

                        // Use the place name as prefix only if it isn't already in the base address
                        $finalAddress = $baseAddress;
                        if (!str_contains(strtolower($baseAddress), strtolower($placeName))) {
                            $finalAddress = $placeName . ', ' . $baseAddress;
                        }

                        return [
                            'latitude'          => (float) $latitude,
                            'longitude'         => (float) $longitude,
                            'formatted_address' => $finalAddress,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Places Nearby Search failed during map-pin enrichment.', [
                'latitude'  => $latitude,
                'longitude' => $longitude,
                'error'     => $e->getMessage(),
            ]);
        }

        // 3. Fall back: return plain reverse-geocoded address
        if ($geocoded !== null) {
            return $geocoded;
        }

        return [
            'latitude'          => (float) $latitude,
            'longitude'         => (float) $longitude,
            'formatted_address' => sprintf('Pin %.6f, %.6f', $latitude, $longitude),
        ];
    }

    /**
     * @param  mixed  $results
     * @return array<string, mixed>
     */
    private function selectBestResult(mixed $results): array
    {
        if (!is_array($results) || $results === []) {
            throw new ApiException('Respons geocoding tidak lengkap.', 503);
        }

        $bestResult = null;
        $bestScore = PHP_INT_MIN;

        foreach ($results as $index => $result) {
            if (!is_array($result)) {
                continue;
            }

            $location = $result['geometry']['location'] ?? null;
            if (!is_array($location) || !isset($location['lat'], $location['lng'])) {
                continue;
            }

            $score = $this->scoreResult($result);
            if ($score <= $bestScore) {
                continue;
            }

            $bestScore = $score;
            $bestResult = $result;
            $bestResult['__index'] = (int) $index;
        }

        if (!is_array($bestResult)) {
            throw new ApiException('Respons geocoding tidak lengkap.', 503);
        }

        return $bestResult;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function scoreResult(array $result): int
    {
        $score = 0;

        $locationType = strtoupper((string) ($result['geometry']['location_type'] ?? ''));
        $score += match ($locationType) {
            'ROOFTOP' => 6,
            'RANGE_INTERPOLATED' => 4,
            'GEOMETRIC_CENTER' => 2,
            'APPROXIMATE' => 1,
            default => 0,
        };

        $partialMatch = (bool) ($result['partial_match'] ?? false);
        $score += $partialMatch ? -1 : 2;

        $types = [];
        foreach (($result['types'] ?? []) as $type) {
            if (is_string($type)) {
                $types[] = strtolower($type);
            }
        }

        foreach ($types as $type) {
            if (in_array($type, ['street_address', 'premise', 'subpremise', 'establishment'], true)) {
                $score += 3;
                break;
            }
        }

        foreach ($types as $type) {
            if (in_array($type, ['locality', 'administrative_area_level_1', 'administrative_area_level_2'], true)) {
                $score += 1;
                break;
            }
        }

        return $score;
    }

    /**
     * @return array{latitude: float, longitude: float, formatted_address: string}|null
     */
    public function resolvePlace(string $query): ?array
    {
        $apiKey = (string) config('bangdeliv.google_maps_api_key');

        if ($apiKey === '') {
            throw new ApiException('Konfigurasi API Google Maps belum tersedia.', 500);
        }

        $response = Http::timeout((int) config('bangdeliv.geocoding.timeout_seconds', 8))
            ->acceptJson()
            ->get('https://maps.googleapis.com/maps/api/place/textsearch/json', [
                'query' => $query,
                'language' => (string) config('bangdeliv.geocoding.language', 'id'),
                'region' => (string) config('bangdeliv.geocoding.region', 'id'),
                'key' => $apiKey,
            ]);

        if (!$response->successful()) {
            return $this->resolveAddress($query);
        }

        $payload = $response->json();
        $status = (string) ($payload['status'] ?? 'UNKNOWN_ERROR');

        if ($status === 'ZERO_RESULTS') {
            return $this->resolveAddress($query);
        }

        if ($status !== 'OK') {
            return $this->resolveAddress($query);
        }

        $results = $payload['results'] ?? [];
        if (count($results) === 0) {
            return $this->resolveAddress($query);
        }

        $selectedResult = $results[0];
        $location = $selectedResult['geometry']['location'] ?? null;

        if (!is_array($location) || !isset($location['lat'], $location['lng'])) {
            return $this->resolveAddress($query);
        }

        $name = trim((string) ($selectedResult['name'] ?? ''));
        $formattedAddress = trim((string) ($selectedResult['formatted_address'] ?? ''));

        $finalAddress = $formattedAddress;

        if ($name !== '' && $name !== $formattedAddress) {
            if (!str_contains(strtolower($formattedAddress), strtolower($name))) {
                $finalAddress = $name . ', ' . $formattedAddress;
            }
        }

        return [
            'latitude' => (float) $location['lat'],
            'longitude' => (float) $location['lng'],
            'formatted_address' => $finalAddress,
        ];
    }
}
