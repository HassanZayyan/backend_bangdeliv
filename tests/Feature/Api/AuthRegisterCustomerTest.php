<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthRegisterCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_customer_registration_creates_customer_with_token(): void
    {
        $response = $this->postJson('/api/auth/register/customer', [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '0812-3456-7890',
            'password' => 'rahasia123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Customer registered successfully')
            ->assertJsonPath('data.name', 'Budi Santoso')
            ->assertJsonPath('data.email', 'budi@example.com')
            ->assertJsonPath('data.phone', '081234567890')
            ->assertJsonPath('data.role', 'customer')
            ->assertJsonPath('data.auth_provider', 'password')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.requires_phone_completion', false)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['access_token']);

        $user = User::query()->where('email', 'budi@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('rahasia123', (string) $user->password));
        $this->assertNull($user->google_sub);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->phone_verified_at);
        $this->assertDatabaseHas('users', [
            'email' => 'budi@example.com',
            'phone' => '081234567890',
            'role' => 'customer',
        ]);
    }

    public function test_manual_customer_registration_rejects_duplicate_email_and_phone(): void
    {
        User::query()->create([
            'name' => 'Existing',
            'email' => 'existing@example.com',
            'phone' => '081234567891',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        $this->postJson('/api/auth/register/customer', [
            'name' => 'Bad Email',
            'email' => 'existing@example.com',
            'phone' => '081234567892',
            'password' => 'rahasia123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/auth/register/customer', [
            'name' => 'Bad Phone',
            'email' => 'bad.phone@example.com',
            'phone' => '0812-3456-7891',
            'password' => 'rahasia123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->postJson('/api/auth/register/customer', [
            'name' => 'Bad Payload',
            'email' => 'not-an-email',
            'phone' => 'abc',
            'password' => 'short',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'phone', 'password']);
    }

    public function test_google_login_creates_customer_without_phone_and_password(): void
    {
        $this->mockGoogleVerifier([
            'sub' => 'google-sub-1',
            'email' => 'google.user@example.com',
            'email_verified' => true,
            'name' => 'Google User',
            'picture' => 'https://lh3.googleusercontent.com/avatar',
        ]);

        $response = $this->postJson('/api/auth/google', [
            'id_token' => 'valid-google-token',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Login Google berhasil')
            ->assertJsonPath('data.email', 'google.user@example.com')
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.avatar_url', 'https://lh3.googleusercontent.com/avatar')
            ->assertJsonPath('data.auth_provider', 'google')
            ->assertJsonPath('data.has_password', false)
            ->assertJsonPath('data.requires_phone_completion', true)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['access_token']);

        $this->assertDatabaseHas('users', [
            'email' => 'google.user@example.com',
            'google_sub' => 'google-sub-1',
            'phone' => null,
            'password' => null,
            'role' => 'customer',
            'avatar' => 'https://lh3.googleusercontent.com/avatar',
        ]);
    }

    public function test_google_login_links_existing_email_account(): void
    {
        $user = User::query()->create([
            'name' => 'Siti Existing',
            'email' => 'siti@example.com',
            'phone' => '081234560009',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
        ]);

        $this->mockGoogleVerifier([
            'sub' => 'google-sub-linked',
            'email' => 'siti@example.com',
            'email_verified' => true,
            'name' => 'Siti Google',
            'picture' => 'https://lh3.googleusercontent.com/siti',
        ]);

        $response = $this->postJson('/api/auth/google', [
            'id_token' => 'valid-google-token',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.avatar_url', 'https://lh3.googleusercontent.com/siti')
            ->assertJsonPath('data.auth_provider', 'google')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.requires_phone_completion', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'google_sub' => 'google-sub-linked',
            'phone' => '081234560009',
            'avatar' => 'https://lh3.googleusercontent.com/siti',
        ]);
        $this->assertTrue(Hash::check('rahasia123', (string) $user->fresh()->password));
    }

    public function test_google_login_rejects_unverified_google_email(): void
    {
        $this->mockGoogleVerifier([
            'sub' => 'google-sub-unverified',
            'email' => 'unverified@example.com',
            'email_verified' => false,
            'name' => 'Unverified User',
            'picture' => null,
        ]);

        $response = $this->postJson('/api/auth/google', [
            'id_token' => 'valid-google-token',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Email Google belum terverifikasi.');
    }

    public function test_google_login_rejects_invalid_token(): void
    {
        $this->mock(GoogleIdTokenVerifier::class, function ($mock): void {
            $mock->shouldReceive('verify')
                ->once()
                ->with('invalid-token')
                ->andThrow(new \RuntimeException('invalid token'));
        });

        $response = $this->postJson('/api/auth/google', [
            'id_token' => 'invalid-token',
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('message', 'Token Google tidak valid. Silakan coba masuk ulang.');
    }

    public function test_google_login_rejects_inactive_account(): void
    {
        User::query()->create([
            'name' => 'Inactive',
            'email' => 'inactive@example.com',
            'phone' => '081234560019',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
            'is_active' => false,
        ]);

        $this->mockGoogleVerifier([
            'sub' => 'google-inactive',
            'email' => 'inactive@example.com',
            'email_verified' => true,
            'name' => 'Inactive',
            'picture' => null,
        ]);

        $response = $this->postJson('/api/auth/google', [
            'id_token' => 'valid-google-token',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('message', 'Akun Anda dinonaktifkan. Silakan hubungi Admin.');
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

    private function mockGoogleVerifier(array $identity): void
    {
        $this->mock(GoogleIdTokenVerifier::class, function ($mock) use ($identity): void {
            $mock->shouldReceive('verify')
                ->once()
                ->with('valid-google-token')
                ->andReturn($identity);
        });
    }
}
