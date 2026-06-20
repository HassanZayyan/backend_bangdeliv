<?php

namespace Tests\Feature;

use App\Events\DriverOrderAvailable;
use App\Models\Address;
use App\Models\AiChatLog;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
        $this->assertStringNotContainsString('Ketik "Konfirmasi"', $draftMessage);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('courier_order_details', 0);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'COD',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-01',
            ])
            ->assertOk()
            ->assertJsonPath('data.courier.payment_method', 'COD');

        $driverUser = User::factory()->create([
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'H 1234 CRT',
            'registration_status' => 'active',
            'status' => 'available',
        ]));
        Event::fake([DriverOrderAvailable::class]);

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
        Event::assertDispatched(DriverOrderAvailable::class, function (DriverOrderAvailable $event) use ($driverUser, $orderId): bool {
            return (int) $event->driverUserId === (int) $driverUser->id
                && (int) ($event->order['id'] ?? 0) === $orderId;
        });
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('courier_order_details', [
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
        $this->assertStringContainsString('Estimasi ongkir sementara:', $finalMessage);
    }

    public function test_chatbot_kurir_requests_profile_address_when_missing(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '081111111118',
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'kirim ke polines, isi paket: kunci',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-no-address',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'courier_order')
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('data.order.created', false)
            ->assertJsonPath('data.action_payloads.OPEN_ADDRESSES.label', 'Isi Alamat Saya');

        $this->assertSame(['OPEN_ADDRESSES'], $response->json('data.validation.next_actions'));
    }

    public function test_chatbot_kurir_treats_zero_coordinate_address_as_missing(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '081111111119',
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

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'kirim ke polines, isi paket: kunci',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-zero-address',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('data.validation.next_actions.0', 'OPEN_ADDRESSES');

        $this->assertSame(['OPEN_ADDRESSES'], $response->json('data.validation.next_actions'));
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
        $this->assertDatabaseCount('courier_order_details', 0);
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
        $this->assertDatabaseCount('courier_order_details', 0);
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
        $this->assertDatabaseCount('courier_order_details', 0);
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
                    'payment_method' => 'COD',
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
                    'payment_method' => 'COD',
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
        $this->assertDatabaseCount('courier_order_details', 1);
        $order = Order::query()->firstOrFail();
        $this->assertIsArray($order->route_snapshot);
        $this->assertSame('distance_matrix', $order->route_snapshot['route_provider'] ?? null);
        $this->assertSame(1600, $order->route_snapshot['distance_meters'] ?? null);
    }

    public function test_chatbot_kurir_rejects_prohibited_package(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '086666666666',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'kirim ke polines, isi paket: bensin 1 liter',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-prohibited',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'courier_order')
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('data.courier.safety_status', 'PROHIBITED')
            ->assertJsonPath('data.order.created', false);

        $this->assertStringContainsString(
            'kategori terlarang',
            (string) $response->json('data.assistant_text')
        );
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('courier_order_details', 0);
    }

    public function test_chatbot_kurir_allows_oversize_package_with_warning_flags(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '087777777777',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'kirim ke polines, isi paket: kasur lipat',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-oversize',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.courier.safety_status', 'ALLOWED')
            ->assertJsonPath('data.courier.size_class', 'OVERSIZE')
            ->assertJsonPath('data.courier.ready_to_confirm', true)
            ->assertJsonPath('data.order.created', false);

        $this->assertContains('OVERSIZE_FURNITURE', $response->json('data.courier.safety_flags'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('courier_order_details', 0);
    }

    public function test_chatbot_kurir_requires_clarification_for_ambiguous_package(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '088888888888',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'kirim ke polines, isi paket: paket',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-ambiguous',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('data.courier.safety_status', 'NEEDS_CLARIFICATION')
            ->assertJsonPath('data.order.created', false);

        $this->assertStringContainsString(
            'Isi paket belum spesifik',
            (string) $response->json('data.assistant_text')
        );
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('courier_order_details', 0);
    }

    public function test_chatbot_kurir_allows_small_common_item_without_weight_or_size(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089111111111',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'aku mau antar kacamata papaku yang ketinggalan ke erha setiabudi tembalang',
                'service_type' => 'kurir',
                'session_id' => 'sess-kurir-small-item',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.intent', 'courier_order')
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.courier.ready_to_confirm', true);
        $this->assertStringContainsString('kacamata', strtolower((string) $response->json('data.courier.package_description')));
        $this->assertStringContainsString('Erha Setiabudi', (string) $response->json('data.courier.dropoff_address'));
    }

    public function test_chatbot_kurir_asks_map_pin_when_text_geocode_resolves_too_far(): void
    {
        $this->fakeGeocodingWithAmbiguousFarDropoff();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089222222222',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot')->plainTextToken;
        $sessionId = 'sess-kurir-map-required';

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'kirim ke erha setiabudi tembalang, isi paket: kacamata',
                'service_type' => 'kurir',
                'session_id' => $sessionId,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('data.courier.ready_to_confirm', false)
            ->assertJsonPath('data.validation.missing_fields.0', 'dropoff_address')
            ->assertJsonPath('data.validation.next_actions.0', 'OPEN_ROUTE_PICKER')
            ->assertJsonPath('data.action_payloads.OPEN_ROUTE_PICKER.label', 'Atur Titik Ambil & Tujuan');

        $this->assertStringContainsString('belum pas di peta', (string) $response->json('data.assistant_text'));
        $this->assertStringNotContainsString('Format cepat', (string) $response->json('data.assistant_text'));

        $pinResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/location', [
                'service_type' => 'kurir',
                'target' => 'dropoff',
                'latitude' => -7.052301,
                'longitude' => 110.435601,
                'address' => 'Erha Setiabudi Tembalang',
            ]);

        $pinResponse
            ->assertOk()
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.courier.ready_to_confirm', true)
            ->assertJsonPath('data.validation.next_actions.0', 'SET_PAYMENT_COD');
    }

    public function test_chatbot_kurir_can_build_draft_from_map_pins_before_chat(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089333333333',
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;
        $sessionId = 'sess-kurir-map-first';

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/location', [
                'service_type' => 'kurir',
                'target' => 'pickup',
                'latitude' => -7.328900,
                'longitude' => 110.500100,
                'address' => 'Ramayan Salatiga',
            ])
            ->assertOk()
            ->assertJsonPath('data.order.created', false)
            ->assertJsonPath('data.courier.ready_to_confirm', false)
            ->assertJsonPath('data.courier.pickup_address', 'Ramayan Salatiga');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/location', [
                'service_type' => 'kurir',
                'target' => 'dropoff',
                'latitude' => -7.331200,
                'longitude' => 110.507700,
                'address' => 'Lapangan Pancasila Salatiga',
            ])
            ->assertOk()
            ->assertJsonPath('data.order.created', false)
            ->assertJsonPath('data.courier.ready_to_confirm', false)
            ->assertJsonPath('data.courier.dropoff_address', 'Lapangan Pancasila Salatiga');

        $draftResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'kunci',
                'service_type' => 'kurir',
                'session_id' => $sessionId,
            ]);

        $draftResponse
            ->assertOk()
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.courier.ready_to_confirm', true)
            ->assertJsonPath('data.courier.package_description', 'kunci')
            ->assertJsonPath('data.courier.pickup_latitude', -7.3289)
            ->assertJsonPath('data.courier.dropoff_latitude', -7.3312);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'COD',
                'service_type' => 'kurir',
                'session_id' => $sessionId,
            ])
            ->assertOk()
            ->assertJsonPath('data.courier.payment_method', 'COD');

        $confirmResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'Konfirmasi',
                'service_type' => 'kurir',
                'session_id' => $sessionId,
            ]);

        $confirmResponse
            ->assertOk()
            ->assertJsonPath('data.order.created', true);

        $orderId = (int) $confirmResponse->json('data.order.id');

        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'location_role' => 'PICKUP',
            'latitude' => -7.32890000,
            'longitude' => 110.50010000,
        ]);
        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'location_role' => 'DROPOFF',
            'latitude' => -7.33120000,
            'longitude' => 110.50770000,
        ]);
    }

    public function test_chatbot_kurir_bulk_route_patch_then_isi_paket_sabun_is_ready_and_confirmable(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089333333334',
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;
        $sessionId = 'sess-kurir-route-bulk';

        $routeResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/locations', [
                'service_type' => 'kurir',
                'locations' => [
                    [
                        'target' => 'pickup',
                        'latitude' => -7.328900,
                        'longitude' => 110.500100,
                        'address' => 'Ramayan Salatiga',
                    ],
                    [
                        'target' => 'dropoff',
                        'latitude' => -7.331200,
                        'longitude' => 110.507700,
                        'address' => 'Lapangan Pancasila Salatiga',
                    ],
                ],
            ]);

        $routeResponse
            ->assertOk()
            ->assertJsonPath('model_used', 'map-route-action')
            ->assertJsonPath('data.courier.ready_to_confirm', false)
            ->assertJsonPath('data.validation.missing_fields.0', 'package_description');

        $this->assertStringContainsString(
            'Barang apa yang mau dikirim',
            (string) $routeResponse->json('data.assistant_text')
        );

        $draftResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'isi paket sabun',
                'service_type' => 'kurir',
                'session_id' => $sessionId,
            ]);

        $draftResponse
            ->assertOk()
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.courier.ready_to_confirm', true)
            ->assertJsonPath('data.courier.package_description', 'sabun')
            ->assertJsonPath('data.courier.pickup_latitude', -7.3289)
            ->assertJsonPath('data.courier.dropoff_latitude', -7.3312);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'COD',
                'service_type' => 'kurir',
                'session_id' => $sessionId,
            ])
            ->assertOk()
            ->assertJsonPath('data.courier.payment_method', 'COD');

        $confirmResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'konfirmasi',
                'service_type' => 'kurir',
                'session_id' => $sessionId,
            ]);

        $confirmResponse
            ->assertOk()
            ->assertJsonPath('data.order.created', true);
    }

    public function test_chatbot_kurir_bulk_route_patch_rejects_overlapping_points(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089333333335',
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;
        $sessionId = 'sess-kurir-route-too-close';

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/locations', [
                'service_type' => 'kurir',
                'locations' => [
                    [
                        'target' => 'pickup',
                        'latitude' => -7.328900,
                        'longitude' => 110.500100,
                        'address' => 'Ramayan Salatiga',
                    ],
                    [
                        'target' => 'dropoff',
                        'latitude' => -7.328900,
                        'longitude' => 110.500100,
                        'address' => 'Ramayan Salatiga',
                    ],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('model_used', 'map-route-action')
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('data.courier.ready_to_confirm', false);

        $this->assertContains(
            'Titik tujuan terlalu dekat dengan titik jemput. Pilih titik tujuan yang berbeda.',
            $response->json('data.validation.rejection_reasons')
        );

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_chatbot_kurir_short_package_completion_after_route_patch_is_ready(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089333333336',
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;
        $sessionId = 'sess-kurir-route-short-package';

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/locations', [
                'service_type' => 'kurir',
                'locations' => [
                    [
                        'target' => 'pickup',
                        'latitude' => -7.328900,
                        'longitude' => 110.500100,
                        'address' => 'Ramayan Salatiga',
                    ],
                    [
                        'target' => 'dropoff',
                        'latitude' => -7.331200,
                        'longitude' => 110.507700,
                        'address' => 'Lapangan Pancasila Salatiga',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.courier.ready_to_confirm', false);

        $draftResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'sabun',
                'service_type' => 'kurir',
                'session_id' => $sessionId,
            ]);

        $draftResponse
            ->assertOk()
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.courier.ready_to_confirm', true)
            ->assertJsonPath('data.courier.package_description', 'sabun');
    }

    public function test_chatbot_kurir_ignores_gemini_confirm_when_message_contains_package_text(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"intent":"courier_order","command":"confirm","pickup_address":"Ramayan Salatiga","dropoff_address":"Lapangan Pancasila Salatiga","package_description":null}',
                        ]],
                    ],
                ]],
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
            'phone' => '089333333335',
        ]);

        $sessionId = 'sess-kurir-partial-gemini';
        AiChatLog::query()->create([
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'message' => 'Lengkapi isi paket.',
            'ai_response' => [
                'intent' => 'courier_order',
                'service_type' => 'kurir',
                'courier' => [
                    'pickup_address' => 'Ramayan Salatiga',
                    'pickup_latitude' => -7.328900,
                    'pickup_longitude' => 110.500100,
                    'dropoff_address' => 'Lapangan Pancasila Salatiga',
                    'dropoff_latitude' => -7.331200,
                    'dropoff_longitude' => 110.507700,
                    'package_description' => null,
                    'ready_to_confirm' => false,
                    'used_default_pickup' => false,
                ],
                'validation' => [
                    'is_valid_order' => false,
                    'rejection_reasons' => ['Isi paket belum jelas.'],
                    'missing_fields' => ['package_description'],
                    'next_actions' => [],
                ],
                'order' => [
                    'created' => false,
                    'id' => null,
                    'order_number' => null,
                    'delivery_fee' => 5000,
                ],
            ],
            'model_used' => 'map-route-action',
            'intent' => 'courier_order',
            'order_id' => null,
        ]);

        $token = $user->createToken('test-chatbot')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'isi paket sabun',
                'service_type' => 'kurir',
                'session_id' => $sessionId,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.courier.ready_to_confirm', true)
            ->assertJsonPath('data.courier.package_description', 'sabun')
            ->assertJsonPath('data.order.created', false);
    }

    public function test_chatbot_kurir_reset_destination_preserves_package_for_map_replacement(): void
    {
        $this->fakeGeocoding();

        $user = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'phone' => '089444444444',
        ]);

        $this->createDefaultAddress($user);

        $token = $user->createToken('test-chatbot')->plainTextToken;
        $sessionId = 'sess-kurir-reset-map';

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'kirim ke polines, isi paket: kunci',
                'service_type' => 'kurir',
                'session_id' => $sessionId,
            ])
            ->assertOk()
            ->assertJsonPath('data.courier.ready_to_confirm', true);

        $resetResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/process', [
                'message' => 'Ubah Tujuan',
                'service_type' => 'kurir',
                'session_id' => $sessionId,
            ]);

        $resetResponse
            ->assertOk()
            ->assertJsonPath('data.courier.ready_to_confirm', false)
            ->assertJsonPath('data.courier.package_description', 'kunci')
            ->assertJsonPath('data.courier.dropoff_address', null);

        $pinResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/chatbot/sessions/'.$sessionId.'/location', [
                'service_type' => 'kurir',
                'target' => 'dropoff',
                'latitude' => -7.331200,
                'longitude' => 110.507700,
                'address' => 'Lapangan Pancasila Salatiga',
            ]);

        $pinResponse
            ->assertOk()
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.courier.ready_to_confirm', true)
            ->assertJsonPath('data.courier.package_description', 'kunci')
            ->assertJsonPath('data.courier.dropoff_address', 'Lapangan Pancasila Salatiga');
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

                if (str_contains($address, 'erha setiabudi')) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Erha Setiabudi Tembalang, Jl. Setiabudi, Kota Semarang, Jawa Tengah 50263, Indonesia',
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

    private function fakeGeocodingWithAmbiguousFarDropoff(): void
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

                if (isset($query['latlng'])) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'Erha Setiabudi Tembalang, Jl. Setiabudi, Kota Semarang, Jawa Tengah 50263, Indonesia',
                            'geometry' => [
                                'location' => [
                                    'lat' => -7.052301,
                                    'lng' => 110.435601,
                                ],
                            ],
                        ]],
                    ], 200);
                }

                $address = strtolower(trim((string) ($query['address'] ?? '')));
                if (str_contains($address, 'erha setiabudi')) {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [[
                            'formatted_address' => 'ERHA Setiabudi, Jakarta Selatan, DKI Jakarta, Indonesia',
                            'geometry' => [
                                'location' => [
                                    'lat' => -6.221000,
                                    'lng' => 106.832000,
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
            'https://maps.googleapis.com/maps/api/distancematrix/*' => function ($request) {
                $queryString = parse_url($request->url(), PHP_URL_QUERY) ?? '';
                parse_str($queryString, $query);
                $destinations = (string) ($query['destinations'] ?? '');
                $isSemarangPin = str_contains($destinations, '-7.05230100,110.43560100');

                return Http::response([
                    'status' => 'OK',
                    'rows' => [[
                        'elements' => [[
                            'status' => 'OK',
                            'distance' => [
                                'text' => $isSemarangPin ? '1.6 km' : '450.8 km',
                                'value' => $isSemarangPin ? 1600 : 450810,
                            ],
                            'duration' => [
                                'text' => $isSemarangPin ? '8 mins' : '8 hours',
                                'value' => $isSemarangPin ? 480 : 28800,
                            ],
                        ]],
                    ]],
                ], 200);
            },
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
