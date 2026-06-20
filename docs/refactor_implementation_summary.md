# Refactor Implementation Summary

Tanggal rangkuman: 2026-06-20

Dokumen ini merangkum hasil Step 0 sampai Step 6J. Rujukan analisis utama tetap `Backend_Bangdeliv/docs/mega_refactor_TA.md`, sedangkan file step individual lama sudah digabung ke dokumen ini.

## Keputusan Utama

- `orders.service_fee` tetap dipertahankan sebagai scalar final untuk satu nilai service fee/penalti.
- Mekanisme generic `service_fee_rules` dan `order_fee_lines` dihapus dari runtime dan schema aktif.
- Fitur Courier "perlu 2 orang" / `careful_carry_required` dihapus dari backend, frontend, payload, UI, dan schema aktif.
- Surcharge Shopping berbasis `is_heavy`, item block surcharge, dan overweight surcharge dihapus.
- Flow gagal pickup/merchant tutup 3 kali tetap dipertahankan: penalti 50% ongkir disimpan di `orders.service_fee` lewat status `CANCELLED_WITH_FEE`.
- `delivery_fee`, `delivery_fee_source`, negosiasi ongkir, edit ongkir manual driver, COD/QRIS, proof pembayaran, tracking, chat, dan realtime tetap dipertahankan.

## Step 0 - Baseline

Baseline mencatat target lama sebelum refactor:

- `service_fee_rules`, `ServiceFeeRule`;
- `order_fee_lines`, `OrderFeeLine`, `feeLines`;
- `careful_carry_required`, `carefulCarryRequired`;
- `is_heavy`, `isHeavy`;
- `ITEM_BLOCK_SURCHARGE`, `OVERWEIGHT_FLAT_SURCHARGE`.

Hasil baseline:

- `php artisan test` lulus sebelum refactor.
- `flutter analyze` lulus sebelum refactor.
- `flutter test` punya 1 failure lama di `driver_active_order_ui_test.dart` karena source-inspection substring anchor tidak ditemukan.

## Step 1 - Backend Service Fee Core

Runtime backend dibersihkan dari mekanisme generic service fee:

- `ShoppingPricingService` tidak lagi membaca `ServiceFeeRule` atau menulis/membaca `OrderFeeLine`.
- `ITEM_BLOCK_SURCHARGE` dan `OVERWEIGHT_FLAT_SURCHARGE` tidak lagi dihitung.
- Penalti gagal pickup 3 kali dipindahkan menjadi rule eksplisit: threshold 3, nilai 50% ongkir.
- `Order::feeLines()` dan `ServiceType::feeRules()` dicabut dari runtime.
- Payload tetap menjaga field kompatibilitas tertentu sebagai `0.0` ketika diperlukan.

Verifikasi utama:

- `ShoppingPenaltyPricingTest`, `DriverOrderWorkflowTest`, dan `ShoppingOrderItemEditTest` lulus.
- Full backend test lulus pada akhir step.

## Step 2 - Backend Careful Carry dan Heavy Cleanup

Backend tidak lagi memakai fitur Courier "perlu 2 orang" dan item berat:

- Request revisi ongkir tidak lagi menerima `careful_carry_required`.
- `DeliveryFeeNegotiationService::quoteAmounts()` memakai nominal driver murni tanpa surcharge 50%.
- `OrderService`, `DriverOrderPayloadFactory`, `Order`, `CourierOrder`, `OrderItem`, dan request shopping item dibersihkan dari careful-carry/heavy runtime.
- Chatbot Shopping tidak lagi membentuk item seed/create payload dengan `is_heavy`.

Yang tetap hidup:

- Courier normal dengan `package_description`.
- Delivery fee override dan delivery fee negotiation.
- Penalti gagal pickup/merchant tutup 3 kali.

Verifikasi utama:

- Driver revision, driver workflow, shopping item edit, chatbot shopping flow, dan pricing notification tests lulus.
- Full backend test lulus pada akhir step.

## Step 3 - Frontend Sync

Flutter disinkronkan dengan payload backend baru:

- Model customer/driver menghapus `carefulCarryRequired`, `isHeavy`, dan fee breakdown generic dari fee lines.
- Driver order service/repository/provider tidak lagi mengirim `careful_carry_required`.
- UI revisi ongkir driver tidak lagi menampilkan opsi "Perlu 2 orang".
- UI checkout Nitip driver tidak lagi menampilkan toggle "Item berat".
- `ShoppingFeeBreakdown` dihapus karena tidak ada pemakai setelah fee lines generic dihapus.
- Helper careful-carry frontend dihapus.

Yang tetap dipertahankan:

- `serviceFee` scalar;
- `deliveryFee`;
- delivery fee negotiation;
- tracking, chat, QRIS/COD, dan payment proof;
- penalty gagal pickup/merchant.

Verifikasi utama:

- `flutter analyze` lulus.
- Targeted frontend tests model/provider/widget/screen lulus.

## Step 4 - Schema dan Data Cleanup

Schema final dibersihkan:

- Fresh/dev reset tidak lagi membuat `order_fee_lines`, `service_fee_rules`, `courier_order_details.careful_carry_required`, atau `shopping_order_items.is_heavy`.
- Migration cleanup idempotent ditambahkan untuk drop table/kolom lama pada DB existing.
- Model obsolete `OrderFeeLine` dan `ServiceFeeRule` dihapus.
- `ShoppingServiceFeeRulesTest` direname menjadi `ShoppingPenaltyPricingTest`.

Verifikasi utama:

- Migration/test syntax lulus.
- Targeted backend tests lulus.
- Full backend test lulus pada akhir step.

## Step 5 - Low-Risk Cleanup

Cleanup placeholder/unused/legacy dilakukan setelah service fee cleanup stabil:

- Forgot password frontend di-hide karena backend reset password belum ada.
- Widget/model Flutter unused dihapus: `BangBottomActionBar`, `BangStatusPill`, dan legacy `OrderModel`.
- Route legacy driver status `PATCH /driver/orders/{orderId}/status` dan `OrderExecutionController` dihapus.
- Admin settings tarif menjadi read-only snapshot, bukan form/kalkulator mock aktif.

Verifikasi utama:

- `flutter analyze` lulus.
- API route contract test lulus.
- Route list dan Blade cache/clear lulus.

## Step 6A - Shared Geo Helper

Helper jarak/koordinat backend dipusatkan:

- Menambahkan `App\Support\GeoDistance`.
- `DriverDistanceCalculator`, `RideOrderService`, `ShoppingMerchantCandidateResolver`, dan `OrderService` memakai helper bersama.
- Rumus haversine lokal di service target dibersihkan.

Verifikasi utama:

- `GeoDistanceTest`, ride creation, shopping item edit, dan driver workflow terkait jarak lulus.

## Step 6B - Courier Package Policy Copy Cleanup

Copy policy paket Courier dibersihkan dari konsep "bantuan 2 orang":

- Copy oversize menjadi netral: driver dapat menyesuaikan ongkir atau memberi catatan penanganan manual.
- Konstanta unused `STATUS_OVERSIZE` dihapus.
- Oversize tetap warning/`size_class`, bukan pemblokir order.

Verifikasi utama:

- Search copy lama bersih di service/test target.
- `ChatbotCourierFlowTest` lulus.

## Step 6C - Chatbot Courier Dead Code Cleanup

Dead code kecil di `ChatbotCourierOrderService` dihapus:

- `$locationHints`;
- `extractAddressByPatterns()`;
- `isLikelyAddressFragment()`;
- `formatPackageSizeLine()`.

Tidak ada perubahan pada draft state machine, route picker payload, pricing, payment method, geocoding, distance matrix, atau order creation.

Verifikasi utama:

- Search helper mati bersih.
- `ChatbotCourierFlowTest` lulus.

## Step 6D - Chatbot Transport Shared Helper

Duplikasi kecil Ride/Courier chatbot dipindahkan ke `ChatbotTransportSupport`:

- Guard customer aktif.
- Normalisasi payment method `COD`/`TRANSFER`.
- Label payment method `COD`/`QRIS`.
- Payload action payment draft: `RESET_DESTINATION`, `SET_PAYMENT_COD`, `SET_PAYMENT_TRANSFER`, dan `CONFIRM_DRAFT`.

Yang tidak diubah:

- endpoint chatbot;
- route picker/map picker;
- draft persistence;
- order creation;
- pricing dan delivery fee.

Verifikasi utama:

- `ChatbotRideFlowTest` dan `ChatbotCourierFlowTest` lulus.

## Step 6E - Chatbot Shopping Item Normalizer

Normalisasi item chatbot Nitip dipusatkan di `ChatbotShoppingItemNormalizer`:

- Normalisasi nama item, quantity, operation, notes, alias payload Gemini `menu/qty`, dan stable key untuk merge.
- `ChatbotGeminiService`, `ChatbotShoppingOrderService`, dan `ChatbotShoppingItemIntentParser` memakai helper yang sama.
- Unit test memastikan input `is_heavy` dibuang.

Yang tidak diubah:

- flow multi-merchant;
- merchant picker;
- route/pricing;
- payment;
- draft persistence;
- order creation.

Verifikasi utama:

- `ChatbotShoppingItemNormalizerTest` dan `ChatbotShoppingFlowTest` lulus.

## Step 6F - OrderService Shopping Pickup Helper

Helper pickup/merchant Shopping dipindahkan dari `OrderService` ke `ShoppingPickupLocationService`:

- lookup pickup by id;
- active pickup count;
- cek pickup aktif dengan item tersedia;
- merchant payload dari pickup;
- resolve pickup merchant DB/external Google Place;
- create pickup location.

`OrderService` tetap menjadi facade/orchestrator workflow order.

Verifikasi utama:

- `ShoppingOrderItemEditTest`, `DriverOrderWorkflowTest`, dan `DriverOrderRevisionEndpointsTest` lulus.

## Step 6G - OrderService Proof/Payment Orchestration

Helper proof/payment mekanis dikeluarkan dari `OrderService`:

- Menambahkan `OrderEvidenceService`.
- Storage foto order, pencatatan driver evidence, dan pengecekan bukti per proof type dipindahkan.
- `OrderPaymentService` mendapat helper `currentMethod()` dan `isPaid()`.
- `OrderTransferEvidenceService` memakai helper storage evidence yang sama.

Yang tidak diubah:

- status transition driver;
- `recordCodPayment()`;
- `confirmTransferPaymentByDriver()`;
- `uploadTransferEvidenceByCustomer()`;
- COD/QRIS, reminder pembayaran, store closed proof, receipt proof, dan penalti gagal pickup.

Verifikasi utama:

- Cod payment, driver workflow, pricing notification, dan driver revision tests lulus.

## Step 6H - Admin AI Monitor View Model

Query/data preparation AI Monitor dipindahkan dari Blade ke controller:

- Menambahkan `Admin\AiMonitorController`.
- Route `admin.ai-monitor` memakai controller.
- Statistik AI, primary model, dan transform log terbaru dipindahkan dari Blade.
- Test admin web diperbarui untuk memastikan halaman AI Monitor bisa dibuka.

Yang tidak diubah:

- desain/layout/admin UI;
- data health panel statis;
- halaman admin lain.

Verifikasi utama:

- `view:cache`, `view:clear`, route list, dan `DashboardWebTest` lulus.

## Step 6I - Flutter Map Picker Shared Helper

Helper non-UI map picker Flutter ditambahkan:

- `frontend_bangdeliv/lib/utils/map_picker_helpers.dart`.
- Memindahkan validasi `LatLng`, jarak haversine, Google Places session token, segmen alamat pertama, cek permission lokasi, dan ambil current location.
- Dipakai oleh address picker, route picker, dan shopping merchant map picker.

Yang tidak diubah:

- layout Google Map;
- search UI;
- reverse geocode UI;
- `RouteLocationPickerResult`;
- payload patch chatbot.

Verifikasi utama:

- `map_picker_helpers_test.dart`, `route_location_picker_screen_test.dart`, dan `flutter analyze` lulus.

## Step 6J - Final Stabilization

Stabilisasi akhir memastikan target lama tidak kembali:

- Search runtime bersih untuk careful-carry, UI "Perlu 2 orang", runtime `is_heavy`, `ShoppingFeeBreakdown`, runtime `feeLines/fee_lines`, `OrderFeeLine`, dan `ServiceFeeRule`.
- Payload `fee_lines` dari history driver backend dihapus.
- Accessor mati `line_service_fee` / `line_total` dari `OrderItem` dihapus.
- Nama lokal chatbot Flutter `feeLines` diganti menjadi `estimateLines` karena hanya berisi teks estimasi ongkir/total sementara.
- Dua test source-inspection frontend yang tertinggal setelah cleanup disesuaikan.

Sisa kemunculan target lama yang disengaja:

- migration cleanup untuk drop table/kolom lama;
- test negatif yang memastikan "Item berat" tidak tampil;
- test normalizer yang memastikan input `is_heavy` dibuang.

Verifikasi final:

- `php artisan test` lulus: 282 test, 1715 assertion.
- `flutter test` lulus: 182 test.
- `flutter analyze` lulus.

## Status Akhir

- Tidak ada query runtime ke `service_fee_rules`.
- Tidak ada query runtime ke `order_fee_lines`.
- Tidak ada request/payload/UI `careful_carry_required`.
- Tidak ada UI "Perlu 2 orang".
- Tidak ada surcharge item berat dari `is_heavy`.
- `orders.service_fee` tetap ada sebagai scalar final.
- Order normal memakai `service_fee = 0`.
- Order gagal pickup/merchant tutup 3 kali tetap menagih 50% ongkir.
- `total_price` tetap konsisten dengan subtotal, ongkir, dan service fee/penalti final.
- Frontend tetap bisa tracking order, chat, upload proof, bayar COD/QRIS, dan menerima update realtime.

## Dokumen Terkait Yang Tetap Dipakai

- `Backend_Bangdeliv/docs/mega_refactor_TA.md`
- `Backend_Bangdeliv/docs/minimal_refactor_service_fee_plan.md`
- `Backend_Bangdeliv/docs/refactor_execution_steps.md`
