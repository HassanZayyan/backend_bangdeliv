<?php

namespace Tests\Feature\Api;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAndCartFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_list_returns_restaurants_without_status_filter(): void
    {
        Restaurant::query()->create([
            'name' => 'Resto Aktif',
            'slug' => 'resto-aktif',
            'address' => 'Jl. Aktif',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081234567890',
            'banner_image' => null,
        ]);

        Restaurant::query()->create([
            'name' => 'Resto Kedua',
            'slug' => 'resto-kedua',
            'address' => 'Jl. Kedua',
            'latitude' => -6.21000000,
            'longitude' => 106.82666600,
            'phone' => '081111111111',
            'banner_image' => null,
        ]);

        $response = $this->getJson('/api/v1/restaurants?sort=name');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'resto-aktif')
            ->assertJsonPath('data.1.slug', 'resto-kedua');
    }

    public function test_restaurant_list_accepts_name_sort_for_shopping_merchant_picker(): void
    {
        Restaurant::query()->create([
            'name' => 'Warung Zeta',
            'slug' => 'warung-zeta',
            'merchant_type' => 'warung',
            'address' => 'Jl. Zeta',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081234567890',
            'banner_image' => null,
        ]);

        Restaurant::query()->create([
            'name' => 'Alfamart BangDeliv Point',
            'slug' => 'alfamart-bangdeliv-point',
            'merchant_type' => 'convenience_store',
            'address' => 'Jl. Alfa',
            'latitude' => -6.21000000,
            'longitude' => 106.82666600,
            'phone' => '081111111111',
            'banner_image' => null,
        ]);

        $response = $this->getJson('/api/v1/restaurants?sort=name&per_page=20');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'Alfamart BangDeliv Point')
            ->assertJsonPath('data.1.name', 'Warung Zeta');
    }
}
