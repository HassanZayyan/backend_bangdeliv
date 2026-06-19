# Use Case BangDeliv

Tanggal analisis: 18 Juni 2026

Dokumen ini menjabarkan use case aplikasi BangDeliv berdasarkan struktur project
backend Laravel dan frontend Flutter yang sedang berjalan. Use case dibuat dalam
bentuk teks agar bisa dipakai untuk laporan TA, penjelasan sidang, dan acuan
implementasi lanjutan.

Dokumen ini menggabungkan:

- kondisi implementasi current di route, service, model, dan test;
- arah flow final yang sudah disepakati di `shopping_nitip_flow_rework.md`;
- prinsip bahwa backend adalah sumber kebenaran untuk status, capability,
  pricing, dan action yang boleh dilakukan user.

Jika ada perbedaan antara flow lama dan flow final Nitip, dokumen ini mengikuti
flow final agar use case tidak mewarisi behavior yang sedang dirework.

## 1. Ringkasan Sistem

BangDeliv adalah aplikasi layanan lokal berbasis mobile dengan tiga jenis
layanan utama:

1. **Antar Jemput / Ride**
   Customer memesan driver untuk menjemput penumpang dari titik pickup ke
   tujuan.

2. **Kurir / Courier**
   Customer mengirim paket atau barang dari titik pickup ke tujuan dropoff.

3. **Nitip / Shopping**
   Customer menitip pembelian makanan/barang dari satu sampai tiga merchant,
   lalu driver membeli dan mengantar barang ke alamat customer.

Sistem terdiri dari:

- **Frontend Flutter** untuk customer dan driver.
- **Backend Laravel API** untuk auth, order, pricing, chatbot, tracking,
  payment, dan driver workflow.
- **Admin web Laravel Blade** untuk admin operasional.
- **Google Maps/Places/Geocoding/Distance** untuk alamat, koordinat, dan rute.
- **Gemini** untuk interpretasi pesan chatbot.
- **Laravel Reverb/Pusher-compatible WebSocket** untuk realtime order,
  tracking, dan chat.
- **Firebase Cloud Messaging** sebagai fondasi push notification.

## 2. Aktor Sistem

### 2.1 Guest

Guest adalah pengguna yang belum login. Guest dapat melihat beberapa halaman
publik seperti home, detail merchant, dan detail menu, tetapi tidak dapat membuat
order atau memakai fitur transaksi.

### 2.2 Customer

Customer adalah pengguna yang sudah login dengan role `customer`. Customer dapat:

- mengelola profil dan alamat;
- memakai chatbot untuk membuat order Ride, Courier, dan Nitip;
- membuat order Ride langsung melalui API/form;
- melihat katalog merchant/menu;
- melihat daftar order, detail, tracking, dan history;
- melakukan pembayaran COD atau transfer/QRIS sesuai order;
- upload bukti transfer;
- chat dengan driver;
- membatalkan order selama status masih memungkinkan;
- upgrade akun menjadi driver.

### 2.3 Driver Nonaktif / Belum Diverifikasi

Driver nonaktif adalah user role `driver` yang dokumennya belum lengkap, pending,
ditolak, atau akun drivernya belum aktif. Driver nonaktif hanya boleh:

- melihat status verifikasi;
- upload dokumen KTP, SIM, dan selfie;
- mengelola profil dasar yang diizinkan.

Driver nonaktif tidak boleh online, menerima order, atau menjalankan order.

### 2.4 Driver Aktif

Driver aktif adalah user role `driver` dengan status verifikasi aktif. Driver
dapat:

- online/offline;
- update lokasi standby;
- menerima/menolak order masuk;
- menjalankan lifecycle order Ride, Courier, dan Nitip;
- update lokasi saat order berjalan;
- upload bukti yang sesuai service type;
- mengajukan revisi ongkir;
- mencatat pembayaran COD atau konfirmasi transfer;
- melihat order aktif, history, dan pendapatan.

### 2.5 Admin

Admin adalah user role `admin` yang mengakses web panel dan sebagian endpoint API
admin. Admin dapat:

- login ke admin panel;
- melihat dashboard, daftar order, customer, driver;
- mengelola merchant/restoran dan menu;
- memverifikasi dokumen driver;
- mencatat failed attempt atau pembayaran COD jika diperlukan;
- melihat settlement COD;
- memonitor riwayat chatbot/AI secara operasional.

### 2.6 Sistem Eksternal

Sistem eksternal bukan user manusia, tetapi berinteraksi dengan BangDeliv:

- Google Maps/Places/Geocoding/Distance untuk alamat dan rute;
- Gemini untuk ekstraksi intent chatbot;
- Reverb/WebSocket untuk realtime;
- FCM untuk push notification;
- Storage local/backend untuk foto bukti, avatar, dokumen driver, dan struk.

## 3. Service Type dan Status

### 3.1 Service Type

Kode service type utama:

| Kode | Nama UI | Kegunaan |
| --- | --- | --- |
| `RIDE` | Antar Jemput | Antar penumpang dari pickup ke tujuan. |
| `COURIER` | Kurir | Antar paket/barang dari pickup ke dropoff. |
| `SHOPPING` | Nitip | Titip belanja makanan/barang dari merchant ke customer. |

Alias chatbot:

| Alias | Service type |
| --- | --- |
| `antar_jemput` | `RIDE` |
| `kurir` | `COURIER` |
| `nitip` | `SHOPPING` |

### 3.2 Status Order Global

Status order global berlaku untuk semua service type, walaupun beberapa status
hanya relevan untuk service tertentu.

| Status | Makna |
| --- | --- |
| `PENDING` | Order dibuat, belum diterima driver. |
| `DRIVER_ASSIGNED` | Driver sudah menerima order. |
| `ARRIVED_MERCHANT` | Driver tiba di merchant/toko untuk order Nitip. |
| `ARRIVED_PICKUP` | Driver tiba di pickup untuk Ride/Courier. |
| `PICKED_UP` | Paket/barang sudah diambil atau belanja selesai. |
| `ON_THE_WAY` | Driver sedang menuju tujuan/customer. |
| `ARRIVED_DROPOFF` | Driver tiba di tujuan/dropoff/customer. |
| `DELIVERED` | Penumpang/barang sudah diserahkan. |
| `COMPLETED` | Order selesai secara sistem, termasuk pembayaran valid. |
| `CANCELLED` | Order dibatalkan tanpa fee berjalan. |
| `CANCELLED_WITH_FEE` | Order dibatalkan dengan fee/cancel penalty. |

### 3.3 Status Merchant Nitip

Nitip menggunakan status per merchant di `order_locations.fulfillment_status`.
Status ini tidak menggantikan status order global; status ini menjelaskan
progress tiap pickup merchant.

| Status merchant | Makna |
| --- | --- |
| `PENDING` | Merchant belum diproses driver. |
| `OPEN_CONFIRMED` | Driver menandai merchant buka. |
| `ITEMS_PENDING_CUSTOMER` | Ada item tidak tersedia dan menunggu keputusan customer. |
| `ITEMS_CONFIRMED` | Daftar item merchant sudah fix. |
| `PRICE_PENDING_CUSTOMER` | Harga merchant menunggu persetujuan customer. |
| `PRICE_APPROVED` | Harga merchant sudah disetujui atau dibypass sesuai rule. |
| `COMPLETED` | Merchant selesai diproses. |
| `FAILED` | Merchant tutup/order batal di merchant. |
| `SKIPPED` | Merchant dilewati karena keputusan customer. |

## 4. Batasan dan Rule Umum

### 4.1 Auth dan Role

- Endpoint transaksi dilindungi Sanctum.
- Customer tidak boleh membuka route driver aktif.
- Driver nonaktif diarahkan ke status verifikasi.
- Driver aktif tidak diarahkan ke route customer-only.
- Admin API dan admin web hanya boleh diakses role `admin`.

### 4.2 Alamat dan Rute

- Alamat customer bisa disimpan sebagai address book.
- Backend memvalidasi alamat terhadap area layanan.
- Jika frontend mengirim koordinat dari map pin, backend menyimpan koordinat
  payload dan tidak memaksa geocoding ulang.
- Route snapshot disimpan di order agar biaya dan rute historis tidak berubah
  ketika hasil Google Maps berubah.

### 4.3 Payment

- Payment method utama: `COD` dan `TRANSFER`.
- Transfer/QRIS memerlukan upload bukti dan/atau konfirmasi driver/admin.
- Driver tidak boleh complete order yang mensyaratkan payment paid sebelum
  payment valid.
- COD dapat dicatat driver atau admin.

### 4.4 Proof

- Courier mendukung bukti pickup dan delivery.
- Shopping/Nitip mendukung bukti receipt/struk dan store closed.
- Ride tidak memakai lifecycle proof driver.
- Bukti transfer terpisah dari proof lifecycle order.

### 4.5 Pricing dan Revisi Ongkir

- Driver dapat mengajukan revisi ongkir pada status yang masih editable.
- Customer dapat approve, counter, atau cancel sesuai flow yang aktif.
- Pending revisi ongkir dapat memblokir progress driver pada action tertentu.
- Untuk Nitip final, ongkir yang sudah disetujui customer menjadi locked dan
  tidak dihitung ulang saat merchant tutup.

### 4.6 Chat dan Realtime

- Customer dan driver yang terkait order dapat chat.
- Chat disabled untuk user yang tidak terkait order.
- Realtime digunakan untuk status order, lokasi driver, chat, incoming order,
  dan pricing updates.
- Device token dipakai untuk push notification.

## 5. Katalog Use Case

| ID | Use Case | Aktor utama |
| --- | --- | --- |
| UC-AUTH-01 | Registrasi customer | Guest |
| UC-AUTH-02 | Login dan restore session | Guest/Customer/Driver/Admin |
| UC-AUTH-03 | Logout | Customer/Driver/Admin |
| UC-PROFILE-01 | Mengelola profil | Customer/Driver |
| UC-PROFILE-02 | Mengelola alamat tersimpan | Customer |
| UC-HOME-01 | Melihat home, merchant, dan menu | Guest/Customer |
| UC-CHATBOT-01 | Membuat order Ride lewat chatbot | Customer |
| UC-CHATBOT-02 | Membuat order Courier lewat chatbot | Customer |
| UC-CHATBOT-03 | Membuat order Nitip lewat chatbot | Customer |
| UC-RIDE-01 | Membuat order Ride langsung | Customer |
| UC-ORDER-01 | Melihat daftar dan detail order customer | Customer |
| UC-ORDER-02 | Melacak order aktif | Customer |
| UC-ORDER-03 | Chat customer-driver | Customer/Driver |
| UC-ORDER-04 | Membatalkan order | Customer |
| UC-PAYMENT-01 | Mengubah metode pembayaran sebelum locked | Customer |
| UC-PAYMENT-02 | Upload bukti transfer/QRIS | Customer |
| UC-PAYMENT-03 | Mencatat pembayaran COD | Driver/Admin |
| UC-DRIVER-01 | Upgrade customer menjadi driver | Customer |
| UC-DRIVER-02 | Upload dokumen verifikasi driver | Driver |
| UC-DRIVER-03 | Mengatur online/offline driver | Driver aktif |
| UC-DRIVER-04 | Melihat dan menerima order masuk | Driver aktif |
| UC-DRIVER-05 | Menolak order masuk | Driver aktif |
| UC-RIDE-DRIVER-01 | Menjalankan order Ride | Driver aktif |
| UC-COURIER-DRIVER-01 | Menjalankan order Courier | Driver aktif |
| UC-SHOPPING-DRIVER-01 | Menjalankan order Nitip multi-merchant | Driver aktif |
| UC-PRICING-01 | Driver mengajukan revisi ongkir | Driver aktif |
| UC-PRICING-02 | Customer merespons revisi ongkir | Customer |
| UC-SHOPPING-01 | Customer menambah/edit item Nitip sebelum driver assigned | Customer |
| UC-SHOPPING-02 | Customer memutuskan item Nitip tidak tersedia | Customer |
| UC-SHOPPING-03 | Customer merespons harga merchant Nitip | Customer |
| UC-HISTORY-01 | Customer melihat riwayat order | Customer |
| UC-HISTORY-02 | Driver melihat riwayat dan pendapatan | Driver aktif |
| UC-ADMIN-01 | Admin login web panel | Admin |
| UC-ADMIN-02 | Admin mengelola merchant/restoran | Admin |
| UC-ADMIN-03 | Admin mengelola menu | Admin |
| UC-ADMIN-04 | Admin memverifikasi driver | Admin |
| UC-ADMIN-05 | Admin melihat order dan audit | Admin |
| UC-ADMIN-06 | Admin melihat settlement COD | Admin |
| UC-SYSTEM-01 | Mengirim realtime update | Sistem |
| UC-SYSTEM-02 | Mengirim push notification | Sistem |

## 6. Detail Use Case Auth dan Profil

### UC-AUTH-01 - Registrasi Customer

**Aktor utama:** Guest

**Tujuan:** Guest membuat akun customer agar dapat memakai layanan BangDeliv.

**Pemicu:** Guest membuka halaman register dan mengirim form pendaftaran.

**Precondition:**

- Guest belum memiliki token login aktif.
- Email/phone yang dikirim belum melanggar validasi unik sistem.

**Alur utama:**

1. Guest membuka halaman register.
2. Sistem menampilkan form nama, email, nomor HP, dan password.
3. Guest mengisi form.
4. Frontend mengirim request registrasi customer ke backend.
5. Backend memvalidasi payload.
6. Backend membuat record user dengan role `customer`.
7. Backend mengembalikan token/session payload.
8. Frontend menyimpan token dan mengarahkan user ke halaman utama customer.

**Alternatif/Exception:**

- Jika email/phone tidak valid, backend mengembalikan error validasi.
- Jika password tidak memenuhi aturan, backend menolak registrasi.
- Jika network error, frontend menampilkan pesan gagal dan user dapat retry.

**Postcondition:**

- User baru tersimpan dengan role `customer`.
- Customer berada dalam kondisi authenticated.

**Data/API terkait:**

- `users`
- `POST /api/auth/register/customer`
- `AuthController::registerCustomer`

### UC-AUTH-02 - Login dan Restore Session

**Aktor utama:** Guest, Customer, Driver, Admin

**Tujuan:** User masuk ke sistem sesuai role dan diarahkan ke area yang benar.

**Pemicu:** User mengirim email/password atau aplikasi memuat token yang sudah
tersimpan.

**Precondition:**

- User sudah terdaftar.
- Untuk driver aktif, profil driver sudah approved/active.

**Alur utama login:**

1. User membuka halaman login.
2. User mengisi email dan password.
3. Frontend mengirim request login.
4. Backend memverifikasi kredensial.
5. Backend mengembalikan token dan profile payload.
6. Frontend menyimpan token.
7. Router mengevaluasi role:
   - customer diarahkan ke home customer;
   - driver aktif diarahkan ke driver home;
   - driver nonaktif diarahkan ke status verifikasi;
   - admin diarahkan sesuai akses admin/profile.

**Alur restore session:**

1. Aplikasi dibuka.
2. Splash screen membaca token dari secure storage.
3. Frontend memanggil endpoint profile.
4. Jika token valid, session dihydrate.
5. Router mengarahkan berdasarkan role.

**Alternatif/Exception:**

- Password salah: backend menolak login.
- Token expired: session dihapus dan user diarahkan ke login.
- Driver nonaktif mencoba membuka driver order: router mengarahkan ke status
  verifikasi.

**Postcondition:**

- Session aktif tersimpan di device.
- User berada pada shell navigation sesuai role.

**Data/API terkait:**

- `users`, `drivers`, `personal_access_tokens`
- `POST /api/auth/login`
- `GET /api/user`
- `AuthController::login`
- `AuthController::me`
- `app_router.dart`

### UC-AUTH-03 - Logout

**Aktor utama:** Customer, Driver, Admin

**Tujuan:** User keluar dari session aktif.

**Pemicu:** User memilih tombol logout.

**Precondition:**

- User sedang authenticated.

**Alur utama:**

1. User menekan logout.
2. Frontend mengirim request logout.
3. Backend menghapus/revoke token aktif.
4. Frontend menghapus token lokal.
5. Router mengarahkan user ke login atau home guest.

**Alternatif/Exception:**

- Jika request logout gagal karena token sudah invalid, frontend tetap boleh
  membersihkan session lokal.

**Postcondition:**

- Token lokal hilang.
- User tidak bisa mengakses route protected.

**Data/API terkait:**

- `personal_access_tokens`
- `POST /api/auth/logout`

### UC-PROFILE-01 - Mengelola Profil

**Aktor utama:** Customer, Driver

**Tujuan:** User memperbarui data pribadi seperti nama, phone, avatar, dan
password.

**Pemicu:** User membuka halaman profil/edit profile.

**Precondition:**

- User authenticated.

**Alur utama update profil:**

1. User membuka halaman profil.
2. Sistem menampilkan data user.
3. User memilih edit profil.
4. User mengubah field yang diizinkan.
5. Frontend mengirim request update profile.
6. Backend memvalidasi payload dan file avatar jika ada.
7. Backend menyimpan perubahan.
8. Frontend refresh session/profile state.

**Alur ganti password:**

1. User membuka halaman ganti password.
2. User mengisi password lama dan password baru.
3. Backend memvalidasi password lama.
4. Backend menyimpan hash password baru.

**Alternatif/Exception:**

- Password lama salah: backend menolak.
- Avatar tidak valid: backend menolak file.
- Nomor HP/email tidak valid: backend mengembalikan error validasi.

**Postcondition:**

- Data profil terbaru tampil di frontend.

**Data/API terkait:**

- `users`
- `PUT /api/user`
- `PUT /api/user/password`
- `AuthController::updateProfile`
- `AuthController::changePassword`

### UC-PROFILE-02 - Mengelola Alamat Tersimpan

**Aktor utama:** Customer

**Tujuan:** Customer menyimpan, mengubah, memvalidasi, dan menghapus alamat
untuk pickup/dropoff/order.

**Pemicu:** Customer membuka menu alamat atau flow order memerlukan alamat.

**Precondition:**

- Customer authenticated.
- Jika menggunakan map, permission lokasi dapat diminta oleh device.

**Alur utama tambah alamat:**

1. Customer membuka halaman tambah alamat.
2. Customer mengisi label, nama penerima, phone, detail alamat.
3. Customer dapat memilih titik di map picker.
4. Frontend mengirim payload alamat dan koordinat.
5. Backend memvalidasi area layanan.
6. Backend menyimpan alamat.
7. Frontend refresh daftar alamat.

**Alur update alamat:**

1. Customer memilih alamat existing.
2. Customer mengubah field.
3. Backend memvalidasi ownership alamat.
4. Backend menyimpan perubahan.

**Alur hapus alamat:**

1. Customer memilih hapus.
2. Backend memvalidasi ownership alamat.
3. Backend menghapus alamat.

**Alternatif/Exception:**

- Alamat di luar area layanan: backend menolak.
- Address id bukan milik customer: backend menolak.
- Geocoding gagal tetapi koordinat payload valid: backend tetap dapat menyimpan
  koordinat payload sesuai rule.

**Postcondition:**

- Address book customer sesuai perubahan terakhir.

**Data/API terkait:**

- `addresses`
- `POST /api/user/addresses/validate`
- `POST /api/user/addresses`
- `PUT /api/user/addresses/{addressId}`
- `DELETE /api/user/addresses/{addressId}`
- `AddressService`

## 7. Detail Use Case Katalog dan Home

### UC-HOME-01 - Melihat Home, Merchant, dan Menu

**Aktor utama:** Guest, Customer

**Tujuan:** User melihat data beranda, daftar merchant, detail merchant, dan menu
sebelum membuat order.

**Pemicu:** User membuka home, merchant detail, atau menu detail.

**Precondition:**

- Endpoint katalog publik dapat diakses.
- Merchant/menu aktif tersedia di database.

**Alur utama:**

1. User membuka home.
2. Frontend mengambil data home dari backend.
3. Backend mengirim section home seperti rekomendasi merchant/menu.
4. User membuka daftar atau detail merchant.
5. Frontend mengambil detail merchant dan menu.
6. Backend hanya mengembalikan merchant/menu aktif sesuai filter.

**Alternatif/Exception:**

- Merchant tidak ditemukan: backend mengembalikan 404/error.
- Merchant inactive: tidak muncul di daftar publik.
- Network error: frontend menampilkan state retry.

**Postcondition:**

- User dapat melihat informasi katalog dan lanjut ke flow order/chatbot.

**Data/API terkait:**

- `restaurants`, `menus`, `menu_categories`
- `GET /api/v1/home`
- `GET /api/v1/restaurants`
- `GET /api/v1/restaurants/{restaurantIdOrSlug}`
- `GET /api/v1/restaurants/{restaurantIdOrSlug}/menus`
- `HomeService`
- `RestaurantService`

## 8. Detail Use Case Chatbot

### UC-CHATBOT-01 - Membuat Order Ride Lewat Chatbot

**Aktor utama:** Customer

**Tujuan:** Customer membuat order antar jemput melalui percakapan natural.

**Pemicu:** Customer membuka BangBot AI dengan service type `antar_jemput` dan
mengirim pesan.

**Precondition:**

- Customer authenticated.
- Customer memiliki pickup yang valid atau memilih pickup melalui map.

**Alur utama:**

1. Customer mengirim pesan tujuan, misalnya "antar saya ke Stasiun Tawang".
2. Backend mengirim pesan ke NLU/Gemini atau parser deterministic.
3. Sistem mengekstrak destination address.
4. Jika pickup belum tersedia, bot meminta customer memilih pickup.
5. Customer dapat memilih titik pickup/destination via map picker.
6. Backend menyimpan draft di session chatbot.
7. Bot menampilkan draft Ride berisi pickup, destination, estimasi ongkir, dan
   metode pembayaran.
8. Customer mengirim "konfirmasi".
9. Backend membuat order `RIDE` status `PENDING`.
10. Frontend mengarahkan customer ke tracking/detail order.

**Alternatif/Exception:**

- Destination ambigu: bot meminta customer memilih via map.
- Pickup dan destination terlalu dekat: backend menolak.
- Alamat di luar service area: backend menolak atau meminta titik lain.
- Pesan berisi "ubah tujuan": draft destination direset.
- Gemini gagal: fallback deterministic/context digunakan jika tersedia.

**Postcondition:**

- Order Ride dibuat.
- Order masuk ke pool dispatch driver.
- Audit status order tercatat.

**Data/API terkait:**

- `ai_chat_sessions`, `ai_chat_messages`, `orders`, `order_locations`,
  `ride_order_details`, `order_events`
- `POST /api/chatbot/process`
- `POST /api/chatbot/sessions/{sessionId}/location`
- `POST /api/chatbot/sessions/{sessionId}/locations`
- `ChatbotRideOrderService`
- `RideOrderService`

### UC-CHATBOT-02 - Membuat Order Courier Lewat Chatbot

**Aktor utama:** Customer

**Tujuan:** Customer membuat order kurir melalui percakapan natural.

**Pemicu:** Customer membuka BangBot AI service type `kurir`.

**Precondition:**

- Customer authenticated.
- Pickup/dropoff berada dalam area layanan.
- Barang tidak melanggar policy paket.

**Alur utama:**

1. Customer mengirim pesan, misalnya "kirim kunci dari rumah ke Erha
   Setiabudi".
2. Bot mengekstrak pickup, dropoff, dan deskripsi paket.
3. Jika pickup "rumah", sistem mencoba memakai alamat default customer.
4. Jika titik belum jelas, bot meminta customer memilih via map.
5. Backend memvalidasi paket melalui package policy.
6. Bot menampilkan draft Courier berisi pickup, dropoff, isi paket, dimensi/berat
   jika ada, ongkir, dan payment method.
7. Customer mengirim "konfirmasi".
8. Backend membuat order `COURIER` status `PENDING`.
9. Sistem broadcast order ke driver available.

**Alternatif/Exception:**

- Paket dilarang: bot menolak dan menjelaskan alasan.
- Paket ambigu/oversize: bot meminta klarifikasi atau memberi warning.
- Pickup/dropoff terlalu dekat: backend menolak.
- Payment belum dipilih: bot meminta COD/TRANSFER.

**Postcondition:**

- Order Courier dibuat dan siap diterima driver.

**Data/API terkait:**

- `orders`, `order_locations`, `courier_order_details`, `order_events`,
  `ai_chat_sessions`, `ai_chat_messages`
- `ChatbotCourierOrderService`
- `CourierPackagePolicyService`

### UC-CHATBOT-03 - Membuat Order Nitip Lewat Chatbot

**Aktor utama:** Customer

**Tujuan:** Customer membuat order Nitip dari satu sampai tiga merchant lewat
chatbot dan merchant picker.

**Pemicu:** Customer membuka BangBot AI service type `nitip`.

**Precondition:**

- Customer authenticated.
- Customer memiliki alamat antar valid.
- Merchant dipilih sebelum order dikonfirmasi.

**Alur utama:**

1. Customer mengirim pesan kebutuhan belanja atau memilih tombol merchant picker.
2. Bot memastikan alamat antar tersedia.
3. Customer memilih merchant lewat map/Places picker.
4. Bot menetapkan merchant aktif dan memberi contoh format item.
5. Customer mengirim daftar item.
6. Bot menyimpan item pada merchant aktif.
7. Jika merchant pertama sudah punya item, bot menawarkan tambah merchant lain.
8. Customer dapat menambah merchant hingga maksimal tiga.
9. Backend memvalidasi rute multi-pickup maksimal 50 km.
10. Bot menampilkan draft Nitip berisi merchant, alamat antar, item per merchant,
    estimasi ongkir, biaya layanan, dan metode pembayaran.
11. Customer memilih payment method.
12. Customer mengirim "konfirmasi".
13. Backend membuat order `SHOPPING` status `PENDING`.
14. Merchant dan item menjadi locked setelah order confirmed.

**Alternatif/Exception:**

- Merchant belum dipilih: bot menampilkan action pilih merchant.
- Customer mengetik "tambah order/tambah merchant": bot memulai mode tambah
  merchant, bukan memasukkan "order" sebagai item.
- Merchant keempat: backend/frontend menolak.
- Item tidak jelas: bot meminta daftar item.
- Alamat antar tidak valid: bot meminta alamat/map pin.
- Rute lebih dari 50 km: backend menolak draft/konfirmasi.
- Merchant cocok dengan DB BangDeliv: sistem dapat memakai menu database.
- Merchant Google Places eksternal: item dibuat manual dengan harga menyusul
  dari driver.

**Postcondition:**

- Order Nitip multi-merchant dibuat.
- Order siap diterima driver.
- Draft chatbot dapat ditutup atau diarsipkan.

**Data/API terkait:**

- `orders`, `order_locations`, `shopping_order_items`,
  `shopping_order_receipts`, `order_fee_lines`, `ai_chat_sessions`,
  `ai_chat_messages`, `order_events`
- `POST /api/chatbot/process`
- `POST /api/chatbot/sessions/{sessionId}/merchant`
- `POST /api/chatbot/sessions/{sessionId}/location`
- `ChatbotShoppingOrderService`
- `ShoppingRouteService`
- `ShoppingPricingService`

## 9. Detail Use Case Order Customer

### UC-RIDE-01 - Membuat Order Ride Langsung

**Aktor utama:** Customer

**Tujuan:** Customer membuat order Ride tanpa chatbot, memakai endpoint/form
langsung.

**Pemicu:** Customer memilih layanan Antar Jemput dan mengisi pickup/destination.

**Precondition:**

- Customer authenticated.
- Pickup/destination valid.
- Jarak memenuhi batas minimum dan area layanan.

**Alur utama:**

1. Customer memilih titik pickup.
2. Customer memilih destination.
3. Frontend memvalidasi destination jika diperlukan.
4. Backend menghitung route dan ongkir.
5. Customer memilih payment method.
6. Customer submit order.
7. Backend membuat order `RIDE` status `PENDING`.
8. Sistem broadcast order ke driver available.

**Alternatif/Exception:**

- Destination invalid/di luar area: backend menolak.
- Pickup dan destination terlalu dekat: backend menolak.
- Tidak ada driver tersedia: order tetap pending atau ditampilkan sesuai dispatch
  policy.

**Postcondition:**

- Order Ride pending driver.

**Data/API terkait:**

- `POST /api/v1/orders/ride/validate-destination`
- `POST /api/v1/orders/ride`
- `RideOrderService`

### UC-ORDER-01 - Melihat Daftar dan Detail Order Customer

**Aktor utama:** Customer

**Tujuan:** Customer melihat semua order, order aktif, detail order, status,
biaya, payment, dan timeline.

**Pemicu:** Customer membuka Aktivitas, Riwayat, atau detail order.

**Precondition:**

- Customer authenticated.
- Order yang diminta milik customer.

**Alur utama:**

1. Customer membuka tab Aktivitas/Riwayat.
2. Frontend mengambil daftar order customer.
3. Backend mengembalikan order yang boleh dilihat customer.
4. Customer memilih order.
5. Frontend mengambil detail order.
6. Backend mengembalikan detail lengkap termasuk route, payment, driver, proof,
   chat status, shopping data jika service `SHOPPING`, dan timeline.

**Alternatif/Exception:**

- Order bukan milik customer: backend menolak.
- Order tidak ditemukan: backend mengembalikan error.
- Data realtime berubah: provider melakukan refresh/invalidate.

**Postcondition:**

- Customer melihat state order terbaru.

**Data/API terkait:**

- `GET /api/v1/orders`
- `GET /api/v1/orders/{orderId}`
- `OrderService`

### UC-ORDER-02 - Melacak Order Aktif

**Aktor utama:** Customer

**Tujuan:** Customer melihat posisi driver, status order, ETA, rute, payment, dan
aksi yang tersedia.

**Pemicu:** Customer membuka halaman tracking.

**Precondition:**

- Customer authenticated.
- Order milik customer.
- Order berada pada status trackable atau masih punya data historis.

**Alur utama:**

1. Customer membuka tracking order.
2. Frontend memuat detail order.
3. Frontend subscribe channel realtime tracking order.
4. Backend mengirim driver location update saat driver bergerak.
5. UI memperbarui marker map, status, fee breakdown, payment card, dan action
   card.
6. Customer dapat membuka chat atau merespons pricing/payment sesuai capability.

**Alternatif/Exception:**

- Driver location stale: UI menampilkan keterangan lokasi terakhir.
- Order terminal: tracking berubah menjadi detail historis.
- WebSocket gagal: frontend tetap melakukan fetch/poll refresh manual sesuai
  provider.

**Postcondition:**

- Customer mendapat visibility order yang sedang berjalan.

**Data/API terkait:**

- `GET /api/v1/orders/{orderId}`
- `PATCH /api/v1/driver/orders/{orderId}/location`
- channel `order.tracking.{orderId}`
- `OrderEtaTargetResolver`

### UC-ORDER-03 - Chat Customer-Driver

**Aktor utama:** Customer, Driver

**Tujuan:** Customer dan driver berkomunikasi terkait order.

**Pemicu:** Salah satu pihak membuka chat order.

**Precondition:**

- Order sudah memiliki driver assigned atau sesuai rule chat.
- User adalah customer pemilik order atau driver yang assigned.

**Alur utama:**

1. User membuka chat order.
2. Frontend mengambil daftar pesan.
3. Frontend menandai pesan sebagai dibaca.
4. User mengirim teks atau attachment yang diizinkan.
5. Backend menyimpan pesan dengan `client_message_id`.
6. Backend broadcast pesan ke participant terkait.
7. Frontend memperbarui unread count.

**Alternatif/Exception:**

- User tidak terkait order: backend menolak.
- Order terminal: history chat dapat dibaca tetapi pengiriman dapat dibatasi.
- `client_message_id` sama: backend mencegah pesan duplikat.
- Attachment payment transfer tidak otomatis menjadi bukti pembayaran kecuali
  melalui endpoint payment evidence.

**Postcondition:**

- Pesan tersimpan dan participant menerima update.

**Data/API terkait:**

- `order_chat_messages`, `order_chat_reads`
- `GET /api/v1/orders/{orderId}/chat/messages`
- `POST /api/v1/orders/{orderId}/chat/messages`
- `POST /api/v1/orders/{orderId}/chat/read`
- `OrderChatService`

### UC-ORDER-04 - Membatalkan Order

**Aktor utama:** Customer

**Tujuan:** Customer membatalkan order selama masih diperbolehkan.

**Pemicu:** Customer menekan tombol cancel order.

**Precondition:**

- Customer authenticated.
- Order milik customer.
- Status order masih bisa dibatalkan.
- Tidak ada kondisi payment/proof yang memblokir cancellation.

**Alur utama:**

1. Customer membuka detail/tracking order.
2. Customer memilih cancel.
3. Frontend meminta konfirmasi jika aksi destruktif.
4. Backend memvalidasi status dan ownership.
5. Backend mengubah status ke `CANCELLED` atau `CANCELLED_WITH_FEE` sesuai rule.
6. Backend void/sync payment jika diperlukan.
7. Backend broadcast status update.

**Alternatif/Exception:**

- Driver sudah terlalu jauh/progress sudah melewati batas: backend menolak atau
  menerapkan fee sesuai rule.
- Ada payment transfer pending: backend dapat menolak perubahan tertentu.
- Order sudah terminal: backend menolak.

**Postcondition:**

- Order terminal cancelled.
- Customer dan driver menerima update.

**Data/API terkait:**

- `orders`, `order_events`, `order_payments`
- `POST /api/v1/orders/{orderId}/cancel`
- `OrderService::cancel`

## 10. Detail Use Case Payment

### UC-PAYMENT-01 - Mengubah Metode Pembayaran Sebelum Locked

**Aktor utama:** Customer

**Tujuan:** Customer memilih atau mengganti metode pembayaran order selama masih
diperbolehkan.

**Pemicu:** Customer memilih COD atau TRANSFER/QRIS pada draft/order.

**Precondition:**

- Customer authenticated.
- Order milik customer.
- Status order belum mengunci payment method.

**Alur utama:**

1. Customer membuka detail order/draft.
2. Customer memilih payment method.
3. Backend memvalidasi method (`COD` atau `TRANSFER`).
4. Backend menyimpan payment method.
5. Backend menyesuaikan pending payment.

**Alternatif/Exception:**

- Order sudah dibuat/progress lewat cutoff: backend menolak perubahan.
- Payment sudah paid: backend menolak perubahan.

**Postcondition:**

- Payment method order tersimpan.

**Data/API terkait:**

- `order_payments`, `orders`
- `PATCH /api/v1/orders/{orderId}/payment-method`
- `OrderPaymentService`

### UC-PAYMENT-02 - Upload Bukti Transfer/QRIS

**Aktor utama:** Customer

**Tujuan:** Customer mengunggah bukti pembayaran transfer/QRIS.

**Pemicu:** Customer menekan upload bukti pembayaran pada tracking/detail order.

**Precondition:**

- Order memakai payment method transfer.
- Customer authenticated dan order miliknya.
- File bukti valid.

**Alur utama:**

1. Customer memilih gambar bukti pembayaran.
2. Frontend mengirim multipart request.
3. Backend memvalidasi file.
4. Backend menyimpan evidence type payment transfer.
5. Backend menandai payment sesuai rule verifikasi.
6. Driver/admin dapat melihat bukti di detail order.

**Alternatif/Exception:**

- Order COD: backend menolak upload transfer evidence.
- File terlalu besar/format invalid: backend menolak.
- Payment sudah paid: backend dapat menolak upload ulang.

**Postcondition:**

- Bukti transfer tersimpan dan dapat diverifikasi.

**Data/API terkait:**

- `order_evidence`, `order_payments`
- `POST /api/v1/orders/{orderId}/payment/transfer/evidence`

### UC-PAYMENT-03 - Mencatat Pembayaran COD

**Aktor utama:** Driver, Admin

**Tujuan:** Mencatat bahwa pembayaran COD sudah diterima.

**Pemicu:** Driver/admin menekan tombol catat COD.

**Precondition:**

- Order menggunakan COD.
- Order assigned ke driver terkait atau admin memiliki akses.
- Payment masih pending.

**Alur utama driver:**

1. Driver membuka order aktif.
2. Driver memilih `Catat COD`.
3. Backend memvalidasi driver assigned.
4. Backend menandai payment `PAID`.
5. Backend memperbarui action yang tersedia.

**Alur utama admin:**

1. Admin membuka endpoint/panel pembayaran.
2. Admin memilih order COD.
3. Backend menandai payment `PAID` dengan actor admin.

**Alternatif/Exception:**

- Order bukan COD: backend menolak.
- Payment sudah paid: backend idempotent atau menolak sesuai rule.
- Driver bukan assigned driver: backend menolak.

**Postcondition:**

- Payment order menjadi paid.
- Order dapat complete jika semua syarat lain terpenuhi.

**Data/API terkait:**

- `order_payments`
- `POST /api/v1/orders/{orderId}/payment/collect-cod`
- `POST /api/v1/admin/orders/{orderId}/payment/record-cod`
- `OrderPaymentService`

## 11. Detail Use Case Driver

### UC-DRIVER-01 - Upgrade Customer Menjadi Driver

**Aktor utama:** Customer

**Tujuan:** Customer mendaftar sebagai driver.

**Pemicu:** Customer membuka menu register/upgrade driver.

**Precondition:**

- Customer authenticated.
- User belum memiliki driver profile aktif.

**Alur utama:**

1. Customer mengisi data kendaraan dan identitas driver yang dibutuhkan.
2. Frontend mengirim request upgrade.
3. Backend membuat profile driver dengan status verifikasi awal.
4. Role user berubah menjadi driver atau memiliki driver access sesuai payload.
5. Frontend mengarahkan ke halaman status verifikasi.

**Alternatif/Exception:**

- Customer sudah punya driver profile: backend menolak.
- Data kendaraan wajib belum lengkap: backend menolak.

**Postcondition:**

- User memiliki driver profile dan dapat upload dokumen.

**Data/API terkait:**

- `users`, `drivers`
- `POST /api/user/upgrade-to-driver`

### UC-DRIVER-02 - Upload Dokumen Verifikasi Driver

**Aktor utama:** Driver nonaktif

**Tujuan:** Driver mengirim dokumen KTP, SIM, dan selfie untuk diverifikasi
admin.

**Pemicu:** Driver membuka halaman status verifikasi.

**Precondition:**

- User role driver.
- Driver profile tersedia.

**Alur utama:**

1. Driver membuka halaman verifikasi.
2. Sistem menampilkan status dokumen.
3. Driver memilih file KTP, SIM, dan selfie.
4. Frontend mengirim multipart request.
5. Backend menyimpan file dokumen.
6. Backend mengubah status dokumen menjadi pending.
7. Admin dapat melihat dokumen di antrean verifikasi.

**Alternatif/Exception:**

- File tidak valid: backend menolak.
- Dokumen belum lengkap: status driver tetap pending.
- Dokumen ditolak admin: driver perlu upload ulang.

**Postcondition:**

- Dokumen driver tersimpan dan menunggu review admin.

**Data/API terkait:**

- `drivers`, `driver_documents`
- `GET /api/v1/driver/verification`
- `POST /api/v1/driver/verification/documents`
- `DriverVerificationService`

### UC-DRIVER-03 - Mengatur Online/Offline Driver

**Aktor utama:** Driver aktif

**Tujuan:** Driver menyatakan siap atau tidak siap menerima order.

**Pemicu:** Driver mengubah toggle availability.

**Precondition:**

- Driver sudah active.
- Driver tidak sedang memiliki running order jika ingin offline.

**Alur utama:**

1. Driver membuka driver home.
2. Frontend memuat status availability.
3. Driver menekan online/offline.
4. Backend memvalidasi status driver dan running order.
5. Backend menyimpan status `available`, `busy`, atau `offline`.
6. Jika online, driver dapat menerima incoming order.

**Alternatif/Exception:**

- Driver masih punya running order: backend menolak offline.
- Driver nonaktif: middleware `driver.active` menolak.

**Postcondition:**

- Status availability driver tersimpan.

**Data/API terkait:**

- `drivers`
- `GET /api/v1/driver/availability`
- `PATCH /api/v1/driver/availability`

### UC-DRIVER-04 - Melihat dan Menerima Order Masuk

**Aktor utama:** Driver aktif

**Tujuan:** Driver melihat order yang tersedia dan menerima salah satunya.

**Pemicu:** Driver online atau membuka tab Orderan.

**Precondition:**

- Driver active dan available.
- Ada order `PENDING` dalam radius/dispatch policy.

**Alur utama:**

1. Driver online.
2. Sistem broadcast incoming order ke driver kandidat.
3. Driver membuka daftar order.
4. Driver melihat detail jarak, pickup, dropoff, service type, dan fee.
5. Driver menekan accept.
6. Backend mengunci order dengan transaction.
7. Backend set `driver_id`, status `DRIVER_ASSIGNED`, dan status driver busy.
8. Backend broadcast order removed ke driver lain dan update tracking customer.
9. Frontend navigasi ke active order.

**Alternatif/Exception:**

- Order sudah diterima driver lain: backend menolak accept.
- Driver offline/nonaktif: backend menolak.
- Driver pernah reject order: order tidak diprioritaskan/ditampilkan sesuai
  dispatch rule.

**Postcondition:**

- Order assigned ke driver.
- Driver punya running order.

**Data/API terkait:**

- `orders`, `drivers`, `order_events`
- `GET /api/v1/driver/orders`
- `POST /api/v1/driver/orders/{orderId}/accept`
- `DriverCandidateSelector`
- `DriverOrderRealtimeService`

### UC-DRIVER-05 - Menolak Order Masuk

**Aktor utama:** Driver aktif

**Tujuan:** Driver menolak order yang tidak ingin diambil.

**Pemicu:** Driver menekan reject pada incoming order.

**Precondition:**

- Driver active.
- Order masih pending dan ditawarkan ke driver.

**Alur utama:**

1. Driver melihat incoming order.
2. Driver menekan reject.
3. Backend mencatat reject/idempotent metadata.
4. Order disembunyikan dari driver tersebut.
5. Sistem dapat tetap menawarkan order ke driver lain.

**Alternatif/Exception:**

- Order sudah accepted driver lain: reject tidak mengubah order.
- Driver tidak terkait kandidat: backend dapat menolak atau ignore.

**Postcondition:**

- Driver tidak melihat order yang sama lagi sebagai incoming utama.

**Data/API terkait:**

- `POST /api/v1/driver/orders/{orderId}/reject`
- `OrderService::rejectByDriver`

## 12. Detail Use Case Eksekusi Order oleh Driver

### UC-RIDE-DRIVER-01 - Menjalankan Order Ride

**Aktor utama:** Driver aktif

**Tujuan:** Driver menyelesaikan order Ride dari pickup sampai payment complete.

**Pemicu:** Driver menerima order Ride.

**Precondition:**

- Order `RIDE` sudah `DRIVER_ASSIGNED`.
- Driver adalah assigned driver.

**Alur utama:**

1. Driver menuju titik pickup.
2. Driver menekan `Tiba di Titik Jemput`.
3. Backend mengubah status ke `ARRIVED_PICKUP`.
4. Penumpang naik.
5. Driver menekan `Penumpang Sudah Naik`.
6. Backend mengubah status ke `ON_THE_WAY`.
7. Driver tiba di tujuan.
8. Driver menekan `Tiba di Tujuan`.
9. Backend mengubah status ke `ARRIVED_DROPOFF`.
10. Penumpang turun.
11. Driver menekan `Penumpang Turun`.
12. Backend mengubah status ke `DELIVERED`.
13. Jika payment COD, driver mencatat COD.
14. Driver menekan `Selesaikan Order`.
15. Backend memvalidasi payment paid dan mengubah status ke `COMPLETED`.

**Alternatif/Exception:**

- Pending delivery fee negotiation dapat memblokir `BOARD_PASSENGER`.
- QRIS/transfer belum paid: complete ditolak.
- Driver bukan assigned: backend menolak.

**Postcondition:**

- Order Ride completed.
- Driver dapat available lagi jika tidak ada running order lain.

**Data/API terkait:**

- `POST /api/v1/driver/orders/{orderId}/status-transition`
- `PATCH /api/v1/driver/orders/{orderId}/location`
- `DriverOrderPayloadFactory::rideActionRules`

### UC-COURIER-DRIVER-01 - Menjalankan Order Courier

**Aktor utama:** Driver aktif

**Tujuan:** Driver mengambil paket dan mengantar ke tujuan.

**Pemicu:** Driver menerima order Courier.

**Precondition:**

- Order `COURIER` sudah assigned ke driver.
- Paket sesuai policy dan order belum terminal.

**Alur utama:**

1. Driver menuju pickup.
2. Driver menekan `Tiba di Titik Pickup`.
3. Backend mengubah status ke `ARRIVED_PICKUP`.
4. Driver dapat upload bukti pickup jika diperlukan.
5. Jika COD harus dibayar di pickup, driver mencatat pembayaran.
6. Driver menekan `Paket Diambil`.
7. Backend mengubah status ke `PICKED_UP`.
8. Driver menekan `Mulai Antar`.
9. Backend mengubah status ke `ON_THE_WAY`.
10. Driver tiba di dropoff.
11. Driver menekan `Tiba di Tujuan`.
12. Backend mengubah status ke `ARRIVED_DROPOFF`.
13. Driver menyerahkan paket dan dapat upload bukti delivery.
14. Driver menekan `Paket Diserahkan`.
15. Backend mengubah status ke `DELIVERED`.
16. Driver menekan `Selesaikan Order` setelah payment valid.

**Alternatif/Exception:**

- Barang tidak sesuai saat pickup: driver memilih `Barang Tidak Sesuai`, backend
  membatalkan order jika unpaid.
- Pending revisi ongkir dapat memblokir `CONFIRM_PICKED_UP`.
- Bukti pickup/delivery dengan tipe salah ditolak oleh proof policy.
- Payment belum paid: complete ditolak.

**Postcondition:**

- Order Courier completed atau cancelled jika paket invalid.

**Data/API terkait:**

- `courier_order_details`, `order_evidence`, `order_payments`
- `DriverOrderPayloadFactory::courierActionRules`
- `OrderProofPolicyService`

### UC-SHOPPING-DRIVER-01 - Menjalankan Order Nitip Multi-Merchant

**Aktor utama:** Driver aktif

**Tujuan:** Driver memproses merchant Nitip, membeli item yang tersedia, mengirim
harga merchant, dan mengantar barang ke customer.

**Pemicu:** Driver menerima order Nitip.

**Precondition:**

- Order `SHOPPING` assigned ke driver.
- Merchant sudah dipilih dan locked oleh customer.
- Order memiliki satu sampai tiga pickup merchant.

**Alur utama:**

1. Driver menuju merchant pertama atau merchant yang dipilih berdasarkan kondisi
   lapangan.
2. Driver menekan `Tiba di Toko / Merchant`.
3. Backend mengubah status order global ke `ARRIVED_MERCHANT`.
4. Driver melihat daftar merchant dan status per merchant.
5. Driver memilih merchant dan menekan `Resto buka`.
6. Backend mengubah status merchant ke `OPEN_CONFIRMED`.
7. Driver mengecek ketersediaan item.
8. Driver menyimpan availability item.
9. Jika semua item tersedia, merchant menjadi `ITEMS_CONFIRMED`.
10. Driver mengirim harga merchant.
11. Merchant menjadi `PRICE_PENDING_CUSTOMER`.
12. Customer approve harga atau driver bypass sesuai rule.
13. Merchant menjadi `PRICE_APPROVED`.
14. Driver mengulangi langkah merchant untuk merchant lain.
15. Setelah semua merchant selesai/terminal dan harga approved, driver dapat
    upload struk jika ada.
16. Driver menekan `Belanja Selesai`.
17. Backend mengubah order ke `PICKED_UP`.
18. Driver menekan `Menuju Customer`.
19. Backend mengubah order ke `ON_THE_WAY`.
20. Driver tiba di customer dan menekan `Tiba di Lokasi Customer`.
21. Driver menyerahkan barang dan menekan `Barang Diserahkan`.
22. Backend mengubah status ke `DELIVERED`.
23. Setelah payment valid, driver menekan `Selesaikan Order`.
24. Backend mengubah status ke `COMPLETED`.

**Alternatif/Exception:**

- Merchant tutup: driver menekan `Resto tutup`, merchant menjadi `FAILED`, item
  merchant tidak dihitung, order lanjut ke merchant lain.
- Semua merchant tutup: order menjadi `CANCELLED_WITH_FEE` sesuai rule; ongkir
  final yang sudah disetujui tetap dipakai jika locked.
- Ada item tidak tersedia: merchant menjadi `ITEMS_PENDING_CUSTOMER` dan customer
  harus memilih edit, lanjut tanpa item, atau batal merchant.
- Harga merchant menunggu customer terlalu lama: driver dapat bypass dengan
  audit event.
- Harga merchant sudah approved: availability item merchant terkunci.
- Pending item decision: card harga tidak ditampilkan sampai item fix.
- Receipt/struk bersifat opsional sesuai flow final, tetapi jika diupload harus
  memakai tipe proof yang benar.

**Postcondition:**

- Order Nitip completed, cancelled, atau cancelled with fee.
- Order menyimpan audit merchant, item, harga, payment, dan proof.

**Data/API terkait:**

- `order_locations`, `shopping_order_items`, `shopping_order_receipts`,
  `order_fee_lines`, `order_events`, `order_evidence`
- `POST /api/v1/driver/orders/{orderId}/shopping-stops/{pickupLocationId}/open`
- `PATCH /api/v1/driver/orders/{orderId}/shopping-items`
- `POST /api/v1/driver/orders/{orderId}/shopping/price-quote`
- `POST /api/v1/driver/orders/{orderId}/shopping/price-quote/bypass`
- `PATCH /api/v1/driver/orders/{orderId}/shopping-checkout`
- `ShoppingOrderCapabilityService`
- `ShoppingPriceNegotiationService`
- `ShoppingPricingService`

## 13. Detail Use Case Pricing

### UC-PRICING-01 - Driver Mengajukan Revisi Ongkir

**Aktor utama:** Driver aktif

**Tujuan:** Driver mengajukan ongkir manual/revisi ketika kondisi lapangan
membutuhkan biaya berbeda.

**Pemicu:** Driver menekan edit/revisi ongkir pada active order.

**Precondition:**

- Driver assigned ke order.
- Order berada pada status editable untuk service type terkait.
- Payment belum paid dan tidak ada bukti transfer pending yang memblokir.

**Alur utama:**

1. Driver membuka active order.
2. Driver memilih revisi ongkir.
3. Driver memasukkan nominal dan alasan.
4. Backend memvalidasi status, driver, nominal, dan payment.
5. Backend mencatat event `DELIVERY_FEE_NEGOTIATION`.
6. Customer menerima update harga dan dapat merespons.
7. Pending approval dapat memblokir progress driver pada action tertentu.

**Alternatif/Exception:**

- Order sudah melewati cutoff: backend menolak.
- Payment sudah paid: backend menolak.
- Nominal invalid: backend menolak.

**Postcondition:**

- Revisi ongkir berada pada status `PENDING_CUSTOMER`.

**Data/API terkait:**

- `order_events`
- `POST /api/v1/driver/orders/{orderId}/delivery-fee-override`
- `DeliveryFeeNegotiationService`

### UC-PRICING-02 - Customer Merespons Revisi Ongkir

**Aktor utama:** Customer

**Tujuan:** Customer menyetujui, menawar, atau membatalkan order karena revisi
ongkir.

**Pemicu:** Customer melihat card revisi ongkir di tracking.

**Precondition:**

- Ada revisi ongkir pending customer.
- Order milik customer dan masih editable.

**Alur utama approve:**

1. Customer membuka tracking.
2. Customer melihat nominal revisi ongkir.
3. Customer menekan setuju.
4. Backend mencatat approval.
5. Backend memperbarui delivery fee/total order.
6. Driver dapat melanjutkan action yang sebelumnya diblokir.

**Alur counter:**

1. Customer mengirim nominal tawaran.
2. Backend mencatat status `PENDING_DRIVER`.
3. Driver dapat menerima atau mengirim requote.

**Alur cancel:**

1. Customer memilih batal order.
2. Backend membatalkan order sesuai rule.

**Alternatif/Exception:**

- Payment sudah paid: backend menolak response.
- Order sudah terminal: backend menolak.
- Untuk Nitip final, approval ongkir mengunci ongkir agar tidak dihitung ulang
  saat merchant tutup.

**Postcondition:**

- Revisi ongkir memiliki status final atau menunggu driver.

**Data/API terkait:**

- `POST /api/v1/orders/{orderId}/delivery-fee-override/respond`
- `POST /api/v1/driver/orders/{orderId}/delivery-fee-override/accept-counter`
- `DeliveryFeeNegotiationService`

## 14. Detail Use Case Nitip Customer

### UC-SHOPPING-01 - Customer Menambah/Edit Item Nitip Sebelum Driver Assigned

**Aktor utama:** Customer

**Tujuan:** Customer mengubah daftar belanja sebelum order diproses driver.

**Pemicu:** Customer membuka tambah item pada order Nitip status `PENDING`.

**Precondition:**

- Order `SHOPPING` milik customer.
- Status order `PENDING`.
- Merchant/item masih editable.

**Alur utama:**

1. Customer membuka halaman tambah item.
2. Customer memilih merchant fixed atau merchant baru sesuai fase draft.
3. Customer mengisi item, jumlah, dan catatan.
4. Frontend mengirim item bulk ke backend.
5. Backend memvalidasi merchant, item, source, dan rute.
6. Backend menambah item.
7. Backend recalculates pricing jika merchant/rute berubah.
8. Frontend menampilkan detail order terbaru.

**Alternatif/Exception:**

- Order sudah driver assigned: backend menolak direct edit.
- Merchant baru melebihi batas tiga: backend menolak.
- Item menu DB dari merchant berbeda: backend menolak.

**Postcondition:**

- Item order berubah dan pricing terbaru tersimpan.

**Data/API terkait:**

- `shopping_order_items`, `order_locations`, `order_fee_lines`
- `POST /api/v1/orders/{orderId}/items`
- `POST /api/v1/orders/{orderId}/items/bulk`
- `PATCH /api/v1/orders/{orderId}/items/{itemId}`
- `DELETE /api/v1/orders/{orderId}/items/{itemId}`

### UC-SHOPPING-02 - Customer Memutuskan Item Nitip Tidak Tersedia

**Aktor utama:** Customer

**Tujuan:** Customer menyelesaikan masalah item tidak tersedia di merchant.

**Pemicu:** Driver menandai satu atau lebih item sebagai tidak tersedia.

**Precondition:**

- Order `SHOPPING` berada di fase merchant/item decision.
- Ada item aktif dengan `is_available=false`.
- Merchant terkait belum terminal.

**Alur utama edit item:**

1. Customer membuka tracking.
2. Sistem menampilkan item tidak tersedia.
3. Customer memilih `Edit`.
4. Frontend membuka screen tambah item dengan merchant readonly.
5. Customer memasukkan item pengganti.
6. Backend langsung menerapkan item baru pada merchant yang sama.
7. Backend menghapus item lama yang tidak tersedia.
8. Merchant menjadi `ITEMS_CONFIRMED`.
9. Harga merchant menjadi `NEEDS_REQUOTE`.
10. Driver melihat item final dan mengirim harga baru.

**Alur lanjut tanpa item:**

1. Customer memilih `Lanjut tanpa ini`.
2. Backend memvalidasi merchant masih punya minimal satu item aktif setelah item
   dihapus.
3. Backend menghapus item tidak tersedia.
4. Merchant menjadi `ITEMS_CONFIRMED`.
5. Harga merchant menjadi `NEEDS_REQUOTE`.

**Alur batal merchant:**

1. Customer memilih `Batal merchant`.
2. Backend menandai merchant `FAILED`/terminal sesuai rule.
3. Item merchant tidak dihitung.
4. Order lanjut jika masih ada merchant aktif.
5. Jika semua merchant terminal, order menjadi cancelled/cancelled with fee sesuai
   rule.

**Alternatif/Exception:**

- Merchant hanya punya satu item dan item itu tidak tersedia: tombol `Lanjut
  tanpa ini` tidak muncul dan backend menolak remove item.
- Customer mencoba memilih merchant lain saat edit: backend menolak.
- Ada pending item decision: driver tidak melihat card harga sampai customer
  memutuskan.

**Postcondition:**

- Merchant keluar dari `ITEMS_PENDING_CUSTOMER`.
- Driver dapat melanjutkan quote harga atau merchant terminal.

**Data/API terkait:**

- `shopping_order_items`, `order_locations`, `order_events`
- `POST /api/v1/orders/{orderId}/shopping/item-change-request`
- `ShoppingOrderCapabilityService`
- target final: `ShoppingUnavailableItemDecisionService`

### UC-SHOPPING-03 - Customer Merespons Harga Merchant Nitip

**Aktor utama:** Customer

**Tujuan:** Customer menyetujui harga barang merchant atau membatalkan merchant.

**Pemicu:** Driver mengirim quote harga merchant.

**Precondition:**

- Merchant status `PRICE_PENDING_CUSTOMER`.
- Quote harga merchant tersedia.
- Customer adalah pemilik order.

**Alur utama approve:**

1. Customer membuka tracking.
2. Sistem menampilkan harga merchant.
3. Customer menekan setuju.
4. Backend mencatat approval.
5. Merchant menjadi `PRICE_APPROVED`.
6. Subtotal barang/order pricing diperbarui.
7. Driver dapat lanjut merchant berikutnya atau checkout.

**Alur batal merchant:**

1. Customer memilih batal merchant.
2. Backend menandai merchant terminal.
3. Item merchant tidak dihitung.
4. Jika ada merchant lain, order lanjut.

**Alur driver bypass:**

1. Jika customer tidak merespons, driver memilih bypass.
2. Backend mencatat event bypass.
3. Merchant menjadi `PRICE_APPROVED`.
4. Customer mendapat notifikasi bahwa harga dilanjutkan.

**Alternatif/Exception:**

- Quote bukan merchant order: backend menolak.
- Merchant belum `ITEMS_CONFIRMED`: driver tidak boleh submit harga.
- Harga sudah approved: response ulang ditolak/idempotent.

**Postcondition:**

- Harga merchant memiliki keputusan final.

**Data/API terkait:**

- `order_events`, `order_fee_lines`, `shopping_order_items`,
  `order_locations`
- `POST /api/v1/orders/{orderId}/shopping/price-quote/respond`
- `ShoppingPriceNegotiationService`

## 15. Detail Use Case Riwayat

### UC-HISTORY-01 - Customer Melihat Riwayat Order

**Aktor utama:** Customer

**Tujuan:** Customer melihat order yang sudah selesai atau dibatalkan.

**Pemicu:** Customer membuka tab Riwayat.

**Precondition:**

- Customer authenticated.

**Alur utama:**

1. Customer membuka Riwayat.
2. Frontend mengambil order completed/cancelled.
3. Backend mengembalikan list order milik customer.
4. Customer membuka detail.
5. Sistem menampilkan summary, route, payment, item shopping jika ada, proof, dan
   timeline.

**Alternatif/Exception:**

- Tidak ada riwayat: frontend menampilkan empty state.
- Detail order tidak ditemukan: frontend menampilkan error.

**Postcondition:**

- Customer dapat melihat histori transaksi.

**Data/API terkait:**

- `GET /api/v1/orders`
- `GET /api/v1/orders/{orderId}`

### UC-HISTORY-02 - Driver Melihat Riwayat dan Pendapatan

**Aktor utama:** Driver aktif

**Tujuan:** Driver melihat order selesai, detail order, dan pendapatan.

**Pemicu:** Driver membuka tab Riwayat Driver.

**Precondition:**

- Driver active.

**Alur utama:**

1. Driver membuka Riwayat.
2. Frontend mengambil history driver.
3. Backend mengembalikan list order completed/cancelled with fee yang relevan.
4. Frontend menampilkan total order selesai dan pendapatan.
5. Driver memilih salah satu order.
6. Backend mengembalikan detail order.
7. Frontend menampilkan breakdown biaya, proof, status timeline, dan item belanja
   tanpa harga per item.

**Alternatif/Exception:**

- Order semua merchant tutup: history tetap menampilkan income/fee sesuai backend
  dan tidak jatuh ke `Rp0` jika ada ongkir locked.
- Tidak ada riwayat: frontend menampilkan empty state.

**Postcondition:**

- Driver dapat melihat pendapatan yang konsisten dengan order backend.

**Data/API terkait:**

- `GET /api/v1/driver/history`
- `GET /api/v1/driver/orders/{orderId}`
- `DriverOrderPayloadFactory`

## 16. Detail Use Case Admin

### UC-ADMIN-01 - Admin Login Web Panel

**Aktor utama:** Admin

**Tujuan:** Admin masuk ke panel web operasional.

**Pemicu:** Admin membuka `/admin/login`.

**Precondition:**

- User admin tersedia di database.

**Alur utama:**

1. Admin membuka login.
2. Admin mengisi email/password.
3. Backend memvalidasi credentials dan role.
4. Jika role admin, session web dibuat.
5. Admin diarahkan ke dashboard.

**Alternatif/Exception:**

- Role bukan admin: akses ditolak.
- Password salah: login gagal.

**Postcondition:**

- Admin memiliki session web aktif.

**Data/API terkait:**

- `users`, `sessions`
- `GET /admin/login`
- `POST /admin/login`
- `AdminAuthController`

### UC-ADMIN-02 - Admin Mengelola Merchant/Restoran

**Aktor utama:** Admin

**Tujuan:** Admin membuat, mengubah, menghapus, dan mengaktifkan/nonaktifkan
merchant.

**Pemicu:** Admin membuka menu restoran.

**Precondition:**

- Admin login.

**Alur utama create/update:**

1. Admin membuka daftar restoran.
2. Admin memilih tambah atau edit.
3. Admin mengisi nama, slug, alamat, koordinat, phone, status, dan metadata lain.
4. Backend memvalidasi form.
5. Backend menyimpan data restoran.
6. Admin kembali ke daftar dengan pesan sukses.

**Alur toggle status:**

1. Admin memilih merchant.
2. Admin menekan toggle status.
3. Backend mengubah status active/inactive.

**Alternatif/Exception:**

- Slug duplikat: backend menolak.
- Merchant punya relasi order/menu tertentu: delete dapat dibatasi sesuai rule.

**Postcondition:**

- Data merchant berubah dan katalog publik mengikuti status terbaru.

**Data/API terkait:**

- `restaurants`
- `/admin/restoran`
- `Admin\RestaurantController`

### UC-ADMIN-03 - Admin Mengelola Menu

**Aktor utama:** Admin

**Tujuan:** Admin mengelola menu pada merchant BangDeliv.

**Pemicu:** Admin membuka menu dari detail restoran.

**Precondition:**

- Admin login.
- Restaurant target tersedia.

**Alur utama:**

1. Admin membuka daftar menu merchant.
2. Admin menambah atau mengubah menu.
3. Backend memvalidasi nama, harga, kategori, availability, dan sort order.
4. Backend menyimpan menu.
5. Menu muncul di katalog frontend jika tersedia.

**Alternatif/Exception:**

- Menu bukan milik restaurant target: backend menolak update/delete.
- Harga invalid: backend menolak.

**Postcondition:**

- Menu merchant tersimpan.

**Data/API terkait:**

- `menus`, `menu_categories`
- `/admin/restoran/{restaurant}/menus`
- `Admin\RestaurantMenuController`

### UC-ADMIN-04 - Admin Memverifikasi Driver

**Aktor utama:** Admin

**Tujuan:** Admin menyetujui atau menolak dokumen driver.

**Pemicu:** Admin membuka antrean verifikasi driver.

**Precondition:**

- Admin login.
- Driver sudah upload dokumen.

**Alur utama:**

1. Admin membuka daftar verifikasi.
2. Admin memfilter/search driver jika diperlukan.
3. Admin membuka detail driver.
4. Admin preview dokumen KTP, SIM, dan selfie.
5. Admin memilih approve/reject untuk tiap dokumen.
6. Backend menyimpan keputusan review.
7. Backend menentukan registration status driver:
   - active jika dokumen lengkap approved;
   - rejected jika ada dokumen rejected;
   - pending jika belum lengkap.
8. Driver melihat status terbaru di aplikasi.

**Alternatif/Exception:**

- Dokumen tidak ditemukan: backend menampilkan error.
- Admin menghapus dokumen invalid agar driver upload ulang.

**Postcondition:**

- Status verifikasi driver terupdate.

**Data/API terkait:**

- `drivers`, `driver_documents`
- `/admin/driver/verifikasi`
- `GET /api/v1/admin/drivers/verification`
- `POST /api/v1/admin/drivers/{driverId}/verification/review`
- `DriverVerificationService`

### UC-ADMIN-05 - Admin Melihat Order dan Audit

**Aktor utama:** Admin

**Tujuan:** Admin memantau detail order, status, payment, proof, chat/audit log,
dan timeline.

**Pemicu:** Admin membuka menu pesanan.

**Precondition:**

- Admin login.

**Alur utama:**

1. Admin membuka daftar pesanan.
2. Admin memilih order.
3. Backend memuat relasi customer, driver, service type, status, item, fee lines,
   receipt, courier/ride detail, locations, evidence, payments, timeline, dan
   logs.
4. Admin membaca detail untuk kebutuhan monitoring/support.

**Alternatif/Exception:**

- Order tidak ditemukan: admin kembali ke daftar.
- Back URL tidak valid: sistem menggunakan default route pesanan.

**Postcondition:**

- Admin mendapat visibility transaksi dan audit.

**Data/API terkait:**

- `orders`, `order_events`, `order_status_histories`, `order_payments`,
  `order_evidence`
- `/admin/pesanan`
- `/admin/pesanan/{order}`

### UC-ADMIN-06 - Admin Melihat Settlement COD

**Aktor utama:** Admin

**Tujuan:** Admin melihat laporan pembayaran COD untuk settlement.

**Pemicu:** Admin membuka laporan COD atau memanggil endpoint settlement.

**Precondition:**

- Admin authenticated.

**Alur utama:**

1. Admin memilih periode/filter settlement.
2. Backend mengambil payment COD paid sesuai filter.
3. Backend mengembalikan laporan settlement.
4. Admin melihat total, order, dan collector terkait.

**Alternatif/Exception:**

- Filter tanggal invalid: backend menolak.
- Tidak ada transaksi: sistem mengembalikan list kosong.

**Postcondition:**

- Admin mendapat data settlement COD.

**Data/API terkait:**

- `order_payments`
- `GET /api/v1/admin/payments/cod-settlement`
- `OrderService::codSettlementReport`

## 17. Detail Use Case Sistem Pendukung

### UC-SYSTEM-01 - Mengirim Realtime Update

**Aktor utama:** Sistem

**Tujuan:** Mengirim perubahan order, lokasi, chat, dan incoming order secara
realtime.

**Pemicu:** Terjadi perubahan status order, lokasi driver, pesan chat, pricing,
atau dispatch order.

**Precondition:**

- Reverb/WebSocket berjalan.
- User memiliki hak subscribe channel.

**Alur utama:**

1. Backend menyimpan perubahan domain.
2. Backend membuat event broadcast.
3. Channel authorization memastikan user terkait order/driver.
4. Frontend menerima event.
5. Provider frontend invalidate atau update state.
6. UI berubah tanpa user melakukan refresh manual.

**Alternatif/Exception:**

- Broadcast gagal: perubahan domain tetap tersimpan, frontend bisa refresh manual.
- User tidak authorized channel: subscribe ditolak.

**Postcondition:**

- State frontend mendekati realtime.

**Data/API terkait:**

- `routes/channels.php`
- `OrderRealtimeBroadcaster`
- `DriverOrderRealtimeService`
- frontend realtime providers

### UC-SYSTEM-02 - Mengirim Push Notification

**Aktor utama:** Sistem

**Tujuan:** Mengirim notifikasi push untuk event penting order, chat, payment, dan
pricing.

**Pemicu:** Ada event seperti order assigned, price changed, chat message, payment
reminder, atau status update.

**Precondition:**

- User memiliki device token aktif.
- FCM/Firebase service tersedia.

**Alur utama:**

1. Frontend mendaftarkan device token.
2. Backend menyimpan token aktif.
3. Saat event penting terjadi, backend memilih recipient.
4. Backend mengirim push notification.
5. Jika token invalid, backend menonaktifkan token tanpa menggagalkan transaksi
   utama.

**Alternatif/Exception:**

- FCM error: transaksi utama tetap sukses.
- Token sudah berpindah user: backend memindahkan token ke user terbaru.

**Postcondition:**

- User menerima notifikasi atau token invalid dinonaktifkan.

**Data/API terkait:**

- `device_tokens`
- `POST /api/v1/device-tokens`
- `DELETE /api/v1/device-tokens`
- `DeviceTokenService`
- notification services

## 18. Relasi Use Case dengan Data Utama

| Data | Dipakai oleh use case |
| --- | --- |
| `users` | Auth, profile, customer, driver, admin. |
| `drivers` | Driver verification, availability, dispatch, driver history. |
| `driver_documents` | Upload dan review dokumen driver. |
| `addresses` | Address book customer, pickup/dropoff default, chatbot. |
| `restaurants`, `menus` | Katalog, merchant picker, item Nitip, admin CRUD. |
| `orders` | Semua transaksi service type. |
| `order_locations` | Pickup/dropoff dan merchant stop Nitip. |
| `shopping_order_items` | Item Nitip dan keputusan availability. |
| `ride_order_details` | Detail khusus Ride. |
| `courier_order_details` | Detail khusus Courier. |
| `shopping_order_receipts` | Receipt/checkout Nitip. |
| `order_fee_lines` | Breakdown biaya order. |
| `order_events` | Audit pricing, status, payment, shopping, dan notification. |
| `order_status_histories` | Timeline status order. |
| `order_evidence` | Bukti proof, struk, toko tutup, transfer. |
| `order_payments` | COD/transfer payment status. |
| `order_chat_messages`, `order_chat_reads` | Chat dan unread count. |
| `ai_chat_sessions`, `ai_chat_messages` | Draft dan riwayat chatbot. |
| `device_tokens` | Push notification. |

## 19. Use Case Prioritas untuk Testing

Use case yang paling penting diuji karena berisiko tinggi:

1. Customer membuat order Ride dari chatbot dan map picker.
2. Customer membuat order Courier dengan paket valid, ambigu, dan terlarang.
3. Customer membuat order Nitip multi-merchant maksimal tiga merchant.
4. Driver menerima order dan driver lain tidak bisa menerima order yang sama.
5. Driver menjalankan Ride sampai completed dengan COD/TRANSFER.
6. Driver menjalankan Courier dengan proof dan invalid package.
7. Driver menjalankan Nitip:
   - merchant buka;
   - merchant tutup;
   - item tidak tersedia;
   - harga merchant approved;
   - semua merchant tutup.
8. Revisi ongkir:
   - driver quote;
   - customer approve;
   - customer counter;
   - driver accept counter;
   - flow Nitip tidak recalculate ongkir locked.
9. Payment:
   - COD collect;
   - transfer evidence;
   - complete blocked saat unpaid.
10. Driver verification:
    - upload docs;
    - admin approve;
    - admin reject.
11. Realtime:
    - order status changed;
    - driver location updated;
    - chat unread count.

## 20. Catatan Implementasi untuk Menjaga Use Case Tetap Konsisten

- Jangan menghitung capability action di banyak widget frontend. Backend harus
  mengirim capability, frontend hanya render.
- Jangan menghitung ulang income/fee driver di frontend. Backend serializer
  menjadi sumber nilai.
- Untuk Nitip, status order global dan status merchant harus tetap dipisahkan.
- Untuk pricing, satu resolver harus menentukan apakah ongkir boleh dihitung
  ulang atau harus memakai nilai locked.
- Untuk proof, gunakan `OrderProofPolicyService` agar tipe proof tidak bercampur
  antar service type.
- Untuk route/API, gunakan `ApiRouteContractTest` setiap menambah endpoint yang
  dipakai Flutter.
- Untuk flow chatbot, draft session harus bisa recovery dari history agar user
  bisa melanjutkan percakapan setelah app reload.
- Untuk admin, aksi destruktif seperti delete dokumen/menu/merchant harus tetap
  punya validasi ownership/relasi.

