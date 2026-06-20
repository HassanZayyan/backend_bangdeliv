# Ringkasan Progress BangDeliv untuk Bimbingan Dosen

Dokumen ini merangkum progres teknis BangDeliv dari sisi database, fitur utama,
API, dependency, dan testing. Urutan penjelasan dibuat dari pondasi database
terlebih dahulu, lalu naik ke fitur dan integrasi aplikasi.

## 1. Gambaran Sistem

BangDeliv adalah aplikasi layanan pengantaran berbasis role:

- Customer: membuat pesanan, menyimpan alamat, melihat restoran/menu, checkout,
  melacak order, dan chat dengan driver.
- Driver: melakukan verifikasi akun, mengatur status kerja, menerima/menolak
  order, mengirim lokasi realtime, mengubah status perjalanan, dan mencatat COD.
- Admin: memverifikasi driver, melihat order, mengelola restoran/menu, dan
  memantau transaksi.

Backend menggunakan Laravel 12 sebagai REST API, Laravel Sanctum untuk
autentikasi token mobile, dan Laravel Reverb untuk WebSocket realtime.

## 2. Fungsi Tabel Database

### 2.1 Autentikasi dan User

| Tabel | Fungsi |
| --- | --- |
| `users` | Menyimpan akun utama customer, driver, dan admin. Kolom penting: `role`, `phone`, `is_active`, `is_blacklisted`, statistik order, avatar, soft delete. |
| `personal_access_tokens` | Token API Laravel Sanctum untuk autentikasi mobile berbasis Bearer token. |
| `device_tokens` | Menyimpan token perangkat Android/iOS/web untuk kebutuhan push notification. |
| `password_reset_tokens` | Menyimpan token reset password. |
| `sessions` | Menyimpan session untuk kebutuhan web/admin Laravel. |

### 2.2 Driver dan Verifikasi

| Tabel | Fungsi |
| --- | --- |
| `drivers` | Profil driver yang terhubung 1:1 ke `users`. Menyimpan data kendaraan, status verifikasi (`pending`, `active`, `rejected`, `suspended`), status kerja (`available`, `busy`, `offline`), koordinat terakhir, rating, dan total delivery. |
| `driver_documents` | Dokumen verifikasi driver seperti KTP, SIM, dan selfie. Mendukung status `pending`, `approved`, `rejected`, alasan penolakan, waktu verifikasi, dan admin verifier. |

### 2.3 Restoran dan Menu

| Tabel | Fungsi |
| --- | --- |
| `restaurants` | Data restoran/merchant: nama, slug, alamat, koordinat, nomor telepon, banner, status aktif, rating, dan estimasi persiapan. |
| `menu_categories` | Kategori menu per restoran. |
| `menus` | Data menu: nama, deskripsi, harga, gambar, ketersediaan, kategori, dan urutan tampil. |

### 2.4 Alamat dan Master Order

| Tabel | Fungsi |
| --- | --- |
| `addresses` | Alamat tersimpan customer: label, penerima, telepon, alamat lengkap, detail, koordinat, dan default address. |
| `service_types` | Master jenis layanan: `RIDE`, `COURIER`, `SHOPPING`. |
| `order_statuses` | Master status order: `PENDING`, `DRIVER_ASSIGNED`, `ARRIVED_PICKUP`, `PICKED_UP`, `ON_THE_WAY`, `DELIVERED`, `COMPLETED`, `CANCELLED`, dan status lain. |
| `service_fee_rules` | Aturan biaya layanan berbasis JSON, misalnya surcharge titip belanja, surcharge barang berat, penalti pembatalan, dan auto-confirm bukti kurir. |
| `orders` | Tabel induk semua order. Menyimpan nomor order, customer, jenis layanan, driver opsional, subtotal, ongkir, service fee, jarak/rute, total, status, alasan batal, estimasi, dan waktu selesai. |
| `order_locations` | Titik lokasi order, misalnya `PICKUP` dan `DROPOFF`, lengkap dengan alamat, kontak, koordinat, dan urutan. |

### 2.5 Detail Order per Jenis Layanan

| Tabel | Fungsi |
| --- | --- |
| `ride_order_details` | Detail khusus layanan antar jemput orang. Menyimpan waktu driver menjemput dan tiba. |
| `courier_order_details` | Detail khusus kurir barang: deskripsi paket, estimasi berat/dimensi, klasifikasi ukuran, status keamanan paket, catatan packing, deadline konfirmasi, komplain, dan auto-confirm. |
| `shopping_order_items` | Item belanja untuk layanan `SHOPPING`, bisa dari menu database atau input manual. Menyimpan snapshot nama menu, harga, subtotal, service fee per item, ketersediaan, catatan, dan metadata. |
| `shopping_order_receipts` | Ringkasan nota layanan `SHOPPING`, termasuk total nota, sumber total, catatan driver, dan waktu konfirmasi. |

Catatan integritas:

- Tabel `ride_order_details`, `courier_order_details`, `shopping_order_items`, dan `shopping_order_receipts`
  memiliki trigger MySQL agar data hanya masuk ke service type yang sesuai.
- `orders` menjadi pusat current-state. Tabel detail hanya dipakai untuk data
  yang memang spesifik layanan atau berjumlah banyak.

### 2.6 Riwayat, Pembayaran, Bukti, dan Audit

| Tabel | Fungsi |
| --- | --- |
| `order_events` | Timeline audit order untuk perubahan status, harga, item, payment, dan event sistem. Menyimpan status lama/baru, user pengubah, catatan, metadata delta harga, dan waktu perubahan. |
| `order_fee_lines` | Rincian komponen biaya order, misalnya ongkir dasar, surcharge item, surcharge barang berat, atau komponen fee lain. |
| `order_payments` | Pembayaran order, saat ini fokus COD. Menyimpan status `PENDING`, `PAID`, `VOID`, nominal, pencatat, driver, waktu bayar, dan metadata. |
| `order_evidence` | Bukti foto untuk kurir/shopping, misalnya foto delivery, receiver, atau struk belanja. Ada status verifikasi manual atau auto approval. |

### 2.7 Chat dan AI

| Tabel | Fungsi |
| --- | --- |
| `order_chat_messages` | Pesan chat antara customer dan driver di satu order. Menyimpan pengirim, role pengirim, snapshot nama, isi pesan, dan `client_message_id` untuk deduplikasi. |
| `order_chat_reads` | Status baca chat per user per order. Menyimpan `last_read_message_id` dan `read_at`, dipakai untuk badge unread. |
| `ai_chat_sessions` | Ringkasan session chatbot per user, termasuk session ID, judul, waktu pesan terakhir, dan status. |
| `ai_chat_messages` | Pesan user/assistant chatbot, respons AI, model yang dipakai, intent, dan order terkait. |
| `ai_message_details` | Detail tambahan untuk satu pesan AI, seperti request/response mentah, token usage, latency, dan metadata teknis. |

### 2.8 Tabel Sistem Laravel

| Tabel | Fungsi |
| --- | --- |
| `cache`, `cache_locks` | Penyimpanan cache Laravel jika memakai database driver. |
| `jobs`, `job_batches`, `failed_jobs` | Infrastruktur queue Laravel. |

## 3. Relasi Database Penting

- `users` 1:1 `drivers` untuk akun driver.
- `drivers` 1:N `driver_documents`.
- `users` 1:N `addresses`, `orders`, `device_tokens`, `ai_chat_sessions`, dan `ai_chat_messages`.
- `restaurants` 1:N `menu_categories`, `menus`, dan `order_locations` sebagai titik pickup merchant.
- `orders` N:1 `users`, N:1 `service_types`, N:1 `order_statuses`, N:1 `drivers`.
- `orders` 1:N `order_locations`, `shopping_order_items`, `order_events`, `order_fee_lines`, `order_evidence`, dan `order_chat_messages`.
- `orders` 1:1 `order_payments`.
- `orders` 1:1 detail layanan yang relevan: `ride_order_details`, `courier_order_details`, atau `shopping_order_receipts`.

## 4. Fitur Penting yang Sudah Dibangun

### 4.1 Autentikasi dan Role

- Register customer.
- Login/logout.
- Endpoint profil user.
- Update profil, password, dan alamat.
- Upgrade customer menjadi driver.
- Middleware role untuk membedakan akses customer, driver, dan admin.
- Autentikasi mobile memakai Laravel Sanctum Bearer token.

### 4.2 Driver Onboarding dan Verifikasi

- Driver mengirim data kendaraan dan dokumen KTP/SIM/selfie.
- Admin dapat melihat, menyetujui, atau menolak dokumen.
- Driver hanya dapat menerima order setelah `registration_status = active`.
- Driver memiliki status kerja: `available`, `busy`, `offline`.

### 4.3 Restoran dan Menu

- Home API untuk data ringkas aplikasi.
- List restoran, detail restoran, dan menu.
- Menu restoran dipakai sebagai katalog resmi untuk layanan `SHOPPING`.
- Item dari menu database disimpan sebagai snapshot di order agar histori harga
  tidak berubah ketika master menu diedit.

### 4.4 Order Multi Layanan

Jenis layanan utama:

- `RIDE`: antar jemput orang berbasis titik pickup dan dropoff.
- `COURIER`: pengiriman barang, termasuk validasi paket dan bukti foto.
- `SHOPPING`: titip belanja dengan item manual/menu, surcharge item banyak,
  surcharge barang berat, dan rekalkulasi harga.

Fitur order:

- Pembuatan order customer.
- Validasi alamat dan jarak.
- Hitung ongkir berdasarkan jarak Google Maps.
- Assignment driver.
- Driver accept/reject order.
- Status transition sesuai alur layanan.
- Riwayat status dan audit perubahan harga disatukan di `order_events`.
- Cancel order dan failed attempt.

### 4.5 Realtime Order dan Tracking

Realtime menggunakan Laravel Reverb dengan private channel:

- `private-driver.orders.user.{userId}`
  - Event `driver.order.available`: order baru masuk ke driver aktif.
  - Event `driver.order.removed`: order dihapus dari daftar driver lain setelah diambil/berubah.

- `private-order.tracking.{orderId}`
  - Event `order.chat.message.sent`: chat customer-driver.
  - Event status order: perubahan status order.
  - Event lokasi driver: update GPS driver.

Perbaikan realtime terakhir:

- Broadcast auth `/broadcasting/auth` memakai middleware `api` dan
  `auth:sanctum`, sehingga Flutter bisa authorize private channel dengan
  Bearer token.
- Channel `order.tracking`, `driver.orders.user`, dan `App.Models.User`
  memakai guard `sanctum`.
- Flutter menjaga listener realtime driver di level root app, sehingga chat
  dan order masuk tetap aktif walaupun user tidak sedang berada di screen
  driver tertentu.
- Ada diagnostics non-sensitive via `REALTIME_DIAGNOSTICS=true` untuk melihat
  koneksi, auth status, subscribe, dan event realtime tanpa mencetak token.

### 4.6 Chat Order dan Unread Badge

- Customer dan driver dapat chat setelah driver ditugaskan.
- Pesan disimpan di `order_chat_messages`.
- `client_message_id` dipakai agar pesan optimistic dari frontend tidak double.
- Unread badge dihitung dari `order_chat_reads`.
- Mark read memakai endpoint backend sebagai source of truth.
- Chat dikirim realtime lewat `order.chat.message.sent`.

### 4.7 Lokasi Driver

- Driver mengirim lokasi terbaru melalui endpoint driver.
- Backend broadcast lokasi ke channel `private-order.tracking.{orderId}`.
- Customer dapat melihat posisi driver pada map secara realtime.

### 4.8 Pembayaran COD

- Order memakai COD.
- Driver/admin dapat mencatat pembayaran COD.
- Data tersimpan di `order_payments`.
- Ada laporan settlement COD untuk admin.

### 4.9 Chatbot

- Chatbot membantu proses order dan validasi input.
- Menggunakan Gemini untuk ekstraksi intent dan data order.
- Mendukung konteks percakapan via `session_id`.
- Session disimpan di `ai_chat_sessions`, pesan di `ai_chat_messages`, dan detail
  teknis AI di `ai_message_details`.

## 5. API Endpoint Penting

### 5.1 Public

| Method | Endpoint | Fungsi |
| --- | --- | --- |
| POST | `/api/auth/register/customer` | Register customer. |
| POST | `/api/auth/login` | Login dan membuat Sanctum token. |
| GET | `/api/v1/home` | Data home aplikasi. |
| GET | `/api/v1/restaurants` | List restoran. |
| GET | `/api/v1/restaurants/{restaurantIdOrSlug}` | Detail restoran. |
| GET | `/api/v1/restaurants/{restaurantIdOrSlug}/menus` | List menu restoran. |

### 5.2 User Terautentikasi

| Method | Endpoint | Fungsi |
| --- | --- | --- |
| POST | `/api/auth/logout` | Logout. |
| GET | `/api/user` | Profil user. |
| PUT | `/api/user` | Update profil. |
| PUT | `/api/user/password` | Ubah password. |
| POST | `/api/user/upgrade-to-driver` | Upgrade customer ke driver. |
| POST | `/api/user/addresses/validate` | Validasi alamat. |
| POST/PUT/DELETE | `/api/user/addresses` | CRUD alamat customer. |

### 5.3 Customer

| Method | Endpoint | Fungsi |
| --- | --- | --- |
| POST | `/api/v1/orders/ride/validate-destination` | Validasi tujuan ride. |
| POST | `/api/v1/orders/ride` | Buat order ride. |
| GET | `/api/v1/orders` | List order customer. |
| GET | `/api/v1/orders/{orderId}` | Detail order customer. |
| POST | `/api/v1/orders/{orderId}/cancel` | Batalkan order. |
| POST/PATCH/DELETE | `/api/v1/orders/{orderId}/items` | Kelola item shopping order. |
| PATCH | `/api/v1/orders/{orderId}/payment-method` | Ubah metode pembayaran order. |

### 5.4 Chat Order

| Method | Endpoint | Fungsi |
| --- | --- | --- |
| GET | `/api/v1/orders/{orderId}/chat/messages` | Ambil pesan chat. |
| POST | `/api/v1/orders/{orderId}/chat/messages` | Kirim pesan chat. |
| GET | `/api/v1/orders/{orderId}/chat/unread` | Ambil jumlah unread. |
| POST | `/api/v1/orders/{orderId}/chat/read` | Tandai pesan sudah dibaca. |

### 5.5 Driver

| Method | Endpoint | Fungsi |
| --- | --- | --- |
| GET | `/api/v1/driver/verification` | Status verifikasi driver. |
| POST | `/api/v1/driver/verification/documents` | Upload dokumen driver. |
| GET | `/api/v1/driver/availability` | Lihat status kerja driver. |
| PATCH | `/api/v1/driver/availability` | Update available/busy/offline. |
| GET | `/api/v1/driver/orders` | List order masuk dan berjalan. |
| GET | `/api/v1/driver/orders/{orderId}` | Detail order driver. |
| POST | `/api/v1/driver/orders/{orderId}/accept` | Terima order. |
| POST | `/api/v1/driver/orders/{orderId}/reject` | Tolak order. |
| POST | `/api/v1/driver/orders/{orderId}/status-transition` | Transisi status order. |
| PATCH | `/api/v1/driver/orders/{orderId}/status` | Update status eksekusi order. |
| POST | `/api/v1/driver/orders/{orderId}/location` | Kirim lokasi driver realtime. |
| POST | `/api/v1/orders/{orderId}/attempt-failed` | Catat gagal percobaan. |
| POST | `/api/v1/orders/{orderId}/payment/collect-cod` | Catat pembayaran COD. |

### 5.6 Admin

| Method | Endpoint | Fungsi |
| --- | --- | --- |
| GET | `/api/v1/admin/drivers/verification` | List verifikasi driver. |
| GET | `/api/v1/admin/drivers/{driverId}/verification` | Detail verifikasi driver. |
| POST | `/api/v1/admin/drivers/{driverId}/verification/review` | Approve/reject driver. |
| POST | `/api/v1/admin/orders/{orderId}/attempt-failed` | Catat gagal percobaan oleh admin. |
| POST | `/api/v1/admin/orders/{orderId}/payment/record-cod` | Catat COD oleh admin. |
| GET | `/api/v1/admin/payments/cod-settlement` | Laporan settlement COD. |

### 5.7 Chatbot

| Method | Endpoint | Fungsi |
| --- | --- | --- |
| POST | `/api/chatbot/process` | Proses pesan chatbot. |
| GET | `/api/chatbot/sessions` | List session chatbot. |
| GET | `/api/chatbot/sessions/{sessionId}/history` | Riwayat session chatbot. |
| POST | `/api/chatbot/sessions/{sessionId}/location` | Update lokasi context chatbot. |
| POST | `/api/chatbot/sessions/{sessionId}/locations` | Update pickup/dropoff context chatbot. |
| DELETE | `/api/chatbot/sessions/{sessionId}` | Hapus session chatbot. |

## 6. API Eksternal dan Integrasi

| Integrasi | Fungsi |
| --- | --- |
| Google Maps Geocoding API | Mengubah alamat menjadi koordinat untuk validasi tujuan. |
| Google Maps Distance Matrix API | Menghitung jarak dan durasi rute. |
| Google Routes API | Fallback modern jika Distance Matrix gagal/non-OK. |
| Gemini API | Natural language understanding chatbot: ekstraksi intent, tujuan, item, dan detail paket. |
| Laravel Reverb | WebSocket realtime untuk order masuk, chat, status order, dan lokasi driver. |
| Pusher protocol | Protokol WebSocket yang dipakai client Flutter untuk subscribe private channel Reverb. |
| Firebase/Kreait | Disiapkan untuk integrasi Firebase seperti push notification/device token. |

## 7. Dependency yang Digunakan

### 7.1 Backend

Runtime:

- PHP `^8.2`
- Laravel Framework `^12.0`
- Laravel Sanctum `^4.0`
- Laravel Reverb `^1.0`
- Kreait Laravel Firebase `^7.1`
- Laravel Tinker `^2.10.1`

Development/testing:

- PHPUnit `^11.5.3`
- Laravel Pint
- Larastan/PHPStan
- Laravel Pail
- Laravel Sail
- Mockery
- Faker

### 7.2 Frontend Flutter

- Flutter SDK + Dart `^3.10.7`
- `flutter_riverpod` untuk state management.
- `go_router` untuk routing.
- `http` untuk REST API.
- `flutter_secure_storage` untuk token.
- `shared_preferences` untuk local preference.
- `google_maps_flutter` untuk map.
- `geolocator` untuk lokasi device.
- `web_socket_channel` untuk koneksi WebSocket Reverb.
- `image_picker` untuk upload dokumen/foto.
- `url_launcher` untuk membuka URL/aksi eksternal.
- `google_fonts` dan `cupertino_icons` untuk UI.

## 8. Arsitektur Realtime

Alur order masuk driver:

1. Customer membuat/konfirmasi order.
2. Backend menyimpan order ke `orders` dan tabel detail layanan.
3. Backend mencari driver aktif dengan `registration_status = active` dan
   `status = available`.
4. Backend broadcast event `driver.order.available`.
5. Flutter driver yang subscribe ke `private-driver.orders.user.{userId}`
   langsung menampilkan order di tab Orderan > Masuk.

Alur chat realtime:

1. Customer/driver mengirim pesan ke endpoint chat.
2. Backend validasi participant order.
3. Pesan disimpan ke `order_chat_messages`.
4. Backend broadcast `order.chat.message.sent` ke
   `private-order.tracking.{orderId}`.
5. Flutter penerima append pesan dan update unread badge.
6. Saat chat dibuka, frontend memanggil mark-read sehingga
   `order_chat_reads` diperbarui.

Channel authorization:

- `order.tracking.{orderId}` hanya boleh diakses customer pemilik order atau
  driver yang ditugaskan.
- `driver.orders.user.{userId}` hanya boleh diakses user driver itu sendiri
  yang sudah aktif.
- Semua private broadcast auth memakai Sanctum guard.

## 9. Command Testing dan Manual Run

Backend:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
php artisan reverb:start --host=0.0.0.0 --port=8080 --debug
vendor\bin\phpunit.bat --filter BroadcastAuthRouteTest --do-not-cache-result
vendor\bin\phpunit.bat --filter OrderChatTest --do-not-cache-result
vendor\bin\phpunit.bat --filter DriverOrderWorkflowTest --do-not-cache-result
```

Frontend:

```powershell
flutter analyze
flutter test test\providers\driver_order_providers_test.dart test\providers\order_chat_provider_test.dart
flutter run --dart-define-from-file=dart_defines.local.json --dart-define=REALTIME_DIAGNOSTICS=true
```

Hasil verifikasi backend terakhir setelah penyederhanaan schema:

- `php artisan migrate:fresh --seed`: berhasil.
  Seeder juga membersihkan folder upload demo di `storage/app/public`
  (`orders`, `avatars`, `driver-documents`, dan folder legacy dokumen) agar file
  testing lama tidak menumpuk.
- `vendor\bin\phpunit.bat --do-not-cache-result`: 170 tests, 1141 assertions, pass.

Hasil verifikasi frontend sebelumnya:

- Flutter analyze: no issues found.
- Flutter provider tests: 23 tests passed.

## 10. Poin yang Bisa Disampaikan Saat Bimbingan

1. Database sudah dipisah antara master user, driver, restoran/menu, order
   induk, detail order per layanan, audit, payment, evidence, chat, dan AI log.
2. Sistem order memakai satu tabel induk `orders` agar semua jenis layanan punya
   lifecycle yang konsisten.
3. Detail layanan dipisah secukupnya: `ride_order_details` untuk ride,
   `courier_order_details` untuk courier, sedangkan shopping memakai
   `shopping_order_items` dan `shopping_order_receipts`.
4. Realtime memakai Reverb private channel, bukan polling biasa.
5. Bug realtime driver sudah diperbaiki dengan Sanctum broadcast auth, root app
   realtime bootstrap di Flutter, retry subscription, dan fallback sync.
6. Chat order sudah punya deduplikasi client message dan unread source of truth
   di backend.
7. Testing automated sudah mencakup broadcast auth, chat, workflow driver, dan
   provider realtime frontend.
