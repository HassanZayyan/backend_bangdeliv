<?php

namespace Tests\Feature\Api;

use App\Events\OrderContentUpdated;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\OrderStatus;
use App\Models\Restaurant;
use App\Models\ServiceType;
use App\Models\ShoppingOrder;
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

    public function test_customer_can_add_manual_item_until_driver_arrived_merchant(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'ARRIVED_MERCHANT');

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items', [
            'item_source' => 'MANUAL',
            'menu_name' => 'Gula 1 kg',
            'quantity' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_price', '25000.00');

        $this->assertDatabaseHas('order_items', [
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

    public function test_customer_can_add_manual_item_from_new_merchant_before_driver_shops(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(2500);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'DRIVER_ASSIGNED');
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
        $this->assertDatabaseHas('order_items', [
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

    public function test_customer_can_bulk_add_manual_items_from_same_merchant_without_recalculating_delivery_fee(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        Http::fake();

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'ARRIVED_MERCHANT');

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
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'menu_name' => 'Kopi sachet',
            'quantity' => 3,
            'unit_price' => 0,
            'is_heavy' => false,
        ]);

        Http::assertNothingSent();
    }

    public function test_customer_can_bulk_add_items_from_multiple_new_merchants_and_recalculate_once(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(2500);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'DRIVER_ASSIGNED');
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
            ->assertJsonPath('data.delivery_fee', '21000.00')
            ->assertJsonPath('data.total_price', '41000.00')
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
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'menu_name' => 'Susu UHT',
            'quantity' => 2,
            'unit_price' => 0,
            'is_heavy' => false,
        ]);
        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 41000,
        ]);
    }

    public function test_customer_adds_restaurant_item_as_manual_and_recalculates_delivery_fee(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        $this->fakeDistance(2500);

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'DRIVER_ASSIGNED');
        $restaurant = $this->createMerchant('Resto Soto Baru', 'resto-soto-baru-test', -7.007, 110.407);

        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders/'.$order->id.'/items', [
            'merchant_id' => $restaurant->id,
            'item_source' => 'MANUAL',
            'menu_name' => 'Soto Ayam',
            'quantity' => 1,
            'is_heavy' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.delivery_fee', '15000.00')
            ->assertJsonPath('data.total_price', '35000.00')
            ->assertJsonPath('data.shopping_stops.1.merchant.id', $restaurant->id)
            ->assertJsonPath('data.shopping_stops.1.items.0.item_source', 'MANUAL')
            ->assertJsonPath('data.shopping_stops.1.items.0.price_status', 'PENDING_DRIVER_INPUT');

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'menu_id' => null,
            'item_source' => 'MANUAL',
            'menu_name' => 'Soto Ayam',
            'unit_price' => 0,
            'is_heavy' => false,
        ]);

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
            'amount' => 35000,
        ]);
    }

    public function test_customer_adds_manual_item_from_existing_restaurant_without_recalculating_delivery_fee(): void
    {
        Config::set('bangdeliv.google_maps_api_key', 'test-key');
        Http::fake();

        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'ARRIVED_MERCHANT');

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
            ->assertJsonPath('message', 'Merchant baru hanya bisa ditambahkan sebelum driver mulai belanja.');
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
            ->assertJsonPath('message', 'Merchant baru hanya bisa ditambahkan sebelum driver mulai belanja.');
    }

    public function test_customer_update_cannot_change_item_heavy_status(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->createShoppingOrder($customer, 'DRIVER_ASSIGNED');
        $item = $order->items()->firstOrFail();

        Sanctum::actingAs($customer);

        $response = $this->patchJson('/api/v1/orders/'.$order->id.'/items/'.$item->id, [
            'menu_name' => 'Telur 2 kg',
            'quantity' => 1,
            'notes' => 'Tambah satu bungkus',
            'is_heavy' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'menu_name' => 'Telur 2 kg',
            'is_heavy' => false,
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
            'label' => 'Merchant',
            'contact_name' => $merchant->name,
            'contact_phone' => $merchant->phone,
            'full_address' => $merchant->address,
            'latitude' => $merchant->latitude,
            'longitude' => $merchant->longitude,
            'sequence_no' => 1,
        ]);

        $order->orderLocations()->create([
            'location_role' => 'DROPOFF',
            'label' => 'Titik Antar',
            'contact_name' => $customer->name,
            'contact_phone' => $customer->phone,
            'full_address' => 'Jl. Customer No. 1',
            'latitude' => -7.003,
            'longitude' => 110.403,
            'sequence_no' => 2,
        ]);

        ShoppingOrder::query()->create([
            'order_id' => $order->id,
            'failed_attempt_count' => 0,
            'item_surcharge' => 0,
            'overweight_surcharge' => 0,
            'cancellation_penalty' => 0,
            'has_overweight_item' => false,
            'recalculation_version' => 0,
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
            'is_heavy' => false,
        ]);

        OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => 25000,
        ]);

        return $order;
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
            'description' => 'Merchant test',
            'merchant_type' => $merchantType,
            'address' => 'Jl. '.$name,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'phone' => '0812'.random_int(10000000, 99999999),
            'status' => 'active',
            'avg_rating' => 4.5,
            'total_reviews' => 1,
            'estimated_prep_time' => 10,
        ]);
    }

    private function fakeDistance(int $distanceMeters): void
    {
        Http::fake([
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
