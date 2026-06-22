<?php

namespace Tests\Feature\Admin;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Restaurant;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminListingPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_shows_single_full_width_card_without_helper_copy(): void
    {
        config(['bangdeliv.service_area.radius_km' => 50]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000004',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Radius area layanan')
            ->assertSee('50 km')
            ->assertSee('Pusat layanan')
            ->assertSee('Angkringan 54')
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

    public function test_driver_management_shows_system_and_manual_admin_fee_modes(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081300000105',
        ]);
        $driverUser = User::factory()->create([
            'role' => 'driver',
            'name' => 'Driver Admin Fee',
            'phone' => '081300000205',
        ]);
        $customer = User::factory()->create([
            'role' => 'customer',
            'phone' => '081300000305',
        ]);
        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'H 1500 FEE',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

        Order::query()->create([
            'order_number' => 'BD-ADMIN-FEE-001',
            'user_id' => $customer->id,
            'service_type_id' => ServiceType::query()->where('code', 'RIDE')->value('id'),
            'driver_id' => $driver->id,
            'delivery_fee' => 15000,
            'service_fee' => 0,
            'total_price' => 15000,
            'status_id' => OrderStatus::query()->where('code', 'COMPLETED')->value('id'),
            'payment_status' => 'paid',
            'payment_method' => 'COD',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.drivers.index'))
            ->assertOk()
            ->assertSee('Driver Admin Fee')
            ->assertSee(route('admin.drivers.show', ['driver' => $driver->id]), false)
            ->assertDontSee(route('admin.verification.show', ['driverId' => $driver->id]), false)
            ->assertDontSee('name="income_mode"', false)
            ->assertSee('Rp 13.500')
            ->assertSee('Bruto Rp 15.000')
            ->assertSee('Potongan 10%: Rp 1.500');

        $this->actingAs($admin)
            ->get(route('admin.drivers.show', ['driver' => $driver->id]))
            ->assertOk()
            ->assertSee('Gaji Driver')
            ->assertSee('By Sistem')
            ->assertSee('Manual')
            ->assertSee('BD-ADMIN-FEE-001')
            ->assertSee('Rp 13.500')
            ->assertSee('Pendapatan Bruto')
            ->assertSee('Rp 15.000')
            ->assertSee('Potongan Admin')
            ->assertSee('Rp 1.500')
            ->assertSee('10%')
            ->assertSee(route('admin.verification.show', ['driverId' => $driver->id]), false);

        $this->actingAs($admin)
            ->get(route('admin.drivers.show', [
                'driver' => $driver->id,
                'income_mode' => 'manual',
                'admin_fee_percent' => 20,
            ]))
            ->assertOk()
            ->assertSee('Manual')
            ->assertSee('Rp 12.000')
            ->assertSee('Rp 3.000')
            ->assertSee('20%');
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
