<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            [
                'name' => 'Hassan Nur',
                'email' => 'hassan@bangdeliv.com',
                'phone' => '081311110001',
            ],
            [
                'name' => 'Sari Wulandari',
                'email' => 'sari@bangdeliv.com',
                'phone' => '081311110002',
            ],
            [
                'name' => 'Budi Santoso',
                'email' => 'budi@bangdeliv.com',
                'phone' => '081311110003',
            ],
        ];

        foreach ($customers as $customer) {
            User::updateOrCreate(
                ['email' => $customer['email']],
                [
                    'name' => $customer['name'],
                    'phone' => $customer['phone'],
                    'password' => Hash::make('password123'),
                    'role' => 'customer',
                    'is_active' => true,
                    'is_blacklisted' => false,
                ]
            );
        }
    }
}
