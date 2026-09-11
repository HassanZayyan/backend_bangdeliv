<?php

namespace App\Console\Commands\Pengujian;

use App\Services\Maps\GoogleMapsDistanceMatrixService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pengujian A2 - Deviasi Jarak Sistem terhadap Pembacaan Aplikasi Google Maps.
 *
 * Perintah ini membaca tools/pengujian/rute_uji.csv, memanggil layanan rute yang
 * dipakai aplikasi untuk setiap pasangan koordinat, lalu menghitung selisih dan
 * persentase kesalahan terhadap jarak yang dibaca manual dari aplikasi Google
 * Maps. Metrik akhir yang dihasilkan adalah MAE (km) dan MAPE (%).
 *
 * Perintah sengaja BERHENTI bila kolom jarak_google_maps_km atau koordinat
 * masih kosong, karena tanpa acuan manual tidak ada deviasi yang bisa dihitung.
 */
class HitungDeviasiJarakCommand extends Command
{
    protected $signature = 'pengujian:deviasi-jarak
                            {--berkas=tools/pengujian/rute_uji.csv : Lokasi berkas CSV rute uji}
                            {--laporan=tools/pengujian/HASIL_A2_DEVIASI_JARAK.md : Lokasi berkas Markdown keluaran}
                            {--periksa : Hanya memeriksa kelengkapan berkas, tanpa memanggil API}
                            {--paksa : Lewati konfirmasi sebelum memanggil Google Maps API}';

    protected $description = 'Pengujian A2: menghitung deviasi jarak sistem terhadap pembacaan aplikasi Google Maps (MAE dan MAPE)';

    private const KOLOM = [
        'no', 'nama_asal', 'lat_asal', 'lng_asal', 'nama_tujuan', 'lat_tujuan', 'lng_tujuan',
        'jarak_google_maps_km', 'jarak_sistem_km', 'selisih_km', 'persen_error',
    ];

    public function handle(GoogleMapsDistanceMatrixService $maps): int
    {
        require_once base_path('tools/pengujian/helper_pengujian.php');

        $berkas = base_path((string) $this->option('berkas'));

        if (! is_file($berkas)) {
            $this->error('Berkas rute uji tidak ditemukan: '.$berkas);

            return self::FAILURE;
        }

        $baris = $this->bacaCsv($berkas);

        if ($baris === null) {
            return self::FAILURE;
        }

        $siap = [];
        $belumLengkap = [];

        foreach ($baris as $row) {
            $alasan = $this->alasanBelumLengkap($row);

            if ($alasan !== null) {
                $belumLengkap[] = ['no' => $row['no'], 'alasan' => $alasan];

                continue;
            }

            $siap[] = $row;
        }

        if ($belumLengkap !== []) {
            $this->error('Berkas rute uji belum siap dijalankan.');
            $this->newLine();
            $this->table(['Baris', 'Yang masih kurang'], array_map(
                static fn (array $item): array => [$item['no'], $item['alasan']],
                $belumLengkap
            ));
            $this->newLine();
            $this->line('Isi terlebih dahulu kolom berikut untuk setiap baris:');
            $this->line('  - lat_asal, lng_asal, lat_tujuan, lng_tujuan  (koordinat nyata di area layanan)');
            $this->line('  - jarak_google_maps_km                        (dibaca manual dari aplikasi Google Maps)');
            $this->newLine();
            $this->line('Berkas: '.$berkas);

            return self::FAILURE;
        }

        if ($siap === []) {
            $this->error('Tidak ada baris yang dapat diuji.');

            return self::FAILURE;
        }

        $this->info('Pengujian A2 - Deviasi Jarak');
        $this->line(sprintf('Baris siap diuji : %d', count($siap)));
        $this->line(sprintf(
            'Perkiraan panggilan Google Maps API : %d sampai %d panggilan',
            count($siap),
            count($siap) * 2
        ));
        $this->line('  (satu panggilan Routes API per rute; bila gagal, sistem mencoba Distance Matrix API sekali lagi)');
        $this->newLine();

        if ($this->option('periksa')) {
            $this->info('Mode periksa: berkas sudah lengkap dan siap dijalankan tanpa opsi --periksa.');

            return self::SUCCESS;
        }

        if (! $this->option('paksa') && ! $this->confirm('Lanjutkan memanggil Google Maps API sekarang?', true)) {
            $this->line('Dibatalkan. Tidak ada panggilan API yang dilakukan.');

            return self::SUCCESS;
        }

        $hasil = [];
        $gagal = [];

        foreach ($siap as $row) {
            try {
                $rute = $maps->resolveRoute(
                    (float) $row['lat_asal'],
                    (float) $row['lng_asal'],
                    (float) $row['lat_tujuan'],
                    (float) $row['lng_tujuan'],
                );
            } catch (Throwable $exception) {
                // Kegagalan dicatat apa adanya, tidak dilewati diam-diam.
                $gagal[] = ['no' => $row['no'], 'pesan' => $exception->getMessage()];
                $this->warn(sprintf('Baris %s gagal: %s', $row['no'], $exception->getMessage()));

                continue;
            }

            $jarakSistem = round(((int) $rute['distance_meters']) / 1000, 2);
            $jarakGoogle = (float) str_replace(',', '.', (string) $row['jarak_google_maps_km']);
            $selisih = round($jarakSistem - $jarakGoogle, 2);
            $persenError = $jarakGoogle > 0 ? round(abs($selisih) / $jarakGoogle * 100, 2) : null;

            $hasil[] = [
                'no' => $row['no'],
                'nama_asal' => $row['nama_asal'],
                'nama_tujuan' => $row['nama_tujuan'],
                'jarak_google_maps_km' => $jarakGoogle,
                'jarak_sistem_km' => $jarakSistem,
                'selisih_km' => $selisih,
                'persen_error' => $persenError,
                'penyedia_rute' => (string) ($rute['route_provider'] ?? '-'),
            ];

            $this->line(sprintf(
                '  Baris %2s : Google %s km | Sistem %s km | selisih %s km | error %s%% | sumber %s',
                $row['no'],
                bangdeliv_desimal($jarakGoogle),
                bangdeliv_desimal($jarakSistem),
                bangdeliv_desimal($selisih),
                $persenError === null ? '-' : bangdeliv_desimal($persenError),
                (string) ($rute['route_provider'] ?? '-')
            ));

            // Jeda singkat agar tidak menembak API secara beruntun.
            usleep(300_000);
        }

        if ($hasil === []) {
            $this->error('Seluruh baris gagal dihitung. MAE dan MAPE tidak dapat dihasilkan.');

            return self::FAILURE;
        }

        $mae = array_sum(array_map(static fn (array $r): float => abs((float) $r['selisih_km']), $hasil)) / count($hasil);
        $persenValid = array_values(array_filter(array_map(
            static fn (array $r): ?float => $r['persen_error'] === null ? null : (float) $r['persen_error'],
            $hasil
        ), static fn (?float $v): bool => $v !== null));
        $mape = $persenValid === [] ? 0.0 : array_sum($persenValid) / count($persenValid);

        $berkasLaporan = base_path((string) $this->option('laporan'));

        $this->tulisUlangCsv($berkas, $baris, $hasil);
        $this->tulisLaporan($berkasLaporan, $hasil, $gagal, $mae, $mape);

        $this->newLine();
        $this->info(sprintf('MAE  = %s km', bangdeliv_desimal($mae, 3)));
        $this->info(sprintf('MAPE = %s %%', bangdeliv_desimal($mape, 2)));
        $this->line(sprintf('Rute berhasil dihitung : %d, gagal : %d', count($hasil), count($gagal)));
        $this->line('CSV diperbarui   : '.$berkas);
        $this->line('Laporan ditulis  : '.$berkasLaporan);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string>>|null
     */
    private function bacaCsv(string $berkas): ?array
    {
        $pegangan = fopen($berkas, 'r');

        if ($pegangan === false) {
            $this->error('Berkas rute uji tidak dapat dibuka.');

            return null;
        }

        $judul = fgetcsv($pegangan);

        if ($judul === false) {
            fclose($pegangan);
            $this->error('Berkas rute uji kosong.');

            return null;
        }

        $judul = array_map(static fn (?string $v): string => trim((string) $v), $judul);

        if ($judul !== self::KOLOM) {
            fclose($pegangan);
            $this->error('Judul kolom CSV tidak sesuai. Kolom yang diharapkan:');
            $this->line('  '.implode(', ', self::KOLOM));

            return null;
        }

        $baris = [];
        while (($data = fgetcsv($pegangan)) !== false) {
            // fgetcsv mengembalikan [null] untuk baris kosong di akhir berkas.
            if ($data === [null]) {
                continue;
            }

            $data = array_pad(array_map(static fn (?string $v): string => trim((string) $v), $data), count(self::KOLOM), '');
            $baris[] = array_combine(self::KOLOM, array_slice($data, 0, count(self::KOLOM)));
        }

        fclose($pegangan);

        return $baris;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function alasanBelumLengkap(array $row): ?string
    {
        $kosong = [];

        foreach (['lat_asal', 'lng_asal', 'lat_tujuan', 'lng_tujuan'] as $kolom) {
            if ($row[$kolom] === '' || ! is_numeric(str_replace(',', '.', $row[$kolom]))) {
                $kosong[] = $kolom;
            }
        }

        $jarakGoogle = str_replace(',', '.', $row['jarak_google_maps_km']);
        if ($jarakGoogle === '' || ! is_numeric($jarakGoogle) || (float) $jarakGoogle <= 0) {
            $kosong[] = 'jarak_google_maps_km';
        }

        return $kosong === [] ? null : implode(', ', $kosong);
    }

    /**
     * @param  array<int, array<string, string>>  $baris
     * @param  array<int, array<string, mixed>>  $hasil
     */
    private function tulisUlangCsv(string $berkas, array $baris, array $hasil): void
    {
        $indeks = [];
        foreach ($hasil as $item) {
            $indeks[(string) $item['no']] = $item;
        }

        $pegangan = fopen($berkas, 'w');
        if ($pegangan === false) {
            return;
        }

        fputcsv($pegangan, self::KOLOM);

        foreach ($baris as $row) {
            $item = $indeks[(string) $row['no']] ?? null;

            if ($item !== null) {
                $row['jarak_sistem_km'] = (string) $item['jarak_sistem_km'];
                $row['selisih_km'] = (string) $item['selisih_km'];
                $row['persen_error'] = $item['persen_error'] === null ? '' : (string) $item['persen_error'];
            }

            fputcsv($pegangan, array_values($row));
        }

        fclose($pegangan);
    }

    /**
     * @param  array<int, array<string, mixed>>  $hasil
     * @param  array<int, array<string, mixed>>  $gagal
     */
    private function tulisLaporan(string $berkasLaporan, array $hasil, array $gagal, float $mae, float $mape): void
    {
        $baris = [];
        foreach ($hasil as $item) {
            $baris[] = sprintf(
                '| %s | %s | %s | %s | %s | %s | %s | %s |',
                $item['no'],
                $item['nama_asal'] !== '' ? $item['nama_asal'] : '-',
                $item['nama_tujuan'] !== '' ? $item['nama_tujuan'] : '-',
                bangdeliv_desimal((float) $item['jarak_google_maps_km']),
                bangdeliv_desimal((float) $item['jarak_sistem_km']),
                bangdeliv_desimal((float) $item['selisih_km']),
                $item['persen_error'] === null ? '-' : bangdeliv_desimal((float) $item['persen_error']),
                $item['penyedia_rute']
            );
        }

        $catatanGagal = $gagal === []
            ? 'Tidak ada rute yang gagal dihitung.'
            : "Rute berikut gagal dihitung dan TIDAK diikutkan dalam perhitungan MAE/MAPE:\n\n".implode("\n", array_map(
                static fn (array $g): string => sprintf('- Baris %s: %s', $g['no'], $g['pesan']),
                $gagal
            ));

        $isi = <<<MARKDOWN
        # Tabel A2 - Deviasi Jarak Sistem terhadap Pembacaan Aplikasi Google Maps

        Dihasilkan oleh: `php artisan pengujian:deviasi-jarak`

        Jarak sistem diperoleh dari layanan rute yang sama dengan yang dipakai aplikasi
        (`GoogleMapsDistanceMatrixService::resolveRoute`). Jarak acuan dibaca manual dari
        aplikasi Google Maps oleh peneliti.

        ## Tabel A2

        | No | Asal | Tujuan | Jarak Google Maps (km) | Jarak Sistem (km) | Selisih (km) | Persen Error (%) | Sumber Rute |
        |---|---|---|---|---|---|---|---|
        {$this->gabung($baris)}

        ## Metrik

        | Metrik | Nilai |
        |---|---|
        | Jumlah rute dihitung | {$this->jumlah($hasil)} |
        | MAE (Mean Absolute Error) | {$this->angka($mae, 3)} km |
        | MAPE (Mean Absolute Percentage Error) | {$this->angka($mape, 2)} % |

        Rumus yang dipakai:

        - MAE  = (1/n) x jumlah |jarak_sistem - jarak_google_maps|
        - MAPE = (1/n) x jumlah (|jarak_sistem - jarak_google_maps| / jarak_google_maps) x 100%

        ## Catatan Kejujuran

        Kedua sumber jarak berasal dari Google, namun tidak identik: sistem memanggil
        Routes API/Distance Matrix API dengan mode kendaraan roda dua dan preferensi
        rute sadar lalu lintas, sedangkan pembacaan manual pada aplikasi Google Maps
        dapat memilih rute alternatif serta dipengaruhi kondisi lalu lintas pada saat
        pengambilan data. Selisih kecil karena itu wajar terjadi dan tidak dapat
        diklaim sebagai kesalahan sistem.

        {$catatanGagal}
        MARKDOWN;

        @mkdir(dirname($berkasLaporan), 0775, true);
        file_put_contents($berkasLaporan, $isi."\n");
    }

    /**
     * @param  array<int, string>  $baris
     */
    private function gabung(array $baris): string
    {
        return implode("\n", $baris);
    }

    /**
     * @param  array<int, mixed>  $data
     */
    private function jumlah(array $data): int
    {
        return count($data);
    }

    private function angka(float $nilai, int $digit): string
    {
        return bangdeliv_desimal($nilai, $digit);
    }
}
