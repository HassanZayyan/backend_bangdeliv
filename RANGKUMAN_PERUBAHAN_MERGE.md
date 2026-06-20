# Rangkuman Perubahan — BangDeliv (Frontend + Backend)

> **Tujuan dokumen:** memudahkan tim melakukan review, merge, dan push perubahan yang belum di-commit.
> **Dibuat:** 21 Juni 2026
> **Status git saat analisis:** perubahan **belum di-commit** di kedua repo.

---

## 1. Ringkasan Eksekutif

Perubahan ini mencakup **modernisasi UI/UX mobile app (Flutter)** dan **penyesuaian backend/admin (Laravel)** agar selaras, terutama di alur **driver**, **customer**, **chatbot**, dan **verifikasi driver**.

### Tema utama perubahan

| Tema | Frontend | Backend |
|------|----------|---------|
| **UI/UX & navigasi** | Floating bottom navbar, text scaling, layout responsif, polish layar auth/home/order | — |
| **Driver workflow** | Redesign beranda/order aktif/riwayat/profil driver; edit ongkir dipindah ke card pembayaran | — |
| **Hapus nomor SIM dari profil driver** | Field SIM dihapus dari register, profil, model | Kolom `license_number` dihapus dari migration/model/API/admin |
| **Verifikasi driver** | UI status verifikasi diperbarui; card verifikasi di profil disederhanakan | Upload dokumen lebih aman (rollback file); test diperluas |
| **Chatbot ride/kurir** | UI chatbot & provider diperbarui | Copywriting & action payload route picker diperbaiki |
| **Customer order history** | `OrderHistoryScreen` dihapus → redirect ke Aktivitas | — |
| **Aset & platform** | Gambar layanan/background diperbarui; penyesuaian Android/iOS styles | Dokumentasi TA diperbarui |

### Statistik diff

| Repo | Branch | File modified | File baru | File dihapus | +/- baris (approx) |
|------|--------|---------------|-----------|--------------|---------------------|
| `frontend_bangdeliv` | `fix/driver/ui` | 70 | 8 | 1 | +6464 / -4567 |
| `backend_bangdeliv` | `fix/admin/ui` | 27 | 0 | 0 | +197 / -126 |

---

## 2. Informasi Git

### Frontend (`frontend_bangdeliv`)

```
Branch : fix/driver/ui
Remote : origin/fix/driver/ui (up to date saat analisis)
Commit terakhir: a3ee0fe feat: add track order widgets for shopping orders
```

### Backend (`backend_bangdeliv`)

```
Branch : fix/admin/ui
Remote : origin/fix/admin/ui (up to date saat analisis)
Commit terakhir: 7d7c090 feat: Refactor driver order workflow and introduce new enums...
```

### ⚠️ Catatan penting sebelum merge

1. **Belum ada commit** untuk perubahan working tree ini — teammate perlu `git add` + `git commit` + `git push`.
2. **Frontend & backend harus di-deploy bersamaan** jika branch production masih mengharapkan field `license_number`.
3. **Migration drivers:** kolom `license_number` dihapus dari migration create table. Jika DB lokal sudah pernah migrate, perlu strategi migration terpisah (fresh migrate / alter manual) — lihat §8.
4. File **binary** ikut berubah: `background.png`, icon layanan, `WhatsApp.webp` (baru).

---

## 3. Backend — Detail Per File

**Branch:** `fix/admin/ui`
**Root:** `backend_bangdeliv`

### 3.1 Domain Driver — Hapus `license_number`

| File | Perubahan |
|------|-----------|
| `database/migrations/2026_03_30_115802_create_drivers_table.php` | Hapus kolom `license_number`. |
| `app/Models/Driver.php` | Hapus property/fillable `license_number`. |
| `app/Http/Requests/Api/UpgradeToDriverRequest.php` | Hapus validasi & normalisasi `license_number`. |
| `app/Services/DriverOnboardingService.php` | Tidak lagi menyimpan `license_number` saat upgrade customer → driver. |
| `app/Services/DriverVerificationService.php` | Hapus `license_number` dari payload admin & pencarian; **perbaikan upload dokumen** (lihat bawah). |
| `resources/views/admin/drivers/index.blade.php` | Hapus search & tampilan nomor SIM di tabel driver. |
| `resources/views/admin/drivers/verification/index.blade.php` | Hapus baris nomor SIM di queue verifikasi. |
| `resources/views/admin/drivers/verification/show.blade.php` | Hapus field "Nomor SIM" di detail verifikasi. |
| `PROGRESS_BIMBINGAN_DOSEN.md` | Update deskripsi tabel `drivers` (tanpa nomor SIM). |

#### Perbaikan `DriverVerificationService::submitDocuments`

- Track path file baru (`$storedPaths`) dan file lama yang akan dihapus (`$oldPathsToDelete`).
- Jika transaksi DB gagal → **rollback** file baru via `Storage::delete`.
- Jika sukses → hapus file dokumen lama yang diganti.
- Perilaku status tetap: re-submit → `registration_status = pending`, `status = offline`.

### 3.2 Chatbot API

| File | Perubahan |
|------|-----------|
| `app/Http/Controllers/Api/ChatbotController.php` | `OPEN_ROUTE_PICKER` mempertahankan label custom dari payload existing (ride/kurir), fallback ke label default. |
| `app/Services/ChatbotRideOrderService.php` | Copy reset tujuan disederhanakan; tambah action `Pilih Tujuan Baru`; hapus instruksi "Konfirmasi"/"Ubah Tujuan" dari draft; contoh alamat disesuaikan (Salatiga). |
| `app/Services/ChatbotCourierOrderService.php` | Contoh barang diubah; draft message tanpa prompt pembayaran/konfirmasi berlebihan. |
| `app/Services/ChatbotGeminiService.php` | Penyesuaian kecil prompt/logic (1 baris). |

### 3.3 Seeders

| File | Perubahan |
|------|-----------|
| `database/seeders/AccessAccountSeeder.php` | Hapus referensi `license_number` pada data seed. |
| `database/seeders/CleanupDemoStorageSeeder.php` | Penyesuaian minor cleanup demo. |

### 3.4 Tests (sinkron dengan perubahan domain)

Semua test di bawah disesuaikan agar **tidak lagi mengasumsikan `license_number`**, plus penambahan coverage verifikasi:

| File | Perubahan |
|------|-----------|
| `tests/Feature/Api/DriverVerificationTest.php` | **+87 baris** — coverage upload/rollback dokumen diperluas. |
| `tests/Feature/Api/UpgradeToDriverTest.php` | Hapus assertion field SIM. |
| `tests/Feature/Api/AuthProfileTest.php` | Penyesuaian response profil driver. |
| `tests/Feature/Api/DriverOrderWorkflowTest.php` | Penyesuaian workflow test. |
| `tests/Feature/Api/DriverOrderRevisionEndpointsTest.php` | Minor sync. |
| `tests/Feature/Api/CodPaymentFlowTest.php` | Minor sync. |
| `tests/Feature/Api/BroadcastAuthRouteTest.php` | Minor sync. |
| `tests/Feature/Api/OrderChatTest.php` | Minor sync. |
| `tests/Feature/Api/OrderStatusPushNotificationTest.php` | Minor sync. |
| `tests/Feature/Admin/DriverVerificationWebTest.php` | Hapus assertion SIM di admin web. |
| `tests/Feature/ChatbotRideFlowTest.php` | Update expected copy & action payloads ride. |
| `tests/Feature/ChatbotCourierFlowTest.php` | Update expected copy courier. |

---

## 4. Frontend — Detail Per File

**Branch:** `fix/driver/ui`
**Root:** `frontend_bangdeliv`

### 4.1 File baru (untracked — wajib `git add`)

| File | Fungsi |
|------|--------|
| `lib/config/app_text_scaling.dart` | Utility clamp skala teks (aksesibilitas + komponen padat). |
| `lib/features/navigation/presentation/widgets/bang_floating_bottom_nav_bar.dart` | **Floating bottom nav** reusable (customer & driver), badge order masuk, `scrollClearance = 120`. |
| `lib/features/home/presentation/widgets/nearby_merchant_card.dart` | Card merchant untuk layar merchant terdekat. |
| `assets/images/WhatsApp.webp` | Aset WhatsApp (kemungkinan bantuan/kontak). |
| `test/config/app_text_scaling_test.dart` | Unit test text scaling. |
| `test/widgets/bang_floating_bottom_nav_bar_test.dart` | Widget test floating navbar. |
| `test/widgets/customer_main_layout_navigation_test.dart` | Test navigasi layout customer. |
| `test/screens/home_responsive_layout_test.dart` | Test layout responsif home. |

### 4.2 File dihapus

| File | Dampak |
|------|--------|
| `lib/features/orders/presentation/screens/order_history_screen.dart` | Route `/history` di-redirect ke **Aktivitas** (`AppRoutes.activity`). |

### 4.3 Konfigurasi, routing & entry point

| File | Perubahan |
|------|-----------|
| `lib/config/app_env.dart` | Penyesuaian env (minor). |
| `lib/config/app_routes.dart` | Tambah `nearbyMerchantDetail`, helper path merchant dari layar nearby. |
| `lib/config/app_router.dart` | Nested route merchant detail dari nearby; redirect history→activity; guard login untuk activity/history/profile; driver non-active redirect ke verifikasi/home; default route driver pending → home. |
| `lib/main.dart` | Wrap app dengan `AppTextScaling.clamp`. |

### 4.4 Navigasi & layout

| File | Perubahan |
|------|-----------|
| `lib/features/navigation/presentation/screens/main_layout.dart` | Ganti `BottomNavigationBar` → `BangFloatingBottomNavBar` (3 tab: Beranda, Aktivitas, Profil). Tab Riwayat dihapus. Back dari nearby merchants → home. |
| `lib/features/navigation/presentation/screens/driver_main_layout.dart` | Floating navbar driver (4 tab + badge order masuk). Tab Orderan jika ada order aktif → langsung ke detail order aktif. |

### 4.5 Driver — layar & widget order

| File | Perubahan |
|------|-----------|
| `driver_home_screen.dart` | Redesign besar beranda driver (availability card, metric tiles, order aktif section). |
| `driver_orders_screen.dart` | Refactor card order masuk, route section, fee bar. |
| `driver_history_screen.dart` | Summary card icon sejajar label (seperti beranda); status pill konsisten dengan order aktif. |
| `driver_active_order_screen.dart` | Edit ongkir dipindah ke card Pembayaran; card Ongkir Driver dihapus dari list. |
| `driver_active_order_meta_widgets.dart` | Card Pembayaran: tombol Edit ongkir, baris Sumber & Perlu 2 orang; refactor card meta order. |
| `driver_active_order_fee_widgets.dart` | Hapus widget card Ongkir Driver; ekstrak `showDriverManualDeliveryFeeEditDialog()`. |
| `driver_active_order_action_widgets.dart` | Penyesuaian action bar & dialog workflow. |
| `driver_active_order_map_widgets.dart` | Minor map UI. |
| `driver_active_order_proof_widgets.dart` | Minor proof UI. |
| `driver_active_order_shopping_widgets.dart` | Minor shopping checkout UI. |
| `driver_transfer_payment_card.dart` | Penyesuaian card verifikasi transfer. |

### 4.6 Driver — profil

| File | Perubahan |
|------|-----------|
| `driver_profile_screen.dart` | Hapus card Status Verifikasi; hapus menu Notifikasi & Kebijakan Privasi; label operasional pakai pill; hapus field Nomor SIM; perbaiki jarak tombol logout ke navbar. |
| `driver_verification_status_screen.dart` | Redesign layar status verifikasi dokumen. |
| `register_driver_screen.dart` | Hapus input nomor SIM; polish hero & form "Data Kendaraan". |
| `vehicle_info_fields.dart` | Label field pakai floating label (Material), hapus label manual terpisah. |
| `user_profile_model.dart` | Hapus `licenseNumber` dari `DriverProfileModel`. |
| `driver_verification_model.dart` | Sync model verifikasi. |
| `driver_verification_service.dart` | Sync service API verifikasi. |

### 4.7 Customer — home, merchant, order, tracking

| File | Perubahan |
|------|-----------|
| `home_screen.dart` | Layout responsif, section merchant/layanan diperbarui. |
| `nearby_merchants_screen.dart` | Refactor list + navigasi ke detail nested. |
| `merchant_detail_screen.dart` | Dukung `returnPath` & args navigasi dari nearby. |
| `nearby_merchant_card.dart` | **(baru)** komponen card. |
| `activity_screen.dart` | Menjadi pengganti riwayat order customer. |
| `order_chat_screen.dart` | Minor UI. |
| `track_order_screen.dart` | Perbaikan UI/info pembayaran & tracking. |
| `track_order_widgets.dart` | Widget pendukung track order diperluas. |
| `customer_order_card.dart` | Polish card order customer. |

### 4.8 Auth, alamat, profil customer, shopping, chatbot

| File | Perubahan |
|------|-----------|
| `login_screen.dart`, `register_screen.dart`, `forgot_password_screen.dart` | Polish UI auth + text scaling. |
| `add_address_screen.dart`, `address_location_picker_screen.dart`, `route_location_picker_screen.dart`, `saved_addresses_screen.dart` | Refactor besar alur alamat & picker. |
| `profile_screen.dart`, `edit_profile_screen.dart`, `change_password_screen.dart`, `notification_settings_screen.dart`, `privacy_map_screen.dart` | Polish profil customer & settings. |
| `shopping_add_item_screen.dart`, `shopping_*_widgets.dart` | UX shopping draft/submit diperbaiki. |
| `chatbot_screen.dart`, `chatbot_conversation_provider.dart`, `chatbot_model.dart` | UI chatbot + handling action payloads route picker. |

### 4.9 Widget & core shared

| File | Perubahan |
|------|-----------|
| `bang_ui.dart` | Komponen UI shared diperluas/diperbaiki. |
| `bang_async_state.dart` | State loading/error konsisten. |
| `bang_status_pill.dart` | Pill status reusable diperbarui. |
| `app_content_background.dart` | Background konten. |
| `bang_select_field.dart` | Select field polish. |
| `auth_service.dart` | Minor (hapus field tidak dipakai). |
| `merchant_model.dart` | Field tambahan untuk merchant UI. |

### 4.10 Platform & aset

| File | Perubahan |
|------|-----------|
| `android/.../AndroidManifest.xml` | Penyesuaian manifest (1 baris). |
| `android/.../styles.xml`, `values-night/styles.xml` | Edge-to-edge / system bar styling. |
| `ios/Runner/Info.plist` | Penyesuaian iOS config (-5 baris). |
| `assets/images/background.png` | Binary diperbarui. |
| `assets/images/services/service_ride_motor_simplified.png` | Binary diperbarui. |
| `assets/images/services/service_shopping_basket_simplified.png` | Binary diperbarui. |
| `macos/Flutter/GeneratedPluginRegistrant.swift` | Auto-generated plugin registrant. |

### 4.11 Tests frontend

| File | Perubahan |
|------|-----------|
| `test/providers/*_test.dart` | Sync provider tests. |
| `test/screens/chatbot_screen_test.dart` | Update expected chatbot copy/flow. |
| `test/screens/track_order_payment_behavior_test.dart` | Update assertion track order. |
| `test/config/app_text_scaling_test.dart` | **(baru)** |
| `test/widgets/bang_floating_bottom_nav_bar_test.dart` | **(baru)** |
| `test/widgets/customer_main_layout_navigation_test.dart` | **(baru)** |
| `test/screens/home_responsive_layout_test.dart` | **(baru)** |

---

## 5. Perubahan UI Driver (Sesi Terbaru — Referensi Merge)

Bagian ini merangkum perubahan spesifik dari sesi polishing driver yang mungkin belum familiar untuk reviewer:

### Order Aktif Driver
- Tombol **Edit ongkir** dipindah dari card Ongkir → **card Pembayaran**.
- Card **Ongkir Driver** dihapus seluruhnya.
- Info **Sumber** ongkir & **Perlu 2 orang** ditampilkan sebagai baris di card Pembayaran.

### Riwayat Driver
- Icon summary card (Order Selesai / Pendapatan) **sejajar dengan label**, warna `textSecondary`, sama seperti beranda driver.
- Label status **Selesai** memakai pill `surfaceAlt + success + border` (konsisten dengan status order aktif).

### Profil Driver
- Card **Status Verifikasi** dihapus (akses tetap via menu **Status Verifikasi**).
- Menu **Notifikasi** & **Kebijakan Privasi** dihapus.
- Label status operasional memakai pill konsisten.
- Padding bawah tombol **Keluar dari Akun** disamakan dengan layar lain (`scrollClearance` tanpa +32).

---

## 6. Breaking Changes & Dampak Integrasi

### 6.1 API — field `license_number` dihapus

**Backend tidak lagi menerima/mengembalikan `license_number` pada:**
- Upgrade to driver (`UpgradeToDriverRequest`)
- Payload verifikasi/admin driver

**Frontend tidak lagi mengirim/menampilkan field tersebut.**

**Checklist integrasi:**
- [ ] Pastikan mobile build baru di-deploy bersamaan dengan backend.
- [ ] Cek apakah admin panel lama masih referensi SIM di tempat lain (sudah dibersihkan di 3 blade utama).
- [ ] Update Postman/API docs internal jika ada.

### 6.2 Database migration

Migration `create_drivers_table` sudah **tanpa** kolom `license_number`.

**Jika DB development sudah ada kolom tersebut:**
```bash
# Opsi A: fresh migrate (development only)
php artisan migrate:fresh --seed

# Opsi B: buat migration baru (staging/production)
# ALTER TABLE drivers DROP COLUMN license_number;
```

### 6.3 Routing customer — `/history`

Route history customer redirect ke `/activity`. Deep link lama ke history tetap aman (redirect), tapi **tab Riwayat di navbar hilang**.

### 6.4 Navbar floating

Semua layar dengan bottom nav perlu **`BangFloatingBottomNavBar.scrollClearance`** di padding bawah ListView agar konten tidak tertutup navbar. Sebagian besar layar sudah di-update.

---

## 7. Panduan Merge untuk Tim

### 7.1 Urutan merge yang disarankan

1. **Merge backend dulu** (`fix/admin/ui`) ke branch target (mis. `develop` / `main`).
2. **Jalankan migration/seed** di environment target.
3. **Merge frontend** (`fix/driver/ui`) ke branch yang sama.
4. Smoke test end-to-end (register driver, verifikasi, order, chatbot).

### 7.2 Conflict hotspot (perhatian extra)

| Area | Kenapa rawan conflict |
|------|----------------------|
| `app/Services/DriverVerificationService.php` | Logic upload + search berubah |
| `app/Services/ChatbotRideOrderService.php` | Banyak perubahan copy/payload |
| `lib/config/app_router.dart` | Guard route & nested merchant |
| `lib/features/navigation/**` | Navbar architecture baru |
| `lib/features/driver_orders/**` | Refactor UI besar |
| `lib/features/addresses/**` | Refactor layar alamat |

### 7.3 Langkah commit & push (contoh)

#### Backend
```bash
cd backend_bangdeliv
git add -A
git status
git commit -m "$(cat <<'EOF'
refactor(driver): remove license_number and harden document upload

Drop SIM field from driver onboarding, admin views, and API payloads.
Improve verification document rollback on failed transactions and sync tests.
EOF
)"
git push origin fix/admin/ui
```

#### Frontend
```bash
cd frontend_bangdeliv
git add -A
git status
git commit -m "$(cat <<'EOF'
feat(ui): modernize navigation and driver/customer order screens

Introduce floating bottom nav, text scaling, and refreshed driver workflows.
Remove order history screen, SIM fields, and align verification/profile UX with backend.
EOF
)"
git push origin fix/driver/ui
```

> Sesuaikan branch target & message dengan konvensi tim. **Jangan commit `.env` atau secrets.**

---

## 8. Checklist Testing Setelah Merge

### Backend
```bash
cd backend_bangdeliv
php artisan test --filter=DriverVerification
php artisan test --filter=UpgradeToDriver
php artisan test --filter=ChatbotRide
php artisan test --filter=ChatbotCourier
```

### Frontend
```bash
cd frontend_bangdeliv
flutter analyze
flutter test test/widgets/bang_floating_bottom_nav_bar_test.dart
flutter test test/widgets/customer_main_layout_navigation_test.dart
flutter test test/screens/home_responsive_layout_test.dart
flutter test test/screens/chatbot_screen_test.dart
```

### Manual smoke test (disarankan)

**Driver**
- [ ] Login driver aktif → beranda, order masuk, terima order
- [ ] Order aktif → edit ongkir dari card Pembayaran
- [ ] Riwayat → pill status & summary card
- [ ] Profil → data operasional, logout tidak terlalu jauh dari navbar
- [ ] Register/upgrade driver tanpa field SIM
- [ ] Upload dokumen verifikasi

**Customer**
- [ ] Floating navbar 3 tab
- [ ] Nearby merchants → detail → back ke home
- [ ] Aktivitas order (pengganti riwayat)
- [ ] Chatbot ride: reset tujuan → tombol "Pilih Tujuan Baru"
- [ ] Track order & pembayaran

**Admin**
- [ ] Daftar driver & verifikasi tanpa kolom SIM
- [ ] Approve/reject dokumen driver

---

## 9. Daftar Lengkap File (Checklist Reviewer)

### Backend — Modified (27)

```
PROGRESS_BIMBINGAN_DOSEN.md
app/Http/Controllers/Api/ChatbotController.php
app/Http/Requests/Api/UpgradeToDriverRequest.php
app/Models/Driver.php
app/Services/ChatbotCourierOrderService.php
app/Services/ChatbotGeminiService.php
app/Services/ChatbotRideOrderService.php
app/Services/DriverOnboardingService.php
app/Services/DriverVerificationService.php
database/migrations/2026_03_30_115802_create_drivers_table.php
database/seeders/AccessAccountSeeder.php
database/seeders/CleanupDemoStorageSeeder.php
resources/views/admin/drivers/index.blade.php
resources/views/admin/drivers/verification/index.blade.php
resources/views/admin/drivers/verification/show.blade.php
tests/Feature/Admin/DriverVerificationWebTest.php
tests/Feature/Api/AuthProfileTest.php
tests/Feature/Api/BroadcastAuthRouteTest.php
tests/Feature/Api/CodPaymentFlowTest.php
tests/Feature/Api/DriverOrderRevisionEndpointsTest.php
tests/Feature/Api/DriverOrderWorkflowTest.php
tests/Feature/Api/DriverVerificationTest.php
tests/Feature/Api/OrderChatTest.php
tests/Feature/Api/OrderStatusPushNotificationTest.php
tests/Feature/Api/UpgradeToDriverTest.php
tests/Feature/ChatbotCourierFlowTest.php
tests/Feature/ChatbotRideFlowTest.php
```

### Frontend — Modified (70)

```
android/app/src/main/AndroidManifest.xml
android/app/src/main/res/values-night/styles.xml
android/app/src/main/res/values/styles.xml
assets/images/background.png
assets/images/services/service_ride_motor_simplified.png
assets/images/services/service_shopping_basket_simplified.png
ios/Runner/Info.plist
lib/config/app_env.dart
lib/config/app_router.dart
lib/config/app_routes.dart
lib/core/widgets/bang_async_state.dart
lib/core/widgets/bang_status_pill.dart
lib/features/addresses/presentation/screens/add_address_screen.dart
lib/features/addresses/presentation/screens/address_location_picker_screen.dart
lib/features/addresses/presentation/screens/route_location_picker_screen.dart
lib/features/addresses/presentation/screens/saved_addresses_screen.dart
lib/features/auth/presentation/screens/forgot_password_screen.dart
lib/features/auth/presentation/screens/login_screen.dart
lib/features/auth/presentation/screens/register_driver_screen.dart
lib/features/auth/presentation/screens/register_screen.dart
lib/features/chatbot/application/chatbot_conversation_provider.dart
lib/features/chatbot/presentation/screens/chatbot_screen.dart
lib/features/driver_orders/presentation/screens/driver_active_order_screen.dart
lib/features/driver_orders/presentation/screens/driver_history_screen.dart
lib/features/driver_orders/presentation/screens/driver_home_screen.dart
lib/features/driver_orders/presentation/screens/driver_orders_screen.dart
lib/features/driver_orders/presentation/widgets/driver_active_order_action_widgets.dart
lib/features/driver_orders/presentation/widgets/driver_active_order_fee_widgets.dart
lib/features/driver_orders/presentation/widgets/driver_active_order_map_widgets.dart
lib/features/driver_orders/presentation/widgets/driver_active_order_meta_widgets.dart
lib/features/driver_orders/presentation/widgets/driver_active_order_proof_widgets.dart
lib/features/driver_orders/presentation/widgets/driver_active_order_shopping_widgets.dart
lib/features/home/presentation/screens/home_screen.dart
lib/features/home/presentation/screens/merchant_detail_screen.dart
lib/features/home/presentation/screens/nearby_merchants_screen.dart
lib/features/navigation/presentation/screens/driver_main_layout.dart
lib/features/navigation/presentation/screens/main_layout.dart
lib/features/orders/presentation/screens/activity_screen.dart
lib/features/orders/presentation/screens/order_chat_screen.dart
lib/features/profile/presentation/screens/change_password_screen.dart
lib/features/profile/presentation/screens/driver_profile_screen.dart
lib/features/profile/presentation/screens/driver_verification_status_screen.dart
lib/features/profile/presentation/screens/edit_profile_screen.dart
lib/features/profile/presentation/screens/notification_settings_screen.dart
lib/features/profile/presentation/screens/privacy_map_screen.dart
lib/features/profile/presentation/screens/profile_screen.dart
lib/features/shopping/presentation/screens/shopping_add_item_screen.dart
lib/features/shopping/presentation/widgets/shopping_draft_items_section.dart
lib/features/shopping/presentation/widgets/shopping_manual_item_section.dart
lib/features/shopping/presentation/widgets/shopping_submit_bar.dart
lib/features/tracking/presentation/screens/track_order_screen.dart
lib/features/tracking/presentation/widgets/track_order_widgets.dart
lib/main.dart
lib/models/chatbot_model.dart
lib/models/driver_verification_model.dart
lib/models/merchant_model.dart
lib/models/user_profile_model.dart
lib/services/auth_service.dart
lib/services/driver_verification_service.dart
lib/widgets/app_content_background.dart
lib/widgets/bang_select_field.dart
lib/widgets/bang_ui.dart
lib/widgets/customer_order_card.dart
lib/widgets/driver_transfer_payment_card.dart
lib/widgets/vehicle_info_fields.dart
macos/Flutter/GeneratedPluginRegistrant.swift
test/providers/customer_order_providers_test.dart
test/providers/driver_order_providers_test.dart
test/providers/order_chat_provider_test.dart
test/screens/chatbot_screen_test.dart
test/screens/track_order_payment_behavior_test.dart
```

### Frontend — Deleted (1)

```
lib/features/orders/presentation/screens/order_history_screen.dart
```

### Frontend — New (8)

```
assets/images/WhatsApp.webp
lib/config/app_text_scaling.dart
lib/features/home/presentation/widgets/nearby_merchant_card.dart
lib/features/navigation/presentation/widgets/bang_floating_bottom_nav_bar.dart
test/config/app_text_scaling_test.dart
test/screens/home_responsive_layout_test.dart
test/widgets/bang_floating_bottom_nav_bar_test.dart
test/widgets/customer_main_layout_navigation_test.dart
```

---

## 10. Kontak & Catatan

- Dokumen ini **hanya rangkuman working tree**, bukan menggantikan `git diff` penuh.
- Untuk detail baris-per-baris, jalankan:
  ```bash
  git diff
  git diff --stat
  git diff path/to/file
  ```
- Jika teammate butuh PR terpisah, pertimbangkan split:
  1. PR backend: hapus SIM + verifikasi rollback
  2. PR frontend: navbar + driver UI
  3. PR frontend: customer/home/chatbot (opsional)

---

*Generated for BangDeliv TA merge assistance.*
