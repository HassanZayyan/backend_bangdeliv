# Plan Rework Besar Flow Nitip BangDeliv

## Summary

Flow Nitip dibuat ulang agar lebih terkunci, mudah dijelaskan, dan minim celah bug.
Customer memilih maksimal **3 merchant lewat Google Map/Places** sebelum order
dikonfirmasi. Setelah order dikonfirmasi, merchant tidak bisa diubah.

Driver langsung melihat semua merchant yang dipilih customer. Backend boleh
memberi urutan rekomendasi rute, tetapi driver tidak dikunci oleh urutan itu
karena estimasi Google Maps tidak selalu sama dengan kondisi lapangan.

Harga merchant dan biaya Nitip dipisahkan:

- **Harga merchant** adalah harga barang/makanan yang dibeli dari toko/resto.
- **Biaya Nitip** adalah service fee global untuk jasa belanja BangDeliv.

Tidak ada table baru untuk versi ini. Gunakan struktur existing:
`order_locations`, `shopping_order_items`, `order_events`, dan service yang sudah
ada.

## Konsep Harga

Harga merchant dihitung per merchant.

Contoh:

- Kedai Tinari: ramen + es jeruk = `Rp25.000`
- Alfamart: minyak + baby oil = `Rp18.000`

Maka subtotal barang = `Rp43.000`.

Biaya Nitip dihitung satu kali per order. Biaya ini bisa berasal dari:

- jumlah merchant aktif,
- jumlah item aktif,
- item berat,
- rule service fee existing.

Total akhir:

```text
Subtotal barang merchant approved
+ Ongkir final
+ Biaya Nitip / service fee global
+ Penalty jika semua merchant gagal/tutup
```

Best practice yang dipakai:

- harga barang tetap per merchant karena driver mengetahui harga saat berada di
  merchant tersebut;
- biaya Nitip tetap global agar UI, pricing, dan penjelasan ke dosen tidak
  terpecah menjadi fee per merchant yang sulit dilacak.

## Flow Customer

1. Customer masuk chatbot Nitip.
2. Card awal menampilkan tombol `Pilih Merchant di Map`.
3. Customer memilih merchant lewat Google Places/map picker.
4. Maksimal merchant adalah **3**.
5. Backend memvalidasi total rute maksimal **50 km** memakai route service
   existing.
6. Setelah merchant dipilih, chatbot meminta item dengan contoh berbasis merchant
   yang dipilih:

   ```text
   Beli di Kedai Tinari:
   ayam geprek 2
   es teh 1
   ```

7. Customer memilih metode pembayaran.
8. Customer melihat halaman/card konfirmasi berisi:
   - daftar merchant,
   - alamat merchant,
   - daftar item per merchant,
   - ongkir estimasi,
   - biaya Nitip,
   - metode pembayaran.
9. Setelah customer konfirmasi, merchant menjadi locked.

## Flow Driver

Setelah driver menerima order, driver melihat semua merchant yang dipilih
customer sejak awal.

Backend dapat memberi label rekomendasi rute, misalnya:

- `Rekomendasi #1`
- `Rekomendasi #2`
- `Rekomendasi #3`

Rekomendasi ini hanya membantu driver, bukan aturan wajib. Driver bebas memilih
merchant mana yang diproses lebih dulu berdasarkan kondisi lapangan.

Untuk setiap merchant yang belum selesai/batal, driver melihat tombol:

- `Resto buka`
- `Resto tutup`

Jika driver memilih `Resto tutup`:

- merchant tersebut batal otomatis;
- item merchant itu tidak dihitung;
- total direkalkulasi;
- driver lanjut ke merchant berikutnya.

Jika driver memilih `Resto buka`:

- checklist ketersediaan item untuk merchant tersebut muncul;
- driver mengecek item di merchant itu;
- driver menyimpan ketersediaan item.

Jika semua item merchant tersedia:

- driver mengirim harga barang merchant ke customer;
- customer mendapat card approval dengan opsi `Iya` atau `Batal merchant ini`;
- driver tetap bisa memakai bypass `Lanjutkan tanpa respon customer` jika
  customer tidak segera merespons;
- bypass wajib tercatat di audit event.

Jika ada item tidak tersedia:

- customer mendapat notifikasi;
- customer hanya boleh mengganti item di merchant yang sama, lanjut tanpa item,
  atau batalkan merchant;
- driver dapat mengecek ulang ketersediaan item sampai merchant itu fix.

Setelah semua merchant selesai atau batal:

- card upload nota muncul;
- nota bersifat nullable/opsional;
- nota tidak mengubah harga.

Driver lalu menuju customer. Customer upload bukti QRIS. Driver menyelesaikan
order setelah pembayaran valid seperti flow sekarang.

## Rule Merchant

- Merchant hanya bisa dipilih sebelum order dikonfirmasi.
- Merchant tidak bisa ditambah setelah driver assigned.
- Merchant tidak bisa diganti ketika item tidak tersedia.
- Driver bisa memproses merchant dalam urutan bebas.
- Urutan Google Maps hanya rekomendasi, bukan action gate.
- Jika merchant tutup, merchant itu batal.
- Jika semua merchant tutup/batal, order otomatis `CANCELLED_WITH_FEE`.
- Penalty memakai rule existing `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS`.

## Status Order dan Status Merchant

Status order global tetap sederhana dan berada di `order_statuses`.

Contoh status order global:

- `DRIVER_ASSIGNED`
- `ARRIVED_MERCHANT`
- `ON_THE_WAY`
- `DELIVERED`
- `COMPLETED`

Status merchant disimpan terpisah di `order_locations.fulfillment_status`.
Ini bukan duplikasi status, karena maknanya berbeda:

- `order_statuses` menjawab: pesanan secara keseluruhan sedang di tahap apa.
- `order_locations.fulfillment_status` menjawab: merchant/stop ini sudah
  diproses sampai mana.

Jika customer memilih 3 merchant, order global bisa tetap berada di fase
`ARRIVED_MERCHANT` atau "belanja di merchant", sementara setiap merchant punya
status sendiri. Contoh:

```text
Merchant C: PRICE_APPROVED
Merchant A: OPEN_CONFIRMED
Merchant B: PENDING
```

Penjelasan sidang:

> Status order bersifat global, sedangkan fulfillment status di order_locations
> adalah state per stop/merchant. Karena order Nitip bisa punya beberapa
> merchant, progress tiap merchant harus disimpan terpisah agar tidak membuat
> order_statuses terlalu kompleks.

## Rule Item Tidak Tersedia

Chat tetap boleh dipakai untuk komunikasi tambahan, tetapi bukan sumber data
utama. Keputusan item harus terstruktur.

Customer mendapat opsi:

- ganti item di merchant yang sama;
- lanjut tanpa item;
- batalkan merchant itu.

Driver bisa cek ulang ketersediaan item berulang sampai merchant tersebut
benar-benar fix. Setelah item merchant fix, baru harga merchant bisa dikirim.

## Backend Changes

### Capability

`ShoppingOrderCapabilityService` menjadi sumber tunggal capability Nitip:

- customer can select merchants before confirm;
- customer cannot mutate merchant after confirm;
- driver can mark any non-terminal merchant open/closed;
- driver can update item availability only after merchant open;
- driver can submit merchant price only after availability confirmed;
- driver can upload receipt only after all merchant prices are approved.

### Route

`ShoppingRouteService` tetap menjadi sumber validasi rute 50 km.

Rule rute:

- total rute pickup-pickup-dropoff maksimal 50 km;
- validasi dilakukan di backend, bukan hanya UI;
- backend dapat menghitung urutan rute rekomendasi;
- rekomendasi rute tidak menjadi action gate untuk driver;
- merchant yang batal/tutup tidak dihitung sebagai active route stop.

Alasan tidak memakai action gate urutan:

- Google Maps/Routes adalah estimasi, bukan jaminan kondisi lapangan;
- driver bisa memiliki konteks jalan lokal yang lebih akurat;
- sistem sebaiknya memberi rekomendasi, bukan mengunci driver.

### Harga Merchant

`ShoppingPriceNegotiationService` disederhanakan:

- hapus counter/tawar harga barang merchant;
- harga merchant hanya punya status utama:
  - belum quote,
  - menunggu approval customer,
  - approved,
  - approved by driver bypass,
  - perlu quote ulang jika item merchant berubah.

Harga merchant tetap disimpan sebagai event/snapshot seperti flow sekarang, tanpa
table baru.

Approval harga merchant bersifat soft approval:

- customer mendapat opsi `Iya` dan `Batal merchant ini`;
- driver dapat bypass jika customer tidak merespons;
- bypass mencatat event `MERCHANT_PRICE_APPROVED_BY_DRIVER_BYPASS`;
- customer tetap mendapat notifikasi bahwa harga dilanjutkan oleh driver.

### Item Change

`ShoppingItemChangeRequestService` disederhanakan:

- request hanya untuk item unavailable di merchant yang sama;
- tidak ada replace merchant;
- tidak ada tambah merchant setelah order dikonfirmasi.

### Workflow Guard

Order workflow memblokir progress jika:

- merchant yang sedang diproses belum diputuskan buka/tutup;
- item availability merchant yang sedang diproses belum fix;
- harga merchant yang sedang diproses belum approved atau belum di-bypass;
- semua merchant belum selesai/batal;
- pembayaran QRIS belum valid saat driver mencoba complete.

### Audit

`order_events.metadata` menyimpan audit:

- merchant opened/closed;
- item unavailable;
- item replacement/removal;
- merchant price quoted/approved/customer cancelled/bypassed by driver;
- penalty/cancel fee.

## Frontend Changes

### Chatbot Nitip

- Card awal selalu punya tombol `Pilih Merchant di Map`.
- Merchant picker reusable dipakai.
- Setelah merchant dipilih, draft card menampilkan nama merchant dan contoh item.
- Merchant lebih dari 3 ditolak sebelum submit.

### Customer Tracking

- Hapus tombol tambah merchant setelah order confirmed.
- Tampilkan merchant locked.
- Tampilkan card decision untuk item unavailable.
- Tampilkan approval harga per merchant.
- Payment QRIS tetap memakai card pembayaran existing.

### Driver Active Order

- Tampilkan semua merchant sejak driver assigned.
- Tampilkan label rekomendasi rute jika backend menyediakan.
- Tombol buka/tutup tersedia pada merchant yang belum terminal.
- Checklist item muncul setelah merchant buka.
- Input harga merchant muncul setelah item tersedia.
- Upload nota muncul setelah semua merchant selesai.
- Tidak ada action gate antar merchant; gate hanya berlaku di dalam proses
  masing-masing merchant.

### Item Berat

- Item berat dipindah menjadi satu card global order.
- Biaya item berat hanya dikenakan satu kali, misalnya `Rp6.000`.
- Jika minimal satu item aktif dianggap berat, fee berat aktif untuk order.
- Merchant asal item berat tidak memengaruhi fee karena driver tetap mengantar
  seluruh order dengan satu motor.

### Shared UI

Gunakan komponen reusable:

- `BangActionButton`
- merchant summary widget
- merchant status panel
- item unavailable decision card
- merchant price approval card

## Cleanup Redundansi Flow Lama

Rework Nitip ini harus menghapus jalur flow lama yang saling bertabrakan. Target
cleanup bukan menghapus file sebanyak mungkin, tetapi menghapus behavior lama
yang membuat kode bercabang terlalu banyak.

### Hapus Counter/Tawar Harga Merchant

Harga merchant tidak lagi memakai tawar nominal.

Yang dihapus dari flow Nitip:

- `CUSTOMER_PRICE_COUNTERED`
- `DRIVER_COUNTER_APPROVED`
- endpoint driver shopping `shopping/price-quote/accept-counter`
- tombol `Tawar` pada card harga merchant customer
- tombol `Setujui Tawaran` pada card harga merchant driver

Yang tetap dipertahankan:

- counter/tawar untuk revisi ongkir, karena ongkir masih mengikuti flow approval
  customer existing.
- reusable `BangAmountNegotiationCard` jika masih dipakai revisi ongkir.

### Hapus Replace Merchant

Merchant tidak bisa diganti setelah order dikonfirmasi.

Yang dihapus:

- payload `replacement_for_pickup_location_id` untuk flow Nitip baru;
- status merchant `REPLACED`;
- logic customer replace failed merchant;
- UI `ShoppingAddItemScreen` mode replace merchant;
- test yang memvalidasi replace merchant setelah driver arrived.

Pengganti flow:

- jika merchant tutup, merchant itu menjadi `FAILED`;
- customer tidak memilih merchant pengganti;
- jika masih ada merchant lain, order lanjut;
- jika semua merchant gagal/tutup, order menjadi `CANCELLED_WITH_FEE`.

### Hapus Tambah Merchant Setelah Driver Assigned

Customer tidak bisa tambah merchant setelah order dikonfirmasi.

Yang dihapus:

- capability `can_customer_request_add_stop`;
- tombol tambah merchant setelah order confirmed;
- request item change yang menambah merchant baru;
- branch backend yang mengizinkan new merchant pada status `DRIVER_ASSIGNED`.

Yang tetap ada:

- customer boleh mengganti item di merchant yang sama jika driver menandai item
  tidak tersedia.

### Evaluasi Skip Failed Merchant Customer

Endpoint customer untuk skip failed merchant:

```text
POST /orders/{orderId}/shopping-stops/{pickupLocationId}/skip
```

kemungkinan tidak diperlukan lagi jika flow baru memakai:

- driver menekan `Resto tutup`;
- merchant otomatis batal;
- order lanjut dengan merchant aktif lain.

Rekomendasi:

- hapus endpoint jika tidak ada flow UI yang membutuhkannya;
- atau tandai deprecated sementara sampai implementasi baru stabil.

### Rename Failed Attempt Request

File:

```text
app/Http/Requests/Api/RecordFailedAttemptRequest.php
```

kurang eksplisit untuk flow baru. Jika action baru adalah driver mencatat merchant
tutup/order batal di merchant, nama yang lebih jelas:

```text
RecordMerchantClosedRequest.php
```

Isi request tetap dapat memvalidasi:

- pickup location/merchant yang ditutup;
- alasan;
- foto toko tutup jika tetap diwajibkan.

### File Yang Tetap Dipakai

File berikut tidak perlu dihapus, tetapi perlu dipersempit tanggung jawabnya:

- `ShoppingPriceNegotiationService`
  - tetap untuk quote/approval harga merchant;
  - hapus branch counter;
  - tambah soft approval bypass driver.
- `ShoppingItemChangeRequestService`
  - tetap untuk item unavailable;
  - hanya merchant yang sama, tidak boleh replace merchant.
- `ShoppingOrderCapabilityService`
  - menjadi pusat rule flow baru.
- `ShoppingRouteService`
  - tetap untuk validasi 50 km dan rekomendasi rute.
- `ShoppingAddItemScreen`
  - tetap untuk input item sebelum confirm dan ganti item unavailable;
  - hapus mode tambah/replace merchant setelah order confirmed.
- `BangAmountNegotiationCard`
  - tetap untuk revisi ongkir;
  - tidak dipakai untuk harga merchant jika card harga merchant dibuat lebih
    sederhana.

## State Merchant Yang Disarankan

Gunakan value `fulfillment_status` yang konsisten di `order_locations`:

- `PENDING`: merchant belum diproses.
- `OPEN_CONFIRMED`: driver sudah konfirmasi merchant buka.
- `ITEMS_PENDING_CUSTOMER`: ada item tidak tersedia dan menunggu keputusan
  customer.
- `ITEMS_CONFIRMED`: item merchant sudah fix.
- `PRICE_PENDING_CUSTOMER`: harga merchant menunggu approval customer.
- `PRICE_APPROVED`: harga merchant sudah disetujui.
- `COMPLETED`: merchant selesai diproses.
- `FAILED`: merchant tutup/order batal di merchant.
- `SKIPPED`: merchant dilewati oleh keputusan customer.

Jika ingin menghindari enum baru di database, tetap simpan sebagai string seperti
sekarang, tetapi mapping harus dipusatkan di service/domain constant agar tidak
ada string literal tersebar.

## Test Plan

### Chatbot

- Tombol `Pilih Merchant di Map` muncul di card awal Nitip.
- Maksimal 3 merchant.
- Merchant ke-4 ditolak.
- Rute lebih dari 50 km ditolak.
- Draft setelah pilih merchant menampilkan contoh item sesuai nama merchant.
- Merchant Google Places yang cocok DB tetap memakai menu database.

### Backend

- Merchant locked setelah order confirmed.
- Customer tidak bisa tambah/ganti merchant setelah confirm.
- Driver bisa memproses merchant dalam urutan bebas.
- Rekomendasi rute tidak memblokir action driver.
- Merchant tutup membatalkan merchant tersebut.
- Semua merchant tutup menghasilkan `CANCELLED_WITH_FEE`.
- Sebagian merchant tutup tetap lanjut dengan merchant aktif lain.
- Item unavailable menghasilkan customer decision.
- Harga merchant tidak bisa dikirim sebelum item fix.
- Harga merchant bisa approved customer atau di-bypass driver dengan audit event.
- Order tidak bisa complete sebelum QRIS valid.
- Service fee global dihitung ulang dari merchant/item aktif.
- Item berat dikenakan satu kali walaupun item berat berasal dari merchant mana
  pun.

### Frontend

- Tracking tidak menampilkan tambah merchant setelah confirm.
- Driver card menampilkan buka/tutup, availability, harga, dan nota sesuai urutan
  status.
- Semua merchant langsung tampil saat driver assigned.
- Driver bisa memilih merchant mana yang diproses tanpa dikunci urutan
  rekomendasi.
- Unavailable item card hanya memberi opsi ganti item merchant sama, lanjut tanpa
  item, atau batalkan merchant.
- Merchant price approval tampil per merchant.
- Customer mendapat notifikasi saat harga merchant dikirim atau di-bypass driver.
- Loading button tetap action-scoped.

### Validation

Backend:

```bash
php artisan test --stop-on-failure
vendor\bin\phpstan analyse --no-progress
vendor\bin\pint --test
```

Frontend:

```bash
flutter analyze
flutter test
```

## Assumptions

- Maksimal merchant Nitip final adalah 3.
- Pembayaran QRIS tetap dilakukan saat driver menuju customer.
- Harga barang merchant tidak memakai tawar nominal.
- Harga merchant memakai soft approval: customer bisa `Iya` atau
  `Batal merchant ini`, driver bisa bypass dengan audit event.
- Biaya Nitip adalah satu service fee global per order.
- Item berat adalah fee global satu kali per order.
- Tidak ada migration/table baru untuk versi ini.
- Chat hanya pendukung komunikasi, bukan sumber kebenaran item/harga.

---

# Plan Tambahan: Finalisasi Edit Item, Ongkir Terkunci, dan History Driver

## Summary

Plan ini menutup celah flow setelah implementasi multi-merchant Nitip:

- edit item tidak tersedia oleh customer langsung berlaku, tanpa approval driver;
- ongkir yang sudah direvisi dan disetujui customer menjadi nilai final yang
  tidak dihitung ulang saat merchant tutup;
- semua merchant tutup tetap memakai ongkir final terakhir, bukan ongkir hasil
  kalkulasi ulang dari route kosong;
- history driver menampilkan nilai fee/order income yang benar;
- opsi `Lanjut tanpa ini` hanya muncul jika merchant masih punya item aktif
  setelah item tidak tersedia dihapus.

Prinsip anti-redundansi:

- rule keputusan item berada di satu service backend;
- rule freeze ongkir berada di satu resolver pricing;
- rule tombol customer berasal dari capability backend, bukan dihitung ulang di
  banyak widget;
- UI hanya render state/action dari payload API.

## Target Behavior

### 1. Customer Edit Item Tidak Tersedia Tanpa Approval Driver

Saat driver menandai item tidak tersedia dan customer memilih `Edit`, perubahan
item di merchant yang sama langsung diterapkan.

Flow baru:

1. Driver set item availability.
2. Jika ada item tidak tersedia, merchant masuk `ITEMS_PENDING_CUSTOMER`.
3. Customer memilih:
   - `Edit`;
   - `Lanjut tanpa ini`, jika valid;
   - `Batal merchant`.
4. Jika customer memilih `Edit`, item baru langsung masuk ke merchant yang sama.
5. Item lama yang tidak tersedia dibersihkan.
6. Merchant kembali ke `ITEMS_CONFIRMED`.
7. Harga merchant masuk `NEEDS_REQUOTE`.
8. Driver langsung melihat daftar item final dan input harga ulang.

Tidak ada card `Request Item Customer` untuk edit item unavailable. Driver tidak
perlu `Setujui/Tolak` karena merchant sudah fix dan customer hanya mengganti item
di merchant yang sama.

### 2. Ongkir Final Tidak Dihitung Ulang Setelah Revisi Disetujui

Jika ongkir sudah direvisi oleh driver dan disetujui customer, nilai itu menjadi
ongkir final order.

Contoh:

```text
Ongkir final disetujui customer: Rp18.000
Merchant 1 tutup
Merchant 2 tutup
Merchant 3 tutup
```

Total/cancellation value tetap memakai `Rp18.000` sebagai ongkir final. Sistem
tidak boleh menghitung ulang ke `Rp5.000`, `Rp0`, atau nilai route kosong.

Alasan produk:

- driver sudah bergerak menuju merchant;
- customer sudah menyetujui ongkir manual/final;
- merchant tutup adalah risiko setelah driver melakukan perjalanan;
- fee yang diterima driver harus mengikuti ongkir final terakhir yang disetujui.

### 3. Semua Merchant Tutup Memakai Ongkir Final Terakhir

Saat seluruh merchant terminal karena tutup/order batal:

- order boleh menjadi cancelled/cancelled with fee sesuai rule existing;
- semua item aktif menjadi nol;
- subtotal barang menjadi `Rp0`;
- ongkir tetap memakai ongkir final terakhir jika sudah locked;
- service fee tidak boleh menggantikan ongkir final driver;
- driver history tidak boleh menampilkan income `Rp0` jika ada ongkir locked.

Expected result dari contoh bug:

```text
Ongkir approved terakhir: Rp18.000
Semua merchant tutup
Subtotal barang: Rp0
Ongkir / driver income basis: Rp18.000
Biaya layanan: mengikuti fee line existing jika memang ada
Total payable/cancel fee: tidak boleh jatuh ke Rp2.500 karena route recalculation
```

### 4. History Driver Menampilkan Service Fee / Income Order

History driver harus mengambil nilai dari payload order yang sama dengan detail
order, bukan menghitung ulang di frontend.

Tampilan list history:

- `Pendapatan` agregat memakai total income order yang sudah diserialisasi
  backend.
- Setiap card history menampilkan value order income yang benar.
- Untuk order semua merchant tutup, value tidak boleh `Rp0` jika backend punya
  fee/order income yang harus dibayar.

Tampilan detail history:

- payment breakdown tetap menampilkan ongkir, biaya layanan, dan total.
- `Item Belanja` tetap tidak menampilkan harga per item.
- jika order selesai karena semua merchant tutup, tampilkan merchant terminal dan
  fee/payment breakdown dengan nilai final dari backend.

### 5. Satu Item Tidak Tersedia: Hanya `Edit` dan `Batal Merchant`

Jika merchant hanya memiliki satu item dan item itu tidak tersedia:

- tombol `Lanjut tanpa ini` disembunyikan;
- customer hanya melihat `Edit` dan `Batal merchant`;
- backend juga menolak action lanjut tanpa item jika merchant akan kosong.

Rule umum:

```text
Tampilkan Lanjut tanpa ini hanya jika:
jumlah item aktif merchant setelah item unavailable dihapus >= 1
```

Jika hasilnya nol, action yang benar adalah `Batal merchant`, bukan remove item.

## Backend Plan

### A. Buat Satu Service Keputusan Item Unavailable

Tambahkan service kecil atau method terpusat, misalnya:

```text
ShoppingUnavailableItemDecisionService
```

Tanggung jawab:

- validasi order sedang dalam fase item unavailable;
- validasi target pickup/merchant aktif;
- validasi item/customer decision tidak keluar merchant;
- apply `EDIT`, `REMOVE_ITEM`, dan `CANCEL_MERCHANT`;
- update `order_locations.fulfillment_status`;
- catat audit event;
- trigger merchant quote `NEEDS_REQUOTE` jika item merchant berubah.

Dengan ini `OrderService` tidak perlu menyebar rule edit/remove/cancel di banyak
branch.

### B. Ubah Edit Item dari Pending Request ke Immediate Apply

Endpoint existing boleh dipakai agar kontrak Flutter tidak melebar:

```text
POST /api/v1/orders/{orderId}/shopping/item-change-request
```

Namun untuk `request_kind=EDIT_UNAVAILABLE`:

- `action=ADD` langsung apply;
- tidak membuat pending request untuk driver;
- tidak memunculkan card approval driver;
- response langsung mengembalikan order detail terbaru.

`ShoppingItemChangeRequestService` hanya dipakai jika nanti ada flow yang benar-
benar butuh approval driver. Untuk flow unavailable item saat ini, keputusan
customer adalah sumber final.

### C. Freeze Ongkir dengan Resolver Tunggal

Tambahkan helper/resolver di pricing layer:

```text
ShoppingDeliveryFeeLockResolver
```

Tugas resolver:

- cari ongkir final terakhir dari event approval ongkir manual/customer;
- fallback ke `orders.delivery_fee`/fee line current jika sudah final;
- expose flag `is_delivery_fee_locked`;
- expose `locked_delivery_fee_amount`.

Semua recalculate Nitip harus lewat resolver ini. Jangan ada branch manual di
controller/widget.

Rule:

```text
Jika delivery fee locked:
  preserve ongkir final terakhir
  jangan panggil route recalculation untuk mengubah ongkir
Jika belum locked:
  boleh kalkulasi normal sesuai active route
```

### D. Merchant Tutup Tidak Mengubah Ongkir Locked

Update path berikut agar memakai resolver:

- driver mark merchant closed / failed attempt;
- customer cancel merchant;
- all merchants failed cancellation;
- shopping pricing recalculation setelah merchant terminal.

Saat semua merchant tutup:

- jangan hitung route kosong sebagai ongkir final;
- jangan set delivery fee ke `0`;
- jangan set order income ke service fee minimum saja;
- gunakan locked delivery fee jika ada.

### E. Service Fee dan Driver Income Payload

Backend serializer driver harus mengirim nilai eksplisit:

```text
fee
service_fee
delivery_fee
driver_income
total_price
fee_lines[]
```

Rule anti-redundansi:

- frontend tidak menghitung income dari total/order items;
- frontend hanya format nilai dari backend;
- satu mapper di `DriverOrderPayloadFactory` menentukan field history dan detail.

### F. Capability Action Customer

Tambahkan capability/action dari backend untuk unavailable item:

```json
{
  "can_edit_unavailable_item": true,
  "can_continue_without_item": false,
  "can_cancel_merchant": true,
  "reason": "Merchant hanya punya satu item aktif."
}
```

UI customer tracking memakai capability ini untuk render tombol. Jangan hitung
ulang jumlah item di widget tracking.

## Frontend Plan

### A. Customer Tracking Item Decision

`TrackShoppingOrderItemsCard` hanya render action dari backend:

- `Edit`;
- `Lanjut tanpa ini`;
- `Batal merchant`.

Jika backend tidak mengirim `can_continue_without_item`, tombol tidak muncul.

Setelah customer edit item:

- tidak tampil pending driver approval;
- card langsung kembali ke merchant item list;
- status merchant menjadi `Item fix` / menunggu harga baru;
- tampil copy singkat:

```text
Item pengganti sudah masuk. Driver akan kirim harga terbaru.
```

### B. Shopping Add Item Screen

Mode edit unavailable tetap memakai merchant fixed:

- merchant card readonly;
- item baru masuk ke `target_pickup_location_id`;
- submit memanggil immediate decision endpoint;
- tidak ada state "menunggu persetujuan driver".

### C. Driver Active Order

Hapus card `Request Item Customer` untuk edit unavailable. Driver hanya melihat:

- merchant status `Item fix` setelah customer edit;
- daftar item final;
- card `Harga Nitip` jika perlu quote ulang.

Jika customer memilih `Batal merchant`, driver melihat merchant terminal.

### D. Driver History

List `Riwayat Driver`:

- pakai `driver_income` atau `fee` dari backend;
- jangan derive dari item/subtotal.

Detail `Riwayat Driver`:

- tampilkan fee line order dari backend;
- tampilkan service fee jika ada;
- item belanja tetap tanpa harga per item.

## Data Contract

Tambahkan/rapikan response order:

```json
{
  "shopping_capabilities": {
    "unavailable_item_actions": [
      {
        "pickup_location_id": 12,
        "item_id": 44,
        "can_edit": true,
        "can_continue_without_item": false,
        "can_cancel_merchant": true
      }
    ]
  },
  "pricing": {
    "delivery_fee": 18000,
    "service_fee": 2500,
    "driver_income": 18000,
    "is_delivery_fee_locked": true,
    "delivery_fee_source": "customer_approved_revision"
  }
}
```

Catatan:

- nama field dapat disesuaikan dengan model existing;
- field baru harus dipetakan di model customer dan driver;
- jangan membuat field paralel dengan arti sama.

## Cleanup Redundansi

Hapus atau matikan behavior lama berikut:

- driver approval untuk customer edit item unavailable;
- `Request Item Customer` card untuk replacement item yang sama merchant;
- kalkulasi ulang ongkir route kosong setelah delivery fee locked;
- logic frontend yang menentukan `Lanjut tanpa ini` dari hitung item lokal;
- kalkulasi driver history income di frontend;
- fallback `Rp0` untuk fee/history ketika backend sebenarnya punya fee line.

Tetap pertahankan:

- audit event item unavailable;
- audit event customer edit/remove/cancel merchant;
- audit event delivery fee revision approval;
- fee line breakdown sebagai sumber tampilan biaya.

## Test Plan

### Backend

- Customer edit item unavailable langsung apply tanpa pending driver request.
- Setelah edit item, merchant menjadi `ITEMS_CONFIRMED`.
- Setelah edit item, harga merchant menjadi `NEEDS_REQUOTE`.
- Driver response endpoint tidak diperlukan untuk edit unavailable.
- `Lanjut tanpa ini` ditolak jika merchant tinggal punya satu item aktif.
- `Lanjut tanpa ini` diterima jika merchant masih punya item aktif lain.
- Ongkir approved `Rp18.000` tetap `Rp18.000` saat satu merchant tutup.
- Ongkir approved `Rp18.000` tetap `Rp18.000` saat semua merchant tutup.
- Semua merchant tutup tidak menghasilkan delivery fee `0` atau route minimum
  baru.
- Driver history payload berisi service fee/driver income sesuai order.

### Frontend

- Customer edit unavailable item tidak menampilkan pending approval driver.
- Driver tidak melihat card `Request Item Customer` setelah customer edit item.
- Driver melihat harga merchant perlu dikirim ulang setelah customer edit item.
- Tracking merchant satu item unavailable hanya menampilkan `Edit` dan
  `Batal merchant`.
- Tracking merchant dengan item aktif lain menampilkan `Lanjut tanpa ini`.
- Driver history list menampilkan income/service fee dari backend, bukan `Rp0`.
- Driver history detail menampilkan fee breakdown dan tetap menyembunyikan harga
  per item.

### Validation

Backend:

```bash
php artisan test tests/Feature/Api/ShoppingOrderItemEditTest.php
php artisan test tests/Feature/Api/DriverOrderWorkflowTest.php
php artisan test tests/Feature/Api/DriverOrderRevisionEndpointsTest.php
php artisan test tests/Feature/Api/OrderPricingPushNotificationTest.php
```

Frontend:

```bash
flutter analyze
flutter test test/widgets/track_shopping_order_items_card_test.dart
flutter test test/widgets/driver_order_action_loading_test.dart
flutter test test/screens/driver_history_detail_test.dart
```

## Implementation Order

1. Backend immediate apply untuk unavailable item decision.
2. Backend capability action untuk tombol customer.
3. Frontend customer tracking dan add item mengikuti capability baru.
4. Driver active order menghapus pending request card untuk edit item.
5. Delivery fee lock resolver dan pricing recalculation preservation.
6. Driver history payload service fee/income.
7. Frontend history memakai payload baru.
8. Test backend/frontend sesuai test plan.

## Assumptions Tambahan

- Ongkir disebut locked setelah ada revisi ongkir manual yang disetujui customer
  atau order sudah memakai final delivery fee manual driver.
- Jika belum ada ongkir locked, kalkulasi normal masih boleh berjalan.
- Customer edit item hanya boleh dalam merchant yang sama.
- Customer tidak boleh mengubah merchant setelah order confirmed.
- Jika merchant kosong karena semua item tidak tersedia, action yang benar adalah
  `Batal merchant`.
