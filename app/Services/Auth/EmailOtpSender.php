<?php

namespace App\Services\Auth;

use App\Mail\OtpCodeMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Pengirim kode OTP verifikasi akun lewat email.
 *
 * Dipilih menggantikan gateway WhatsApp tak resmi (Fonnte dsb.) yang mudah
 * diblokir WhatsApp. SMTP jauh lebih andal dan tidak kena ban.
 *
 * Bila MAIL_MAILER=log, email hanya ditulis ke log (alur tetap bisa diuji di
 * environment lokal tanpa SMTP).
 */
class EmailOtpSender
{
    public function send(string $email, string $code): bool
    {
        $ttlMinutes = (int) config('bangdeliv.otp.ttl_minutes', 5);

        try {
            Mail::to($email)->send(new OtpCodeMail($code, $ttlMinutes));
        } catch (\Throwable $exception) {
            Log::warning('Gagal mengirim kode OTP lewat email.', [
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
