<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;

class RestaurantMenuSeeder extends Seeder
{
    /**
     * @var array<int, string>
     */
    private const DUMMY_RESTAURANT_SLUGS = [
        'resto-taman-kedai-satu',
        'dapur-mrican-bangdeliv',
        'warung-makan-ndeso-sampangan',
        'warung-madura-barokah',
        'warung-madura-sumber-rejeki',
        'warung-madura-maju-jaya',
        'alfamart-bangdeliv-point',
        'indomaret-bangdeliv-point',
    ];

    public function run(): void
    {
        $restaurants = require database_path('seeders/data/bangdeliv_official_restaurants.php');

        $this->deleteDummyRestaurants();

        foreach ($restaurants as $restoData) {
            $restaurant = $this->upsertRestaurant($restoData);
            $this->replaceMenus($restaurant, $restoData['menus'] ?? []);
        }
    }

    private function deleteDummyRestaurants(): void
    {
        Restaurant::withTrashed()
            ->whereIn('slug', self::DUMMY_RESTAURANT_SLUGS)
            ->get()
            ->each(function (Restaurant $restaurant): void {
                Menu::withTrashed()
                    ->where('restaurant_id', $restaurant->id)
                    ->forceDelete();

                MenuCategory::query()
                    ->where('restaurant_id', $restaurant->id)
                    ->delete();

                $restaurant->forceDelete();
            });
    }

    /**
     * @param  array<string, mixed>  $restoData
     */
    private function upsertRestaurant(array $restoData): Restaurant
    {
        $restaurant = Restaurant::withTrashed()
            ->where('slug', (string) $restoData['slug'])
            ->first();

        if (! $restaurant) {
            $restaurant = new Restaurant(['slug' => (string) $restoData['slug']]);
        }

        if ($restaurant->exists && $restaurant->trashed()) {
            $restaurant->restore();
        }

        $restaurant->fill([
            'name' => (string) $restoData['name'],
            'slug' => (string) $restoData['slug'],
            'description' => 'Merchant resmi BangDeliv.',
            'merchant_type' => (string) $restoData['merchant_type'],
            'address' => (string) $restoData['address'],
            'latitude' => $restoData['latitude'],
            'longitude' => $restoData['longitude'],
            'phone' => (string) $restoData['phone'],
            'banner_image' => null,
        ]);

        $restaurant->save();

        return $restaurant;
    }

    /**
     * @param  array<int, array{name: string, price: int|float}>  $menus
     */
    private function replaceMenus(Restaurant $restaurant, array $menus): void
    {
        Menu::withTrashed()
            ->where('restaurant_id', $restaurant->id)
            ->forceDelete();

        MenuCategory::query()
            ->where('restaurant_id', $restaurant->id)
            ->delete();

        $category = MenuCategory::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Menu',
            'sort_order' => 1,
        ]);

        foreach (array_values($menus) as $index => $menu) {
            Menu::query()->create([
                'restaurant_id' => $restaurant->id,
                'menu_category_id' => $category->id,
                'name' => (string) $menu['name'],
                'description' => null,
                'price' => max(0, (float) $menu['price']),
                'image' => null,
                'is_available' => true,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
