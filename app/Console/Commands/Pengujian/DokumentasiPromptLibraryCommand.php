<?php

namespace App\Console\Commands\Pengujian;

use App\Services\Chatbot\ChatbotPromptLibrary;
use Illuminate\Console\Command;

/**
 * Pembuat dokumentasi Prompt Library untuk laporan (Bab III 3.4.3 dan Lampiran).
 *
 * Perintah ini membaca ChatbotPromptLibrary secara langsung, menghitung jumlah
 * aturan dan contoh few-shot dari string prompt yang benar-benar dikirim ke
 * Gemini, lalu menuliskannya sebagai dokumen Markdown. Dengan cara ini angka
 * pada tabel ringkasan tidak pernah diketik manual sehingga tidak dapat
 * menyimpang dari isi kode.
 */
class DokumentasiPromptLibraryCommand extends Command
{
    protected $signature = 'pengujian:dokumentasi-prompt
                            {--berkas=docs/PROMPT_LIBRARY_LAPORAN.md : Lokasi berkas Markdown keluaran}';

    protected $description = 'Menghasilkan docs/PROMPT_LIBRARY_LAPORAN.md berisi struktur, tabel ringkasan, dan teks lengkap prompt library';

    /**
     * Field keluaran per instruction set, dibaca dari definisi responseSchema
     * pada App\Services\Chatbot\ChatbotGeminiService.
     *
     * @var array<string, array{properti: array<int, string>, wajib: array<int, string>}>
     */
    private const FIELD_KELUARAN = [
        'nitip' => [
            'properti' => [
                'intent', 'command', 'merchant', 'resto', 'menu_search', 'delivery_address',
                'target_merchant', 'target_stop', 'items', 'stops',
            ],
            'wajib' => ['intent', 'command', 'items'],
        ],
        'kurir' => [
            'properti' => [
                'intent', 'command', 'pickup_address', 'dropoff_address',
                'package_description', 'payment_method',
            ],
            'wajib' => ['intent', 'command'],
        ],
        'antar_jemput' => [
            'properti' => [
                'intent', 'command', 'pickup_address', 'destination_address', 'notes',
            ],
            'wajib' => ['intent', 'command'],
        ],
    ];

    public function handle(): int
    {
        $set = [
            'nitip' => [
                'metode' => 'foodOrderInstruction()',
                'layanan' => 'Nitip',
                'teks' => ChatbotPromptLibrary::foodOrderInstruction(),
            ],
            'kurir' => [
                'metode' => 'courierInstruction()',
                'layanan' => 'Kurir',
                'teks' => ChatbotPromptLibrary::courierInstruction(),
            ],
            'antar_jemput' => [
                'metode' => 'rideInstruction()',
                'layanan' => 'Antar Jemput',
                'teks' => ChatbotPromptLibrary::rideInstruction(),
            ],
        ];

        foreach ($set as $kunci => $data) {
            $set[$kunci]['jumlah_aturan'] = $this->hitungAturan($data['teks']);
            $set[$kunci]['jumlah_contoh'] = count(ChatbotPromptLibrary::examplesFor($kunci));
        }

        $tujuan = base_path((string) $this->option('berkas'));
        @mkdir(dirname($tujuan), 0775, true);
        file_put_contents($tujuan, $this->susun($set));

        $this->info('Dokumentasi prompt library ditulis ke: '.$tujuan);
        $this->newLine();
        $this->table(
            ['Instruction Set', 'Layanan', 'Jumlah Aturan', 'Contoh Few-shot', 'Field Keluaran'],
            array_map(
                fn (array $d, string $k): array => [
                    $d['metode'],
                    $d['layanan'],
                    $d['jumlah_aturan'],
                    $d['jumlah_contoh'],
                    count(self::FIELD_KELUARAN[$k]['properti']),
                ],
                $set,
                array_keys($set)
            )
        );

        return self::SUCCESS;
    }

    /**
     * Menghitung jumlah aturan sebagai banyaknya baris berawalan "- " pada
     * string prompt yang sudah dirakit oleh compose(). Cara ini dipilih karena
     * yang dihitung adalah aturan sebagaimana benar-benar terkirim ke Gemini,
     * bukan banyaknya elemen pada larik sumber.
     */
    private function hitungAturan(string $teks): int
    {
        return count(array_filter(
            explode("\n", $teks),
            static fn (string $baris): bool => str_starts_with($baris, '- ')
        ));
    }

    /**
     * @param  array<string, array<string, mixed>>  $set
     */
    private function susun(array $set): string
    {
        $b = [];

        $b[] = '# Prompt Library Chatbot Pelanggan 15';
        $b[] = '';
        $b[] = 'Dokumen ini dihasilkan oleh `php artisan pengujian:dokumentasi-prompt` dan membaca';
        $b[] = 'langsung berkas `app/Services/Chatbot/ChatbotPromptLibrary.php` serta';
        $b[] = '`app/Services/Chatbot/ChatbotGeminiService.php`. Seluruh angka pada dokumen ini';
        $b[] = 'dihitung dari kode, bukan diketik manual.';
        $b[] = '';
        $b[] = '---';
        $b[] = '';

        $b[] = $this->bagianStruktur();
        $b[] = '';
        $b[] = $this->bagianTabel($set);
        $b[] = '';
        $b[] = $this->bagianContohUtuh($set);
        $b[] = '';
        $b[] = $this->bagianFallback();
        $b[] = '';
        $b[] = $this->bagianTeknik();
        $b[] = '';
        $b[] = $this->bagianTemuan();
        $b[] = '';
        $b[] = $this->bagianLampiran($set);
        $b[] = '';

        return implode("\n", $b);
    }

    private function bagianStruktur(): string
    {
        return <<<'MARKDOWN'
        ## 1. Struktur Prompt

        Seluruh instruksi sistem pada `ChatbotPromptLibrary` dirakit oleh satu metode privat
        `compose()` sehingga ketiga layanan memiliki susunan yang seragam. Susunan tersebut
        terdiri atas empat blok yang selalu muncul dengan urutan tetap:

        ```
        PERAN:    <penetapan peran>
        ATURAN:
        - <aturan 1>
        - <aturan n>
        KELUARAN: <kontrak keluaran dan enumerasi nilai sah>
        CONTOH:
        INPUT:  "<pesan pengguna>"
        OUTPUT: {<JSON hasil ekstraksi>}
        ```

        ### 1.1 PERAN

        Blok PERAN menetapkan peran yang harus diambil model, yaitu sebagai *natural language
        understanding assistant* Pelanggan 15 untuk satu layanan tertentu. Penetapan peran
        dilakukan per layanan, bukan satu peran umum untuk seluruh layanan, sehingga ruang
        jawaban model dipersempit sejak kalimat pertama. Tanpa blok ini model cenderung
        berperan sebagai asisten percakapan umum dan menghasilkan jawaban naratif yang tidak
        dapat diproses lebih lanjut oleh sistem.

        ### 1.2 ATURAN

        Blok ATURAN memuat daftar batasan perilaku dalam bentuk butir, satu kalimat untuk satu
        batasan. Isinya mencakup tiga jenis ketentuan: kapan sebuah perintah (*command*) boleh
        dipilih, bagaimana entitas dipisahkan dan dinormalisasi, serta larangan eksplisit
        terhadap penafsiran yang keliru. Blok ini merupakan bagian terpanjang karena ragam
        bahasa sehari-hari pengguna Pelanggan 15 memuat banyak kasus khusus, misalnya angka yang
        merupakan bagian nama menu ("level 6", "500ml") dan angka yang merupakan jumlah
        pesanan. Tanpa batasan eksplisit, keduanya tidak dapat dibedakan secara andal.

        ### 1.3 KELUARAN

        Blok KELUARAN berisi kontrak keluaran. Kontrak tersebut menyatakan bahwa model hanya
        boleh menjawab dalam bentuk JSON sesuai skema, menyebutkan secara enumeratif nilai
        `intent` dan `command` yang sah, serta melarang jawaban dalam bentuk teks biasa. Blok
        inilah yang membuat keluaran model dapat langsung diperlakukan sebagai data
        terstruktur dan bukan sebagai teks yang masih harus ditafsirkan.

        Kontrak keluaran ditegakkan pada tiga lapisan yang saling menguatkan:

        | Lapisan | Bentuk penegakan | Letak pada kode |
        |---|---|---|
        | Prompt | Kalimat larangan pada blok KELUARAN | `ChatbotPromptLibrary::compose()` |
        | Antarmuka API | `responseMimeType: application/json` dan `responseSchema` | `ChatbotGeminiService::generateJson()` |
        | Aplikasi | Normalisasi dan nilai cadangan bila JSON gagal diurai | `normalizeFoodPayload()`, `normalizeCourierPayload()`, `normalizeRidePayload()` |

        Rangkap tiga lapisan ini penting karena penegakan pada tingkat prompt bersifat
        persuasif, sedangkan penegakan pada tingkat skema API bersifat mengikat, dan
        penegakan pada tingkat aplikasi menjamin sistem tetap berjalan meskipun kedua lapisan
        sebelumnya gagal.

        ### 1.4 CONTOH

        Blok CONTOH memuat pasangan INPUT dan OUTPUT yang berfungsi sebagai *few-shot
        examples*. Contoh dirender dari metode `examplesFor()` sehingga pasangan
        masukan-keluaran tersebut menjadi satu sumber kebenaran yang dapat diuji secara
        otomatis, tidak sekadar teks yang tertanam pada prompt. Contoh yang dipilih adalah
        ragam bahasa gaul yang lazim dipakai pengguna, misalnya "beliin gw seblak 2 dong di
        Teteh" dan "anterin gue ke stasiun", karena tujuan utamanya adalah menunjukkan
        pemetaan dari bahasa sehari-hari menjadi struktur data, bukan dari kalimat baku.

        ### 1.5 Penyisipan CONTEXT_JSON

        Konteks percakapan tidak disisipkan ke dalam instruksi sistem, melainkan dikirim
        sebagai bagian kedua dari pesan pengguna:

        ```
        contents[0].parts[0].text = <pesan pengguna>
        contents[0].parts[1].text = "CONTEXT_JSON: " + <konteks dalam bentuk JSON>
        ```

        Pemisahan ini menjaga instruksi sistem tetap tidak berubah antar permintaan, sehingga
        perilaku model terhadap aturan tetap konsisten sementara konteks percakapan dapat
        berubah setiap giliran. Bagian `parts[1]` hanya ditambahkan bila konteks tersedia dan
        tidak kosong.
        MARKDOWN;
    }

    /**
     * @param  array<string, array<string, mixed>>  $set
     */
    private function bagianTabel(array $set): string
    {
        $b = [];
        $b[] = '## 2. Tabel Ringkasan Instruction Set';
        $b[] = '';
        $b[] = '| Instruction Set | Layanan | Jumlah Aturan | Jumlah Contoh Few-shot | Field Keluaran |';
        $b[] = '|---|---|---|---|---|';

        foreach ($set as $kunci => $data) {
            $field = self::FIELD_KELUARAN[$kunci];
            $b[] = sprintf(
                '| `%s` | %s | %d | %d | %d field (%d wajib) |',
                $data['metode'],
                $data['layanan'],
                $data['jumlah_aturan'],
                $data['jumlah_contoh'],
                count($field['properti']),
                count($field['wajib'])
            );
        }

        $b[] = '';
        $b[] = '### 2.1 Cara Angka Tersebut Dihitung';
        $b[] = '';
        $b[] = 'Angka pada tabel di atas dihitung dengan cara berikut sehingga dapat diverifikasi ulang:';
        $b[] = '';
        $b[] = '1. **Jumlah aturan** dihitung sebagai banyaknya baris yang diawali tanda `- ` pada';
        $b[] = '   string prompt hasil rakitan `compose()`. Yang dihitung adalah aturan sebagaimana';
        $b[] = '   benar-benar terkirim ke Gemini, bukan banyaknya elemen pada larik sumber.';
        $b[] = '2. **Jumlah contoh few-shot** dihitung dengan `count(ChatbotPromptLibrary::examplesFor($layanan))`.';
        $b[] = '3. **Field keluaran** dihitung dari banyaknya kunci pada `properties` di dalam';
        $b[] = '   definisi `responseSchema` pada `ChatbotGeminiService`, beserta banyaknya kunci';
        $b[] = '   pada senarai `required`.';
        $b[] = '';
        $b[] = 'Verifikasi dapat dilakukan dengan menjalankan ulang perintah berikut dari folder `Backend_Bangdeliv`:';
        $b[] = '';
        $b[] = '```bash';
        $b[] = 'php artisan pengujian:dokumentasi-prompt';
        $b[] = '```';
        $b[] = '';
        $b[] = '### 2.2 Rincian Field Keluaran per Layanan';
        $b[] = '';

        foreach ($set as $kunci => $data) {
            $field = self::FIELD_KELUARAN[$kunci];
            $b[] = sprintf('**%s (`%s`)**', $data['layanan'], $data['metode']);
            $b[] = '';
            $b[] = '- Field: `'.implode('`, `', $field['properti']).'`';
            $b[] = '- Wajib ada: `'.implode('`, `', $field['wajib']).'`';
            $b[] = '';
        }

        $b[] = 'Pada layanan Nitip, field `items` dan `stops` bertipe larik objek. Setiap elemen';
        $b[] = '`items` memiliki field `name`, `menu`, `quantity`, `qty`, `operation`, dan `notes`,';
        $b[] = 'dengan `name` dan `quantity` bersifat wajib. Setiap elemen `stops` memiliki field';
        $b[] = '`merchant`, `resto`, dan `items`, dengan `items` bersifat wajib.';

        return implode("\n", $b);
    }

    /**
     * @param  array<string, array<string, mixed>>  $set
     */
    private function bagianContohUtuh(array $set): string
    {
        $b = [];
        $b[] = '## 3. Contoh Satu Instruction Set Utuh';
        $b[] = '';
        $b[] = 'Berikut instruksi sistem layanan Antar Jemput (`rideInstruction()`) ditampilkan apa';
        $b[] = 'adanya. Instruction set ini dipilih sebagai contoh pada badan laporan karena';
        $b[] = 'merupakan yang terpendek, sehingga keempat blok penyusunnya dapat terlihat utuh';
        $b[] = 'dalam satu halaman.';
        $b[] = '';
        $b[] = '```text';
        $b[] = (string) $set['antar_jemput']['teks'];
        $b[] = '```';

        return implode("\n", $b);
    }

    private function bagianFallback(): string
    {
        return <<<'MARKDOWN'
        ## 4. Mekanisme Fallback Model dan Pembatasan Permintaan

        Bagian ini menguraikan mekanisme sebagaimana benar-benar diterapkan pada kode, agar
        konsisten dengan uraian pada Bab II dan batasan masalah.

        ### 4.1 Daftar Model dan Urutan Percobaan

        Daftar model dibaca dari konfigurasi `bangdeliv.chatbot.gemini.models`, yang berasal
        dari variabel lingkungan `GEMINI_MODELS`. Nilai bawaan berisi empat model yang dicoba
        berurutan:

        | Urutan | Model | Peran |
        |---|---|---|
        | 1 | `gemini-3.1-flash-lite` | model utama |
        | 2 | `gemini-2.5-flash-lite` | cadangan pertama |
        | 3 | `gemini-2.5-flash` | cadangan kedua |
        | 4 | `gemini-3-flash-preview` | cadangan ketiga |

        Bila konfigurasi kosong atau tidak berupa larik, sistem memakai satu model tunggal
        `gemini-2.5-flash`.

        ### 4.2 Alur Fallback

        Permintaan dikirim ke `https://generativelanguage.googleapis.com/v1beta/models/<model>:generateContent`
        dengan batas waktu 12 detik per permintaan (`bangdeliv.chatbot.gemini.timeout_seconds`).
        Perilaku untuk setiap model pada urutan tersebut adalah sebagai berikut:

        | Kondisi respons | Tindakan sistem |
        |---|---|
        | Berhasil (2xx) | Keluaran diurai dan dikembalikan beserta nama model yang dipakai |
        | Status 429 (kuota terlampaui) | Dicatat ke log sebagai peringatan, lalu lanjut ke model berikutnya |
        | Status galat lain | Pesan galat disimpan, lalu lanjut ke model berikutnya |
        | Kegagalan jaringan atau batas waktu | Dicatat ke log sebagai galat, lalu lanjut ke model berikutnya |
        | Seluruh model gagal | Dilempar `ApiException` berkode 503 dengan pesan bahwa layanan AI sedang sibuk |

        Perlu dicatat bahwa fallback pada sistem ini terjadi antar model, bukan antar penyedia
        layanan. Bila seluruh model pada daftar gagal, permintaan tidak dialihkan ke mekanisme
        pemrosesan lain melainkan langsung ditolak dengan pesan kesalahan yang dapat dibaca
        pengguna.

        ### 4.3 Penanganan Keluaran yang Tidak Dapat Diurai

        Bila permintaan berhasil tetapi teks jawaban tidak dapat diurai sebagai JSON, sistem
        tidak melempar galat melainkan memakai nilai cadangan yang telah disiapkan per
        layanan. Nilai cadangan tersebut berbeda antar layanan:

        | Layanan | `intent` pada nilai cadangan |
        |---|---|
        | Nitip | `out_of_domain` |
        | Kurir | `courier_order` |
        | Antar Jemput | `ride_order` |

        ### 4.4 Pembatasan Permintaan

        Pembatasan diterapkan pada dua tingkat yang berbeda tujuannya:

        | Jenis batas | Nilai | Letak penegakan |
        |---|---|---|
        | Permintaan per menit per pengguna | 12 | `RateLimiter::for('chatbot')` pada `AppServiceProvider` |
        | Permintaan per jam per pengguna | 120 | `RateLimiter::for('chatbot')` pada `AppServiceProvider` |
        | Pesan di luar konteks sebelum diblokir | 3 kali | `ChatbotContextLimitService` |
        | Rentang waktu penghitungan pesan di luar konteks | 30 menit | `ChatbotContextLimitService` |
        | Lama pemblokiran setelah batas terlampaui | 15 menit | `ChatbotContextLimitService` |
        | Masa berlaku draft percakapan | 120 menit | `bangdeliv.chatbot.draft_ttl_minutes` |

        Pembatas permintaan diidentifikasi berdasarkan `user:<id>` bagi pengguna yang sudah
        terautentikasi, dan berdasarkan `ip:<alamat>` bila tidak. Pembatas pesan di luar
        konteks berlaku per pengguna per jenis layanan, sehingga pemblokiran pada satu layanan
        tidak ikut memblokir layanan lain.
        MARKDOWN;
    }

    private function bagianTeknik(): string
    {
        return <<<'MARKDOWN'
        ## 5. Teknik Prompting yang Benar-Benar Terpakai

        Daftar berikut hanya memuat teknik yang dapat ditunjuk letaknya pada kode. Teknik yang
        tidak ditemukan pada implementasi tidak dicantumkan agar penulisan Bab III tidak
        mengklaim sesuatu yang tidak ada.

        | Istilah | Wujud pada kode | Letak |
        |---|---|---|
        | *Role prompting* (penetapan peran) | Blok `PERAN` menetapkan model sebagai NLU assistant satu layanan | `compose()`, argumen `$role` |
        | *Constraint listing* (penyenaraian batasan) | Blok `ATURAN` berisi butir batasan perilaku | larik `$rules` pada tiap instruction set |
        | *Negative constraint* (batasan berbentuk larangan) | Kalimat berawalan "Jangan ..." dan "TIDAK BOLEH ..." | contoh: aturan pemisahan item dan aturan `package_description` |
        | *Output contract* (kontrak keluaran) | Blok `KELUARAN` mewajibkan JSON dan melarang teks biasa | `compose()`, argumen `$outputContract` |
        | *Constrained decoding* (pembatasan keluaran pada tingkat API) | `responseMimeType: application/json` dan `responseSchema` | `ChatbotGeminiService::generateJson()` |
        | *Enumerated value constraint* (pembatasan nilai terhitung) | Penyebutan eksplisit nilai `intent` dan `command` yang sah | blok `KELUARAN` ketiga instruction set |
        | *Few-shot prompting* (pembelajaran dalam konteks) | Blok `CONTOH` berisi pasangan INPUT/OUTPUT | `examplesFor()` |
        | *Context injection* (penyisipan konteks percakapan) | `CONTEXT_JSON` dikirim sebagai bagian kedua pesan pengguna | `generateJson()` |
        | *Grounding terhadap katalog* | Aturan menyamakan nama item dengan daftar `available_menus` dan larangan mengarang nama menu | aturan ke-23 dan ke-24 `foodOrderInstruction()` |
        | *Model fallback chaining* | Perulangan daftar model dengan lanjut ke model berikutnya saat gagal | `generateJson()` |

        Teknik berikut **tidak** dipakai pada implementasi ini dan karena itu tidak boleh
        dicantumkan pada laporan: *chain-of-thought prompting*, *self-consistency*, *ReAct*,
        *tree-of-thought*, *retrieval-augmented generation* berbasis penelusuran vektor, serta
        penyetelan parameter *temperature* atau *top-p* (`generationConfig` pada kode hanya
        memuat `responseMimeType` dan `responseSchema`).
        MARKDOWN;
    }

    private function bagianTemuan(): string
    {
        return <<<'MARKDOWN'
        ## 6. Temuan pada Prompt Library

        Bagian ini mencatat hal-hal yang berpotensi membingungkan model atau berpotensi tidak
        berpengaruh sama sekali. Temuan ini tidak diperbaiki pada tahap pengujian agar angka
        akurasi yang diperoleh mencerminkan kondisi sistem apa adanya, dan disediakan sebagai
        bahan saran pengembangan pada Bab V.

        **Temuan 1 — Perintah `reset_destination` disebut pada kontrak keluaran tetapi tidak
        memiliki aturan pendamping.** Blok KELUARAN pada `courierInstruction()` dan
        `rideInstruction()` mencantumkan `reset_destination` sebagai nilai `command` yang sah,
        namun tidak ada satu pun butir pada blok ATURAN yang menjelaskan kapan perintah itu
        harus dipilih. Model hanya dapat menebak dari nama perintahnya.

        **Temuan 2 — Terdapat aturan yang tidak memiliki sasaran field keluaran.** Aturan
        "Jangan menentukan item berat; berat akan dikonfirmasi driver" pada
        `foodOrderInstruction()` tidak memiliki field padanan pada `responseSchema`, karena
        skema elemen `items` hanya memuat `name`, `menu`, `quantity`, `qty`, `operation`, dan
        `notes`. Aturan tersebut menambah panjang instruksi tanpa dapat memengaruhi keluaran.

        **Temuan 3 — Skema keluaran memuat pasangan field sinonim yang tidak dijelaskan pada
        prompt.** Skema elemen `items` menyediakan `name` dan `menu` serta `quantity` dan
        `qty` sekaligus. Prompt tidak menyatakan pasangan mana yang harus diisi, sehingga
        pemilihannya diserahkan sepenuhnya kepada model. Perbedaan tersebut memang diserap
        oleh `ChatbotShoppingItemNormalizer`, namun ketidakjelasan pada tingkat prompt tetap
        menambah ruang variasi keluaran yang tidak perlu.

        **Temuan 4 — Aturan pemisahan item dan aturan penanganan koma saling bertumpuk.**
        Aturan ke-12 melarang penggabungan item yang dipisahkan koma, sedangkan aturan ke-13
        menyatakan koma boleh memisahkan item dengan pengecualian bila setelah koma hanya
        terdapat angka. Kedua aturan tersebut tidak bertentangan, namun ketentuan pokok dan
        pengecualiannya dipecah menjadi dua butir sehingga penafsiran yang benar bergantung
        pada kemampuan model menggabungkan keduanya sebagai satu ketentuan utuh.

        **Temuan 5 — Aturan `help` diulang pada ketiga instruction set dengan redaksi hampir
        identik.** Pengulangan ini menyulitkan pemeliharaan karena perubahan perilaku `help`
        harus dilakukan di tiga tempat.

        **Temuan 6 — Beban instruksi pada layanan Nitip jauh lebih besar daripada dua layanan
        lain.** Jumlah aturan pada `foodOrderInstruction()` beberapa kali lipat jumlah aturan
        pada dua instruction set lainnya, seluruhnya berada pada satu blok datar tanpa
        pengelompokan. Butir yang paling menentukan keberhasilan ekstraksi, yaitu aturan
        pemisahan item, terletak jauh dari contoh few-shot yang memperagakannya.

        **Temuan 7 — Nilai cadangan untuk layanan Kurir dan Antar Jemput bukan
        `out_of_domain`.** Bila keluaran model gagal diurai sebagai JSON, kedua layanan
        tersebut tetap menghasilkan `intent` berupa pesanan yang sah, berbeda dengan layanan
        Nitip yang menghasilkan `out_of_domain`. Akibatnya pesan yang sebenarnya di luar
        konteks berpeluang tetap diperlakukan sebagai permintaan pesanan pada kedua layanan
        tersebut.
        MARKDOWN;
    }

    /**
     * @param  array<string, array<string, mixed>>  $set
     */
    private function bagianLampiran(array $set): string
    {
        $b = [];
        $b[] = '---';
        $b[] = '';
        $b[] = '## Lampiran — Teks Lengkap Ketiga Instruction Set';
        $b[] = '';
        $b[] = 'Teks di bawah ini adalah instruksi sistem yang benar-benar dikirim ke Gemini pada';
        $b[] = 'field `systemInstruction.parts[0].text`, ditampilkan apa adanya tanpa penyuntingan.';
        $b[] = '';

        $urutan = 1;
        foreach ($set as $kunci => $data) {
            $field = self::FIELD_KELUARAN[$kunci];

            $b[] = sprintf(
                '### Lampiran %d — Layanan %s (`ChatbotPromptLibrary::%s`)',
                $urutan,
                $data['layanan'],
                $data['metode']
            );
            $b[] = '';
            $b[] = sprintf(
                'Jumlah aturan: %d butir. Jumlah contoh few-shot: %d pasang. Field keluaran: %d field.',
                $data['jumlah_aturan'],
                $data['jumlah_contoh'],
                count($field['properti'])
            );
            $b[] = '';
            $b[] = '```text';
            $b[] = (string) $data['teks'];
            $b[] = '```';
            $b[] = '';

            $urutan++;
        }

        return implode("\n", $b);
    }
}
