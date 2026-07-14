<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\User;
use App\Services\Chatbot\ChatbotDraftStore;
use App\Services\Chatbot\ChatbotHelpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
                                ['text' => '{"intent":"courier_order","command":"none","pickup_address":null,"dropoff_address":null,"package_description":null,"payment_method":null}'],
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
            ->assertJsonPath('model_used', 'gemini-3.1-flash-lite');
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

    public function test_clear_chatbot_session_forgets_cached_draft(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081277777777',
        ]);
        $sessionId = 'chat-archive-test';

        $store = app(ChatbotDraftStore::class);
        $store->savePayload($user, $sessionId, [
            'intent' => 'ride_order',
            'ride' => [
                'destination_address' => 'Polines',
                'ready_to_confirm' => true,
            ],
            'order' => [
                'created' => false,
            ],
        ]);

        $this->assertNotNull($store->latestPayload($user, $sessionId));

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/chatbot/sessions/'.$sessionId);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('cleared', true)
            ->assertJsonPath('session_id', $sessionId);

        $this->assertNull($store->latestPayload($user, $sessionId));
    }

    public function test_out_of_context_messages_are_limited_per_user_and_service(): void
    {
        config([
            'bangdeliv.chatbot.out_of_context_limit' => 2,
            'bangdeliv.chatbot.out_of_context_window_minutes' => 30,
            'bangdeliv.chatbot.out_of_context_block_minutes' => 15,
        ]);

        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081288888888',
        ]);

        Sanctum::actingAs($user);

        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"intent":"out_of_domain","command":"none"}'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $payload = [
            'message' => 'ceritakan film favoritmu',
            'service_type' => 'kurir',
            'session_id' => 'ooc-limit-test',
        ];

        $this->postJson('/api/chatbot/process', $payload)
            ->assertOk()
            ->assertJsonPath('data.intent', 'out_of_domain');

        $this->postJson('/api/chatbot/process', [
            ...$payload,
            'message' => 'bahas topik lain',
        ])
            ->assertStatus(429)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Chatbot BangDeliv hanya untuk membuat pesanan. Kamu bisa coba lagi beberapa menit lagi.');
    }

    public function test_help_requests_are_handled_deterministically_for_every_service(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081299990001',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $this->createUsableAddress($user);
        Sanctum::actingAs($user);
        Http::fake();

        $cases = [
            'antar_jemput' => [
                'message' => 'ini suruh ngapain?',
                'intent' => 'ride_order',
                'actions' => ['OPEN_ROUTE_PICKER'],
                'action_path' => 'data.action_payloads.OPEN_ROUTE_PICKER.label',
                'action_label' => 'Atur Lokasi Jemput/Tujuan',
                'text' => 'Untuk membuat pesanan Antar Jemput',
            ],
            'kurir' => [
                'message' => 'CARA PESENNYA GIMANA???',
                'intent' => 'courier_order',
                'actions' => ['OPEN_ROUTE_PICKER'],
                'action_path' => 'data.action_payloads.OPEN_ROUTE_PICKER.label',
                'action_label' => 'Atur Lokasi Ambil/Tujuan',
                'text' => 'Untuk membuat pesanan Kurir',
            ],
            'nitip' => [
                'message' => 'mulainya dari mana?',
                'intent' => 'shopping_order',
                'actions' => ['OPEN_MERCHANT_PICKER', 'OPEN_MAP_PICKER_DELIVERY'],
                'action_path' => 'data.action_payloads.OPEN_MERCHANT_PICKER.label',
                'action_label' => 'Pilih Toko/Resto',
                'text' => 'Untuk membuat pesanan Nitip',
            ],
        ];

        foreach ($cases as $serviceType => $case) {
            $response = $this->postJson('/api/chatbot/process', [
                'message' => $case['message'],
                'service_type' => $serviceType,
                'session_id' => 'help-'.$serviceType,
            ]);

            $response->assertOk()
                ->assertJsonPath('data.intent', $case['intent'])
                ->assertJsonPath('data.validation.next_actions', $case['actions'])
                ->assertJsonPath($case['action_path'], $case['action_label'])
                ->assertJsonPath('model_used', 'deterministic-help');
            $this->assertStringContainsString($case['text'], (string) $response->json('data.assistant_text'));
            $this->assertStringContainsString('Google Maps', (string) $response->json('data.assistant_text'));
        }

        Http::assertNothingSent();
    }

    public function test_help_matcher_accepts_guidance_phrases_without_stealing_order_commands(): void
    {
        $service = app(ChatbotHelpService::class);

        foreach ([
            '  bantuan  ',
            'Bagaimana cara pakainya?',
            'ini suruh ngapain?',
            'saya harus isi apa',
            'mulainya gimana',
        ] as $message) {
            $this->assertTrue($service->matches($message), $message);
        }

        foreach ([
            'bingung makan apa',
            'antar ke Ramayana Salatiga',
            'COD',
            'tampilkan menu',
        ] as $message) {
            $this->assertFalse($service->matches($message), $message);
        }
    }

    public function test_help_without_saved_address_only_offers_address_book(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081299990002',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        Sanctum::actingAs($user);
        Http::fake();

        $response = $this->postJson('/api/chatbot/process', [
            'message' => 'aku harus isi apa?',
            'service_type' => 'nitip',
            'session_id' => 'help-no-address',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.intent', 'shopping_order')
            ->assertJsonPath('data.validation.next_actions', ['OPEN_ADDRESSES'])
            ->assertJsonPath('data.action_payloads.OPEN_ADDRESSES.label', 'Isi Alamat Saya')
            ->assertJsonMissingPath('data.action_payloads.OPEN_MERCHANT_PICKER')
            ->assertJsonPath('model_used', 'deterministic-help');
        $this->assertStringContainsString('Sebelum membuat pesanan Nitip', (string) $response->json('data.assistant_text'));

        Http::assertNothingSent();
    }

    public function test_help_keeps_existing_draft_payload_unchanged(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081299990003',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $this->createUsableAddress($user);
        $sessionId = 'help-preserves-draft';
        $draft = [
            'intent' => 'courier_order',
            'service_type' => 'kurir',
            'courier' => [
                'pickup_address' => 'Jl. Rumah No. 1',
                'dropoff_address' => null,
                'package_description' => 'dokumen',
            ],
            'validation' => [
                'is_valid_order' => false,
                'rejection_reasons' => [],
                'missing_fields' => ['dropoff_address'],
                'next_actions' => ['OPEN_ROUTE_PICKER'],
            ],
            'action_payloads' => [
                'OPEN_ROUTE_PICKER' => [
                    'label' => 'Atur Lokasi Ambil/Tujuan',
                ],
            ],
            'order' => [
                'created' => false,
                'id' => null,
            ],
        ];
        $store = app(ChatbotDraftStore::class);
        $store->savePayload($user, $sessionId, $draft);
        Sanctum::actingAs($user);
        Http::fake();

        $response = $this->postJson('/api/chatbot/process', [
            'message' => 'gimana caranya?',
            'service_type' => 'kurir',
            'session_id' => $sessionId,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.validation.missing_fields', ['dropoff_address'])
            ->assertJsonPath('data.validation.next_actions', ['OPEN_ROUTE_PICKER'])
            ->assertJsonPath('model_used', 'deterministic-help');
        $this->assertStringContainsString('Data pesanan yang sudah kamu isi tetap tersimpan', (string) $response->json('data.assistant_text'));
        $this->assertStringContainsString('lokasi tujuan', (string) $response->json('data.assistant_text'));
        $this->assertSame($draft, $store->latestPayload($user, $sessionId));

        Http::assertNothingSent();
    }

    public function test_help_resets_previous_out_of_context_counter(): void
    {
        config([
            'bangdeliv.chatbot.out_of_context_limit' => 2,
            'bangdeliv.chatbot.out_of_context_window_minutes' => 30,
            'bangdeliv.chatbot.out_of_context_block_minutes' => 15,
        ]);

        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081299990004',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        Sanctum::actingAs($user);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => '{"intent":"out_of_domain","command":"none"}'],
                        ],
                    ],
                ]],
            ], 200),
        ]);

        $payload = [
            'service_type' => 'kurir',
            'session_id' => 'help-resets-counter',
        ];

        $this->postJson('/api/chatbot/process', [
            ...$payload,
            'message' => 'ceritakan film favoritmu',
        ])->assertOk();
        $this->assertTrue(Cache::has('chatbot:context-limit:v1:'.$user->id.':kurir'));

        $this->postJson('/api/chatbot/process', [
            ...$payload,
            'message' => 'cara pesennya gimana?',
        ])->assertOk()
            ->assertJsonPath('model_used', 'deterministic-help');
        $this->assertFalse(Cache::has('chatbot:context-limit:v1:'.$user->id.':kurir'));

        $this->postJson('/api/chatbot/process', [
            ...$payload,
            'message' => 'bahas sepak bola',
        ])->assertOk();

        $this->postJson('/api/chatbot/process', [
            ...$payload,
            'message' => 'bahas film lain',
        ])->assertStatus(429);
    }

    public function test_gemini_help_command_uses_help_response_instead_of_out_of_context(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '081299990005',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $this->createUsableAddress($user);
        Sanctum::actingAs($user);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => '{"intent":"ride_order","command":"help","destination_address":null,"notes":null}'],
                        ],
                    ],
                ]],
            ], 200),
        ]);

        $response = $this->postJson('/api/chatbot/process', [
            'message' => 'boleh jelaskan langkah penggunaannya?',
            'service_type' => 'antar_jemput',
            'session_id' => 'gemini-help-command',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.intent', 'ride_order')
            ->assertJsonPath('data.validation.next_actions', ['OPEN_ROUTE_PICKER'])
            ->assertJsonPath('model_used', 'gemini-3.1-flash-lite');
        $this->assertStringContainsString('Untuk membuat pesanan Antar Jemput', (string) $response->json('data.assistant_text'));
    }

    private function createUsableAddress(User $user): void
    {
        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => $user->name,
            'phone' => $user->phone,
            'full_address' => 'Jl. Melati No. 3, Salatiga',
            'latitude' => -7.3305,
            'longitude' => 110.5084,
            'is_default' => true,
        ]);
    }
}
