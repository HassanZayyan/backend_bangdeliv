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
            ->assertSee('Periksa Dokumen');
    }

    public function test_admin_can_submit_review_from_web_page(): void
    {
        Storage::fake('public');

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
            $path = 'driver-documents/'.$driver->id.'/'.$type.'/dummy.jpg';
            Storage::disk('public')->put($path, 'dummy');

            DriverDocument::query()->create([
                'driver_id' => $driver->id,
                'document_type' => $type,
                'file_path' => $path,
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

    public function test_admin_can_delete_only_the_approved_file_and_keep_the_audit_record_and_driver_status(): void
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

        $verifiedAt = now()->subDay();
        $ktpDocument = DriverDocument::query()->create([
            'driver_id' => $driver->id,
            'document_type' => 'ktp',
            'file_path' => $ktpPath,
            'verification_status' => 'approved',
            'verified_at' => $verifiedAt,
            'verified_by' => $admin->id,
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
            ->assertRedirect(route('admin.verification.show', ['driverId' => $driver->id]))
            ->assertSessionHas('success', 'File dokumen berhasil dihapus. Data verifikasi tetap tersimpan.');

        $this->assertDatabaseHas('driver_documents', [
            'id' => $ktpDocument->id,
            'driver_id' => $driver->id,
            'document_type' => 'ktp',
            'file_path' => $ktpPath,
            'verification_status' => 'approved',
            'verified_by' => $admin->id,
        ]);
        $this->assertSame(
            $verifiedAt->format('Y-m-d H:i:s'),
            $ktpDocument->fresh()?->verified_at?->format('Y-m-d H:i:s')
        );

        Storage::disk('public')->assertMissing($ktpPath);

        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'registration_status' => 'active',
            'status' => 'available',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.verification.documents.destroy', [
                'driverId' => $driver->id,
                'documentType' => 'ktp',
            ]))
            ->assertRedirect(route('admin.verification.show', ['driverId' => $driver->id]))
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->get(route('admin.verification.show', ['driverId' => $driver->id]))
            ->assertOk()
            ->assertSee('File fisik telah dihapus; data verifikasi tetap tersimpan.')
            ->assertSee('Keputusan verifikasi dikunci: Disetujui.')
            ->assertDontSee(route('admin.verification.documents.destroy', [
                'driverId' => $driver->id,
                'documentType' => 'ktp',
            ]), false);
    }

    public function test_admin_cannot_delete_an_unapproved_document_file(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['role' => 'admin', 'phone' => '081355550023']);
        $driverUser = User::factory()->create(['role' => 'driver', 'phone' => '081355550024']);
        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 3345 PND',
            'registration_status' => 'pending',
            'status' => 'offline',
        ]));
        $path = 'driver-documents/'.$driver->id.'/ktp/pending.jpg';
        Storage::disk('public')->put($path, 'pending');
        DriverDocument::query()->create([
            'driver_id' => $driver->id,
            'document_type' => 'ktp',
            'file_path' => $path,
            'verification_status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.verification.documents.destroy', [
                'driverId' => $driver->id,
                'documentType' => 'ktp',
            ]))
            ->assertSessionHas('error', 'File dokumen hanya dapat dihapus setelah dokumen berstatus approved.');

        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('driver_documents', [
            'driver_id' => $driver->id,
            'document_type' => 'ktp',
            'verification_status' => 'pending',
        ]);
    }

    public function test_admin_cannot_change_an_approved_decision_after_the_file_is_deleted(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['role' => 'admin', 'phone' => '081355550025']);
        $driverUser = User::factory()->create(['role' => 'driver', 'phone' => '081355550026']);
        $driver = Driver::query()->create($this->driverAttributes([
            'user_id' => $driverUser->id,
            'vehicle_plate' => 'B 3346 LCK',
            'registration_status' => 'active',
            'status' => 'available',
        ]));
        DriverDocument::query()->create([
            'driver_id' => $driver->id,
            'document_type' => 'ktp',
            'file_path' => 'driver-documents/'.$driver->id.'/ktp/deleted.jpg',
            'verification_status' => 'approved',
            'verified_at' => now()->subDay(),
            'verified_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.verification.review', ['driverId' => $driver->id]), [
                'documents' => [[
                    'document_type' => 'ktp',
                    'verification_status' => 'rejected',
                    'rejection_reason' => 'Tidak boleh diubah setelah file dihapus.',
                ]],
            ])
            ->assertSessionHas(
                'error',
                'Keputusan dokumen ktp tidak dapat diubah karena file fisiknya sudah tidak tersedia.'
            );

        $this->assertDatabaseHas('driver_documents', [
            'driver_id' => $driver->id,
            'document_type' => 'ktp',
            'verification_status' => 'approved',
            'rejection_reason' => null,
        ]);
        $this->assertDatabaseHas('drivers', [
            'id' => $driver->id,
            'registration_status' => 'active',
            'status' => 'available',
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
