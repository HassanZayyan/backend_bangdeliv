# Refactor Execution Steps

Dokumen ini adalah rencana eksekusi bertahap untuk refactor minimal BangDeliv.

## Aturan Wajib

- Wajib berpatok pada `Backend_Bangdeliv/docs/mega_refactor_TA.md` sebelum implementasi setiap step.
- Sebelum mulai step, baca ulang section `Decision Pass Setelah Audit Lengkap`, `Plan Eksekusi Refactor Minimal`, dan entry file yang akan diedit di `mega_refactor_TA.md`.
- Jangan refactor berdasarkan ingatan saja. Jika ada keraguan, cari entry terkait di `mega_refactor_TA.md` atau baca ulang file sumber sebelum edit.
- Jika implementasi menemukan fakta baru yang bertentangan dengan `mega_refactor_TA.md`, revisi `mega_refactor_TA.md` dulu sebelum lanjut edit kode.
- Perubahan harus seminimal mungkin, clean, dan tidak mengubah behavior/route/kontrak API kecuali memang tertulis sebagai kandidat refactor di `mega_refactor_TA.md`.
- Jangan menghapus `delivery_fee`, `delivery_fee_source`, manual delivery fee override, delivery fee negotiation, tracking, chat, payment proof, atau status penalti gagal pickup.
- Jangan menghapus scalar `orders.service_fee`; field ini tetap dipertahankan sebagai satu nilai final service fee/penalti.
- Yang dihapus adalah mekanisme generic `service_fee_rules`, `order_fee_lines`, fee lines/breakdown dari table tersebut, `careful_carry_required`, `is_heavy`, item surcharge, dan overweight surcharge.
- Flow gagal pickup/merchant tutup 3 kali bayar 50% ongkir wajib tetap hidup: `CANCELLED_WITH_FEE`, `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS`, failed attempt count, proof `STORE_CLOSED_PHOTO`, QRIS/payment proof, dan total payment terkait.
- Setiap step harus selesai dengan verifikasi minimal: search target lama, test/analyze yang relevan, dan catatan hasil.

## Step 0 - Baseline

Tujuan: mengetahui kondisi awal sebelum edit agar error lama tidak bercampur dengan error refactor.

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Pekerjaan:

1. Jalankan search target lama:
   - `service_fee_rules`
   - `ServiceFeeRule`
   - `order_fee_lines`
   - `OrderFeeLine`
   - `feeLines`
   - `careful_carry_required`
   - `carefulCarryRequired`
   - `is_heavy`
   - `isHeavy`
   - `ITEM_BLOCK_SURCHARGE`
   - `OVERWEIGHT_FLAT_SURCHARGE`
2. Jalankan test/analyze baseline yang realistis.
3. Catat test yang sudah gagal sebelum refactor.

Output:

- Daftar target yang masih muncul.
- Daftar test/analyze baseline dan hasilnya.

## Step 1 - Backend Service Fee Core

Tujuan: menghapus mekanisme generic service fee tanpa mematikan penalti gagal pickup.

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Rujukan wajib di `mega_refactor_TA.md`:

- `Backend_Bangdeliv/app/Services/Pricing/ShoppingPricingService.php`
- `Backend_Bangdeliv/app/Services/Order/OrderService.php`
- `Backend_Bangdeliv/app/Models/OrderFeeLine.php`
- `Backend_Bangdeliv/app/Models/ServiceFeeRule.php`
- migration `create_orders_table`
- migration `create_service_fee_rules_table`

Pekerjaan:

1. Hapus dependency runtime ke `ServiceFeeRule`.
2. Hapus dependency runtime ke `OrderFeeLine`.
3. Hapus sync/read `order_fee_lines`.
4. Hapus item block surcharge dan overweight surcharge.
5. Pertahankan rule gagal pickup 3 kali/50% ongkir sebagai logic eksplisit service/config.
6. Simpan penalti final ke `orders.service_fee`.
7. Pastikan `orders.total_price = subtotal + delivery_fee + service_fee`.

Yang tidak boleh rusak:

- `delivery_fee`
- manual delivery fee override
- delivery fee negotiation
- failed pickup 3 kali/50%
- COD/QRIS pending payment amount
- tracking/order detail total

Verifikasi:

- Search `ServiceFeeRule`, `OrderFeeLine`, `order_fee_lines`, dan `service_fee_rules`.
- Test pricing/order/payment yang relevan.

## Step 2 - Backend Careful Carry dan Heavy Cleanup

Tujuan: menghapus fitur Courier "perlu 2 orang" dan surcharge item berat.

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Rujukan wajib di `mega_refactor_TA.md`:

- `Backend_Bangdeliv/app/Services/Order/DeliveryFeeNegotiationService.php`
- `Backend_Bangdeliv/app/Services/Order/OrderService.php`
- `Backend_Bangdeliv/app/Services/Driver/DriverOrderPayloadFactory.php`
- `Backend_Bangdeliv/app/Models/CourierOrder.php`
- migration `create_courier_orders_table`
- migration `create_order_items_table`

Pekerjaan:

1. Hapus `careful_carry_required` dari request, service, model, payload, dan metadata.
2. Hapus helper/support careful-carry dan surcharge 50%.
3. Hapus `is_heavy` dari payload/model/calculation jika hanya untuk surcharge.
4. Pertahankan Courier normal dan `package_description`.
5. Pertahankan merchant failed attempt dan proof store closed.

Yang tidak boleh rusak:

- create order Courier normal
- delivery fee override normal
- driver payload order Courier
- Shopping item availability
- failed pickup merchant

Verifikasi:

- Search `careful_carry_required`, `carefulCarryRequired`, `is_heavy`, `isHeavy`.
- Test driver order revision, courier order, shopping item update, dan failed pickup.

## Step 3 - Frontend Sync

Tujuan: menyamakan Flutter dengan payload backend baru.

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Rujukan wajib di `mega_refactor_TA.md`:

- `frontend_bangdeliv/lib/models/customer_order_model.dart`
- `frontend_bangdeliv/lib/models/driver_order_model.dart`
- `frontend_bangdeliv/lib/models/delivery_fee_negotiation_model.dart`
- `frontend_bangdeliv/lib/features/driver_orders/presentation/widgets/driver_active_order_fee_widgets.dart`
- `frontend_bangdeliv/lib/features/driver_orders/presentation/widgets/driver_active_order_meta_widgets.dart`
- `frontend_bangdeliv/lib/features/driver_orders/presentation/widgets/driver_active_order_shopping_widgets.dart`
- `frontend_bangdeliv/lib/features/tracking/presentation/widgets/track_order_widgets.dart`
- `frontend_bangdeliv/lib/widgets/shopping_fee_breakdown.dart`
- `frontend_bangdeliv/lib/services/driver_order_service.dart`

Pekerjaan:

1. Hapus field/model/request `carefulCarryRequired`.
2. Hapus UI "Perlu 2 orang".
3. Hapus field/model/UI `isHeavy` dan toggle item berat.
4. Hapus `ShoppingFeeBreakdown` yang berasal dari fee lines generic.
5. Pertahankan `serviceFee` scalar, `deliveryFee`, delivery fee negotiation, cancellation penalty, payment proof, tracking, chat, dan QRIS.

Yang tidak boleh rusak:

- driver active order
- customer tracking
- order history/activity
- chat order
- delivery fee negotiation
- cancellation penalty 50%

Verifikasi:

- `flutter analyze`
- widget/model tests yang terdampak
- search target frontend lama.

## Step 4 - Schema dan Data Cleanup

Tujuan: membersihkan schema sesuai keputusan final.

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Rujukan wajib di `mega_refactor_TA.md`:

- migration `create_orders_table`
- migration `create_service_fee_rules_table`
- migration `create_order_items_table`
- migration `create_courier_orders_table`
- seeders dan factories

Pekerjaan:

1. Tentukan strategi migration:
   - dev reset: ubah migration awal agar tidak membuat table/field target lama.
   - existing DB: buat migration baru `dropIfExists`.
2. Drop `service_fee_rules`.
3. Drop `order_fee_lines`.
4. Pertahankan `orders.service_fee`.
5. Hapus `courier_order_details.careful_carry_required` jika backend/frontend sudah sinkron.
6. Hapus `shopping_order_items.is_heavy` jika tidak ada kebutuhan non-fee.
7. Update factory/seeder/test data yang masih memakai field lama.

Verifikasi:

- migration fresh/reset jika memungkinkan.
- test backend yang memakai database.
- search schema lama.

## Step 5 - Low-Risk Cleanup

Tujuan: membersihkan placeholder/unused/legacy yang sudah ditegaskan di decision pass.

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Rujukan wajib di `mega_refactor_TA.md`:

- `frontend_bangdeliv/lib/features/auth/presentation/screens/forgot_password_screen.dart`
- `frontend_bangdeliv/lib/config/app_router.dart`
- `frontend_bangdeliv/lib/core/widgets/bang_bottom_action_bar.dart`
- `frontend_bangdeliv/lib/core/widgets/bang_status_pill.dart`
- `frontend_bangdeliv/lib/models/order_model.dart`
- `Backend_Bangdeliv/routes/api.php`
- `Backend_Bangdeliv/app/Http/Controllers/Api/Driver/OrderExecutionController.php`
- `Backend_Bangdeliv/resources/views/admin/settings/index.blade.php`

Pekerjaan:

1. Hide link/route forgot password atau ubah menjadi unavailable sampai backend reset password dibuat.
2. Hapus widget/model/provider unused yang sudah dikonfirmasi dengan `rg`.
3. Rapikan route legacy status driver setelah kontrak test disesuaikan.
4. Ubah admin settings tarif mock menjadi read-only/hide agar tidak menyesatkan.

Verifikasi:

- `flutter analyze`
- route list/API contract test
- smoke check login/admin route jika memungkinkan.

## Step 6 - Refactor Besar Opsional

Tujuan: merapikan arsitektur setelah cleanup utama stabil.

Status: selesai pada 2026-06-20. Scope 6A-6J selesai dan diringkas di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Rujukan wajib di `mega_refactor_TA.md`:

- `Backend_Bangdeliv/app/Services/Order/OrderService.php`
- service chatbot besar
- admin Blade views
- map/location picker Flutter
- shared formatter/helper yang duplikatif

Aturan sub-step:

- Kerjakan satu sub-step per prompt/eksekusi kecuali user meminta eksplisit untuk menggabungkan.
- Sebelum mulai sub-step, baca ulang entry file terkait di `mega_refactor_TA.md` dan file sumber yang akan diedit.
- Dokumen step individual sudah diringkas ke `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.
- Jangan ubah schema/pricing service fee lagi di Step 6, kecuali hanya dokumentasi atau cleanup referensi mati yang sudah terbukti.
- Jika sebuah sub-step ternyata menyentuh banyak endpoint/UI sekaligus, pecah lagi sebelum implementasi.

Sub-step yang direncanakan:

### Step 6A - Shared Geo Helper

Status: selesai pada 2026-06-20.

Ringkasan hasil: `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Scope:

1. Ekstrak helper jarak/koordinat backend yang berulang.
2. Pakai helper itu di `OrderService`, `RideOrderService`, `ShoppingMerchantCandidateResolver`, dan `DriverDistanceCalculator`.

Verifikasi minimal:

- Unit test helper geo.
- Feature test Ride, Shopping item edit, dan driver dispatch/order workflow terkait jarak.

### Step 6B - Courier Package Policy Copy Cleanup

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Tujuan: menyelesaikan sisa copy/konsep Courier "bantuan 2 orang" yang masih tercatat di audit, tanpa mengubah schema/pricing.

Rujukan wajib:

- `Backend_Bangdeliv/app/Services/Chatbot/CourierPackagePolicyService.php`
- `Backend_Bangdeliv/app/Services/Chatbot/ChatbotCourierOrderService.php`
- `Backend_Bangdeliv/tests/Feature/ChatbotCourierFlowTest.php`
- Entry `CourierPackagePolicyService` di `mega_refactor_TA.md`.

Scope:

1. Ganti teks reason/packing note yang masih menyebut "bantuan 2 orang" menjadi copy netral: driver bisa menyesuaikan ongkir/manual handling.
2. Evaluasi `STATUS_OVERSIZE`: konsistenkan pemakaian atau dokumentasikan jika tetap sebagai size class saja.
3. Jangan mengembalikan `careful_carry_required`, surcharge 50%, atau table fee lama.

Verifikasi minimal:

- Search `bantuan 2 orang`, `perlu 2 orang`, `careful_carry`.
- `php artisan test tests\Feature\ChatbotCourierFlowTest.php`.

### Step 6C - Chatbot Courier Dead Code Cleanup

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Tujuan: menghapus helper mati kecil di chatbot courier setelah copy policy aman.

Rujukan wajib:

- `Backend_Bangdeliv/app/Services/Chatbot/ChatbotCourierOrderService.php`
- Entry `ChatbotCourierOrderService` di `mega_refactor_TA.md`.

Scope:

1. Konfirmasi dengan `rg` helper kandidat mati seperti `extractAddressByPatterns()` dan `formatPackageSizeLine()`.
2. Hapus helper mati beserta helper privat pendukung yang menjadi tidak terpakai.
3. Jangan ubah draft state machine, route picker payload, pricing, atau order creation.

Verifikasi minimal:

- `php -l` file terkait.
- `php artisan test tests\Feature\ChatbotCourierFlowTest.php`.

### Step 6D - Chatbot Transport Shared Helper

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Tujuan: mengurangi duplikasi Ride/Courier chatbot yang paling jelas, tetapi tetap mempertahankan endpoint dan payload action frontend.

Rujukan wajib:

- `Backend_Bangdeliv/app/Services/Chatbot/ChatbotRideOrderService.php`
- `Backend_Bangdeliv/app/Services/Chatbot/ChatbotCourierOrderService.php`
- `Backend_Bangdeliv/app/Http/Controllers/Api/ChatbotController.php`
- Entry service chatbot di `mega_refactor_TA.md`.

Scope awal yang aman:

1. Ekstrak guard customer aktif, normalisasi payment method/preset action, atau builder action payload kecil yang sama.
2. Jangan langsung memindahkan order creation atau draft persistence besar.
3. Pertahankan action hints frontend: `OPEN_ROUTE_PICKER`, map picker, payment, confirm, reset, dan change pickup.

Verifikasi minimal:

- `php artisan test tests\Feature\ChatbotRideFlowTest.php tests\Feature\ChatbotCourierFlowTest.php`.
- Search action payload penting.

### Step 6E - Chatbot Shopping Normalizer Kecil

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Tujuan: konsolidasi normalisasi item/merchant kecil di chatbot Shopping tanpa rewrite flow multi-merchant.

Rujukan wajib:

- `Backend_Bangdeliv/app/Services/Chatbot/ChatbotShoppingOrderService.php`
- `Backend_Bangdeliv/app/Services/Chatbot/ChatbotShoppingItemIntentParser.php`
- `Backend_Bangdeliv/app/Services/Chatbot/ChatbotGeminiService.php`
- Entry chatbot shopping di `mega_refactor_TA.md`.

Scope awal:

1. Satukan normalisasi nama item/quantity/operation yang berulang bila cakupannya kecil.
2. Jangan ubah flow create order, route/pricing, payment, atau multi-merchant draft.
3. Pastikan tidak menghidupkan kembali `is_heavy`/overweight surcharge.

Verifikasi minimal:

- `php artisan test tests\Feature\Api\ChatbotShoppingFlowTest.php`.
- Test parser chatbot shopping jika tersedia.

### Step 6F - OrderService Shopping Pickup Helper

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Tujuan: memecah bagian `OrderService` yang paling jelas batasnya tanpa mengubah kontrak controller.

Rujukan wajib:

- `Backend_Bangdeliv/app/Services/Order/OrderService.php`
- `Backend_Bangdeliv/app/Services/Shopping/ShoppingMerchantCandidateResolver.php`
- `Backend_Bangdeliv/app/Services/Shopping/ShoppingRouteService.php`
- Entry `OrderService` dan Shopping services di `mega_refactor_TA.md`.

Scope awal:

1. Ekstrak helper pickup/merchant Shopping seperti lookup pickup by id, active pickup count, merchant payload, dan create pickup location ke service kecil bila dependency-nya tetap sederhana.
2. `OrderService` tetap menjadi facade/orchestrator endpoint dulu.
3. Jangan gabungkan dengan payment/proof atau status transition.

Verifikasi minimal:

- `php artisan test tests\Feature\Api\ShoppingOrderItemEditTest.php tests\Feature\Api\DriverOrderWorkflowTest.php tests\Feature\Api\DriverOrderRevisionEndpointsTest.php`.

### Step 6G - OrderService Proof/Payment Orchestration

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Tujuan: mengurangi campuran proof/payment di `OrderService` dengan memanfaatkan service kecil yang sudah ada.

Rujukan wajib:

- `Backend_Bangdeliv/app/Services/Order/OrderService.php`
- `Backend_Bangdeliv/app/Services/Order/OrderPaymentService.php`
- `Backend_Bangdeliv/app/Services/Order/OrderProofPolicyService.php`
- `Backend_Bangdeliv/app/Services/Order/OrderTransferEvidenceService.php`

Scope awal:

1. Pindahkan helper proof/payment kecil yang tidak mengubah behavior.
2. Pertahankan COD/QRIS, upload transfer evidence, store closed proof, dan payment penalty 50%.
3. Jangan ubah status transition/action rules pada sub-step ini.

Verifikasi minimal:

- `php artisan test tests\Feature\Api\CodPaymentFlowTest.php tests\Feature\Api\DriverOrderWorkflowTest.php tests\Feature\Api\OrderPricingPushNotificationTest.php`.

### Step 6H - Admin Blade View Model

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Tujuan: mengurangi logic/query langsung di Blade admin secara bertahap.

Rujukan wajib:

- `Backend_Bangdeliv/routes/web.php`
- controller admin terkait bila dibuat.
- `Backend_Bangdeliv/resources/views/admin/*`
- Entry Blade/admin di `mega_refactor_TA.md`.

Scope awal:

1. Pilih satu halaman admin yang paling kecil.
2. Pindahkan data preparation ke controller/view model.
3. Jangan redesign visual besar.

Verifikasi minimal:

- `php artisan view:cache` lalu `php artisan view:clear`.
- Route list/admin feature test jika tersedia.

### Step 6I - Flutter Map Picker Shared Helper

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Tujuan: mengurangi duplikasi permission/search/reverse-geocode pada map picker Flutter tanpa rewrite UI.

Rujukan wajib:

- `frontend_bangdeliv/lib/features/addresses/presentation/screens/address_location_picker_screen.dart`
- `frontend_bangdeliv/lib/features/addresses/presentation/screens/route_location_picker_screen.dart`
- `frontend_bangdeliv/lib/features/shopping/presentation/screens/shopping_merchant_map_picker_screen.dart`
- Entry map picker di `mega_refactor_TA.md`.

Scope awal:

1. Ekstrak helper non-UI untuk validasi koordinat, permission/current location, atau search lookup kecil.
2. Jangan ganti layout/map widget besar.
3. Pertahankan result route picker dan chatbot patch payload.

Verifikasi minimal:

- `flutter analyze`.
- Widget/unit test map picker terkait jika tersedia.

### Step 6J - Final Stabilization

Status: selesai pada 2026-06-20. Ringkasan hasil ada di `Backend_Bangdeliv/docs/refactor_implementation_summary.md`.

Tujuan: memastikan seluruh sub-step Step 6 tidak menggeser kontrak utama.

Scope:

1. Jalankan search target lama service fee/careful-carry/heavy lagi.
2. Jalankan test backend/frontend yang paling representatif.
3. Update `mega_refactor_TA.md` untuk menandai sub-step yang sudah tidak lagi menjadi masalah aktif.

Verifikasi minimal:

- `php artisan test` jika waktu memungkinkan.
- `flutter analyze`.
- Targeted frontend tests yang terdampak.

Catatan:

- Step ini dilakukan setelah service fee cleanup hijau.
- Jangan digabung dengan perubahan schema/pricing.

## Checklist Selesai Global

- Tidak ada query runtime ke `service_fee_rules`.
- Tidak ada query runtime ke `order_fee_lines`.
- Tidak ada request/payload/UI `careful_carry_required`.
- Tidak ada UI "Perlu 2 orang".
- Tidak ada surcharge item berat dari `is_heavy`.
- `orders.service_fee` tetap ada sebagai scalar final.
- Order normal memiliki `service_fee = 0`.
- Order gagal pickup/merchant tutup 3 kali tetap menagih 50% ongkir.
- `total_price` tetap konsisten.
- Frontend masih bisa tracking order, chat, upload proof, bayar COD/QRIS, dan menerima update realtime.
