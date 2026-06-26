<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_upload_documents_and_status_becomes_pending(): void
    {
        Storage::fake('public');

        $driverUser = User::query()->create([
            'name' => 'Driver Upload',
            'email' => 'driver.upload@example.com',
            'phone' => '081211112222',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 1010 UPL',
            'registration_status' => 'rejected',
            'status' => 'offline',
        ]));

        Sanctum::actingAs($driverUser);

        $response = $this->post('/api/v1/driver/verification/documents', [
            'ktp' => UploadedFile::fake()->image('ktp.jpg', 800, 800),
            'sim' => UploadedFile::fake()->image('sim.jpg', 800, 800),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.driver.registration_status', 'pending');

        $this->assertDatabaseHas('driver_documents', [
            'driver_id' => $driver->id,
            'document_type' => 'ktp',
            'verification_status' => 'pending',
        ]);

        $this->assertDatabaseHas('driver_documents', [
            'driver_id' => $driver->id,
            'document_type' => 'sim',
            'verification_status' => 'pending',
        ]);

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'registration_status' => 'pending',
        ]);
    }

    public function test_driver_status_includes_all_required_document_slots(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver Slots',
            'email' => 'driver.slots@example.com',
            'phone' => '081211113333',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 1111 SLO',
            'registration_status' => 'pending',
            'status' => 'offline',
        ]));

        Sanctum::actingAs($driverUser);

        $response = $this->getJson('/api/v1/driver/verification');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.documents.0.document_type', 'ktp')
            ->assertJsonPath('data.documents.1.document_type', 'sim')
            ->assertJsonPath('data.documents.2.document_type', 'selfie')
            ->assertJsonPath('data.documents.1.is_uploaded', false);
    }

    public function test_reuploading_document_replaces_old_storage_file(): void
    {
        Storage::fake('public');

        $driverUser = User::query()->create([
            'name' => 'Driver Reupload',
            'email' => 'driver.reupload@example.com',
            'phone' => '081211114444',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 1212 RUP',
            'registration_status' => 'pending',
            'status' => 'offline',
        ]));

        Sanctum::actingAs($driverUser);

        $this->post('/api/v1/driver/verification/documents', [
            'ktp' => UploadedFile::fake()->image('ktp-old.jpg', 800, 800),
        ], [
            'Accept' => 'application/json',
        ])->assertCreated();

        $oldPath = DriverDocument::query()
            ->where('driver_id', $driver->id)
            ->where('document_type', 'ktp')
            ->value('file_path');

        $this->assertNotEmpty($oldPath);
        Storage::disk('public')->assertExists($oldPath);

        $this->post('/api/v1/driver/verification/documents', [
            'ktp' => UploadedFile::fake()->image('ktp-new.jpg', 800, 800),
        ], [
            'Accept' => 'application/json',
        ])->assertCreated();

        $newPath = DriverDocument::query()
            ->where('driver_id', $driver->id)
            ->where('document_type', 'ktp')
            ->value('file_path');

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_admin_can_approve_all_documents_and_activate_driver(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin Verify',
            'email' => 'admin.verify@example.com',
            'phone' => '081200001111',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driverUser = User::query()->create([
            'name' => 'Driver Verify',
            'email' => 'driver.verify@example.com',
            'phone' => '081299991111',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 2020 VRF',
            'registration_status' => 'pending',
            'status' => 'offline',
        ]));

        foreach (['ktp', 'sim', 'selfie'] as $type) {
            DriverDocument::query()->create([
                'driver_id' => $driver->id,
                'document_type' => $type,
                'file_path' => 'driver-documents/'.$driver->id.'/'.$type.'/example.jpg',
                'verification_status' => 'pending',
            ]);
        }

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/drivers/'.$driver->id.'/verification/review', [
            'documents' => [
                ['document_type' => 'ktp', 'verification_status' => 'approved'],
                ['document_type' => 'sim', 'verification_status' => 'approved'],
                ['document_type' => 'selfie', 'verification_status' => 'approved'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.driver.registration_status', 'active');

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'registration_status' => 'active',
        ]);

        $this->assertDatabaseHas('driver_documents', [
            'driver_id' => $driver->id,
            'document_type' => 'ktp',
            'verification_status' => 'approved',
            'verified_by' => $admin->id,
        ]);
    }

    public function test_pending_driver_is_blocked_from_active_driver_routes(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver Pending',
            'email' => 'driver.pending@example.com',
            'phone' => '081277771111',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 3030 PNG',
            'registration_status' => 'pending',
            'status' => 'offline',
        ]));

        Sanctum::actingAs($driverUser);

        $response = $this->postJson('/api/v1/orders/999/attempt-failed', [
            'failure_type' => 'PICKUP',
            'reason' => 'Driver belum bisa pickup karena akun belum aktif.',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_pending_driver_can_still_access_customer_order_flow(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver Pending Customer Flow',
            'email' => 'driver.pending.customer.flow@example.com',
            'phone' => '081277771222',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 3131 PNG',
            'registration_status' => 'pending',
            'status' => 'offline',
        ]));

        Sanctum::actingAs($driverUser);

        $response = $this->getJson('/api/v1/orders');

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_active_driver_cannot_access_customer_order_flow(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver Active Customer Flow',
            'email' => 'driver.active.customer.flow@example.com',
            'phone' => '081277771333',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 3232 ACT',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

        Sanctum::actingAs($driverUser);

        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_pending_driver_can_cancel_application_and_return_to_customer_role(): void
    {
        Storage::fake('public');

        $driverUser = User::query()->create([
            'name' => 'Driver Cancel',
            'email' => 'driver.cancel@example.com',
            'phone' => '081277772222',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 4040 CNL',
            'registration_status' => 'pending',
            'status' => 'offline',
        ]));

        $filePath = UploadedFile::fake()
            ->image('ktp-cancel.jpg', 800, 800)
            ->storeAs('driver-documents/'.$driver->id.'/ktp', 'ktp-cancel.jpg', 'public');

        DriverDocument::query()->create([
            'driver_id' => $driver->id,
            'document_type' => 'ktp',
            'file_path' => $filePath,
            'verification_status' => 'pending',
        ]);

        Storage::disk('public')->assertExists($filePath);
        Sanctum::actingAs($driverUser);

        $response = $this->deleteJson('/api/v1/driver/verification');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonPath('data.driver_profile', null);

        $this->assertDatabaseHas('users', [
            'id' => $driverUser->id,
            'role' => 'customer',
        ]);
        $this->assertDatabaseMissing('drivers', [
            'id' => $driver->id,
        ]);
        $this->assertDatabaseMissing('driver_documents', [
            'driver_id' => $driver->id,
        ]);
        Storage::disk('public')->assertMissing($filePath);
    }

    public function test_active_driver_cannot_cancel_application(): void
    {
        $driverUser = User::query()->create([
            'name' => 'Driver Active Cancel',
            'email' => 'driver.active.cancel@example.com',
            'phone' => '081277773333',
            'password' => Hash::make('password123'),
            'role' => 'driver',
            'is_active' => true,
            'is_blacklisted' => false,
        ]);

        Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 5050 ACT',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

        Sanctum::actingAs($driverUser);

        $response = $this->deleteJson('/api/v1/driver/verification');

        $response->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('users', [
            'id' => $driverUser->id,
            'role' => 'driver',
        ]);
    }
}
