<?php

namespace Tests\Feature\Pengujian;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Uji kelayakan perkakas pengujian A2.
 *
 * Berkas ini TIDAK mengukur akurasi jarak. Tujuannya hanya membuktikan bahwa
 * perintah `pengujian:deviasi-jarak` berjalan benar: menolak berkas yang belum
 * lengkap, serta menghitung selisih, persen error, MAE, dan MAPE dengan rumus
 * yang tepat. Respons Google Maps sengaja dipalsukan agar kuota API tidak
 * terpakai dan agar hasil hitungnya dapat diperiksa terhadap angka yang sudah
 * diketahui.
 *
 * Pengukuran akurasi yang sesungguhnya tetap dilakukan dengan menjalankan
 * perintah tersebut tanpa pemalsuan, memakai koordinat dan jarak acuan nyata.
 */
class DeviasiJarakCommandTest extends TestCase
{
    private string $berkasCsv = 'tools/pengujian/tmp/rute_uji_test.csv';

    private string $berkasLaporan = 'tools/pengujian/tmp/hasil_test.md';

    protected function tearDown(): void
    {
        foreach ([$this->berkasCsv, $this->berkasLaporan] as $berkas) {
            $jalur = base_path($berkas);
            if (is_file($jalur)) {
                unlink($jalur);
            }
        }

        $folder = base_path('tools/pengujian/tmp');
        if (is_dir($folder)) {
            @rmdir($folder);
        }

        parent::tearDown();
    }

    /**
     * Perintah harus berhenti dengan pesan jelas selama kolom acuan manual
     * belum diisi, supaya tidak ada angka deviasi yang dihasilkan dari data
     * kosong.
     */
    public function test_perintah_berhenti_bila_jarak_google_maps_belum_diisi(): void
    {
        $this->tulisCsv([
            '1,Titik A,-7.32,110.46,Titik B,-7.33,110.47,,,,',
        ]);

        $this->artisan('pengujian:deviasi-jarak', ['--berkas' => $this->berkasCsv, '--periksa' => true])
            ->expectsOutputToContain('Berkas rute uji belum siap dijalankan.')
            ->expectsOutputToContain('jarak_google_maps_km')
            ->assertExitCode(1);
    }

    /**
     * Memeriksa aritmetika selisih, persen error, MAE, dan MAPE terhadap angka
     * yang dihitung tangan:
     *
     *   Baris 1: Google 10,00 km, sistem 10.500 m = 10,50 km
     *            selisih  = 10,50 - 10,00 = 0,50 km
     *            error    = 0,50 / 10,00 x 100% = 5,00%
     *   Baris 2: Google 20,00 km, sistem 19.000 m = 19,00 km
     *            selisih  = 19,00 - 20,00 = -1,00 km
     *            error    = 1,00 / 20,00 x 100% = 5,00%
     *
     *   MAE  = (0,50 + 1,00) / 2 = 0,750 km
     *   MAPE = (5,00 + 5,00) / 2 = 5,00%
     */
    public function test_perintah_menghitung_mae_dan_mape_sesuai_hitung_manual(): void
    {
        config(['bangdeliv.google_maps_api_key' => 'kunci-uji']);

        $this->tulisCsv([
            '1,Titik A,-7.32,110.46,Titik B,-7.33,110.47,10,,,',
            '2,Titik C,-7.34,110.48,Titik D,-7.35,110.49,20,,,',
        ]);

        $urutan = 0;
        Http::fake([
            'routes.googleapis.com/*' => function () use (&$urutan) {
                $jarak = [10500, 19000][$urutan] ?? 0;
                $urutan++;

                return Http::response([
                    'routes' => [[
                        'distanceMeters' => $jarak,
                        'duration' => '900s',
                    ]],
                ]);
            },
        ]);

        $this->artisan('pengujian:deviasi-jarak', [
            '--berkas' => $this->berkasCsv,
            '--laporan' => $this->berkasLaporan,
            '--paksa' => true,
        ])
            ->expectsOutputToContain('MAE  = 0,750 km')
            ->expectsOutputToContain('MAPE = 5,00 %')
            ->assertExitCode(0);

        // CSV harus terisi ulang dengan hasil hitung agar dapat dilampirkan.
        $isiCsv = file_get_contents(base_path($this->berkasCsv));
        $this->assertStringContainsString('10.5,0.5,5', $isiCsv);
        $this->assertStringContainsString('19,-1,5', $isiCsv);

        // Berkas laporan Markdown harus terbentuk dan memuat metriknya.
        $isiLaporan = file_get_contents(base_path($this->berkasLaporan));
        $this->assertStringContainsString('| MAE (Mean Absolute Error) | 0,750 km |', $isiLaporan);
        $this->assertStringContainsString('| MAPE (Mean Absolute Percentage Error) | 5,00 % |', $isiLaporan);
    }

    /**
     * @param  array<int, string>  $baris
     */
    private function tulisCsv(array $baris): void
    {
        $jalur = base_path($this->berkasCsv);
        @mkdir(dirname($jalur), 0775, true);

        $judul = 'no,nama_asal,lat_asal,lng_asal,nama_tujuan,lat_tujuan,lng_tujuan,'
            .'jarak_google_maps_km,jarak_sistem_km,selisih_km,persen_error';

        file_put_contents($jalur, $judul."\n".implode("\n", $baris)."\n");
    }
}
