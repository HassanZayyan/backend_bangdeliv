<?php

namespace Tests\Feature\Api;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ]);

        $response = $this->getJson('/api/v1/restaurants');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'resto-aktif');
    }

    public function test_restaurant_list_accepts_name_sort_for_shopping_merchant_picker(): void
    {
        Restaurant::query()->create([
            'name' => 'Warung Zeta',
            'slug' => 'warung-zeta',
            'description' => null,
            'merchant_type' => 'warung',
            'address' => 'Jl. Zeta',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081234567890',
            'banner_image' => null,
            'status' => 'active',
        ]);

        Restaurant::query()->create([
            'name' => 'Alfamart BangDeliv Point',
            'slug' => 'alfamart-bangdeliv-point',
            'description' => null,
            'merchant_type' => 'convenience_store',
            'address' => 'Jl. Alfa',
            'latitude' => -6.21000000,
            'longitude' => 106.82666600,
            'phone' => '081111111111',
            'banner_image' => null,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/v1/restaurants?sort=name&per_page=20');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'Alfamart BangDeliv Point')
            ->assertJsonPath('data.1.name', 'Warung Zeta');
    }
}
