<?php

namespace App\Services\Device;

use App\Models\DeviceToken;
use App\Models\User;

class DeviceTokenService
{
    public function register(User $user, string $token, string $deviceType): DeviceToken
    {
        return DeviceToken::query()->updateOrCreate(
            ['token' => $token],
            [
                'user_id' => $user->id,
                'device_type' => $deviceType,
                'is_active' => true,
            ],
        );
    }

    public function deactivate(User $user, string $token): bool
    {
        return DeviceToken::query()
            ->where('user_id', $user->id)
            ->where('token', $token)
            ->update(['is_active' => false]) > 0;
    }
}
