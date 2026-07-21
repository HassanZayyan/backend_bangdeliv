<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        // Buat akun admin (customer demo tidak dibuat lagi — dataset skripsi
        // lewat ThesisDatasetSeeder sudah memuat customer sungguhan).
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
    }
}
