<?php

namespace Tests\Feature\Database;

use App\Models\Driver;
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

    public function test_database_seeder_in_production_creates_admin_and_official_catalog_only(): void
    {
        $this->useProductionEnvironment();
        config()->set('bangdeliv.production_admin', [
            'email' => 'owner@bangdeliv.com',
            'password' => 'secret-production-password',
            'name' => 'Owner BangDeliv',
            'phone' => '081300000001',
        ]);

        $this->runSeeder(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->count());

        $admin = User::query()->where('email', 'owner@bangdeliv.com')->firstOrFail();
        $this->assertSame('Owner BangDeliv', $admin->name);
        $this->assertSame('081300000001', $admin->phone);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertFalse($admin->is_blacklisted);
        $this->assertTrue(Hash::check('secret-production-password', (string) $admin->password));

        foreach ($this->demoEmails() as $email) {
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }

        $this->assertSame(32, Restaurant::query()->count());
        $this->assertSame(740, Menu::query()->count());
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
