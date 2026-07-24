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
        $this->assertSame(range(1, 62), array_column($officialRestaurants, 'source_no'));
        $this->assertSame('Martabak Bangka Idola Cabang Krenceng', $officialRestaurants[23]['name']);
        $this->assertSame('restaurants/25-1.JPG', $officialRestaurants[23]['banner_image']);

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

        $legacyBaksoSragen = Restaurant::query()->create([
            'name' => 'Bakso Dan Mie Ayam Sragen',
            'slug' => 'bakso-dan-mie-ayam-sragen',
            'merchant_type' => 'restaurant',
            'address' => 'Alamat lama',
            'latitude' => -7.31559352,
            'longitude' => 110.46664161,
            'phone' => '081233330102',
        ]);
        Menu::query()->create([
            'restaurant_id' => $legacyBaksoSragen->id,
            'name' => 'Menu Legacy Sragen',
            'price' => 9000,
            'sort_order' => 1,
        ]);

        $closedRestaurant = Restaurant::query()->create([
            'name' => 'Nasi Goreng Nikmal',
            'slug' => 'nasi-goreng-nikmal',
            'merchant_type' => 'restaurant',
            'address' => 'Resto tutup',
            'latitude' => -7.319,
            'longitude' => 110.46600000,
            'phone' => '081233330103',
        ]);
        Menu::query()->create([
            'restaurant_id' => $closedRestaurant->id,
            'name' => 'Menu Nikmal Lama',
            'price' => 9000,
            'sort_order' => 1,
        ]);

        $this->seed(RestaurantMenuSeeder::class);

        $this->assertDatabaseMissing('restaurants', [
            'slug' => 'resto-taman-kedai-satu',
        ]);
        $this->assertDatabaseMissing('restaurants', [
            'slug' => 'nasi-goreng-nikmal',
        ]);
        $this->assertDatabaseMissing('menus', [
            'name' => 'Menu Nikmal Lama',
        ]);
        $this->assertSame(62, Restaurant::query()->count());
        $this->assertSame(1293, Menu::query()->count());

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
        $this->assertSame(
            'RT.01/RW.05, Kumpulrejo, Candirejo, Kec. Tuntang, Kabupaten Semarang, Jawa Tengah 50773',
            $restaurantWithoutCoordinates->address
        );
        $this->assertSame('-7.30028456', (string) $restaurantWithoutCoordinates->latitude);
        $this->assertSame('110.46112358', (string) $restaurantWithoutCoordinates->longitude);
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
        $this->assertSame('-7.31693801', (string) $dapurFamily->latitude);
        $this->assertSame('110.46631791', (string) $dapurFamily->longitude);
        $this->assertSame(23, $dapurFamily->menus()->count());
        $this->assertDatabaseHas('menus', [
            'restaurant_id' => $dapurFamily->id,
            'name' => 'Chicken Katsu (LH / Cabe / Tomat)',
            'price' => 14000,
        ]);

        $baksoSragen = Restaurant::query()
            ->where('slug', 'bakso-dan-mie-ayam-sragen-depan-perumahan-sraten')
            ->firstOrFail();
        $this->assertSame($legacyBaksoSragen->id, $baksoSragen->id);
        $this->assertSame('Bakso Dan Mie Ayam Sragen Depan Perumahan Sraten', $baksoSragen->name);
        $this->assertSame('-7.31566632', (string) $baksoSragen->latitude);
        $this->assertSame('110.46650368', (string) $baksoSragen->longitude);
        $this->assertSame(10, $baksoSragen->menus()->count());
        $this->assertSame(0, Restaurant::query()->where('slug', 'bakso-dan-mie-ayam-sragen')->count());

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
        $this->assertSame(40, $mieCio->menus()->count());
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

    public function test_it_preserves_admin_created_restaurants_and_menus_on_reseed(): void
    {
        // Seed katalog resmi terlebih dulu.
        $this->seed(RestaurantMenuSeeder::class);
        $this->assertSame(1293, Menu::query()->where('source', 'official')->count());

        // Admin menambah resto + menu lewat panel (slug unik, source default 'admin',
        // id auto-increment di luar rentang katalog).
        $adminResto = Restaurant::query()->create([
            'name' => 'Warung Admin Baru',
            'slug' => 'warung-admin-baru',
            'merchant_type' => 'warung',
            'address' => 'Alamat admin',
            'latitude' => -7.30000000,
            'longitude' => 110.460,
            'phone' => '081200000000',
        ]);
        $adminMenu = Menu::query()->create([
            'restaurant_id' => $adminResto->id,
            'name' => 'Menu Buatan Admin',
            'price' => 15000,
            'sort_order' => 1,
        ]);

        $this->assertSame('admin', $adminResto->refresh()->source);
        $this->assertSame('admin', $adminMenu->refresh()->source);
        $this->assertGreaterThan(1293, $adminMenu->id);

        // Re-seed katalog (mensimulasikan seed manual) TIDAK boleh menghapus data admin.
        $this->seed(RestaurantMenuSeeder::class);

        $this->assertDatabaseHas('restaurants', ['slug' => 'warung-admin-baru']);
        $this->assertNotNull(
            Menu::query()->find($adminMenu->id),
            'Menu buatan admin tidak boleh ter-hard-delete oleh RestaurantMenuSeeder.'
        );
        $this->assertDatabaseHas('menus', [
            'id' => $adminMenu->id,
            'name' => 'Menu Buatan Admin',
            'source' => 'admin',
        ]);
        $this->assertSame(1293, Menu::query()->where('source', 'official')->count());
        $this->assertSame(1, Menu::query()->where('source', 'admin')->count());
    }
}
