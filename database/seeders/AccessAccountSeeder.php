<?php

namespace Database\Seeders;

use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AccessAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        User::updateOrCreate(
            ['email' => 'mhnzayyan@gmail.com'],
            [
                'name' => 'Mhn Zayyan',
                'phone' => '081399990001',
                'password' => Hash::make('password'),
                'role' => 'customer',
                'is_active' => true,
                'is_blacklisted' => false,
            ]
        );

        $driverUser = User::updateOrCreate(
            ['email' => 'zaky@gmail.com'],
            [
                'name' => 'Zaky Driver',
                'phone' => '081399990002',
                'password' => Hash::make('password'),
                'role' => 'driver',
                'is_active' => true,
                'is_blacklisted' => false,
            ]
        );

        $driver = Driver::updateOrCreate(
            ['user_id' => $driverUser->id],
            [
                'vehicle_type' => 'Motor Matic',
                'vehicle_brand' => 'Honda',
                'vehicle_model' => 'Beat',
                'vehicle_plate' => 'B 9090 ZKY',
                'registration_status' => 'active',
                'status' => 'available',
            ]
        );

        $documentTypes = ['ktp', 'sim', 'selfie'];
        foreach ($documentTypes as $documentType) {
            DriverDocument::updateOrCreate(
                [
                    'driver_id' => $driver->id,
                    'document_type' => $documentType,
                ],
                [
                    'file_path' => 'driver-docs/'.$driver->id.'/'.$documentType.'.jpg',
                    'verification_status' => 'approved',
                    'rejection_reason' => null,
                    'verified_at' => now(),
                    'verified_by' => null,
                ]
            );
        }
    }
}
