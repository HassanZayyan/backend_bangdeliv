# Minimal Refactor Plan: Service Fee dan Courier Careful Carry

## Tujuan

Plan ini dibuat untuk refactor branch `refactor/global` dengan perubahan seminimal mungkin.

- Hapus fitur Courier "perlu 2 orang" / `careful_carry_required`.
- Hapus surcharge otomatis yang muncul dari fitur "perlu 2 orang".
- Hapus mekanisme `service_fee_rules` sebagai master/rule table.
- Hapus mekanisme `order_fee_lines` sebagai breakdown fee per order.
- Pertahankan satu service fee yang masih dibutuhkan: penalti 50% ongkir setelah gagal pickup merchant 3 kali.
- Pertahankan kemampuan driver mengubah ongkir manual sebagai sumber penyesuaian ongkir.

## Prinsip Refactor

- Jangan hapus service type `COURIER`; yang dihapus hanya fitur Courier "perlu 2 orang".
- Jangan ubah endpoint publik, route name, HTTP method, atau envelope JSON `success/message/data`.
- Jangan ubah alur utama order Ride, Courier, dan Shopping/Nitip.
- Hindari refactor besar di luar area pricing, payload, model, dan UI yang terdampak langsung.
- Untuk menjaga kompatibilitas Flutter, kolom dan field ringkas `service_fee` tetap dipertahankan dulu sebagai total service fee. Yang dihapus adalah master rule dan fee line detail.

## Scope Backend

### 1. Database dan Model

- Hapus migrasi/table `service_fee_rules`.
- Hapus migrasi/table `order_fee_lines`.
- Pertahankan `orders.service_fee` untuk menyimpan satu nilai service fee final.
- Hapus model `App\Models\ServiceFeeRule`.
- Hapus model `App\Models\OrderFeeLine`.
- Hapus relasi `ServiceType::serviceFeeRules()`.
- Hapus relasi `Order::feeLines()`.
- Hapus docblock/model reference yang masih menyebut `OrderFeeLine` atau `ServiceFeeRule`.

Catatan migrasi:

- Jika database dev masih sering reset dari awal, ubah migrasi awal agar tidak membuat `service_fee_rules` dan `order_fee_lines`.
- Jika database existing perlu aman, tambahkan migration baru untuk `dropIfExists('service_fee_rules')` dan `dropIfExists('order_fee_lines')`.

### 2. Shopping Pricing

Target file utama:

- `app/Services/Pricing/ShoppingPricingService.php`
- `app/Services/Driver/DriverOrderPayloadFactory.php`
- `app/Services/Order/OrderService.php`
- `app/Models/Order.php`

Rencana:

- Ganti pembacaan `ServiceFeeRule` dengan konstanta internal atau config sederhana.
- Hapus rule fee item banyak:
  - `ITEM_BLOCK_SURCHARGE`
  - `calculateItemSurcharge`
  - payload `item_surcharge`
- Hapus rule item berat:
  - `OVERWEIGHT_FLAT_SURCHARGE`
  - payload `overweight_surcharge`
  - kalkulasi dari `is_heavy` jika hanya dipakai untuk surcharge.
- Pertahankan rule penalti:
  - `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS`
  - threshold default `3`
  - percent default `50`
- Hapus `syncFeeLines()`, `feeLineAmount()`, dan `feeBreakdownForOrder()`.
- Simpan penalti langsung ke `orders.service_fee`.
- Hitung `orders.total_price = subtotal + delivery_fee + service_fee`.
- Untuk order normal tanpa penalti, `service_fee` menjadi `0`.

### 3. Penalti Gagal Pickup 3 Kali

Rencana implementasi minimal:

- Buat method kecil di `ShoppingPricingService`, misalnya:
  - `cancellationFailedAttemptThreshold(): int`
  - `calculateCancellationPenalty(Order $order): float`
  - `failedAttemptCount(Order $order): int`
- Hardcode default dulu:
  - threshold `3`
  - penalty `50%` dari ongkir final/locked delivery fee.
- Saat status menjadi `CANCELLED_WITH_FEE`, set:
  - `orders.service_fee = cancellation_penalty`
  - `orders.total_price = delivery_fee + cancellation_penalty` jika subtotal memang tidak ditagihkan.
- Payload boleh tetap mengirim:
  - `pricing.service_fee`
  - `pricing.cancellation_penalty`
  - `pricing.failed_attempt_count`
  - `pricing.failed_attempt_threshold`
- Payload tidak perlu lagi mengirim `pricing.fee_breakdown` dari table fee lines.

### 4. Courier "Perlu 2 Orang"

Target file utama:

- `app/Services/Chatbot/CourierPackagePolicyService.php`
- `app/Services/Order/DeliveryFeeNegotiationService.php`
- `app/Services/Order/OrderService.php`
- `app/Services/Driver/DriverOrderPayloadFactory.php`
- `database/migrations/2026_04_09_000012_create_courier_orders_table.php`

Rencana:

- Hapus kolom `courier_order_details.careful_carry_required` jika tidak dipakai lagi.
- Hapus validasi/request `careful_carry_required` dari endpoint revisi ongkir.
- Hapus logika surcharge `base * 0.5` untuk careful carry.
- Hapus helper `carefulCarryRequired()` dan `setCourierCarefulCarryRequired()`.
- Hapus payload `careful_carry_required` dari response driver/customer jika frontend sudah disesuaikan.
- Ubah pesan policy paket Courier agar tidak menyarankan "bantuan 2 orang"; cukup arahkan bahwa driver dapat menyesuaikan ongkir manual jika barang terlalu besar/berat.

## Scope Frontend

Target file utama:

- `lib/utils/service_type.dart`
- `lib/utils/order_ui_helpers.dart`
- `lib/models/driver_order_model.dart`
- `lib/models/customer_order_model.dart`
- `lib/models/delivery_fee_negotiation_model.dart`
- `lib/features/driver_orders/presentation/widgets/driver_active_order_fee_widgets.dart`
- `lib/features/driver_orders/presentation/widgets/driver_active_order_meta_widgets.dart`
- `lib/features/tracking/application/customer_order_tracking_provider.dart`

Rencana:

- Hapus helper `serviceTypeSupportsCarefulCarry()`.
- Hapus helper `carefulCarryDefaultDeliveryFee()`.
- Hapus field model `carefulCarryRequired` jika backend tidak lagi mengirim field tersebut.
- Hapus chip/label "Perlu 2 orang".
- Hapus checkbox "Perlu 2 orang / hati-hati" pada form revisi ongkir driver.
- Hapus pengiriman parameter `careful_carry_required` saat driver mengajukan revisi ongkir.
- Pertahankan tampilan `service_fee` sebagai angka tunggal jika backend masih mengirimnya.
- Hapus parsing/tampilan `fee_lines` atau `fee_breakdown` yang hanya berasal dari `order_fee_lines`, kecuali breakdown ongkir dari route pricing yang masih berguna.

## Tests Yang Perlu Disesuaikan

Backend:

- Hapus atau update `tests/Feature/ShoppingServiceFeeRulesTest.php`.
- Update test yang assert table `order_fee_lines`.
- Update test `DriverOrderWorkflowTest` sekitar:
  - item surcharge
  - overweight surcharge
  - cancellation penalty 3 kali gagal
- Update test `DriverOrderRevisionEndpointsTest` sekitar `careful_carry_required`.
- Pastikan test penalti 3 gagal tetap ada dan assert ke `orders.service_fee`, bukan `order_fee_lines`.

Frontend:

- Update `test/utils/service_type_test.dart`.
- Update `test/utils/order_ui_helpers_test.dart`.
- Update model tests yang assert `careful_carry_required`.
- Update widget tests pada form revisi ongkir dan metadata driver active order.

## Urutan Eksekusi Disarankan

1. Backend: hapus fitur Courier "perlu 2 orang" dari pricing negotiation dan payload.
2. Frontend: hapus UI dan request field `careful_carry_required`.
3. Backend: sederhanakan `ShoppingPricingService` agar hanya menghitung penalti gagal 3 kali.
4. Backend: hapus penggunaan `order_fee_lines` dari model, payload, dan tests.
5. Backend: hapus `service_fee_rules` dari pricing dan tests.
6. Database: hapus table `service_fee_rules` dan `order_fee_lines` lewat migration strategy yang dipilih.
7. Docs: update ERD/database schema/use case agar tidak lagi menyebut fee line dan master service fee.
8. Verifikasi end-to-end Ride, Courier, Shopping normal, Shopping gagal 3 kali, dan revisi ongkir manual driver.

## Acceptance Criteria

- Tidak ada referensi runtime ke `ServiceFeeRule`.
- Tidak ada referensi runtime ke `OrderFeeLine`.
- Tidak ada query ke table `service_fee_rules`.
- Tidak ada query ke table `order_fee_lines`.
- Tidak ada UI, payload, atau request untuk `careful_carry_required`.
- Driver tetap bisa mengedit ongkir manual.
- Shopping/Nitip yang gagal pickup merchant 3 kali tetap membuat tagihan penalti 50% ongkir.
- `orders.total_price` tetap konsisten dengan `subtotal + delivery_fee + service_fee`.
- Endpoint Flutter tetap kompatibel, minimal untuk route dan envelope response.

## Risiko dan Catatan

- Menghapus `fee_breakdown` dari payload bisa berdampak ke UI tracking/payment jika frontend masih membacanya sebagai fallback.
- Menghapus `careful_carry_required` perlu dilakukan sinkron antara backend dan frontend agar request revisi ongkir tidak lagi mengirim field yang ditolak backend.
- Jika migration lama sudah pernah dijalankan di database lokal, mengedit migration awal saja tidak cukup; perlu migration drop table.
- Jika laporan TA/ERD masih memakai `service_fee_rules` dan `order_fee_lines`, dokumen tersebut perlu diupdate setelah implementasi.
