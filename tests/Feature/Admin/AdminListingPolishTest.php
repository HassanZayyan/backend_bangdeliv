<?php

namespace Tests\Feature\Admin;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminListingPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_shows_single_full_width_card_without_helper_copy(): void
    {
        config(['bangdeliv.max_delivery_distance' => 50]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000004',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Jarak maksimum')
            ->assertSee('50 km')
            ->assertDontSee('>5 km<', false)
            ->assertSee('settings-overview-panel', false)
            ->assertSee('settings-metric-grid', false)
            ->assertDontSee('Snapshot konfigurasi aktif dari env/config backend.')
            ->assertDontSee('Bell admin memakai data pending dari server dan update realtime bila Reverb aktif.')
            ->assertDontSee('Perubahan profil belum tersedia di dashboard ini.');
    }

    public function test_driver_and_customer_lists_keep_search_without_static_filter_button(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000005',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.drivers.index'))
            ->assertOk()
            ->assertSee('Cari Nama, Plat Nomor, atau HP')
            ->assertDontSee('bx-filter-alt', false)
            ->assertDontSee('>Filter<', false);

        $this->actingAs($admin)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('Cari Nama, Email, atau HP')
            ->assertDontSee('bx-filter-alt', false)
            ->assertDontSee('>Filter<', false);
    }

    public function test_customer_actions_show_detail_orders_and_blacklist_controls(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000006',
        ]);

        $customer = User::factory()->create([
            'role' => 'customer',
            'name' => 'Customer Action',
            'phone' => '081300000106',
            'is_blacklisted' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee($customer->name)
            ->assertSee('js-customer-toggle', false)
            ->assertSee('Lihat pesanan pelanggan')
            ->assertSee('Blacklist pelanggan')
            ->assertDontSee('>Lihat Pesanan<', false)
            ->assertDontSee('window.location.href', false);
    }

    public function test_driver_and_customer_lists_use_shared_pagination_markup(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000008',
        ]);

        User::factory()->count(12)->create([
            'role' => 'customer',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('Menampilkan 1-5 dari 12 pelanggan')
            ->assertSee('pagination-controls', false)
            ->assertDontSee('<button class="btn-page active">1</button>', false);

        $this->actingAs($admin)
            ->get(route('admin.drivers.index'))
            ->assertOk()
            ->assertSee('panel-pagination', false)
            ->assertSee('pagination-controls', false)
            ->assertDontSee('<button class="btn-page active">1</button>', false);
    }

    public function test_shared_pagination_limits_page_numbers_to_five_per_window(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000009',
        ]);

        User::factory()->count(52)->create([
            'role' => 'customer',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('Menampilkan 1-5 dari 52 pelanggan')
            ->assertSee('aria-label="Halaman 5"', false)
            ->assertDontSee('aria-label="Halaman 6"', false)
            ->assertSee('aria-label="Halaman berikutnya"', false);

        $this->actingAs($admin)
            ->get(route('admin.customers.index', ['page' => 6]))
            ->assertOk()
            ->assertSee('Menampilkan 26-30 dari 52 pelanggan')
            ->assertSee('<span class="btn-page active" aria-current="page">6</span>', false)
            ->assertDontSee('aria-label="Halaman 5"', false);
    }

    public function test_restaurant_list_uses_five_item_admin_pagination(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000010',
        ]);

        for ($i = 1; $i <= 8; $i++) {
            Restaurant::query()->create([
                'name' => "Resto Page {$i}",
                'slug' => "resto-page-{$i}",
                'description' => null,
                'address' => "Jl. Page {$i}",
                'latitude' => -6.2,
                'longitude' => 106.8 + ($i / 1000),
                'phone' => '08124444'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.restaurants.index'))
            ->assertOk()
            ->assertSee('Menampilkan 1-5 dari 8 mitra restoran')
            ->assertSee('aria-label="Halaman berikutnya"', false)
            ->assertDontSee('Menampilkan 1-8 dari 8 mitra restoran');
    }

    public function test_admin_can_blacklist_and_unblacklist_customer(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000007',
        ]);

        $customer = User::factory()->create([
            'role' => 'customer',
            'is_blacklisted' => false,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.customers.blacklist', $customer), [
                'is_blacklisted' => 1,
            ])
            ->assertRedirect(route('admin.customers.index'));

        $this->assertTrue($customer->refresh()->is_blacklisted);

        $this->actingAs($admin)
            ->patch(route('admin.customers.blacklist', $customer), [
                'is_blacklisted' => 0,
            ])
            ->assertRedirect(route('admin.customers.index'));

        $this->assertFalse($customer->refresh()->is_blacklisted);
    }
}
