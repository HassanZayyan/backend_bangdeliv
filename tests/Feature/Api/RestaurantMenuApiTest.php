<?php

namespace Tests\Feature\Api;

use App\Models\Menu;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantMenuApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_menus_are_returned_without_categories(): void
    {
        $restaurant = Restaurant::query()->create([
            'name' => 'Resto Menu API',
            'slug' => 'resto-menu-api',
            'merchant_type' => 'restaurant',
            'address' => 'Jl. Menu API',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081234560000',
            'banner_image' => null,
        ]);

        Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Nasi Goreng',
            'price' => 18000,
            'image' => null,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Air Putih',
            'price' => 0,
            'image' => null,
            'is_available' => true,
            'sort_order' => 2,
        ]);

        Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Harga Nota',
            'price' => null,
            'image' => null,
            'is_available' => true,
            'sort_order' => 3,
        ]);

        Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Mie Rebus',
            'price' => 15000,
            'image' => null,
            'is_available' => false,
            'sort_order' => 4,
        ]);

        $response = $this->getJson('/api/v1/restaurants/resto-menu-api/menus?category_id=999');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.categories', [])
            ->assertJsonPath('data.menus.0.name', 'Nasi Goreng')
            ->assertJsonPath('data.menus.0.menu_category_id', null)
            ->assertJsonPath('data.menus.0.category_name', null)
            ->assertJsonPath('data.menus.1.name', 'Air Putih')
            ->assertJsonPath('data.menus.1.price', 0)
            ->assertJsonPath('data.menus.2.name', 'Harga Nota')
            ->assertJsonPath('data.menus.2.price', null)
            ->assertJsonMissingPath('data.menus.0.description');

        $this->assertCount(3, $response->json('data.menus'));
    }
}
