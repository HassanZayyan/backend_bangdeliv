<?php

namespace Tests\Feature\Api;

use App\Models\PhoneVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthPhoneOtpTest extends TestCase
{
    use RefreshDatabase;

    private function unverifiedUser(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'phone' => '081234567890',
            'password' => Hash::make('rahasia123'),
            'role' => 'customer',
            'phone_verified_at' => null,
        ], $overrides));
    }

    private function useFonnteToken(): void
    {
        config(['bangdeliv.whatsapp.fonnte_token' => 'test-token']);
    }

    public function test_send_otp_stores_hashed_code_and_calls_gateway(): void
    {
        $this->useFonnteToken();
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true], 200)]);
        $user = $this->unverifiedUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/otp/send')
            ->assertOk()
            ->assertJsonPath('data.already_verified', false)
            ->assertJsonPath('data.resend_available_in', 60);

        $record = PhoneVerificationCode::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('081234567890', $record->phone);
        $this->assertSame(0, $record->attempts);
        $this->assertNotNull($record->last_sent_at);
        $this->assertTrue($record->expires_at->isFuture());
        // Kode disimpan dalam bentuk hash, bukan teks polos.
        $this->assertFalse(Hash::check('000000', $record->code_hash));

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'test-token')
                && $request['target'] === '081234567890'
                && preg_match('/\b\d{6}\b/', (string) $request['message']) === 1;
        });
    }

    public function test_send_otp_without_token_only_logs_and_skips_gateway(): void
    {
        config(['bangdeliv.whatsapp.fonnte_token' => '']);
        Http::fake();
        Sanctum::actingAs($this->unverifiedUser());

        $this->postJson('/api/auth/otp/send')->assertOk();

        Http::assertNothingSent();
        $this->assertDatabaseCount('phone_verification_codes', 1);
    }

    public function test_send_otp_respects_resend_cooldown(): void
    {
        $this->useFonnteToken();
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true], 200)]);
        Sanctum::actingAs($this->unverifiedUser());

        $this->postJson('/api/auth/otp/send')->assertOk();

        $this->postJson('/api/auth/otp/send')
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after_seconds']);
    }

    public function test_send_otp_rejects_user_without_phone(): void
    {
        Sanctum::actingAs($this->unverifiedUser(['phone' => null]));

        $this->postJson('/api/auth/otp/send')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nomor WhatsApp belum diisi.');
    }

    public function test_send_otp_is_noop_when_phone_already_verified(): void
    {
        Http::fake();
        Sanctum::actingAs($this->unverifiedUser(['phone_verified_at' => now()]));

        $this->postJson('/api/auth/otp/send')
            ->assertOk()
            ->assertJsonPath('data.already_verified', true);

        $this->assertDatabaseCount('phone_verification_codes', 0);
        Http::assertNothingSent();
    }

    public function test_send_otp_reports_gateway_soft_failure_without_starting_cooldown(): void
    {
        $this->useFonnteToken();
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => false, 'reason' => 'device not connected'], 200)]);
        Sanctum::actingAs($this->unverifiedUser());

        $this->postJson('/api/auth/otp/send')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Gagal mengirim kode verifikasi WhatsApp. Coba lagi.');

        $this->assertDatabaseCount('phone_verification_codes', 0);
    }

    public function test_verify_otp_marks_phone_verified_and_returns_profile(): void
    {
        $user = $this->unverifiedUser();
        Sanctum::actingAs($user);
        PhoneVerificationCode::query()->create([
            'user_id' => $user->id,
            'phone' => '081234567890',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'last_sent_at' => now(),
        ]);

        $this->postJson('/api/auth/otp/verify', ['code' => '123456'])
            ->assertOk()
            ->assertJsonPath('message', 'Nomor WhatsApp berhasil diverifikasi.')
            ->assertJsonPath('data.requires_phone_verification', false)
            ->assertJsonPath('data.requires_phone_completion', false);

        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertDatabaseCount('phone_verification_codes', 0);
    }

    public function test_verify_otp_locks_after_max_attempts(): void
    {
        $user = $this->unverifiedUser();
        Sanctum::actingAs($user);
        PhoneVerificationCode::query()->create([
            'user_id' => $user->id,
            'phone' => '081234567890',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'last_sent_at' => now(),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/otp/verify', ['code' => '000000'])->assertStatus(422);
        }

        // Kode benar pun ditolak setelah percobaan habis.
        $this->postJson('/api/auth/otp/verify', ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Terlalu banyak percobaan. Kirim ulang kode baru.');

        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_verify_otp_rejects_expired_code(): void
    {
        $user = $this->unverifiedUser();
        Sanctum::actingAs($user);
        PhoneVerificationCode::query()->create([
            'user_id' => $user->id,
            'phone' => '081234567890',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
            'attempts' => 0,
            'last_sent_at' => now()->subMinutes(6),
        ]);

        $this->postJson('/api/auth/otp/verify', ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kode OTP kedaluwarsa. Kirim ulang kode.');
    }

    public function test_verify_otp_without_prior_send_fails(): void
    {
        Sanctum::actingAs($this->unverifiedUser());

        $this->postJson('/api/auth/otp/verify', ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kode OTP tidak ditemukan. Kirim ulang kode.');
    }

    public function test_verify_otp_rejects_code_issued_for_a_different_phone(): void
    {
        $user = $this->unverifiedUser();
        Sanctum::actingAs($user);
        PhoneVerificationCode::query()->create([
            'user_id' => $user->id,
            'phone' => '081234567890',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'last_sent_at' => now(),
        ]);

        $this->patchJson('/api/user/phone', ['phone' => '081298765432'])->assertOk();

        $this->postJson('/api/auth/otp/verify', ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kode OTP tidak ditemukan. Kirim ulang kode.');
    }

    public function test_verify_otp_requires_six_digit_code(): void
    {
        Sanctum::actingAs($this->unverifiedUser());

        $this->postJson('/api/auth/otp/verify', ['code' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_changing_phone_re_triggers_verification_flag(): void
    {
        $user = $this->unverifiedUser(['phone_verified_at' => now()]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/phone', ['phone' => '081298765432'])
            ->assertOk()
            ->assertJsonPath('data.requires_phone_verification', true);
    }
}
