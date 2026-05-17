<?php

namespace App\Services;

use App\Models\Address;
use App\Models\User;

class ChatbotAddressReadinessService
{
    public function hasUsableSavedAddress(User $user): bool
    {
        return Address::query()
            ->where('user_id', $user->id)
            ->get()
            ->contains(fn (Address $address): bool => $this->isUsable($address));
    }

    public function resolveDefaultUsableAddress(User $user): ?Address
    {
        return Address::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->first(fn (Address $address): bool => $this->isUsable($address));
    }

    /**
     * @return array{formatted_address: string, latitude: float, longitude: float}|null
     */
    public function toLocationPayload(?Address $address): ?array
    {
        if ($address === null || ! $this->isUsable($address)) {
            return null;
        }

        return [
            'formatted_address' => trim((string) $address->full_address),
            'latitude' => (float) $address->latitude,
            'longitude' => (float) $address->longitude,
        ];
    }

    public function isUsable(Address $address): bool
    {
        $fullAddress = trim((string) $address->full_address);
        $latitude = $this->nullableCoordinate($address->latitude);
        $longitude = $this->nullableCoordinate($address->longitude);

        return $fullAddress !== ''
            && $latitude !== null
            && $longitude !== null
            && $latitude >= -90
            && $latitude <= 90
            && $longitude >= -180
            && $longitude <= 180
            && ! ($latitude == 0.0 && $longitude == 0.0);
    }

    private function nullableCoordinate(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
