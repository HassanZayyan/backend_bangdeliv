<?php

namespace Tests\Feature\Database;

use App\Models\Address;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\User;
use Database\Seeders\PreservedProductionDataSeeder;
use Database\Seeders\ProductionAdminSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\PreservedProductionSnapshot;
use Tests\TestCase;

class PreservedProductionDataSeederTest extends TestCase
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

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_it_restores_the_baseline_idempotently_and_uses_the_production_admin_as_verifier(): void
    {
        PreservedProductionSnapshot::write();
        $this->runSeeder(ProductionAdminSeeder::class);

        $this->runSeeder(PreservedProductionDataSeeder::class);
        $this->runSeeder(PreservedProductionDataSeeder::class);

        $admin = User::query()->where('role', 'admin')->firstOrFail();

        $this->assertSame(7, User::query()->count());
        $this->assertSame(4, Address::query()->count());
        $this->assertSame(1, Driver::query()->count());
        $this->assertSame(3, DriverDocument::query()->count());
        $this->assertDatabaseMissing('users', ['id' => 7]);
        $this->assertDatabaseMissing('drivers', ['id' => 2]);
        $this->assertDatabaseMissing('driver_documents', ['driver_id' => 2]);
        $this->assertSame(
            [$admin->id],
            DriverDocument::query()->distinct()->pluck('verified_by')->all()
        );
    }

    public function test_it_fails_when_the_private_snapshot_is_missing(): void
    {
        $this->runSeeder(ProductionAdminSeeder::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Snapshot production tidak ditemukan');

        $this->runSeeder(PreservedProductionDataSeeder::class);
    }

    public function test_it_fails_when_the_snapshot_json_is_malformed(): void
    {
        Storage::disk('local')->put(PreservedProductionDataSeeder::SNAPSHOT_PATH, '{invalid-json');
        $this->runSeeder(ProductionAdminSeeder::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Snapshot production bukan JSON yang valid');

        $this->runSeeder(PreservedProductionDataSeeder::class);
    }

    public function test_it_rejects_a_reference_to_the_excluded_testing_user(): void
    {
        $snapshot = PreservedProductionSnapshot::data();
        $snapshot['addresses'][0]['user_id'] = 7;
        PreservedProductionSnapshot::write($snapshot);
        $this->runSeeder(ProductionAdminSeeder::class);

        try {
            $this->runSeeder(PreservedProductionDataSeeder::class);
            $this->fail('Seeder seharusnya menolak foreign key user testing.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('user yang tidak tersedia', $exception->getMessage());
        }

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Address::query()->count());
        $this->assertSame(0, Driver::query()->count());
        $this->assertSame(0, DriverDocument::query()->count());
    }

    public function test_it_restores_approved_document_audit_when_the_physical_file_is_missing(): void
    {
        PreservedProductionSnapshot::write(missingDocumentTypes: ['selfie']);
        $this->runSeeder(ProductionAdminSeeder::class);

        $this->runSeeder(PreservedProductionDataSeeder::class);

        $this->assertSame(3, DriverDocument::query()->count());
        $this->assertDatabaseHas('driver_documents', [
            'driver_id' => 1,
            'document_type' => 'selfie',
            'file_path' => 'driver-documents/1/selfie/preserved.jpg',
            'verification_status' => 'approved',
        ]);
        Storage::disk('public')->assertMissing('driver-documents/1/selfie/preserved.jpg');
        $this->assertDatabaseHas('drivers', [
            'id' => 1,
            'registration_status' => 'active',
        ]);
    }

    /** @param class-string<Seeder> $seederClass */
    private function runSeeder(string $seederClass): void
    {
        $this->app->make($seederClass)
            ->setContainer($this->app)
            ->__invoke();
    }
}
