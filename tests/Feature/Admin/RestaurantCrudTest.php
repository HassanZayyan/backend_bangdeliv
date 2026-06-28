<?php

namespace Tests\Feature\Admin;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RestaurantCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_restaurant(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000001',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.restaurants.store'), [
                'name' => 'Resto Test Admin',
                'slug' => 'resto-test-admin',
                'address' => 'Jl. Test No. 1',
                'latitude' => -6.2,
                'longitude' => 106.8,
                'phone' => '081233330099',
            ])
            ->assertRedirect(route('admin.restaurants.index'));

        $restaurant = Restaurant::query()->where('slug', 'resto-test-admin')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.restaurants.update', $restaurant), [
                'name' => 'Resto Test Admin Updated',
                'slug' => 'resto-test-admin-updated',
                'address' => 'Jl. Test No. 2',
                'latitude' => -6.21,
                'longitude' => 106.81,
                'phone' => '081233330099',
            ])
            ->assertRedirect(route('admin.restaurants.index'));

        $restaurant->refresh();
        $this->assertSame('resto-test-admin-updated', $restaurant->slug);

        $this->actingAs($admin)
            ->delete(route('admin.restaurants.destroy', $restaurant))
            ->assertRedirect(route('admin.restaurants.index'));

        $this->assertSoftDeleted('restaurants', ['id' => $restaurant->id]);
    }

    public function test_admin_can_create_restaurant_without_slug_field(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000101',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.restaurants.store'), [
                'name' => 'Resto Tanpa Slug',
                'address' => 'Jl. Test Slug',
                'latitude' => -6.2,
                'longitude' => 106.8,
                'phone' => '081233330101',
            ])
            ->assertRedirect(route('admin.restaurants.index'));

        $this->assertDatabaseHas('restaurants', [
            'name' => 'Resto Tanpa Slug',
            'slug' => 'resto-tanpa-slug',
        ]);
    }

    public function test_restaurant_slug_auto_generation_uses_suffix_when_name_collides(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000102',
        ]);

        Restaurant::query()->create([
            'name' => 'Resto Sama',
            'slug' => 'resto-sama',
            'address' => 'Jl. Existing',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'phone' => '081233330102',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.restaurants.store'), [
                'name' => 'Resto Sama',
                'address' => 'Jl. Duplicate',
                'latitude' => -6.21,
                'longitude' => 106.81,
                'phone' => '081233330103',
            ])
            ->assertRedirect(route('admin.restaurants.index'));

        $this->assertDatabaseHas('restaurants', [
            'name' => 'Resto Sama',
            'slug' => 'resto-sama-2',
            'address' => 'Jl. Duplicate',
        ]);
    }

    public function test_admin_can_create_restaurant_with_banner_upload(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000103',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.restaurants.store'), [
                'name' => 'Resto Banner Upload',
                'address' => 'Jl. Banner Upload',
                'latitude' => -6.2,
                'longitude' => 106.8,
                'phone' => '081233330104',
                'banner_image' => UploadedFile::fake()->image('banner.jpg', 900, 600),
            ])
            ->assertRedirect(route('admin.restaurants.index'));

        $restaurant = Restaurant::query()->where('slug', 'resto-banner-upload')->firstOrFail();

        $this->assertIsString($restaurant->banner_image);
        $this->assertStringStartsWith('restaurants/uploads/'.$restaurant->id.'/', $restaurant->banner_image);
        Storage::disk('public')->assertExists($restaurant->banner_image);
    }

    public function test_admin_can_update_restaurant_banner_and_old_admin_upload_is_deleted(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000104',
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Resto Banner Replace',
            'slug' => 'resto-banner-replace',
            'address' => 'Jl. Banner Replace',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'phone' => '081233330105',
        ]);
        $oldPath = 'restaurants/uploads/'.$restaurant->id.'/old-banner.jpg';
        Storage::disk('public')->put($oldPath, 'old-banner');
        $restaurant->update(['banner_image' => $oldPath]);

        $this->actingAs($admin)
            ->post(route('admin.restaurants.update', $restaurant), [
                '_method' => 'PUT',
                'name' => 'Resto Banner Replace',
                'address' => 'Jl. Banner Replace Updated',
                'latitude' => -6.21,
                'longitude' => 106.81,
                'phone' => '081233330105',
                'banner_image' => UploadedFile::fake()->image('new-banner.png', 900, 600),
            ])
            ->assertRedirect(route('admin.restaurants.index'));

        $restaurant->refresh();

        Storage::disk('public')->assertMissing($oldPath);
        $this->assertIsString($restaurant->banner_image);
        $this->assertStringStartsWith('restaurants/uploads/'.$restaurant->id.'/', $restaurant->banner_image);
        Storage::disk('public')->assertExists($restaurant->banner_image);
    }

    public function test_admin_can_remove_restaurant_banner_without_deleting_seed_asset(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000106',
        ]);
        $seedPath = 'restaurants/1-1.JPG';
        Storage::disk('public')->put($seedPath, 'official-seed-banner');

        $restaurant = Restaurant::query()->create([
            'name' => 'Resto Seed Banner',
            'slug' => 'resto-seed-banner',
            'address' => 'Jl. Seed Banner',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'phone' => '081233330106',
            'banner_image' => $seedPath,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.restaurants.update', $restaurant), [
                '_method' => 'PUT',
                'name' => 'Resto Seed Banner',
                'address' => 'Jl. Seed Banner',
                'latitude' => -6.2,
                'longitude' => 106.8,
                'phone' => '081233330106',
                'remove_banner_image' => 1,
            ])
            ->assertRedirect(route('admin.restaurants.index'));

        $this->assertNull($restaurant->fresh()?->banner_image);
        Storage::disk('public')->assertExists($seedPath);
    }

    public function test_admin_restaurant_banner_upload_must_be_valid_image(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000107',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.restaurants.create'))
            ->post(route('admin.restaurants.store'), [
                'name' => 'Resto Invalid Banner',
                'address' => 'Jl. Invalid Banner',
                'latitude' => -6.2,
                'longitude' => 106.8,
                'phone' => '081233330107',
                'banner_image' => UploadedFile::fake()->create('banner.pdf', 12, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.restaurants.create'))
            ->assertSessionHasErrors(['banner_image']);
    }

    public function test_restaurant_banner_forms_include_live_preview_hooks(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000108',
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Resto Preview Banner',
            'slug' => 'resto-preview-banner',
            'address' => 'Jl. Preview Banner',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'phone' => '081233330108',
        ]);
        $bannerPath = 'restaurants/uploads/'.$restaurant->id.'/banner.jpg';
        Storage::disk('public')->put($bannerPath, 'banner-preview');
        $restaurant->update(['banner_image' => $bannerPath]);

        $this->actingAs($admin)
            ->get(route('admin.restaurants.create'))
            ->assertOk()
            ->assertSee('data-banner-preview', false)
            ->assertSee('data-banner-input', false)
            ->assertSee('data-banner-placeholder-text', false);

        $this->actingAs($admin)
            ->get(route('admin.restaurants.edit', $restaurant))
            ->assertOk()
            ->assertSee('data-banner-preview', false)
            ->assertSee('data-banner-image', false)
            ->assertSee('data-banner-input', false)
            ->assertSee('data-banner-remove', false)
            ->assertSee('storage/'.$bannerPath, false);
    }

    public function test_restaurants_table_has_no_status_column(): void
    {
        $this->assertFalse(Schema::hasColumn('restaurants', 'status'));
    }

    public function test_restaurants_and_menus_tables_have_no_description_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('restaurants', 'description'));
        $this->assertFalse(Schema::hasColumn('menus', 'description'));
    }

    public function test_admin_can_create_update_and_delete_menu(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000002',
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Resto Menu Test',
            'slug' => 'resto-menu-test',
            'address' => 'Jl. Menu Test',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'phone' => '081244440001',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.restaurants.menus.store', $restaurant), [
                'name' => 'Menu Test',
                'price' => 15000,
                'is_available' => 1,
                'sort_order' => 1,
            ])
            ->assertRedirect(route('admin.restaurants.menus.index', $restaurant));

        $menu = $restaurant->menus()->where('name', 'Menu Test')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.restaurants.menus.update', [$restaurant, $menu]), [
                'name' => 'Menu Test Updated',
                'price' => 17000,
                'is_available' => 0,
                'sort_order' => 2,
            ])
            ->assertRedirect(route('admin.restaurants.menus.index', $restaurant));

        $menu->refresh();
        $this->assertSame('Menu Test Updated', $menu->name);
        $this->assertFalse((bool) $menu->is_available);

        $this->actingAs($admin)
            ->delete(route('admin.restaurants.menus.destroy', [$restaurant, $menu]))
            ->assertRedirect(route('admin.restaurants.menus.index', $restaurant));

        $this->assertSoftDeleted('menus', ['id' => $menu->id]);
    }

    public function test_restaurant_toggle_status_route_is_removed(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000003',
        ]);

        $restaurant = Restaurant::query()->create([
            'name' => 'Resto No Status',
            'slug' => 'resto-no-status',
            'address' => 'Jl. No Status',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'phone' => '081244440002',
        ]);

        $this->actingAs($admin)
            ->patch('/admin/restoran/'.$restaurant->id.'/toggle-status')
            ->assertNotFound();
    }

    public function test_restaurant_index_hides_status_ui(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000004',
        ]);

        Restaurant::query()->create([
            'name' => 'Resto Clean UI',
            'slug' => 'resto-clean-ui',
            'address' => 'Jl. Clean UI',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'phone' => '081244440003',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.restaurants.index'))
            ->assertOk()
            ->assertSee('Resto Clean UI')
            ->assertDontSee('Status Live')
            ->assertDontSee('aktif/nonaktif')
            ->assertDontSee('Suspended')
            ->assertDontSee('Tutup (Luar Jam)')
            ->assertDontSee('toggle-status');
    }
}
