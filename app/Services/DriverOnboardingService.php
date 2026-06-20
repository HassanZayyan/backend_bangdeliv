<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DriverOnboardingService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function upgradeCustomerToDriver(User $actor, array $payload): array
    {
        if ($actor->role !== 'customer') {
            throw new HttpException(403, 'Hanya akun customer yang dapat upgrade menjadi driver.');
        }

        if ($actor->driver()->exists()) {
            throw new HttpException(409, 'Akun ini sudah memiliki profil driver.');
        }

        DB::transaction(function () use ($actor, $payload): void {
            Driver::query()->create([
                'user_id' => $actor->id,
                'vehicle_type' => trim((string) $payload['vehicle_type']),
                'vehicle_brand' => trim((string) $payload['vehicle_brand']),
                'vehicle_model' => trim((string) $payload['vehicle_model']),
                'vehicle_plate' => trim((string) $payload['vehicle_plate']),
                'registration_status' => 'pending',
                'status' => 'offline',
            ]);

            $actor->update([
                'role' => 'driver',
            ]);
        });

        $actor->refresh()->load('driver');

        return [
            'user' => [
                'id' => $actor->id,
                'name' => $actor->name,
                'email' => $actor->email,
                'phone' => $actor->phone,
                'role' => $actor->role,
            ],
            'driver_profile' => $actor->driver,
        ];
    }
}
