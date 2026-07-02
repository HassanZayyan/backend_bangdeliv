<?php

namespace Tests\Feature\Api;

use App\Models\Menu;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_api_returns_aggregated_sections(): void
    {
        $restaurant = Restaurant::query()->create([
            'name' => 'Ayam Bakar Mantap',
            'slug' => 'ayam-bakar-mantap',
            'address' => 'Jl. Veteran',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081234567890',
            'banner_image' => null,
        ]);

        Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Ayam Bakar Paket',
            'price' => 28000,
            'image' => null,
            'is_available' => true,
            'sort_order' => 1,
        ]);

        Menu::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Harga Nota',
            'price' => null,
            'image' => null,
            'is_available' => true,
            'sort_order' => 2,
        ]);

        $response = $this->getJson('/api/v1/home');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.categories', [])
            ->assertJsonPath('data.popular_menus.0.name', 'Ayam Bakar Paket')
            ->assertJsonPath('data.popular_menus.1.name', 'Harga Nota')
            ->assertJsonPath('data.popular_menus.1.price', null)
            ->assertJsonMissingPath('data.popular_menus.0.description')
            ->assertJsonPath('data.nearby_merchants.0.name', 'Ayam Bakar Mantap');
    }
}
