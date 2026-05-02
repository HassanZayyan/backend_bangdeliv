<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotRideFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bangdeliv.google_maps_api_key' => 'test-google-maps-key',
        ]);
    }

    public function test_chatbot_ride_requires_draft_before_confirmation(): void
    {
        $this->fakeGeminiAndGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089900000001',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot-ride')->plainTextToken;

        $draftResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'antar ke polines',
                'service_type' => 'antar_jemput',
                'session_id' => 'sess-ride-01',
            ]);

        $draftResponse
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.intent', 'ride_order')
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.order.created', false)
            ->assertJsonPath('data.ride.ready_to_confirm', true);

        $this->assertStringContainsString(
            'Ketik "Konfirmasi"',
            (string) $draftResponse->json('data.assistant_text')
        );

        $this->assertDatabaseCount('orders', 0);

        $confirmResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'konfirmasi',
                'service_type' => 'antar_jemput',
                'session_id' => 'sess-ride-01',
            ]);

        $confirmResponse
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order.created', true);

        $this->assertStringContainsString(
            'Nomor order:',
            (string) $confirmResponse->json('data.assistant_text')
        );

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_chatbot_ride_confirmation_preserves_draft_coordinates_without_regeocoding_destination(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089900000004',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot-ride')->plainTextToken;

        $polinesGeocodeCalls = 0;

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => function ($request) {
                $body = $request->data();
                $rawMessage = (string) data_get($body, 'contents.0.parts.0.text', '');
                $normalized = strtolower(trim($rawMessage));

                if ($normalized === 'konfirmasi') {
                    $json = '{"intent":"ride_order","command":"confirm","destination_address":null,"notes":null}';
                } else {
                    $json = '{"intent":"ride_order","command":"none","destination_address":"Polines Semarang","notes":null}';
                }

                return Http::response([
                    'candidates' => [[
                        'content' => [
                            'parts' => [
                                ['text' => $json],
                            ],
                        ],
                    ]],
                ], 200);
            },
            'https://maps.googleapis.com/maps/api/geocode/*' => function ($request) use (&$polinesGeocodeCalls) {
                $queryString = parse_url($request->url(), PHP_URL_QUERY) ?? '';
                parse_str($queryString, $query);

                $address = strtolower(trim((string) ($query['address'] ?? '')));

                if (str_contains($address, 'melati')) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Jl. Melati No. 3, Banyumanik, Kota Semarang, Jawa Tengah, Indonesia',
                            'geometry' => [
                                'location' => [
                                    'lat' => -7.050900,
                                    'lng' => 110.431500,
                                ],
                            ],
                        ]],
                    ], 200);
                }

                if (str_contains($address, 'polines')) {
                    $polinesGeocodeCalls++;

                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Jl. Prof. Soedarto, Tembalang, Kota Semarang, Jawa Tengah, Indonesia',
                            'geometry' => [
                                'location' => [
                                    'lat' => $polinesGeocodeCalls === 1 ? -7.052301 : -6.900001,
                                    'lng' => $polinesGeocodeCalls === 1 ? 110.435601 : 107.600001,
                                ],
                            ],
                        ]],
                    ], 200);
                }

                return Http::response([
                    'status' => 'ZERO_RESULTS',
                    'results' => [],
                ], 200);
            },
            'https://maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
                'status' => 'OK',
                'rows' => [[
                    'elements' => [[
                        'status' => 'OK',
                        'distance' => [
                            'text' => '1.3 km',
                            'value' => 1300,
                        ],
                        'duration' => [
                            'text' => '7 mins',
                            'value' => 420,
                        ],
                    ]],
                ]],
            ], 200),
        ]);

        $draftResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'antar ke polines',
                'service_type' => 'antar_jemput',
                'session_id' => 'sess-ride-04',
            ]);

        $draftResponse
            ->assertOk()
            ->assertJsonPath('data.ride.ready_to_confirm', true)
            ->assertJsonPath('data.ride.destination_latitude', -7.052301)
            ->assertJsonPath('data.ride.destination_longitude', 110.435601);

        $confirmResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'konfirmasi',
                'service_type' => 'antar_jemput',
                'session_id' => 'sess-ride-04',
            ]);

        $confirmResponse
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order.created', true);

        $orderId = (int) $confirmResponse->json('data.order.id');

        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'location_role' => 'PICKUP',
            'latitude' => -7.05090000,
            'longitude' => 110.43150000,
        ]);

        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'location_role' => 'DROPOFF',
            'latitude' => -7.05230100,
            'longitude' => 110.43560100,
        ]);

        $this->assertSame(1, $polinesGeocodeCalls, 'Destination geocode should run once during draft only.');
    }

    public function test_chatbot_ride_reset_destination_invalidates_draft(): void
    {
        $this->fakeGeminiAndGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089900000002',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot-ride')->plainTextToken;

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'antar ke polines',
                'service_type' => 'antar_jemput',
                'session_id' => 'sess-ride-02',
            ])
            ->assertOk()
            ->assertJsonPath('data.ride.ready_to_confirm', true);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'ubah tujuan',
                'service_type' => 'antar_jemput',
                'session_id' => 'sess-ride-02',
            ])
            ->assertOk()
            ->assertJsonPath('data.ride.ready_to_confirm', false)
            ->assertJsonPath('data.order.created', false);

        $confirmResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'konfirmasi',
                'service_type' => 'antar_jemput',
                'session_id' => 'sess-ride-02',
            ]);

        $confirmResponse
            ->assertOk()
            ->assertJsonPath('data.order.created', false);

        $this->assertStringContainsString(
            'Belum ada draft antar jemput yang siap dikonfirmasi',
            (string) $confirmResponse->json('data.assistant_text')
        );

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_chatbot_ride_requests_profile_address_when_missing(): void
    {
        $this->fakeGeminiAndGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089900000003',
        ]);

        $token = $user->createToken('test-chatbot-ride')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'antar ke polines',
                'service_type' => 'antar_jemput',
                'session_id' => 'sess-ride-03',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.intent', 'ride_order')
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('data.order.created', false);

        $nextActions = $response->json('data.validation.next_actions') ?? [];
        $this->assertContains('OPEN_ADDRESSES', $nextActions);
        $this->assertContains('OPEN_MAP_PICKER_PICKUP', $nextActions);
    }

    public function test_chatbot_ride_destination_message_is_not_misread_as_confirm_command(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089900000010',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot-ride')->plainTextToken;

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"intent":"ride_order","command":"confirm","destination_address":"Mie Gacoan Setiabudi Semarang","notes":null}',
                        ]],
                    ],
                ]],
            ], 200),
            'https://maps.googleapis.com/maps/api/geocode/*' => function ($request) {
                $queryString = parse_url($request->url(), PHP_URL_QUERY) ?? '';
                parse_str($queryString, $query);

                $address = strtolower(trim((string) ($query['address'] ?? '')));

                if (str_contains($address, 'melati')) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Jl. Melati No. 3, Banyumanik, Kota Semarang, Jawa Tengah, Indonesia',
                            'geometry' => [
                                'location' => [
                                    'lat' => -7.050900,
                                    'lng' => 110.431500,
                                ],
                            ],
                        ]],
                    ], 200);
                }

                if (str_contains($address, 'gacoan')) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Mie Gacoan Setiabudi, Semarang, Jawa Tengah, Indonesia',
                            'geometry' => [
                                'location' => [
                                    'lat' => -7.047339,
                                    'lng' => 110.420782,
                                ],
                            ],
                        ]],
                    ], 200);
                }

                return Http::response([
                    'status' => 'ZERO_RESULTS',
                    'results' => [],
                ], 200);
            },
            'https://maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
                'status' => 'OK',
                'rows' => [[
                    'elements' => [[
                        'status' => 'OK',
                        'distance' => [
                            'text' => '2.2 km',
                            'value' => 2200,
                        ],
                        'duration' => [
                            'text' => '9 mins',
                            'value' => 540,
                        ],
                    ]],
                ]],
            ], 200),
        ]);

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'antarkan aku ke gacoan setiabudi',
                'service_type' => 'antar_jemput',
                'session_id' => 'sess-ride-nlu-guard',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'ride_order')
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.order.created', false)
            ->assertJsonPath('data.ride.ready_to_confirm', true);

        $this->assertStringContainsString(
            'Ketik "Konfirmasi"',
            (string) $response->json('data.assistant_text')
        );

        $this->assertDatabaseCount('orders', 0);
    }

    private function fakeGeminiAndGeocoding(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => function ($request) {
                $body = $request->data();
                $rawMessage = (string) data_get($body, 'contents.0.parts.0.text', '');
                $normalized = strtolower(trim($rawMessage));

                if ($normalized === 'konfirmasi') {
                    $json = '{"intent":"ride_order","command":"confirm","destination_address":null,"notes":null}';
                } elseif ($normalized === 'ubah tujuan') {
                    $json = '{"intent":"ride_order","command":"reset_destination","destination_address":null,"notes":null}';
                } else {
                    $json = '{"intent":"ride_order","command":"none","destination_address":"Polines Semarang","notes":null}';
                }

                return Http::response([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    ['text' => $json],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            },
            'https://maps.googleapis.com/maps/api/geocode/*' => function ($request) {
                $queryString = parse_url($request->url(), PHP_URL_QUERY) ?? '';
                parse_str($queryString, $query);

                $address = strtolower(trim((string) ($query['address'] ?? '')));

                if (str_contains($address, 'melati')) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Jl. Melati No. 3, Banyumanik, Kota Semarang, Jawa Tengah, Indonesia',
                            'geometry' => [
                                'location' => [
                                    'lat' => -7.050900,
                                    'lng' => 110.431500,
                                ],
                            ],
                        ]],
                    ], 200);
                }

                if (
                    str_contains($address, 'polines') ||
                    str_contains($address, 'prof. soedarto') ||
                    str_contains($address, 'tembalang')
                ) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Jl. Prof. Soedarto, Tembalang, Kota Semarang, Jawa Tengah, Indonesia',
                            'geometry' => [
                                'location' => [
                                    'lat' => -7.052301,
                                    'lng' => 110.435601,
                                ],
                            ],
                        ]],
                    ], 200);
                }

                return Http::response([
                    'status' => 'ZERO_RESULTS',
                    'results' => [],
                ], 200);
            },
            'https://maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
                'status' => 'OK',
                'rows' => [[
                    'elements' => [[
                        'status' => 'OK',
                        'distance' => [
                            'text' => '1.3 km',
                            'value' => 1300,
                        ],
                        'duration' => [
                            'text' => '7 mins',
                            'value' => 420,
                        ],
                    ]],
                ]],
            ], 200),
        ]);
    }

    private function createDefaultAddress(User $user): void
    {
        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => $user->name,
            'phone' => $user->phone,
            'full_address' => 'Jl. Melati No. 3, Semarang',
            'detail' => null,
            'latitude' => -7.05090000,
            'longitude' => 110.43150000,
            'is_default' => true,
        ]);
    }
}
