<?php

namespace Tests\Feature\Api;

use App\Models\Menu;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
