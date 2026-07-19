<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class ProductionAdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = config('bangdeliv.production_admin', []);

        $email = trim((string) ($admin['email'] ?? ''));
        $password = (string) ($admin['password'] ?? '');
        $name = trim((string) ($admin['name'] ?? '')) ?: 'Super Admin';
        $phone = trim((string) ($admin['phone'] ?? ''));

        if ($email === '') {
            throw new InvalidArgumentException('BANGDELIV_ADMIN_EMAIL wajib diisi untuk ProductionAdminSeeder.');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('BANGDELIV_ADMIN_EMAIL harus berupa alamat email valid.');
        }

        if ($password === '') {
            throw new InvalidArgumentException('BANGDELIV_ADMIN_PASSWORD wajib diisi untuk ProductionAdminSeeder.');
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'phone' => $phone !== '' ? $phone : null,
                // Admin di-seed langsung terverifikasi agar tidak terjebak
                // layar OTP di environment baru.
                'phone_verified_at' => $phone !== '' ? now() : null,
                'password' => Hash::make($password),
                'role' => 'admin',
                'is_active' => true,
                'is_blacklisted' => false,
            ]
        );
    }
}
