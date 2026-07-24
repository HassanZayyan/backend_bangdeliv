<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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

    /**
     * @var array<int, string>
     */
    private const REMOVED_OFFICIAL_RESTAURANT_SLUGS = [
        'nasi-goreng-nikmal',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const LEGACY_RESTAURANT_SLUGS = [
        'bakso-dan-mie-ayam-sragen-depan-perumahan-sraten' => [
            'bakso-dan-mie-ayam-sragen',
        ],
    ];

    public function run(): void
    {
        $restaurants = require database_path('seeders/data/bangdeliv_official_restaurants.php');

        $this->deleteDummyRestaurants();
        $this->deleteRemovedOfficialRestaurants();

        $menuRows = [];
        $menuId = 0;
        $now = now();

        foreach ($restaurants as $restoData) {
            $restaurant = $this->upsertRestaurant($restoData);

            foreach (array_values($restoData['menus'] ?? []) as $index => $menu) {
                $menuId++;
                $price = $menu['price'] ?? null;

                $menuRows[] = [
                    'id' => $menuId,
                    'restaurant_id' => $restaurant->id,
                    'source' => 'official',
                    'name' => (string) $menu['name'],
                    'price' => $price === null ? null : (float) $price,
                    'image' => null,
                    'is_available' => true,
                    'sort_order' => $index + 1,
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $this->syncMenus($menuRows);
    }

    /**
     * Menu di-upsert dengan id deterministik (urutan restoran + urutan menu di
     * data file), sehingga id menu yang sama tidak berubah antar seeding dan
     * referensi shopping_order_items.menu_id tidak menjadi NULL. Jangan
     * menyisipkan menu baru di tengah daftar — tambahkan di akhir daftar menu
     * restoran paling akhir agar penomoran lama tetap stabil.
     *
     * @param  array<int, array<string, mixed>>  $menuRows
     */
    private function syncMenus(array $menuRows): void
    {
        // Prune HANYA baris katalog resmi (source=official) yang sudah tidak ada
        // di katalog terbaru. Menu buatan admin (source=admin) TIDAK PERNAH
        // disentuh — inilah perbaikan penyebab menu admin hilang tiap re-seed.
        DB::table('menus')
            ->where('source', 'official')
            ->whereNotIn('id', array_column($menuRows, 'id'))
            ->delete();

        foreach (array_chunk($menuRows, 500) as $chunk) {
            DB::table('menus')->upsert(
                $chunk,
                ['id'],
                ['restaurant_id', 'source', 'name', 'price', 'image', 'is_available', 'sort_order', 'deleted_at', 'updated_at']
            );
        }
    }

    private function deleteDummyRestaurants(): void
    {
        $this->deleteRestaurantsBySlug(self::DUMMY_RESTAURANT_SLUGS);
    }

    private function deleteRemovedOfficialRestaurants(): void
    {
        $this->deleteRestaurantsBySlug(self::REMOVED_OFFICIAL_RESTAURANT_SLUGS);
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function deleteRestaurantsBySlug(array $slugs): void
    {
        if (empty($slugs)) {
            return;
        }

        Restaurant::withTrashed()
            ->whereIn('slug', $slugs)
            ->get()
            ->each(function (Restaurant $restaurant): void {
                Menu::withTrashed()
                    ->where('restaurant_id', $restaurant->id)
                    ->forceDelete();

                $restaurant->forceDelete();
            });
    }

    /**
     * @param  array<string, mixed>  $restoData
     */
    private function upsertRestaurant(array $restoData): Restaurant
    {
        $slug = (string) $restoData['slug'];
        $legacySlugs = self::LEGACY_RESTAURANT_SLUGS[$slug] ?? [];

        $restaurant = Restaurant::withTrashed()
            ->where('slug', $slug)
            ->first();

        if (! $restaurant && ! empty($legacySlugs)) {
            $restaurant = Restaurant::withTrashed()
                ->whereIn('slug', $legacySlugs)
                ->first();
        }

        if (! $restaurant) {
            $restaurant = new Restaurant(['slug' => $slug]);
        } elseif (! empty($legacySlugs)) {
            $this->deleteRestaurantsBySlug(
                array_values(array_filter(
                    $legacySlugs,
                    fn (string $legacySlug): bool => $legacySlug !== $restaurant->slug
                ))
            );
        }

        if ($restaurant->exists && $restaurant->trashed()) {
            $restaurant->restore();
        }

        $restaurant->fill([
            'source' => 'official',
            'name' => (string) $restoData['name'],
            'slug' => (string) $restoData['slug'],
            'merchant_type' => (string) $restoData['merchant_type'],
            'address' => $restoData['address'] ?? null,
            'latitude' => $restoData['latitude'] ?? null,
            'longitude' => $restoData['longitude'] ?? null,
            'phone' => (string) $restoData['phone'],
            'banner_image' => $restoData['banner_image'] ?? null,
            'gallery_images' => array_values($restoData['gallery_images'] ?? []),
        ]);

        $restaurant->save();

        return $restaurant;
    }

}
