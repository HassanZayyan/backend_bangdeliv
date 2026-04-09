<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthRegisterCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_with_name_email_phone_and_password(): void
    {
        $response = $this->postJson('/api/auth/register/customer', [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '0812-3456-7890',
            'password' => 'rahasia123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Customer registered successfully')
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'name', 'email', 'phone', 'role'],
                'access_token',
                'token_type',
            ]);

        $this->assertDatabaseHas('users', [
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'role' => 'customer',
            'email' => 'budi@example.com',
        ]);
    }

    public function test_user_can_login_with_email_and_password(): void
    {
        User::query()->create([
            'name' => 'Siti',
            'email' => 'siti@example.com',
            'phone' => '081234560001',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'siti@example.com',
            'password' => 'rahasia123',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('data.phone', '081234560001')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'name', 'phone', 'role'],
                'access_token',
                'token_type',
            ]);
    }
}
