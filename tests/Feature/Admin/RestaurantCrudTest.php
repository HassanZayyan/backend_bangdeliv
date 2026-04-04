<?php

namespace Tests\Feature\Admin;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_toggle_and_delete_restaurant(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000001',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.restaurants.store'), [
                'name' => 'Resto Test Admin',
                'slug' => 'resto-test-admin',
                'description' => 'test',
                'address' => 'Jl. Test No. 1',
                'latitude' => -6.2,
                'longitude' => 106.8,
                'phone' => '081233330099',
                'estimated_prep_time' => 20,
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.restaurants.index'));

        $restaurant = Restaurant::query()->where('slug', 'resto-test-admin')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.restaurants.update', $restaurant), [
                'name' => 'Resto Test Admin Updated',
                'slug' => 'resto-test-admin-updated',
                'description' => 'test update',
                'address' => 'Jl. Test No. 2',
                'latitude' => -6.21,
                'longitude' => 106.81,
                'phone' => '081233330099',
                'estimated_prep_time' => 25,
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.restaurants.index'));

        $restaurant->refresh();
        $this->assertSame('resto-test-admin-updated', $restaurant->slug);

        $this->actingAs($admin)
            ->patch(route('admin.restaurants.toggle-status', $restaurant))
            ->assertRedirect(route('admin.restaurants.index'));

        $restaurant->refresh();
        $this->assertSame('inactive', $restaurant->status);

        $this->actingAs($admin)
            ->delete(route('admin.restaurants.destroy', $restaurant))
            ->assertRedirect(route('admin.restaurants.index'));

        $this->assertSoftDeleted('restaurants', ['id' => $restaurant->id]);
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
            'description' => null,
            'address' => 'Jl. Menu Test',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'phone' => '081244440001',
            'status' => 'active',
            'estimated_prep_time' => 15,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.restaurants.menus.store', $restaurant), [
                'name' => 'Menu Test',
                'price' => 15000,
                'description' => 'desc',
                'is_available' => 1,
                'sort_order' => 1,
                'new_category_name' => 'Kategori Test',
            ])
            ->assertRedirect(route('admin.restaurants.menus.index', $restaurant));

        $menu = $restaurant->menus()->where('name', 'Menu Test')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('admin.restaurants.menus.update', [$restaurant, $menu]), [
                'name' => 'Menu Test Updated',
                'price' => 17000,
                'description' => 'desc updated',
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
}
