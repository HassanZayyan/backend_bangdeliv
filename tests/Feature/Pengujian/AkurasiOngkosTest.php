<?php

namespace Tests\Feature\Pengujian;

use App\Exceptions\ApiException;
use App\Services\Geo\BangDelivServiceAreaService;
use App\Services\Pricing\DeliveryPricingService;
use App\Support\GeoDistance;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pengujian Paket A - Akurasi Estimasi Jarak dan Ongkos.
 *
 * Berkas ini menguji dua hal:
 *   A1  Kesesuaian implementasi DeliveryPricingService terhadap rumus (1), (2),
 *       dan (3) yang dirancang pada Bab III.
 *   A4  Kesesuaian keputusan penerimaan order terhadap rumus (9), yaitu batas
 *       radius layanan dari titik kumpul Pelanggan 15.
 *
 * Prinsip yang dipegang agar pengujian sah secara ilmiah:
 *   1. Nilai harapan dihitung TANGAN dan disimpan tetap pada
 *      tools/pengujian/kasus_uji.php. Nilai harapan TIDAK PERNAH diperoleh
 *      dengan memanggil ulang service yang sedang diuji (menghindari tautologi).
 *   2. DeliveryPricingService dipakai apa adanya, tanpa mock, karena justru
 *      service itulah objek yang diuji.
 *   3. Setiap kasus uji berdiri sendiri sebagai satu test sehingga kegagalan
 *      dapat ditelusuri per kasus, bukan tenggelam di dalam satu perulangan.
 */
class AkurasiOngkosTest extends TestCase
{
    /**
     * Memuat berkas kasus uji bersama. Dipanggil dari data provider yang
     * bersifat statis dan berjalan sebelum aplikasi Laravel di-boot, sehingga
     * jalur berkas disusun manual, bukan lewat helper base_path().
     *
     * @return array<string, mixed>
     */
    private static function kasusUji(): array
    {
        return require dirname(__DIR__, 3).'/tools/pengujian/kasus_uji.php';
    }

    /**
     * Menyamakan konfigurasi tarif dengan nilai yang tertulis pada Bab III agar
     * hasil uji tidak bergantung pada isi berkas .env di mesin yang menjalankan.
     */
    private function pakaiTarifRancanganBabIII(): void
    {
        config([
            'bangdeliv.base_delivery_fee' => 5000,
            'bangdeliv.delivery_rate_0_10_per_km' => 2000,
            'bangdeliv.delivery_rate_10_25_per_km' => 2500,
            'bangdeliv.delivery_rate_25_50_per_km' => 3000,
            'bangdeliv.service_area.radius_km' => 50,
        ]);
    }

    // =====================================================================
    // A0 - Prasyarat: konfigurasi yang benar-benar dipakai sistem
    // =====================================================================

    /**
     * Membuktikan bahwa nilai tarif yang tertulis di Bab III memang nilai yang
     * dibaca sistem saat berjalan, bukan sekadar angka yang dipaksakan di test.
     * Konfigurasi dibaca apa adanya tanpa override.
     */
    public function test_a0_konfigurasi_tarif_sistem_sesuai_rancangan_bab_iii(): void
    {
        $this->assertSame(5000, (int) config('bangdeliv.base_delivery_fee'),
            'Tarif dasar (base_fee) yang dibaca sistem harus Rp5.000');
        $this->assertSame(2000, (int) config('bangdeliv.delivery_rate_0_10_per_km'),
            'Tarif rentang 0-10 km harus Rp2.000/km');
        $this->assertSame(2500, (int) config('bangdeliv.delivery_rate_10_25_per_km'),
            'Tarif rentang 10-25 km harus Rp2.500/km');
        $this->assertSame(3000, (int) config('bangdeliv.delivery_rate_25_50_per_km'),
            'Tarif rentang 25-50 km harus Rp3.000/km');
        $this->assertSame(50.0, (float) config('bangdeliv.service_area.radius_km'),
            'Radius layanan harus 50 km');
    }

    // =====================================================================
    // A1 - Kesesuaian implementasi terhadap rumus (1), (2), dan (3)
    // =====================================================================

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function kasusA1Provider(): array
    {
        $provider = [];

        foreach (self::kasusUji()['a1'] as $kasus) {
            // Nama kasus dibuat deskriptif supaya keluaran PHPUnit langsung
            // dapat dipakai sebagai bukti pada laporan.
            $nama = sprintf(
                'A1-%02d jarak %s km [%s]',
                $kasus['no'],
                number_format($kasus['jarak_km'], 2, ',', ''),
                $kasus['kelompok']
            );

            $provider[$nama] = [$kasus];
        }

        return $provider;
    }

    /**
     * Menguji satu kasus ongkos terhadap hasil hitung manual.
     *
     * Yang diperiksa: billed_km (rumus 2), rate_per_km (rumus 3), dan
     * total_fee (rumus 1).
     *
     * @param  array<string, mixed>  $kasus
     */
    #[DataProvider('kasusA1Provider')]
    public function test_a1_ongkos_sesuai_hitung_manual_rumus_laporan(array $kasus): void
    {
        $this->pakaiTarifRancanganBabIII();

        // Sistem menerima jarak dalam satuan meter, maka jarak uji dikonversi
        // lebih dulu. Pembulatan ke bilangan bulat meter mengikuti perilaku
        // Google Maps yang memang mengembalikan jarak dalam meter bulat.
        $jarakMeter = (int) round($kasus['jarak_km'] * 1000);

        $hasil = app(DeliveryPricingService::class)->calculateFromDistanceMeters($jarakMeter);

        $konteks = sprintf(
            "Kasus A1-%d, jarak %s km.\nHitung manual: %s",
            $kasus['no'],
            number_format($kasus['jarak_km'], 2, ',', ''),
            $kasus['hitung_manual']
        );

        $this->assertSame($kasus['billed_km'], $hasil['billed_km'],
            'Kilometer tertagih (rumus 2) tidak sesuai. '.$konteks);
        $this->assertSame((float) $kasus['rate_per_km'], $hasil['rate_per_km'],
            'Tarif per km (rumus 3) tidak sesuai. '.$konteks);
        $this->assertSame((float) $kasus['total_fee'], $hasil['total_fee'],
            'Total ongkos (rumus 1) tidak sesuai. '.$konteks);
    }

    // =====================================================================
    // A4 - Batas radius layanan (rumus 9)
    // =====================================================================

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function kasusA4Provider(): array
    {
        $provider = [];

        foreach (self::kasusUji()['a4'] as $kasus) {
            $nama = sprintf(
                'A4-%d jarak %s km dari titik kumpul (harusnya %s)',
                $kasus['no'],
                number_format($kasus['jarak_km'], 1, ',', ''),
                $kasus['keputusan_seharusnya']
            );

            $provider[$nama] = [$kasus];
        }

        return $provider;
    }

    /**
     * Menguji keputusan terima/tolak order berdasarkan jarak titik pesanan ke
     * titik kumpul Pelanggan 15.
     *
     * Titik uji dibangkitkan pada meridian yang sama dengan titik kumpul agar
     * jarak haversine-nya persis sebesar jarak yang diuji. Keakuratan generator
     * titik ini ikut diverifikasi di dalam test (toleransi 1 meter) supaya
     * kegagalan uji tidak salah dituduhkan ke service yang diuji.
     *
     * @param  array<string, mixed>  $kasus
     */
    #[DataProvider('kasusA4Provider')]
    public function test_a4_keputusan_radius_sesuai_rumus_laporan(array $kasus): void
    {
        $this->pakaiTarifRancanganBabIII();

        require_once dirname(__DIR__, 3).'/tools/pengujian/helper_pengujian.php';

        $area = app(BangDelivServiceAreaService::class);

        [$lintang, $bujur] = bangdeliv_titik_pada_jarak_km(
            $area->centerLatitude(),
            $area->centerLongitude(),
            $kasus['jarak_km']
        );

        // Verifikasi generator titik: jarak yang terbentuk harus sesuai target.
        $jarakTerhitungMeter = GeoDistance::meters(
            $area->centerLatitude(),
            $area->centerLongitude(),
            $lintang,
            $bujur
        );

        $this->assertEqualsWithDelta(
            $kasus['jarak_km'] * 1000,
            $jarakTerhitungMeter,
            1.0,
            'Titik uji tidak berhasil dibangkitkan pada jarak yang diminta.'
        );

        $diterimaSeharusnya = $kasus['keputusan_seharusnya'] === 'DITERIMA';

        // Pemeriksaan 1: pembacaan boolean radius.
        $this->assertSame(
            $diterimaSeharusnya,
            $area->isWithinRadius($lintang, $bujur),
            sprintf('Kasus A4-%d. %s', $kasus['no'], $kasus['alasan'])
        );

        // Pemeriksaan 2: penegakan aturan saat order benar-benar divalidasi.
        if ($diterimaSeharusnya) {
            $area->assertPointWithinRadius($lintang, $bujur, 'titik jemput');
            $this->addToAssertionCount(1);

            return;
        }

        try {
            $area->assertPointWithinRadius($lintang, $bujur, 'titik jemput');
            $this->fail(sprintf(
                'Kasus A4-%d seharusnya DITOLAK, tetapi sistem menerimanya. %s',
                $kasus['no'],
                $kasus['alasan']
            ));
        } catch (ApiException $exception) {
            $this->assertSame(
                BangDelivServiceAreaService::ERROR_DISTANCE_LIMIT,
                $exception->errors()['code'] ?? null,
                'Penolakan harus memakai kode kesalahan batas layanan.'
            );
        }
    }
}
