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

    public function test_chatbot_ride_with_saved_address_offers_route_picker_when_destination_missing(): void
    {
        $this->fakeGeminiAndGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089900000101',
        ]);

        $this->createDefaultAddress($user);
        $token = $user->createToken('test-chatbot-ride')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'konfirmasi',
                'service_type' => 'antar_jemput',
                'session_id' => 'sess-ride-route-action',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'ride_order')
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('data.validation.next_actions.0', 'OPEN_ROUTE_PICKER')
            ->assertJsonPath('data.action_payloads.OPEN_ROUTE_PICKER.label', 'Atur Titik Jemput & Tujuan');
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
        $this->assertSame(['OPEN_ADDRESSES'], $nextActions);
        $this->assertSame('Isi Alamat Saya', $response->json('data.action_payloads.OPEN_ADDRESSES.label'));
    }

    public function test_chatbot_ride_treats_zero_coordinate_address_as_missing(): void
    {
        $this->fakeGeminiAndGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089900000023',
        ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => $user->name,
            'phone' => $user->phone,
            'full_address' => 'Jl. Koordinat Nol',
            'latitude' => 0,
            'longitude' => 0,
            'is_default' => true,
        ]);

        $token = $user->createToken('test-chatbot-ride')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'antar ke polines',
                'service_type' => 'antar_jemput',
                'session_id' => 'sess-ride-zero-address',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('data.validation.next_actions.0', 'OPEN_ADDRESSES');

        $this->assertSame(['OPEN_ADDRESSES'], $response->json('data.validation.next_actions'));
    }

    public function test_chatbot_ride_can_build_draft_from_destination_map_pin_before_chat(): void
    {
        $this->fakeGeminiAndGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089900000011',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot-ride')->plainTextToken;
        $sessionId = 'sess-ride-map-first';

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/location', [
                'service_type' => 'antar_jemput',
                'target' => 'destination',
                'latitude' => -7.052301,
                'longitude' => 110.435601,
                'address' => 'Polines Semarang',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'ride_order')
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.ride.ready_to_confirm', true)
            ->assertJsonPath('data.ride.destination_address', 'Polines Semarang')
            ->assertJsonPath('data.ride.destination_latitude', -7.052301);
    }

    public function test_chatbot_ride_bulk_route_patch_builds_confirmable_draft(): void
    {
        $this->fakeGeminiAndGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089900000111',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot-ride')->plainTextToken;
        $sessionId = 'sess-ride-route-bulk';

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/locations', [
                'service_type' => 'antar_jemput',
                'locations' => [
                    [
                        'target' => 'pickup',
                        'latitude' => -7.328900,
                        'longitude' => 110.500100,
                        'address' => 'Ramayan Salatiga',
                    ],
                    [
                        'target' => 'destination',
                        'latitude' => -7.331200,
                        'longitude' => 110.507700,
                        'address' => 'Lapangan Pancasila Salatiga',
                    ],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('model_used', 'map-route-action')
            ->assertJsonPath('data.intent', 'ride_order')
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.ride.ready_to_confirm', true)
            ->assertJsonPath('data.ride.pickup_address', 'Ramayan Salatiga')
            ->assertJsonPath('data.ride.destination_address', 'Lapangan Pancasila Salatiga');
    }

    public function test_chatbot_ride_bulk_route_patch_without_address_uses_reverse_geocoded_label(): void
    {
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/*' => function ($request) {
                $queryString = parse_url($request->url(), PHP_URL_QUERY) ?? '';
                parse_str($queryString, $query);
                $latLng = (string) ($query['latlng'] ?? '');

                if (str_contains($latLng, '-7.328900')) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Ramayan Salatiga, Jl. Diponegoro, Kota Salatiga, Jawa Tengah, Indonesia',
                            'geometry' => [
                                'location' => [
                                    'lat' => -7.328900,
                                    'lng' => 110.500100,
                                ],
                            ],
                        ]],
                    ], 200);
                }

                if (str_contains($latLng, '-7.331200')) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Lapangan Pancasila Salatiga, Jl. Ahmad Yani, Kota Salatiga, Jawa Tengah, Indonesia',
                            'geometry' => [
                                'location' => [
                                    'lat' => -7.331200,
                                    'lng' => 110.507700,
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
            'https://maps.googleapis.com/maps/api/place/nearbysearch/*' => Http::response([
                'status' => 'ZERO_RESULTS',
                'results' => [],
            ], 200),
            'https://maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
                'status' => 'OK',
                'rows' => [[
                    'elements' => [[
                        'status' => 'OK',
                        'distance' => [
                            'text' => '1.6 km',
                            'value' => 1600,
                        ],
                        'duration' => [
                            'text' => '8 mins',
                            'value' => 480,
                        ],
                    ]],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089900000112',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot-ride')->plainTextToken;
        $sessionId = 'sess-ride-route-reverse-label';

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/locations', [
                'service_type' => 'antar_jemput',
                'locations' => [
                    [
                        'target' => 'pickup',
                        'latitude' => -7.328900,
                        'longitude' => 110.500100,
                    ],
                    [
                        'target' => 'destination',
                        'latitude' => -7.331200,
                        'longitude' => 110.507700,
                    ],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.ride.ready_to_confirm', true);

        $destination = (string) $response->json('data.ride.destination_address');
        $this->assertStringContainsString('Lapangan Pancasila Salatiga', $destination);
        $this->assertStringNotContainsString('Pin -7.', $destination);
    }

    public function test_chatbot_ride_reset_destination_preserves_custom_map_pickup(): void
    {
        $this->fakeGeminiAndGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089900000012',
        ]);

        $token = $user->createToken('test-chatbot-ride')->plainTextToken;
        $sessionId = 'sess-ride-custom-pickup-reset';

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/location', [
                'service_type' => 'antar_jemput',
                'target' => 'pickup',
                'latitude' => -7.328900,
                'longitude' => 110.500100,
                'address' => 'Ramayan Salatiga',
            ])
            ->assertOk()
            ->assertJsonPath('data.ride.ready_to_confirm', false);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/location', [
                'service_type' => 'antar_jemput',
                'target' => 'destination',
                'latitude' => -7.331200,
                'longitude' => 110.507700,
                'address' => 'Lapangan Pancasila Salatiga',
            ])
            ->assertOk()
            ->assertJsonPath('data.ride.ready_to_confirm', true)
            ->assertJsonPath('data.ride.pickup_address', 'Ramayan Salatiga');

        $resetResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'Ubah Tujuan',
                'service_type' => 'antar_jemput',
                'session_id' => $sessionId,
            ]);

        $resetResponse
            ->assertOk()
            ->assertJsonPath('data.ride.ready_to_confirm', false)
            ->assertJsonPath('data.ride.pickup_address', 'Ramayan Salatiga')
            ->assertJsonPath('data.ride.destination_address', null);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/location', [
                'service_type' => 'antar_jemput',
                'target' => 'destination',
                'latitude' => -7.332300,
                'longitude' => 110.510800,
                'address' => 'Terminal Tingkir Salatiga',
            ])
            ->assertOk()
            ->assertJsonPath('data.ride.ready_to_confirm', true)
            ->assertJsonPath('data.ride.pickup_address', 'Ramayan Salatiga')
            ->assertJsonPath('data.ride.destination_address', 'Terminal Tingkir Salatiga');
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
