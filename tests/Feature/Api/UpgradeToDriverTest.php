<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UpgradeToDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_upgrade_to_driver_from_authenticated_session(): void
    {
        $customer = User::query()->create([
            'name' => 'Customer Upgrade',
            'email' => 'customer.upgrade@example.com',
            'phone' => '081233445566',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/user/upgrade-to-driver', [
            'vehicle_plate' => 'K 6969 MT',
            'license_number' => '3374011201010001',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Upgrade ke driver berhasil. Dokumen menunggu verifikasi admin.')
            ->assertJsonPath('data.user.role', 'driver')
            ->assertJsonPath('data.driver_profile.registration_status', 'pending')
            ->assertJsonPath('data.driver_profile.status', 'offline');

        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'role' => 'driver',
        ]);

        $this->assertDatabaseHas('drivers', [
            'user_id' => $customer->id,
            'vehicle_plate' => 'K 6969 MT',
            'license_number' => '3374011201010001',
            'registration_status' => 'pending',
        ]);
    }

    public function test_guest_cannot_upgrade_to_driver(): void
    {
        $response = $this->postJson('/api/user/upgrade-to-driver', [
            'vehicle_plate' => 'K 1111 AB',
            'license_number' => '3374011201010002',
        ]);

        $response->assertStatus(401);
    }

    public function test_non_customer_role_cannot_use_upgrade_endpoint(): void
    {
        $driver = User::query()->create([
            'name' => 'Existing Driver',
            'email' => 'existing.driver@example.com',
            'phone' => '081244556677',
            'password' => Hash::make('rahasia123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Sanctum::actingAs($driver);

        $response = $this->postJson('/api/user/upgrade-to-driver', [
            'vehicle_plate' => 'K 2222 AB',
            'license_number' => '3374011201010003',
        ]);

        $response->assertStatus(403);
    }

    public function test_upgrade_endpoint_rejects_when_customer_already_has_driver_profile(): void
    {
        $customer = User::query()->create([
            'name' => 'Customer Existing Driver Profile',
            'email' => 'customer.driver.profile@example.com',
            'phone' => '081255667788',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Driver::query()->create([
            'user_id' => $customer->id,
            'vehicle_plate' => 'K 3333 AB',
            'license_number' => '3374011201010004',
            'registration_status' => 'pending',
            'status' => 'offline',
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/user/upgrade-to-driver', [
            'vehicle_plate' => 'K 4444 AB',
            'license_number' => '3374011201010005',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Akun ini sudah memiliki profil driver.');
    }

    public function test_public_driver_registration_route_is_not_available_anymore(): void
    {
        $response = $this->postJson('/api/auth/register/driver', [
            'name' => 'Driver Public',
            'email' => 'driver.public@example.com',
            'phone' => '081200000099',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'vehicle_plate' => 'K 5555 AB',
            'license_number' => '3374011201010006',
        ]);

        $response->assertStatus(404);
    }
}
