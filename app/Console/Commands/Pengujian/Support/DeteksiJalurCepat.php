<?php

namespace App\Console\Commands\Pengujian\Support;

use App\Http\Controllers\Api\ChatbotController;
use App\Models\User;
use ReflectionClass;
use Throwable;

/**
 * Pembungkus untuk memanggil jalur cepat (fast path) milik ChatbotController.
 *
 * ChatbotController memeriksa sebagian pesan secara deterministik SEBELUM
 * memanggil Gemini, sehingga pesan tersebut tidak pernah diproses oleh prompt.
 * Kelas ini memanggil dua metode privat itu apa adanya melalui Reflection --
 * bukan menyalin ulang logikanya -- supaya hasil pemeriksaan tidak pernah
 * berbeda dari perilaku aplikasi meskipun controller berubah kelak.
 *
 * Pemeriksaan ini sepenuhnya lokal dan TIDAK memanggil API apa pun.
 */
final class DeteksiJalurCepat
{
    private static ?ChatbotController $controller = null;

    /**
     * Mengembalikan label jalur cepat yang mencegat pesan (nilai model_used
     * pada controller, misalnya "deterministic-command"), atau null bila pesan
     * memang diteruskan ke Gemini.
     */
    public static function periksa(string $serviceType, string $pesan): ?string
    {
        if (trim($pesan) === '' || trim($pesan) === '-') {
            return null;
        }

        // Cabang terakhir jalur cepat Nitip membaca cache percakapan. Pengujian
        // ini memakai pesan tunggal tanpa percakapan berjalan, sehingga cache
        // memang harus kosong; penyimpanan array dipakai agar pemeriksaan tidak
        // bergantung pada basis data dan tetap murni lokal.
        config(['cache.default' => 'array']);

        try {
            $controller = self::$controller ??= app(ChatbotController::class);
            $refleksi = new ReflectionClass($controller);

            if ($serviceType === 'nitip') {
                $metode = $refleksi->getMethod('detectShoppingFastPayload');
                $metode->setAccessible(true);

                // User tiruan tanpa penyimpanan: jalur cepat hanya memakainya
                // untuk membaca cache percakapan, yang pada pengujian memang kosong.
                $pengguna = new User;
                $pengguna->id = 0;

                $hasil = $metode->invoke($controller, $pesan, $pengguna, 'pengujian-aturan');
            } else {
                $metode = $refleksi->getMethod('detectTransportFastPayload');
                $metode->setAccessible(true);

                $hasil = $metode->invoke($controller, $pesan, $serviceType);
            }
        } catch (Throwable $galat) {
            return null;
        }

        if (! is_array($hasil)) {
            return null;
        }

        return (string) ($hasil['model_used'] ?? 'deterministic');
    }
}
