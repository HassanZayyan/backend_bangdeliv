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
}
