<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
        $resolvedAddress = $this->validateAddress($payload['full_address'] ?? '');
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
        $resolvedAddress = $this->validateAddress($payload['full_address'] ?? '');
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
        $normalizedAddress = trim($fullAddress);

        if ($normalizedAddress === '') {
            throw new ApiException('Alamat lengkap wajib diisi.', 422, [
                'full_address' => ['Alamat lengkap wajib diisi.'],
            ]);
        }

        $resolved = $this->geocodingService->resolveAddress($normalizedAddress);

        if ($resolved === null) {
            throw new ApiException('Alamat tidak valid atau tidak ditemukan di peta.', 422, [
                'full_address' => ['Alamat tidak valid atau tidak ditemukan di peta.'],
            ]);
        }

        return $resolved;
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
