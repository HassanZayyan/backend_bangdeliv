<?php

namespace Tests\Feature\Database;

use App\Models\Menu;
use App\Models\Restaurant;
use Database\Seeders\RestaurantMenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantMenuSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_official_bangdeliv_restaurants_and_removes_dummy_data(): void
    {
        $dummy = Restaurant::query()->create([
            'name' => 'Resto Taman Kedai Satu',
            'slug' => 'resto-taman-kedai-satu',
            'merchant_type' => 'restaurant',
            'address' => 'Area dummy',
            'latitude' => -7.0549432,
            'longitude' => 110.4347394,
            'phone' => '081233330101',
        ]);
        Menu::query()->create([
            'restaurant_id' => $dummy->id,
            'name' => 'Nasi Dummy',
            'price' => 12000,
            'sort_order' => 1,
        ]);

        $this->seed(RestaurantMenuSeeder::class);

        $this->assertDatabaseMissing('restaurants', [
            'slug' => 'resto-taman-kedai-satu',
        ]);
        $this->assertSame(32, Restaurant::query()->count());
        $this->assertSame(740, Menu::query()->count());

        $restaurant = Restaurant::query()
            ->where('slug', 'mie-ayam-bakso-pak-kumaidi')
            ->firstOrFail();
        $this->assertSame('restaurant', $restaurant->merchant_type);
        $this->assertSame('restaurants/1-1.JPG', $restaurant->banner_image);
        $this->assertSame([
            'restaurants/1-1.JPG',
            'restaurants/1-2.PNG',
            'restaurants/1-3.PNG',
        ], $restaurant->gallery_images);
        $this->assertSame('-', $restaurant->phone);
        $this->assertSame(19, $restaurant->menus()->count());
        $this->assertDatabaseHas('menus', [
            'restaurant_id' => $restaurant->id,
            'name' => 'Mie Ayam Biasa',
            'price' => 10000,
            'image' => null,
            'is_available' => true,
        ]);

        $warung = Restaurant::query()
            ->where('slug', 'warung-bunda-dhia')
            ->firstOrFail();
        $this->assertSame('warung', $warung->merchant_type);

        $restaurantWithoutCoordinates = Restaurant::query()
            ->where('slug', 'santoso-food-kumpulrejo')
            ->firstOrFail();
        $this->assertNull($restaurantWithoutCoordinates->address);
        $this->assertNull($restaurantWithoutCoordinates->latitude);
        $this->assertNull($restaurantWithoutCoordinates->longitude);
        $this->assertSame(11, $restaurantWithoutCoordinates->menus()->count());

        $kedaiMbakVita = Restaurant::query()
            ->where('slug', 'kedai-mbak-vita')
            ->firstOrFail();
        $this->assertDatabaseHas('menus', [
            'restaurant_id' => $kedaiMbakVita->id,
            'name' => 'Lotek',
            'price' => 0,
            'image' => null,
        ]);
    }
}
