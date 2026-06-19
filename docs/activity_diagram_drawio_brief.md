# Brief Activity Diagram BangDeliv untuk Draw.io

Dokumen ini berisi best practice dan spesifikasi activity diagram BangDeliv dalam
bentuk teks. Tujuannya agar nanti bisa diberikan ke Codex lain untuk dibuatkan
diagram visual di Draw.io/diagrams.net melalui extension Chrome.

Referensi utama dokumen ini:

- `docs/use_case_bangdeliv.md`
- `docs/shopping_nitip_flow_rework.md`
- `docs/backend_architecture.md`
- `docs/frontend_architecture.md`

## 1. Tujuan Activity Diagram

Activity diagram dipakai untuk menjelaskan alur kerja sistem dari sudut pandang
proses, bukan struktur database atau class.

Diagram harus menjawab:

- siapa aktor yang melakukan aktivitas;
- kapan sistem melakukan validasi;
- kapan data berubah;
- keputusan apa yang membuat alur bercabang;
- kondisi sukses dan gagal;
- status akhir proses.

Untuk BangDeliv, activity diagram paling penting adalah:

1. Customer membuat order.
2. Driver menerima dan menjalankan order.
3. Flow khusus Nitip multi-merchant.
4. Payment dan revisi ongkir.
5. Verifikasi driver oleh admin.

## 2. Best Practice Activity Diagram

### 2.1 Pakai Swimlane

Gunakan swimlane agar tanggung jawab jelas.

Swimlane yang disarankan:

- `Customer`
- `Frontend Flutter`
- `Backend Laravel`
- `Driver`
- `Admin`
- `External Services`

Tidak semua diagram harus memakai semua swimlane. Pilih hanya yang relevan agar
diagram tidak terlalu ramai.

Contoh:

- Diagram order Ride cukup memakai `Customer`, `Frontend Flutter`,
  `Backend Laravel`, `Driver`, `External Services`.
- Diagram verifikasi driver memakai `Driver`, `Frontend Flutter`,
  `Backend Laravel`, `Admin`.
- Diagram CRUD merchant cukup memakai `Admin`, `Backend Laravel`.

### 2.2 Mulai dari Initial Node dan Akhiri dengan Final Node

Setiap diagram harus punya:

- initial node: titik mulai proses;
- final node: titik akhir sukses atau terminal.

Untuk alur yang punya akhir gagal, boleh pakai lebih dari satu final node, tetapi
beri label yang jelas:

- `Order berhasil dibuat`
- `Order dibatalkan`
- `Validasi gagal`
- `Driver aktif`

### 2.3 Gunakan Decision Node untuk Kondisi

Gunakan decision node berbentuk diamond untuk kondisi seperti:

- user sudah login?
- alamat valid?
- payment sudah paid?
- driver menerima order?
- merchant buka?
- item tersedia?
- ongkir sudah disetujui?

Label guard harus singkat dan eksplisit:

```text
[valid]
[tidak valid]
[merchant buka]
[merchant tutup]
[payment paid]
[payment belum paid]
```

Jangan menulis kondisi panjang di dalam diamond. Kondisi panjang ditaruh sebagai
catatan.

### 2.4 Pisahkan Happy Path dan Alternative Path

Happy path harus mudah diikuti dari atas ke bawah atau kiri ke kanan.

Alternative path ditaruh sebagai cabang samping:

- validasi gagal;
- user batal;
- payment belum paid;
- merchant tutup;
- item tidak tersedia;
- driver reject order.

Jika satu diagram terlalu banyak cabang, buat diagram terpisah.

### 2.5 Jangan Campur Detail Teknis Berlebihan

Activity diagram bukan sequence diagram. Hindari detail seperti:

- nama function terlalu banyak;
- response JSON lengkap;
- query database;
- nama provider Flutter;
- nama file internal.

Boleh mencantumkan service utama sebagai catatan kecil:

- `OrderService`
- `ShoppingPricingService`
- `DriverOrderPayloadFactory`
- `ShoppingOrderCapabilityService`

### 2.6 Gunakan Level Abstraksi yang Konsisten

Dalam satu diagram, semua activity harus berada di level yang sama.

Contoh yang baik:

```text
Customer memilih merchant
Backend validasi rute
Driver mengirim harga merchant
Customer menyetujui harga
```

Contoh yang terlalu campur:

```text
Customer klik tombol
Controller memanggil service
Service query order_locations
Driver menerima order
```

Jika ingin menjelaskan teknis backend, buat diagram teknis terpisah.

### 2.7 Simbol yang Disarankan

| Simbol | Fungsi |
| --- | --- |
| Filled circle | Initial node / mulai |
| Rounded rectangle | Activity/action |
| Diamond | Decision/merge |
| Thick bar | Fork/join untuk aktivitas paralel |
| Bullseye/final circle | Final node / selesai |
| Note | Catatan rule bisnis |
| Swimlane | Pemisah aktor/sistem |

### 2.8 Penamaan Activity

Gunakan kalimat aktif dan ringkas.

Format:

```text
Aktor + aksi + objek
```

Contoh:

- `Customer memilih alamat antar`
- `Backend memvalidasi rute`
- `Driver menyimpan ketersediaan item`
- `Admin menyetujui dokumen driver`

Hindari label ambigu:

- `Proses data`
- `Validasi`
- `Update`
- `Submit`

### 2.9 Warna yang Disarankan di Draw.io

Gunakan warna konsisten agar diagram mudah dibaca.

| Elemen | Warna |
| --- | --- |
| Customer | Biru muda `#E8F2FF` |
| Driver | Hijau muda `#EAF8EF` |
| Admin | Ungu muda `#F2ECFF` |
| Frontend Flutter | Oranye muda `#FFF1E8` |
| Backend Laravel | Kuning muda `#FFF8D8` |
| External Services | Abu muda `#F3F4F6` |
| Error/blocked path | Merah muda `#FEE2E2` |
| Final success | Hijau `#DCFCE7` |

Primary BangDeliv tetap boleh memakai orange `#F05B24` untuk garis utama atau
judul diagram.

### 2.10 Ukuran Diagram

Untuk laporan TA, satu diagram sebaiknya muat dalam satu halaman A4 landscape.

Rule praktis:

- maksimal 6 swimlane per diagram;
- maksimal 25 activity node per diagram;
- jika lebih dari 25 node, pecah menjadi subdiagram;
- gunakan nomor diagram, misalnya `AD-01`, `AD-02`.

## 3. Daftar Activity Diagram yang Disarankan

### AD-01 - Registrasi, Login, dan Routing Role

Tujuan:

Menjelaskan bagaimana user masuk sistem dan diarahkan ke halaman sesuai role.

Swimlane:

- `User`
- `Frontend Flutter`
- `Backend Laravel`

Output diagram:

- Customer masuk ke home.
- Driver aktif masuk ke driver home.
- Driver belum aktif masuk ke status verifikasi.
- Admin masuk ke admin/profile sesuai akses.

### AD-02 - Customer Mengelola Alamat

Tujuan:

Menjelaskan proses simpan/update alamat customer, termasuk validasi area layanan
dan map picker.

Swimlane:

- `Customer`
- `Frontend Flutter`
- `Backend Laravel`
- `External Services`

External services:

- Google Maps Geocoding
- Places/map picker

### AD-03 - Customer Membuat Order Ride

Tujuan:

Menjelaskan alur order Antar Jemput dari input pickup/destination sampai order
pending driver.

Swimlane:

- `Customer`
- `Frontend Flutter`
- `Backend Laravel`
- `External Services`

Decision utama:

- alamat valid?
- pickup dan destination terlalu dekat?
- route/pricing berhasil?

### AD-04 - Customer Membuat Order Courier lewat Chatbot

Tujuan:

Menjelaskan flow Kurir dari chat natural language sampai order dibuat.

Swimlane:

- `Customer`
- `Frontend Flutter`
- `Backend Laravel`
- `External Services`

Decision utama:

- pickup/dropoff lengkap?
- paket valid?
- perlu map picker?
- customer konfirmasi?

### AD-05 - Customer Membuat Order Nitip Multi-Merchant lewat Chatbot

Tujuan:

Menjelaskan flow Nitip dari pilih merchant, tambah item, tambah merchant lain,
pilih pembayaran, sampai order dibuat.

Swimlane:

- `Customer`
- `Frontend Flutter`
- `Backend Laravel`
- `External Services`

Decision utama:

- alamat antar tersedia?
- merchant sudah dipilih?
- jumlah merchant kurang dari 3?
- item merchant lengkap?
- rute maksimal 50 km?
- payment method sudah dipilih?
- customer konfirmasi?

### AD-06 - Driver Menerima atau Menolak Order

Tujuan:

Menjelaskan dispatch order ke driver, driver accept/reject, dan efek ke status
order.

Swimlane:

- `Backend Laravel`
- `Driver`
- `Frontend Flutter`
- `Customer`

Decision utama:

- driver online?
- order masih pending?
- driver accept?
- order sudah diterima driver lain?

### AD-07 - Driver Menjalankan Order Ride

Tujuan:

Menjelaskan lifecycle Ride dari driver assigned sampai completed.

Swimlane:

- `Driver`
- `Frontend Flutter`
- `Backend Laravel`
- `Customer`

Status utama:

```text
DRIVER_ASSIGNED
ARRIVED_PICKUP
ON_THE_WAY
ARRIVED_DROPOFF
DELIVERED
COMPLETED
```

Decision utama:

- payment sudah paid?
- ada revisi ongkir pending?

### AD-08 - Driver Menjalankan Order Courier

Tujuan:

Menjelaskan lifecycle Courier dari pickup paket sampai delivered/completed.

Swimlane:

- `Driver`
- `Frontend Flutter`
- `Backend Laravel`
- `Customer`

Status utama:

```text
DRIVER_ASSIGNED
ARRIVED_PICKUP
PICKED_UP
ON_THE_WAY
ARRIVED_DROPOFF
DELIVERED
COMPLETED
```

Decision utama:

- paket valid?
- payment pickup sudah paid?
- proof dibutuhkan?
- payment final sudah paid?

### AD-09 - Driver Menjalankan Order Nitip Multi-Merchant

Tujuan:

Menjelaskan lifecycle Nitip dari tiba merchant, proses tiap merchant, checkout,
antar ke customer, sampai completed.

Swimlane:

- `Driver`
- `Frontend Flutter`
- `Backend Laravel`
- `Customer`

Status order utama:

```text
DRIVER_ASSIGNED
ARRIVED_MERCHANT
PICKED_UP
ON_THE_WAY
ARRIVED_DROPOFF
DELIVERED
COMPLETED
```

Status merchant:

```text
PENDING
OPEN_CONFIRMED
ITEMS_PENDING_CUSTOMER
ITEMS_CONFIRMED
PRICE_PENDING_CUSTOMER
PRICE_APPROVED
FAILED
COMPLETED
```

Decision utama:

- merchant buka?
- item tersedia?
- customer sudah memutuskan item unavailable?
- harga merchant disetujui?
- semua merchant selesai/terminal?
- payment sudah paid?

### AD-10 - Customer Memutuskan Item Nitip Tidak Tersedia

Tujuan:

Menjelaskan flow saat driver menandai item tidak tersedia dan customer harus
memilih edit, lanjut tanpa item, atau batal merchant.

Swimlane:

- `Customer`
- `Frontend Flutter`
- `Backend Laravel`
- `Driver`

Decision utama:

- merchant masih punya item aktif lain?
- customer memilih edit?
- customer memilih lanjut tanpa item?
- customer memilih batal merchant?

Rule penting:

- Jika merchant hanya punya satu item dan item itu tidak tersedia, jangan
  tampilkan `Lanjut tanpa ini`.
- Edit item langsung berlaku tanpa approval driver.

### AD-11 - Revisi Ongkir dan Approval Customer

Tujuan:

Menjelaskan driver mengajukan revisi ongkir, customer merespons, dan efeknya ke
progress order.

Swimlane:

- `Driver`
- `Frontend Flutter`
- `Backend Laravel`
- `Customer`

Decision utama:

- order masih editable?
- customer setuju?
- customer counter?
- customer batal?
- driver accept counter?

Rule khusus Nitip:

- Setelah ongkir disetujui customer, ongkir menjadi locked.
- Jika merchant tutup setelah ongkir locked, jangan kalkulasi ulang ongkir dari
  route kosong.

### AD-12 - Payment COD dan Transfer/QRIS

Tujuan:

Menjelaskan alur pembayaran COD dan transfer/QRIS sampai order boleh completed.

Swimlane:

- `Customer`
- `Driver`
- `Frontend Flutter`
- `Backend Laravel`
- `Admin`

Decision utama:

- payment method COD atau TRANSFER?
- customer sudah upload bukti?
- driver/admin sudah konfirmasi?
- payment paid?

### AD-13 - Driver Upload Dokumen dan Admin Verifikasi

Tujuan:

Menjelaskan flow driver menjadi aktif melalui upload dan review dokumen.

Swimlane:

- `Driver`
- `Frontend Flutter`
- `Backend Laravel`
- `Admin`

Decision utama:

- dokumen lengkap?
- admin approve semua dokumen?
- ada dokumen ditolak?

Output:

- driver active;
- driver pending;
- driver rejected/perlu upload ulang.

### AD-14 - Admin Kelola Merchant dan Menu

Tujuan:

Menjelaskan proses admin CRUD merchant dan menu.

Swimlane:

- `Admin`
- `Backend Laravel`

Decision utama:

- data valid?
- slug/menu valid?
- merchant aktif/inaktif?

### AD-15 - Realtime Tracking dan Chat

Tujuan:

Menjelaskan bagaimana update status, lokasi driver, dan chat diterima secara
realtime.

Swimlane:

- `Customer`
- `Driver`
- `Frontend Flutter`
- `Backend Laravel`
- `External Services`

External services:

- Laravel Reverb/WebSocket
- FCM untuk push notification

Decision utama:

- user authorized channel?
- WebSocket connected?
- push token valid?

## 4. Detail Alur Diagram Prioritas

Bagian ini memberi detail step yang bisa langsung dipakai untuk menggambar
diagram.

## AD-05 Detail - Customer Membuat Order Nitip Multi-Merchant

### Swimlane

1. `Customer`
2. `Frontend Flutter`
3. `Backend Laravel`
4. `External Services`

### Alur Utama

1. Start.
2. Customer membuka BangBot AI - Nitip.
3. Frontend membuat/memakai session chatbot.
4. Customer mengirim kebutuhan belanja atau memilih tombol pilih merchant.
5. Backend memeriksa alamat antar customer.
6. Decision: alamat antar tersedia dan valid?
7. Jika tidak valid:
   - Backend mengirim action isi/pilih alamat.
   - Customer memilih alamat atau map pin.
   - Frontend patch lokasi ke session chatbot.
   - Kembali ke validasi alamat.
8. Jika valid:
   - Backend mengirim action pilih merchant.
9. Customer membuka merchant map picker.
10. External Services menyediakan Places/map result.
11. Customer memilih merchant.
12. Frontend patch merchant ke session chatbot.
13. Backend menyimpan merchant sebagai stop aktif.
14. Backend mengirim contoh format item untuk merchant aktif.
15. Customer mengetik daftar item.
16. Backend menambahkan item ke merchant aktif.
17. Backend memvalidasi draft merchant.
18. Decision: customer ingin tambah merchant lain?
19. Jika ya:
   - Decision: jumlah merchant kurang dari 3?
   - Jika tidak, Backend menolak merchant tambahan.
   - Jika ya, Customer memilih merchant berikutnya.
   - Kembali ke step pilih merchant.
20. Jika tidak:
   - Backend validasi rute semua merchant ke alamat antar.
21. External Services menghitung jarak/rute.
22. Decision: rute maksimal 50 km?
23. Jika tidak:
   - Backend menolak draft dan meminta revisi merchant/alamat.
24. Jika ya:
   - Backend menghitung estimasi ongkir dan biaya layanan.
25. Decision: payment method sudah dipilih?
26. Jika belum:
   - Backend mengirim action pilih COD/TRANSFER.
   - Customer memilih payment method.
27. Backend menampilkan draft confirmable.
28. Customer mengirim konfirmasi.
29. Backend membuat order `SHOPPING`.
30. Backend menyimpan `order_locations`, `shopping_order_items`, fee lines, dan
    event audit.
31. Backend broadcast order ke driver available.
32. Frontend membuka tracking order.
33. Final: order Nitip dibuat.

### Alternative Path

#### Merchant tidak ditemukan

1. Backend tidak menemukan merchant DB.
2. Jika payload Google Places lengkap, merchant disimpan sebagai external
   merchant stop.
3. Jika payload tidak lengkap, backend meminta customer pilih merchant via map.

#### Item belum lengkap

1. Backend mendeteksi merchant punya item kosong.
2. Bot menampilkan contoh format item.
3. Customer melengkapi item.

#### Customer membatalkan draft

1. Customer clear session.
2. Backend arsipkan session chatbot.
3. Final: draft dibatalkan.

### Catatan Draw.io

- Gunakan loop visual dari "Tambah merchant lain?" kembali ke "Pilih merchant".
- Gunakan note di dekat decision jumlah merchant:

```text
Maksimal 3 merchant per order Nitip.
```

- Gunakan note di dekat validasi rute:

```text
Backend menjadi sumber validasi rute 50 km.
```

## AD-09 Detail - Driver Menjalankan Order Nitip Multi-Merchant

### Swimlane

1. `Driver`
2. `Frontend Flutter`
3. `Backend Laravel`
4. `Customer`

### Alur Utama

1. Start.
2. Driver menerima order Nitip.
3. Driver menuju merchant.
4. Driver menekan `Tiba di Toko / Merchant`.
5. Backend mengubah status order ke `ARRIVED_MERCHANT`.
6. Frontend menampilkan daftar merchant.
7. Driver memilih merchant yang akan diproses.
8. Decision: merchant buka?
9. Jika merchant tutup:
   - Driver menekan `Resto tutup`.
   - Backend menandai merchant `FAILED`.
   - Backend menyimpan event merchant closed.
   - Decision: masih ada merchant aktif?
   - Jika ya, kembali ke pilih merchant.
   - Jika tidak, lanjut ke flow semua merchant terminal.
10. Jika merchant buka:
    - Driver menekan `Resto buka`.
    - Backend menandai merchant `OPEN_CONFIRMED`.
11. Driver mengecek item merchant.
12. Driver menyimpan ketersediaan item.
13. Decision: semua item tersedia?
14. Jika ada item tidak tersedia:
    - Backend menandai merchant `ITEMS_PENDING_CUSTOMER`.
    - Customer menerima decision card.
    - Masuk ke AD-10.
    - Setelah customer memutuskan, kembali ke validasi item merchant.
15. Jika item fix:
    - Backend menandai merchant `ITEMS_CONFIRMED`.
16. Driver mengirim harga merchant.
17. Backend menandai merchant `PRICE_PENDING_CUSTOMER`.
18. Customer menerima card harga merchant.
19. Decision: customer menyetujui harga?
20. Jika customer setuju:
    - Backend menandai merchant `PRICE_APPROVED`.
21. Jika customer batal merchant:
    - Backend menandai merchant `FAILED`.
22. Jika customer tidak merespons:
    - Driver dapat memilih bypass.
    - Backend mencatat audit bypass.
    - Backend menandai merchant `PRICE_APPROVED`.
23. Decision: semua merchant selesai atau terminal?
24. Jika belum:
    - Driver memilih merchant berikutnya.
25. Jika sudah:
    - Frontend menampilkan upload struk opsional.
    - Driver upload struk jika ada.
26. Driver menekan `Belanja Selesai`.
27. Backend mengubah order ke `PICKED_UP`.
28. Driver menekan `Menuju Customer`.
29. Backend mengubah order ke `ON_THE_WAY`.
30. Driver tiba di customer.
31. Backend mengubah order ke `ARRIVED_DROPOFF`.
32. Driver menyerahkan barang.
33. Backend mengubah order ke `DELIVERED`.
34. Decision: payment paid?
35. Jika belum paid:
    - Customer upload QRIS/transfer atau driver catat COD.
    - Backend verifikasi payment.
36. Jika paid:
    - Driver menekan `Selesaikan Order`.
    - Backend mengubah order ke `COMPLETED`.
37. Final: order Nitip selesai.

### Alternative Path: Semua Merchant Tutup

1. Semua merchant menjadi `FAILED`.
2. Backend menentukan cancellation status.
3. Jika ongkir sudah locked, backend mempertahankan ongkir final terakhir.
4. Backend tidak menghitung route kosong sebagai ongkir baru.
5. Order menjadi `CANCELLED_WITH_FEE` atau terminal sesuai rule.
6. Driver menyelesaikan order setelah payment/cancel fee valid.

### Catatan Draw.io

- Buat sub-process "Proses satu merchant" agar diagram tidak terlalu panjang.
- Gunakan loop dari "Semua merchant selesai/terminal?" kembali ke "Pilih
  merchant berikutnya".
- Tambahkan note:

```text
Driver bebas memproses merchant dalam urutan lapangan.
Urutan route hanya rekomendasi.
```

## AD-10 Detail - Customer Memutuskan Item Nitip Tidak Tersedia

### Swimlane

1. `Customer`
2. `Frontend Flutter`
3. `Backend Laravel`
4. `Driver`

### Alur Utama

1. Start.
2. Driver menyimpan availability item.
3. Backend menemukan item tidak tersedia.
4. Backend mengubah merchant ke `ITEMS_PENDING_CUSTOMER`.
5. Frontend customer menampilkan item tidak tersedia.
6. Backend mengirim capability action:
   - `can_edit`
   - `can_continue_without_item`
   - `can_cancel_merchant`
7. Decision: merchant hanya punya satu item aktif?
8. Jika ya:
   - Frontend hanya menampilkan `Edit` dan `Batal merchant`.
9. Jika tidak:
   - Frontend menampilkan `Edit`, `Lanjut tanpa ini`, dan `Batal merchant`.
10. Decision: customer memilih aksi apa?

### Cabang Edit

1. Customer memilih `Edit`.
2. Frontend membuka screen tambah item dengan merchant readonly.
3. Customer memasukkan item pengganti.
4. Frontend mengirim item ke backend.
5. Backend langsung menerapkan item baru.
6. Backend menghapus item lama yang tidak tersedia.
7. Backend mengubah merchant ke `ITEMS_CONFIRMED`.
8. Backend mencatat harga perlu quote ulang.
9. Driver melihat item final dan card harga merchant.
10. Final: item decision selesai.

### Cabang Lanjut Tanpa Ini

1. Customer memilih `Lanjut tanpa ini`.
2. Backend memvalidasi merchant masih punya item aktif lain.
3. Backend menghapus item tidak tersedia.
4. Backend mengubah merchant ke `ITEMS_CONFIRMED`.
5. Backend mencatat harga perlu quote ulang.
6. Final: item decision selesai.

### Cabang Batal Merchant

1. Customer memilih `Batal merchant`.
2. Backend menandai merchant `FAILED`.
3. Backend menghapus item merchant dari perhitungan aktif.
4. Decision: masih ada merchant aktif?
5. Jika ya, driver lanjut merchant lain.
6. Jika tidak, order masuk flow semua merchant terminal.
7. Final: merchant dibatalkan.

### Rule Penting

- Edit item tidak perlu approval driver.
- `Lanjut tanpa ini` tidak boleh tersedia jika merchant akan kosong.
- Driver tidak boleh mengubah availability item merchant yang harga sudah
  disetujui.

## AD-11 Detail - Revisi Ongkir dan Approval Customer

### Swimlane

1. `Driver`
2. `Frontend Flutter`
3. `Backend Laravel`
4. `Customer`

### Alur Utama

1. Start.
2. Driver membuka order aktif.
3. Driver memilih `Revisi Ongkir`.
4. Driver mengisi nominal dan alasan.
5. Frontend mengirim request revisi.
6. Backend memvalidasi order, driver, status, payment, dan nominal.
7. Decision: revisi boleh diajukan?
8. Jika tidak:
   - Backend mengirim error.
   - Frontend menampilkan pesan.
   - Final: revisi gagal.
9. Jika ya:
   - Backend mencatat event revisi ongkir.
   - Backend mengirim notifikasi ke customer.
10. Customer membuka tracking.
11. Customer melihat card revisi ongkir.
12. Decision: customer memilih apa?

### Cabang Setuju

1. Customer menekan setuju.
2. Backend mencatat approval.
3. Backend memperbarui ongkir order.
4. Jika service `SHOPPING`, backend mengunci ongkir final.
5. Driver dapat melanjutkan progress.
6. Final: revisi ongkir disetujui.

### Cabang Counter

1. Customer mengirim nominal tawaran.
2. Backend mencatat counter.
3. Driver melihat tawaran customer.
4. Decision: driver setuju counter?
5. Jika ya, backend mencatat approval.
6. Jika tidak, driver mengirim revisi baru.

### Cabang Batal

1. Customer memilih batal order.
2. Backend membatalkan order sesuai rule.
3. Backend broadcast status terminal.
4. Final: order dibatalkan.

### Rule Khusus Nitip

Tambahkan note di diagram:

```text
Jika ongkir Nitip sudah approved, merchant tutup tidak boleh memicu ongkir baru.
Gunakan ongkir final terakhir.
```

## AD-13 Detail - Driver Upload Dokumen dan Admin Verifikasi

### Swimlane

1. `Driver`
2. `Frontend Flutter`
3. `Backend Laravel`
4. `Admin`

### Alur Utama

1. Start.
2. Customer melakukan upgrade ke driver.
3. Backend membuat driver profile status pending.
4. Driver membuka halaman status verifikasi.
5. Driver upload KTP, SIM, dan selfie.
6. Backend memvalidasi file.
7. Decision: file valid?
8. Jika tidak:
   - Backend mengirim error.
   - Driver upload ulang.
9. Jika valid:
   - Backend menyimpan dokumen.
   - Status dokumen menjadi pending.
10. Admin membuka antrean verifikasi.
11. Admin membuka detail driver.
12. Admin preview dokumen.
13. Admin memberi keputusan tiap dokumen.
14. Decision: semua dokumen approved?
15. Jika ya:
    - Backend mengubah driver menjadi active.
    - Driver dapat online.
16. Jika ada dokumen rejected:
    - Backend mengubah status rejected/pending upload ulang.
    - Driver melihat alasan dan upload ulang.
17. Final: status verifikasi diperbarui.

## 5. Prompt Siap Pakai untuk Codex Pembuat Draw.io

Gunakan prompt berikut saat meminta Codex lain membuat file draw.io.

```text
Saya punya dokumen activity diagram brief di:
D:\AA_Kuliah_Hassan\TA\Backend_Bangdeliv\docs\activity_diagram_drawio_brief.md

Tolong buat activity diagram BangDeliv di Draw.io/diagrams.net melalui Chrome
extension berdasarkan dokumen tersebut.

Prioritaskan diagram:
1. AD-05 Customer Membuat Order Nitip Multi-Merchant
2. AD-09 Driver Menjalankan Order Nitip Multi-Merchant
3. AD-10 Customer Memutuskan Item Nitip Tidak Tersedia
4. AD-11 Revisi Ongkir dan Approval Customer
5. AD-13 Driver Upload Dokumen dan Admin Verifikasi

Gunakan swimlane, warna konsisten, decision node dengan guard condition, dan
pecah diagram jika terlalu panjang. Output akhir berupa file .drawio dan
export PNG/PDF jika memungkinkan.
```

## 6. Checklist Review Diagram

Sebelum diagram dianggap selesai, cek hal berikut:

- Setiap diagram punya initial node dan final node.
- Swimlane aktor tidak terlalu banyak.
- Happy path mudah diikuti.
- Decision node punya guard condition.
- Cabang gagal tidak mengganggu alur utama.
- Status order dan status merchant Nitip tidak tercampur.
- Rule Nitip penting sudah muncul:
  - maksimal 3 merchant;
  - merchant locked setelah confirm;
  - item unavailable diputuskan customer;
  - edit item tidak perlu approval driver;
  - ongkir locked tidak dihitung ulang saat merchant tutup.
- Payment paid menjadi syarat complete order.
- Diagram muat di halaman A4 landscape atau dibagi menjadi subdiagram.
- Warna dan label konsisten.

