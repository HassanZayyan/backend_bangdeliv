<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Chatbot\ChatbotDraftStore;
use App\Services\Chatbot\ChatbotGeminiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatbotShoppingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatbot_shopping_without_saved_address_returns_open_addresses_action(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Restaurant::query()->create([
            'name' => 'Warung Madura Pak Ali',
            'slug' => 'warung-madura-no-address-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Warung No. 2',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000004',
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Warung Madura Pak Ali',
            'items' => [
                ['name' => 'Telur 1 kg', 'quantity' => 1],
            ],
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-no-address-session',
            'service_type' => 'nitip',
            'message' => 'titip telur 1 kg di Warung Madura Pak Ali',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', false)
            ->assertJsonPath('data.validation.missing_fields.0', 'delivery_address')
            ->assertJsonPath('data.validation.next_actions.0', 'OPEN_ADDRESSES');

        $assistantText = (string) $response->json('data.assistant_text');
        $this->assertStringContainsString('titik antar', $assistantText);
        $this->assertStringNotContainsString('delivery_address', $assistantText);
    }

    public function test_chatbot_shopping_treats_zero_coordinate_address_as_missing(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => $customer->name,
            'phone' => $customer->phone ?: '081200000099',
            'full_address' => 'Jl. Koordinat Nol',
            'latitude' => 0,
            'longitude' => 0,
            'is_default' => true,
        ]);

        Restaurant::query()->create([
            'name' => 'Warung Madura Koordinat Nol',
            'slug' => 'warung-madura-zero-address-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Warung No. 3',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000005',
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Warung Madura Koordinat Nol',
            'items' => [
                ['name' => 'Telur 1 kg', 'quantity' => 1],
            ],
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-zero-address-session',
            'service_type' => 'nitip',
            'message' => 'titip telur 1 kg di Warung Madura Koordinat Nol',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', false)
            ->assertJsonPath('data.validation.missing_fields.0', 'delivery_address')
            ->assertJsonPath('data.validation.next_actions.0', 'OPEN_ADDRESSES');
    }

    public function test_chatbot_shopping_without_merchant_returns_merchant_picker_action(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000011',
            'full_address' => 'Jl. Customer No. 11',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [
                ['name' => 'sembako', 'quantity' => 1],
            ],
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-missing-merchant-session',
            'service_type' => 'nitip',
            'message' => 'beli sembako',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', false)
            ->assertJsonPath('data.validation.missing_fields.0', 'merchant')
            ->assertJsonPath('data.validation.next_actions.0', 'OPEN_MERCHANT_PICKER')
            ->assertJsonPath('data.action_payloads.OPEN_MERCHANT_PICKER.label', 'Pilih Tempat di Map');

        $assistantText = (string) $response->json('data.assistant_text');
        $this->assertStringContainsString('tempat', $assistantText);
        $this->assertStringNotContainsString('merchant', $assistantText);
    }

    public function test_chatbot_shopping_rejects_delivery_point_outside_service_radius_before_merchant_selection(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/chatbot/sessions/shopping-delivery-outside-radius/location', [
            'service_type' => 'nitip',
            'target' => 'delivery',
            'latitude' => -6.175392,
            'longitude' => 106.827153,
            'address' => 'Monas Jakarta',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', false)
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('data.action_payloads.OPEN_MAP_PICKER_DELIVERY.label', 'Pilih Alamat Antar');

        $this->assertContains('delivery_address', $response->json('data.validation.missing_fields'));
        $this->assertSame(['OPEN_MAP_PICKER_DELIVERY'], $response->json('data.validation.next_actions'));
        $this->assertNotContains('OPEN_ADDRESSES', $response->json('data.validation.next_actions'));
        $this->assertStringContainsString(
            'melebihi batas layanan',
            implode(' ', $response->json('data.validation.rejection_reasons'))
        );
        $assistantText = (string) $response->json('data.assistant_text');
        $this->assertStringContainsString('melebihi batas layanan', $assistantText);
        $this->assertStringNotContainsString('Tempat belum dipilih', $assistantText);
        $this->assertStringNotContainsString('Item belanja', $assistantText);
        $this->assertStringNotContainsString('Tulis item', $assistantText);
    }

    public function test_chatbot_shopping_merchant_picker_guides_item_completion(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000013',
            'full_address' => 'Jl. Customer No. 13',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [],
        ]);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-merchant-picker-items-guide-session';

        $draftResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'halo',
        ]);
        $draftResponse->assertOk()
            ->assertJsonPath('data.validation.next_actions.0', 'OPEN_MERCHANT_PICKER');

        $merchantResponse = $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'merchant_place' => [
                'place_id' => 'google-place-kedai-tinari',
                'name' => 'Kedai Tinari',
                'address' => 'Jl. Kedai Tinari, Kota Semarang',
                'latitude' => -7.054932,
                'longitude' => 110.434739,
                'types' => ['restaurant', 'food'],
            ],
        ]);

        $merchantResponse->assertOk()
            ->assertJsonPath('model_used', 'merchant-picker-action')
            ->assertJsonPath('data.shopping.ready_to_confirm', false)
            ->assertJsonPath('data.validation.missing_fields.0', 'items')
            ->assertJsonPath('data.shopping.merchant.name', 'Kedai Tinari');

        $assistantText = (string) $merchantResponse->json('data.assistant_text');
        $this->assertStringContainsString('Tempat', $assistantText);
        $this->assertStringContainsString('Kedai Tinari', $assistantText);
        $this->assertStringContainsString('Tulis item dan jumlah untuk tempat ini.', $assistantText);
        $this->assertStringContainsString('- nasi goreng 1', $assistantText);
        $this->assertStringContainsString('- mie pedas level 7, 1', $assistantText);
        $this->assertStringContainsString('- minyak 500ml, 1', $assistantText);
        $this->assertStringContainsString('- sabun 1', $assistantText);
        $this->assertStringNotContainsString('- susu 1', $assistantText);
    }

    public function test_chatbot_shopping_merchant_picker_rejects_far_merchant_before_items(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000023',
            'full_address' => 'Jl. Customer No. 23',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [],
        ], 185150);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-merchant-picker-far-before-items-session';

        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'halo',
        ])->assertOk()
            ->assertJsonPath('data.validation.next_actions.0', 'OPEN_MERCHANT_PICKER');

        $merchantResponse = $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'merchant_place' => [
                'place_id' => 'google-place-too-far',
                'name' => 'Merchant Terlalu Jauh',
                'address' => 'Jl. Terlalu Jauh',
                'latitude' => -7.7061,
                'longitude' => 110.9061,
                'types' => ['restaurant', 'food'],
            ],
        ]);

        $merchantResponse->assertOk()
            ->assertJsonPath('model_used', 'merchant-picker-action')
            ->assertJsonPath('data.shopping.ready_to_confirm', false)
            ->assertJsonPath('data.validation.is_valid_order', false)
            ->assertJsonPath('data.validation.next_actions.0', 'OPEN_MERCHANT_PICKER')
            ->assertJsonPath('data.action_payloads.OPEN_MERCHANT_PICKER.mode', 'select');

        $this->assertContains(
            'merchant_distance',
            $merchantResponse->json('data.validation.missing_fields')
        );
        $this->assertStringContainsString(
            'melebihi batas layanan',
            (string) $merchantResponse->json('data.validation.rejection_reasons.0')
        );
        $assistantText = (string) $merchantResponse->json('data.assistant_text');
        $this->assertStringContainsString('melebihi batas layanan', $assistantText);
        $this->assertStringNotContainsString('Tulis item dan jumlah', $assistantText);
    }

    public function test_chatbot_shopping_allows_long_route_when_points_are_inside_service_radius(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000014',
            'full_address' => 'Jl. Customer No. 14',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        Restaurant::query()->create([
            'name' => 'Kedai Tinari',
            'slug' => 'kedai-tinari-route-limit-test',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Kedai Tinari',
            'latitude' => -7.054932,
            'longitude' => 110.434739,
            'phone' => '081200000015',
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Kedai Tinari',
            'items' => [
                ['name' => 'sembako', 'quantity' => 1],
            ],
        ], 185150);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-long-route-inside-radius-session',
            'service_type' => 'nitip',
            'message' => 'beli sembako di Kedai Tinari',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.validation.is_valid_order', true)
            ->assertJsonPath('data.shopping.route.distance_meters', 185150)
            ->assertJsonPath('data.validation.next_actions.0', 'OPEN_ADD_MERCHANT_PICKER');
    }

    public function test_chatbot_shopping_replace_merchant_targets_specific_stop_without_adding(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        Config::set('bangdeliv.routes.optimize_shopping_waypoints', false);

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000077',
            'full_address' => 'Jl. Customer No. 77',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $sate = Restaurant::query()->create([
            'name' => 'Sate Ayam Cak Sabari',
            'slug' => 'sate-ayam-cak-sabari-replace-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Sate No. 1',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000078',
        ]);
        $rendys = Restaurant::query()->create([
            'name' => "Rendy's Chicken",
            'slug' => 'rendys-chicken-replace-test',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Rendy No. 1',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000079',
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [],
        ], 1000);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-replace-merchant-session';

        // Toko #1: Sate Ayam Cak Sabari.
        $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'merchant_id' => $sate->id,
        ])->assertOk()
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Sate Ayam Cak Sabari');

        // Toko #2: Rendy's Chicken (mode add) -> jadi 2 toko.
        $addResponse = $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'mode' => 'add',
            'merchant_id' => $rendys->id,
        ]);
        $addResponse->assertOk()
            ->assertJsonCount(2, 'data.shopping.stops')
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Sate Ayam Cak Sabari')
            ->assertJsonPath('data.shopping.stops.1.merchant.name', "Rendy's Chicken");

        $sateStopId = (string) $addResponse->json('data.shopping.stops.0.stop_id');
        $rendysStopId = (string) $addResponse->json('data.shopping.stops.1.stop_id');
        $this->assertNotSame('', $rendysStopId);
        $this->assertNotSame($sateStopId, $rendysStopId);

        // GANTI Rendy's -> Brownies (via Maps / merchant_place). HARUS TETAP 2 toko,
        // Rendy's tergantikan Brownies (bug: sebelumnya jadi 3 toko / menambah).
        $replaceResponse = $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'mode' => 'replace',
            'replace_target' => $rendysStopId,
            'merchant_place' => [
                'place_id' => 'google-place-brownies-singasari',
                'name' => 'Brownies Singasari, Salatiga',
                'address' => 'Jl. Singasari, Salatiga',
                'latitude' => -7.004,
                'longitude' => 110.404,
                'types' => ['bakery', 'store'],
            ],
        ]);

        $replaceResponse->assertOk()
            ->assertJsonCount(2, 'data.shopping.stops')
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Sate Ayam Cak Sabari')
            ->assertJsonPath('data.shopping.stops.1.merchant.name', 'Brownies Singasari, Salatiga');

        $names = collect($replaceResponse->json('data.shopping.stops'))
            ->pluck('merchant.name')
            ->all();
        $this->assertNotContains("Rendy's Chicken", $names);

        // Slot yang sama diganti (stop_id target dipertahankan), toko #1 tak tersentuh.
        $this->assertSame($rendysStopId, (string) $replaceResponse->json('data.shopping.stops.1.stop_id'));
        $this->assertSame($sateStopId, (string) $replaceResponse->json('data.shopping.stops.0.stop_id'));

        // Target tak dikenal -> 422 (tidak diam-diam menambah).
        $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'mode' => 'replace',
            'replace_target' => 'stop-tidak-ada',
            'merchant_id' => $sate->id,
        ])->assertStatus(422);
    }

    public function test_chatbot_shopping_added_stop_does_not_double_item_quantities(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        Config::set('bangdeliv.routes.optimize_shopping_waypoints', false);

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000088',
            'full_address' => 'Jl. Customer No. 88',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $nasgor = Restaurant::query()->create([
            'name' => 'Nasgor Gajah',
            'slug' => 'nasgor-gajah-double-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Nasgor No. 1',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000089',
        ]);
        Restaurant::query()->create([
            'name' => 'Aneka Kripik Cap Gajah',
            'slug' => 'aneka-kripik-double-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Kripik No. 1',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000090',
        ]);

        Sanctum::actingAs($customer);

        // Pesan multi-tempat sekaligus (sesi baru): Gemini mengembalikan `stops`
        // dengan dua toko. Merge menambahkan tempat ke-2 -> di sinilah dulu item
        // tempat ke-2 dobel (1x jadi 2x).
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [],
            'stops' => [
                [
                    'merchant' => 'Nasgor Gajah',
                    'items' => [
                        ['name' => 'Nasi Goreng', 'quantity' => 1],
                        ['name' => 'Es Teh', 'quantity' => 1],
                    ],
                ],
                [
                    'merchant' => 'Aneka Kripik Cap Gajah',
                    'items' => [
                        ['name' => 'kripik', 'quantity' => 1],
                    ],
                ],
            ],
        ], 1000);

        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-double-item-session',
            'service_type' => 'nitip',
            'message' => 'beli nasi goreng 1, es teh 1 di nasgor gajah dan beli kripik 1 di Aneka Kripik Cap Gajah',
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.shopping.stops')
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Nasgor Gajah')
            ->assertJsonPath('data.shopping.stops.1.merchant.name', 'Aneka Kripik Cap Gajah');

        // Tempat 1 (aktif pertama) benar.
        $this->assertSame(1, (int) $response->json('data.shopping.stops.0.items.0.quantity'));
        $this->assertSame(1, (int) $response->json('data.shopping.stops.0.items.1.quantity'));
        // REGRESI: tempat ke-2 yang ditambahkan tidak boleh dobel (dulu jadi 2).
        $this->assertSame(1, (int) $response->json('data.shopping.stops.1.items.0.quantity'));
    }

    public function test_chatbot_shopping_lowercase_di_merchant_opens_new_stop(): void
    {
        // REGRESI #2: "di <tempat>" harus membuka tempat baru TERLEPAS kapitalisasi.
        // Dulu "baloeng gajah" (huruf kecil) malah jadi ITEM di tempat aktif.
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        Config::set('bangdeliv.routes.optimize_shopping_waypoints', false);

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000091',
            'full_address' => 'Jl. Customer No. 91',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);
        $sate = Restaurant::query()->create([
            'name' => 'Sate Cak Sabari',
            'slug' => 'sate-cak-sabari-lowercase-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Sate No. 1',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000092',
        ]);
        Restaurant::query()->create([
            'name' => 'Baloeng Gajah',
            'slug' => 'baloeng-gajah-lowercase-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Baloeng No. 1',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000093',
        ]);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-lowercase-di-session';

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [],
        ], 1000);
        $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'merchant_id' => $sate->id,
        ])->assertOk();

        // Isi item tempat 1 supaya stop aktif punya item (bukan slot kosong).
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [['name' => 'sate ayam', 'quantity' => 1]],
        ], 1000);
        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'sate ayam 1',
        ])->assertOk()
            ->assertJsonPath('data.shopping.stops.0.items.0.name', 'sate ayam');

        // Tempat 2 lewat chat, HURUF KECIL "di baloeng gajah", Gemini TIDAK isi
        // merchant (null) -> backend harus tetap membuka stop baru dari ekor "di".
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [['name' => 'sum sum goreng', 'quantity' => 1]],
        ], 1000);
        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'beli sum sum goreng 1 di baloeng gajah',
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.shopping.stops')
            ->assertJsonPath('data.shopping.stops.1.merchant.name', 'Baloeng Gajah')
            ->assertJsonPath('data.shopping.stops.1.items.0.name', 'sum sum goreng');
        // Tempat 1 tak kemasukan item tempat 2.
        $this->assertCount(1, (array) $response->json('data.shopping.stops.0.items'));
    }

    public function test_chatbot_shopping_resolves_numbered_merchant_branch_correctly(): void
    {
        // REGRESI #1: "bakmi remaja 3" harus resolve ke cabang bernomor "3",
        // bukan "6" (dulu pilih id terkecil karena tak membobot angka).
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        Config::set('bangdeliv.routes.optimize_shopping_waypoints', false);

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000094',
            'full_address' => 'Jl. Customer No. 94',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);
        // Cabang 6 dibuat lebih dulu (id lebih kecil) -> bug lama memilihnya.
        Restaurant::query()->create([
            'name' => 'Bakmi Remaja 6 Perumahan Sraten',
            'slug' => 'bakmi-remaja-6-numbered-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Bakmi No. 6',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000095',
        ]);
        Restaurant::query()->create([
            'name' => 'Bakmi Remaja 3 Perumahan Sraten',
            'slug' => 'bakmi-remaja-3-numbered-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Bakmi No. 3',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000096',
        ]);

        Sanctum::actingAs($customer);
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'bakmi remaja 3',
            'items' => [['name' => 'mie yamin', 'quantity' => 1]],
        ], 1000);

        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-numbered-merchant-session',
            'service_type' => 'nitip',
            'message' => 'beli mie yamin 1 di bakmi remaja 3',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Bakmi Remaja 3 Perumahan Sraten');
    }

    public function test_chatbot_shopping_removes_item_from_non_active_stop(): void
    {
        // REGRESI #4: "hapus <item>" harus menyasar stop yang MEMUAT item itu,
        // bukan hanya stop aktif. Dulu item di tempat non-aktif gagal dihapus.
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        Config::set('bangdeliv.routes.optimize_shopping_waypoints', false);

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000097',
            'full_address' => 'Jl. Customer No. 97',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);
        $tahu = Restaurant::query()->create([
            'name' => 'Tahu Crispy Sraten',
            'slug' => 'tahu-crispy-edit-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Tahu No. 1',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000098',
        ]);
        $bakmi = Restaurant::query()->create([
            'name' => 'Bakmi Remaja Sraten',
            'slug' => 'bakmi-remaja-edit-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Bakmi No. 1',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000099',
        ]);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-edit-nonactive-session';

        // Tempat 1: Tahu Crispy + tahu gejrot.
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order', 'command' => 'none', 'merchant' => null, 'items' => [],
        ], 1000);
        $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip', 'merchant_id' => $tahu->id,
        ])->assertOk();
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order', 'command' => 'none', 'merchant' => null,
            'items' => [['name' => 'tahu gejrot', 'quantity' => 5]],
        ], 1000);
        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId, 'service_type' => 'nitip', 'message' => 'tahu gejrot 5',
        ])->assertOk()->assertJsonPath('data.shopping.stops.0.items.0.name', 'tahu gejrot');

        // Tempat 2: Bakmi Remaja + mie yamin (jadi stop aktif).
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order', 'command' => 'none', 'merchant' => null, 'items' => [],
        ], 1000);
        $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip', 'mode' => 'add', 'merchant_id' => $bakmi->id,
        ])->assertOk();
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order', 'command' => 'none', 'merchant' => null,
            'items' => [['name' => 'mie yamin', 'quantity' => 1]],
        ], 1000);
        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId, 'service_type' => 'nitip', 'message' => 'mie yamin 1',
        ])->assertOk()->assertJsonPath('data.shopping.stops.1.items.0.name', 'mie yamin');

        // HAPUS item milik Tempat 1 (non-aktif). Harus terhapus dari Tempat 1.
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order', 'command' => 'none', 'merchant' => null, 'items' => [],
        ], 1000);
        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId, 'service_type' => 'nitip', 'message' => 'hapus tahu gejrot',
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.shopping.stops')
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Tahu Crispy Sraten')
            ->assertJsonPath('data.shopping.stops.1.items.0.name', 'mie yamin');
        // Tempat 1 kosong (item terhapus); Tempat 2 tak tersentuh.
        $this->assertCount(0, (array) $response->json('data.shopping.stops.0.items'));
        $this->assertCount(1, (array) $response->json('data.shopping.stops.1.items'));
    }

    public function test_chatbot_shopping_remove_merchant_from_draft_via_chat(): void
    {
        // #3: "tidak jadi pesan di X" harus MENGHAPUS tempat X dari draft, dan
        // menolak bila hanya tersisa 1 tempat.
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        Config::set('bangdeliv.routes.optimize_shopping_waypoints', false);

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000100',
            'full_address' => 'Jl. Customer No. 100',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);
        $tahu = Restaurant::query()->create([
            'name' => 'Tahu Crispy Sraten',
            'slug' => 'tahu-crispy-remove-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Tahu No. 1',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000101',
        ]);
        $baloeng = Restaurant::query()->create([
            'name' => 'Baloeng Gajah',
            'slug' => 'baloeng-gajah-remove-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Baloeng No. 1',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000102',
        ]);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-remove-merchant-session';
        $benign = [
            'intent' => 'shopping_order', 'command' => 'none', 'merchant' => null, 'items' => [],
        ];

        // Dua tempat + item.
        $this->fakeGeminiAndDistance($benign, 1000);
        $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip', 'merchant_id' => $tahu->id,
        ])->assertOk();
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order', 'command' => 'none', 'merchant' => null,
            'items' => [['name' => 'tahu gejrot', 'quantity' => 5]],
        ], 1000);
        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId, 'service_type' => 'nitip', 'message' => 'tahu gejrot 5',
        ])->assertOk();

        $this->fakeGeminiAndDistance($benign, 1000);
        $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip', 'mode' => 'add', 'merchant_id' => $baloeng->id,
        ])->assertOk();
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order', 'command' => 'none', 'merchant' => null,
            'items' => [['name' => 'sum sum goreng', 'quantity' => 1]],
        ], 1000);
        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId, 'service_type' => 'nitip', 'message' => 'sum sum goreng 1',
        ])->assertOk()->assertJsonCount(2, 'data.shopping.stops');

        // HAPUS tempat "baloeng gajah" via chat.
        $this->fakeGeminiAndDistance($benign, 1000);
        $removeResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId, 'service_type' => 'nitip', 'message' => 'tidak jadi pesan di baloeng gajah',
        ]);
        $removeResponse->assertOk()
            ->assertJsonCount(1, 'data.shopping.stops')
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Tahu Crispy Sraten');
        $this->assertStringContainsString(
            'Baloeng Gajah',
            (string) $removeResponse->json('data.assistant_text')
        );
        $this->assertStringContainsString(
            'dihapus',
            (string) $removeResponse->json('data.assistant_text')
        );

        // Menghapus SATU-SATUNYA tempat tersisa harus DITOLAK.
        $this->fakeGeminiAndDistance($benign, 1000);
        $rejectResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId, 'service_type' => 'nitip', 'message' => 'hapus tempat tahu crispy',
        ]);
        $rejectResponse->assertOk()
            ->assertJsonCount(1, 'data.shopping.stops');
        $this->assertStringContainsString(
            'Minimal ada 1 tempat',
            (string) $rejectResponse->json('data.assistant_text')
        );
    }

    public function test_chatbot_shopping_patch_google_place_merchant_completes_external_merchant_draft(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000012',
            'full_address' => 'Jl. Customer No. 12',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [
                ['name' => 'sembako', 'quantity' => 1],
            ],
        ]);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-google-place-merchant-session';

        $draftResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'beli sembako',
        ]);
        $draftResponse->assertOk()
            ->assertJsonPath('data.validation.next_actions.0', 'OPEN_MERCHANT_PICKER');

        $merchantResponse = $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'merchant_place' => [
                'place_id' => 'google-place-alfamart-undip',
                'name' => 'Alfamart Undip Prof. Soedarto',
                'address' => 'Jl. Prof. Soedarto, Tembalang, Kota Semarang',
                'latitude' => -7.054932,
                'longitude' => 110.434739,
                'types' => ['convenience_store', 'store'],
            ],
        ]);

        $merchantResponse->assertOk()
            ->assertJsonPath('model_used', 'merchant-picker-action')
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.merchant.id', null)
            ->assertJsonPath('data.shopping.merchant.name', 'Alfamart Undip Prof. Soedarto')
            ->assertJsonPath('data.shopping.merchant.merchant_type', 'convenience_store')
            ->assertJsonPath('data.shopping.merchant.merchant_place.place_id', 'google-place-alfamart-undip')
            ->assertJsonPath('data.shopping.items.0.item_source', 'MANUAL')
            ->assertJsonPath('data.action_payloads.OPEN_MAP_PICKER_DELIVERY.label', 'Ganti Alamat Antar');

        $codResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'COD',
        ]);
        $codResponse->assertOk()
            ->assertJsonPath('data.shopping.payment_method', 'COD');

        $confirmResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'konfirmasi',
        ]);

        $confirmResponse->assertOk()
            ->assertJsonPath('data.order.created', true);

        $orderId = (int) $confirmResponse->json('data.order.id');
        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'restaurant_id' => null,
            'location_role' => 'PICKUP',
            'label' => 'Alfamart Undip Prof. Soedarto',
        ]);

        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('MANUAL', $item->item_source);
        $this->assertSame('google-place-alfamart-undip', $item->metadata['place_id'] ?? null);
    }

    public function test_chatbot_shopping_external_merchant_item_list_overrides_merged_gemini_item(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000032',
            'full_address' => 'happ, Sraten, Kec. Tuntang, Kabupaten Semarang, Jawa Tengah, 50773',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-external-gacoan-item-list-session';

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'items' => [],
        ]);

        $merchantResponse = $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'merchant_place' => [
                'place_id' => 'google-place-mie-gacoan-salatiga-patimura',
                'name' => 'Mie Gacoan Salatiga 2 - Patimura',
                'address' => 'Jl. Patimura, Salatiga',
                'latitude' => -7.004,
                'longitude' => 110.404,
                'types' => ['restaurant', 'food', 'establishment'],
            ],
        ]);

        $merchantResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', false)
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Mie Gacoan Salatiga 2 - Patimura')
            ->assertJsonPath('data.validation.missing_fields.0', 'items');

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [
                ['name' => 'gacoan level 6 - udang keju', 'quantity' => 1],
            ],
        ]);

        $itemsResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => "- gacoan level 6 1x\n- udang keju 1x",
        ]);

        $itemsResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonCount(2, 'data.shopping.stops.0.items')
            ->assertJsonPath('data.shopping.stops.0.items.0.name', 'gacoan level 6')
            ->assertJsonPath('data.shopping.stops.0.items.1.name', 'udang keju');
        $this->assertSame(
            ['gacoan level 6' => 1, 'udang keju' => 1],
            $this->shoppingItemQuantities($itemsResponse)
        );
    }

    public function test_chatbot_shopping_beli_di_header_is_merchant_not_item(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000034',
            'full_address' => 'baskoro raya, Bejalen, Kec. Ambarawa, Kabupaten Semarang, Jawa Tengah, 50611',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        Restaurant::query()->create([
            'name' => 'Nasgor Gajah',
            'slug' => 'nasgor-gajah',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Nasgor Gajah No. 1',
            'latitude' => -7.004,
            'longitude' => 110.404,
            'phone' => '081200000035',
        ]);

        Sanctum::actingAs($customer);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Nasgor Gajah',
            'items' => [],
        ]);

        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-beli-di-header-merchant-session',
            'service_type' => 'nitip',
            'message' => "Beli di nasgor gajah:\n1. sego tiwul 1x",
        ]);

        $response->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.merchant.name', 'Nasgor Gajah')
            ->assertJsonCount(1, 'data.shopping.items')
            ->assertJsonPath('data.shopping.items.0.name', 'sego tiwul')
            ->assertJsonPath('data.shopping.items.0.quantity', 1);
        $this->assertSame(['sego tiwul' => 1], $this->shoppingItemQuantities($response));
    }

    public function test_chatbot_shopping_external_merchant_supports_bare_quantity_decrement_and_ambiguous_edit_guard(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000033',
            'full_address' => 'baskoro raaya, Bejalen, Kec. Ambarawa, Kabupaten Semarang, Jawa Tengah, 50611',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-external-gacoan-natural-edit-session';

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'items' => [],
        ]);

        $merchantResponse = $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'merchant_place' => [
                'place_id' => 'google-place-mie-gacoan-salatiga',
                'name' => 'Mie Gacoan Salatiga',
                'address' => 'Jl. Patimura, Salatiga',
                'latitude' => -7.004,
                'longitude' => 110.404,
                'types' => ['restaurant', 'food', 'establishment'],
            ],
        ]);
        $merchantResponse->assertOk()
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Mie Gacoan Salatiga');

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [
                ['name' => 'mie gacoan level 7 2', 'quantity' => 1],
            ],
        ]);

        $bareQuantityResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'mie gacoan level 7 2',
        ]);
        $bareQuantityResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.stops.0.items.0.name', 'mie gacoan level 7')
            ->assertJsonPath('data.shopping.stops.0.items.0.quantity', 2);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [
                ['name' => 'mie gacoan level 7', 'quantity' => 2, 'operation' => 'add'],
            ],
        ]);

        $addResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'tambah mie gacoan level 7 2x',
        ]);
        $addResponse->assertOk()
            ->assertJsonPath('data.shopping.stops.0.items.0.quantity', 4);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [
                ['name' => 'mie gacoan level 7', 'quantity' => 2, 'operation' => 'decrement'],
            ],
        ]);

        $decrementResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'kurangi mie gacoan level 7 2x',
        ]);
        $decrementResponse->assertOk()
            ->assertJsonPath('data.shopping.stops.0.items.0.quantity', 2);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [
                ['name' => 'mie gacoan level 7', 'quantity' => 2, 'operation' => 'add'],
            ],
        ]);

        $ambiguousResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'eh tambah lagi 2',
        ]);
        $ambiguousResponse->assertOk()
            ->assertJsonPath('data.shopping.stops.0.items.0.quantity', 2);
        $this->assertStringContainsString(
            'Tulis item yang mau diubah',
            (string) $ambiguousResponse->json('data.assistant_text')
        );
    }

    public function test_chatbot_shopping_creates_menu_database_restaurant_order_after_confirmation(): void
    {
        $this->travelTo(Carbon::create(2026, 6, 29, 10, 15, 0, 'Asia/Jakarta'));

        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000001',
            'full_address' => 'Jl. Customer No. 1',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Ayam Geprek Juara',
            'slug' => 'ayam-geprek-juara-test',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Merchant No. 1',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000002',
        ]);

        $menu = Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Paket Geprek Original',
            'price' => 22000,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Ayam Geprek Juara',
            'resto' => 'Ayam Geprek Juara',
            'items' => [
                ['name' => 'Paket Geprek Original', 'menu' => 'Paket Geprek Original', 'quantity' => 2, 'qty' => 2],
            ],
        ]);

        Sanctum::actingAs($customer);

        $sessionId = 'shopping-restaurant-manual-session';
        $draftResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'titip 2 paket geprek original dari Ayam Geprek Juara',
        ]);

        $draftResponse->assertOk()
            ->assertJsonPath('data.intent', 'shopping_order')
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.items.0.item_source', 'MENU_DB')
            ->assertJsonPath('data.shopping.items.0.menu_id', $menu->id)
            ->assertJsonPath('data.shopping.items.0.unit_price', 22000)
            ->assertJsonPath('data.shopping.items.0.subtotal', 44000)
            ->assertJsonPath('data.shopping.items.0.metadata.price_status', 'CONFIRMED')
            ->assertJsonPath('data.action_payloads.OPEN_MAP_PICKER_DELIVERY.label', 'Ganti Alamat Antar');
        $this->assertStringContainsString(
            'Estimasi ongkir sementara:',
            (string) $draftResponse->json('data.assistant_text')
        );

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Ayam Geprek Juara',
            'resto' => 'Ayam Geprek Juara',
            'payment_method' => 'TRANSFER',
            'items' => [
                ['name' => 'Paket Geprek Original', 'menu' => 'Paket Geprek Original', 'quantity' => 2, 'qty' => 2],
            ],
        ]);

        $codResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'COD',
        ]);

        $codResponse->assertOk()
            ->assertJsonPath('data.shopping.payment_method', 'COD')
            ->assertJsonPath('model_used', 'deterministic-payment');
        $this->assertSame(
            ['OPEN_ADD_MERCHANT_PICKER', 'OPEN_MAP_PICKER_DELIVERY', 'CONFIRM_DRAFT'],
            $codResponse->json('data.validation.next_actions')
        );
        $this->assertArrayNotHasKey('SET_PAYMENT_COD', $codResponse->json('data.action_payloads'));
        $this->assertArrayNotHasKey('SET_PAYMENT_TRANSFER', $codResponse->json('data.action_payloads'));
        $this->assertStringContainsString(
            'Metode pembayaran: COD.',
            (string) $codResponse->json('data.assistant_text')
        );

        $confirmResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'konfirmasi',
        ]);

        $confirmResponse->assertOk()
            ->assertJsonPath('data.order.created', true)
            ->assertJsonPath('data.intent', 'shopping_order');
        $this->assertStringContainsString(
            'Estimasi ongkir sementara:',
            (string) $confirmResponse->json('data.assistant_text')
        );

        $orderId = (int) $confirmResponse->json('data.order.id');
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'order_number' => 'BD-290626-001',
            'user_id' => $customer->id,
        ]);

        $this->assertDatabaseHas('shopping_order_items', [
            'order_id' => $orderId,
            'menu_id' => $menu->id,
            'item_source' => 'MENU_DB',
            'quantity' => 2,
            'unit_price' => 22000,
            'subtotal' => 44000,
        ]);

        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('CONFIRMED', $item->metadata['price_status'] ?? null);

        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'restaurant_id' => $restaurant->id,
            'location_role' => 'PICKUP',
            'sequence_no' => 1,
        ]);

        $routeSnapshot = Order::query()->findOrFail($orderId)->route_snapshot;
        $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', $routeSnapshot['encoded_polyline'] ?? null);
    }

    public function test_chatbot_shopping_menu_database_zero_price_stays_confirmed(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000041',
            'full_address' => 'Jl. Customer No. 41',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Kedai Mbak Vita',
            'slug' => 'kedai-mbak-vita-zero-price-test',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Merchant No. 41',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000042',
        ]);

        $menu = Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Tahu Campur',
            'price' => 0,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Kedai Mbak Vita',
            'items' => [
                ['name' => 'Tahu Campur', 'menu' => 'Tahu Campur', 'quantity' => 1, 'qty' => 1],
            ],
        ]);

        Sanctum::actingAs($customer);

        $sessionId = 'shopping-zero-price-menu-session';
        $draftResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'titip tahu campur di Kedai Mbak Vita',
        ]);

        $draftResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.items.0.item_source', 'MENU_DB')
            ->assertJsonPath('data.shopping.items.0.menu_id', $menu->id)
            ->assertJsonPath('data.shopping.items.0.unit_price', 0)
            ->assertJsonPath('data.shopping.items.0.metadata.price_status', 'CONFIRMED');

        $assistantText = (string) $draftResponse->json('data.assistant_text');
        $this->assertStringContainsString('1x Tahu Campur (Rp0)', $assistantText);
        $this->assertStringNotContainsString('Harga barang: Sesuai nota', $assistantText);
        $this->assertStringNotContainsString('Estimasi total sementara: Menunggu harga barang', $assistantText);

        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'COD',
        ])->assertOk();

        $confirmResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'konfirmasi',
        ]);

        $confirmResponse->assertOk()
            ->assertJsonPath('data.order.created', true);

        $orderId = (int) $confirmResponse->json('data.order.id');
        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();

        $this->assertSame($menu->id, $item->menu_id);
        $this->assertSame('MENU_DB', $item->item_source);
        $this->assertSame('CONFIRMED', $item->metadata['price_status'] ?? null);
    }

    public function test_chatbot_shopping_preview_menu_selector_preserves_plus_menu_name_for_official_merchant(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000049',
            'full_address' => 'Jl. Customer No. 49',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => "Rendy's Chicken",
            'slug' => 'rendys-chicken-plus-menu-selector-test',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Rendy No. 49',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000050',
        ]);

        $pahaAtas = Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Ayam Krispi Paha Atas',
            'price' => 8000,
            'is_available' => true,
            'sort_order' => 1,
        ]);
        $paketSayap = Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Paket Ayam Geprek + Nasi Sayap',
            'price' => 11000,
            'is_available' => true,
            'sort_order' => 2,
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [],
        ], 1000);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-preview-plus-menu-selector-session';

        $merchantResponse = $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'merchant_id' => $restaurant->id,
        ]);

        $merchantResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', false)
            ->assertJsonPath('data.shopping.stops.0.merchant.name', "Rendy's Chicken")
            ->assertJsonPath('data.validation.missing_fields.0', 'items');

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [
                ['name' => 'paket ayam geprek', 'quantity' => 1],
                ['name' => 'nasi sayap', 'quantity' => 1],
            ],
        ], 1000);

        $itemsResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => "Ayam Krispi Paha Atas 1\nPaket Ayam Geprek + Nasi Sayap 1",
        ]);

        $itemsResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonCount(2, 'data.shopping.stops.0.items')
            ->assertJsonPath('data.shopping.stops.0.items.0.name', 'Ayam Krispi Paha Atas')
            ->assertJsonPath('data.shopping.stops.0.items.0.quantity', 1)
            ->assertJsonPath('data.shopping.stops.0.items.0.item_source', 'MENU_DB')
            ->assertJsonPath('data.shopping.stops.0.items.0.menu_id', $pahaAtas->id)
            ->assertJsonPath('data.shopping.stops.0.items.0.unit_price', 8000)
            ->assertJsonPath('data.shopping.stops.0.items.1.name', 'Paket Ayam Geprek + Nasi Sayap')
            ->assertJsonPath('data.shopping.stops.0.items.1.quantity', 1)
            ->assertJsonPath('data.shopping.stops.0.items.1.item_source', 'MENU_DB')
            ->assertJsonPath('data.shopping.stops.0.items.1.menu_id', $paketSayap->id)
            ->assertJsonPath('data.shopping.stops.0.items.1.unit_price', 11000)
            ->assertJsonPath('data.pricing.subtotal', 19000)
            ->assertJsonPath('data.pricing.delivery_fee', 5000)
            ->assertJsonPath('data.pricing.total_price', 24000);

        $assistantText = (string) $itemsResponse->json('data.assistant_text');
        $this->assertStringContainsString('1x Paket Ayam Geprek + Nasi Sayap (Rp11.000)', $assistantText);
        $this->assertStringNotContainsString('Sesuai nota', $assistantText);
        $this->assertStringNotContainsString('1x paket ayam geprek (harga sesuai nota)', strtolower($assistantText));
        $this->assertStringNotContainsString('1x nasi sayap (harga sesuai nota)', strtolower($assistantText));
    }

    public function test_chatbot_shopping_preview_menu_selector_single_plus_menu_action_matches_official_menu(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000051',
            'full_address' => 'Jl. Customer No. 51',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => "Rendy's Chicken",
            'slug' => 'rendys-chicken-single-plus-menu-test',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Rendy No. 51',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000052',
        ]);

        $menu = Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Paket Ayam Geprek + Nasi Sayap',
            'price' => 11000,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [],
        ], 1000);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-preview-single-plus-menu-session';

        $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'merchant_id' => $restaurant->id,
        ])->assertOk();

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [],
        ], 1000);

        $itemsResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'Paket Ayam Geprek + Nasi Sayap 1',
        ]);

        $itemsResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonCount(1, 'data.shopping.stops.0.items')
            ->assertJsonPath('data.shopping.stops.0.items.0.name', 'Paket Ayam Geprek + Nasi Sayap')
            ->assertJsonPath('data.shopping.stops.0.items.0.quantity', 1)
            ->assertJsonPath('data.shopping.stops.0.items.0.item_source', 'MENU_DB')
            ->assertJsonPath('data.shopping.stops.0.items.0.menu_id', $menu->id)
            ->assertJsonPath('data.shopping.stops.0.items.0.unit_price', 11000);
    }

    public function test_chatbot_shopping_preserves_comma_separated_menu_items_at_session_start(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000045',
            'full_address' => 'Jl. Customer No. 45',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Nasgor Gajah',
            'slug' => 'nasgor-gajah-comma-menu-test',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Nasgor Gajah No. 45',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000046',
        ]);

        $nasiGoreng = Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Nasi Goreng',
            'price' => 12000,
            'is_available' => true,
            'sort_order' => 1,
        ]);
        $nasiRuwet = Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Nasi Ruwet',
            'price' => 12000,
            'is_available' => true,
            'sort_order' => 2,
        ]);
        $kwetiauGoreng = Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Kwetiau Goreng',
            'price' => 12000,
            'is_available' => true,
            'sort_order' => 3,
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Nasgor Gajah',
            'items' => [
                ['name' => 'Nasi Goreng', 'quantity' => 1],
                ['name' => 'Nasi Ruwet', 'quantity' => 2],
                ['name' => 'Kwetiau Goreng', 'quantity' => 1],
            ],
        ], 1000);

        Sanctum::actingAs($customer);

        $draftResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-comma-menu-items-session',
            'service_type' => 'nitip',
            'message' => 'gue mau beli nasi goreng 1, nasi ruwet 2, kwetiau goreng 1 di nasgor gajah',
        ]);

        $draftResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.merchant.name', 'Nasgor Gajah')
            ->assertJsonPath('data.shopping.items.0.name', 'Nasi Goreng')
            ->assertJsonPath('data.shopping.items.0.quantity', 1)
            ->assertJsonPath('data.shopping.items.0.item_source', 'MENU_DB')
            ->assertJsonPath('data.shopping.items.0.menu_id', $nasiGoreng->id)
            ->assertJsonPath('data.shopping.items.1.name', 'Nasi Ruwet')
            ->assertJsonPath('data.shopping.items.1.quantity', 2)
            ->assertJsonPath('data.shopping.items.1.item_source', 'MENU_DB')
            ->assertJsonPath('data.shopping.items.1.menu_id', $nasiRuwet->id)
            ->assertJsonPath('data.shopping.items.2.name', 'Kwetiau Goreng')
            ->assertJsonPath('data.shopping.items.2.quantity', 1)
            ->assertJsonPath('data.shopping.items.2.item_source', 'MENU_DB')
            ->assertJsonPath('data.shopping.items.2.menu_id', $kwetiauGoreng->id)
            ->assertJsonPath('data.pricing.subtotal', 48000)
            ->assertJsonPath('data.pricing.delivery_fee', 5000)
            ->assertJsonPath('data.pricing.total_price', 53000);

        $assistantText = (string) $draftResponse->json('data.assistant_text');
        $this->assertStringContainsString('1x Nasi Goreng (Rp12.000)', $assistantText);
        $this->assertStringContainsString('2x Nasi Ruwet (Rp12.000)', $assistantText);
        $this->assertStringContainsString('1x Kwetiau Goreng (Rp12.000)', $assistantText);
    }

    public function test_chatbot_shopping_parses_quantity_x_with_apostrophe_merchant_and_matches_menu(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000043',
            'full_address' => 'Jl. Customer No. 43',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => "Rendy's Chicken",
            'slug' => 'rendys-chicken-quantity-x-test',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Rendy No. 43',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000044',
        ]);

        $menu = Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Ayam Krispi Sayap',
            'price' => 5000,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => "Rendy's Chicken",
            'items' => [
                ['name' => "3x aku mau beli ayam krispi sayap di rendy's chicken", 'quantity' => 1],
            ],
        ], 1000);

        Sanctum::actingAs($customer);

        $draftResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-quantity-x-apostrophe-merchant-session',
            'service_type' => 'nitip',
            'message' => "aku mau beli ayam krispi sayap 3x di rendy's chicken",
        ]);

        $draftResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.merchant.name', "Rendy's Chicken")
            ->assertJsonPath('data.shopping.items.0.name', 'Ayam Krispi Sayap')
            ->assertJsonPath('data.shopping.items.0.quantity', 3)
            ->assertJsonPath('data.shopping.items.0.item_source', 'MENU_DB')
            ->assertJsonPath('data.shopping.items.0.menu_id', $menu->id)
            ->assertJsonPath('data.shopping.items.0.unit_price', 5000)
            ->assertJsonPath('data.shopping.items.0.subtotal', 15000)
            ->assertJsonPath('data.pricing.subtotal', 15000)
            ->assertJsonPath('data.pricing.delivery_fee', 5000)
            ->assertJsonPath('data.pricing.total_price', 20000);

        $assistantText = (string) $draftResponse->json('data.assistant_text');
        $this->assertStringContainsString('3x Ayam Krispi Sayap (Rp5.000)', $assistantText);
        $this->assertStringContainsString('Estimasi total sementara: Rp 20.000', $assistantText);
        $this->assertStringNotContainsString('Sesuai nota', $assistantText);
        $this->assertStringNotContainsString('3x aku mau beli', $assistantText);
    }

    public function test_chatbot_shopping_active_merchant_parses_size_items_with_comma_quantity_when_gemini_empty(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000047',
            'full_address' => 'Jl. Customer No. 47',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $warung = Restaurant::query()->create([
            'name' => 'Warung Serba Ada',
            'slug' => 'warung-serba-ada-size-item-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Warung No. 47',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000048',
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [],
        ], 1000);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-size-item-comma-quantity-session';

        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'halo',
        ])->assertOk();

        $merchantResponse = $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'merchant_id' => $warung->id,
        ]);

        $merchantResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', false)
            ->assertJsonPath('data.validation.missing_fields.0', 'items');

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [],
        ], 1000);

        $itemsResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'minyak 500ml, 1 dan beras 1kg, 1',
        ]);

        $itemsResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.stops.0.items.0.name', 'minyak 500ml')
            ->assertJsonPath('data.shopping.stops.0.items.0.quantity', 1)
            ->assertJsonPath('data.shopping.stops.0.items.0.item_source', 'MANUAL')
            ->assertJsonPath('data.shopping.stops.0.items.1.name', 'beras 1kg')
            ->assertJsonPath('data.shopping.stops.0.items.1.quantity', 1)
            ->assertJsonPath('data.shopping.stops.0.items.1.item_source', 'MANUAL');
    }

    public function test_chatbot_shopping_delivery_location_patch_preserves_ready_draft(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000031',
            'full_address' => 'Jl. Customer Lama No. 31',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Gecok Jogo Roso Tlogo',
            'slug' => 'gecok-jogo-roso-tlogo-location-patch-test',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Merchant No. 31',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000032',
        ]);

        $menu = Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Tongseng Kambing',
            'price' => 35000,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-delivery-location-preserves-draft-session';

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Gecok Jogo Roso Tlogo',
            'resto' => 'Gecok Jogo Roso Tlogo',
            'items' => [
                ['name' => 'Tongseng Kambing', 'quantity' => 1],
            ],
        ], 2500);

        $draftResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'beli tongseng kambing di Gecok Jogo Roso Tlogo',
        ]);

        $draftResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Gecok Jogo Roso Tlogo')
            ->assertJsonPath('data.shopping.stops.0.items.0.menu_id', $menu->id)
            ->assertJsonPath('data.shopping.delivery.address', 'Jl. Customer Lama No. 31');

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
        ], 4200);

        $locationResponse = $this->postJson("/api/chatbot/sessions/{$sessionId}/location", [
            'service_type' => 'nitip',
            'target' => 'delivery',
            'latitude' => -7.011,
            'longitude' => 110.411,
            'address' => 'Jl. Customer Baru No. 31',
        ]);

        $locationResponse->assertOk()
            ->assertJsonPath('model_used', 'map-pin-action')
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.delivery.address', 'Jl. Customer Baru No. 31')
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Gecok Jogo Roso Tlogo')
            ->assertJsonPath('data.shopping.stops.0.items.0.name', 'Tongseng Kambing')
            ->assertJsonPath('data.shopping.stops.0.items.0.menu_id', $menu->id)
            ->assertJsonPath('data.action_payloads.OPEN_MAP_PICKER_DELIVERY.label', 'Ganti Alamat Antar');
        $this->assertIsNumeric($locationResponse->json('data.shopping.route.distance_meters'));

        $this->assertSame(
            ['OPEN_ADD_MERCHANT_PICKER', 'OPEN_MAP_PICKER_DELIVERY', 'SET_PAYMENT_COD', 'SET_PAYMENT_TRANSFER'],
            $locationResponse->json('data.validation.next_actions')
        );
        $assistantText = (string) $locationResponse->json('data.assistant_text');
        $this->assertStringContainsString('Draft Nitip tempat pertama sudah aman.', $assistantText);
        $this->assertStringContainsString('Gecok Jogo Roso Tlogo', $assistantText);
        $this->assertStringContainsString('Tongseng Kambing', $assistantText);
        $this->assertStringContainsString('Jl. Customer Baru No. 31', $assistantText);
        $this->assertStringNotContainsString('Sekarang pilih toko/resto', $assistantText);
    }

    public function test_chatbot_shopping_delivery_location_patch_without_address_uses_plain_street_reverse_geocode(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000034',
            'full_address' => 'Jl. Customer Lama No. 34',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Warung Tongseng Test',
            'slug' => 'warung-tongseng-location-reverse-geocode-test',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Merchant No. 34',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000035',
        ]);

        Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Tongseng Kambing',
            'price' => 35000,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-delivery-location-plain-reverse-geocode-session';

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Warung Tongseng Test',
            'resto' => 'Warung Tongseng Test',
            'items' => [
                ['name' => 'Tongseng Kambing', 'quantity' => 1],
            ],
        ], 2500);

        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'beli tongseng kambing di Warung Tongseng Test',
        ])->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true);

        $requestedUrls = [];
        $streetAddress = 'Jl. Sraten Raya No. 10, Sraten, Kabupaten Semarang, Jawa Tengah';
        Http::fake(function ($request) use (&$requestedUrls, $streetAddress) {
            $requestedUrls[] = $request->url();

            if (str_contains($request->url(), 'routes.googleapis.com')) {
                return Http::response([
                    'routes' => [[
                        'distanceMeters' => 4200,
                        'duration' => '600s',
                        'polyline' => [
                            'encodedPolyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
                        ],
                        'legs' => [[
                            'distanceMeters' => 4200,
                            'duration' => '600s',
                        ]],
                    ]],
                ], 200);
            }

            if (str_contains($request->url(), 'maps.googleapis.com/maps/api/distancematrix')) {
                return Http::response([
                    'status' => 'OK',
                    'rows' => [
                        [
                            'elements' => [
                                [
                                    'status' => 'OK',
                                    'distance' => ['value' => 4200, 'text' => '4,2 km'],
                                    'duration' => ['value' => 600, 'text' => '10 menit'],
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            if (str_contains($request->url(), 'maps.googleapis.com/maps/api/geocode/json')) {
                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'formatted_address' => $streetAddress,
                        'types' => ['street_address'],
                        'geometry' => [
                            'location_type' => 'ROOFTOP',
                            'location' => [
                                'lat' => -7.011,
                                'lng' => 110.411,
                            ],
                        ],
                    ]],
                ], 200);
            }

            if (str_contains($request->url(), 'maps.googleapis.com/maps/api/place/nearbysearch/json')) {
                return Http::response([
                    'status' => 'OK',
                    'results' => [[
                        'name' => 'Permakaman Lama',
                        'vicinity' => 'Sraten',
                        'types' => ['cemetery', 'establishment'],
                    ]],
                ], 200);
            }

            return Http::response([], 404);
        });

        $locationResponse = $this->postJson("/api/chatbot/sessions/{$sessionId}/location", [
            'service_type' => 'nitip',
            'target' => 'delivery',
            'latitude' => -7.011,
            'longitude' => 110.411,
        ]);

        $locationResponse->assertOk()
            ->assertJsonPath('model_used', 'map-pin-action')
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.delivery.address', $streetAddress)
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Warung Tongseng Test')
            ->assertJsonPath('data.action_payloads.OPEN_MAP_PICKER_DELIVERY.label', 'Ganti Alamat Antar');

        $assistantText = (string) $locationResponse->json('data.assistant_text');
        $this->assertStringContainsString($streetAddress, $assistantText);
        $this->assertStringNotContainsString('Permakaman Lama', $assistantText);
        $this->assertStringNotContainsString('Pin -7.011000, 110.411000', $assistantText);
        $this->assertFalse(
            collect($requestedUrls)->contains(
                fn (string $url): bool => str_contains($url, 'maps.googleapis.com/maps/api/place/nearbysearch/json')
            ),
            'Nitip delivery location patch should not enrich the address with nearest establishment.'
        );
    }

    public function test_chatbot_shopping_can_add_second_merchant_after_first_is_ready(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000021',
            'full_address' => 'Jl. Customer No. 21',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $firstRestaurant = Restaurant::query()->create([
            'name' => 'Kedai Tinari',
            'slug' => 'kedai-tinari-chatbot-test',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Kedai Tinari',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000022',
        ]);
        $secondRestaurant = Restaurant::query()->create([
            'name' => 'Warung Sembako Maju',
            'slug' => 'warung-sembako-maju-chatbot-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Warung Sembako',
            'latitude' => -7.004,
            'longitude' => 110.404,
            'phone' => '081200000023',
        ]);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-add-second-merchant-session';

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Kedai Tinari',
            'items' => [
                ['name' => 'ramen mala', 'quantity' => 1],
                ['name' => 'es jeruk', 'quantity' => 1],
            ],
        ]);
        $firstDraftResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'beli ramen mala 1 dan es jeruk 1 di Kedai Tinari',
        ]);

        $firstDraftResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.stops.0.merchant.name', 'Kedai Tinari')
            ->assertJsonPath('data.action_payloads.OPEN_ADD_MERCHANT_PICKER.label', 'Tambah Tempat')
            ->assertJsonPath('data.action_payloads.OPEN_ADD_MERCHANT_PICKER.mode', 'add');
        $this->assertStringContainsString(
            'Mau tambah tempat lain? Pilih tempatnya dulu.',
            (string) $firstDraftResponse->json('data.assistant_text')
        );
        $this->assertStringContainsString(
            '- mie pedas level 7, 1',
            (string) $firstDraftResponse->json('data.assistant_text')
        );
        $this->assertStringNotContainsString(
            '- susu 1',
            (string) $firstDraftResponse->json('data.assistant_text')
        );

        $addCommandResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'tambah tempat',
        ]);

        $addCommandResponse->assertOk()
            ->assertJsonPath('model_used', 'deterministic-command')
            ->assertJsonPath('data.action_payloads.OPEN_ADD_MERCHANT_PICKER.mode', 'add');
        $this->assertArrayNotHasKey('order', $this->shoppingItemQuantities($addCommandResponse));

        $secondMerchantResponse = $this->postJson("/api/chatbot/sessions/{$sessionId}/merchant", [
            'service_type' => 'nitip',
            'mode' => 'add',
            'merchant_id' => $secondRestaurant->id,
        ]);

        $secondMerchantResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', false)
            ->assertJsonPath('data.shopping.stops.1.is_active', true)
            ->assertJsonPath('data.shopping.stops.1.merchant.name', 'Warung Sembako Maju')
            ->assertJsonPath('data.validation.missing_fields.0', 'items');
        $this->assertStringContainsString(
            'Tulis item dan jumlah untuk tempat ini.',
            (string) $secondMerchantResponse->json('data.assistant_text')
        );
        $this->assertStringContainsString(
            '- minyak 500ml, 1',
            (string) $secondMerchantResponse->json('data.assistant_text')
        );

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [
                ['name' => 'susu', 'quantity' => 1],
                ['name' => 'roti tawar', 'quantity' => 2],
            ],
        ]);
        $secondItemsResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'tambah susu 1x dan roti tawar 2x',
        ]);

        $secondItemsResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.stops.1.items.0.name', 'susu')
            ->assertJsonPath('data.shopping.stops.1.items.1.name', 'roti tawar');

        $codResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'COD',
        ]);
        $codResponse->assertOk()
            ->assertJsonPath('data.shopping.payment_method', 'COD');

        $confirmResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'konfirmasi',
        ]);
        $confirmResponse->assertOk()
            ->assertJsonPath('data.order.created', true);

        $order = Order::query()
            ->with(['orderLocations', 'items'])
            ->findOrFail((int) $confirmResponse->json('data.order.id'));
        $pickups = $order->orderLocations
            ->where('location_role', 'PICKUP')
            ->sortBy('sequence_no')
            ->values();

        $this->assertCount(2, $pickups);
        $this->assertSame($firstRestaurant->id, (int) $pickups[0]->restaurant_id);
        $this->assertSame($secondRestaurant->id, (int) $pickups[1]->restaurant_id);
        $this->assertDatabaseHas('order_locations', [
            'order_id' => $order->id,
            'location_role' => 'DROPOFF',
            'sequence_no' => 3,
        ]);
        $this->assertSame(
            [(int) $pickups[0]->id, (int) $pickups[1]->id],
            $order->route_snapshot['ordered_pickup_location_ids'] ?? []
        );
        $this->assertTrue(
            $order->items
                ->where('pickup_location_id', $pickups[1]->id)
                ->contains(fn (OrderItem $item): bool => $item->menu_name === 'susu')
        );
    }

    public function test_chatbot_shopping_item_edit_context_add_set_and_remove(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000006',
            'full_address' => 'Jl. Customer No. 6',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        Restaurant::query()->create([
            'name' => 'Resto Taman Kedai Satu',
            'slug' => 'resto-taman-kedai-satu',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Taman Kedai No. 1',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000007',
        ]);

        Sanctum::actingAs($customer);
        $sessionId = 'shopping-context-edit-session';

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Resto Taman Kedai Satu',
            'items' => [
                ['name' => 'ayam geprek', 'quantity' => 1],
            ],
        ]);
        $draftResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'beli ayam geprek 1x di taman kedai',
        ]);

        $draftResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true);
        $this->assertSame(['ayam geprek' => 1], $this->shoppingItemQuantities($draftResponse));

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Resto Taman Kedai Satu',
            'items' => [
                ['name' => 'ayam geprek', 'quantity' => 1],
                ['name' => 'es teh', 'quantity' => 1, 'operation' => 'add'],
            ],
        ]);
        $addTeaResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'tambah es teh 1x',
        ]);

        $addTeaResponse->assertOk();
        $this->assertSame(
            ['ayam geprek' => 1, 'es teh' => 1],
            $this->shoppingItemQuantities($addTeaResponse)
        );

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Resto Taman Kedai Satu',
            'items' => [
                ['name' => 'ayam geprek', 'quantity' => 1, 'operation' => 'set'],
                ['name' => 'es teh', 'quantity' => 1],
            ],
        ]);
        $setChickenResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'ayam gepreknya 1x saja',
        ]);

        $setChickenResponse->assertOk();
        $this->assertSame(
            ['ayam geprek' => 1, 'es teh' => 1],
            $this->shoppingItemQuantities($setChickenResponse)
        );

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Resto Taman Kedai Satu',
            'items' => [
                ['name' => 'ayam geprek', 'quantity' => 1, 'operation' => 'add'],
                ['name' => 'es teh', 'quantity' => 1],
            ],
        ]);
        $addChickenResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'tambah ayam geprek 1x',
        ]);

        $addChickenResponse->assertOk();
        $this->assertSame(
            ['ayam geprek' => 2, 'es teh' => 1],
            $this->shoppingItemQuantities($addChickenResponse)
        );

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Resto Taman Kedai Satu',
            'items' => [
                ['name' => 'ayam geprek', 'quantity' => 2],
                ['name' => 'es teh', 'quantity' => 1, 'operation' => 'remove'],
            ],
        ]);
        $removeTeaResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'hapus es teh',
        ]);

        $removeTeaResponse->assertOk();
        $this->assertSame(['ayam geprek' => 2], $this->shoppingItemQuantities($removeTeaResponse));

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Resto Taman Kedai Satu',
            'items' => [
                ['name' => 'ayam geprek', 'quantity' => 2, 'operation' => 'remove'],
            ],
        ]);
        $removeLastItemResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'hapus ayam geprek',
        ]);

        $removeLastItemResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', false);
        $this->assertSame([], $this->shoppingItemQuantities($removeLastItemResponse));
        $this->assertContains('items', $removeLastItemResponse->json('data.validation.missing_fields'));
    }

    public function test_chatbot_shopping_for_warung_creates_manual_pending_price_item(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Kos',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000003',
            'full_address' => 'Jl. Kos No. 1',
            'latitude' => -7.004,
            'longitude' => 110.404,
            'is_default' => true,
        ]);

        $warung = Restaurant::query()->create([
            'name' => 'Warung Madura Pak Ali',
            'slug' => 'warung-madura-pak-ali',
            'merchant_type' => 'warung',
            'address' => 'Jl. Warung No. 2',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000004',
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => 'Warung Madura Pak Ali',
            'items' => [
                ['name' => 'Telur 1 kg', 'quantity' => 1],
            ],
        ]);

        Sanctum::actingAs($customer);

        $sessionId = 'shopping-warung-session';
        $draftResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'titip telur 1 kg di Warung Madura Pak Ali',
        ]);

        $draftResponse->assertOk()
            ->assertJsonPath('data.shopping.ready_to_confirm', true)
            ->assertJsonPath('data.shopping.merchant.merchant_type', 'warung')
            ->assertJsonPath('data.shopping.items.0.item_source', 'MANUAL')
            ->assertJsonPath('data.shopping.items.0.unit_price', 0);

        $codResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'COD',
        ]);

        $codResponse->assertOk()
            ->assertJsonPath('data.shopping.payment_method', 'COD')
            ->assertJsonPath('model_used', 'deterministic-payment');
        $this->assertSame(
            ['OPEN_ADD_MERCHANT_PICKER', 'OPEN_MAP_PICKER_DELIVERY', 'CONFIRM_DRAFT'],
            $codResponse->json('data.validation.next_actions')
        );
        $this->assertArrayNotHasKey('SET_PAYMENT_COD', $codResponse->json('data.action_payloads'));
        $this->assertArrayNotHasKey('SET_PAYMENT_TRANSFER', $codResponse->json('data.action_payloads'));

        $confirmResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'konfirmasi',
        ]);

        $confirmResponse->assertOk()
            ->assertJsonPath('data.order.created', true);

        $orderId = (int) $confirmResponse->json('data.order.id');
        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'restaurant_id' => $warung->id,
            'location_role' => 'PICKUP',
        ]);

        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('MANUAL', $item->item_source);
        $this->assertSame('0.00', (string) $item->unit_price);
        $this->assertSame('PENDING_DRIVER_INPUT', $item->metadata['price_status'] ?? null);
    }

    public function test_chatbot_shopping_browses_searches_and_paginates_official_menu_without_mutating_draft(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
            'name' => 'Hassan',
        ]);
        $restaurant = Restaurant::query()->create([
            'name' => 'Warung Menu Lengkap',
            'slug' => 'warung-menu-lengkap-chatbot',
            'merchant_type' => 'warung',
            'address' => 'Jl. Menu No. 1',
            'latitude' => -7.002,
            'longitude' => 110.402,
        ]);
        foreach (range(1, 10) as $index) {
            Menu::query()->create([
                'restaurant_id' => $restaurant->id,
                'name' => sprintf('Menu %02d', $index),
                'price' => $index === 2 ? 0 : 10000 + $index,
                'is_available' => true,
                'sort_order' => $index,
            ]);
        }
        Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Menu Tidak Tersedia',
            'price' => 12000,
            'is_available' => false,
            'sort_order' => 0,
        ]);

        $sessionId = 'shopping-menu-browse-session';
        $this->saveShoppingDraft($customer, $sessionId, [$restaurant]);
        $this->fakeGeminiAndDistance(['intent' => 'shopping_order', 'command' => 'none', 'items' => []]);
        Sanctum::actingAs($customer);

        $firstPage = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'tampilkan menu',
        ]);

        $firstPage->assertOk()
            ->assertJsonPath('model_used', 'deterministic-assistant');
        $firstText = (string) $firstPage->json('data.assistant_text');
        $this->assertStringContainsString('Baik Hassan', $firstText);
        $this->assertStringContainsString('1. Menu 01', $firstText);
        $this->assertStringContainsString('8. Menu 08', $firstText);
        $this->assertStringNotContainsString('Menu 09', $firstText);
        $this->assertStringNotContainsString('Menu Tidak Tersedia', $firstText);
        $this->assertStringContainsString('harga belum tersedia', $firstText);
        $originalStops = $firstPage->json('data.shopping.stops');

        $nextPage = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'menu berikutnya',
        ]);
        $nextPage->assertOk();
        $this->assertStringContainsString('9. Menu 09', (string) $nextPage->json('data.assistant_text'));
        $this->assertStringContainsString('10. Menu 10', (string) $nextPage->json('data.assistant_text'));
        $this->assertSame($originalStops, $nextPage->json('data.shopping.stops'));

        $previousPage = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'menu sebelumnya',
        ]);
        $previousPage->assertOk();
        $this->assertStringContainsString('1. Menu 01', (string) $previousPage->json('data.assistant_text'));

        $search = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'cari menu 09',
        ]);
        $search->assertOk();
        $this->assertStringContainsString('Menu 09', (string) $search->json('data.assistant_text'));
        $this->assertStringNotContainsString('Menu 08', (string) $search->json('data.assistant_text'));
        $this->assertSame($originalStops, $search->json('data.shopping.stops'));

        $notFound = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'cari menu sate naga',
        ]);
        $this->assertStringContainsString('belum menemukan menu', strtolower((string) $notFound->json('data.assistant_text')));

        $recommendation = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'rekomendasi makanan',
        ]);
        $recommendationText = (string) $recommendation->json('data.assistant_text');
        $this->assertStringContainsString('Menu 01', $recommendationText);
        $this->assertStringContainsString('Menu 02', $recommendationText);
        $this->assertStringContainsString('Menu 03', $recommendationText);
        $this->assertStringNotContainsString('Menu 04', $recommendationText);
        $this->assertSame($originalStops, $recommendation->json('data.shopping.stops'));

        $isolatedSession = 'shopping-menu-browse-isolated-session';
        $this->saveShoppingDraft($customer, $isolatedSession, [$restaurant]);
        $isolatedNavigation = $this->postJson('/api/chatbot/process', [
            'session_id' => $isolatedSession,
            'service_type' => 'nitip',
            'message' => 'menu berikutnya',
        ]);
        $this->assertStringContainsString('Belum ada daftar menu', (string) $isolatedNavigation->json('data.assistant_text'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_chatbot_shopping_resolves_draft_restaurant_by_order_name_partial_and_typo(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $alpha = $this->createRestaurantWithMenus('Warung Alpha', 'warung-alpha-chatbot', -7.001, 110.401, ['Ayam Alpha']);
        $beta = $this->createRestaurantWithMenus('Warung Beta', 'warung-beta-chatbot', -7.002, 110.402, ['Bakso Beta']);
        $sessionId = 'shopping-menu-multi-session';
        $this->saveShoppingDraft($customer, $sessionId, [$alpha, $beta]);
        $this->fakeGeminiAndDistance(['intent' => 'shopping_order', 'command' => 'none', 'items' => []]);
        Sanctum::actingAs($customer);

        $clarification = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'tampilkan menu',
        ]);
        $clarification->assertOk();
        $clarificationText = (string) $clarification->json('data.assistant_text');
        $this->assertStringContainsString('1. Warung Alpha', $clarificationText);
        $this->assertStringContainsString('2. Warung Beta', $clarificationText);
        $originalStops = $clarification->json('data.shopping.stops');

        $byOrder = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'yang kedua',
        ]);
        $byOrder->assertJsonPath('model_used', 'deterministic-assistant');
        $this->assertStringContainsString('Bakso Beta', (string) $byOrder->json('data.assistant_text'));

        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'tampilkan menu',
        ])->assertOk();
        $byNumber = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => '1',
        ]);
        $byNumber->assertJsonPath('model_used', 'deterministic-assistant');
        $this->assertStringContainsString('Ayam Alpha', (string) $byNumber->json('data.assistant_text'));

        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'tampilkan menu',
        ])->assertOk();
        $byNameReply = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'Warung Alpha',
        ]);
        $byNameReply->assertJsonPath('model_used', 'deterministic-assistant');
        $this->assertStringContainsString('Ayam Alpha', (string) $byNameReply->json('data.assistant_text'));

        $byPartialName = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'menu Alpha',
        ]);
        $this->assertStringContainsString('Ayam Alpha', (string) $byPartialName->json('data.assistant_text'));

        $byTypo = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'menu Warung Alpa',
        ]);
        $this->assertStringContainsString('Ayam Alpha', (string) $byTypo->json('data.assistant_text'));

        $ambiguous = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'menu Warung',
        ]);
        $this->assertStringContainsString('lebih dari satu pilihan', (string) $ambiguous->json('data.assistant_text'));

        $notInDraft = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'menu Resto Tidak Ada',
        ]);
        $this->assertStringContainsString('belum ada di draft', (string) $notInDraft->json('data.assistant_text'));

        $conflict = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'menu restoran pertama Warung Beta',
        ]);
        $this->assertStringContainsString('tidak menunjuk pilihan yang sama', (string) $conflict->json('data.assistant_text'));
        $this->assertSame($originalStops, $conflict->json('data.shopping.stops'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_chatbot_shopping_recommends_three_nearest_restaurants_without_creating_draft(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => $customer->name,
            'phone' => $customer->phone ?: '081200000001',
            'full_address' => 'Jl. Rekomendasi No. 1',
            'latitude' => -7.000,
            'longitude' => 110.400,
            'is_default' => true,
        ]);
        $nearest = $this->createRestaurantWithMenus('Resto Paling Dekat', 'resto-paling-dekat', -7.001, 110.401, ['Dekat Satu', 'Dekat Dua', 'Dekat Tiga']);
        $second = $this->createRestaurantWithMenus('Resto Dekat Kedua', 'resto-dekat-kedua', -7.010, 110.410, ['Kedua Satu', 'Kedua Dua']);
        $third = $this->createRestaurantWithMenus('Resto Dekat Ketiga', 'resto-dekat-ketiga', -7.020, 110.420, ['Ketiga Satu', 'Ketiga Dua']);
        $this->createRestaurantWithMenus('Resto Terjauh', 'resto-terjauh', -7.100, 110.500, ['Jauh Satu']);

        $this->fakeGeminiAndDistance(['intent' => 'shopping_order', 'command' => 'none', 'items' => []]);
        Sanctum::actingAs($customer);
        $prompts = [
            'aku bingung mau makan apa',
            'enaknya pesan apa?',
            'nitip apa enaknya',
            'enaknya nitip apa',
            'baiknya beli apa',
            'mau pesan apa',
        ];

        foreach ($prompts as $index => $prompt) {
            $response = $this->postJson('/api/chatbot/process', [
                'session_id' => 'shopping-nearby-recommendation-session-'.$index,
                'service_type' => 'nitip',
                'message' => $prompt,
            ]);

            $response->assertOk()
                ->assertJsonPath('model_used', 'deterministic-assistant')
                ->assertJsonPath('data.shopping.stops', []);
            $text = (string) $response->json('data.assistant_text');
            $this->assertLessThan(strpos($text, $second->name), strpos($text, $nearest->name));
            $this->assertLessThan(strpos($text, $third->name), strpos($text, $second->name));
            $this->assertStringNotContainsString('Resto Terjauh', $text);
            $this->assertStringContainsString('Dekat Satu', $text);
            $this->assertStringContainsString('Dekat Dua', $text);
            $this->assertStringNotContainsString('Dekat Tiga', $text);
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_chatbot_shopping_concrete_nitip_item_is_not_treated_as_recommendation(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'none',
            'merchant' => null,
            'items' => [
                ['name' => 'nasi goreng', 'quantity' => 1],
            ],
        ]);
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-concrete-nitip-item-session',
            'service_type' => 'nitip',
            'message' => 'nitip nasi goreng 1',
        ]);

        $response->assertOk();
        $this->assertNotSame('deterministic-assistant', $response->json('model_used'));
        $this->assertSame('nasi goreng', $response->json('data.shopping.items.0.name'));
        $this->assertSame(1, $response->json('data.shopping.items.0.quantity'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_chatbot_shopping_recommendation_without_location_keeps_open_addresses_action(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $this->createRestaurantWithMenus('Resto Lokasi Wajib', 'resto-lokasi-wajib', -7.001, 110.401, ['Menu Lokasi']);
        $this->fakeGeminiAndDistance(['intent' => 'shopping_order', 'command' => 'none', 'items' => []]);
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-recommendation-no-location-session',
            'service_type' => 'nitip',
            'message' => 'rekomendasi makanan',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('Lengkapi alamatmu', (string) $response->json('data.assistant_text'));
        $this->assertContains('OPEN_ADDRESSES', $response->json('data.validation.next_actions'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_chatbot_shopping_external_restaurant_menu_request_falls_back_to_manual_items(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);
        $sessionId = 'shopping-external-menu-session';
        $merchant = [
            'id' => null,
            'name' => 'Kedai Eksternal',
            'merchant_place' => [
                'place_id' => 'external-place-1',
                'name' => 'Kedai Eksternal',
                'address' => 'Jl. Eksternal No. 1',
                'latitude' => -7.001,
                'longitude' => 110.401,
                'types' => ['restaurant'],
            ],
        ];
        app(ChatbotDraftStore::class)->savePayload($customer, $sessionId, [
            'intent' => 'shopping_order',
            'service_type' => 'nitip',
            'shopping' => [
                'merchant' => $merchant,
                'delivery' => [
                    'address' => 'Jl. Draft No. 1',
                    'latitude' => -7.000,
                    'longitude' => 110.400,
                    'source' => 'map_pin',
                ],
                'items' => [],
                'stops' => [[
                    'merchant' => $merchant,
                    'items' => [],
                    'is_active' => true,
                ]],
                'active_stop_index' => 0,
                'ready_to_confirm' => false,
                'payment_method' => null,
            ],
            'order' => ['created' => false],
        ]);
        $this->fakeGeminiAndDistance(['intent' => 'shopping_order', 'command' => 'none', 'items' => []]);
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'tampilkan menu',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('belum tersedia di katalog', (string) $response->json('data.assistant_text'));
        $this->assertStringContainsString('manual', (string) $response->json('data.assistant_text'));
        $this->assertSame('Kedai Eksternal', $response->json('data.shopping.stops.0.merchant.name'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_chatbot_gemini_normalizes_informational_food_command_as_shopping_context(): void
    {
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'search_menu',
            'merchant' => 'Warung Alpha',
            'menu_search' => 'ayam geprek',
            'items' => [],
        ]);

        $parsed = app(ChatbotGeminiService::class)->parseFoodOrder('Warung Alpha punya ayam geprek tidak?');

        $this->assertSame('shopping_order', $parsed['payload']['intent']);
        $this->assertSame('search_menu', $parsed['payload']['command']);
        $this->assertSame('Warung Alpha', $parsed['payload']['merchant']);
        $this->assertSame('ayam geprek', $parsed['payload']['menu_search']);
        $this->assertSame([], $parsed['payload']['items']);
    }

    public function test_chatbot_shopping_qris_payment_only_message_sets_transfer_method(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000021',
            'full_address' => 'Jl. Customer No. 21',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Ayam Geprek Transfer',
            'slug' => 'ayam-geprek-transfer-test',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Merchant No. 21',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000022',
        ]);

        Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Paket Geprek Original',
            'price' => 22000,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($customer);

        $paymentTokens = ['QRIS', 'tf', 'non tunai'];
        foreach ($paymentTokens as $index => $paymentToken) {
            $this->fakeGeminiAndDistance([
                'intent' => 'shopping_order',
                'command' => 'none',
                'merchant' => 'Ayam Geprek Transfer',
                'resto' => 'Ayam Geprek Transfer',
                'items' => [
                    ['name' => 'Paket Geprek Original', 'menu' => 'Paket Geprek Original', 'quantity' => 1, 'qty' => 1],
                ],
            ]);

            $sessionId = 'shopping-transfer-token-'.$index;
            $this->postJson('/api/chatbot/process', [
                'session_id' => $sessionId,
                'service_type' => 'nitip',
                'message' => 'titip 1 paket geprek original dari Ayam Geprek Transfer',
            ])
                ->assertOk()
                ->assertJsonPath('data.shopping.ready_to_confirm', true);

            $paymentResponse = $this->postJson('/api/chatbot/process', [
                'session_id' => $sessionId,
                'service_type' => 'nitip',
                'message' => $paymentToken,
            ]);

            $paymentResponse->assertOk()
                ->assertJsonPath('data.shopping.payment_method', 'TRANSFER')
                ->assertJsonPath('model_used', 'deterministic-payment');
            $this->assertContains(
                'CONFIRM_DRAFT',
                $paymentResponse->json('data.validation.next_actions'),
                'CONFIRM_DRAFT missing after payment token: '.$paymentToken
            );
            $this->assertStringContainsString(
                'Metode pembayaran: QRIS.',
                (string) $paymentResponse->json('data.assistant_text'),
                'Assistant text mismatch for payment token: '.$paymentToken
            );
        }
    }

    /**
     * @return array<string, int>
     */
    private function shoppingItemQuantities(TestResponse $response): array
    {
        $items = $response->json('data.shopping.items') ?? [];
        $quantities = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = strtolower(trim((string) ($item['name'] ?? $item['menu_name'] ?? '')));
            if ($name === '') {
                continue;
            }

            $quantities[$name] = (int) ($item['quantity'] ?? 0);
        }

        ksort($quantities);

        return $quantities;
    }

    /**
     * @param  array<int, Restaurant>  $restaurants
     */
    private function saveShoppingDraft(User $customer, string $sessionId, array $restaurants): void
    {
        $stops = array_map(fn (Restaurant $restaurant, int $index): array => [
            'merchant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
            ],
            'items' => [],
            'is_active' => $index === count($restaurants) - 1,
        ], $restaurants, array_keys($restaurants));

        app(ChatbotDraftStore::class)->savePayload($customer, $sessionId, [
            'intent' => 'shopping_order',
            'service_type' => 'nitip',
            'shopping' => [
                'merchant' => $stops[array_key_last($stops)]['merchant'] ?? [],
                'delivery' => [
                    'address' => 'Jl. Draft No. 1',
                    'latitude' => -7.000,
                    'longitude' => 110.400,
                    'source' => 'map_pin',
                ],
                'items' => [],
                'stops' => $stops,
                'active_stop_index' => max(0, count($stops) - 1),
                'ready_to_confirm' => false,
                'payment_method' => null,
            ],
            'order' => ['created' => false],
        ]);
    }

    /**
     * @param  array<int, string>  $menuNames
     */
    private function createRestaurantWithMenus(
        string $name,
        string $slug,
        float $latitude,
        float $longitude,
        array $menuNames,
    ): Restaurant {
        $restaurant = Restaurant::query()->create([
            'name' => $name,
            'slug' => $slug,
            'merchant_type' => 'restaurant',
            'address' => 'Jl. '.$name,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
        foreach ($menuNames as $index => $menuName) {
            Menu::query()->create([
                'restaurant_id' => $restaurant->id,
                'name' => $menuName,
                'price' => 10000 + ($index * 1000),
                'is_available' => true,
                'sort_order' => $index + 1,
            ]);
        }

        return $restaurant;
    }

    /**
     * @param  array<string, mixed>  $geminiPayload
     */
    public function test_chatbot_shopping_lihat_menu_registered_resto_returns_menu_selector_signal(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000201',
            'full_address' => 'Jl. Customer No. 201',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Nasgor Gajah',
            'slug' => 'nasgor-gajah-lihat-menu-test',
            'merchant_type' => 'warung',
            'address' => 'Jl. Nasgor No. 1',
            'latitude' => -7.004,
            'longitude' => 110.404,
            'phone' => '081200000202',
        ]);

        Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Nasi Goreng Spesial',
            'price' => 15000,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        // Fast-path menangani show_menu; fake HTTP mencegah request nyata.
        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'show_menu',
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-lihat-menu-session',
            'service_type' => 'nitip',
            'message' => 'Lihat menu Nasgor Gajah',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.menu_selector.merchant_id', $restaurant->id)
            ->assertJsonPath('data.menu_selector.merchant_name', 'Nasgor Gajah')
            ->assertJsonPath('data.menu_selector.mode', 'select');

        // Resto terdaftar dijadikan tempat aktif di draft.
        $this->assertSame(
            $restaurant->id,
            (int) $response->json('data.shopping.stops.0.merchant.id')
        );

        $this->assertStringContainsString(
            'menu Nasgor Gajah',
            (string) $response->json('data.assistant_text')
        );
    }

    public function test_chatbot_shopping_lihat_menu_unregistered_resto_has_no_menu_selector(): void
    {
        $customer = User::factory()->create([
            'role' => 'customer',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer Test',
            'phone' => '081200000203',
            'full_address' => 'Jl. Customer No. 203',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $this->fakeGeminiAndDistance([
            'intent' => 'shopping_order',
            'command' => 'show_menu',
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/chatbot/process', [
            'session_id' => 'shopping-lihat-menu-unknown-session',
            'service_type' => 'nitip',
            'message' => 'Lihat menu Restoran Tidak Terdaftar XYZ',
        ]);

        $response->assertOk();
        $this->assertNull($response->json('data.menu_selector'));
    }

    private function fakeGeminiAndDistance(array $geminiPayload, int $distanceMeters = 2500): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode($geminiPayload)],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://routes.googleapis.com/*' => Http::response([
                'routes' => [[
                    'distanceMeters' => $distanceMeters,
                    'duration' => '600s',
                    'polyline' => [
                        'encodedPolyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
                    ],
                    'legs' => [[
                        'distanceMeters' => $distanceMeters,
                        'duration' => '600s',
                    ]],
                ]],
            ], 200),
            'https://maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
                'status' => 'OK',
                'rows' => [
                    [
                        'elements' => [
                            [
                                'status' => 'OK',
                                'distance' => ['value' => $distanceMeters, 'text' => '2,5 km'],
                                'duration' => ['value' => 600, 'text' => '10 menit'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);
    }
}
