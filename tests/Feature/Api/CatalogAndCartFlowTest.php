<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Menu;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogAndCartFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_list_returns_only_active_restaurants(): void
    {
        Restaurant::query()->create([
            'name' => 'Resto Aktif',
            'slug' => 'resto-aktif',
            'description' => null,
            'address' => 'Jl. Aktif',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081234567890',
            'banner_image' => null,
            'status' => 'active',
            'avg_rating' => 4.50,
            'total_reviews' => 10,
            'estimated_prep_time' => 20,
        ]);

        Restaurant::query()->create([
            'name' => 'Resto Inaktif',
            'slug' => 'resto-inaktif',
            'description' => null,
            'address' => 'Jl. Inaktif',
            'latitude' => -6.21000000,
            'longitude' => 106.82666600,
            'phone' => '081111111111',
            'banner_image' => null,
            'status' => 'inactive',
            'avg_rating' => 4.00,
            'total_reviews' => 4,
            'estimated_prep_time' => 15,
        ]);

        $response = $this->getJson('/api/v1/restaurants');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'resto-aktif');
    }

    public function test_customer_cannot_add_cart_items_from_different_restaurants_in_one_active_cart(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '089999999999',
        ]);

        Sanctum::actingAs($user);

        $restaurantOne = Restaurant::query()->create([
            'name' => 'Resto Satu',
            'slug' => 'resto-satu',
            'description' => null,
            'address' => 'Jl. Satu',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081200000001',
            'banner_image' => null,
            'status' => 'active',
            'avg_rating' => 4.60,
            'total_reviews' => 11,
            'estimated_prep_time' => 20,
        ]);

        $restaurantTwo = Restaurant::query()->create([
            'name' => 'Resto Dua',
            'slug' => 'resto-dua',
            'description' => null,
            'address' => 'Jl. Dua',
            'latitude' => -6.21000000,
            'longitude' => 106.82666600,
            'phone' => '081200000002',
            'banner_image' => null,
            'status' => 'active',
            'avg_rating' => 4.30,
            'total_reviews' => 7,
            'estimated_prep_time' => 18,
        ]);

        $menuOne = Menu::query()->create([
            'restaurant_id' => $restaurantOne->id,
            'menu_category_id' => null,
            'name' => 'Nasi Ayam',
            'description' => null,
            'price' => 22000,
            'image' => null,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        $menuTwo = Menu::query()->create([
            'restaurant_id' => $restaurantTwo->id,
            'menu_category_id' => null,
            'name' => 'Mie Pedas',
            'description' => null,
            'price' => 18000,
            'image' => null,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/v1/cart/items', [
            'restaurant_id' => $restaurantOne->id,
            'menu_id' => $menuOne->id,
            'quantity' => 1,
        ])->assertCreated();

        $this->postJson('/api/v1/cart/items', [
            'restaurant_id' => $restaurantTwo->id,
            'menu_id' => $menuTwo->id,
            'quantity' => 1,
        ])->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_checkout_creates_pending_cod_payment(): void
    {
        config([
            'bangdeliv.google_maps_api_key' => 'test-google-maps-key',
        ]);

        Http::fake([
            'https://maps.googleapis.com/maps/api/distancematrix/json*' => Http::response([
                'status' => 'OK',
                'rows' => [[
                    'elements' => [[
                        'status' => 'OK',
                        'distance' => [
                            'text' => '3.0 km',
                            'value' => 3000,
                        ],
                        'duration' => [
                            'text' => '9 mins',
                            'value' => 540,
                        ],
                    ]],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create([
            'role' => 'customer',
            'phone' => '089999999998',
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Resto COD',
            'slug' => 'resto-cod',
            'description' => null,
            'address' => 'Jl. COD',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081200000009',
            'banner_image' => null,
            'status' => 'active',
            'avg_rating' => 4.60,
            'total_reviews' => 11,
            'estimated_prep_time' => 20,
        ]);

        $menu = Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'menu_category_id' => null,
            'name' => 'Nasi COD',
            'description' => null,
            'price' => 22000,
            'image' => null,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Rumah',
            'recipient_name' => 'Customer COD',
            'phone' => '089999999998',
            'full_address' => 'Jl. Pelanggan COD',
            'detail' => 'Pagar putih',
            'latitude' => -6.21000000,
            'longitude' => 106.82666600,
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'restaurant_id' => $restaurant->id,
            'menu_id' => $menu->id,
            'quantity' => 2,
        ])->assertCreated();

        $response = $this->postJson('/api/v1/orders/checkout', [
            'address_id' => $address->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_method', 'COD')
            ->assertJsonPath('data.payment_status', 'unpaid');

        $orderId = (int) $response->json('data.id');

        $this->assertDatabaseHas('order_payments', [
            'order_id' => $orderId,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
        ]);
    }
}
