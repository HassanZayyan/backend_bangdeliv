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
                'vehicle_plate' => 'B 9090 ZKY',
                'license_number' => 'SIMC-ZKY-2026',
                'registration_status' => 'active',
                'status' => 'available',
                'avg_rating' => 0,
                'total_deliveries' => 0,
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
