<?php

namespace Tests\Feature\Admin;

use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DriverVerificationWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_verification_list_and_detail_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081355550001',
        ]);

        $pendingUser = User::factory()->create([
            'role' => 'driver',
            'name' => 'Driver Pending Web',
            'phone' => '081355550002',
        ]);
        $pendingDriver = Driver::query()->create($this->driverAttributes([
            'user_id' => $pendingUser->id,
            'vehicle_plate' => 'B 1234 PND',
            'registration_status' => 'pending',
            'status' => 'offline',
        ]));

        $rejectedUser = User::factory()->create([
            'role' => 'driver',
            'name' => 'Driver Rejected Web',
            'phone' => '081355550003',
        ]);
        Driver::query()->create($this->driverAttributes([
            'user_id' => $rejectedUser->id,
            'vehicle_plate' => 'B 5678 REJ',
            'registration_status' => 'rejected',
            'status' => 'offline',
        ]));

        $this->actingAs($admin)
            ->get(route('admin.verification', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('Driver Pending Web')
            ->assertDontSee('Driver Rejected Web');

        $this->actingAs($admin)
            ->get(route('admin.verification.show', ['driverId' => $pendingDriver->id]))
            ->assertOk()
            ->assertSee('Review Dokumen');
    }

    public function test_admin_can_submit_review_from_web_page(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081355550011',
        ]);

        $driverUser = User::factory()->create([
            'role' => 'driver',
            'phone' => '081355550012',
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 9012 RVW',
            'registration_status' => 'pending',
            'status' => 'offline',
        ]));

        foreach (['ktp', 'sim', 'selfie'] as $type) {
            DriverDocument::query()->create([
                'driver_id' => $driver->id,
                'document_type' => $type,
                'file_path' => 'driver-documents/'.$driver->id.'/'.$type.'/dummy.jpg',
                'verification_status' => 'pending',
            ]);
        }

        $this->actingAs($admin)
            ->post(route('admin.verification.review', ['driverId' => $driver->id]), [
                'documents' => [
                    ['document_type' => 'ktp', 'verification_status' => 'approved'],
                    ['document_type' => 'sim', 'verification_status' => 'approved'],
                    ['document_type' => 'selfie', 'verification_status' => 'approved'],
                ],
            ])
            ->assertRedirect(route('admin.verification.show', ['driverId' => $driver->id]));

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

    public function test_admin_can_delete_document_from_web_page(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081355550021',
        ]);

        $driverUser = User::factory()->create([
            'role' => 'driver',
            'phone' => '081355550022',
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 3344 DEL',
            'registration_status' => 'active',
            'status' => 'available',
        ]));

        $ktpPath = 'driver-documents/'.$driver->id.'/ktp/to-delete.jpg';
        Storage::disk('public')->put($ktpPath, 'dummy');

        DriverDocument::query()->create([
            'driver_id' => $driver->id,
            'document_type' => 'ktp',
            'file_path' => $ktpPath,
            'verification_status' => 'approved',
        ]);

        DriverDocument::query()->create([
            'driver_id' => $driver->id,
            'document_type' => 'sim',
            'file_path' => 'driver-documents/'.$driver->id.'/sim/keep.jpg',
            'verification_status' => 'approved',
        ]);

        DriverDocument::query()->create([
            'driver_id' => $driver->id,
            'document_type' => 'selfie',
            'file_path' => 'driver-documents/'.$driver->id.'/selfie/keep.jpg',
            'verification_status' => 'approved',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.verification.documents.destroy', [
                'driverId' => $driver->id,
                'documentType' => 'ktp',
            ]))
            ->assertRedirect(route('admin.verification.show', ['driverId' => $driver->id]));

        $this->assertDatabaseMissing('driver_documents', [
            'driver_id' => $driver->id,
            'document_type' => 'ktp',
        ]);

        Storage::disk('public')->assertMissing($ktpPath);

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'registration_status' => 'pending',
            'status' => 'offline',
        ]);
    }

    public function test_admin_can_preview_uploaded_document_from_web_page(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081355550031',
        ]);

        $driverUser = User::factory()->create([
            'role' => 'driver',
            'phone' => '081355550032',
        ]);

        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 7788 PRV',
            'registration_status' => 'pending',
            'status' => 'offline',
        ]));

        $ktpPath = 'driver-documents/'.$driver->id.'/ktp/preview.jpg';
        Storage::disk('public')->put($ktpPath, 'preview-data');

        DriverDocument::query()->create([
            'driver_id' => $driver->id,
            'document_type' => 'ktp',
            'file_path' => $ktpPath,
            'verification_status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.verification.documents.preview', [
                'driverId' => $driver->id,
                'documentType' => 'ktp',
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg')
            ->assertHeader('content-disposition', 'inline; filename=preview.jpg');
    }
}
