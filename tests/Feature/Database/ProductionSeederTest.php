<?php

namespace Tests\Feature\Database;

use App\Models\Address;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Menu;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\AccessAccountSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductionAdminSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_in_production_restores_the_thesis_dataset_and_official_catalog(): void
    {
        $this->useProductionEnvironment();

        config()->set('bangdeliv.production_admin', [
            'email' => 'owner@bangdeliv.com',
            'password' => 'secret-production-password',
            'name' => 'Owner BangDeliv',
            'phone' => '081300000001',
        ]);

        $this->runSeeder(DatabaseSeeder::class);

        $this->assertSame(29, User::query()->count());
        // 19: +1 alamat Pelanggan 19 (sebelumnya responden kuesioner tanpa alamat).
        $this->assertSame(19, Address::query()->count());
        $this->assertSame(5, Driver::query()->count());
        $this->assertSame(15, DriverDocument::query()->count());

        $admin = User::query()->where('email', 'owner@bangdeliv.com')->firstOrFail();
        $this->assertSame('Owner BangDeliv', $admin->name);
        $this->assertSame('081300000001', $admin->phone);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertFalse($admin->is_blacklisted);
        $this->assertTrue(Hash::check('secret-production-password', (string) $admin->password));

        $this->assertDatabaseHas('users', [
            'id' => 8,
            'name' => 'Pelanggan 05',
            'role' => 'customer',
        ]);
        $this->assertDatabaseMissing('users', ['id' => 7]);
        $this->assertDatabaseHas('addresses', ['id' => 5, 'user_id' => 8]);
        $this->assertDatabaseMissing('addresses', ['user_id' => 7]);
        $this->assertDatabaseHas('drivers', [
            'id' => 1,
            'user_id' => 2,
            'registration_status' => 'active',
            'status' => 'offline',
        ]);
        $this->assertDatabaseHas('drivers', [
            'id' => 5,
            'user_id' => 25,
            'vehicle_plate' => 'H 1005 AA',
            'registration_status' => 'active',
        ]);

        foreach (['ktp', 'sim', 'selfie'] as $type) {
            $this->assertDatabaseHas('driver_documents', [
                'driver_id' => 1,
                'document_type' => $type,
                'verification_status' => 'approved',
                'verified_by' => $admin->id,
            ]);
        }

        foreach ($this->demoEmails() as $email) {
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }

        $this->assertSame(62, Restaurant::query()->count());
        $this->assertSame(1293, Menu::query()->count());
    }

    public function test_production_admin_seeder_requires_email(): void
    {
        config()->set('bangdeliv.production_admin', [
            'email' => '',
            'password' => 'secret-production-password',
            'name' => 'Owner BangDeliv',
            'phone' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BANGDELIV_ADMIN_EMAIL wajib diisi');

        $this->runSeeder(ProductionAdminSeeder::class);
    }

    public function test_production_admin_seeder_requires_password(): void
    {
        config()->set('bangdeliv.production_admin', [
            'email' => 'owner@bangdeliv.com',
            'password' => '',
            'name' => 'Owner BangDeliv',
            'phone' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BANGDELIV_ADMIN_PASSWORD wajib diisi');

        $this->runSeeder(ProductionAdminSeeder::class);
    }

    public function test_demo_account_seeders_are_noop_in_production(): void
    {
        $this->useProductionEnvironment();

        $this->runSeeder(UserSeeder::class);
        $this->runSeeder(CustomerSeeder::class);
        $this->runSeeder(AccessAccountSeeder::class);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Driver::query()->count());
    }

    public function test_demo_access_account_seeder_still_runs_outside_production(): void
    {
        $this->runSeeder(AccessAccountSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'mhnzayyan@gmail.com',
            'role' => 'customer',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'zaky@gmail.com',
            'role' => 'driver',
        ]);
        $this->assertSame(1, Driver::query()->count());
    }

    private function useProductionEnvironment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('app.env', 'production');
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

    /**
     * @return array<int, string>
     */
    private function demoEmails(): array
    {
        return [
            'customer@bangdeliv.com',
            'mhnzayyan@gmail.com',
            'zaky@gmail.com',
            'hassan@bangdeliv.com',
            'sari@bangdeliv.com',
            'budi@bangdeliv.com',
        ];
    }
}
