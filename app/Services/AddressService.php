<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AddressService
{
    public function __construct(
        private readonly GoogleMapsGeocodingService $geocodingService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(User $user, array $payload): Address
    {
        $fullAddress = $this->normalizeRequiredAddress((string) ($payload['full_address'] ?? ''));
        $providedCoordinates = $this->extractProvidedCoordinates($payload);
        $resolvedAddress = $this->resolveAddressForWrite($fullAddress, $providedCoordinates);
        $phone = $this->normalizePhone((string) ($payload['phone'] ?? ''));

        return DB::transaction(function () use ($user, $payload, $resolvedAddress, $phone): Address {
            $isDefault = (bool) ($payload['is_default'] ?? false);

            if ($isDefault || !$user->addresses()->exists()) {
                $user->addresses()->update(['is_default' => false]);
                $isDefault = true;
            }

            return $user->addresses()->create([
                'label' => trim((string) $payload['label']),
                'recipient_name' => trim((string) $payload['recipient_name']),
                'phone' => $phone,
                'full_address' => trim((string) $resolvedAddress['formatted_address']),
                'detail' => $this->normalizeNullableString($payload['detail'] ?? null),
                'latitude' => round((float) $resolvedAddress['latitude'], 8),
                'longitude' => round((float) $resolvedAddress['longitude'], 8),
                'is_default' => $isDefault,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(User $user, Address $address, array $payload): Address
    {
        $fullAddress = $this->normalizeRequiredAddress((string) ($payload['full_address'] ?? ''));
        $providedCoordinates = $this->extractProvidedCoordinates($payload);
        $resolvedAddress = $this->resolveAddressForWrite($fullAddress, $providedCoordinates);
        $phone = $this->normalizePhone((string) ($payload['phone'] ?? ''));

        return DB::transaction(function () use ($user, $address, $payload, $resolvedAddress, $phone): Address {
            $isDefault = (bool) ($payload['is_default'] ?? false);

            if ($isDefault) {
                $user->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
            }

            $address->update([
                'label' => trim((string) $payload['label']),
                'recipient_name' => trim((string) $payload['recipient_name']),
                'phone' => $phone,
                'full_address' => trim((string) $resolvedAddress['formatted_address']),
                'detail' => $this->normalizeNullableString($payload['detail'] ?? null),
                'latitude' => round((float) $resolvedAddress['latitude'], 8),
                'longitude' => round((float) $resolvedAddress['longitude'], 8),
                'is_default' => $isDefault,
            ]);

            return $address->fresh();
        });
    }

    /**
     * @return array{latitude: float, longitude: float, formatted_address: string}
     */
    public function validateAddress(string $fullAddress): array
    {
        $normalizedAddress = $this->normalizeRequiredAddress($fullAddress);

        $resolved = $this->geocodingService->resolveAddress($normalizedAddress);

        if ($resolved === null) {
            throw new ApiException('Alamat tidak valid atau tidak ditemukan di peta.', 422, [
                'full_address' => ['Alamat tidak valid atau tidak ditemukan di peta.'],
            ]);
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{latitude: float, longitude: float}|null
     */
    private function extractProvidedCoordinates(array $payload): ?array
    {
        $hasLatitude = array_key_exists('latitude', $payload) && $payload['latitude'] !== null && $payload['latitude'] !== '';
        $hasLongitude = array_key_exists('longitude', $payload) && $payload['longitude'] !== null && $payload['longitude'] !== '';

        if (!$hasLatitude && !$hasLongitude) {
            return null;
        }

        if (!$hasLatitude || !$hasLongitude) {
            throw new ApiException('Koordinat alamat tidak lengkap.', 422, [
                'latitude' => ['Latitude dan longitude wajib diisi berpasangan.'],
                'longitude' => ['Latitude dan longitude wajib diisi berpasangan.'],
            ]);
        }

        $latitude = (float) $payload['latitude'];
        $longitude = (float) $payload['longitude'];

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new ApiException('Koordinat alamat tidak valid.', 422, [
                'latitude' => ['Latitude atau longitude di luar rentang yang diizinkan.'],
                'longitude' => ['Latitude atau longitude di luar rentang yang diizinkan.'],
            ]);
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    /**
     * @param array{latitude: float, longitude: float}|null $providedCoordinates
     * @return array{latitude: float, longitude: float, formatted_address: string}
     */
    private function resolveAddressForWrite(string $fullAddress, ?array $providedCoordinates): array
    {
        if ($providedCoordinates === null) {
            return $this->validateAddress($fullAddress);
        }

        $resolvedAddress = $this->tryResolveAddress($fullAddress);

        return [
            'latitude' => $providedCoordinates['latitude'],
            'longitude' => $providedCoordinates['longitude'],
            'formatted_address' => trim((string) ($resolvedAddress['formatted_address'] ?? $fullAddress)),
        ];
    }

    /**
     * @return array{latitude: float, longitude: float, formatted_address: string}|null
     */
    private function tryResolveAddress(string $fullAddress): ?array
    {
        try {
            return $this->geocodingService->resolveAddress($fullAddress);
        } catch (ApiException $exception) {
            Log::warning('Address geocoding fallback to payload coordinates.', [
                'address' => $fullAddress,
                'status' => $exception->status(),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function normalizeRequiredAddress(string $fullAddress): string
    {
        $normalizedAddress = trim($fullAddress);

        if ($normalizedAddress === '') {
            throw new ApiException('Alamat lengkap wajib diisi.', 422, [
                'full_address' => ['Alamat lengkap wajib diisi.'],
            ]);
        }

        return $normalizedAddress;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', trim($phone)) ?? '';
    }
}
