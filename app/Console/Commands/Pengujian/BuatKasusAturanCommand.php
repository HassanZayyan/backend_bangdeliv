<?php

namespace App\Console\Commands\Pengujian;

use App\Console\Commands\Pengujian\Support\DeteksiJalurCepat;
use App\Console\Commands\Pengujian\Support\SpesifikasiPrompt;
use Illuminate\Console\Command;

/**
 * Pembangkit berkas kasus uji kepatuhan aturan prompt (LANGKAH 2).
 *
 * Perintah ini menurunkan kasus uji DARI teks spesifikasi, yaitu butir-butir
 * blok ATURAN dan blok KELUARAN pada ChatbotPromptLibrary. Kolom
 * kutipan_aturan TIDAK diketik ulang, melainkan dibaca langsung dari string
 * instruksi yang benar-benar dikirim ke Gemini, sehingga penguji dapat
 * menelusuri setiap kasus uji kembali ke kalimat aslinya.
 *
 * Aturan yang tidak dapat diturunkan menjadi nilai yang dapat diperiksa tetap
 * dicatat sebagai baris tersendiri dengan penanda TIDAK DAPAT DIUJI beserta
 * alasannya, bukan dihilangkan atau dipaksakan dengan kriteria buatan sendiri.
 */
class BuatKasusAturanCommand extends Command
{
    protected $signature = 'pengujian:buat-kasus-aturan
                            {--berkas=tools/pengujian/kasus_aturan.csv : Lokasi berkas CSV keluaran}
                            {--paksa : Timpa berkas yang sudah ada tanpa bertanya}';

    protected $description = 'LANGKAH 2: menurunkan kasus uji kepatuhan dari butir aturan dan kontrak keluaran ChatbotPromptLibrary';

    private const KOLOM = [
        'no',
        'instruction_set',
        'no_aturan',
        'kutipan_aturan',
        'pesan_uji',
        'konteks_json',
        'field_diperiksa',
        'nilai_diharapkan',
        'dicegat_jalur_cepat',
        'alasan_penurunan',
    ];

    private const PENANDA_TIDAK_DIUJI = 'TIDAK DAPAT DIUJI';

    /**
     * CONTEXT_JSON tiruan. Bentuknya mengikuti ChatbotDraftStore::context()
     * apa adanya (draft_state.shopping.*) supaya pengujian memakai struktur
     * konteks yang sama dengan yang diterima model saat aplikasi berjalan.
     */
    private const KONTEKS_MERCHANT_AKTIF = '{"service_type":"nitip","draft_state":{"intent":"shopping_order","service_type":"nitip","shopping":{"merchant_name":"Warung Teteh","merchant_count":1,"active_merchant_name":"Warung Teteh","available_menus":[],"item_count":0,"ready_to_confirm":false}}}';

    private const KONTEKS_RIWAYAT_ITEM = '{"service_type":"nitip","recent_turns":[{"role":"user","message":"beliin nasi goreng 1"},{"role":"assistant","message":"Oke, nasi goreng 1 sudah dicatat."}],"draft_state":{"intent":"shopping_order","service_type":"nitip","shopping":{"merchant_name":"Warung Teteh","merchant_count":1,"active_merchant_name":"Warung Teteh","available_menus":[],"item_count":1,"ready_to_confirm":false}}}';

    private const KONTEKS_KATALOG_MENU = '{"service_type":"nitip","draft_state":{"intent":"shopping_order","service_type":"nitip","shopping":{"merchant_name":"Mie Gacoan Salatiga","merchant_count":1,"active_merchant_name":"Mie Gacoan Salatiga","available_menus":["Mie Gacoan Level 1","Mie Gacoan Level 5","Es Teh Manis"],"item_count":0,"ready_to_confirm":false}}}';

    private const NAMA_SET = [
        'nitip' => 'Nitip',
        'kurir' => 'Kurir',
        'antar_jemput' => 'Antar Jemput',
    ];

    public function handle(): int
    {
        $berkas = base_path((string) $this->option('berkas'));

        if (is_file($berkas) && ! $this->option('paksa')) {
            $this->warn('Berkas sudah ada: '.$berkas);
            $this->line('Jalankan ulang dengan --paksa bila ingin menimpanya.');

            return self::FAILURE;
        }

        $aturan = [
            'nitip' => SpesifikasiPrompt::butirAturan('nitip'),
            'kurir' => SpesifikasiPrompt::butirAturan('kurir'),
            'antar_jemput' => SpesifikasiPrompt::butirAturan('antar_jemput'),
        ];

        $kontrak = [
            'nitip' => SpesifikasiPrompt::kontrakKeluaran('nitip'),
            'kurir' => SpesifikasiPrompt::kontrakKeluaran('kurir'),
            'antar_jemput' => SpesifikasiPrompt::kontrakKeluaran('antar_jemput'),
        ];

        $baris = [];
        $no = 0;

        foreach ($this->definisiKasus() as $kasus) {
            [$set, $noAturan, $pesan, $konteks, $field, $harapan, $alasan] = $kasus;

            if ($noAturan === 'KELUARAN') {
                $kutipan = $kontrak[$set];
            } else {
                $daftar = $aturan[$set];
                $indeks = ((int) $noAturan) - 1;

                if (! isset($daftar[$indeks])) {
                    $this->error('Nomor aturan '.$noAturan.' tidak ada pada instruction set '.$set.'.');

                    return self::FAILURE;
                }

                $kutipan = $daftar[$indeks];
            }

            $no++;
            $baris[] = [
                $no,
                self::NAMA_SET[$set],
                $noAturan,
                $kutipan,
                $pesan,
                $konteks,
                $field,
                $harapan,
                $harapan === self::PENANDA_TIDAK_DIUJI ? '-' : $this->labelJalurCepat($set, $pesan),
                $alasan,
            ];
        }

        $this->tulisCsv($berkas, $baris);

        $dapatDiuji = count(array_filter(
            $baris,
            static fn (array $row): bool => $row[7] !== self::PENANDA_TIDAK_DIUJI
        ));

        $dicegat = count(array_filter(
            $baris,
            static fn (array $row): bool => $row[8] === 'ya'
        ));

        $this->info('Berkas kasus uji ditulis: '.$berkas);
        $this->line('Total baris               : '.count($baris));
        $this->line('Kasus dapat dieksekusi    : '.$dapatDiuji);
        $this->line('Ditandai tidak dapat diuji: '.(count($baris) - $dapatDiuji));
        $this->line('Dicegat jalur cepat       : '.$dicegat.' (tidak pernah sampai ke Gemini di aplikasi)');

        return self::SUCCESS;
    }

    /**
     * Menandai apakah pesan uji dicegat jalur cepat deterministik pada
     * ChatbotController sebelum sempat dikirim ke Gemini. Pemeriksaan lokal,
     * tanpa panggilan API.
     */
    private function labelJalurCepat(string $serviceType, string $pesan): string
    {
        return DeteksiJalurCepat::periksa($serviceType, $pesan) !== null ? 'ya' : 'tidak';
    }

    /**
     * Daftar kasus uji: [instruction set, nomor aturan, pesan uji, konteks,
     * field diperiksa, nilai diharapkan, alasan penurunan].
     *
     * Nilai diharapkan memakai penulisan:
     *   teks biasa  -> harus sama persis (tidak peka huruf besar-kecil, spasi berlebih diabaikan)
     *   kosong      -> field harus null atau string kosong
     *   berisi:x    -> field harus memuat potongan teks x
     *   tanpa:x     -> field tidak boleh memuat kata x
     *   bukan:x     -> field tidak boleh bernilai x
     *
     * @return array<int, array{0: string, 1: int|string, 2: string, 3: string, 4: string, 5: string, 6: string}>
     */
    private function definisiKasus(): array
    {
        return array_merge(
            $this->kasusNitip(),
            $this->kasusKurir(),
            $this->kasusAntarJemput(),
            $this->kasusKontrakKeluaran(),
        );
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function kasusNitip(): array
    {
        return [
            ['nitip', 1, 'konfirmasi', '', 'command;intent', 'confirm;shopping_order', 'Kata "konfirmasi" tercantum verbatim pada aturan sebagai bentuk pemicu command confirm.'],
            ['nitip', 1, 'beliin nasi goreng 1', '', 'command', 'bukan:confirm', 'Kata "hanya" pada aturan menutup kemungkinan pesan berisi item diperlakukan sebagai confirm (kasus negatif).'],
            ['nitip', 2, 'tambah toko', '', 'command', 'add_merchant', 'Frasa "tambah toko" tercantum verbatim pada daftar pemicu add_merchant.'],
            ['nitip', 2, 'order baru', '', 'command;items.jumlah', 'add_merchant;0', 'Aturan menyebut "order baru" sebagai pemicu sekaligus melarang kata order dijadikan item.'],
            ['nitip', 3, 'minta daftar menu dong', '', 'command', 'show_menu', 'Aturan menetapkan show_menu untuk permintaan daftar menu.'],
            ['nitip', 4, 'menu berikutnya', '', 'command', 'menu_next', 'Aturan menetapkan menu_next untuk navigasi maju halaman menu.'],
            ['nitip', 4, 'menu sebelumnya', '', 'command', 'menu_previous', 'Aturan menetapkan menu_previous untuk navigasi mundur halaman menu.'],
            ['nitip', 5, 'cari menu ayam geprek', '', 'command;menu_search', 'search_menu;ayam geprek', 'Aturan mewajibkan menu_search diisi hanya dengan nama menu yang dicari, tanpa kata perintah.'],
            ['nitip', 6, 'bingung makan apa', '', 'command', 'recommend_food', 'Contoh "bingung makan apa" tercantum verbatim pada aturan.'],
            ['nitip', 7, 'cara pesennya gimana', '', 'command;intent', 'help;shopping_order', 'Contoh "cara pesennya gimana" tercantum verbatim pada aturan.'],
            ['nitip', 8, 'carikan menu es teh', '', 'command;intent;items.jumlah', 'search_menu;shopping_order;0', 'Aturan mewajibkan items kosong pada seluruh command informasional, termasuk search_menu (kasus negatif: nama menu berpeluang jadi item).'],
            ['nitip', 9, 'beliin nasi goreng 1 antar ke Jalan Diponegoro 5', '', 'items.0.name;delivery_address', 'nasi goreng;berisi:diponegoro', 'Alamat antar disebut pada pesan sehingga menurut aturan wajib diekstrak.'],
            ['nitip', 9, 'beliin nasi goreng 1', '', 'delivery_address;merchant|resto', 'kosong;kosong', 'Frasa "hanya jika disebut" mewajibkan field yang tidak disebut tetap kosong (kasus negatif).'],
            ['nitip', 10, 'beliin seblak 1 di Warung Teteh', '', 'merchant|resto', 'Warung Teteh', 'Aturan mewajibkan nama restoran target diisi ke field merchant/resto.'],
            ['nitip', 11, 'es teh 2', self::KONTEKS_MERCHANT_AKTIF, 'items.jumlah;items.0.name;items.0.quantity', '1;es teh;2', 'Dengan active_merchant_name terisi, aturan melarang nama merchant dipaksakan menjadi bagian nama item.'],
            ['nitip', 12, 'gacoan level 6 1 porsi dan udang keju 1 porsi', '', 'items.jumlah;items.0.name;items.0.quantity;items.1.name;items.1.quantity', '2;gacoan level 6;1;udang keju;1', 'Pesan uji beserta hasil pemisahannya tertulis verbatim di dalam aturan.'],
            ['nitip', 12, 'roti tawar 1 + susu ultra 2', '', 'items.jumlah;items.0.name;items.1.name', '2;roti tawar;susu ultra', 'Aturan menyebut tanda plus sebagai pemisah item yang tidak boleh digabungkan.'],
            ['nitip', 13, 'nasi goreng 1, nasi ruwet 2, kwetiau goreng 1', '', 'items.jumlah;items.0.quantity;items.1.quantity;items.2.quantity', '3;1;2;1', 'Contoh beserta hasil tiga item tertulis verbatim di dalam aturan.'],
            ['nitip', 13, 'beras 1kg, 1', '', 'items.jumlah;items.0.name;items.0.quantity', '1;beras 1kg;1', 'Pengecualian "jika setelah koma hanya jumlah" beserta hasilnya tertulis verbatim di dalam aturan.'],
            ['nitip', 14, 'mie gacoan level 7 2', '', 'items.0.name;items.0.quantity', 'mie gacoan level 7;2', 'Contoh beserta hasilnya tertulis verbatim di dalam aturan.'],
            ['nitip', 14, 'beliin susu 500ml', '', 'items.0.name;items.0.quantity', 'susu 500ml;1', 'Aturan menyatakan ukuran "500ml" adalah bagian nama item dan bukan jumlah.'],
            ['nitip', 15, 'es teh 3 pcs', '', 'items.0.name;items.0.quantity', 'es teh;3', 'Format "3 pcs" disebut eksplisit sebagai penanda quantity.'],
            ['nitip', 15, "aku mau beli ayam geprek 1 di Rendy's Chicken", '', 'items.0.name;merchant|resto', "ayam geprek;Rendy's Chicken", 'Aturan menyebut frasa intent "aku mau beli" dan ekor merchant "di Rendy\'s Chicken" harus dibuang dari nama item.'],
            ['nitip', 16, 'nitipin gw batagor 2 dong', '', 'items.0.name;items.0.quantity', 'batagor;2', 'Kata "nitipin" dan "dong" disebut sebagai frasa intent yang bukan bagian nama item.'],
            ['nitip', 17, 'beliin seblak 1 di Warung Teteh dan es teh 2 di Kopi Kenangan', '', 'stops.jumlah;stops.0.merchant|stops.0.resto;stops.1.merchant|stops.1.resto', '2;Warung Teteh;Kopi Kenangan', 'Aturan mewajibkan stops diisi bila satu pesan jelas menyebut beberapa merchant.'],
            ['nitip', 18, 'beliin nasi goreng 1 di baloeng gajah', '', 'merchant|resto;items.0.name', 'baloeng gajah;nasi goreng', 'Aturan menyatakan nama tempat setelah kata "di" selalu merchant meskipun ditulis huruf kecil.'],
            ['nitip', 18, 'beliin roti 1 dari alfamart tingkir', '', 'merchant|resto;items.0.name', 'alfamart tingkir;roti', 'Aturan menyetarakan kata "dari" dengan "di" sebagai penanda merchant.'],
            ['nitip', 19, 'hapus tempat baloeng gajah', '', 'command;target_merchant;items.jumlah', 'remove_merchant;baloeng gajah;0', 'Contoh tertulis verbatim dan aturan melarang perintah ini diperlakukan sebagai penghapusan item (kasus negatif).'],
            ['nitip', 19, 'batalkan toko kedua', '', 'command;target_stop', 'remove_merchant;2', 'Contoh tertulis verbatim dan aturan menyediakan target_stop berisi urutan 1-3.'],
            ['nitip', 20, 'tambah es teh 1', '', 'items.0.operation', 'add', 'Aturan menetapkan operation "add" untuk penambahan item.'],
            ['nitip', 20, 'kurangi es teh 1', '', 'items.0.operation', 'decrement', 'Kata "kurangi" disebut eksplisit sebagai pemicu operation decrement.'],
            ['nitip', 20, 'es teh 2 saja', '', 'items.0.operation', 'set', 'Kata "saja" disebut eksplisit sebagai pemicu operation set.'],
            ['nitip', 20, 'hapus es teh', '', 'items.0.operation', 'remove', 'Aturan menetapkan operation "remove" untuk penghapusan item.'],
            ['nitip', 21, 'kurangin es teh 1', '', 'items.0.name', 'es teh', 'Aturan melarang kata "kurangin" menjadi bagian nama item (kasus negatif).'],
            ['nitip', 22, 'es teh 2', self::KONTEKS_RIWAYAT_ITEM, 'items.jumlah;items.0.name', '1;es teh', 'Item lama hanya muncul pada riwayat CONTEXT_JSON dan tidak disebut ulang, sehingga aturan melarang item itu dikembalikan (kasus negatif).'],
            ['nitip', 23, 'gacoan lvl 1', self::KONTEKS_KATALOG_MENU, 'items.0.name', 'Mie Gacoan Level 1', 'Contoh "gacoan lvl 1" menjadi "Mie Gacoan Level 1" tertulis verbatim pada aturan.'],
            ['nitip', 24, 'sate ayam 1', self::KONTEKS_KATALOG_MENU, 'items.0.name', 'sate ayam', 'Nama tidak ada pada available_menus sehingga aturan mewajibkan penulisan apa adanya tanpa mengarang nama menu (kasus negatif).'],
            ['nitip', 25, '-', '', '-', self::PENANDA_TIDAK_DIUJI, 'Aturan memakai kata "boleh" sehingga bersifat permisif: tidak ada keluaran yang dapat dinyatakan melanggarnya.'],
            ['nitip', 26, '-', '', '-', self::PENANDA_TIDAK_DIUJI, 'Tidak ada field berat pada responseSchema; selain itu aturan ini tumpang tindih dengan aturan 14 yang justru mewajibkan ukuran seperti "1kg" menjadi bagian nama item.'],
            ['nitip', 27, '-', '', '-', self::PENANDA_TIDAK_DIUJI, 'Aturan bersifat umum tanpa nilai keluaran tertentu; bagiannya yang dapat diperiksa ("tanpa menyalin ulang semua item lama") sudah diuji melalui aturan 22.'],
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function kasusKurir(): array
    {
        return [
            ['kurir', 1, 'konfirmasi', '', 'command;intent', 'confirm;courier_order', 'Kata "konfirmasi" tercantum verbatim pada aturan sebagai bentuk pemicu command confirm.'],
            ['kurir', 1, 'lanjut antar kunci ke Alfamart Sidomukti', '', 'command', 'bukan:confirm', 'Aturan menyatakan pesan yang berisi isi paket atau lokasi tidak boleh menjadi confirm (kasus negatif).'],
            ['kurir', 2, 'cara pesennya gimana', '', 'command;intent', 'help;courier_order', 'Contoh "cara pesennya gimana" beserta intent courier_order tertulis verbatim pada aturan.'],
            ['kurir', 3, 'anterin buku ke Kantor Pos Salatiga', '', 'dropoff_address;package_description', 'berisi:kantor pos salatiga;buku', 'Tujuan dan isi paket disebut pada pesan sehingga menurut aturan wajib diekstrak.'],
            ['kurir', 3, 'anterin paket', '', 'pickup_address;dropoff_address;payment_method', 'kosong;kosong;kosong', 'Frasa "hanya jika user menyebutnya" mewajibkan field yang tidak disebut tetap kosong (kasus negatif).'],
            ['kurir', 4, 'isi paket kunci', '', 'package_description', 'kunci', 'Frasa "isi paket kunci" tercantum verbatim pada aturan sebagai pengisi package_description.'],
            ['kurir', 5, 'antar kacamata ke Erha Setiabudi Tembalang', '', 'dropoff_address;package_description', 'Erha Setiabudi Tembalang;kacamata', 'Pesan uji beserta kedua nilai harapannya tertulis verbatim di dalam aturan.'],
            ['kurir', 6, 'ambil paketnya di rumahku, antar ke Alfamart Sidomukti', '', 'pickup_address', 'rumah', 'Aturan menetapkan sebutan "rumahku" sebagai pickup diisi dengan nilai "rumah".'],
            ['kurir', 7, '-', '', '-', self::PENANDA_TIDAK_DIUJI, 'Kriteria "barang ambigu" tidak didefinisikan pada prompt sehingga nilai harapannya bergantung penilaian penguji, bukan spesifikasi.'],
            ['kurir', 8, '-', '', '-', self::PENANDA_TIDAK_DIUJI, 'Aturan mengatur cara berpikir model, bukan keluaran: tidak ada field terpisah untuk barang inti maupun imbuhan; akibatnya baru terlihat pada aturan 9.'],
            ['kurir', 9, 'kirimin charger punya adek ke Alfamart Sidomukti', '', 'package_description;package_description;package_description;package_description', 'berisi:charger;berisi:adek;tanpa:ke;tanpa:alfamart', 'Aturan mewajibkan barang inti beserta imbuhannya dan melarang kata "ke" maupun nama tempat tujuan ikut masuk.'],
            ['kurir', 10, 'bawain payung ke Kantor Pos Salatiga', '', 'intent;dropoff_address', 'courier_order;berisi:kantor pos', 'Kata "bawain" disebut eksplisit sebagai kata kerja yang berarti antar/kirim.'],
            ['kurir', 10, 'kirimin dokumen ke Alfamart Sidomukti', '', 'intent;package_description', 'courier_order;dokumen', 'Kata "kirimin" disebut eksplisit sebagai kata kerja yang berarti antar/kirim.'],
            ['kurir', 11, '-', '', '-', self::PENANDA_TIDAK_DIUJI, 'Aturan bersifat umum ("gunakan untuk membaca progres") tanpa nilai keluaran tertentu yang dapat diturunkan.'],
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function kasusAntarJemput(): array
    {
        return [
            ['antar_jemput', 1, 'cara pesennya gimana', '', 'command;intent', 'help;ride_order', 'Contoh "cara pesennya gimana" beserta intent ride_order tertulis verbatim pada aturan.'],
            ['antar_jemput', 2, 'antar ke Ramayana Salatiga', '', 'destination_address', 'Ramayana Salatiga', 'Pola "antar ke Ramayana Salatiga" tertulis verbatim pada aturan sebagai pengisi destination_address.'],
            ['antar_jemput', 3, 'gas ke Stasiun Tawang', '', 'destination_address', 'Stasiun Tawang', 'Frasa "gas ke" disebut eksplisit sebagai permintaan diantar ke tujuan.'],
            ['antar_jemput', 3, 'otw ke Bandara Ahmad Yani', '', 'destination_address', 'Bandara Ahmad Yani', 'Frasa "otw ke" disebut eksplisit sebagai permintaan diantar ke tujuan.'],
            ['antar_jemput', 4, 'jemput saya di Terminal Tingkir', '', 'pickup_address;notes', 'Terminal Tingkir;kosong', 'Pola "jemput saya di <lokasi>" tertulis verbatim, dan aturan melarang lokasi jemput ditaruh di notes (kasus negatif).'],
            ['antar_jemput', 4, 'dari Kos Melati ke Ramayana Salatiga', '', 'pickup_address;destination_address', 'Kos Melati;Ramayana Salatiga', 'Pola "dari <lokasi> ke <tujuan>" tertulis verbatim pada aturan.'],
            ['antar_jemput', 5, 'jemput di kantor ya', '', 'pickup_address', 'kantor', 'Aturan menyatakan sebutan alamat tersimpan seperti "kantor" boleh diisi apa adanya ke pickup_address.'],
            ['antar_jemput', 6, '-', '', '-', self::PENANDA_TIDAK_DIUJI, 'Aturan bersifat umum ("gunakan untuk membaca progres") tanpa nilai keluaran tertentu yang dapat diturunkan.'],
        ];
    }

    /**
     * Kasus yang diturunkan dari blok KELUARAN, bukan blok ATURAN.
     *
     * Tujuannya memeriksa apakah setiap nilai intent dan command yang
     * dinyatakan sah pada kontrak keluaran benar-benar dapat dipicu. Nilai yang
     * tidak pernah dapat dipicu adalah temuan, karena artinya kontrak keluaran
     * menjanjikan perilaku yang tidak didukung satu pun butir aturan.
     *
     * @return array<int, array<int, mixed>>
     */
    private function kasusKontrakKeluaran(): array
    {
        return [
            ['nitip', 'KELUARAN', 'harga bitcoin hari ini berapa', '', 'intent', 'out_of_domain', 'Kontrak keluaran menyatakan out_of_domain sebagai nilai intent yang sah, sehingga pesan di luar domain harus dapat memicunya.'],
            ['kurir', 'KELUARAN', 'presiden indonesia siapa', '', 'intent', 'out_of_domain', 'Kontrak keluaran menyatakan out_of_domain sebagai nilai intent yang sah, sehingga pesan di luar domain harus dapat memicunya.'],
            ['antar_jemput', 'KELUARAN', 'cuaca besok gimana', '', 'intent', 'out_of_domain', 'Kontrak keluaran menyatakan out_of_domain sebagai nilai intent yang sah, sehingga pesan di luar domain harus dapat memicunya.'],
            ['kurir', 'KELUARAN', 'ganti tujuan', '', 'command', 'reset_destination', 'Kontrak keluaran menyatakan reset_destination sebagai command yang sah, padahal tidak ada butir aturan yang menjelaskan kapan dipilih.'],
            ['antar_jemput', 'KELUARAN', 'ubah tujuan', '', 'command', 'reset_destination', 'Kontrak keluaran menyatakan reset_destination sebagai command yang sah, padahal tidak ada butir aturan yang menjelaskan kapan dipilih.'],
            ['antar_jemput', 'KELUARAN', 'konfirmasi', '', 'command', 'confirm', 'Kontrak keluaran menyatakan confirm sebagai command yang sah, padahal instruction set Antar Jemput tidak memuat butir aturan tentang konfirmasi.'],
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $baris
     */
    private function tulisCsv(string $berkas, array $baris): void
    {
        $direktori = dirname($berkas);

        if (! is_dir($direktori)) {
            mkdir($direktori, 0777, true);
        }

        $pointer = fopen($berkas, 'w');

        fputcsv($pointer, self::KOLOM);

        foreach ($baris as $row) {
            fputcsv($pointer, $row);
        }

        fclose($pointer);
    }
}
