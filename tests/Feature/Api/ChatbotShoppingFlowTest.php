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
            'description' => 'Warung kebutuhan harian',
            'merchant_type' => 'warung',
            'address' => 'Jl. Warung No. 2',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000004',
            'status' => 'active',
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
            ->assertJsonPath('data.action_payloads.OPEN_MERCHANT_PICKER.label', 'Pilih Merchant di Map');
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
        $this->assertStringContainsString('Merchant', $assistantText);
        $this->assertStringContainsString('Kedai Tinari', $assistantText);
        $this->assertStringContainsString('Tulis item dan jumlah untuk merchant ini.', $assistantText);
        $this->assertStringContainsString('- susu 1', $assistantText);
        $this->assertStringContainsString('- roti tawar 2', $assistantText);
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
            ->assertJsonPath('data.action_payloads.OPEN_MAP_PICKER_DELIVERY.label', 'Ganti Titik Antar');

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
            'contact_name' => 'Alfamart Undip Prof. Soedarto',
        ]);

        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('MANUAL', $item->item_source);
        $this->assertSame('google-place-alfamart-undip', $item->metadata['place_id'] ?? null);
    }

    public function test_chatbot_shopping_creates_menu_database_restaurant_order_after_confirmation(): void
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
        ]);

        $category = MenuCategory::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Paket',
            'sort_order' => 1,
        ]);

        $menu = Menu::query()->create([
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
            ->assertJsonPath('data.shopping.items.0.item_source', 'MENU_DB')
            ->assertJsonPath('data.shopping.items.0.menu_id', $menu->id)
            ->assertJsonPath('data.shopping.items.0.unit_price', 22000)
            ->assertJsonPath('data.shopping.items.0.subtotal', 44000)
            ->assertJsonPath('data.shopping.items.0.metadata.price_status', 'CONFIRMED')
            ->assertJsonPath('data.action_payloads.OPEN_MAP_PICKER_DELIVERY.label', 'Ganti Titik Antar');
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
            'user_id' => $customer->id,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'subtotal' => 44000,
        ]);

        $this->assertDatabaseHas('shopping_order_items', [
            'order_id' => $orderId,
            'menu_id' => $menu->id,
            'item_source' => 'MENU_DB',
            'quantity' => 2,
            'unit_price' => 22000,
            'subtotal' => 44000,
            'is_heavy' => false,
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
            'description' => 'Kedai ramen',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Kedai Tinari',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000022',
            'status' => 'active',
        ]);
        $secondRestaurant = Restaurant::query()->create([
            'name' => 'Warung Sembako Maju',
            'slug' => 'warung-sembako-maju-chatbot-test',
            'description' => 'Warung sembako',
            'merchant_type' => 'warung',
            'address' => 'Jl. Warung Sembako',
            'latitude' => -7.004,
            'longitude' => 110.404,
            'phone' => '081200000023',
            'status' => 'active',
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
            ->assertJsonPath('data.action_payloads.OPEN_ADD_MERCHANT_PICKER.label', 'Tambah Merchant')
            ->assertJsonPath('data.action_payloads.OPEN_ADD_MERCHANT_PICKER.mode', 'add');
        $this->assertStringContainsString(
            'Mau tambah merchant lain? Pilih merchantnya dulu.',
            (string) $firstDraftResponse->json('data.assistant_text')
        );

        $addCommandResponse = $this->postJson('/api/chatbot/process', [
            'session_id' => $sessionId,
            'service_type' => 'nitip',
            'message' => 'tambah order',
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
            'Tulis item dan jumlah untuk merchant ini.',
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
            'description' => 'Resto ayam geprek',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Taman Kedai No. 1',
            'latitude' => -7.001,
            'longitude' => 110.401,
            'phone' => '081200000007',
            'status' => 'active',
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
            'description' => 'Warung kebutuhan harian',
            'merchant_type' => 'warung',
            'address' => 'Jl. Warung No. 2',
            'latitude' => -7.002,
            'longitude' => 110.402,
            'phone' => '081200000004',
            'status' => 'active',
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
