<?php

namespace App\Console\Commands\Pengujian\Support;

use App\Services\Chatbot\ChatbotPromptLibrary;

/**
 * Pembaca spesifikasi prompt.
 *
 * Seluruh acuan kebenaran pengujian kepatuhan diambil dari sini, yaitu dari
 * string instruksi yang benar-benar dikirim ke Gemini. Tidak ada butir aturan
 * maupun daftar nilai sah yang diketik ulang secara manual, sehingga bila
 * ChatbotPromptLibrary berubah, kasus uji dan penilaiannya ikut berubah.
 */
final class SpesifikasiPrompt
{
    /**
     * Instruksi lengkap per layanan.
     */
    public static function instruksi(string $serviceType): string
    {
        return match ($serviceType) {
            'nitip' => ChatbotPromptLibrary::foodOrderInstruction(),
            'kurir' => ChatbotPromptLibrary::courierInstruction(),
            'antar_jemput' => ChatbotPromptLibrary::rideInstruction(),
            default => '',
        };
    }

    /**
     * Butir-butir blok ATURAN apa adanya. Pembacaan berhenti pada baris
     * KELUARAN agar contoh few-shot tidak ikut terbaca.
     *
     * @return array<int, string>
     */
    public static function butirAturan(string $serviceType): array
    {
        $hasil = [];

        foreach (explode("\n", self::instruksi($serviceType)) as $baris) {
            if (str_starts_with($baris, 'KELUARAN: ')) {
                break;
            }

            if (str_starts_with($baris, '- ')) {
                $hasil[] = substr($baris, 2);
            }
        }

        return $hasil;
    }

    /**
     * Isi blok KELUARAN (kontrak keluaran) apa adanya.
     */
    public static function kontrakKeluaran(string $serviceType): string
    {
        foreach (explode("\n", self::instruksi($serviceType)) as $baris) {
            if (str_starts_with($baris, 'KELUARAN: ')) {
                return substr($baris, strlen('KELUARAN: '));
            }
        }

        return '';
    }

    /**
     * Daftar nilai intent dan command yang dinyatakan sah pada kontrak
     * keluaran. Dipakai untuk mendeteksi keluaran model di luar enumerasi.
     *
     * @return array{intent: array<int, string>, command: array<int, string>}
     */
    public static function nilaiSah(string $serviceType): array
    {
        $kontrak = self::kontrakKeluaran($serviceType);

        return [
            'intent' => self::petikNilai($kontrak, 'intent valid:'),
            'command' => self::petikNilai($kontrak, 'command valid:'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function petikNilai(string $kontrak, string $penanda): array
    {
        $posisi = mb_strpos($kontrak, $penanda);

        if ($posisi === false) {
            return [];
        }

        $potongan = mb_substr($kontrak, $posisi + mb_strlen($penanda));
        $akhir = mb_strpos($potongan, '. ');

        if ($akhir !== false) {
            $potongan = mb_substr($potongan, 0, $akhir);
        }

        preg_match_all('/"([^"]+)"/u', $potongan, $cocok);

        return $cocok[1] ?? [];
    }
}
