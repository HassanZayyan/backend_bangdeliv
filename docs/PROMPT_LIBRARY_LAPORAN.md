# Prompt Library Chatbot Pelanggan 15

Dokumen ini dihasilkan oleh `php artisan pengujian:dokumentasi-prompt` dan membaca
langsung berkas `app/Services/Chatbot/ChatbotPromptLibrary.php` serta
`app/Services/Chatbot/ChatbotGeminiService.php`. Seluruh angka pada dokumen ini
dihitung dari kode, bukan diketik manual.

---

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

## 2. Tabel Ringkasan Instruction Set

| Instruction Set | Layanan | Jumlah Aturan | Jumlah Contoh Few-shot | Field Keluaran |
|---|---|---|---|---|
| `foodOrderInstruction()` | Nitip | 27 | 5 | 10 field (3 wajib) |
| `courierInstruction()` | Kurir | 11 | 4 | 6 field (2 wajib) |
| `rideInstruction()` | Antar Jemput | 6 | 4 | 5 field (2 wajib) |

### 2.1 Cara Angka Tersebut Dihitung

Angka pada tabel di atas dihitung dengan cara berikut sehingga dapat diverifikasi ulang:

1. **Jumlah aturan** dihitung sebagai banyaknya baris yang diawali tanda `- ` pada
   string prompt hasil rakitan `compose()`. Yang dihitung adalah aturan sebagaimana
   benar-benar terkirim ke Gemini, bukan banyaknya elemen pada larik sumber.
2. **Jumlah contoh few-shot** dihitung dengan `count(ChatbotPromptLibrary::examplesFor($layanan))`.
3. **Field keluaran** dihitung dari banyaknya kunci pada `properties` di dalam
   definisi `responseSchema` pada `ChatbotGeminiService`, beserta banyaknya kunci
   pada senarai `required`.

Verifikasi dapat dilakukan dengan menjalankan ulang perintah berikut dari folder `Backend_Bangdeliv`:

```bash
php artisan pengujian:dokumentasi-prompt
```

### 2.2 Rincian Field Keluaran per Layanan

**Nitip (`foodOrderInstruction()`)**

- Field: `intent`, `command`, `merchant`, `resto`, `menu_search`, `delivery_address`, `target_merchant`, `target_stop`, `items`, `stops`
- Wajib ada: `intent`, `command`, `items`

**Kurir (`courierInstruction()`)**

- Field: `intent`, `command`, `pickup_address`, `dropoff_address`, `package_description`, `payment_method`
- Wajib ada: `intent`, `command`

**Antar Jemput (`rideInstruction()`)**

- Field: `intent`, `command`, `pickup_address`, `destination_address`, `notes`
- Wajib ada: `intent`, `command`

Pada layanan Nitip, field `items` dan `stops` bertipe larik objek. Setiap elemen
`items` memiliki field `name`, `menu`, `quantity`, `qty`, `operation`, dan `notes`,
dengan `name` dan `quantity` bersifat wajib. Setiap elemen `stops` memiliki field
`merchant`, `resto`, dan `items`, dengan `items` bersifat wajib.

## 3. Contoh Satu Instruction Set Utuh

Berikut instruksi sistem layanan Antar Jemput (`rideInstruction()`) ditampilkan apa
adanya. Instruction set ini dipilih sebagai contoh pada badan laporan karena
merupakan yang terpendek, sehingga keempat blok penyusunnya dapat terlihat utuh
dalam satu halaman.

```text
PERAN: Kamu adalah NLU assistant BangDeliv untuk layanan Antar Jemput.
ATURAN:
- Gunakan command "help" dengan intent "ride_order" saat pengguna menanyakan cara memakai BangBot atau cara membuat pesanan, misalnya "cara pesennya gimana" atau "ini suruh ngapain".
- Jika user memberi tujuan dengan pola seperti "antar ke Ramayana Salatiga" atau "saya mau ke Alun-Alun Salatiga", isi destination_address.
- Kata kerja santai seperti "anter", "anterin", "gas ke", dan "otw ke" juga berarti minta diantar ke tujuan.
- Jika user menyebut lokasi jemput dengan pola seperti "jemput saya di <lokasi>" atau "dari <lokasi> ke <tujuan>", isi pickup_address dengan lokasi jemput tersebut. Jangan taruh lokasi jemput di notes.
- Sebutan jemput yang mengacu ke alamat tersimpan seperti "rumah" atau "kantor" boleh diisi apa adanya ke pickup_address.
- Jika disediakan CONTEXT_JSON, gunakan untuk membaca progres percakapan dan draft terakhir.
KELUARAN: Hanya JSON sesuai schema. intent valid: "ride_order" atau "out_of_domain". command valid: "confirm", "reset_destination", "help", atau "none". Dilarang merespon teks biasa.
CONTOH:
INPUT: "anterin gue ke stasiun"
OUTPUT: {"intent":"ride_order","command":"none","pickup_address":null,"destination_address":"stasiun","notes":null}
INPUT: "saya mau ke Alun-Alun Salatiga"
OUTPUT: {"intent":"ride_order","command":"none","pickup_address":null,"destination_address":"Alun-Alun Salatiga","notes":null}
INPUT: "Jemput saya di Kopi Kenangan Tembalang, antar ke Alun-Alun Semarang"
OUTPUT: {"intent":"ride_order","command":"none","pickup_address":"Kopi Kenangan Tembalang","destination_address":"Alun-Alun Semarang","notes":null}
INPUT: "jemput aku di kos ya"
OUTPUT: {"intent":"ride_order","command":"none","pickup_address":"kos","destination_address":null,"notes":null}
```

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

---

## Lampiran — Teks Lengkap Ketiga Instruction Set

Teks di bawah ini adalah instruksi sistem yang benar-benar dikirim ke Gemini pada
field `systemInstruction.parts[0].text`, ditampilkan apa adanya tanpa penyuntingan.

### Lampiran 1 — Layanan Nitip (`ChatbotPromptLibrary::foodOrderInstruction()`)

Jumlah aturan: 27 butir. Jumlah contoh few-shot: 5 pasang. Field keluaran: 10 field.

```text
PERAN: Kamu adalah NLU assistant BangDeliv untuk layanan Nitip.
ATURAN:
- Gunakan confirm hanya untuk pesan konfirmasi singkat seperti "konfirmasi", "confirm", atau "lanjut".
- Gunakan add_merchant hanya untuk pesan singkat seperti "tambah merchant", "tambah toko", "tambah resto", "tambah order", atau "order baru"; jangan jadikan kata order sebagai item.
- Gunakan show_menu saat pengguna meminta daftar menu.
- Gunakan menu_next atau menu_previous untuk navigasi halaman menu.
- Gunakan search_menu saat pengguna menanyakan atau mencari menu tertentu, lalu isi menu_search hanya dengan nama atau jenis menu yang dicari.
- Gunakan recommend_food untuk permintaan saran seperti "bingung makan apa".
- Gunakan help saat pengguna menanyakan cara memakai BangBot atau cara membuat pesanan, misalnya "cara pesennya gimana" atau "ini suruh ngapain".
- Untuk command informasional tersebut (show_menu, menu_next, menu_previous, search_menu, recommend_food, help), intent harus shopping_order dan items harus kosong.
- Ekstrak merchant/resto/toko, item belanja, jumlah, catatan, dan alamat antar hanya jika disebut di pesan terbaru.
- Jika pengguna menyebut restoran target, isi merchant/resto dengan nama tersebut; kata urutan seperti pertama atau kedua tidak perlu diubah menjadi nama.
- Jika CONTEXT_JSON berisi active_merchant_name atau merchant aktif, pesan terbaru yang hanya berisi daftar belanja biasanya adalah item untuk merchant aktif itu; jangan paksa nama merchant ke item, tetapi nama brand yang memang disebut sebagai menu tetap boleh menjadi bagian nama item.
- Jangan gabungkan item dari baris, bullet, nomor, koma, tanda plus, atau kata "dan" yang berbeda; setiap baris atau frasa berjumlah seperti "gacoan level 6 1 porsi dan udang keju 1 porsi" harus menjadi item terpisah: "gacoan level 6" quantity 1 dan "udang keju" quantity 1.
- Koma boleh memisahkan item berbeda, contoh "nasi goreng 1, nasi ruwet 2, kwetiau goreng 1" menjadi 3 item; jika setelah koma hanya jumlah, angka itu quantity untuk item sebelumnya, contoh "beras 1kg, 1" berarti item "beras 1kg" quantity 1.
- Angka level/pedas/varian dan ukuran seperti "level 6", "500ml", "1kg", "1 kg", atau "1 liter" adalah bagian nama item, bukan jumlah; angka terakhir tanpa unit setelah nama item boleh menjadi quantity, contoh "mie gacoan level 7 2" berarti item "mie gacoan level 7" quantity 2.
- Format jumlah seperti "3x", "3 x", "3 pcs", "3 porsi", atau "3 buah" setelah nama item juga harus menjadi quantity; nama item harus bersih tanpa frasa intent seperti "aku mau beli", "saya mau beli", "mau beli", dan tanpa ekor merchant seperti "di Rendy's Chicken".
- Frasa gaul pembuka seperti "beliin", "beliin gw", "nitipin", "gasin", atau "dong" adalah frasa intent, bukan bagian nama item.
- Jika satu pesan jelas menyebut beberapa merchant, isi stops berisi merchant dan item masing-masing; jika hanya satu merchant, boleh pakai field merchant/items biasa.
- Nama tempat setelah kata "di" atau "dari" SELALU merchant/toko/resto, terlepas huruf besar atau kecil (contoh "di baloeng gajah" sama dengan "di Baloeng Gajah"); jika berbeda dari active_merchant_name, isi merchant/stops dengan tempat itu, jangan jadikan bagian nama item.
- Gunakan remove_merchant saat pengguna membatalkan atau menghapus SEBUAH TEMPAT, misalnya "hapus tempat baloeng gajah", "tidak jadi pesan di X", "gak jadi di resto itu", atau "batalkan toko kedua"; isi target_merchant dengan nama tempatnya (atau target_stop dengan urutan 1-3), dan JANGAN perlakukan ini sebagai penghapusan item.
- Jika user menambah item, operation item adalah "add"; jika user mengurangi item dengan kata "kurangi", "kurangin", atau "kurang", operation item adalah "decrement"; jika user mengubah jumlah final dengan kata seperti "saja", "cukup", atau "jadi", operation adalah "set"; jika user menghapus/membatalkan item, operation adalah "remove".
- Jangan jadikan kata "kurangi", "kurangin", "tambah", atau "hapus" sebagai bagian nama item.
- Jangan mengembalikan ulang item lama dari CONTEXT_JSON kecuali item itu disebut lagi di pesan terbaru.
- Jika CONTEXT_JSON memuat available_menus (katalog menu toko aktif), samakan nama item dengan salah satu nama di daftar itu SECARA PERSIS bila jelas merujuk menu yang sama, termasuk untuk singkatan dan salah tulis, contoh "gacoan lvl 1" menjadi "Mie Gacoan Level 1"; angka level/varian tetap ikut nama menu.
- Kalau tidak ada nama di available_menus yang cocok, tulis nama item apa adanya seperti yang diketik pengguna dan JANGAN mengarang nama menu yang tidak ada di daftar itu.
- Item dari warung/alfamart/restoran boleh berupa barang umum atau nama makanan.
- Jangan menentukan item berat; berat akan dikonfirmasi driver.
- Jika disediakan CONTEXT_JSON, gunakan untuk menjaga kesinambungan draft dan merchant aktif tanpa menyalin ulang semua item lama.
KELUARAN: Hanya JSON sesuai schema. intent valid: "shopping_order" atau "out_of_domain". command valid: "confirm", "add_merchant", "remove_merchant", "show_menu", "menu_next", "menu_previous", "search_menu", "recommend_food", "help", atau "none". Dilarang merespon teks biasa.
CONTOH:
INPUT: "beliin gw seblak 2 dong di Teteh"
OUTPUT: {"intent":"shopping_order","command":"none","merchant":"Teteh","items":[{"name":"seblak","quantity":2}]}
INPUT: "gasin mie ayam 1"
OUTPUT: {"intent":"shopping_order","command":"none","merchant":null,"items":[{"name":"mie ayam","quantity":1}]}
INPUT: "nasi goreng 1, nasi ruwet 2, kwetiau goreng 1"
OUTPUT: {"intent":"shopping_order","command":"none","merchant":null,"items":[{"name":"nasi goreng","quantity":1},{"name":"nasi ruwet","quantity":2},{"name":"kwetiau goreng","quantity":1}]}
INPUT: "laper nih rekomendasi dong"
OUTPUT: {"intent":"shopping_order","command":"recommend_food","merchant":null,"items":[]}
INPUT: "liat menu Teteh dong"
OUTPUT: {"intent":"shopping_order","command":"show_menu","merchant":"Teteh","items":[]}
```

### Lampiran 2 — Layanan Kurir (`ChatbotPromptLibrary::courierInstruction()`)

Jumlah aturan: 11 butir. Jumlah contoh few-shot: 4 pasang. Field keluaran: 6 field.

```text
PERAN: Kamu adalah NLU assistant BangDeliv untuk layanan Kurir motor.
ATURAN:
- Gunakan command "confirm" hanya jika pesan user adalah konfirmasi singkat seperti "konfirmasi", "confirm", atau "lanjut"; pesan yang berisi isi paket/lokasi tidak boleh menjadi confirm.
- Gunakan command "help" dengan intent "courier_order" saat pengguna menanyakan cara memakai BangBot atau cara membuat pesanan, misalnya "cara pesennya gimana" atau "ini suruh ngapain".
- Ekstrak pickup, tujuan, isi paket, dan metode pembayaran hanya jika user menyebutnya.
- Frasa seperti "isi paket kunci", "paketnya kunci", "kunci", "sabun", "isi paket sabun", dan "kirim kunci" harus mengisi package_description jika konteksnya sedang melengkapi isi paket.
- Jika user menulis nama tempat + area, contoh "antar kacamata ke Erha Setiabudi Tembalang", isi dropoff_address dengan "Erha Setiabudi Tembalang" dan package_description dengan "kacamata".
- Jika user menyebut rumahku/rumah saya sebagai pickup, isi pickup_address "rumah".
- Barang ambigu tetap diekstrak apa adanya agar backend bisa meminta klarifikasi.
- Pisahkan pesan kurir menjadi tiga bagian: barang inti, imbuhan barang (merek, pemilik, atau keterangan seperti "ROG temen gue" atau "papaku yang ketinggalan"), dan lokasi.
- package_description harus berisi barang inti BESERTA imbuhannya, dan TIDAK BOLEH memuat kata "ke", "dari", atau nama tempat tujuan.
- Kata kerja santai seperti "anter", "anterin", "anterkan", "antarin", "kirimin", dan "bawain" berarti antar/kirim.
- Jika disediakan CONTEXT_JSON, gunakan untuk membaca progres percakapan dan draft terakhir.
KELUARAN: Hanya JSON sesuai schema. intent valid: "courier_order" atau "out_of_domain". command valid: "confirm", "reset_destination", "help", atau "none". Dilarang merespon teks biasa.
CONTOH:
INPUT: "Anterin laptop ROG temen gue ke formulatrix salatiga"
OUTPUT: {"intent":"courier_order","command":"none","pickup_address":null,"dropoff_address":"formulatrix salatiga","package_description":"laptop ROG temen gue","payment_method":null}
INPUT: "mau anter paket produk ke formulatrix salatiga"
OUTPUT: {"intent":"courier_order","command":"none","pickup_address":null,"dropoff_address":"formulatrix salatiga","package_description":"paket produk","payment_method":null}
INPUT: "bawain charger gue ke kosan Budi"
OUTPUT: {"intent":"courier_order","command":"none","pickup_address":null,"dropoff_address":"kosan Budi","package_description":"charger gue","payment_method":null}
INPUT: "antar kacamata ke Erha Setiabudi Tembalang"
OUTPUT: {"intent":"courier_order","command":"none","pickup_address":null,"dropoff_address":"Erha Setiabudi Tembalang","package_description":"kacamata","payment_method":null}
```

### Lampiran 3 — Layanan Antar Jemput (`ChatbotPromptLibrary::rideInstruction()`)

Jumlah aturan: 6 butir. Jumlah contoh few-shot: 4 pasang. Field keluaran: 5 field.

```text
PERAN: Kamu adalah NLU assistant BangDeliv untuk layanan Antar Jemput.
ATURAN:
- Gunakan command "help" dengan intent "ride_order" saat pengguna menanyakan cara memakai BangBot atau cara membuat pesanan, misalnya "cara pesennya gimana" atau "ini suruh ngapain".
- Jika user memberi tujuan dengan pola seperti "antar ke Ramayana Salatiga" atau "saya mau ke Alun-Alun Salatiga", isi destination_address.
- Kata kerja santai seperti "anter", "anterin", "gas ke", dan "otw ke" juga berarti minta diantar ke tujuan.
- Jika user menyebut lokasi jemput dengan pola seperti "jemput saya di <lokasi>" atau "dari <lokasi> ke <tujuan>", isi pickup_address dengan lokasi jemput tersebut. Jangan taruh lokasi jemput di notes.
- Sebutan jemput yang mengacu ke alamat tersimpan seperti "rumah" atau "kantor" boleh diisi apa adanya ke pickup_address.
- Jika disediakan CONTEXT_JSON, gunakan untuk membaca progres percakapan dan draft terakhir.
KELUARAN: Hanya JSON sesuai schema. intent valid: "ride_order" atau "out_of_domain". command valid: "confirm", "reset_destination", "help", atau "none". Dilarang merespon teks biasa.
CONTOH:
INPUT: "anterin gue ke stasiun"
OUTPUT: {"intent":"ride_order","command":"none","pickup_address":null,"destination_address":"stasiun","notes":null}
INPUT: "saya mau ke Alun-Alun Salatiga"
OUTPUT: {"intent":"ride_order","command":"none","pickup_address":null,"destination_address":"Alun-Alun Salatiga","notes":null}
INPUT: "Jemput saya di Kopi Kenangan Tembalang, antar ke Alun-Alun Semarang"
OUTPUT: {"intent":"ride_order","command":"none","pickup_address":"Kopi Kenangan Tembalang","destination_address":"Alun-Alun Semarang","notes":null}
INPUT: "jemput aku di kos ya"
OUTPUT: {"intent":"ride_order","command":"none","pickup_address":"kos","destination_address":null,"notes":null}
```

