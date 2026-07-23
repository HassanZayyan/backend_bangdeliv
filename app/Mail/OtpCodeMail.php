<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email berisi kode OTP verifikasi akun BangDeliv.
 */
class OtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kode Verifikasi BangDeliv',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp_code',
            with: [
                'code' => $this->code,
                'ttlMinutes' => $this->ttlMinutes,
            ],
        );
    }
}
