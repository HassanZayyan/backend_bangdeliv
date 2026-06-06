<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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
            'description' => 'Warung kebutuhan harian',
            'merchant_type' => 'warung',
            'address' => 'Jl. Warung No. 2',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000004',
            'status' => 'active',
            'avg_rating' => 4.5,
            'total_reviews' => 4,
            'estimated_prep_time' => 5,
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
            'description' => 'Warung kebutuhan harian',
            'merchant_type' => 'warung',
            'address' => 'Jl. Warung No. 3',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000005',
            'status' => 'active',
            'avg_rating' => 4.5,
            'total_reviews' => 4,
            'estimated_prep_time' => 5,
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

    public function test_chatbot_shopping_creates_manual_restaurant_order_after_confirmation(): void
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
            'phone' => '081200000001',
            'full_address' => 'Jl. Customer No. 1',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'is_default' => true,
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Ayam Geprek Juara',
            'slug' => 'ayam-geprek-juara-test',
            'description' => 'Ayam geprek',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Merchant No. 1',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000002',
            'status' => 'active',
            'avg_rating' => 4.8,
            'total_reviews' => 10,
            'estimated_prep_time' => 10,
        ]);

        $category = MenuCategory::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Paket',
            'sort_order' => 1,
        ]);

        Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'menu_category_id' => $category->id,
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
            ->assertJsonPath('data.shopping.items.0.item_source', 'MANUAL')
            ->assertJsonPath('data.shopping.items.0.menu_id', null)
            ->assertJsonPath('data.shopping.items.0.unit_price', 0)
            ->assertJsonPath('data.shopping.items.0.metadata.price_status', 'PENDING_DRIVER_INPUT')
            ->assertJsonPath('data.action_payloads.OPEN_MAP_PICKER_DELIVERY.label', 'Ganti Titik Antar');
        $this->assertStringContainsString(
            'Estimasi ongkir sementara:',
            (string) $draftResponse->json('data.assistant_text')
        );

        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'COD',
        ])->assertOk()
            ->assertJsonPath('data.shopping.payment_method', 'COD');

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
            'user_id' => $customer->id,
            'restaurant_id' => $restaurant->id,
            'subtotal' => 0,
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'menu_id' => null,
            'item_source' => 'MANUAL',
            'quantity' => 2,
            'unit_price' => 0,
            'is_heavy' => false,
        ]);

        $this->assertDatabaseHas('order_locations', [
            'order_id' => $orderId,
            'restaurant_id' => $restaurant->id,
            'location_role' => 'PICKUP',
            'sequence_no' => 1,
        ]);

        $routeSnapshot = Order::query()->findOrFail($orderId)->route_snapshot;
        $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', $routeSnapshot['encoded_polyline'] ?? null);
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
            'description' => 'Warung kebutuhan harian',
            'merchant_type' => 'warung',
            'address' => 'Jl. Warung No. 2',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000004',
            'status' => 'active',
            'avg_rating' => 4.5,
            'total_reviews' => 4,
            'estimated_prep_time' => 5,
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

        $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'COD',
        ])->assertOk()
            ->assertJsonPath('data.shopping.payment_method', 'COD');

        $confirmResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'konfirmasi',
        ]);

        $confirmResponse->assertOk()
            ->assertJsonPath('data.order.created', true);

        $orderId = (int) $confirmResponse->json('data.order.id');
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'restaurant_id' => $warung->id,
        ]);

        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('MANUAL', $item->item_source);
        $this->assertSame('0.00', (string) $item->unit_price);
        $this->assertSame('PENDING_DRIVER_INPUT', $item->metadata['price_status'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $geminiPayload
     */
    private function fakeGeminiAndDistance(array $geminiPayload): void
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
                    'distanceMeters' => 2500,
                    'duration' => '600s',
                    'polyline' => [
                        'encodedPolyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
                    ],
                    'legs' => [[
                        'distanceMeters' => 2500,
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
                                'distance' => ['value' => 2500, 'text' => '2,5 km'],
                                'duration' => ['value' => 600, 'text' => '10 menit'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);
    }
}
