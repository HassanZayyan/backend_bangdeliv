<?php

namespace Tests\Feature\Api;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_register_android_device_token(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/device-tokens', [
            'token' => 'android-token-1',
            'device_type' => 'android',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.device_type', 'android')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'token' => 'android-token-1',
            'device_type' => 'android',
            'is_active' => true,
        ]);
    }

    public function test_existing_token_moves_to_latest_authenticated_user(): void
    {
        $oldUser = User::factory()->create(['role' => 'customer']);
        $newUser = User::factory()->create(['role' => 'driver']);

        DeviceToken::query()->create([
            'user_id' => $oldUser->id,
            'token' => 'shared-device-token',
            'device_type' => 'android',
            'is_active' => false,
        ]);

        Sanctum::actingAs($newUser);

        $this->postJson('/api/v1/device-tokens', [
            'token' => 'shared-device-token',
            'device_type' => 'android',
        ])->assertCreated();

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $newUser->id,
            'token' => 'shared-device-token',
            'device_type' => 'android',
            'is_active' => true,
        ]);

        $this->assertDatabaseCount('device_tokens', 1);
    }

    public function test_authenticated_user_can_deactivate_own_device_token(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        DeviceToken::query()->create([
            'user_id' => $user->id,
            'token' => 'android-token-logout',
            'device_type' => 'android',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/device-tokens', [
            'token' => 'android-token-logout',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deactivated', true);

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'token' => 'android-token-logout',
            'is_active' => false,
        ]);
    }
}
