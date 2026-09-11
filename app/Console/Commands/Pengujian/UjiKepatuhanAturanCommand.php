<?php

namespace App\Console\Commands\Pengujian;

use App\Console\Commands\Pengujian\Support\DeteksiJalurCepat;
use App\Console\Commands\Pengujian\Support\SpesifikasiPrompt;
use App\Services\Chatbot\ChatbotGeminiService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Pengujian kepatuhan keluaran chatbot terhadap spesifikasi prompt.
 *
 * Acuan kebenaran pengujian ini BUKAN pendapat penulis, melainkan butir aturan
 * dan kontrak keluaran pada ChatbotPromptLibrary. Setiap kasus uji pada
 * kasus_aturan.csv dapat ditelusuri kembali ke kalimat aslinya lewat kolom
 * kutipan_aturan.
 *
 * Beberapa keputusan metodologis yang perlu diketahui saat membaca hasilnya:
 *
 * 1. Penilaian dilakukan pada JSON MENTAH dari Gemini, bukan pada payload yang
 *    sudah dinormalisasi. Alasannya, normalizeCommand() memetakan command yang
 *    tidak dikenal menjadi "none" sehingga pelanggaran kontrak keluaran akan
 *    tersembunyi bila dinilai setelah normalisasi. Nilai hasil normalisasi tetap
 *    dicatat pada kolom tersendiri agar terlihat bahwa lapisan normalisasi
 *    menangkap ketidakpatuhan tersebut.
 *
 * 2. Kegagalan penguraian JSON dicatat eksplisit. Pada layanan Kurir dan Antar
 *    Jemput, nilai cadangan berisi intent pesanan yang sah, sehingga tanpa
 *    pencatatan ini sebuah kasus bisa terhitung patuh padahal permintaannya
 *    sebenarnya gagal.
 *
 * 3. Seluruh kasus dijalankan berulang karena generationConfig tidak menyetel
 *    temperature, sehingga keluaran model tidak dijamin sama pada pengulangan.
 *
 *    ATURAN KEPUTUSAN (ditetapkan sebelum eksekusi pertama dijalankan, agar
 *    ambang penilaian tidak dapat dituduh dipilih setelah melihat angka):
 *      - lulus pada SELURUH pengulangan  -> patuh
 *      - lulus pada SEBAGIAN pengulangan -> tidak stabil (kategori tersendiri,
 *        tidak dihitung sebagai patuh maupun tidak patuh)
 *      - tidak lulus sama sekali         -> tidak patuh
 *
 * 4. Model dikunci pada satu nama model untuk seluruh eksekusi supaya hasil
 *    tidak bercampur antarmodel. Bila model terkunci menolak permintaan,
 *    perintah berhenti dan melapor, bukan diam-diam turun ke model cadangan.
 *
 * 5. Kepatuhan dilaporkan dalam DUA angka. Kepatuhan prompt dihitung atas
 *    seluruh kasus dan menjawab "apakah rancangan prompt dipatuhi model".
 *    Kepatuhan berdampak dihitung hanya atas kasus yang benar-benar mencapai
 *    Gemini saat aplikasi berjalan, yaitu yang tidak tersalip jalur cepat
 *    deterministik pada ChatbotController, dan menjawab "apakah sistemnya
 *    bekerja". Satu angka saja akan menyisakan salah satu pertanyaan itu
 *    tanpa jawaban.
 */
class UjiKepatuhanAturanCommand extends Command
{
    protected $signature = 'pengujian:uji-kepatuhan-aturan
                            {--berkas=tools/pengujian/kasus_aturan.csv : Berkas kasus uji}
                            {--keluaran=tools/pengujian/keluaran_aturan : Folder bukti keluaran mentah}
                            {--hasil=tools/pengujian/hasil_kepatuhan_aturan.csv : Berkas CSV hasil mentah}
                            {--laporan=tools/pengujian/HASIL_KEPATUHAN_ATURAN.md : Berkas laporan Markdown}
                            {--model=gemini-3.1-flash-lite : Model Gemini yang dikunci untuk seluruh eksekusi}
                            {--ulangi=3 : Jumlah pengulangan setiap kasus}
                            {--jeda=4000 : Jeda antarpermintaan dalam milidetik}
                            {--hanya= : Batasi pada nomor kasus tertentu, dipisah koma (untuk uji coba)}
                            {--laporan-saja : Susun ulang laporan dari hasil yang sudah ada, tanpa memanggil API}
                            {--paksa : Lewati konfirmasi sebelum memanggil API Gemini}';

    protected $description = 'Menguji kepatuhan keluaran Gemini terhadap butir aturan dan kontrak keluaran ChatbotPromptLibrary';

    private const PENANDA_TIDAK_DIUJI = 'TIDAK DAPAT DIUJI';

    private const SERVICE_TYPE = [
        'Nitip' => 'nitip',
        'Kurir' => 'kurir',
        'Antar Jemput' => 'antar_jemput',
    ];

    private const KOLOM_HASIL = [
        'no', 'instruction_set', 'no_aturan', 'pesan_uji', 'field_diperiksa', 'nilai_diharapkan',
        'nilai_sistem_1', 'nilai_sistem_2', 'nilai_sistem_3',
        'lulus_1', 'lulus_2', 'lulus_3', 'jumlah_lulus', 'status_kepatuhan', 'stabil',
        'json_terurai', 'model_menjawab', 'mentah_intent', 'mentah_command',
        'dinormalisasi_jadi', 'dicegat_jalur_cepat', 'jalur_cepat_label',
        'nilai_di_luar_enumerasi', 'jenis_kesalahan', 'berkas_bukti',
    ];

    private const STATUS_PATUH = 'patuh';

    private const STATUS_TIDAK_PATUH = 'tidak_patuh';

    private const STATUS_TIDAK_STABIL = 'tidak_stabil';

    /**
     * Rekaman respons HTTP mentah untuk permintaan yang sedang berjalan.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $rekaman = [];

    public function handle(ChatbotGeminiService $gemini): int
    {
        $berkas = base_path((string) $this->option('berkas'));

        if (! is_file($berkas)) {
            $this->error('Berkas kasus uji tidak ditemukan: '.$berkas);
            $this->line('Jalankan dulu: php artisan pengujian:buat-kasus-aturan');

            return self::FAILURE;
        }

        $semuaKasus = $this->bacaKasus($berkas);

        if ($this->option('laporan-saja')) {
            return $this->susunLaporanSaja($semuaKasus);
        }

        $kasus = $this->saringKasus($semuaKasus);

        if ($kasus === []) {
            $this->error('Tidak ada kasus yang dapat dieksekusi.');

            return self::FAILURE;
        }

        $model = trim((string) $this->option('model'));
        $ulangi = max(1, (int) $this->option('ulangi'));
        $jeda = max(0, (int) $this->option('jeda'));
        $totalPanggilan = count($kasus) * $ulangi;

        $this->line('');
        $this->info('Rencana eksekusi');
        $this->line('  Kasus dieksekusi     : '.count($kasus));
        $this->line('  Pengulangan per kasus: '.$ulangi);
        $this->line('  Total panggilan API  : '.$totalPanggilan);
        $this->line('  Jeda antarpermintaan : '.$jeda.' ms');
        $this->line('  Perkiraan durasi     : ~'.ceil($totalPanggilan * ($jeda / 1000 + 1.5) / 60).' menit');
        $this->line('  Model dikunci ke     : '.$model);
        $this->line('');

        if (! $this->option('paksa') && ! $this->confirm('Lanjutkan memanggil API Gemini sebanyak '.$totalPanggilan.' kali?', false)) {
            $this->warn('Dibatalkan. Tidak ada panggilan API yang dilakukan.');

            return self::SUCCESS;
        }

        // Kunci daftar model ke satu nama saja supaya tidak terjadi fallback.
        config(['bangdeliv.chatbot.gemini.models' => [$model]]);
        // Pengujian tidak membutuhkan riwayat percakapan tersimpan.
        config(['cache.default' => 'array']);

        $folderBukti = base_path((string) $this->option('keluaran'));

        if (! is_dir($folderBukti)) {
            mkdir($folderBukti, 0777, true);
        }

        $this->pasangPerekamRespons();

        $hasil = [];
        $nomorUrut = 0;

        foreach ($kasus as $item) {
            $nomorUrut++;
            $hasil[] = $this->jalankanKasus($gemini, $item, $ulangi, $jeda, $folderBukti, $model);
            $this->line(sprintf(
                '  [%d/%d] kasus %s (%s aturan %s) -> %s',
                $nomorUrut,
                count($kasus),
                $item['no'],
                $item['instruction_set'],
                $item['no_aturan'],
                mb_strtoupper(str_replace('_', ' ', (string) end($hasil)['status']))
            ));
        }

        $this->tulisHasil($hasil);
        $this->tulisLaporan($semuaKasus, $hasil, $model, $ulangi);
        $this->ringkasanLayar($hasil);

        return self::SUCCESS;
    }

    /**
     * Menjalankan satu kasus sebanyak jumlah pengulangan dan merangkum hasilnya.
     *
     * @param  array<string, string>  $item
     * @return array<string, mixed>
     */
    private function jalankanKasus(
        ChatbotGeminiService $gemini,
        array $item,
        int $ulangi,
        int $jeda,
        string $folderBukti,
        string $model
    ): array {
        $serviceType = self::SERVICE_TYPE[$item['instruction_set']] ?? 'nitip';
        $konteks = $this->uraikanKonteks($item['konteks_json']);
        $nilaiSah = SpesifikasiPrompt::nilaiSah($serviceType);

        $nilaiSistem = [];
        $patuhPer = [];
        $jsonTerurai = [];
        $modelMenjawab = [];
        $mentahIntent = [];
        $mentahCommand = [];
        $dinormalisasi = [];
        $diLuarEnumerasi = [];
        $berkasBukti = [];
        $galat = [];

        for ($ulangan = 1; $ulangan <= $ulangi; $ulangan++) {
            $this->rekaman = [];
            $payloadNormal = null;
            $pesanGalat = null;

            try {
                $balasan = $serviceType === 'nitip'
                    ? $gemini->parseFoodOrder($item['pesan_uji'], $konteks)
                    : $gemini->interpretTransportMessage($serviceType, $item['pesan_uji'], $konteks);

                $payloadNormal = $balasan['payload'];
            } catch (Throwable $exception) {
                $pesanGalat = $exception->getMessage();
            }

            $respons = end($this->rekaman) ?: null;
            $teksMentah = $this->ambilTeksMentah($respons['body'] ?? null);
            $mentah = is_string($teksMentah) ? json_decode($teksMentah, true) : null;
            $terurai = is_array($mentah);

            $periksa = $terurai
                ? $this->periksaKasus($mentah, $item['field_diperiksa'], $item['nilai_diharapkan'])
                : ['lulus' => false, 'nilai' => $pesanGalat !== null ? 'ERROR: '.$pesanGalat : 'JSON GAGAL DIURAI'];

            $intentMentah = $terurai ? (string) ($mentah['intent'] ?? '') : '';
            $commandMentah = $terurai ? (string) ($mentah['command'] ?? '') : '';

            $luar = [];
            if ($terurai && $intentMentah !== '' && ! in_array($intentMentah, $nilaiSah['intent'], true)) {
                $luar[] = 'intent='.$intentMentah;
            }
            if ($terurai && $commandMentah !== '' && ! in_array($commandMentah, $nilaiSah['command'], true)) {
                $luar[] = 'command='.$commandMentah;
            }

            $namaBukti = sprintf('kasus_%03d_ulangan_%d.json', (int) $item['no'], $ulangan);
            file_put_contents(
                $folderBukti.DIRECTORY_SEPARATOR.$namaBukti,
                json_encode([
                    'no_kasus' => $item['no'],
                    'instruction_set' => $item['instruction_set'],
                    'no_aturan' => $item['no_aturan'],
                    'kutipan_aturan' => $item['kutipan_aturan'],
                    'pesan_uji' => $item['pesan_uji'],
                    'konteks_json' => $konteks,
                    'ulangan' => $ulangan,
                    'model_dikunci' => $model,
                    'model_menjawab' => $this->modelDariUrl($respons['url'] ?? null),
                    'status_http' => $respons['status'] ?? null,
                    // Bodi HTTP apa adanya disimpan supaya kegagalan permintaan
                    // (misalnya penolakan kunci API) dapat ditelusuri tanpa
                    // perlu mengulang panggilan.
                    'respons_http_mentah' => $respons['body'] ?? null,
                    'keluaran_mentah_teks' => $teksMentah,
                    'keluaran_mentah_terurai' => $mentah,
                    'json_terurai' => $terurai,
                    'payload_setelah_normalisasi' => $payloadNormal,
                    'galat' => $pesanGalat,
                    'field_diperiksa' => $item['field_diperiksa'],
                    'nilai_diharapkan' => $item['nilai_diharapkan'],
                    'nilai_sistem' => $periksa['nilai'],
                    'patuh' => $periksa['lulus'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            $nilaiSistem[] = $periksa['nilai'];
            $patuhPer[] = $periksa['lulus'] ? 'ya' : 'tidak';
            $jsonTerurai[] = $terurai ? 'ya' : 'tidak';
            $modelMenjawab[] = $this->modelDariUrl($respons['url'] ?? null) ?? '-';
            $mentahIntent[] = $intentMentah;
            $mentahCommand[] = $commandMentah;
            $dinormalisasi[] = is_array($payloadNormal)
                ? ((string) ($payloadNormal['intent'] ?? '-')).'/'.((string) ($payloadNormal['command'] ?? '-'))
                : '-';
            $diLuarEnumerasi[] = implode(' ', $luar);
            $berkasBukti[] = $namaBukti;

            if ($pesanGalat !== null) {
                $galat[] = $pesanGalat;
            }

            if ($jeda > 0 && ! ($ulangan === $ulangi && $item['no'] === '')) {
                usleep($jeda * 1000);
            }
        }

        $stabil = count(array_unique($nilaiSistem)) === 1;
        $jumlahLulus = count(array_filter($patuhPer, static fn (string $nilai): bool => $nilai === 'ya'));

        // Aturan keputusan tiga arah, ditetapkan sebelum eksekusi pertama.
        $status = match (true) {
            $jumlahLulus === $ulangi => self::STATUS_PATUH,
            $jumlahLulus === 0 => self::STATUS_TIDAK_PATUH,
            default => self::STATUS_TIDAK_STABIL,
        };

        $label = DeteksiJalurCepat::periksa($serviceType, $item['pesan_uji']);

        return [
            'no' => $item['no'],
            'instruction_set' => $item['instruction_set'],
            'no_aturan' => $item['no_aturan'],
            'kutipan_aturan' => $item['kutipan_aturan'],
            'pesan_uji' => $item['pesan_uji'],
            'field_diperiksa' => $item['field_diperiksa'],
            'nilai_diharapkan' => $item['nilai_diharapkan'],
            'nilai_sistem' => $nilaiSistem,
            'patuh_per_ulangan' => $patuhPer,
            'jumlah_lulus' => $jumlahLulus.'/'.$ulangi,
            'status' => $status,
            'stabil' => $stabil ? 'ya' : 'tidak',
            'json_terurai' => in_array('tidak', $jsonTerurai, true) ? 'tidak' : 'ya',
            'model_menjawab' => implode('/', array_unique($modelMenjawab)),
            'mentah_intent' => implode('/', array_unique($mentahIntent)),
            'mentah_command' => implode('/', array_unique($mentahCommand)),
            'dinormalisasi_jadi' => implode('/', array_unique($dinormalisasi)),
            'dicegat_jalur_cepat' => $item['dicegat_jalur_cepat'],
            'jalur_cepat_label' => $label ?? '-',
            'nilai_di_luar_enumerasi' => trim(implode(' ', array_unique(array_filter($diLuarEnumerasi)))),
            'jenis_kesalahan' => $this->jenisKesalahan(
                $status,
                in_array('tidak', $jsonTerurai, true),
                $galat !== [],
                trim(implode(' ', array_filter($diLuarEnumerasi))) !== '',
                $item['no_aturan'] === 'KELUARAN'
            ),
            'berkas_bukti' => implode(';', $berkasBukti),
        ];
    }

    /**
     * Penggolongan penyebab kegagalan yang dapat ditentukan secara objektif
     * dari bukti eksekusi. Penyebab yang membutuhkan penafsiran (misalnya
     * aturan bertabrakan atau terlalu jauh dari contoh few-shot) sengaja tidak
     * ditebak oleh program dan ditandai "perlu_analisis" untuk ditelaah manual
     * berdasarkan berkas bukti.
     */
    private function jenisKesalahan(
        string $status,
        bool $adaJsonGagal,
        bool $adaGalat,
        bool $adaDiLuarEnumerasi,
        bool $dariKontrakKeluaran
    ): string {
        if ($status === self::STATUS_PATUH) {
            return '-';
        }

        if ($adaGalat) {
            return 'error_api';
        }

        if ($adaJsonGagal) {
            return 'json_gagal_diurai';
        }

        if ($status === self::STATUS_TIDAK_STABIL) {
            return 'hasil_tidak_stabil';
        }

        if ($adaDiLuarEnumerasi) {
            return 'nilai_di_luar_enumerasi';
        }

        if ($dariKontrakKeluaran) {
            return 'perintah_tanpa_aturan_pendamping';
        }

        return 'perlu_analisis';
    }

    /**
     * Memeriksa seluruh pasangan field dan nilai harapan pada satu kasus.
     *
     * @param  array<string, mixed>  $mentah
     * @return array{lulus: bool, nilai: string}
     */
    private function periksaKasus(array $mentah, string $fields, string $harapanGabungan): array
    {
        $daftarField = array_map('trim', explode(';', $fields));
        $daftarHarapan = array_map('trim', explode(';', $harapanGabungan));

        if (count($daftarField) !== count($daftarHarapan)) {
            return ['lulus' => false, 'nilai' => 'DEFINISI KASUS TIDAK SEIMBANG'];
        }

        $lulus = true;
        $terbaca = [];

        foreach ($daftarField as $indeks => $field) {
            $harapan = $daftarHarapan[$indeks];
            $nilai = $this->ambilNilai($mentah, $field, $harapan === 'kosong');
            $terbaca[] = $this->sebagaiTeks($nilai);

            if (! $this->bandingkan($nilai, $harapan)) {
                $lulus = false;
            }
        }

        return ['lulus' => $lulus, 'nilai' => implode(';', $terbaca)];
    }

    /**
     * Membaca nilai sebuah field dari JSON mentah.
     *
     * Penulisan field yang didukung:
     *   items.jumlah  -> jumlah elemen larik items
     *   items.0.name  -> elemen larik ke-0, kunci name
     *   merchant|resto-> alternatif field yang aturannya menyebut keduanya setara
     *
     * Untuk alternatif, nilai pertama yang terisi yang dipakai; khusus harapan
     * "kosong" seluruh alternatif harus kosong sehingga yang dikembalikan
     * adalah alternatif pertama yang terisi (bila ada), agar kegagalan terlihat.
     *
     * @param  array<string, mixed>  $mentah
     */
    private function ambilNilai(array $mentah, string $field, bool $harusSemuaKosong = false): mixed
    {
        $alternatif = array_map('trim', explode('|', $field));
        $pertama = null;

        foreach ($alternatif as $jalur) {
            $nilai = str_ends_with($jalur, '.jumlah')
                ? $this->hitungElemen($mentah, substr($jalur, 0, -strlen('.jumlah')))
                : data_get($mentah, $jalur);

            if ($pertama === null) {
                $pertama = $nilai;
            }

            $kosong = $nilai === null || (is_string($nilai) && trim($nilai) === '');

            if (! $kosong) {
                return $nilai;
            }
        }

        return $harusSemuaKosong ? null : $pertama;
    }

    /**
     * @param  array<string, mixed>  $mentah
     */
    private function hitungElemen(array $mentah, string $jalur): int
    {
        $nilai = data_get($mentah, $jalur);

        return is_array($nilai) ? count($nilai) : 0;
    }

    /**
     * Pembandingan nilai. Perbandingan teks tidak peka huruf besar-kecil dan
     * mengabaikan spasi berlebih. Bentuk longgar (berisi/tanpa) dipakai hanya
     * bila aturan memang menuntut demikian, dan dinyatakan terbuka di laporan.
     */
    private function bandingkan(mixed $nilai, string $harapan): bool
    {
        if ($harapan === 'kosong') {
            return $nilai === null
                || (is_string($nilai) && trim($nilai) === '')
                || (is_array($nilai) && $nilai === []);
        }

        $teks = $this->normalkan($this->sebagaiTeks($nilai));

        if (str_starts_with($harapan, 'berisi:')) {
            return str_contains($teks, $this->normalkan(substr($harapan, 7)));
        }

        if (str_starts_with($harapan, 'tanpa:')) {
            $kata = preg_quote($this->normalkan(substr($harapan, 6)), '/');

            return preg_match('/\b'.$kata.'\b/u', $teks) !== 1;
        }

        if (str_starts_with($harapan, 'bukan:')) {
            return $teks !== $this->normalkan(substr($harapan, 6));
        }

        return $teks === $this->normalkan($harapan);
    }

    private function normalkan(string $teks): string
    {
        $teks = (string) preg_replace('/\s+/u', ' ', $teks);

        return trim(mb_strtolower($teks));
    }

    private function sebagaiTeks(mixed $nilai): string
    {
        if ($nilai === null) {
            return '(kosong)';
        }

        if (is_bool($nilai)) {
            return $nilai ? 'true' : 'false';
        }

        if (is_array($nilai)) {
            return (string) json_encode($nilai, JSON_UNESCAPED_UNICODE);
        }

        return (string) $nilai;
    }

    /**
     * Merekam seluruh respons HTTP klien agar keluaran mentah Gemini dapat
     * disimpan sebagai bukti tanpa mengubah kode produksi.
     */
    private function pasangPerekamRespons(): void
    {
        Event::listen(ResponseReceived::class, function (ResponseReceived $peristiwa): void {
            $this->rekaman[] = [
                'url' => $peristiwa->request->url(),
                'status' => $peristiwa->response->status(),
                'body' => $peristiwa->response->body(),
            ];
        });
    }

    private function ambilTeksMentah(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $terurai = json_decode($body, true);

        if (! is_array($terurai)) {
            return null;
        }

        $teks = data_get($terurai, 'candidates.0.content.parts.0.text');

        return is_string($teks) ? $teks : null;
    }

    private function modelDariUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return preg_match('#/models/([^:]+):#', $url, $cocok) === 1 ? $cocok[1] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function uraikanKonteks(string $konteks): ?array
    {
        if (trim($konteks) === '') {
            return null;
        }

        $terurai = json_decode($konteks, true);

        return is_array($terurai) ? $terurai : null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function bacaKasus(string $berkas): array
    {
        $pointer = fopen($berkas, 'r');
        $judul = fgetcsv($pointer);
        $baris = [];

        while (($data = fgetcsv($pointer)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }

            $baris[] = array_combine($judul, array_pad($data, count($judul), ''));
        }

        fclose($pointer);

        return $baris;
    }

    /**
     * @param  array<int, array<string, string>>  $semua
     * @return array<int, array<string, string>>
     */
    private function saringKasus(array $semua): array
    {
        $hanya = trim((string) $this->option('hanya'));
        $nomor = $hanya === '' ? null : array_map('trim', explode(',', $hanya));

        return array_values(array_filter($semua, static function (array $row) use ($nomor): bool {
            if ($row['nilai_diharapkan'] === self::PENANDA_TIDAK_DIUJI) {
                return false;
            }

            return $nomor === null || in_array($row['no'], $nomor, true);
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $hasil
     */
    private function tulisHasil(array $hasil): void
    {
        $berkas = base_path((string) $this->option('hasil'));
        $pointer = fopen($berkas, 'w');

        fputcsv($pointer, self::KOLOM_HASIL);

        foreach ($hasil as $row) {
            fputcsv($pointer, [
                $row['no'],
                $row['instruction_set'],
                $row['no_aturan'],
                $row['pesan_uji'],
                $row['field_diperiksa'],
                $row['nilai_diharapkan'],
                $row['nilai_sistem'][0] ?? '',
                $row['nilai_sistem'][1] ?? '',
                $row['nilai_sistem'][2] ?? '',
                $row['patuh_per_ulangan'][0] ?? '',
                $row['patuh_per_ulangan'][1] ?? '',
                $row['patuh_per_ulangan'][2] ?? '',
                $row['jumlah_lulus'],
                $row['status'],
                $row['stabil'],
                $row['json_terurai'],
                $row['model_menjawab'],
                $row['mentah_intent'],
                $row['mentah_command'],
                $row['dinormalisasi_jadi'],
                $row['dicegat_jalur_cepat'],
                $row['jalur_cepat_label'],
                $row['nilai_di_luar_enumerasi'],
                $row['jenis_kesalahan'],
                $row['berkas_bukti'],
            ]);
        }

        fclose($pointer);

        $this->info('Hasil mentah ditulis: '.$berkas);
    }

    /**
     * @param  array<int, array<string, string>>  $semuaKasus
     * @param  array<int, array<string, mixed>>  $hasil
     */
    private function tulisLaporan(array $semuaKasus, array $hasil, string $model, int $ulangi): void
    {
        $berkas = base_path((string) $this->option('laporan'));
        $baris = [];

        $baris[] = '# Hasil Pengujian Kepatuhan Keluaran Chatbot terhadap Spesifikasi Prompt';
        $baris[] = '';
        $baris[] = 'Acuan kebenaran pengujian ini adalah butir aturan dan kontrak keluaran pada';
        $baris[] = '`app/Services/Chatbot/ChatbotPromptLibrary.php`. Seluruh angka pada dokumen ini';
        $baris[] = 'dihasilkan dari eksekusi nyata terhadap API Gemini.';
        $baris[] = '';
        $baris[] = '- Model yang dikunci: `'.$model.'`';
        $baris[] = '- Pengulangan tiap kasus: '.$ulangi.' kali';
        $baris[] = '- Jumlah kasus dieksekusi: '.count($hasil);
        $baris[] = '- Bukti keluaran mentah: `'.(string) $this->option('keluaran').'/`';
        $baris[] = '';

        $baris = array_merge($baris, $this->tabelRingkasanUtama($hasil));
        $baris = array_merge($baris, $this->tabelKepatuhanPerSet($semuaKasus, $hasil));
        $baris = array_merge($baris, $this->tabelRincianKasus($hasil));
        $baris = array_merge($baris, $this->tabelJenisKesalahan($hasil));
        $baris = array_merge($baris, $this->tabelTidakDapatDiuji($semuaKasus));
        $baris = array_merge($baris, $this->tabelJalurCepat($hasil));
        $baris = array_merge($baris, $this->tabelKestabilan($hasil));
        $baris = array_merge($baris, $this->bagianMetode($model, $ulangi));

        file_put_contents($berkas, implode("\n", $baris)."\n");

        $this->info('Laporan ditulis: '.$berkas);
    }

    /**
     * Ringkasan utama: dua angka kepatuhan yang disajikan berdampingan.
     *
     * @param  array<int, array<string, mixed>>  $hasil
     * @return array<int, string>
     */
    private function tabelRingkasanUtama(array $hasil): array
    {
        $berdampak = array_values(array_filter(
            $hasil,
            static fn (array $row): bool => $row['dicegat_jalur_cepat'] !== 'ya'
        ));

        $baris = ['## Tabel 1. Ringkasan Kepatuhan', ''];
        $baris[] = '| Angka | Cakupan | Jumlah Kasus | Patuh | Tidak Stabil | Tidak Patuh | Persentase Patuh |';
        $baris[] = '|---|---|---|---|---|---|---|';
        $baris[] = $this->barisRingkasan('Kepatuhan prompt', 'seluruh kasus uji', $hasil);
        $baris[] = $this->barisRingkasan('Kepatuhan berdampak', 'kasus yang benar-benar mencapai Gemini di produksi', $berdampak);
        $baris[] = '';
        $baris[] = 'Kepatuhan prompt menjawab pertanyaan "apakah rancangan prompt dipatuhi model",';
        $baris[] = 'sedangkan kepatuhan berdampak menjawab "apakah sistemnya bekerja", karena hanya';
        $baris[] = 'menghitung kasus yang tidak tersalip jalur cepat deterministik pada';
        $baris[] = '`ChatbotController` sehingga butir aturannya benar-benar dikerjakan model saat';
        $baris[] = 'aplikasi berjalan.';
        $baris[] = '';
        $baris[] = 'Aturan keputusan ditetapkan sebelum eksekusi pertama dijalankan: sebuah kasus';
        $baris[] = 'dinyatakan patuh hanya bila lulus pada SELURUH pengulangan; lulus pada sebagian';
        $baris[] = 'pengulangan digolongkan sebagai tidak stabil, yaitu kategori tersendiri yang tidak';
        $baris[] = 'dihitung sebagai patuh maupun tidak patuh.';
        $baris[] = '';

        return $baris;
    }

    /**
     * @param  array<int, array<string, mixed>>  $kumpulan
     */
    private function barisRingkasan(string $nama, string $cakupan, array $kumpulan): string
    {
        $jumlah = count($kumpulan);
        $patuh = $this->hitungStatus($kumpulan, self::STATUS_PATUH);
        $tidakStabil = $this->hitungStatus($kumpulan, self::STATUS_TIDAK_STABIL);
        $tidakPatuh = $this->hitungStatus($kumpulan, self::STATUS_TIDAK_PATUH);
        $persen = $jumlah > 0 ? number_format($patuh / $jumlah * 100, 2, ',', '.').'%' : '-';

        return '| **'.$nama.'** | '.$cakupan.' | '.$jumlah.' | '.$patuh.' | '.$tidakStabil
            .' | '.$tidakPatuh.' | **'.$persen.'** |';
    }

    /**
     * @param  array<int, array<string, mixed>>  $kumpulan
     */
    private function hitungStatus(array $kumpulan, string $status): int
    {
        return count(array_filter($kumpulan, static fn (array $row): bool => $row['status'] === $status));
    }

    /**
     * @param  array<int, array<string, string>>  $semuaKasus
     * @param  array<int, array<string, mixed>>  $hasil
     * @return array<int, string>
     */
    private function tabelKepatuhanPerSet(array $semuaKasus, array $hasil): array
    {
        $baris = ['## Tabel 2. Kepatuhan per Instruction Set', ''];
        $baris[] = '| Instruction Set | Jumlah Aturan | Dapat Diuji | Kasus Uji | Kasus Patuh | Kasus Tidak Stabil | Aturan Patuh | Persentase Aturan Patuh |';
        $baris[] = '|---|---|---|---|---|---|---|---|';

        $totalAturan = 0;
        $totalDiuji = 0;
        $totalKasus = 0;
        $totalKasusPatuh = 0;
        $totalKasusTidakStabil = 0;
        $totalAturanPatuh = 0;

        foreach (self::SERVICE_TYPE as $nama => $serviceType) {
            $jumlahAturan = count(SpesifikasiPrompt::butirAturan($serviceType));

            $kasusSet = array_values(array_filter(
                $hasil,
                static fn (array $row): bool => $row['instruction_set'] === $nama && $row['no_aturan'] !== 'KELUARAN'
            ));

            $aturanDiuji = array_unique(array_column($kasusSet, 'no_aturan'));
            $aturanPatuh = [];

            foreach ($aturanDiuji as $noAturan) {
                $kasusAturan = array_filter(
                    $kasusSet,
                    static fn (array $row): bool => $row['no_aturan'] === $noAturan
                );

                // Aturan hanya dihitung patuh bila SELURUH kasus miliknya berstatus patuh.
                $status = array_column($kasusAturan, 'status');

                if (! in_array(self::STATUS_TIDAK_PATUH, $status, true)
                    && ! in_array(self::STATUS_TIDAK_STABIL, $status, true)) {
                    $aturanPatuh[] = $noAturan;
                }
            }

            $kasusPatuh = $this->hitungStatus($kasusSet, self::STATUS_PATUH);
            $kasusTidakStabil = $this->hitungStatus($kasusSet, self::STATUS_TIDAK_STABIL);
            $persen = count($aturanDiuji) > 0
                ? number_format(count($aturanPatuh) / count($aturanDiuji) * 100, 2, ',', '.').'%'
                : '-';

            $baris[] = '| '.$nama.' | '.$jumlahAturan.' | '.count($aturanDiuji).' | '.count($kasusSet)
                .' | '.$kasusPatuh.' | '.$kasusTidakStabil.' | '.count($aturanPatuh).' | '.$persen.' |';

            $totalAturan += $jumlahAturan;
            $totalDiuji += count($aturanDiuji);
            $totalKasus += count($kasusSet);
            $totalKasusPatuh += $kasusPatuh;
            $totalKasusTidakStabil += $kasusTidakStabil;
            $totalAturanPatuh += count($aturanPatuh);
        }

        $kasusKontrak = array_values(array_filter(
            $hasil,
            static fn (array $row): bool => $row['no_aturan'] === 'KELUARAN'
        ));

        $baris[] = '| Blok KELUARAN (3 set) | - | - | '.count($kasusKontrak)
            .' | '.$this->hitungStatus($kasusKontrak, self::STATUS_PATUH)
            .' | '.$this->hitungStatus($kasusKontrak, self::STATUS_TIDAK_STABIL).' | - | - |';

        $persenTotal = $totalDiuji > 0
            ? number_format($totalAturanPatuh / $totalDiuji * 100, 2, ',', '.').'%'
            : '-';

        $baris[] = '| **Total** | **'.$totalAturan.'** | **'.$totalDiuji.'** | **'.($totalKasus + count($kasusKontrak))
            .'** | **'.($totalKasusPatuh + $this->hitungStatus($kasusKontrak, self::STATUS_PATUH))
            .'** | **'.($totalKasusTidakStabil + $this->hitungStatus($kasusKontrak, self::STATUS_TIDAK_STABIL))
            .'** | **'.$totalAturanPatuh.'** | **'.$persenTotal.'** |';
        $baris[] = '';
        $baris[] = 'Sebuah aturan dihitung patuh hanya bila seluruh kasus uji miliknya berstatus';
        $baris[] = 'patuh, yaitu lulus pada seluruh pengulangan.';
        $baris[] = '';

        return $baris;
    }

    /**
     * @param  array<int, array<string, mixed>>  $hasil
     * @return array<int, string>
     */
    private function tabelRincianKasus(array $hasil): array
    {
        $baris = ['## Tabel 3. Rincian Kasus Uji', ''];
        $baris[] = '| No | Set | Aturan | Kutipan Aturan (dipersingkat) | Pesan Uji | Diharapkan | Hasil Sistem | JSON Terurai | Lulus | Dicegat Jalur Cepat | Status |';
        $baris[] = '|---|---|---|---|---|---|---|---|---|---|---|';

        foreach ($hasil as $row) {
            $baris[] = '| '.$row['no']
                .' | '.$row['instruction_set']
                .' | '.$row['no_aturan']
                .' | '.$this->persingkat((string) $row['kutipan_aturan'], 70)
                .' | '.$this->amanTabel((string) $row['pesan_uji'])
                .' | '.$this->amanTabel((string) $row['nilai_diharapkan'])
                .' | '.$this->amanTabel((string) ($row['nilai_sistem'][0] ?? '-'))
                .' | '.$row['json_terurai']
                .' | '.$row['jumlah_lulus']
                .' | '.$row['dicegat_jalur_cepat']
                .' | '.str_replace('_', ' ', (string) $row['status']).' |';
        }

        $baris[] = '';
        $baris[] = 'Kolom Hasil Sistem menampilkan nilai pada pengulangan pertama. Nilai seluruh';
        $baris[] = 'pengulangan tersedia pada `'.(string) $this->option('hasil').'`.';
        $baris[] = '';

        return $baris;
    }

    /**
     * @param  array<int, array<string, mixed>>  $hasil
     * @return array<int, string>
     */
    private function tabelJenisKesalahan(array $hasil): array
    {
        $gagal = array_filter($hasil, static fn (array $row): bool => $row['status'] !== self::STATUS_PATUH);
        $hitung = [];

        foreach ($gagal as $row) {
            $jenis = (string) $row['jenis_kesalahan'];
            $hitung[$jenis] = ($hitung[$jenis] ?? 0) + 1;
        }

        arsort($hitung);

        $baris = ['## Tabel 4. Jenis Penyebab Kegagalan', ''];
        $baris[] = 'Mencakup seluruh kasus yang tidak berstatus patuh, yaitu kasus tidak patuh dan';
        $baris[] = 'kasus tidak stabil.';
        $baris[] = '';
        $baris[] = '| Jenis Penyebab Kegagalan | Jumlah | Persentase terhadap Kasus Tidak Patuh |';
        $baris[] = '|---|---|---|';

        if ($hitung === []) {
            $baris[] = '| (tidak ada kasus gagal) | 0 | - |';
        }

        foreach ($hitung as $jenis => $jumlah) {
            $baris[] = '| '.$jenis.' | '.$jumlah.' | '
                .number_format($jumlah / max(1, count($gagal)) * 100, 2, ',', '.').'% |';
        }

        $baris[] = '';
        $baris[] = 'Penggolongan di atas ditentukan secara objektif dari bukti eksekusi. Kasus';
        $baris[] = 'bertanda `perlu_analisis` adalah kasus yang keluarannya berupa JSON sah tetapi';
        $baris[] = 'nilainya berbeda dari spesifikasi; penyebabnya ditelaah manual dari berkas bukti';
        $baris[] = 'dan dibahas pada laporan, bukan ditebak oleh program.';
        $baris[] = '';

        return $baris;
    }

    /**
     * @param  array<int, array<string, string>>  $semuaKasus
     * @return array<int, string>
     */
    private function tabelTidakDapatDiuji(array $semuaKasus): array
    {
        $baris = ['## Tabel 5. Aturan yang Tidak Dapat Diuji', ''];
        $baris[] = '| Instruction Set | No Aturan | Kutipan Aturan | Alasan |';
        $baris[] = '|---|---|---|---|';

        foreach ($semuaKasus as $row) {
            if ($row['nilai_diharapkan'] !== self::PENANDA_TIDAK_DIUJI) {
                continue;
            }

            $baris[] = '| '.$row['instruction_set']
                .' | '.$row['no_aturan']
                .' | '.$this->persingkat($row['kutipan_aturan'], 90)
                .' | '.$this->amanTabel($row['alasan_penurunan']).' |';
        }

        $baris[] = '';

        return $baris;
    }

    /**
     * @param  array<int, array<string, mixed>>  $hasil
     * @return array<int, string>
     */
    private function tabelJalurCepat(array $hasil): array
    {
        $baris = ['## Tabel 6. Aturan yang Tersalip Jalur Cepat Deterministik', ''];
        $baris[] = 'ChatbotController memeriksa sebagian pesan secara deterministik sebelum memanggil';
        $baris[] = 'Gemini. Untuk pesan tersebut, butir aturan yang bersangkutan tidak pernah benar-benar';
        $baris[] = 'dikerjakan model saat aplikasi berjalan, sehingga kepatuhannya bersifat cadangan';
        $baris[] = 'belaka. Pemeriksaan ini dilakukan lokal, tanpa panggilan API.';
        $baris[] = '';
        $baris[] = '| No | Set | Aturan | Pesan Uji | Dicegat Jalur Cepat | Jalur yang Mencegat | Status di Gemini |';
        $baris[] = '|---|---|---|---|---|---|---|';

        foreach ($hasil as $row) {
            if ($row['dicegat_jalur_cepat'] !== 'ya') {
                continue;
            }

            $baris[] = '| '.$row['no']
                .' | '.$row['instruction_set']
                .' | '.$row['no_aturan']
                .' | '.$this->amanTabel((string) $row['pesan_uji'])
                .' | ya | `'.$row['jalur_cepat_label'].'`'
                .' | '.str_replace('_', ' ', (string) $row['status']).' |';
        }

        $jumlahDicegat = count(array_filter($hasil, static fn (array $row): bool => $row['dicegat_jalur_cepat'] === 'ya'));

        $baris[] = '';
        $baris[] = 'Jumlah kasus yang tersalip jalur cepat: '.$jumlahDicegat.' dari '.count($hasil).' kasus ('
            .number_format($jumlahDicegat / max(1, count($hasil)) * 100, 2, ',', '.').'%).';
        $baris[] = '';

        return $baris;
    }

    /**
     * @param  array<int, array<string, mixed>>  $hasil
     * @return array<int, string>
     */
    private function tabelKestabilan(array $hasil): array
    {
        $tidakStabil = array_filter($hasil, static fn (array $row): bool => $row['stabil'] === 'tidak');

        $baris = ['## Tabel 7. Kasus yang Hasilnya Berubah Antarpengulangan', ''];
        $baris[] = '| No | Set | Aturan | Pesan Uji | Ulangan 1 | Ulangan 2 | Ulangan 3 | Lulus | Status |';
        $baris[] = '|---|---|---|---|---|---|---|---|---|';

        if ($tidakStabil === []) {
            $baris[] = '| - | - | - | (tidak ada) | - | - | - | - | - |';
        }

        foreach ($tidakStabil as $row) {
            $baris[] = '| '.$row['no']
                .' | '.$row['instruction_set']
                .' | '.$row['no_aturan']
                .' | '.$this->amanTabel((string) $row['pesan_uji'])
                .' | '.$this->amanTabel((string) ($row['nilai_sistem'][0] ?? '-'))
                .' | '.$this->amanTabel((string) ($row['nilai_sistem'][1] ?? '-'))
                .' | '.$this->amanTabel((string) ($row['nilai_sistem'][2] ?? '-'))
                .' | '.$row['jumlah_lulus']
                .' | '.str_replace('_', ' ', (string) $row['status']).' |';
        }

        $stabil = count($hasil) - count($tidakStabil);

        $baris[] = '';
        $baris[] = 'Kasus dengan nilai keluaran identik pada seluruh pengulangan: '.$stabil.' dari '
            .count($hasil).' ('.number_format($stabil / max(1, count($hasil)) * 100, 2, ',', '.').'%).';
        $baris[] = '';
        $baris[] = 'Tabel ini memakai ukuran yang lebih ketat daripada status kepatuhan: sebuah kasus';
        $baris[] = 'dapat berstatus patuh namun nilainya tetap berbeda antarpengulangan, misalnya pada';
        $baris[] = 'pemeriksaan berbentuk `berisi:` yang dapat dipenuhi oleh lebih dari satu rumusan';
        $baris[] = 'jawaban. Kasus berstatus tidak stabil, yaitu yang lulus hanya pada sebagian';
        $baris[] = 'pengulangan, berjumlah '.$this->hitungStatus($hasil, self::STATUS_TIDAK_STABIL).'.';
        $baris[] = '';

        return $baris;
    }

    /**
     * @return array<int, string>
     */
    private function bagianMetode(string $model, int $ulangi): array
    {
        return [
            '## Catatan Metode',
            '',
            '1. **Penilaian pada keluaran mentah.** Kepatuhan dinilai terhadap JSON yang',
            '   dikembalikan Gemini apa adanya, bukan terhadap payload setelah normalisasi.',
            '   `ChatbotGeminiService::normalizeCommand()` memetakan command yang tidak dikenal',
            '   menjadi `none`, sehingga pelanggaran kontrak keluaran tidak akan terlihat bila',
            '   dinilai setelah normalisasi. Nilai setelah normalisasi tetap dicatat pada kolom',
            '   `dinormalisasi_jadi`. Dengan begitu tabel hasil menunjukkan dua hal sekaligus:',
            '   keluaran model dapat menyimpang dari kontrak prompt, tetapi lapisan normalisasi',
            '   menangkap penyimpangan itu sehingga aplikasi tetap menerima nilai yang sah.',
            '',
            '2. **Pencocokan nilai.** Perbandingan teks tidak peka huruf besar-kecil dan',
            '   mengabaikan spasi berlebih. Selain kesamaan persis, dipakai empat bentuk yang',
            '   lebih longgar dan dinyatakan terbuka di sini: `kosong` (field harus null atau',
            '   string kosong), `berisi:x` (memuat potongan teks x), `tanpa:x` (tidak memuat kata',
            '   x, dicocokkan per kata utuh), dan `bukan:x` (tidak bernilai x). Bentuk longgar',
            '   hanya dipakai bila aturan yang bersangkutan memang menuntut demikian, misalnya',
            '   aturan yang melarang nama tempat ikut masuk ke deskripsi paket.',
            '',
            '3. **Penguncian model.** Seluruh eksekusi memakai `'.$model.'`, yaitu model pertama',
            '   pada rantai `GEMINI_MODELS`. Penguncian dilakukan agar hasil tidak bercampur',
            '   antarmodel. Bila model terkunci menolak permintaan, perintah berhenti dan',
            '   melapor, bukan turun diam-diam ke model cadangan.',
            '',
            '4. **Pengulangan dan aturan keputusan.** Setiap kasus dijalankan '.$ulangi.' kali karena',
            '   `generationConfig` pada `ChatbotGeminiService` hanya menyetel `responseMimeType` dan',
            '   `responseSchema` tanpa menyetel `temperature`, sehingga keluaran tidak dijamin sama',
            '   pada pengulangan. Aturan keputusan berikut ditetapkan di dalam kode SEBELUM',
            '   eksekusi pertama dijalankan, sehingga ambang penilaian tidak dipilih setelah angka',
            '   diketahui:',
            '',
            '   | Jumlah pengulangan yang lulus | Status |',
            '   |---|---|',
            '   | '.$ulangi.' dari '.$ulangi.' | patuh |',
            '   | sebagian | tidak stabil |',
            '   | 0 dari '.$ulangi.' | tidak patuh |',
            '',
            '   Kasus tidak stabil dilaporkan sebagai kategori tersendiri dan tidak digabungkan ke',
            '   dalam angka patuh maupun tidak patuh. Sebuah butir aturan dihitung patuh hanya bila',
            '   seluruh kasus uji miliknya berstatus patuh.',
            '',
            '5. **Batas keberlakuan.** Pengujian ini mengukur kepatuhan keluaran model terhadap',
            '   spesifikasi prompt, BUKAN ketangguhan sistem terhadap bahasa pengguna sungguhan.',
            '   Pesan uji sengaja dibuat sesingkat mungkin dan hanya menguji satu butir aturan',
            '   agar kegagalan dapat diatribusikan ke satu aturan tertentu. Angka pada dokumen ini',
            '   tidak dapat ditafsirkan sebagai akurasi chatbot pada percakapan nyata.',
            '',
            '6. **Perbedaan dengan alur aplikasi.** Harness memanggil `ChatbotGeminiService`',
            '   langsung dengan instruction set layanan yang sesuai, yaitu jalur yang sama dengan',
            '   `ChatbotController::processChat` ketika jalur cepat deterministik tidak aktif.',
            '   Kasus yang tersalip jalur cepat dirinci pada Tabel 5.',
            '',
            '## Perintah untuk Menjalankan Ulang',
            '',
            '```bash',
            '# 1. Menyusun ulang berkas kasus uji dari teks prompt',
            'php artisan pengujian:buat-kasus-aturan --paksa',
            '',
            '# 2. Menjalankan pengujian (memanggil API Gemini)',
            'php artisan pengujian:uji-kepatuhan-aturan --model='.$model.' --ulangi='.$ulangi,
            '',
            '# 3. Menyusun ulang laporan dari hasil yang sudah ada, tanpa memanggil API',
            'php artisan pengujian:uji-kepatuhan-aturan --laporan-saja',
            '```',
            '',
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $semuaKasus
     */
    private function susunLaporanSaja(array $semuaKasus): int
    {
        $berkasHasil = base_path((string) $this->option('hasil'));

        if (! is_file($berkasHasil)) {
            $this->error('Berkas hasil belum ada: '.$berkasHasil);

            return self::FAILURE;
        }

        $pointer = fopen($berkasHasil, 'r');
        $judul = fgetcsv($pointer);
        $hasil = [];
        $petaKasus = [];

        foreach ($semuaKasus as $row) {
            $petaKasus[$row['no']] = $row;
        }

        while (($data = fgetcsv($pointer)) !== false) {
            if ($data === [null] || $data === []) {
                continue;
            }

            $row = array_combine($judul, array_pad($data, count($judul), ''));

            $hasil[] = [
                'no' => $row['no'],
                'instruction_set' => $row['instruction_set'],
                'no_aturan' => $row['no_aturan'],
                'kutipan_aturan' => $petaKasus[$row['no']]['kutipan_aturan'] ?? '',
                'pesan_uji' => $row['pesan_uji'],
                'field_diperiksa' => $row['field_diperiksa'],
                'nilai_diharapkan' => $row['nilai_diharapkan'],
                'nilai_sistem' => [$row['nilai_sistem_1'], $row['nilai_sistem_2'], $row['nilai_sistem_3']],
                'patuh_per_ulangan' => [$row['lulus_1'], $row['lulus_2'], $row['lulus_3']],
                'jumlah_lulus' => $row['jumlah_lulus'],
                'status' => $row['status_kepatuhan'],
                'stabil' => $row['stabil'],
                'json_terurai' => $row['json_terurai'],
                'model_menjawab' => $row['model_menjawab'],
                'mentah_intent' => $row['mentah_intent'],
                'mentah_command' => $row['mentah_command'],
                'dinormalisasi_jadi' => $row['dinormalisasi_jadi'],
                'dicegat_jalur_cepat' => $row['dicegat_jalur_cepat'],
                'jalur_cepat_label' => $row['jalur_cepat_label'],
                'nilai_di_luar_enumerasi' => $row['nilai_di_luar_enumerasi'],
                'jenis_kesalahan' => $row['jenis_kesalahan'],
                'berkas_bukti' => $row['berkas_bukti'],
            ];
        }

        fclose($pointer);

        $model = trim((string) $this->option('model'));
        $this->tulisLaporan($semuaKasus, $hasil, $model, max(1, (int) $this->option('ulangi')));
        $this->ringkasanLayar($hasil);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $hasil
     */
    private function ringkasanLayar(array $hasil): void
    {
        $berdampak = array_values(array_filter(
            $hasil,
            static fn (array $row): bool => $row['dicegat_jalur_cepat'] !== 'ya'
        ));

        $patuh = $this->hitungStatus($hasil, self::STATUS_PATUH);
        $gagalUrai = count(array_filter($hasil, static fn (array $row): bool => $row['json_terurai'] === 'tidak'));
        $luar = count(array_filter($hasil, static fn (array $row): bool => trim((string) $row['nilai_di_luar_enumerasi']) !== ''));

        $this->line('');
        $this->info('Ringkasan');
        $this->line('  Kepatuhan prompt    : '.$patuh.' dari '.count($hasil).' kasus patuh');
        $this->line('  Kepatuhan berdampak : '.$this->hitungStatus($berdampak, self::STATUS_PATUH)
            .' dari '.count($berdampak).' kasus patuh (di luar jalur cepat)');
        $this->line('  Tidak stabil        : '.$this->hitungStatus($hasil, self::STATUS_TIDAK_STABIL));
        $this->line('  Tidak patuh         : '.$this->hitungStatus($hasil, self::STATUS_TIDAK_PATUH));
        $this->line('  JSON gagal diurai   : '.$gagalUrai);
        $this->line('  Di luar enumerasi   : '.$luar);
    }

    private function persingkat(string $teks, int $panjang): string
    {
        $teks = $this->amanTabel($teks);

        return mb_strlen($teks) <= $panjang ? $teks : mb_substr($teks, 0, $panjang - 3).'...';
    }

    private function amanTabel(string $teks): string
    {
        return trim(str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $teks));
    }
}
