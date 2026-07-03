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
        $officialRestaurants = require database_path('seeders/data/bangdeliv_official_restaurants.php');
        $this->assertSame(range(1, 63), array_column($officialRestaurants, 'source_no'));

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
        $this->assertSame(63, Restaurant::query()->count());
        $this->assertSame(1307, Menu::query()->count());

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
            'price' => null,
            'image' => null,
        ]);

        $dapurFamily = Restaurant::query()
            ->where('slug', 'dapur-family')
            ->firstOrFail();
        $this->assertSame(1, Restaurant::query()->where('slug', 'dapur-family')->count());
        $this->assertSame('restaurants/15.JPG', $dapurFamily->banner_image);
        $this->assertSame(23, $dapurFamily->menus()->count());
        $this->assertDatabaseHas('menus', [
            'restaurant_id' => $dapurFamily->id,
            'name' => 'Chicken Katsu (LH / Cabe / Tomat)',
            'price' => 14000,
        ]);

        $sbSweger = Restaurant::query()
            ->where('slug', 's-b-swegerrr-krenceng')
            ->firstOrFail();
        $this->assertSame('restaurants/22.JPG', $sbSweger->banner_image);
        $this->assertSame([
            'restaurants/22.JPG',
        ], $sbSweger->gallery_images);

        $rendysChicken = Restaurant::query()
            ->where('slug', 'rendy-s-chicken')
            ->firstOrFail();
        $this->assertSame('restaurants/27.JPG', $rendysChicken->banner_image);
        $this->assertSame([
            'restaurants/27.JPG',
        ], $rendysChicken->gallery_images);

        $mieCio = Restaurant::query()
            ->where('slug', 'mie-cio-mii-dempel-candi')
            ->firstOrFail();
        $this->assertSame('restaurants/33.JPG', $mieCio->banner_image);
        $this->assertSame([
            'restaurants/33.JPG',
        ], $mieCio->gallery_images);
        $this->assertDatabaseHas('menus', [
            'restaurant_id' => $mieCio->id,
            'name' => 'Level 0',
            'price' => 0,
        ]);

        $bakmiRemaja6 = Restaurant::query()
            ->where('slug', 'bakmi-remaja-6-perumahan-sraten')
            ->firstOrFail();
        $this->assertSame('restaurants/34.JPG', $bakmiRemaja6->banner_image);
        $this->assertSame([
            'restaurants/34.JPG',
        ], $bakmiRemaja6->gallery_images);

        $mamiYolla = Restaurant::query()
            ->where('slug', 'warung-soto-campur-mami-yolla')
            ->firstOrFail();
        $this->assertSame('restaurants/63.JPG', $mamiYolla->banner_image);
        $this->assertSame([
            'restaurants/63.JPG',
        ], $mamiYolla->gallery_images);
    }
}
