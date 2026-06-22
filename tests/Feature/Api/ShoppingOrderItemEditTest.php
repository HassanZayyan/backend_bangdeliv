<?php

namespace Tests\Feature\Api;

use App\Events\OrderContentUpdated;
use App\Models\Driver;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\Restaurant;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShoppingOrderItemEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_add_manual_item_while_order_is_pending(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items', [
            'item_source' => 'MANUAL',
            'menu_name' => 'Gula 1 kg',
            'quantity' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_price', '25000.00');

        $this->assertDatabaseHas('shopping_order_items', [
            'order_id' => $order->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Gula 1 kg',
            'unit_price' => 0,
        ]);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 25000,
        ]);
    }

    public function test_customer_can_add_manual_item_from_new_merchant_before_driver_is_assigned(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(2500);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');
        $warung = $this->createMerchant('Warung Madura Barokah', 'warung-madura-barokah-test', -7.006, 110.406, 'warung');
        Event::fake([OrderContentUpdated::class]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items', [
            'merchant_id' => $warung->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Telur 1 kg',
            'quantity' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', '15000.00')
            ->assertJsonPath('data.total_price', '35000.00')
            ->assertJsonPath('data.shopping_stops.1.merchant.id', $warung->id)
            ->assertJsonPath('data.shopping_stops.1.merchant.latitude', -7.006)
            ->assertJsonPath('data.shopping_stops.1.merchant.longitude', 110.406)
            ->assertJsonPath('data.shopping_stops.1.items.0.price_status', 'PENDING_DRIVER_INPUT');

        Event::assertDispatched(
            OrderContentUpdated::class,
            fn (OrderContentUpdated $event): bool => $event->orderId === $order->id
                && $event->changeType === 'SHOPPING_ROUTE_UPDATED'
        );

        $this->assertDatabaseHas('order_locations', [
            'order_id' => $order->id,
            'restaurant_id' => $warung->id,
            'location_role' => 'PICKUP',
            'sequence_no' => 2,
        ]);

        $pickupId = (int) $response->json('data.shopping_stops.1.pickup_location_id');
        $this->assertDatabaseHas('shopping_order_items', [
            'order_id' => $order->id,
            'pickup_location_id' => $pickupId,
            'item_source' => 'MANUAL',
            'menu_name' => 'Telur 1 kg',
            'unit_price' => 0,
        ]);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 35000,
        ]);
    }

    public function test_customer_can_add_manual_item_from_google_place_without_creating_restaurant(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(2500);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items/bulk', [
            'items' => [
                [
                    'merchant_place' => [
                        'place_id' => 'google-place-warung-baru',
                        'name' => 'Warung Google Baru',
                        'address' => 'Jl. Warung Google Baru, Semarang',
                        'latitude' => -7.0061,
                        'longitude' => 110.4061,
                        'types' => ['food', 'store'],
                    ],
                    'item_source' => 'MANUAL',
                    'menu_name' => 'Es teh jumbo',
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', '15000.00')
            ->assertJsonPath('data.total_price', '35000.00')
            ->assertJsonPath('data.shopping_stops.1.merchant.id', null)
            ->assertJsonPath('data.shopping_stops.1.merchant.name', 'Warung Google Baru')
            ->assertJsonPath('data.shopping_stops.1.items.0.menu_name', 'Es teh jumbo');

        $pickupId = (int) $response->json('data.shopping_stops.1.pickup_location_id');
        $this->assertDatabaseHas('order_locations', [
            'id' => $pickupId,
            'order_id' => $order->id,
            'restaurant_id' => null,
            'label' => 'Warung Google Baru',
            'full_address' => 'Jl. Warung Google Baru, Semarang',
        ]);

        $item = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('menu_name', 'Es teh jumbo')
            ->firstOrFail();

        $this->assertSame($pickupId, (int) $item->pickup_location_id);
        $this->assertSame('CUSTOMER_GOOGLE_PLACE', $item->metadata['source'] ?? null);
        $this->assertSame('google-place-warung-baru', $item->metadata['place_id'] ?? null);
    }

    public function test_google_place_item_cannot_use_menu_db_source(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items/bulk', [
            'items' => [
                [
                    'merchant_place' => [
                        'place_id' => 'google-place-resto',
                        'name' => 'Resto Google',
                        'address' => 'Jl. Resto Google, Semarang',
                        'latitude' => -7.0061,
                        'longitude' => 110.4061,
                    ],
                    'item_source' => 'MENU_DB',
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Menu database hanya tersedia untuk merchant resmi BangDeliv.');
    }

    public function test_google_place_item_still_respects_maximum_shopping_route_distance(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(26000);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items/bulk', [
            'items' => [
                [
                    'merchant_place' => [
                        'place_id' => 'google-place-far',
                        'name' => 'Tempat Google Jauh',
                        'address' => 'Jl. Terlalu Jauh',
                        'latitude' => -7.7061,
                        'longitude' => 110.9061,
                    ],
                    'item_source' => 'MANUAL',
                    'menu_name' => 'Air mineral',
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString('melebihi batas layanan', (string) $response->json('message'));
        $this->assertDatabaseMissing('order_locations', [
            'order_id' => $order->id,
            'label' => 'Tempat Google Jauh',
        ]);
    }

    public function test_customer_can_bulk_add_manual_items_from_same_merchant_without_recalculating_delivery_fee(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        Http::fake();

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items/bulk', [
            'items' => [
                [
                    'merchant_id' => $order->restaurant_id,
                    'item_source' => 'MANUAL',
                    'menu_name' => 'Gula 1 kg',
                    'quantity' => 1,
                ],
                [
                    'merchant_id' => $order->restaurant_id,
                    'item_source' => 'MANUAL',
                    'menu_name' => 'Kopi sachet',
                    'quantity' => 3,
                    'notes' => 'Yang ABC',
                ],
                [
                    'merchant_id' => $order->restaurant_id,
                    'item_source' => 'MANUAL',
                    'menu_name' => 'Sabun mandi',
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', '5000.00')
            ->assertJsonPath('data.total_price', '25000.00')
            ->assertJsonCount(1, 'data.shopping_stops');

        $this->assertSame(4, OrderItem::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseHas('shopping_order_items', [
            'order_id' => $order->id,
            'menu_name' => 'Kopi sachet',
            'quantity' => 3,
            'unit_price' => 0,
        ]);

        Http::assertNothingSent();
    }

    public function test_customer_can_bulk_add_items_from_multiple_new_merchants_and_recalculate_once(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(2500);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');
        $warung = $this->createMerchant('Warung Madura Bulk', 'warung-madura-bulk-test', -7.006, 110.406, 'warung');
        $alfa = $this->createMerchant('Alfamart Bulk', 'alfamart-bulk-test', -7.008, 110.408, 'convenience_store');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items/bulk', [
            'items' => [
                [
                    'merchant_id' => $warung->id,
                    'item_source' => 'MANUAL',
                    'menu_name' => 'Telur 1 kg',
                    'quantity' => 1,
                ],
                [
                    'merchant_id' => $alfa->id,
                    'item_source' => 'MANUAL',
                    'menu_name' => 'Susu UHT',
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', '19000.00')
            ->assertJsonPath('data.total_price', '39000.00')
            ->assertJsonCount(3, 'data.shopping_stops');

        $this->assertDatabaseHas('order_locations', [
            'order_id' => $order->id,
            'restaurant_id' => $warung->id,
            'location_role' => 'PICKUP',
            'sequence_no' => 2,
        ]);
        $this->assertDatabaseHas('order_locations', [
            'order_id' => $order->id,
            'restaurant_id' => $alfa->id,
            'location_role' => 'PICKUP',
            'sequence_no' => 3,
        ]);
        $this->assertDatabaseHas('shopping_order_items', [
            'order_id' => $order->id,
            'menu_name' => 'Susu UHT',
            'quantity' => 2,
            'unit_price' => 0,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 39000,
        ]);
    }

    public function test_customer_adds_restaurant_item_as_manual_and_recalculates_delivery_fee(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(2500);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');
        $restaurant = $this->createMerchant('Resto Soto Baru', 'resto-soto-baru-test', -7.007, 110.407);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items', [
            'merchant_id' => $restaurant->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Soto Ayam',
            'quantity' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', '15000.00')
            ->assertJsonPath('data.total_price', '35000.00')
            ->assertJsonPath('data.shopping_stops.1.merchant.id', $restaurant->id)
            ->assertJsonPath('data.shopping_stops.1.items.0.item_source', 'MANUAL')
            ->assertJsonPath('data.shopping_stops.1.items.0.price_status', 'PENDING_DRIVER_INPUT');

        $this->assertDatabaseHas('shopping_order_items', [
            'order_id' => $order->id,
            'menu_id' => null,
            'item_source' => 'MANUAL',
            'menu_name' => 'Soto Ayam',
            'unit_price' => 0,
        ]);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 35000,
        ]);
    }

    public function test_customer_can_add_restaurant_menu_database_item_with_price_snapshot(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        Http::fake();

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');
        $menu = Menu::query()->create([
            'restaurant_id' => $order->restaurant_id,
            'name' => 'Soto Ayam',
            'price' => 18000,
            'is_available' => true,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items', [
            'merchant_id' => $order->restaurant_id,
            'item_source' => 'MENU_DB',
            'menu_id' => $menu->id,
            'quantity' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', '5000.00')
            ->assertJsonPath('data.total_price', '61000.00')
            ->assertJsonPath('data.shopping_stops.0.items.1.menu_id', $menu->id)
            ->assertJsonPath('data.shopping_stops.0.items.1.item_source', 'MENU_DB')
            ->assertJsonPath('data.shopping_stops.0.items.1.price_status', 'CONFIRMED');

        $this->assertDatabaseHas('shopping_order_items', [
            'order_id' => $order->id,
            'menu_id' => $menu->id,
            'item_source' => 'MENU_DB',
            'menu_name' => 'Soto Ayam',
            'quantity' => 2,
            'unit_price' => 18000,
            'subtotal' => 36000,
        ]);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 61000,
        ]);

        Http::assertNothingSent();
    }

    public function test_customer_menu_database_item_requires_menu_id(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items', [
            'merchant_id' => $order->restaurant_id,
            'item_source' => 'MENU_DB',
            'quantity' => 1,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['menu_id']);
    }

    public function test_customer_cannot_add_menu_database_item_from_different_merchant(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');
        $otherRestaurant = $this->createMerchant('Resto Lain', 'resto-lain-menu-test', -7.009, 110.409);
        $otherMenu = Menu::query()->create([
            'restaurant_id' => $otherRestaurant->id,
            'name' => 'Soto Beda Merchant',
            'price' => 19000,
            'is_available' => true,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items', [
            'merchant_id' => $order->restaurant_id,
            'item_source' => 'MENU_DB',
            'menu_id' => $otherMenu->id,
            'quantity' => 1,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Menu tidak ditemukan atau tidak sesuai merchant.');
    }

    public function test_customer_adds_manual_item_from_existing_restaurant_without_recalculating_delivery_fee(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        Http::fake();

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items', [
            'merchant_id' => $order->restaurant_id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Es Jeruk',
            'quantity' => 2,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', '5000.00')
            ->assertJsonPath('data.total_price', '25000.00')
            ->assertJsonCount(1, 'data.shopping_stops')
            ->assertJsonPath('data.shopping_stops.0.items.1.item_source', 'MANUAL');

        Http::assertNothingSent();
    }

    public function test_customer_cannot_add_new_merchant_after_driver_arrived_merchant(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(2500);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'ARRIVED_MERCHANT');
        $warung = $this->createMerchant('Warung Madura Barokah', 'warung-madura-arrived-test', -7.006, 110.406, 'warung');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items', [
            'merchant_id' => $warung->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Telur 1 kg',
            'quantity' => 1,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Item tidak bisa diubah pada status order saat ini.');
    }

    public function test_customer_cannot_bulk_add_new_merchant_after_driver_arrived_merchant(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(2500);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'ARRIVED_MERCHANT');
        $warung = $this->createMerchant('Warung Madura Bulk Arrived', 'warung-madura-bulk-arrived-test', -7.006, 110.406, 'warung');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items/bulk', [
            'items' => [
                [
                    'merchant_id' => $warung->id,
                    'item_source' => 'MANUAL',
                    'menu_name' => 'Telur 1 kg',
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Item tidak bisa diubah pada status order saat ini.');
    }

    public function test_customer_can_update_basic_manual_item_fields(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PENDING');
        $item = $order->items()->firstOrFail();

        Sanctum::actingAs($customer);

        $response = $this->patchJson('/api/v1/orders/'.$order->id.'/items/'.$item->id, [
            'menu_name' => 'Telur 2 kg',
            'quantity' => 1,
            'notes' => 'Tambah satu bungkus',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('shopping_order_items', [
            'id' => $item->id,
            'menu_name' => 'Telur 2 kg',
        ]);
    }

    public function test_customer_cannot_edit_shopping_items_after_picked_up(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'PICKED_UP');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items', [
            'item_source' => 'MANUAL',
            'menu_name' => 'Gula 1 kg',
            'quantity' => 1,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Item tidak bisa diubah pada status order saat ini.');
    }

    public function test_customer_direct_item_edit_is_rejected_after_driver_assigned(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'DRIVER_ASSIGNED');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items', [
            'item_source' => 'MANUAL',
            'menu_name' => 'Es teh',
            'quantity' => 1,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Item tidak bisa diubah pada status order saat ini.');
    }

    public function test_customer_edit_unavailable_item_applies_immediately_and_requires_requote(): void
    {
        [, $driver] = $this->createDriver();
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'ARRIVED_MERCHANT');
        $order->forceFill(['driver_id' => $driver->id])->save();
        $pickup = $order->orderLocations()->where('location_role', 'PICKUP')->firstOrFail();
        $unavailableItem = $order->items()->firstOrFail();
        $unavailableItem->update(['is_available' => false]);
        $menu = Menu::query()->create([
            'restaurant_id' => $order->restaurant_id,
            'name' => 'Es Teh Manis',
            'price' => 6000,
            'is_available' => true,
        ]);

        $order->logs()->create([
            'event_type' => 'SHOPPING_NEGOTIATION',
            'trigger_type' => 'CUSTOMER_PRICE_APPROVED',
            'changed_by_user_id' => $customer->id,
            'note' => 'Harga disetujui untuk test.',
            'metadata' => [
                'status' => 'APPROVED',
                'pickup_location_id' => $pickup->id,
                'approved_amount' => 20000,
            ],
        ]);

        Sanctum::actingAs($customer);

        $request = $this->postJson('/api/v1/orders/'.$order->id.'/shopping/item-change-request', [
            'action' => 'ADD',
            'request_kind' => 'EDIT_UNAVAILABLE',
            'target_pickup_location_id' => $pickup->id,
            'items' => [
                [
                    'merchant_id' => $order->restaurant_id,
                    'item_source' => 'MENU_DB',
                    'menu_id' => $menu->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $request->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shopping_item_change_request.status', 'APPROVED')
            ->assertJsonPath('data.shopping_item_change_request.requested_stops.0.pickup_location_id', $pickup->id)
            ->assertJsonPath('data.shopping_item_change_request.requested_stops.0.merchant_name', 'Resto Test Shopping')
            ->assertJsonPath('data.shopping_item_change_request.requested_stops.0.merchant_address', 'Jl. Resto Test Shopping')
            ->assertJsonPath('data.shopping_item_change_request.requested_stops.0.items.0.name', 'Es Teh Manis')
            ->assertJsonPath('data.shopping_capabilities.has_pending_item_change_request', false)
            ->assertJsonPath('data.shopping_negotiation.checkout_allowed', false);

        $this->assertDatabaseHas('shopping_order_items', [
            'order_id' => $order->id,
            'menu_id' => $menu->id,
            'menu_name' => 'Es Teh Manis',
            'unit_price' => 6000,
            'is_available' => true,
        ]);
        $this->assertDatabaseMissing('shopping_order_items', [
            'id' => $unavailableItem->id,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'SHOPPING_ITEM_CHANGE_REQUEST',
            'trigger_type' => 'CUSTOMER_ITEM_CHANGE_APPLIED',
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'SHOPPING_NEGOTIATION',
            'trigger_type' => 'SHOPPING_ITEM_CHANGE_REQUIRES_REQUOTE',
        ]);
    }

    public function test_customer_can_add_replacement_item_to_fixed_external_merchant_without_merchant_payload(): void
    {
        [, $driver] = $this->createDriver();
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'ARRIVED_MERCHANT');
        $order->forceFill(['driver_id' => $driver->id])->save();
        $externalPickup = $order->orderLocations()->create([
            'restaurant_id' => null,
            'location_role' => 'PICKUP',
            'label' => 'Kedai Tinari',
            'full_address' => 'Kedai Tinari, Jl. Sawunggaling III No.44',
            'latitude' => -7.0015,
            'longitude' => 110.4025,
            'sequence_no' => 2,
            'fulfillment_status' => 'ITEMS_PENDING_CUSTOMER',
        ]);
        $unavailableItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $externalPickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Es jeruk',
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
            'is_available' => false,
        ]);

        Sanctum::actingAs($customer);

        $request = $this->postJson('/api/v1/orders/'.$order->id.'/shopping/item-change-request', [
            'action' => 'ADD',
            'request_kind' => 'EDIT_UNAVAILABLE',
            'target_pickup_location_id' => $externalPickup->id,
            'items' => [
                [
                    'item_source' => 'MANUAL',
                    'menu_name' => 'Es teh',
                    'quantity' => 1,
                ],
            ],
        ]);

        $request->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shopping_item_change_request.status', 'APPROVED')
            ->assertJsonPath('data.shopping_item_change_request.requested_stops.0.pickup_location_id', $externalPickup->id)
            ->assertJsonPath('data.shopping_item_change_request.requested_stops.0.merchant_name', 'Kedai Tinari')
            ->assertJsonPath('data.shopping_item_change_request.requested_stops.0.items.0.name', 'Es teh')
            ->assertJsonPath('data.shopping_capabilities.has_pending_item_change_request', false);

        $this->assertDatabaseHas('shopping_order_items', [
            'order_id' => $order->id,
            'pickup_location_id' => $externalPickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Es teh',
            'is_available' => true,
        ]);
        $this->assertDatabaseMissing('shopping_order_items', [
            'id' => $unavailableItem->id,
        ]);
    }

    public function test_customer_can_continue_without_unavailable_item(): void
    {
        [, $driver] = $this->createDriver();
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'ARRIVED_MERCHANT');
        $order->forceFill(['driver_id' => $driver->id])->save();
        $pickup = $order->orderLocations()->where('location_role', 'PICKUP')->firstOrFail();
        $unavailableItem = $order->items()->firstOrFail();
        $unavailableItem->update(['is_available' => false]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Ramen mala',
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal' => 20000,
            'is_available' => true,
        ]);

        Sanctum::actingAs($customer);

        $request = $this->postJson('/api/v1/orders/'.$order->id.'/shopping/item-change-request', [
            'action' => 'REMOVE',
            'request_kind' => 'EDIT_UNAVAILABLE',
            'target_pickup_location_id' => $pickup->id,
            'item_id' => $unavailableItem->id,
        ]);

        $request->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shopping_item_change_request.status', 'APPROVED')
            ->assertJsonPath('data.shopping_item_change_request.action', 'REMOVE')
            ->assertJsonPath('data.shopping_item_change_request.target_pickup_location_id', $pickup->id)
            ->assertJsonPath('data.shopping_capabilities.has_pending_item_change_request', false);

        $this->assertDatabaseMissing('shopping_order_items', [
            'id' => $unavailableItem->id,
        ]);
        $this->assertDatabaseHas('order_locations', [
            'id' => $pickup->id,
            'fulfillment_status' => 'ITEMS_CONFIRMED',
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'SHOPPING_NEGOTIATION',
            'trigger_type' => 'SHOPPING_ITEM_CHANGE_REQUIRES_REQUOTE',
        ]);
    }

    public function test_customer_cannot_continue_without_only_unavailable_item_in_merchant(): void
    {
        [, $driver] = $this->createDriver();
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'ARRIVED_MERCHANT');
        $order->forceFill(['driver_id' => $driver->id])->save();
        $pickup = $order->orderLocations()->where('location_role', 'PICKUP')->firstOrFail();
        $unavailableItem = $order->items()->firstOrFail();
        $unavailableItem->update([
            'is_available' => false,
            'unit_price' => 0,
            'subtotal' => 0,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/shopping/item-change-request', [
            'action' => 'REMOVE',
            'request_kind' => 'EDIT_UNAVAILABLE',
            'target_pickup_location_id' => $pickup->id,
            'item_id' => $unavailableItem->id,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Merchant hanya punya item tidak tersedia. Pilih edit item atau batal merchant.');

        $this->assertDatabaseHas('shopping_order_items', [
            'id' => $unavailableItem->id,
            'is_available' => false,
        ]);
    }

    public function test_customer_can_cancel_unavailable_item_merchant(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(0);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'ARRIVED_MERCHANT');
        $firstPickup = $order->orderLocations()->where('location_role', 'PICKUP')->firstOrFail();
        $order->items()->where('pickup_location_id', $firstPickup->id)->update([
            'is_available' => false,
            'unit_price' => 0,
            'subtotal' => 0,
        ]);

        $secondMerchant = $this->createMerchant('Warung Tetap Jalan', 'warung-tetap-jalan', -7.006, 110.406, 'warung');
        $secondPickup = $order->orderLocations()->create([
            'restaurant_id' => $secondMerchant->id,
            'location_role' => 'PICKUP',
            'label' => $secondMerchant->name,
            'full_address' => $secondMerchant->address,
            'latitude' => $secondMerchant->latitude,
            'longitude' => $secondMerchant->longitude,
            'sequence_no' => 2,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $secondPickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Kopi susu',
            'quantity' => 1,
            'unit_price' => 18000,
            'subtotal' => 18000,
            'is_available' => true,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/shopping/item-change-request', [
            'action' => 'CANCEL_MERCHANT',
            'request_kind' => 'EDIT_UNAVAILABLE',
            'target_pickup_location_id' => $firstPickup->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Merchant Nitip berhasil dibatalkan.')
            ->assertJsonFragment([
                'pickup_location_id' => $firstPickup->id,
                'fulfillment_status' => 'FAILED',
            ]);

        $this->assertDatabaseHas('order_locations', [
            'id' => $firstPickup->id,
            'fulfillment_status' => 'FAILED',
        ]);
        $this->assertDatabaseHas('order_locations', [
            'id' => $secondPickup->id,
            'fulfillment_status' => 'PENDING',
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'SHOPPING_NEGOTIATION',
            'trigger_type' => 'CUSTOMER_CANCEL_MERCHANT',
        ]);
    }

    public function test_customer_cannot_skip_failed_merchant_in_new_flow(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(0);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'ARRIVED_MERCHANT');
        $failedPickup = $order->orderLocations()
            ->where('location_role', 'PICKUP')
            ->firstOrFail();
        $failedPickup->update([
            'fulfillment_status' => 'FAILED',
        ]);
        $order->items()->where('pickup_location_id', $failedPickup->id)->update([
            'is_available' => false,
            'unit_price' => 0,
            'subtotal' => 0,
        ]);

        $secondMerchant = $this->createMerchant('Warung Pengganti Skip', 'warung-pengganti-skip', -7.006, 110.406, 'warung');
        $secondPickup = $order->orderLocations()->create([
            'restaurant_id' => $secondMerchant->id,
            'location_role' => 'PICKUP',
            'label' => $secondMerchant->name,
            'full_address' => $secondMerchant->address,
            'latitude' => $secondMerchant->latitude,
            'longitude' => $secondMerchant->longitude,
            'sequence_no' => 2,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $secondPickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Beras 1 kg',
            'quantity' => 1,
            'unit_price' => 10000,
            'subtotal' => 10000,
            'is_available' => true,
        ]);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/shopping-stops/'.$failedPickup->id.'/skip');

        $response->assertStatus(410)
            ->assertJsonPath('message', 'Endpoint skip merchant sudah deprecated pada flow Nitip baru.');

        $this->assertDatabaseHas('order_locations', [
            'id' => $failedPickup->id,
            'fulfillment_status' => 'FAILED',
        ]);
    }

    public function test_customer_cannot_replace_failed_merchant_after_driver_arrived(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(2500);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'ARRIVED_MERCHANT');
        $failedPickup = $order->orderLocations()
            ->where('location_role', 'PICKUP')
            ->firstOrFail();
        $failedPickup->update([
            'fulfillment_status' => 'FAILED',
        ]);
        $order->items()->where('pickup_location_id', $failedPickup->id)->update([
            'is_available' => false,
            'unit_price' => 0,
            'subtotal' => 0,
        ]);
        $replacementMerchant = $this->createMerchant('Warung Pengganti Baru', 'warung-pengganti-baru', -7.006, 110.406, 'warung');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items/bulk', [
            'replacement_for_pickup_location_id' => $failedPickup->id,
            'items' => [
                [
                    'merchant_id' => $replacementMerchant->id,
                    'item_source' => 'MANUAL',
                    'menu_name' => 'Beras 1 kg',
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Item tidak bisa diubah pada status order saat ini.');

        $this->assertDatabaseHas('order_locations', [
            'id' => $failedPickup->id,
            'fulfillment_status' => 'FAILED',
        ]);
        $this->assertDatabaseMissing('order_locations', [
            'order_id' => $order->id,
            'restaurant_id' => $replacementMerchant->id,
            'location_role' => 'PICKUP',
        ]);
        $this->assertDatabaseMissing('shopping_order_items', [
            'order_id' => $order->id,
            'menu_name' => 'Beras 1 kg',
        ]);
    }

    private function createShoppingOrder(User $customer, string $statusCode): Order
    {
        $shoppingTypeId = (int) ServiceType::query()->where('code', 'SHOPPING')->value('id');
        $statusId = (int) OrderStatus::query()->where('code', $statusCode)->value('id');
        $merchant = $this->createMerchant('Resto Test Shopping', 'resto-test-shopping-'.strtolower($statusCode), -7.001, 110.401);

        $order = Order::query()->create([
            'order_number' => 'BD-CUS-'.strtoupper(substr(md5($statusCode.random_int(1, 999999)), 0, 8)),
            'user_id' => $customer->id,
            'restaurant_id' => $merchant->id,
            'service_type_id' => $shoppingTypeId,
            'subtotal' => 20000,
            'delivery_fee' => 5000,
            'service_fee' => 0,
            'total_price' => 25000,
            'status_id' => $statusId,
        ]);

        $pickup = $order->orderLocations()->create([
            'restaurant_id' => $merchant->id,
            'location_role' => 'PICKUP',
            'label' => $merchant->name,
            'full_address' => $merchant->address,
            'latitude' => $merchant->latitude,
            'longitude' => $merchant->longitude,
            'sequence_no' => 1,
        ]);

        $order->orderLocations()->create([
            'location_role' => 'DROPOFF',
            'label' => 'Titik Antar',
            'full_address' => 'Jl. Customer No. 1',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'sequence_no' => 2,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'pickup_location_id' => $pickup->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Telur 1 kg',
            'quantity' => 1,
            'unit_price' => 20000,
            'subtotal' => 20000,
            'is_available' => true,
        ]);

        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => 25000,
        ]);

        return $order;
    }

    /**
     * @return array{0: User, 1: Driver}
     */
    private function createDriver(): array
    {
        $driverUser = User::factory()->create([
            'role' => 'driver',
            'is_active' => true,
        ]);

        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'vehicle_type' => 'Motor Matic',
            'vehicle_brand' => 'Honda',
            'vehicle_model' => 'Beat',
            'vehicle_plate' => 'H '.random_int(1000, 9999).' TST',
            'registration_status' => 'active',
            'status' => 'busy',
        ]);

        return [$driverUser, $driver];
    }

    private function createMerchant(
        string $name,
        string $slug,
        float $latitude,
        float $longitude,
        string $merchantType = 'restaurant',
    ): Restaurant {
        return Restaurant::query()->create([
            'name' => $name,
            'slug' => $slug,
            'merchant_type' => $merchantType,
            'address' => 'Jl. '.$name,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'phone' => '0812'.random_int(10000000, 99999999),
        ]);
    }

    private function fakeDistance(int $distanceMeters): void
    {
        Http::fake([
            'https://routes.googleapis.com/*' => function ($request) use ($distanceMeters) {
                $data = $request->data();
                $intermediateCount = is_array($data['intermediates'] ?? null)
                    ? count($data['intermediates'])
                    : 0;
                $legCount = max(1, $intermediateCount + 1);
                $durationSeconds = 600 * $legCount;
                $legs = [];

                for ($index = 0; $index < $legCount; $index++) {
                    $legs[] = [
                        'distanceMeters' => $distanceMeters,
                        'duration' => '600s',
                    ];
                }

                return Http::response([
                    'routes' => [[
                        'distanceMeters' => $distanceMeters * $legCount,
                        'duration' => $durationSeconds.'s',
                        'optimizedIntermediateWaypointIndex' => $intermediateCount > 0
                            ? range(0, $intermediateCount - 1)
                            : [],
                        'legs' => $legs,
                        'polyline' => ['encodedPolyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@'],
                    ]],
                ], 200);
            },
            'https://maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
                'status' => 'OK',
                'rows' => [
                    [
                        'elements' => [
                            [
                                'status' => 'OK',
                                'distance' => ['value' => $distanceMeters, 'text' => number_format($distanceMeters / 1000, 1).' km'],
                                'duration' => ['value' => 600, 'text' => '10 menit'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);
    }
}
