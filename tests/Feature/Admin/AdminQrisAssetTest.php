<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminQrisAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_shows_qris_preview_and_upload_form(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081399990001',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('QRIS Pembayaran')
            ->assertSee(route('payments.qris.show'), false)
            ->assertSee('name="qris_image"', false)
            ->assertSee(route('admin.settings.qris.update'), false);
    }

    public function test_admin_can_replace_qris_image_and_old_upload_is_removed(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081399990002',
        ]);
        $oldPath = 'settings/payments/qris/qris-bangdeliv.jpg';
        Storage::disk('public')->put($oldPath, 'old-qris');

        $this->actingAs($admin)
            ->post(route('admin.settings.qris.update'), [
                'qris_image' => UploadedFile::fake()->image('qris-baru.png', 600, 800),
            ])
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHas('success', 'QRIS Pelanggan 15 berhasil diperbarui.');

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists('settings/payments/qris/qris-bangdeliv.png');

        $this->assertSame(
            ['settings/payments/qris/qris-bangdeliv.png'],
            Storage::disk('public')->files('settings/payments/qris')
        );
    }

    public function test_invalid_qris_upload_keeps_existing_file(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => 'admin',
            'phone' => '081399990003',
        ]);
        $oldPath = 'settings/payments/qris/qris-bangdeliv.jpg';
        Storage::disk('public')->put($oldPath, 'old-qris');

        $this->actingAs($admin)
            ->from(route('admin.settings'))
            ->post(route('admin.settings.qris.update'), [
                'qris_image' => UploadedFile::fake()->create('qris.pdf', 10, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHasErrors('qris_image');

        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame([$oldPath], Storage::disk('public')->files('settings/payments/qris'));
    }

    public function test_public_qris_route_serves_uploaded_image(): void
    {
        Storage::fake('public');

        $uploadedImage = UploadedFile::fake()->image('qris.jpg', 600, 800);
        Storage::disk('public')->put(
            'settings/payments/qris/qris-bangdeliv.jpg',
            file_get_contents($uploadedImage->getRealPath())
        );

        $response = $this->get(route('payments.qris.show'))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('max-age=0', (string) $response->headers->get('cache-control'));
    }

    public function test_public_qris_route_serves_dummy_fallback_when_no_upload_exists(): void
    {
        Storage::fake('public');

        $response = $this->get(route('payments.qris.show'))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('max-age=0', (string) $response->headers->get('cache-control'));
    }

    public function test_login_page_uses_split_brand_layout_without_store_icon(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('auth-shell', false)
            ->assertSee('auth-brand-logo', false)
            ->assertSee('images/logo.jpg', false)
            ->assertDontSee('bxs-store-alt', false);
    }
}
