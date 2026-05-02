<?php

namespace Tests\Feature;

use App\Models\AiChatLog;
use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotCourierFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bangdeliv.google_maps_api_key' => 'test-google-maps-key',
        ]);
    }

    public function test_chatbot_kurir_requires_draft_before_confirmation(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '081111111111',
        ]);

        $this->createDefaultAddress($user);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => $user->name,
            'phone' => $user->phone,
            'full_address' => 'Jl. Melati No. 3, Jakarta',
            'detail' => 'Pagar hitam',
            'latitude' => -6.20550000,
            'longitude' => 106.82400000,
            'is_default' => true,
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'kirim ke polines, isi paket: ijazah',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-01',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.intent', 'courier_order')
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.order.created', false)
            ->assertJsonPath('data.courier.ready_to_confirm', true);

        $draftMessage = (string) $response->json('data.assistant_text');
        $this->assertStringContainsString('Estimasi ongkir sementara', $draftMessage);
        $this->assertStringContainsString('Ketik "Konfirmasi"', $draftMessage);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('courier_orders', 0);

        $confirmResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'Konfirmasi',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-01',
            ]);

        $confirmResponse
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order.created', true);

        $orderId = (int) $confirmResponse->json('data.order.id');

        $this->assertGreaterThan(0, $orderId);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('courier_orders', [
            'order_id' => $orderId,
            'package_description' => 'ijazah',
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $orderId,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
        ]);

        $finalMessage = (string) $confirmResponse->json('data.assistant_text');
        $this->assertStringContainsString('Nomor order:', $finalMessage);
        $this->assertStringContainsString('Ambil:', $finalMessage);
        $this->assertStringContainsString('Tujuan:', $finalMessage);
        $this->assertStringContainsString('Barang:', $finalMessage);
        $this->assertStringContainsString('Ongkir:', $finalMessage);
    }

    public function test_chatbot_kurir_uses_profile_pickup_for_rumah_alias(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '082222222222',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'ambil di rumah, kirim ke polines, isi paket: ijazah',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-02',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.intent', 'courier_order')
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.order.created', false);

        $pickup = (string) $response->json('data.courier.pickup_address');
        $this->assertStringContainsString('Jl. Melati No. 3', $pickup);
        $this->assertStringNotContainsStringIgnoringCase('rumah', $pickup);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('courier_orders', 0);
    }

    public function test_chatbot_kurir_resolves_named_locations_to_full_addresses(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '083333333333',
        ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => $user->name,
            'phone' => $user->phone,
            'full_address' => 'Sraten, Karanganyar',
            'detail' => 'Gang 1',
            'latitude' => -7.56100000,
            'longitude' => 110.82000000,
            'is_default' => true,
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'ambil di simpang lima, kirim ke polines, isi paket: dokumen',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-03',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order.created', false)
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.courier.package_description', 'dokumen');

        $pickup = (string) $response->json('data.courier.pickup_address');
        $dropoff = (string) $response->json('data.courier.dropoff_address');

        $this->assertStringContainsString('Kota Semarang', $pickup);
        $this->assertStringContainsString('Indonesia', $pickup);
        $this->assertStringContainsString('Kota Semarang', $dropoff);
        $this->assertStringContainsString('Indonesia', $dropoff);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('courier_orders', 0);
    }

    public function test_chatbot_kurir_reset_destination_invalidates_previous_draft(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '084444444444',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'kirim ke polines, isi paket: ijazah',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-04',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order.created', false)
            ->assertJsonPath('data.courier.ready_to_confirm', true);

        $resetResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'Ubah Tujuan',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-04',
            ]);

        $resetResponse
            ->assertOk()
            ->assertJsonPath('data.order.created', false)
            ->assertJsonPath('data.courier.ready_to_confirm', false);

        $confirmResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'Konfirmasi',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-04',
            ]);

        $confirmResponse
            ->assertOk()
            ->assertJsonPath('data.order.created', false);

        $this->assertStringContainsString(
            'Belum ada draft pengiriman yang siap dikonfirmasi',
            (string) $confirmResponse->json('data.assistant_text')
        );

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('courier_orders', 0);
    }

    public function test_chatbot_kurir_confirm_uses_fast_path_without_gemini_call(): void
    {
        Http::preventStrayRequests();
        Http::fake([
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
            'phone' => '085555555555',
        ]);

        $sessionId = 'sess-kurir-fast-confirm';

        AiChatLog::query()->create([
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'message' => 'Draft kurir siap dikonfirmasi.',
            'ai_response' => [
                'intent' => 'courier_order',
                'service_type' => 'kurir',
                'courier' => [
                    'pickup_address' => 'Jl. Melati No. 3, Kelurahan Pedalangan, Kecamatan Banyumanik, Kota Semarang, Jawa Tengah 50268, Indonesia',
                    'dropoff_address' => 'Jl. Prof. Soedarto, Tembalang, Kecamatan Tembalang, Kota Semarang, Jawa Tengah 50275, Indonesia',
                    'package_description' => 'dokumen',
                    'pickup_latitude' => -7.050900,
                    'pickup_longitude' => 110.431500,
                    'dropoff_latitude' => -7.052301,
                    'dropoff_longitude' => 110.435601,
                    'distance_km' => 1.2,
                    'ready_to_confirm' => true,
                    'used_default_pickup' => false,
                    'pickup_address_id' => null,
                ],
                'validation' => [
                    'is_valid_order' => true,
                    'rejection_reasons' => [],
                    'missing_fields' => [],
                    'next_actions' => [],
                ],
                'order' => [
                    'created' => false,
                    'id' => null,
                    'order_number' => null,
                    'delivery_fee' => 5000,
                ],
                'assistant_text' => 'Ketik "Konfirmasi" untuk membuat order.',
            ],
            'model_used' => 'deterministic-command',
            'intent' => 'courier_order',
            'order_id' => null,
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'Konfirmasi',
                'service_type' => 'kurir',
                'session_id' => $sessionId,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order.created', true)
            ->assertJsonPath('model_used', 'deterministic-command');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('courier_orders', 1);
    }

    private function fakeGeocoding(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 503,
                    'message' => 'Gemini disabled in courier flow tests',
                    'status' => 'UNAVAILABLE',
                ],
            ], 503),
            'https://maps.googleapis.com/maps/api/geocode/*' => function ($request) {
                $queryString = parse_url($request->url(), PHP_URL_QUERY) ?? '';
                parse_str($queryString, $query);

                $address = strtolower(trim((string) ($query['address'] ?? '')));

                if (str_contains($address, 'melati')) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Jl. Melati No. 3, Kelurahan Pedalangan, Kecamatan Banyumanik, Kota Semarang, Jawa Tengah 50268, Indonesia',
                            'geometry' => [
                                'location' => [
                                    'lat' => -7.050900,
                                    'lng' => 110.431500,
                                ],
                            ],
                        ]],
                    ], 200);
                }

                if (str_contains($address, 'simpang lima')) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Simpang Lima, Pleburan, Kecamatan Semarang Selatan, Kota Semarang, Jawa Tengah 50241, Indonesia',
                            'geometry' => [
                                'location' => [
                                    'lat' => -6.993200,
                                    'lng' => 110.420300,
                                ],
                            ],
                        ]],
                    ], 200);
                }

                if (str_contains($address, 'polines')) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Jl. Prof. Soedarto, Tembalang, Kecamatan Tembalang, Kota Semarang, Jawa Tengah 50275, Indonesia',
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
