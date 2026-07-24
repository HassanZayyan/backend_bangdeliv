<?php

namespace Tests\Feature\Database;

use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Menu;
use App\Models\User;
use Database\Seeders\ProductionAdminSeeder;
use Database\Seeders\RestaurantMenuSeeder;
use Database\Seeders\ThesisDatasetSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ThesisDatasetSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('app.env', 'production');
        config()->set('bangdeliv.production_admin', [
            'email' => 'owner@bangdeliv.com',
            'password' => 'secret-production-password',
            'name' => 'Owner BangDeliv',
            'phone' => '081300000001',
        ]);
    }

    public function test_it_requires_an_admin_account(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Admin belum tersedia');

        $this->runSeeder(ThesisDatasetSeeder::class);
    }

    public function test_it_restores_the_thesis_dataset_idempotently(): void
    {
        $this->seedAll();
        $this->runSeeder(ThesisDatasetSeeder::class); // idempotent second run

        $this->assertSame(29, DB::table('users')->count()); // admin + 5 driver + 23 customer
        $this->assertSame(5, User::query()->where('role', 'driver')->count());
        $this->assertSame(23, User::query()->where('role', 'customer')->count());
        $this->assertSame(18, DB::table('addresses')->count());
        $this->assertSame(5, Driver::query()->count());
        $this->assertSame(15, DriverDocument::query()->count());
        $this->assertSame(16, DB::table('device_tokens')->count());
        $this->assertSame(29, DB::table('orders')->count());
        $this->assertSame(29, DB::table('order_payments')->count());
        $this->assertSame(1293, Menu::query()->count());

        // Naufal Zayyan dihapus dari dataset.
        $this->assertDatabaseMissing('users', ['email' => 'naufalzayyan@gmail.com']);
        $this->assertDatabaseMissing('addresses', ['id' => 22]);

        // Timestamp admin di-pin agar tidak menampilkan waktu fresh seed.
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $this->assertSame(ThesisDatasetSeeder::ADMIN_CREATED_AT, $admin->created_at?->format('Y-m-d H:i:s'));
        $this->assertSame(ThesisDatasetSeeder::ADMIN_UPDATED_AT, $admin->updated_at?->format('Y-m-d H:i:s'));
    }

    public function test_muhammad_faiz_is_a_verified_driver_with_three_orders(): void
    {
        $this->seedAll();

        $this->assertDatabaseHas('users', [
            'id' => 25,
            'name' => 'Driver 05',
            'phone' => '081100000005',
            'role' => 'driver',
        ]);
        $this->assertDatabaseHas('drivers', [
            'id' => 5,
            'user_id' => 25,
            'vehicle_plate' => 'H 1005 AA',
            'vehicle_model' => 'Vario 150',
            'registration_status' => 'active',
            'status' => 'offline',
        ]);
        $this->assertDatabaseHas('driver_documents', [
            'driver_id' => 5,
            'document_type' => 'ktp',
            'verified_at' => '2026-07-15 20:06:29',
        ]);

        $this->assertSame(3, DB::table('orders')->where('driver_id', 5)->count());
        $this->assertSame(
            ['BD-150726-023', 'BD-160726-001', 'BD-170726-002'],
            DB::table('orders')->where('driver_id', 5)->orderBy('id')->pluck('order_number')->all()
        );

        $snapshot = DB::table('order_status_histories')
            ->where('order_id', 23)
            ->where('status_id', 2)
            ->value('metadata');
        $this->assertStringContainsString('Driver 05', (string) $snapshot);
        $this->assertStringContainsString('H 1005 AA', (string) $snapshot);
    }

    public function test_orders_are_distributed_across_drivers(): void
    {
        $this->seedAll();

        $counts = DB::table('orders')
            ->whereNotNull('driver_id')
            ->groupBy('driver_id')
            ->selectRaw('driver_id, COUNT(*) as n')
            ->pluck('n', 'driver_id')
            ->map(fn ($n) => (int) $n)
            ->all();

        $this->assertSame(
            [1 => 4, 2 => 7, 3 => 5, 4 => 5, 5 => 3],
            $counts
        );

        // Kausalitas kuesioner: order Pelanggan 06 selesai sebelum ia mengisi form 09:55:03.
        $order1DoneAt = (string) DB::table('orders')->where('id', 1)->value('updated_at');
        $this->assertLessThan('2026-07-15 09:55:03', $order1DoneAt);
    }

    public function test_all_driver_documents_are_locked_approved_audits(): void
    {
        $this->seedAll();

        $admin = User::query()->where('role', 'admin')->firstOrFail();

        $this->assertSame(15, DriverDocument::query()->count());
        foreach (DriverDocument::query()->get() as $document) {
            $this->assertSame('approved', $document->verification_status);
            $this->assertNotNull($document->verified_at);
            $this->assertNotSame('', trim((string) $document->file_path));
            $this->assertSame($admin->id, $document->verified_by);
        }

        $this->assertSame(
            ['active'],
            Driver::query()->distinct()->pluck('registration_status')->all()
        );
    }

    public function test_only_order_ten_keeps_qris_and_the_rest_are_cod(): void
    {
        $this->seedAll();

        $this->assertDatabaseHas('order_payments', [
            'order_id' => 10,
            'payment_method' => 'TRANSFER',
            'payment_status' => 'PAID',
        ]);
        $this->assertSame(
            0,
            DB::table('order_payments')
                ->where('order_id', '!=', 10)
                ->where('payment_method', '!=', 'COD')
                ->count()
        );
        $this->assertSame(0, DB::table('order_evidence')->where('evidence_type', 'PAYMENT_TRANSFER_PHOTO')->count());
    }

    public function test_menu_db_items_reference_existing_menus(): void
    {
        $this->seedAll();

        $items = DB::table('shopping_order_items')->where('item_source', 'MENU_DB')->get();
        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertNotNull($item->menu_id, "item {$item->id} masih null menu_id");
            $menu = Menu::query()->find($item->menu_id);
            $this->assertNotNull($menu, "menu {$item->menu_id} tidak ada");
            $this->assertSame($menu->name, $item->menu_name);
        }
    }

    public function test_users_are_phone_verified_shortly_after_registration(): void
    {
        $this->seedAll();

        $users = User::query()->where('role', '!=', 'admin')->get();
        $this->assertNotEmpty($users);

        foreach ($users as $user) {
            $this->assertNotNull($user->phone_verified_at, "user {$user->id} belum phone verified");
            $gap = Carbon::parse($user->created_at)->diffInSeconds(Carbon::parse($user->phone_verified_at));
            $this->assertGreaterThanOrEqual(30, $gap, "user {$user->id} gap {$gap}s");
            $this->assertLessThanOrEqual(60, $gap, "user {$user->id} gap {$gap}s");
        }

        // Foto profil (URL Google) tetap tercatat setelah fresh seed.
        $this->assertStringStartsWith(
            'https://lh3.googleusercontent.com/',
            (string) User::query()->findOrFail(2)->avatar
        );
    }

    public function test_all_order_activity_is_within_operating_hours(): void
    {
        $this->seedAll();

        foreach (['orders' => 'created_at', 'order_status_histories' => 'created_at', 'order_events' => 'created_at'] as $table => $column) {
            foreach (DB::table($table)->pluck($column) as $value) {
                $hour = (int) Carbon::parse((string) $value)->format('H');
                $this->assertTrue(
                    $hour >= 9 && $hour < 21,
                    "{$table}.{$column} di luar jam operasional 09:00-21:00: {$value}"
                );
            }
        }
    }

    private function seedAll(): void
    {
        $this->runSeeder(ProductionAdminSeeder::class);
        $this->runSeeder(RestaurantMenuSeeder::class);
        $this->runSeeder(ThesisDatasetSeeder::class);
    }

    /**
     * @param  class-string<Seeder>  $seederClass
     */
    private function runSeeder(string $seederClass): void
    {
        $this->app->make($seederClass)
            ->setContainer($this->app)
            ->__invoke();
    }
}
