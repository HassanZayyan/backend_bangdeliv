<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pengirim kode OTP verifikasi nomor WhatsApp lewat gateway Fonnte.
 *
 * Bila token belum dikonfigurasi, kode hanya ditulis ke log (mirip perilaku
 * MAIL_MAILER=log) sehingga alur tetap bisa diuji di environment lokal.
 */
class WhatsAppOtpSender
{
    public function send(string $phone, string $code): bool
    {
        $token = trim((string) config('bangdeliv.whatsapp.fonnte_token', ''));
        $ttlMinutes = (int) config('bangdeliv.otp.ttl_minutes', 5);

        if ($token === '') {
            Log::info('Kode OTP WhatsApp tidak dikirim: token Fonnte belum dikonfigurasi.', [
                'phone' => $phone,
                'code' => $code,
            ]);

            return true;
        }

        $message = "Kode verifikasi BangDeliv Anda: {$code}. "
            ."Berlaku {$ttlMinutes} menit. Jangan bagikan kode ini kepada siapa pun.";

        try {
            $response = Http::timeout((int) config('bangdeliv.whatsapp.timeout_seconds', 10))
                ->withHeaders(['Authorization' => $token])
                ->asForm()
                ->post((string) config('bangdeliv.whatsapp.fonnte_endpoint', 'https://api.fonnte.com/send'), [
                    'target' => $this->normalizeTarget($phone),
                    'message' => $message,
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Gagal memanggil gateway WhatsApp Fonnte.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Gateway WhatsApp Fonnte membalas status error.', [
                'phone' => $phone,
                'status' => $response->status(),
            ]);

            return false;
        }

        // Fonnte dapat membalas HTTP 200 dengan status false (device belum
        // terhubung, kuota habis, nomor tidak valid).
        $payload = $response->json();
        if (is_array($payload) && array_key_exists('status', $payload) && $payload['status'] !== true) {
            Log::warning('Gateway WhatsApp Fonnte menolak pengiriman.', [
                'phone' => $phone,
                'reason' => $payload['reason'] ?? null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Fonnte menerima nomor dalam bentuk digit (08xxx atau 62xxx) tanpa tanda plus.
     */
    private function normalizeTarget(string $phone): string
    {
        return ltrim(preg_replace('/[^0-9+]/', '', trim($phone)) ?? '', '+');
    }
}
