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
            'banner_image' => 'restaurants/test-1.JPG',
            'gallery_images' => [
                'restaurants/test-1.JPG',
                'restaurants/test-2.PNG',
            ],
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
            ->assertJsonPath('data.0.banner_image', 'restaurants/test-1.JPG')
            ->assertJsonPath('data.0.gallery_images.1', 'restaurants/test-2.PNG')
            ->assertJsonPath('data.1.slug', 'resto-kedua');
    }

    public function test_restaurant_detail_returns_gallery_images_for_carousel(): void
    {
        Restaurant::query()->create([
            'name' => 'Resto Galeri',
            'slug' => 'resto-galeri',
            'address' => 'Jl. Galeri',
            'latitude' => -6.20000000,
            'longitude' => 106.81666600,
            'phone' => '081234567890',
            'banner_image' => 'restaurants/galeri-1.JPG',
            'gallery_images' => [
                'restaurants/galeri-1.JPG',
                'restaurants/galeri-2.PNG',
            ],
        ]);

        $response = $this->getJson('/api/v1/restaurants/resto-galeri');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.banner_image', 'restaurants/galeri-1.JPG')
            ->assertJsonPath('data.gallery_images.0', 'restaurants/galeri-1.JPG')
            ->assertJsonPath('data.gallery_images.1', 'restaurants/galeri-2.PNG');
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

    public function test_legacy_map_picker_request_returns_full_current_catalog_without_explicit_page(): void
    {
        for ($index = 1; $index <= 62; $index++) {
            Restaurant::query()->create([
                'name' => sprintf('Warung Map %02d', $index),
                'slug' => sprintf('warung-map-%02d', $index),
                'merchant_type' => 'warung',
                'address' => sprintf('Jl. Map %02d', $index),
                'latitude' => -7.30000000 - ($index / 100000),
                'longitude' => 110.460 + ($index / 100000),
                'phone' => '081234567890',
                'banner_image' => null,
            ]);
        }

        $legacyMapResponse = $this->getJson('/api/v1/restaurants?sort=name&per_page=50');

        $legacyMapResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(62, 'data')
            ->assertJsonPath('meta.total', 62)
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.last_page', 1);

        $explicitPageResponse = $this->getJson('/api/v1/restaurants?sort=name&per_page=50&page=1');

        $explicitPageResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.total', 62)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_restaurant_list_sorts_nearest_before_pagination(): void
    {
        Restaurant::query()->create([
            'name' => 'Resto Dekat',
            'slug' => 'resto-dekat',
            'address' => 'Jl. Dekat',
            'latitude' => -7.31780000,
            'longitude' => 110.46348000,
            'phone' => '081234567890',
            'banner_image' => null,
        ]);

        Restaurant::query()->create([
            'name' => 'Resto Jauh',
            'slug' => 'resto-jauh',
            'address' => 'Jl. Jauh',
            'latitude' => -7.90000000,
            'longitude' => 110.90000000,
            'phone' => '081111111111',
            'banner_image' => null,
        ]);

        $response = $this->getJson(
            '/api/v1/restaurants?sort=nearest&latitude=-7.3178&longitude=110.46348&per_page=1'
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'resto-dekat')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_restaurant_list_keeps_merchants_without_coordinates_after_nearest_results(): void
    {
        Restaurant::query()->create([
            'name' => 'Resto Dekat',
            'slug' => 'resto-dekat-null-coordinate-test',
            'address' => 'Jl. Dekat',
            'latitude' => -7.31780000,
            'longitude' => 110.46348000,
            'phone' => '081234567890',
            'banner_image' => null,
        ]);

        Restaurant::query()->create([
            'name' => 'Resto Tanpa Koordinat',
            'slug' => 'resto-tanpa-koordinat',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
            'phone' => '081111111111',
            'banner_image' => null,
        ]);

        $response = $this->getJson(
            '/api/v1/restaurants?sort=nearest&latitude=-7.3178&longitude=110.46348&per_page=20'
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'resto-dekat-null-coordinate-test')
            ->assertJsonPath('data.0.distance_km', 0)
            ->assertJsonPath('data.1.slug', 'resto-tanpa-koordinat')
            ->assertJsonPath('data.1.latitude', null)
            ->assertJsonPath('data.1.longitude', null)
            ->assertJsonPath('data.1.distance_km', null);
    }
}
