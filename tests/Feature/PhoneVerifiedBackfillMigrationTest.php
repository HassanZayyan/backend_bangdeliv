<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhoneVerifiedBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * User lama yang sudah punya nomor tidak boleh dipaksa OTP saat rilis.
     */
    public function test_backfill_marks_existing_phones_as_verified(): void
    {
        $withPhone = User::query()->create([
            'name' => 'User Lama',
            'email' => 'lama@example.com',
            'phone' => '081234567890',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
            'phone_verified_at' => null,
        ]);

        $withoutPhone = User::query()->create([
            'name' => 'User Google',
            'email' => 'google@example.com',
            'phone' => null,
            'password' => null,
            'google_sub' => 'google-sub-1',
            'role' => 'customer',
            'phone_verified_at' => null,
        ]);

        $migration = require database_path('migrations/2026_07_19_000001_create_phone_verification_codes_table.php');
        $migration->up();

        $this->assertNotNull($withPhone->fresh()->phone_verified_at);
        $this->assertNull($withoutPhone->fresh()->phone_verified_at);
    }
}
