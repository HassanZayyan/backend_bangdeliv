<?php

namespace App\Console\Commands\Pengujian;

use App\Services\Geo\BangDelivServiceAreaService;
use App\Services\Pricing\DeliveryPricingService;
use App\Support\GeoDistance;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pembuat tabel siap salin untuk Bab IV bagian 4.5 (Paket A).
 *
 * Perintah ini menjalankan ulang kasus uji A1, A3, dan A4 melalui service yang
 * sesungguhnya, lalu menuliskan hasilnya sebagai tabel Markdown pada
 * tools/pengujian/HASIL_PAKET_A.md. Angka pada berkas keluaran selalu berasal
 * dari eksekusi nyata, bukan disalin dari catatan sebelumnya.
 */
class LaporanPaketACommand extends Command
{
    protected $signature = 'pengujian:laporan-paket-a
                            {--pengulangan=5 : Jumlah pengulangan pada uji konsistensi A3}';

    protected $description = 'Menghasilkan tools/pengujian/HASIL_PAKET_A.md berisi tabel A1, A3, dan A4 siap salin ke laporan';

    public function handle(DeliveryPricingService $pricing, BangDelivServiceAreaService $area): int
    {
        require_once base_path('tools/pengujian/helper_pengujian.php');

        $kasus = require base_path('tools/pengujian/kasus_uji.php');
        $pengulangan = max(2, (int) $this->option('pengulangan'));

        $a1 = $this->jalankanA1($pricing, $kasus['a1']);
        $a3 = $this->jalankanA3($pricing, $kasus['a3'], $pengulangan);
        $a4 = $this->jalankanA4($area, $kasus['a4']);

        $tujuan = base_path('tools/pengujian/HASIL_PAKET_A.md');
        file_put_contents($tujuan, $this->susunMarkdown($a1, $a3, $a4, $pengulangan));

        $this->info('Laporan Paket A ditulis ke: '.$tujuan);
        $this->newLine();
        $this->line(sprintf('A1 : %d dari %d kasus sesuai (%s%%)',
            $a1['sesuai'], $a1['total'], bangdeliv_desimal($a1['persen'])));
        $this->line(sprintf('A3 : %d dari %d jarak identik pada %d pengulangan (%s%%)',
            $a3['identik'], $a3['total'], $pengulangan, bangdeliv_desimal($a3['persen'])));
        $this->line(sprintf('A4 : %d dari %d keputusan sesuai (%s%%)',
            $a4['sesuai'], $a4['total'], bangdeliv_desimal($a4['persen'])));

        if ($a1['sesuai'] < $a1['total']) {
            $this->newLine();
            $this->warn('Terdapat kasus A1 yang TIDAK sesuai rumus laporan. Rinciannya ada di tabel A1 pada berkas keluaran.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $kasusA1
     * @return array{baris: array<int, array<string, mixed>>, sesuai: int, total: int, persen: float}
     */
    private function jalankanA1(DeliveryPricingService $pricing, array $kasusA1): array
    {
        $baris = [];
        $sesuai = 0;

        foreach ($kasusA1 as $item) {
            $hasil = $pricing->calculateFromDistanceMeters((int) round($item['jarak_km'] * 1000));

            $cocok = (int) $hasil['billed_km'] === (int) $item['billed_km']
                && (float) $hasil['rate_per_km'] === (float) $item['rate_per_km']
                && (float) $hasil['total_fee'] === (float) $item['total_fee'];

            $sesuai += $cocok ? 1 : 0;

            $baris[] = [
                'no' => $item['no'],
                'jarak_km' => $item['jarak_km'],
                'kelompok' => $item['kelompok'],
                'billed_sistem' => (int) $hasil['billed_km'],
                'billed_manual' => (int) $item['billed_km'],
                'rate_sistem' => (float) $hasil['rate_per_km'],
                'rate_manual' => (float) $item['rate_per_km'],
                'total_sistem' => (float) $hasil['total_fee'],
                'total_manual' => (float) $item['total_fee'],
                'hitung_manual' => $item['hitung_manual'],
                'sesuai' => $cocok,
            ];
        }

        $total = count($kasusA1);

        return [
            'baris' => $baris,
            'sesuai' => $sesuai,
            'total' => $total,
            'persen' => $total > 0 ? $sesuai / $total * 100 : 0.0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $kasusA3
     * @return array{baris: array<int, array<string, mixed>>, identik: int, total: int, persen: float}
     */
    private function jalankanA3(DeliveryPricingService $pricing, array $kasusA3, int $pengulangan): array
    {
        $baris = [];
        $identik = 0;

        foreach ($kasusA3 as $item) {
            $ongkos = [];
            for ($i = 0; $i < $pengulangan; $i++) {
                $ongkos[] = (float) $pricing->calculateFromDistanceMeters((int) round($item['jarak_km'] * 1000))['total_fee'];
            }

            $sama = count(array_unique($ongkos, SORT_REGULAR)) === 1;
            $identik += $sama ? 1 : 0;

            $baris[] = [
                'no' => $item['no'],
                'jarak_km' => $item['jarak_km'],
                'ongkos' => $ongkos,
                'identik' => $sama,
            ];
        }

        $total = count($kasusA3);

        return [
            'baris' => $baris,
            'identik' => $identik,
            'total' => $total,
            'persen' => $total > 0 ? $identik / $total * 100 : 0.0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $kasusA4
     * @return array{baris: array<int, array<string, mixed>>, sesuai: int, total: int, persen: float}
     */
    private function jalankanA4(BangDelivServiceAreaService $area, array $kasusA4): array
    {
        $baris = [];
        $sesuai = 0;

        foreach ($kasusA4 as $item) {
            [$lintang, $bujur] = bangdeliv_titik_pada_jarak_km(
                $area->centerLatitude(),
                $area->centerLongitude(),
                $item['jarak_km']
            );

            $jarakTerhitungKm = GeoDistance::meters(
                $area->centerLatitude(),
                $area->centerLongitude(),
                $lintang,
                $bujur
            ) / 1000;

            // Keputusan sistem diambil dari jalur penegakan yang sesungguhnya,
            // yaitu pemeriksaan yang dipanggil saat order dibuat.
            try {
                $area->assertPointWithinRadius($lintang, $bujur, 'titik jemput');
                $keputusan = 'DITERIMA';
            } catch (Throwable) {
                $keputusan = 'DITOLAK';
            }

            $cocok = $keputusan === $item['keputusan_seharusnya'];
            $sesuai += $cocok ? 1 : 0;

            $baris[] = [
                'no' => $item['no'],
                'jarak_km' => $item['jarak_km'],
                'jarak_terhitung_km' => $jarakTerhitungKm,
                'lintang' => $lintang,
                'bujur' => $bujur,
                'keputusan_sistem' => $keputusan,
                'keputusan_seharusnya' => $item['keputusan_seharusnya'],
                'sesuai' => $cocok,
            ];
        }

        $total = count($kasusA4);

        return [
            'baris' => $baris,
            'sesuai' => $sesuai,
            'total' => $total,
            'persen' => $total > 0 ? $sesuai / $total * 100 : 0.0,
        ];
    }

    /**
     * @param  array{baris: array<int, array<string, mixed>>, sesuai: int, total: int, persen: float}  $a1
     * @param  array{baris: array<int, array<string, mixed>>, identik: int, total: int, persen: float}  $a3
     * @param  array{baris: array<int, array<string, mixed>>, sesuai: int, total: int, persen: float}  $a4
     */
    private function susunMarkdown(array $a1, array $a3, array $a4, int $pengulangan): string
    {
        $keluaran = [];

        $keluaran[] = '# Hasil Pengujian Paket A - Akurasi Estimasi Jarak dan Ongkos';
        $keluaran[] = '';
        $keluaran[] = 'Berkas ini dihasilkan otomatis oleh `php artisan pengujian:laporan-paket-a`.';
        $keluaran[] = 'Seluruh angka pada tabel di bawah berasal dari eksekusi nyata terhadap';
        $keluaran[] = '`App\Services\Pricing\DeliveryPricingService` dan `App\Services\Geo\BangDelivServiceAreaService`.';
        $keluaran[] = '';
        $keluaran[] = '## Konfigurasi Tarif yang Terbaca Sistem';
        $keluaran[] = '';
        $keluaran[] = '| Parameter | Nilai | Sumber |';
        $keluaran[] = '|---|---|---|';
        $keluaran[] = '| Tarif dasar (base_fee) | Rp'.bangdeliv_rupiah((float) config('bangdeliv.base_delivery_fee')).' | `config/bangdeliv.php` -> `base_delivery_fee` |';
        $keluaran[] = '| Tarif 0-10 km | Rp'.bangdeliv_rupiah((float) config('bangdeliv.delivery_rate_0_10_per_km')).'/km | `delivery_rate_0_10_per_km` |';
        $keluaran[] = '| Tarif 10-25 km | Rp'.bangdeliv_rupiah((float) config('bangdeliv.delivery_rate_10_25_per_km')).'/km | `delivery_rate_10_25_per_km` |';
        $keluaran[] = '| Tarif 25-50 km | Rp'.bangdeliv_rupiah((float) config('bangdeliv.delivery_rate_25_50_per_km')).'/km | `delivery_rate_25_50_per_km` |';
        $keluaran[] = '| Ambang pembulatan (ROUND_UP_FRACTION) | 0,7 | konstanta `DeliveryPricingService` |';
        $keluaran[] = '| Jarak bebas ongkir jarak (FLAT_DISTANCE_KM) | 1,5 km | konstanta `DeliveryPricingService` |';
        $keluaran[] = '| Radius layanan | '.bangdeliv_desimal((float) config('bangdeliv.service_area.radius_km'), 0).' km | `service_area.radius_km` |';
        $keluaran[] = '';

        // ---------------- Tabel A1 ----------------
        $keluaran[] = '## Tabel A1 - Kesesuaian Perhitungan Ongkos terhadap Hitung Manual';
        $keluaran[] = '';
        $keluaran[] = '| No | Jarak (km) | Billed km | Tarif/km (Rp) | Ongkos Sistem (Rp) | Ongkos Hitung Manual (Rp) | Sesuai |';
        $keluaran[] = '|---|---|---|---|---|---|---|';

        foreach ($a1['baris'] as $row) {
            $billed = $row['billed_sistem'] === $row['billed_manual']
                ? (string) $row['billed_sistem']
                : sprintf('%d (manual %d)', $row['billed_sistem'], $row['billed_manual']);

            $tarif = $row['rate_sistem'] === $row['rate_manual']
                ? bangdeliv_rupiah($row['rate_sistem'])
                : sprintf('%s (manual %s)', bangdeliv_rupiah($row['rate_sistem']), bangdeliv_rupiah($row['rate_manual']));

            $keluaran[] = sprintf(
                '| %d | %s | %s | %s | %s | %s | %s |',
                $row['no'],
                bangdeliv_desimal($row['jarak_km']),
                $billed,
                $tarif,
                bangdeliv_rupiah($row['total_sistem']),
                bangdeliv_rupiah($row['total_manual']),
                $row['sesuai'] ? 'Ya' : '**Tidak**'
            );
        }

        $keluaran[] = '';
        $keluaran[] = sprintf('Kesesuaian A1: **%d dari %d kasus (%s%%)**.',
            $a1['sesuai'], $a1['total'], bangdeliv_desimal($a1['persen']));
        $keluaran[] = '';

        $tidakSesuai = array_values(array_filter($a1['baris'], static fn (array $r): bool => ! $r['sesuai']));

        if ($tidakSesuai !== []) {
            $keluaran[] = '### Rincian Kasus A1 yang Tidak Sesuai';
            $keluaran[] = '';
            $keluaran[] = '| No | Jarak (km) | Hitung Manual menurut Rumus Bab III | Hasil Sistem |';
            $keluaran[] = '|---|---|---|---|';

            foreach ($tidakSesuai as $row) {
                $keluaran[] = sprintf(
                    '| %d | %s | %s | billed %d km, tarif Rp%s, total Rp%s |',
                    $row['no'],
                    bangdeliv_desimal($row['jarak_km']),
                    $row['hitung_manual'],
                    $row['billed_sistem'],
                    bangdeliv_rupiah($row['rate_sistem']),
                    bangdeliv_rupiah($row['total_sistem'])
                );
            }

            $keluaran[] = '';
        }

        // ---------------- Tabel A3 ----------------
        $keluaran[] = '## Tabel A3 - Uji Konsistensi Perhitungan Ongkos';
        $keluaran[] = '';

        $judulA3 = ['No', 'Jarak (km)'];
        for ($i = 1; $i <= $pengulangan; $i++) {
            $judulA3[] = 'Hasil ke-'.$i.' (Rp)';
        }
        $judulA3[] = 'Identik';

        $keluaran[] = '| '.implode(' | ', $judulA3).' |';
        $keluaran[] = '|'.str_repeat('---|', count($judulA3));

        foreach ($a3['baris'] as $row) {
            $sel = [(string) $row['no'], bangdeliv_desimal($row['jarak_km'])];
            foreach ($row['ongkos'] as $nilai) {
                $sel[] = bangdeliv_rupiah($nilai);
            }
            $sel[] = $row['identik'] ? 'Ya' : '**Tidak**';

            $keluaran[] = '| '.implode(' | ', $sel).' |';
        }

        $keluaran[] = '';
        $keluaran[] = sprintf('Konsistensi A3: **%d dari %d jarak identik pada %d pengulangan (%s%%)**.',
            $a3['identik'], $a3['total'], $pengulangan, bangdeliv_desimal($a3['persen']));
        $keluaran[] = '';

        // ---------------- Tabel A4 ----------------
        $keluaran[] = '## Tabel A4 - Batas Radius Layanan';
        $keluaran[] = '';
        $keluaran[] = '| No | Jarak dari Titik Kumpul (km) | Keputusan Sistem | Keputusan Seharusnya | Sesuai |';
        $keluaran[] = '|---|---|---|---|---|';

        foreach ($a4['baris'] as $row) {
            $keluaran[] = sprintf(
                '| %d | %s | %s | %s | %s |',
                $row['no'],
                bangdeliv_desimal($row['jarak_km'], 1),
                $row['keputusan_sistem'],
                $row['keputusan_seharusnya'],
                $row['sesuai'] ? 'Ya' : '**Tidak**'
            );
        }

        $keluaran[] = '';
        $keluaran[] = sprintf('Kesesuaian A4: **%d dari %d kasus (%s%%)**.',
            $a4['sesuai'], $a4['total'], bangdeliv_desimal($a4['persen']));
        $keluaran[] = '';
        $keluaran[] = 'Titik uji dibangkitkan pada meridian yang sama dengan titik kumpul sehingga jarak';
        $keluaran[] = 'haversine-nya persis sebesar jarak yang diuji. Koordinat titik uji:';
        $keluaran[] = '';
        $keluaran[] = '| No | Jarak Target (km) | Lintang | Bujur | Jarak Terhitung Sistem (km) |';
        $keluaran[] = '|---|---|---|---|---|';

        foreach ($a4['baris'] as $row) {
            $keluaran[] = sprintf(
                '| %d | %s | %.8f | %.8f | %s |',
                $row['no'],
                bangdeliv_desimal($row['jarak_km'], 1),
                $row['lintang'],
                $row['bujur'],
                bangdeliv_desimal($row['jarak_terhitung_km'], 4)
            );
        }

        $keluaran[] = '';

        // ---------------- Ringkasan ----------------
        $totalKasus = $a1['total'] + $a3['total'] + $a4['total'];
        $totalSesuai = $a1['sesuai'] + $a3['identik'] + $a4['sesuai'];
        $persenGabungan = $totalKasus > 0 ? $totalSesuai / $totalKasus * 100 : 0.0;

        $keluaran[] = '## Ringkasan Paket A';
        $keluaran[] = '';
        $keluaran[] = '| Sub-pengujian | Jumlah Kasus | Sesuai | Persentase Kesesuaian |';
        $keluaran[] = '|---|---|---|---|';
        $keluaran[] = sprintf('| A1 - Kesesuaian rumus ongkos | %d | %d | %s%% |', $a1['total'], $a1['sesuai'], bangdeliv_desimal($a1['persen']));
        $keluaran[] = sprintf('| A3 - Konsistensi hasil | %d | %d | %s%% |', $a3['total'], $a3['identik'], bangdeliv_desimal($a3['persen']));
        $keluaran[] = sprintf('| A4 - Batas radius layanan | %d | %d | %s%% |', $a4['total'], $a4['sesuai'], bangdeliv_desimal($a4['persen']));
        $keluaran[] = sprintf('| **Gabungan** | **%d** | **%d** | **%s%%** |', $totalKasus, $totalSesuai, bangdeliv_desimal($persenGabungan));
        $keluaran[] = '';
        $keluaran[] = 'Sub-pengujian A2 (deviasi jarak terhadap pembacaan aplikasi Google Maps) dilaporkan';
        $keluaran[] = 'terpisah pada `tools/pengujian/HASIL_A2_DEVIASI_JARAK.md` karena memerlukan pengambilan';
        $keluaran[] = 'data manual dari aplikasi Google Maps.';
        $keluaran[] = '';
        $keluaran[] = $this->catatanTemuan();

        // ---------------- Cara menjalankan ulang ----------------
        $keluaran[] = '## Perintah untuk Menjalankan Ulang Seluruh Pengujian';
        $keluaran[] = '';
        $keluaran[] = 'Dijalankan dari folder `Backend_Bangdeliv`:';
        $keluaran[] = '';
        $keluaran[] = '```bash';
        $keluaran[] = '# A1 dan A4 - kesesuaian rumus ongkos dan batas radius';
        $keluaran[] = 'php artisan test --filter=AkurasiOngkosTest';
        $keluaran[] = '';
        $keluaran[] = '# A3 - uji konsistensi hasil perhitungan';
        $keluaran[] = 'php artisan pengujian:konsistensi-ongkos';
        $keluaran[] = '';
        $keluaran[] = '# A2 - deviasi jarak (memerlukan tools/pengujian/rute_uji.csv terisi)';
        $keluaran[] = 'php artisan pengujian:deviasi-jarak --periksa   # memeriksa kelengkapan berkas lebih dulu';
        $keluaran[] = 'php artisan pengujian:deviasi-jarak             # memanggil Google Maps API';
        $keluaran[] = '';
        $keluaran[] = '# Membuat ulang berkas tabel ini';
        $keluaran[] = 'php artisan pengujian:laporan-paket-a';
        $keluaran[] = '```';
        $keluaran[] = '';

        return implode("\n", $keluaran);
    }

    /**
     * Catatan temuan pengujian beserta angka hasil eksekusi PERTAMA, sebelum
     * perbaikan dilakukan. Bagian ini ditulis tetap dan bukan hasil eksekusi
     * ulang, karena kondisi kode sebelum perbaikan tidak dapat dijalankan lagi.
     * Angka yang dicantumkan diambil apa adanya dari keluaran uji pada
     * eksekusi pertama.
     */
    private function catatanTemuan(): string
    {
        return <<<'MARKDOWN'
## Catatan Temuan Pengujian

Pengujian A1 dijalankan dua kali: sekali pada kondisi kode sebelum perbaikan, dan
sekali setelahnya. Bagian ini mencatat keduanya agar penurunan dan kenaikan angka
dapat dipertanggungjawabkan.

| Eksekusi | Kasus Sesuai | Persentase |
|---|---|---|
| Sebelum perbaikan | 26 dari 30 | 86,67% |
| Setelah perbaikan | 30 dari 30 | 100,00% |

### Temuan 1 - Ambang pembulatan 0,7 berlaku tidak konsisten (galat pembulatan bilangan pecahan)

Pada eksekusi pertama, dua kasus batas pembulatan gagal:

| No | Jarak | Seharusnya (rumus 2) | Hasil sistem saat itu | Selisih tarif |
|---|---|---|---|---|
| 17 | 12,70 km | 13 km tertagih, Rp37.500 | 12 km tertagih, Rp35.000 | Rp2.500 |
| 21 | 20,70 km | 21 km tertagih, Rp57.500 | 20 km tertagih, Rp55.000 | Rp2.500 |

Penyebabnya bukan kesalahan rumus, melainkan keterbatasan representasi bilangan
pecahan IEEE-754 pada perhitungan `distance_km - floor(distance_km)`:

| Jarak | Nilai pecahan yang benar-benar dihitung | Hasil perbandingan terhadap 0,7 |
|---|---|---|
| 2,70 / 3,70 / 5,70 / 33,70 / 49,70 km | 0,70000000000000017 | lebih besar, dibulatkan ke atas (benar) |
| 9,70 / 12,70 / 15,70 / 20,70 / 24,70 km | 0,69999999999999929 | lebih kecil, dibulatkan ke bawah (salah) |

Akibatnya jarak 12,70 km ditagih lebih murah daripada 12,71 km, padahal menurut
rumus (2) keduanya sama-sama dibulatkan ke atas.

Perbaikan yang diterapkan: perbandingan ambang dipindahkan dari satuan kilometer
bertipe pecahan ke satuan meter bertipe bilangan bulat, yaitu `sisa_meter >= 700`
menggantikan `pecahan_km >= 0,7`. Perubahan ini tidak mengubah rumus (2), hanya
membuat implementasinya menghasilkan nilai yang benar-benar sesuai rumus.

### Temuan 2 - Cabang jarak <= 1,5 km belum tertulis pada rumus (2)

Dua kasus lain gagal karena implementasi membebaskan pesanan berjarak 1,5 km ke
bawah dari komponen ongkir jarak (`FLAT_DISTANCE_KM`), sedangkan rumus (2) pada
rancangan awal tidak memuat ketentuan tersebut:

| No | Jarak | Rumus awal | Hasil sistem |
|---|---|---|---|
| 2 | 0,80 km | Rp7.000 | Rp5.000 |
| 3 | 1,00 km | Rp7.000 | Rp5.000 |

Pemeriksaan menunjukkan ketentuan ini memang sudah berlaku sejak awal dan telah
diuji pada `tests/Unit/DeliveryPricingServiceTest.php`, sehingga bukan cacat
implementasi melainkan ketentuan yang belum terdokumentasi. Rumus (2) pada Bab III
diperbaiki dengan menambahkan cabang pertama, dan kode tidak diubah.

### Temuan 3 - Rumus (3) tidak memiliki batas atas 50 km

Implementasi menetapkan tarif Rp3.000/km untuk seluruh jarak 25 km ke atas tanpa
plafon, sedangkan rumus (3) menuliskan rentang tertinggi sebagai "25-50 km".
Radius layanan 50 km diukur dari titik kumpul, bukan dari panjang rute, sehingga
rute yang lebih panjang dari 50 km tetap mungkin terjadi selama seluruh titiknya
berada di dalam radius. Penulisan rentang tertinggi pada Bab III perlu dibaca
sebagai "25 km ke atas", atau plafon tarif ditetapkan sebagai pengembangan lanjutan.

### Temuan 4 - Tarif per km ditentukan dari jarak tempuh, bukan dari kilometer tertagih

Pada jarak 9,90 km, tarif yang berlaku adalah Rp2.000/km (rentang 0-10 km) meskipun
kilometer tertagihnya 10 km, sehingga ongkosnya Rp25.000. Pada jarak 10,00 km tarif
berpindah ke Rp2.500/km sehingga ongkosnya Rp30.000. Terdapat lompatan Rp5.000 pada
batas rentang. Perilaku ini konsisten dengan rumus (3) dan bukan kesalahan, namun
urutan penerapannya (tarif ditentukan lebih dulu dari jarak tempuh, baru dikalikan
kilometer tertagih) perlu dinyatakan eksplisit pada Bab III agar tidak menimbulkan
tafsir ganda.
MARKDOWN;
    }
}
