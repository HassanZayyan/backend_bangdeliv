<?php

namespace App\Console\Commands\Pengujian;

use App\Services\Pricing\DeliveryPricingService;
use Illuminate\Console\Command;

/**
 * Pengujian A3 - Uji Konsistensi Perhitungan Ongkos.
 *
 * Membuktikan klaim "konsisten" pada tujuan penelitian ke-2: untuk masukan
 * jarak yang sama, sistem harus selalu menghasilkan ongkos yang sama persis.
 * Setiap jarak dihitung lima kali berturut-turut lalu kelima hasilnya
 * dibandingkan satu sama lain.
 */
class UjiKonsistensiOngkosCommand extends Command
{
    protected $signature = 'pengujian:konsistensi-ongkos
                            {--pengulangan=5 : Jumlah pengulangan per jarak}
                            {--json= : Simpan hasil mentah ke berkas JSON}';

    protected $description = 'Pengujian A3: menguji konsistensi hasil perhitungan ongkos untuk masukan yang sama';

    public function handle(DeliveryPricingService $pricing): int
    {
        require_once base_path('tools/pengujian/helper_pengujian.php');

        $pengulangan = max(2, (int) $this->option('pengulangan'));
        $kasus = (require base_path('tools/pengujian/kasus_uji.php'))['a3'];

        $this->info('Pengujian A3 - Konsistensi Perhitungan Ongkos');
        $this->line(sprintf('Jumlah jarak diuji : %d', count($kasus)));
        $this->line(sprintf('Pengulangan        : %d kali per jarak', $pengulangan));
        $this->newLine();

        $baris = [];
        $hasilMentah = [];
        $jumlahIdentik = 0;

        foreach ($kasus as $item) {
            $jarakMeter = (int) round($item['jarak_km'] * 1000);

            $ongkos = [];
            $rincian = [];
            for ($i = 0; $i < $pengulangan; $i++) {
                $hasil = $pricing->calculateFromDistanceMeters($jarakMeter);
                $ongkos[] = (float) $hasil['total_fee'];
                $rincian[] = [
                    'billed_km' => $hasil['billed_km'],
                    'rate_per_km' => $hasil['rate_per_km'],
                    'total_fee' => $hasil['total_fee'],
                ];
            }

            // Identik bila seluruh hasil pengulangan bernilai sama persis.
            $identik = count(array_unique($ongkos, SORT_REGULAR)) === 1;
            $jumlahIdentik += $identik ? 1 : 0;

            $baris[] = [
                $item['no'],
                bangdeliv_desimal($item['jarak_km']),
                ...array_map(static fn (float $nilai): string => bangdeliv_rupiah($nilai), $ongkos),
                $identik ? 'Ya' : 'TIDAK',
            ];

            $hasilMentah[] = [
                'no' => $item['no'],
                'jarak_km' => $item['jarak_km'],
                'jarak_meter' => $jarakMeter,
                'hasil' => $rincian,
                'identik' => $identik,
            ];
        }

        $judul = ['No', 'Jarak (km)'];
        for ($i = 1; $i <= $pengulangan; $i++) {
            $judul[] = 'Ke-'.$i.' (Rp)';
        }
        $judul[] = 'Identik';

        $this->table($judul, $baris);

        $total = count($kasus);
        $persen = $total > 0 ? $jumlahIdentik / $total * 100 : 0.0;

        $this->newLine();
        $this->line(sprintf('Hasil identik : %d dari %d jarak (%s%%)', $jumlahIdentik, $total, bangdeliv_desimal($persen)));

        if ($this->option('json')) {
            $tujuan = base_path((string) $this->option('json'));
            @mkdir(dirname($tujuan), 0775, true);
            file_put_contents($tujuan, json_encode($hasilMentah, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('Hasil mentah disimpan ke: '.$tujuan);
        }

        if ($jumlahIdentik !== $total) {
            $this->error('Ditemukan jarak yang hasilnya TIDAK konsisten. Periksa daftar di atas.');

            return self::FAILURE;
        }

        $this->info('Seluruh jarak menghasilkan ongkos yang identik pada setiap pengulangan.');

        return self::SUCCESS;
    }
}
