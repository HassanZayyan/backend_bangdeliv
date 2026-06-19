# Brief Flowchart BangDeliv untuk Codex

Dokumen ini berisi best practice dan spesifikasi flowchart BangDeliv dalam
bentuk teks. Tujuannya agar Codex lain dapat memahami flow sistem dan mengubahnya
menjadi diagram visual, misalnya di Draw.io/diagrams.net.

Dokumen terkait:

- `docs/use_case_bangdeliv.md`
- `docs/activity_diagram_drawio_brief.md`
- `docs/shopping_nitip_flow_rework.md`
- `docs/backend_architecture.md`
- `docs/database_schema.md`

## 1. Perbedaan Flowchart dan Activity Diagram

Flowchart dan activity diagram sama-sama menjelaskan alur, tetapi fokusnya
berbeda.

| Jenis diagram | Fokus utama | Cocok untuk |
| --- | --- | --- |
| Flowchart | Urutan proses, keputusan, validasi, dan output | Menjelaskan logic flow, algoritma backend, rule pricing, validasi order |
| Activity diagram | Aktivitas antar aktor dengan swimlane | Menjelaskan interaksi Customer, Driver, Admin, Frontend, Backend |

Untuk BangDeliv:

- **Activity diagram** dipakai untuk menjelaskan siapa melakukan apa.
- **Flowchart** dipakai untuk menjelaskan keputusan sistem dan urutan logic.

Contoh:

- Activity diagram Nitip menjelaskan Customer pilih merchant, Driver cek item,
  Backend update status.
- Flowchart Nitip menjelaskan validasi merchant, item, rute, pricing, status
  merchant, dan kondisi terminal.

## 2. Best Practice Flowchart

### 2.1 Satu Tujuan per Diagram

Setiap flowchart harus menjawab satu pertanyaan utama.

Contoh tujuan yang baik:

- Bagaimana sistem menentukan route customer setelah login?
- Bagaimana backend memproses order Ride?
- Bagaimana sistem memutuskan tombol item unavailable?
- Bagaimana pricing Nitip menjaga ongkir locked?

Hindari satu flowchart yang mencoba menjelaskan semua aplikasi dari login sampai
history.

### 2.2 Gunakan Simbol Standar

| Simbol | Nama | Fungsi |
| --- | --- | --- |
| Rounded rectangle / terminator | Start/End | Awal dan akhir flow |
| Rectangle | Process | Aksi/proses normal |
| Diamond | Decision | Percabangan kondisi |
| Parallelogram | Input/Output | Input user atau output response |
| Cylinder | Database/Data store | Simpan/baca data penting |
| Document | Document/Report | Bukti, struk, dokumen driver |
| Predefined process | Sub-process | Flow yang dirinci di diagram lain |
| Connector circle | Connector | Menghubungkan flow yang panjang |

Jika menggunakan Draw.io, pilih shape flowchart standar agar diagram mudah
dibaca dosen.

### 2.3 Label Decision dengan Pertanyaan

Decision node sebaiknya berupa pertanyaan singkat.

Contoh:

```text
Alamat valid?
Driver aktif?
Payment paid?
Merchant buka?
Semua item tersedia?
Ongkir locked?
```

Cabang keluar memakai label:

```text
Ya
Tidak
Valid
Tidak valid
Approve
Reject
```

Jangan memakai label panjang seperti:

```text
Jika order sudah berada di status ARRIVED_MERCHANT dan tidak ada pending request
```

Label panjang dipindah menjadi note.

### 2.4 Jaga Arah Diagram Konsisten

Gunakan satu arah utama:

- atas ke bawah untuk flow bisnis;
- kiri ke kanan untuk flow ringkas;
- hindari alur bolak-balik yang membuat garis silang.

Rekomendasi untuk laporan TA:

- gunakan **atas ke bawah**;
- cabang error ke kanan;
- cabang alternatif ke kiri atau kanan;
- happy path tetap di tengah.

### 2.5 Pisahkan Sub-process

Jika flow terlalu panjang, pecah menjadi sub-process.

Contoh:

- `Proses Merchant Nitip`
- `Validasi Payment`
- `Hitung Pricing Nitip`
- `Kirim Realtime Update`
- `Validasi Paket Courier`

Sub-process boleh digambar sebagai rectangle dengan garis ganda, lalu dibuatkan
flowchart detail terpisah.

### 2.6 Jangan Masukkan Detail Kode Berlebihan

Flowchart boleh menyebut service utama, tetapi tidak perlu menulis isi method
atau query.

Baik:

```text
Backend validasi capability Nitip
Backend hitung pricing
Backend simpan order
```

Terlalu teknis:

```text
OrderService::requestShoppingItemChange()
query order_locations where fulfillment_status = PENDING
call app(ShoppingItemChangeRequestService::class)
```

Nama service boleh ditaruh di note kecil jika penting:

```text
Note: rule berasal dari ShoppingOrderCapabilityService.
```

### 2.7 Tampilkan Error Path yang Penting

Flowchart harus menampilkan error path yang berpengaruh pada user atau status
order.

Contoh error path penting:

- alamat tidak valid;
- driver belum aktif;
- order sudah diterima driver lain;
- payment belum paid;
- merchant tutup;
- item tidak tersedia;
- merchant lebih dari tiga;
- rute Nitip lebih dari 50 km;
- ongkir tidak boleh direcalculate karena locked.

Error teknis yang tidak perlu terlalu detail:

- timeout network;
- exception internal;
- storage unavailable;
- log write failed.

### 2.8 Pakai Warna Secara Konsisten

Warna bukan syarat UML, tetapi membantu pembacaan.

| Elemen | Warna |
| --- | --- |
| Start/End sukses | Hijau muda `#DCFCE7` |
| Process normal | Putih `#FFFFFF` |
| Decision | Kuning muda `#FEF3C7` |
| Input/Output | Biru muda `#DBEAFE` |
| Database | Abu muda `#F3F4F6` |
| Error/blocked | Merah muda `#FEE2E2` |
| Sub-process | Oranye muda `#FFF1E8` |

Garis utama boleh memakai warna BangDeliv orange `#F05B24`.

### 2.9 Batas Ukuran Diagram

Untuk laporan:

- maksimal 20-25 node per flowchart;
- maksimal 6 decision node besar;
- jika lebih, pecah menjadi diagram lanjutan;
- satu diagram idealnya muat di A4 landscape.

### 2.10 Naming Diagram

Gunakan prefix `FC` untuk flowchart.

Format:

```text
FC-01 - Nama Flowchart
```

Contoh:

```text
FC-05 - Flowchart Validasi Draft Nitip
```

## 3. Daftar Flowchart yang Disarankan

### FC-01 - Routing User Setelah Login

Tujuan:

Menjelaskan bagaimana frontend/backend menentukan halaman awal user berdasarkan
session, role, dan status driver.

Pertanyaan utama:

- Token valid?
- Role user apa?
- Driver sudah active?
- Route yang dibuka boleh untuk role itu?

Output:

- Guest ke login/home publik.
- Customer ke home customer.
- Driver active ke driver home.
- Driver nonactive ke verification status.
- Admin ke area admin/profile.

### FC-02 - Pembuatan Order Ride

Tujuan:

Menjelaskan validasi dan pembuatan order Ride dari pickup dan destination.

Pertanyaan utama:

- Customer authenticated?
- Pickup valid?
- Destination valid?
- Titik terlalu dekat?
- Route/pricing berhasil?
- Payment method valid?

Output:

- Order `RIDE` status `PENDING`.
- Error validasi alamat/rute.

### FC-03 - Pembuatan Order Courier dari Chatbot

Tujuan:

Menjelaskan chatbot Kurir dari parsing pesan sampai order dibuat.

Pertanyaan utama:

- Intent courier valid?
- Pickup tersedia?
- Dropoff tersedia?
- Paket valid?
- Perlu map picker?
- Customer konfirmasi?

Output:

- Order `COURIER` status `PENDING`.
- Bot meminta kelengkapan data.
- Bot menolak paket terlarang.

### FC-04 - Draft dan Konfirmasi Order Nitip

Tujuan:

Menjelaskan logic draft Nitip sebelum order dibuat.

Pertanyaan utama:

- Alamat antar tersedia?
- Merchant sudah dipilih?
- Jumlah merchant kurang dari 3?
- Item merchant lengkap?
- Rute multi-pickup <= 50 km?
- Payment method sudah dipilih?
- Customer konfirmasi?

Output:

- Order `SHOPPING` status `PENDING`.
- Draft belum lengkap.
- Merchant/rute ditolak.

### FC-05 - Driver Accept/Reject Order

Tujuan:

Menjelaskan bagaimana order pending diterima atau ditolak driver.

Pertanyaan utama:

- Driver active?
- Driver online?
- Order masih pending?
- Driver accept atau reject?
- Order sudah diterima driver lain?

Output:

- Order menjadi `DRIVER_ASSIGNED`.
- Order tetap pending untuk driver lain.
- Driver tidak melihat order yang sudah direject.

### FC-06 - Driver Action Resolver per Service Type

Tujuan:

Menjelaskan bagaimana sistem menentukan action driver yang tersedia berdasarkan
service type dan status order.

Pertanyaan utama:

- Service type apa?
- Status order apa?
- Payment required?
- Proof required?
- Ada pending delivery fee negotiation?
- Ada pending shopping decision?

Output:

- List action Ride.
- List action Courier.
- List action Nitip.
- Action disabled atau hidden jika syarat belum terpenuhi.

### FC-07 - Lifecycle Order Ride

Tujuan:

Menjelaskan status transition Ride.

Status flow:

```text
DRIVER_ASSIGNED
ARRIVED_PICKUP
ON_THE_WAY
ARRIVED_DROPOFF
DELIVERED
COMPLETED
```

Pertanyaan utama:

- Driver sudah tiba pickup?
- Penumpang sudah naik?
- Driver tiba tujuan?
- Payment paid?

### FC-08 - Lifecycle Order Courier

Tujuan:

Menjelaskan status transition Courier.

Status flow:

```text
DRIVER_ASSIGNED
ARRIVED_PICKUP
PICKED_UP
ON_THE_WAY
ARRIVED_DROPOFF
DELIVERED
COMPLETED
```

Pertanyaan utama:

- Paket valid?
- Payment pickup paid?
- Bukti pickup/delivery valid?
- Payment final paid?

### FC-09 - Lifecycle Order Nitip Multi-Merchant

Tujuan:

Menjelaskan status transition utama Nitip dan loop per merchant.

Pertanyaan utama:

- Merchant buka?
- Item tersedia?
- Customer sudah memutuskan item unavailable?
- Harga merchant approved?
- Semua merchant selesai/terminal?
- Payment paid?

Output:

- Order completed.
- Order cancelled/cancelled with fee jika semua merchant gagal.

### FC-10 - Proses Satu Merchant Nitip

Tujuan:

Menjelaskan sub-process merchant Nitip.

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

Pertanyaan utama:

- Merchant buka?
- Semua item tersedia?
- Customer edit/lanjut/batal?
- Driver input harga?
- Customer approve harga?
- Driver bypass?

### FC-11 - Keputusan Item Nitip Tidak Tersedia

Tujuan:

Menjelaskan pilihan customer saat item tidak tersedia.

Pertanyaan utama:

- Merchant masih punya item aktif lain?
- Customer pilih edit?
- Customer pilih lanjut tanpa ini?
- Customer pilih batal merchant?

Rule penting:

- Edit item langsung berlaku tanpa approval driver.
- `Lanjut tanpa ini` hanya boleh jika merchant tetap punya item aktif.

### FC-12 - Revisi Ongkir dan Delivery Fee Lock

Tujuan:

Menjelaskan revisi ongkir, approval customer, dan lock ongkir Nitip.

Pertanyaan utama:

- Order masih editable?
- Customer approve?
- Customer counter?
- Driver accept counter?
- Service type `SHOPPING`?
- Ongkir sudah locked?

Output:

- Ongkir final diperbarui.
- Untuk Nitip, ongkir locked tidak boleh direcalculate saat merchant tutup.

### FC-13 - Pricing Nitip Saat Merchant Tutup

Tujuan:

Menjelaskan rule harga saat satu atau semua merchant Nitip tutup.

Pertanyaan utama:

- Merchant yang tutup punya item aktif?
- Masih ada merchant aktif lain?
- Semua merchant terminal?
- Ongkir locked?
- Ada cancellation fee?

Output:

- Item merchant tutup tidak dihitung.
- Order lanjut jika ada merchant aktif.
- Jika semua merchant tutup, order terminal.
- Ongkir final terakhir dipertahankan jika locked.

### FC-14 - Payment Validation Before Complete

Tujuan:

Menjelaskan validasi payment sebelum order completed.

Pertanyaan utama:

- Payment method COD atau TRANSFER?
- COD sudah dicatat?
- Bukti transfer sudah diupload?
- Driver/admin sudah konfirmasi transfer?
- Payment status `PAID`?

Output:

- Driver boleh complete.
- Driver diblokir karena unpaid.

### FC-15 - Verifikasi Driver oleh Admin

Tujuan:

Menjelaskan driver upload dokumen sampai admin approve/reject.

Pertanyaan utama:

- Dokumen lengkap?
- File valid?
- Semua dokumen approved?
- Ada dokumen rejected?

Output:

- Driver active.
- Driver pending.
- Driver rejected/perlu upload ulang.

### FC-16 - Admin Kelola Merchant dan Menu

Tujuan:

Menjelaskan CRUD merchant dan menu dari admin panel.

Pertanyaan utama:

- Admin authenticated?
- Data merchant valid?
- Slug unik?
- Menu valid?
- Merchant aktif?

Output:

- Merchant/menu tersimpan.
- Merchant/menu ditolak karena validasi.

### FC-17 - Chat dan Realtime Update

Tujuan:

Menjelaskan pengiriman chat, status update, driver location, dan push
notification.

Pertanyaan utama:

- User authorized channel?
- Message valid?
- WebSocket connected?
- Device token valid?

Output:

- UI customer/driver terupdate realtime.
- Push dikirim atau token invalid dinonaktifkan.

## 4. Detail Flowchart Prioritas

Bagian ini berisi flowchart prioritas yang sebaiknya dibuat lebih dulu.

## FC-04 Detail - Draft dan Konfirmasi Order Nitip

### Tujuan

Menjelaskan logic draft Nitip sebelum order `SHOPPING` dibuat.

### Flow

```text
Start
Customer buka BangBot Nitip
Backend load/create chatbot session
Backend cek alamat antar
Alamat valid?
  Tidak -> Tampilkan action pilih/isi alamat -> Customer pilih alamat -> Patch session -> Cek alamat lagi
  Ya -> Lanjut
Merchant sudah dipilih?
  Tidak -> Tampilkan action pilih merchant -> Customer pilih merchant lewat map -> Patch merchant ke session
  Ya -> Lanjut
Backend set merchant aktif
Backend tampilkan contoh item merchant aktif
Customer input item
Backend normalize item dan assign ke merchant aktif
Draft punya minimal 1 merchant dan semua merchant punya item?
  Tidak -> Tampilkan draft belum lengkap -> End sementara
  Ya -> Lanjut
Customer ingin tambah merchant?
  Ya -> Jumlah merchant < 3?
    Tidak -> Tolak tambah merchant -> Kembali ke draft
    Ya -> Tampilkan action tambah merchant -> Customer pilih merchant -> Patch merchant -> Input item
  Tidak -> Lanjut
Backend validasi rute multi-pickup
Rute <= 50 km?
  Tidak -> Tolak draft dan minta revisi merchant/alamat -> End sementara
  Ya -> Lanjut
Payment method sudah dipilih?
  Tidak -> Tampilkan action COD/TRANSFER -> Customer pilih payment -> Simpan ke draft
  Ya -> Lanjut
Backend hitung estimasi ongkir dan biaya layanan
Backend tampilkan draft confirmable
Customer konfirmasi?
  Tidak -> Draft tetap tersimpan -> End sementara
  Ya -> Backend create order SHOPPING
Backend simpan order_locations, shopping_order_items, fee lines, payment
Backend broadcast order ke driver available
Frontend buka tracking order
End: Order Nitip dibuat
```

### Node Penting

- Decision: `Alamat valid?`
- Decision: `Merchant sudah dipilih?`
- Decision: `Jumlah merchant < 3?`
- Decision: `Rute <= 50 km?`
- Decision: `Payment method sudah dipilih?`
- Decision: `Customer konfirmasi?`

### Catatan untuk Diagram

Tambahkan note:

```text
Merchant dan item menjadi locked setelah order dikonfirmasi.
```

Tambahkan note:

```text
Backend adalah sumber validasi rute dan maksimal 3 merchant.
```

## FC-09 Detail - Lifecycle Order Nitip Multi-Merchant

### Tujuan

Menjelaskan flow utama driver menjalankan order Nitip.

### Flow

```text
Start
Order SHOPPING status DRIVER_ASSIGNED
Driver menuju merchant
Driver tiba di toko/merchant
Backend set order status ARRIVED_MERCHANT
Ambil merchant aktif berikutnya
Ada merchant aktif?
  Tidak -> Semua merchant terminal?
    Ya -> Masuk flow terminal/cancel fee
    Tidak -> Error state
  Ya -> Lanjut
Merchant buka?
  Tidak -> Backend set merchant FAILED -> Cek merchant aktif berikutnya
  Ya -> Backend set merchant OPEN_CONFIRMED
Driver cek item
Semua item tersedia?
  Tidak -> Backend set merchant ITEMS_PENDING_CUSTOMER -> Masuk FC-11 -> Kembali cek item fix
  Ya -> Backend set merchant ITEMS_CONFIRMED
Driver input harga merchant
Backend set merchant PRICE_PENDING_CUSTOMER
Customer approve harga?
  Ya -> Backend set merchant PRICE_APPROVED
  Tidak -> Customer batal merchant?
    Ya -> Backend set merchant FAILED
    Tidak -> Driver bypass?
      Ya -> Backend audit bypass dan set merchant PRICE_APPROVED
      Tidak -> Tunggu response customer
Semua merchant selesai/terminal?
  Tidak -> Ambil merchant aktif berikutnya
  Ya -> Lanjut
Driver upload struk jika ada
Driver pilih Belanja Selesai
Backend set order PICKED_UP
Driver menuju customer
Backend set order ON_THE_WAY
Driver tiba customer
Backend set order ARRIVED_DROPOFF
Driver serahkan barang
Backend set order DELIVERED
Payment paid?
  Tidak -> Jalankan FC-14 Payment Validation -> Kembali cek payment
  Ya -> Driver complete order
Backend set order COMPLETED
End: Order Nitip selesai
```

### Node Penting

- Sub-process: `FC-10 Proses Satu Merchant Nitip`
- Sub-process: `FC-11 Keputusan Item Nitip Tidak Tersedia`
- Sub-process: `FC-14 Payment Validation Before Complete`

### Catatan untuk Diagram

Jangan membuat semua detail item unavailable di diagram utama. Pakai sub-process
agar flowchart tetap terbaca.

## FC-11 Detail - Keputusan Item Nitip Tidak Tersedia

### Tujuan

Menjelaskan rule tombol customer saat item tidak tersedia.

### Flow

```text
Start
Backend menerima availability item dari driver
Ada item tidak tersedia?
  Tidak -> Merchant ITEMS_CONFIRMED -> End
  Ya -> Merchant ITEMS_PENDING_CUSTOMER
Backend hitung capability action customer
Merchant punya item aktif lain jika item unavailable dihapus?
  Ya -> Tampilkan Edit, Lanjut tanpa ini, Batal merchant
  Tidak -> Tampilkan Edit dan Batal merchant
Customer pilih action
Action = Edit?
  Ya -> Customer input item pengganti
        Backend validasi merchant sama
        Valid?
          Tidak -> Tampilkan error
          Ya -> Apply item baru
                Hapus item unavailable lama
                Merchant ITEMS_CONFIRMED
                Mark harga NEEDS_REQUOTE
                End
Action = Lanjut tanpa ini?
  Ya -> Merchant masih punya item aktif lain?
        Tidak -> Tolak action
        Ya -> Hapus item unavailable
              Merchant ITEMS_CONFIRMED
              Mark harga NEEDS_REQUOTE
              End
Action = Batal merchant?
  Ya -> Backend set merchant FAILED
        Item merchant tidak dihitung
        Masih ada merchant aktif?
          Ya -> Driver lanjut merchant lain
          Tidak -> Masuk flow semua merchant terminal
        End
Action tidak valid -> Tampilkan error -> End
```

### Rule Penting

```text
Edit item tidak perlu approval driver.
Driver hanya perlu mengirim harga merchant ulang setelah item fix.
```

```text
Lanjut tanpa ini tidak boleh membuat merchant kosong.
```

## FC-12 Detail - Revisi Ongkir dan Delivery Fee Lock

### Tujuan

Menjelaskan flow revisi ongkir dan kapan ongkir Nitip menjadi locked.

### Flow

```text
Start
Driver buka order aktif
Driver pilih Revisi Ongkir
Backend cek order editable
Order editable?
  Tidak -> Tolak revisi ongkir -> End
Payment sudah paid atau bukti transfer pending?
  Ya -> Tolak revisi ongkir -> End
  Tidak -> Lanjut
Driver input nominal dan alasan
Nominal valid?
  Tidak -> Tampilkan error -> End
  Ya -> Backend record DRIVER_FEE_QUOTED
Backend set negotiation PENDING_CUSTOMER
Customer menerima card revisi ongkir
Customer setuju?
  Ya -> Backend record CUSTOMER_FEE_APPROVED
        Backend update delivery_fee
        Service type SHOPPING?
          Ya -> Set delivery_fee_locked = true
          Tidak -> Lanjut
        End: Ongkir approved
  Tidak -> Customer counter?
    Ya -> Backend record CUSTOMER_FEE_COUNTERED
          Driver setuju counter?
            Ya -> Backend record DRIVER_COUNTER_APPROVED
                  Backend update delivery_fee
                  Service type SHOPPING?
                    Ya -> Set delivery_fee_locked = true
                    Tidak -> Lanjut
                  End
            Tidak -> Driver kirim requote -> Kembali ke PENDING_CUSTOMER
    Tidak -> Customer batal order?
      Ya -> Backend cancel order sesuai rule -> End
      Tidak -> Tetap pending response -> End sementara
```

### Rule Khusus Nitip

Tambahkan note besar:

```text
Jika delivery_fee_locked = true, merchant tutup tidak boleh mengubah ongkir.
Gunakan ongkir final terakhir yang disetujui customer.
```

## FC-13 Detail - Pricing Nitip Saat Merchant Tutup

### Tujuan

Menjelaskan flow pricing ketika satu atau semua merchant tutup.

### Flow

```text
Start
Driver/customer menandai merchant tutup/batal
Backend set merchant FAILED
Backend exclude item merchant dari subtotal aktif
Masih ada merchant aktif?
  Ya -> Backend recalculate subtotal barang aktif
        Delivery fee locked?
          Ya -> Preserve delivery fee final
          Tidak -> Recalculate route active merchant
        Backend update total
        Order lanjut
        End
  Tidak -> Semua merchant terminal
        Delivery fee locked?
          Ya -> Preserve delivery fee final terakhir
          Tidak -> Gunakan cancellation fee rule / delivery fee existing sesuai policy
        Backend hitung service fee/cancel fee
        Backend set order CANCELLED_WITH_FEE atau terminal sesuai rule
        Backend update payment pending
        End
```

### Contoh yang Harus Dicegah

```text
Ongkir approved terakhir: Rp18.000
Semua merchant tutup
Sistem menghitung route kosong menjadi Rp0/Rp5.000
Total berubah menjadi service fee minimum saja
```

Expected:

```text
Ongkir final tetap Rp18.000 jika locked.
```

## FC-14 Detail - Payment Validation Before Complete

### Tujuan

Menjelaskan validasi payment sebelum driver complete order.

### Flow

```text
Start
Driver menekan Selesaikan Order
Backend cek payment method
Payment method COD?
  Ya -> COD sudah dicatat paid?
        Ya -> Complete boleh
        Tidak -> Tampilkan action Catat COD -> Block complete
  Tidak -> Payment method TRANSFER/QRIS
        Bukti transfer sudah upload?
          Tidak -> Block complete dan minta customer upload
          Ya -> Payment sudah confirmed paid?
                Ya -> Complete boleh
                Tidak -> Block complete sampai driver/admin konfirmasi
Complete boleh?
  Ya -> Backend set order COMPLETED
  Tidak -> Order tetap DELIVERED/CANCELLED_WITH_FEE
End
```

## FC-15 Detail - Verifikasi Driver oleh Admin

### Tujuan

Menjelaskan driver dari upgrade sampai active.

### Flow

```text
Start
Customer pilih upgrade ke driver
Backend validasi data driver
Data valid?
  Tidak -> Tampilkan error
  Ya -> Backend buat driver profile pending
Driver upload dokumen KTP/SIM/selfie
File valid dan lengkap?
  Tidak -> Tampilkan error/upload ulang
  Ya -> Backend simpan dokumen pending
Admin buka antrean verifikasi
Admin review dokumen
Semua dokumen approved?
  Ya -> Backend set driver active
        Driver dapat online
        End: Driver aktif
  Tidak -> Ada dokumen rejected?
        Ya -> Backend set rejected/perlu upload ulang
              Driver upload ulang dokumen
              Kembali ke review
        Tidak -> Status tetap pending
              End sementara
```

## 5. Prompt Siap Pakai untuk Codex Pembuat Flowchart

Gunakan prompt berikut saat ingin meminta Codex membuat diagram visual.

```text
Saya punya brief flowchart BangDeliv di:
D:\AA_Kuliah_Hassan\TA\Backend_Bangdeliv\docs\flowchart_bangdeliv_brief.md

Tolong buat flowchart visual di Draw.io/diagrams.net berdasarkan dokumen itu.

Prioritaskan:
1. FC-04 Draft dan Konfirmasi Order Nitip
2. FC-09 Lifecycle Order Nitip Multi-Merchant
3. FC-11 Keputusan Item Nitip Tidak Tersedia
4. FC-12 Revisi Ongkir dan Delivery Fee Lock
5. FC-13 Pricing Nitip Saat Merchant Tutup
6. FC-14 Payment Validation Before Complete
7. FC-15 Verifikasi Driver oleh Admin

Gunakan simbol flowchart standar, direction atas-ke-bawah, happy path di tengah,
error path di kanan, decision node dengan label Ya/Tidak, dan warna sesuai
brief. Output berupa file .drawio, lalu export PNG/PDF jika memungkinkan.
```

## 6. Checklist Review Flowchart

Sebelum flowchart dianggap selesai, pastikan:

- Ada satu start node yang jelas.
- Ada final node untuk sukses dan final node untuk error jika perlu.
- Setiap decision node berbentuk pertanyaan.
- Cabang decision punya label `Ya/Tidak` atau guard yang jelas.
- Happy path tidak tertutup cabang error.
- Error path penting tetap ditampilkan.
- Tidak ada garis terlalu banyak menyilang.
- Flow yang panjang dipecah menjadi sub-process.
- Simbol dipakai konsisten.
- Status order global dan status merchant Nitip tidak dicampur.
- Rule penting Nitip muncul:
  - maksimal 3 merchant;
  - item unavailable diputuskan customer;
  - edit item tidak perlu approval driver;
  - ongkir locked tidak dihitung ulang saat merchant tutup;
  - payment paid wajib sebelum complete.
- Diagram bisa dibaca saat dicetak A4 landscape.

