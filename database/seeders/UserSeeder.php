<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Buat akun admin
        User::updateOrCreate(
            ['email' => 'admin@bangdeliv.com'], // Cek berdasarkan email
            [
                'name' => 'Super Admin',
                'phone' => '081234567890',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        // Buat akun customer untuk testing
        User::updateOrCreate(
            ['email' => 'customer@bangdeliv.com'],
            [
                'name' => 'Testing Customer',
                'phone' => '081111111111',
                'password' => Hash::make('password123'),
                'role' => 'customer',
                'is_active' => true,
            ]
        );
    }
}
