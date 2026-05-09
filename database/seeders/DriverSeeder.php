<?php

namespace Database\Seeders;

use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DriverSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@bangdeliv.com')->first();

        $drivers = [
            [
                'user' => [
                    'name' => 'Agus Prasetyo',
                    'email' => 'agus.driver@bangdeliv.com',
                    'phone' => '081322220001',
                ],
                'driver' => [
                    'vehicle_type' => 'Motor Matic',
                    'vehicle_brand' => 'Honda',
                    'vehicle_model' => 'Vario 160',
                    'vehicle_plate' => 'B 1234 AGS',
                    'license_number' => 'SIMC-AGUS-2026',
                    'registration_status' => 'active',
                    'status' => 'available',
                    'avg_rating' => 4.80,
                    'total_deliveries' => 152,
                ],
                'documents' => [
                    'ktp' => 'approved',
                    'sim' => 'approved',
                    'selfie' => 'approved',
                ],
            ],
            [
                'user' => [
                    'name' => 'Dwi Wahyudi',
                    'email' => 'dwi.driver@bangdeliv.com',
                    'phone' => '081322220002',
                ],
                'driver' => [
                    'vehicle_type' => 'Motor Manual',
                    'vehicle_brand' => 'Yamaha',
                    'vehicle_model' => 'Jupiter MX',
                    'vehicle_plate' => 'B 8899 DWI',
                    'license_number' => 'SIMC-DWI-2026',
                    'registration_status' => 'pending',
                    'status' => 'offline',
                    'avg_rating' => 0,
                    'total_deliveries' => 0,
                ],
                'documents' => [
                    'ktp' => 'approved',
                    'sim' => 'pending',
                    'selfie' => 'pending',
                ],
            ],
            [
                'user' => [
                    'name' => 'Siti Aisyah',
                    'email' => 'siti.driver@bangdeliv.com',
                    'phone' => '081322220003',
                ],
                'driver' => [
                    'vehicle_type' => 'Motor Matic',
                    'vehicle_brand' => 'Suzuki',
                    'vehicle_model' => 'Nex II',
                    'vehicle_plate' => 'D 5511 SIT',
                    'license_number' => 'SIMC-SITI-2026',
                    'registration_status' => 'rejected',
                    'status' => 'offline',
                    'avg_rating' => 0,
                    'total_deliveries' => 0,
                ],
                'documents' => [
                    'ktp' => 'rejected',
                    'sim' => 'approved',
                    'selfie' => 'approved',
                ],
            ],
        ];

        foreach ($drivers as $entry) {
            $user = User::updateOrCreate(
                ['email' => $entry['user']['email']],
                [
                    'name' => $entry['user']['name'],
                    'phone' => $entry['user']['phone'],
                    'password' => Hash::make('password123'),
                    'role' => 'driver',
                    'is_active' => true,
                    'is_blacklisted' => false,
                ]
            );

            $driver = Driver::updateOrCreate(
                ['user_id' => $user->id],
                $entry['driver']
            );

            foreach ($entry['documents'] as $documentType => $status) {
                DriverDocument::updateOrCreate(
                    [
                        'driver_id' => $driver->id,
                        'document_type' => $documentType,
                    ],
                    [
                        'file_path' => 'driver-docs/'.$driver->id.'/'.$documentType.'.jpg',
                        'verification_status' => $status,
                        'rejection_reason' => $status === 'rejected' ? 'Dokumen buram atau tidak terbaca.' : null,
                        'verified_at' => $status === 'pending' ? null : now(),
                        'verified_by' => $status === 'pending' ? null : $admin?->id,
                    ]
                );
            }
        }
    }
}
