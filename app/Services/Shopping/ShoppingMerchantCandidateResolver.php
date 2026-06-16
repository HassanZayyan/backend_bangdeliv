<?php

namespace App\Services\Shopping;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderLocation;
use App\Models\Restaurant;

class ShoppingMerchantCandidateResolver
{
    private const RESTAURANT_MATCH_DISTANCE_METERS = 150;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolve(Order $order, array $payload): ShoppingMerchantCandidate
    {
        if (isset($payload['merchant_place']) && is_array($payload['merchant_place'])) {
            return $this->resolveGooglePlaceCandidate($payload['merchant_place']);
        }

        return ShoppingMerchantCandidate::fromRestaurant(
            $this->resolveRestaurant($order, $payload)
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveRestaurant(Order $order, array $payload): Restaurant
    {
        if (isset($payload['merchant_id']) && is_numeric($payload['merchant_id'])) {
            $merchant = Restaurant::query()
                ->where('status', 'active')
                ->whereKey((int) $payload['merchant_id'])
                ->first();

            if (! $merchant) {
                throw new ApiException('Merchant tidak ditemukan atau tidak aktif.', 404);
            }

            return $merchant;
        }

        if ($order->restaurant instanceof Restaurant && $order->restaurant->status === 'active') {
            return $order->restaurant;
        }

        $pickup = $order->orderLocations
            ->first(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP' && $location->restaurant instanceof Restaurant);

        if ($pickup?->restaurant instanceof Restaurant && $pickup->restaurant->status === 'active') {
            return $pickup->restaurant;
        }

        throw new ApiException('Merchant wajib dipilih untuk item manual.', 422);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveGooglePlaceCandidate(array $payload): ShoppingMerchantCandidate
    {
        $name = $this->requiredText($payload['name'] ?? null, 'Nama tempat wajib diisi.');
        $address = $this->requiredText($payload['address'] ?? null, 'Alamat tempat wajib diisi.');
        $latitude = $this->coordinate($payload['latitude'] ?? null, -90, 90, 'Latitude tempat tidak valid.');
        $longitude = $this->coordinate($payload['longitude'] ?? null, -180, 180, 'Longitude tempat tidak valid.');

        $matchedRestaurant = $this->matchActiveRestaurant($name, $latitude, $longitude);
        if ($matchedRestaurant instanceof Restaurant) {
            return ShoppingMerchantCandidate::fromRestaurant($matchedRestaurant);
        }

        return ShoppingMerchantCandidate::fromGooglePlace(
            placeId: $this->optionalText($payload['place_id'] ?? null),
            name: $name,
            address: $address,
            latitude: $latitude,
            longitude: $longitude,
            placeTypes: $this->placeTypes($payload['types'] ?? []),
        );
    }

    private function matchActiveRestaurant(string $name, float $latitude, float $longitude): ?Restaurant
    {
        $normalizedName = $this->normalizeText($name);
        if ($normalizedName === '') {
            return null;
        }

        $candidates = Restaurant::query()
            ->where('status', 'active')
            ->where('name', 'like', '%'.$name.'%')
            ->limit(10)
            ->get();

        foreach ($candidates as $restaurant) {
            if ($this->normalizeText((string) $restaurant->name) !== $normalizedName) {
                continue;
            }

            if ($this->distanceMeters(
                $latitude,
                $longitude,
                (float) $restaurant->latitude,
                (float) $restaurant->longitude
            ) <= self::RESTAURANT_MATCH_DISTANCE_METERS) {
                return $restaurant;
            }
        }

        return null;
    }

    private function requiredText(mixed $value, string $message): string
    {
        $text = $this->optionalText($value);
        if ($text === null) {
            throw new ApiException($message, 422);
        }

        return mb_substr($text, 0, 255);
    }

    private function optionalText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    private function coordinate(mixed $value, float $min, float $max, string $message): float
    {
        if (! is_numeric($value)) {
            throw new ApiException($message, 422);
        }

        $coordinate = (float) $value;
        if ($coordinate < $min || $coordinate > $max) {
            throw new ApiException($message, 422);
        }

        return $coordinate;
    }

    /**
     * @return array<int, string>
     */
    private function placeTypes(mixed $rawTypes): array
    {
        if (! is_array($rawTypes)) {
            return [];
        }

        return collect($rawTypes)
            ->map(fn (mixed $type): string => trim((string) $type))
            ->filter(fn (string $type): bool => $type !== '')
            ->take(12)
            ->values()
            ->all();
    }

    private function normalizeText(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function distanceMeters(float $originLatitude, float $originLongitude, float $targetLatitude, float $targetLongitude): float
    {
        $earthRadiusMeters = 6371000.0;
        $originLatitudeRad = deg2rad($originLatitude);
        $targetLatitudeRad = deg2rad($targetLatitude);
        $deltaLatitudeRad = deg2rad($targetLatitude - $originLatitude);
        $deltaLongitudeRad = deg2rad($targetLongitude - $originLongitude);

        $haversine = sin($deltaLatitudeRad / 2) ** 2
            + cos($originLatitudeRad) * cos($targetLatitudeRad) * sin($deltaLongitudeRad / 2) ** 2;
        $safeHaversine = min(1.0, max(0.0, $haversine));

        return $earthRadiusMeters * 2 * atan2(sqrt($safeHaversine), sqrt(1 - $safeHaversine));
    }
}
