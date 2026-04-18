<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatbotAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_chatbot_endpoint(): void
    {
        $response = $this->postJson('/api/chatbot/process', [
            'message' => 'saya mau pesan ayam bakar',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_chatbot_endpoint(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081233333333',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"intent":"out_of_domain","resto":null,"items":[]}'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/chatbot/process', [
            'message' => 'halo',
            'service_type' => 'kurir',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('service_context.service_type', 'kurir')
            ->assertJsonPath('service_context.service_code', 'COURIER')
            ->assertJsonPath('data.intent', 'courier_order')
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('model_used', 'gemini-3.1-flash-lite-preview');
    }

    public function test_authenticated_user_is_throttled_when_exceeding_limit(): void
    {
        config([
            'bangdeliv.chatbot.rate_limit_per_minute' => 2,
            'bangdeliv.chatbot.rate_limit_per_hour' => 2,
        ]);

        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081244444444',
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"intent":"out_of_domain","resto":null,"items":[]}'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->postJson('/api/chatbot/process', [
            'message' => 'tes 1',
            'service_type' => 'nitip',
        ])->assertOk();

        $this->postJson('/api/chatbot/process', [
            'message' => 'tes 2',
            'service_type' => 'nitip',
        ])->assertOk();

        $this->postJson('/api/chatbot/process', [
            'message' => 'tes 3',
            'service_type' => 'nitip',
        ])->assertStatus(429);
    }

    public function test_invalid_service_type_is_rejected(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081255555555',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/chatbot/process', [
            'message' => 'tes invalid service type',
            'service_type' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['service_type']);
    }

    public function test_service_type_defaults_to_nitip_when_not_provided(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081266666666',
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"intent":"out_of_domain","resto":null,"items":[]}'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/chatbot/process', [
            'message' => 'default context test',
        ]);

        $response->assertOk()
            ->assertJsonPath('service_context.service_type', 'nitip')
            ->assertJsonPath('service_context.service_code', 'SHOPPING');
    }
}
