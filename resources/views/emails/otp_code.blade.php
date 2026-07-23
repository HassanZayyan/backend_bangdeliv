<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode Verifikasi BangDeliv</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:440px; background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,0.06);">
                    <tr>
                        <td style="background-color:#ea580c; padding:28px 32px; text-align:center;">
                            <span style="color:#ffffff; font-size:20px; font-weight:800; letter-spacing:0.3px;">BangDeliv</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 8px; font-size:18px; color:#111827;">Kode Verifikasi Akun</h1>
                            <p style="margin:0 0 24px; font-size:14px; line-height:1.6; color:#6b7280;">
                                Gunakan kode di bawah ini untuk memverifikasi akun BangDeliv Anda.
                            </p>
                            <div style="text-align:center; margin:0 0 24px;">
                                <span style="display:inline-block; background-color:#fff7ed; border:1px solid #fed7aa; border-radius:12px; padding:16px 28px; font-size:32px; font-weight:800; letter-spacing:10px; color:#ea580c;">{{ $code }}</span>
                            </div>
                            <p style="margin:0 0 8px; font-size:13px; line-height:1.6; color:#6b7280;">
                                Kode berlaku {{ $ttlMinutes }} menit. Jangan bagikan kode ini kepada siapa pun.
                            </p>
                            <p style="margin:0; font-size:13px; line-height:1.6; color:#9ca3af;">
                                Jika Anda tidak meminta kode ini, abaikan email ini.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 28px; border-top:1px solid #f3f4f6;">
                            <p style="margin:0; font-size:12px; color:#9ca3af; text-align:center;">
                                &copy; BangDeliv &middot; Email otomatis, mohon tidak dibalas.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
