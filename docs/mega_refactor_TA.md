# Mega Refactor TA

Audit file per file untuk refactor minimal Backend_Bangdeliv dan frontend_bangdeliv.

Prinsip utama refactor:

- Refactor dengan perubahan kode seminimal mungkin.
- Prioritaskan kode yang clean, mudah dibaca, dan mudah diuji.
- Jangan ubah behavior, route, kontrak API, atau UI flow tanpa alasan kuat.
- Hapus redundansi hanya jika dampaknya jelas dan risikonya rendah.
- Simpan perubahan besar per klaster kecil agar mudah diverifikasi.
- Dokumen ini bersifat hidup: jika audit file baru menghapus keraguan atau mengubah asumsi pada file lama, revisi entry lama agar keputusan refactor makin tegas.

## frontend_bangdeliv/lib/config/app_colors.dart

- Fungsi: pusat token warna Flutter untuk brand BangDeliv, theme, widget umum, status, text, border, dan background.
- Logic penting: tidak ada logic runtime; isinya `static const Color` yang dipakai luas oleh `AppTheme` dan banyak screen/widget.
- Redundansi/minimalisasi: `info` belum dipakai via `AppColors.info`, sementara warna biru sejenis masih hard-coded di widget lain; `surface` dan `white` sama-sama `Colors.white`; `border` dan `divider` memakai hex sama tetapi masih punya makna semantik berbeda. Refactor minimal cukup biarkan dulu, kecuali nanti mau konsolidasi raw color/hard-coded color bertahap.
- Service fee notice: tidak ada logic service fee.
- Risiko: menghapus atau mengganti token warna berdampak luas karena `AppColors` dipakai ratusan kali; perubahan sebaiknya dilakukan setelah audit raw color dan theme selesai.

## frontend_bangdeliv/lib/config/app_env.dart

- Fungsi: pusat konfigurasi compile-time Flutter untuk API base URL, Google Maps key, Reverb/Pusher WebSocket, backend origin, normalisasi URL asset backend, dan debug summary.
- Logic penting: `apiBaseUrl` memakai `String.fromEnvironment('API_BASE_URL')` lalu fallback ke default; `backendOrigin` menghapus path `/api`; `resolveBackendAssetUrl()` menangani URL kosong, protocol-relative, absolute URL, loopback URL, dan path relatif `/storage`; WebSocket memakai host API yang sama dengan port/scheme dari dart define.
- Redundansi/minimalisasi: karena `.vscode/launch.json` selalu memakai `--dart-define-from-file=dart_defines.local.json` dan file lokal sudah berisi `API_BASE_URL`, fallback Android hard-coded `192.168.1.80` terlihat redundant/stale untuk launch normal. `pusherCluster` didefinisikan tetapi tidak dipakai oleh `PusherService`; `GEMINI_API_KEY` dan `GEMINI_MODEL` ada di dart defines tetapi tidak dibaca oleh `AppEnv`/frontend. Parsing `Uri.parse(apiBaseUrl)` di beberapa getter masih kecil dan aman, bukan prioritas.
- Service fee notice: tidak ada logic service fee.
- Risiko: menghapus fallback Android bisa membuat `flutter run` tanpa launch config/dart defines gagal di physical device; menghapus env yang belum dipakai perlu cek apakah masih dibutuhkan tooling eksternal atau rencana fitur.

## frontend_bangdeliv/lib/config/app_routes.dart

- Fungsi: pusat konstanta path GoRouter dan helper pembentuk URL dinamis untuk customer, driver, chatbot, alamat, tracking, chat order, merchant, dan menu.
- Logic penting: route dinamis memakai placeholder seperti `:orderId`, sedangkan helper seperti `orderTrackPath()` membuat path aktual plus query `focus`/`pickup_location_id` untuk deep link tracking dari notifikasi.
- Redundansi/minimalisasi: `AppRoutes.orders` tidak dipakai; `menuDetailPath()` belum dipakai walau `merchantDetailPath()` dipakai. File juga menduplikasi string path antara konstanta dan helper, tetapi ini masih wajar karena belum memakai named routes GoRouter.
- Service fee notice: tidak ada logic service fee; hanya ada route tracking yang bisa membawa query focus terkait delivery fee/payment.
- Risiko: menghapus route/helper yang terlihat tidak dipakai harus cek deep link/notifikasi/manual URL; `orderTrackPath()` penting untuk FCM dan jangan disatukan dengan `/track` tanpa migrasi caller.

## frontend_bangdeliv/lib/config/app_router.dart

- Fungsi: konfigurasi utama `GoRouter`, auth/role redirect, shell navigation customer/driver, route builder untuk semua screen, dan invalidasi provider saat session berubah.
- Logic penting: route root memakai custom slide transition; redirect membedakan public, guest-accessible, customer-only, driver aktif/nonaktif, dan admin; `ref.listen(authSessionProvider)` menghapus cache order/home/chat/realtime ketika auth/role/user berubah.
- Redundansi/minimalisasi: file ini memegang terlalu banyak tanggung jawab sekaligus (route table, parsing `state.extra`, auth policy, cache invalidation). Ada dua entry tracking (`/track` via `extra`/active order dan `/orders/:orderId/track` via path) yang perlu dipertahankan sampai caller dimigrasi. `_customerOnlyRoutes` belum memasukkan beberapa route customer seperti `orderTrack`, `orderChat`, dan `chatbot`, sehingga driver aktif masih bisa lolos ke route itu jika navigasi langsung; perlu diklarifikasi apakah sengaja untuk chat/tracking lintas role. Invalid ID screen diulang beberapa kali sebagai `Scaffold` sederhana.
- Service fee notice: tidak ada logic service fee langsung.
- Risiko: refactor router rawan memutus auth redirect, notifikasi deep link, bottom navigation shell, dan cache invalidation; pecah file boleh dilakukan belakangan per klaster kecil, bukan sambil ubah behavior.

## frontend_bangdeliv/lib/config/app_theme.dart

- Fungsi: pusat `ThemeData` light mode untuk Material 3, color scheme, typography Nunito Sans, AppBar, Card, Button, BottomNavigationBar, Chip, SnackBar, dan InputDecoration.
- Logic penting: `AppTheme.lightTheme` dipasang global di `MaterialApp.router`; semua token warna berasal dari `AppColors`, dan typography memakai `GoogleFonts.nunitoSansTextTheme()` lalu override style utama.
- Redundansi/minimalisasi: banyak screen/widget masih membuat `TextStyle`, `InputDecoration`, dan `Button.styleFrom` manual, sehingga theme belum sepenuhnya menjadi sumber tunggal. Refactor minimal jangan ubah theme dulu; lebih aman nanti konsolidasi style berulang per widget/flow setelah audit UI selesai.
- Service fee notice: tidak ada logic service fee.
- Risiko: mengubah ukuran font, radius, padding, atau button theme di sini berdampak global dan bisa menggeser layout/test widget; validasi visual dan widget test perlu dijalankan jika theme diubah.

## frontend_bangdeliv/lib/config/payment_assets.dart

- Fungsi: menyimpan path QRIS dummy dan membentuk URL publik QRIS dari `AppEnv.backendOrigin`.
- Logic penting: `qrisUrl` sengaja memakai `backendOrigin`, bukan `apiBaseUrl`, sehingga URL menjadi `/images/...` dan tidak tersisip `/api`; sudah dijaga oleh `test/config/payment_assets_test.dart`.
- Redundansi/minimalisasi: file sangat kecil dan belum redundant. Jika nanti aset QRIS menjadi dinamis dari backend/env, file ini bisa diganti menjadi config/env service, tetapi untuk sekarang clean dan low-risk.
- Service fee notice: tidak ada logic service fee langsung; QRIS dipakai juga untuk pembayaran penalti 50% di tracking, tetapi file ini hanya menyediakan URL aset pembayaran.
- Risiko: mengubah `qrisPath` atau basis URL dapat memutus preview/download QRIS dan test `payment_assets_test`; jangan pakai `AppEnv.apiBaseUrl` untuk aset publik.

## frontend_bangdeliv/lib/core/di/app_providers.dart

- Fungsi: pusat dependency injection Riverpod untuk `ApiClient`, service API, repository order/chatbot/address/driver, realtime client, QRIS downloader, home search query, dan `homeDataProvider`.
- Logic penting: mayoritas service memakai `apiClientProvider`; repository membungkus service API; `qrisDownloadServiceProvider` mendaftarkan `ref.onDispose(service.close)`; `orderRealtimeClientProvider` memakai singleton `PusherService.instance`; `homeDataProvider` bergantung pada `homeSearchQueryProvider`.
- Redundansi/minimalisasi: `rideOrderApiServiceProvider` dan `addressRepositoryProvider` tampak hanya didefinisikan dan belum dipakai non-test/runtime; `HomeScreen` punya `_homeScreenDataProvider` sendiri untuk data home berbasis lokasi sehingga `homeDataProvider` global hanya dipakai oleh detail menu/merchant dan invalidasi router. Pola DI juga belum seragam: sebagian lewat `ApiClient`, driver masih memakai `DriverOrderService()` sendiri, address memakai `AuthAddressRepository`, realtime memakai singleton.
- Service fee notice: tidak ada logic service fee langsung; `qrisDownloadServiceProvider` dapat ikut flow pembayaran penalti 50%, tetapi file ini hanya menyediakan dependency.
- Risiko: provider banyak dipakai test override dan fitur realtime/order; penghapusan provider yang terlihat idle harus dicek dengan `rg` dan test. Memecah file DI per domain bisa lebih bersih, tetapi sebaiknya dilakukan setelah audit provider fitur agar import churn tetap kecil.

## frontend_bangdeliv/lib/core/widgets/bang_action_button.dart

- Fungsi: wrapper tombol aksi reusable untuk `FilledButton`/`OutlinedButton` dengan loading spinner, ikon opsional, disabled state, dan style override.
- Logic penting: `effectiveOnPressed` otomatis null saat disabled/loading; warna progress mengikuti variant.
- Redundansi/minimalisasi: overlap dengan `BangPrimaryButton` di `lib/widgets/bang_ui.dart`, tetapi `BangActionButton` lebih fleksibel karena punya variant outlined. Bisa jadi kandidat tombol standar utama setelah audit semua tombol.
- Service fee notice: tidak ada logic service fee.
- Risiko: dipakai cukup banyak di driver/order widgets; perubahan ukuran/loading state bisa memengaruhi layout dan widget tests.

## frontend_bangdeliv/lib/core/widgets/bang_amount_negotiation_card.dart

- Fungsi: kartu approval/counter/cancel untuk negosiasi nominal, dipakai pada flow revisi ongkir customer.
- Logic penting: menampilkan amount dengan `formatCurrency`, mengunci tombol lain saat salah satu action loading, dan memakai `BangActionButton`.
- Redundansi/minimalisasi: domainnya cukup spesifik ke negosiasi amount; masih bersih. Jika nanti ada banyak negosiasi serupa, nama dan copy bisa dibuat lebih generic.
- Service fee notice: tidak ada logic service fee; terkait ongkir/delivery fee negotiation, bukan service fee.
- Risiko: jangan digabung dengan UI service fee sebelum flow delivery fee dan shopping price diaudit karena copy/action berbeda.

## frontend_bangdeliv/lib/core/widgets/bang_async_state.dart

- Fungsi: komponen reusable untuk loading, empty, dan error state.
- Logic penting: `BangLoadingState` mendukung pesan opsional; `BangEmptyState` punya ikon/title/message/action; `BangErrorState` punya retry.
- Redundansi/minimalisasi: `BangLoadingState` tampak belum dipakai; `BangEmptyState` dan `BangErrorState` punya nama sama dengan class di `lib/widgets/bang_ui.dart` tetapi API/visual berbeda. Ini kandidat konsolidasi naming/import agar tidak membingungkan.
- Service fee notice: tidak ada logic service fee.
- Risiko: duplikasi nama lintas file bisa menyebabkan import conflict saat refactor; konsolidasi harus per screen agar tidak mematahkan constructor yang berbeda.

## frontend_bangdeliv/lib/core/widgets/bang_bottom_action_bar.dart

- Fungsi: wrapper bottom action area dengan `SafeArea`, background putih, shadow atas, dan padding.
- Logic penting: hanya membungkus child dengan padding dan dekorasi bottom bar.
- Redundansi/minimalisasi: tampak belum dipakai runtime. Bisa menjadi kandidat hapus jika setelah audit semua screen tidak ada rencana memakai bottom action bar standar.
- Service fee notice: tidak ada logic service fee.
- Risiko: risiko hapus rendah jika benar tidak ada pemakaian, tetapi cek dulu kemungkinan import tersembunyi/test baru.

## frontend_bangdeliv/lib/core/widgets/bang_counter_amount_dialog.dart

- Fungsi: bottom sheet input nominal counter offer yang mengembalikan `double?`.
- Logic penting: parse input dengan `parseCurrencyInput`, menolak nominal <= 0, menampilkan current amount opsional, dan menghindari keyboard overlap lewat `AnimatedPadding`.
- Redundansi/minimalisasi: cukup spesifik dan kecil; bisa dibuat lebih reusable jika nanti semua input nominal driver/customer memakai parser dan sheet yang sama.
- Service fee notice: tidak ada logic service fee; dipakai untuk tawar ongkir.
- Risiko: perubahan parser/copy bisa berdampak pada flow negosiasi ongkir dan test `bang_amount_negotiation_card_test`.

## frontend_bangdeliv/lib/core/widgets/bang_image_preview.dart

- Fungsi: dialog preview gambar network dengan zoom/pan memakai `InteractiveViewer`.
- Logic penting: ignore URL kosong, loading state fixed size, fallback broken image, batas max tinggi 82% viewport.
- Redundansi/minimalisasi: ada beberapa `InteractiveViewer` preview custom di driver/tracking widgets; file ini bisa dijadikan preview standar bertahap.
- Service fee notice: tidak ada logic service fee; salah satu caller adalah preview QRIS/payment.
- Risiko: standardisasi preview perlu cek fitur proof/QRIS karena tiap caller mungkin punya ukuran, caption, atau action berbeda.

## frontend_bangdeliv/lib/core/widgets/bang_negotiation_cancel_sheet.dart

- Fungsi: bottom sheet daftar opsi pembatalan negosiasi dengan action string, label, dan ikon.
- Logic penting: return `String?` action dari pilihan user.
- Redundansi/minimalisasi: sangat kecil dan spesifik; bisa tetap. Jika nanti banyak action sheet serupa, model option bisa dipakai ulang sebagai generic action sheet.
- Service fee notice: tidak ada logic service fee; dipakai pada cancel negosiasi ongkir.
- Risiko: action berupa string raw rentan typo, tetapi aman selama caller/backend contract tetap sama.

## frontend_bangdeliv/lib/core/widgets/bang_negotiation_status_panel.dart

- Fungsi: panel status ringkas untuk negosiasi amount dengan ikon, label, amount, warna, dan mode compact.
- Logic penting: warna foreground/border/background diturunkan dari satu `color`; amount disembunyikan jika kosong.
- Redundansi/minimalisasi: dipakai di delivery fee dan shopping quote widgets; cukup reusable. Bisa ditingkatkan dengan enum status setelah audit model negosiasi selesai.
- Service fee notice: tidak ada logic service fee; panel ini untuk negotiation amount/ongkir/harga Nitip.
- Risiko: perubahan compact sizing berdampak ke driver active order cards yang padat.

## frontend_bangdeliv/lib/core/widgets/bang_shopping_merchant_request_summary.dart

- Fungsi: ringkasan request perubahan item/merchant Shopping/Nitip untuk customer/driver.
- Logic penting: render per requested stop jika ada; fallback ke daftar item; label request hanya membedakan `EDIT_UNAVAILABLE` vs tambah merchant.
- Redundansi/minimalisasi: logic label request masih minim dan hard-coded; jika action request bertambah, perlu mapping domain yang lebih jelas. Widget sudah domain-specific, tidak perlu dibuat generic.
- Service fee notice: tidak ada logic service fee.
- Risiko: perubahan format item/merchant bisa memengaruhi dua sisi UI tracking customer dan driver active order.

## frontend_bangdeliv/lib/core/widgets/bang_status_pill.dart

- Fungsi: pill status generic dengan warna foreground/background dan ikon opsional.
- Logic penting: label ellipsis dalam `Flexible`, bentuk pill penuh dengan radius 999.
- Redundansi/minimalisasi: tampak belum dipakai; overlap dengan `BangStatusChip` di `lib/widgets/bang_ui.dart` dan beberapa private `_StatusPill`. Kandidat hapus atau jadikan standar tunggal setelah audit status chips selesai.
- Service fee notice: tidak ada logic service fee.
- Risiko: risiko hapus rendah jika tetap tidak dipakai, tetapi standardisasi status pill bisa memengaruhi banyak tampilan status order.

## frontend_bangdeliv/lib/data/repositories/address_repository.dart

- Fungsi: kontrak repository untuk CRUD saved address dan validasi alamat, dengan implementasi `AuthAddressRepository` yang meneruskan semua call ke static method `AuthService`.
- Logic penting: tidak ada logic domain tambahan; semua parameter alamat, koordinat, dan `isDefault` hanya diforward ke `AuthService`.
- Redundansi/minimalisasi: `addressRepositoryProvider` hanya didefinisikan di DI dan screen alamat masih memanggil `AuthService` langsung, jadi repository ini kandidat unused/legacy. Refactor minimal: hapus repository/provider jika tetap tidak dipakai, atau konsisten migrasikan screen ke repository jika ingin testable DI.
- Service fee notice: tidak ada logic service fee.
- Risiko: menghapus repository aman jika tidak ada caller baru, tetapi menghilangkan titik abstraction yang mungkin berguna untuk test override alamat; migrasi screen ke repository punya churn lebih besar.

## frontend_bangdeliv/lib/data/repositories/chatbot_repository.dart

- Fungsi: kontrak repository chatbot untuk kirim pesan, ambil session/history, patch lokasi, patch beberapa lokasi, patch merchant, dan clear session.
- Logic penting: `ApiChatbotRepository` hanya pass-through ke `ChatbotApiService`; setiap operasi tetap membawa `serviceType`, dan patch merchant menerima `merchantId` atau `ShoppingMerchantPlacePayload`.
- Redundansi/minimalisasi: abstraksi repository masih dipakai oleh provider chatbot, tetapi implementasinya sangat tipis. Import `ShoppingMerchantPlacePayload` dari `customer_order_api_service.dart` membuat coupling lintas service; kandidat clean-up kecil adalah memindahkan payload ini ke model/DTO shared.
- Service fee notice: tidak ada logic service fee.
- Risiko: memindahkan DTO merchant bisa menyentuh chatbot, shopping add item, map picker, dan test; lakukan terpisah dari refactor service fee.

## frontend_bangdeliv/lib/data/repositories/customer_order_repository.dart

- Fungsi: kontrak repository customer order untuk list/detail/cancel order, upload bukti transfer, shopping item changes, respon quote harga, respon revisi ongkir, dan pencarian merchant/menu.
- Logic penting: `ApiCustomerOrderRepository` hanya meneruskan call ke `CustomerOrderApiService`; repository ini aktif dipakai tracking, orders, shopping screen, provider, dan widget tests.
- Redundansi/minimalisasi: interface cukup besar karena menggabungkan order umum, shopping item, quote, delivery fee negotiation, dan merchant search. Untuk refactor minimal sebaiknya dipertahankan dulu; pemecahan per domain bisa dilakukan belakangan jika audit service/screen menunjukkan batas yang jelas.
- Service fee notice: tidak ada logic service fee langsung. `respondDeliveryFeeOverride` adalah negosiasi ongkir, bukan service fee, jadi tidak masuk kandidat hapus.
- Risiko: banyak caller dan test override bergantung pada interface ini; split repository akan menghasilkan import churn dan update fake test yang cukup luas.

## frontend_bangdeliv/lib/data/repositories/driver_order_repository.dart

- Fungsi: kontrak repository driver untuk lifecycle order, pembayaran COD/transfer, revisi ongkir, lokasi driver, upload proof, flow shopping, gagal pickup merchant, availability, dan history.
- Logic penting: `ApiDriverOrderRepository` pass-through ke `DriverOrderService`; method `updateDeliveryFeeOverride` membawa `carefulCarryRequired`, sedangkan `recordShoppingPickupFailed` meneruskan flow gagal pickup merchant.
- Redundansi/minimalisasi: interface sangat besar tetapi masih mengikuti kebutuhan driver provider saat ini. Refactor minimal sebaiknya fokus dulu menghapus parameter `carefulCarryRequired` dari contract dan forwarding setelah backend/frontend service-fee cleanup siap, bukan memecah repository sekaligus.
- Service fee notice: `carefulCarryRequired` adalah kandidat hapus/refactor sesuai plan Courier "perlu 2 orang". `recordShoppingPickupFailed` harus dipertahankan karena terkait penalti 50% ongkir setelah 3 kali gagal pickup.
- Risiko: file ini adalah jalur utama driver order; perubahan signature harus sinkron dengan `DriverOrderService`, provider driver, UI revisi ongkir, model negotiation, dan test.

## frontend_bangdeliv/lib/data/repositories/realtime_order_client.dart

- Fungsi: abstraksi realtime order dan DTO `OrderStatusRealtimeEvent` untuk tracking customer, driver order broadcast, chat message, content update, status update, dan lokasi driver.
- Logic penting: interface memisahkan app dari implementasi Pusher; callback subscription menangani location, status, content, chat, subscribed, dan connection issue. Dipakai oleh `PusherService`, realtime hub, provider tracking/driver, serta fake realtime client di test.
- Redundansi/minimalisasi: file relatif bersih dan masih bernilai sebagai boundary realtime. Callback memang banyak, tetapi refactor typed event stream sebaiknya ditunda karena blast radius ke realtime hub dan tests tinggi.
- Service fee notice: tidak ada logic service fee.
- Risiko: perubahan interface dapat mematahkan PusherService, fake test client, chat provider tests, tracking, dan driver order realtime flow.

## frontend_bangdeliv/lib/domain/order_domain.dart

- Fungsi: pusat konstanta domain mentah untuk service type, service type chatbot, status order, metode pembayaran, tipe proof, dan action driver.
- Logic penting: `ServiceTypeCode.normalize()` menyamakan alias backend/user-facing seperti `ANTAR_JEMPUT`, `KURIR`, dan `NITIP`; `OrderStatusCode.normalize()` uppercase sederhana; `PaymentMethodCode.normalize()` fallback ke COD; `ChatbotServiceType.fromServiceCode()` fallback ke `nitip`.
- Redundansi/minimalisasi: app runtime lebih sering memakai wrapper plural di `lib/utils/service_type.dart` dan `lib/utils/order_status.dart`, sedangkan `ChatbotServiceType`, `PaymentMethodCode`, `ProofTypeCode`, dan `DriverActionCode` tampak hanya dipakai test/domain coverage. Kandidat minimalisasi nanti: jadikan file ini benar-benar sumber tunggal dan kurangi wrapper yang hanya re-export, atau hapus konstanta yang tidak pernah dipakai runtime setelah audit semua action/proof selesai.
- Service fee notice: `OrderStatusCode.cancelledWithFee` dan `DriverActionCode.cancelWithFee` terkait flow penalti 50% setelah gagal pickup, jadi harus dipertahankan. Tidak ada `service_fee_rules`, `order_fee_lines`, `fee_breakdown`, atau `careful_carry_required` di file ini.
- Risiko: konstanta status/service type berdampak luas ke parsing model, tracking, driver workflow, test, dan kompatibilitas payload backend; jangan menghapus `COURIER` karena plan hanya menghapus fitur "perlu 2 orang", bukan service type Courier.

## frontend_bangdeliv/lib/features/addresses/application/address_form_presenter.dart

- Fungsi: helper murni untuk form alamat, berisi normalisasi label alamat dan validasi pasangan koordinat.
- Logic penting: label kosong/rumah/home dinormalisasi ke `Rumah`, kantor/office ke `Kantor`, selain itu `Lainnya`; koordinat valid jika lat/lng tidak null, masih dalam range bumi, dan bukan titik `0,0`.
- Redundansi/minimalisasi: file kecil dan aktif dipakai `add_address_screen` serta punya test khusus. Nama `Presenter` agak lebih besar dari isinya karena belum mengelola state/form flow; refactor minimal cukup biarkan dulu, atau rename ke util/helper hanya jika nanti address layer sedang dirapikan sekalian.
- Service fee notice: tidak ada logic service fee.
- Risiko: perubahan normalisasi label atau validasi koordinat langsung memengaruhi edit alamat lama, enable/disable submit, dan penyimpanan alamat dengan pin lokasi; test `address_form_presenter_test.dart` perlu ikut disesuaikan jika rule berubah.

## frontend_bangdeliv/lib/features/addresses/presentation/screens/add_address_screen.dart

- Fungsi: screen create/edit alamat tersimpan, termasuk prefill profil user, pilihan label alamat, pilihan wilayah layanan, input detail alamat, picker titik peta, toggle alamat utama, simpan/update, dan hapus alamat.
- Logic penting: `_coverageData` hard-coded untuk `Jawa Tengah`/Salatiga/Kabupaten Semarang; edit mode mengisi form dari `SavedAddressModel`, mencoba hydrate wilayah dari `fullAddress`, lalu ekstrak detail manual; save memvalidasi form, coverage, dan koordinat, compose full address, memanggil `AuthService.createSavedAddress`/`updateSavedAddress`, refresh `authSessionProvider`, lalu `pop(true)`; picker lokasi dibuka via `AppRoutes.addressLocationPicker` dengan `restrictAddressSearchToServiceArea: true`.
- Redundansi/minimalisasi: file terlalu besar karena data coverage, parsing alamat, state submit/delete, dan UI field berada di satu screen. Kandidat minimal yang aman: hapus `_detailController` jika tetap hanya `clear()`/`dispose()`, pindahkan `_coverageData` ke data/helper terpisah, dan pindahkan `_hydrateCoverageSelection`, `_composeFullAddress`, `_extractManualDetailFromFullAddress` ke presenter/helper agar bisa dites. Screen juga masih memanggil `AuthService` langsung walau ada `AddressRepository`; pilih salah satu pola nanti agar DI tidak setengah jalan. `normalizedFullAddress = rawFullAddress` dan hasil `validateSavedAddress()` yang tidak dipakai terlihat redundant.
- Service fee notice: tidak ada logic service fee.
- Risiko: refactor file ini rawan memutus edit alamat lama, pemilihan area layanan, validasi titik peta, refresh session setelah CRUD alamat, dan return value route (`true/false`) yang dipakai screen sebelumnya; ekstraksi data/helper sebaiknya dilakukan kecil-kecil dengan test presenter.

## frontend_bangdeliv/lib/features/addresses/presentation/screens/address_location_picker_screen.dart

- Fungsi: screen picker titik alamat tersimpan berbasis Google Maps, pencarian tempat, reverse geocode alamat, dan GPS "Lokasi Saya".
- Logic penting: initial camera memakai koordinat dari route extra jika valid, fallback ke Salatiga; tanpa koordinat awal screen otomatis mencoba `_moveToCurrentLocation()` setelah frame pertama; search memakai `GoogleMapsLookupService` dengan scope Indonesia atau `salatigaServiceAreaAddress`; `onCameraIdle` reverse geocode target; confirm hanya mengembalikan `AddressLocationPickerResult(latitude, longitude, source)`.
- Redundansi/minimalisasi: banyak pola berulang dengan `route_location_picker_screen.dart` dan sebagian shopping merchant map picker: `GoogleMapController`, `GoogleMapsLookupService`, flow permission Geolocator, search place, animate camera, dan snackbar error. Kandidat minimal: ekstrak helper permission/current-location atau map lookup wrapper dulu, bukan full reusable screen. `_handleCameraIdle()` punya `setState(() {})` saat `_isResolvingCurrentLocation` yang tampak redundant; `_mapsLookup` dibuat langsung sehingga screen sulit di-test dengan fake service.
- Service fee notice: tidak ada logic service fee.
- Risiko: refactor map picker rawan memengaruhi permission GPS, initial coordinate edit alamat, search scope area layanan, dan return result ke `add_address_screen`/chatbot; karena tidak ada test screen khusus untuk file ini, ekstraksi sebaiknya disertai test helper/service atau widget test dengan fake Google Maps platform.

## frontend_bangdeliv/lib/features/addresses/presentation/screens/route_location_picker_screen.dart

- Fungsi: screen picker rute untuk chatbot Ride/Courier, memilih titik pickup dan destination/dropoff lewat Google Maps, search place, GPS, reverse geocode, ringkasan dua titik, serta validasi jarak minimum.
- Logic penting: menerima `RouteLocationPickerArgs` dari `AppRoutes.routeLocationPicker`; default pickup dari alamat utama bisa langsung mengisi pickup lalu memulai flow destination; map bisa disembunyikan saat destination belum masuk mode pilih peta; `_confirmRoute()` hanya mengirim pickup jika berubah atau tidak ada default pickup, selalu mengirim destination, dan menolak pickup/destination yang jaraknya kurang dari 20 meter.
- Redundansi/minimalisasi: pola map/search/GPS banyak overlap dengan `address_location_picker_screen.dart`: `GoogleMapController`, `GoogleMapsLookupService`, permission Geolocator, reverse geocode, snackbar error, dan `setState(() {})` saat idle/loading. `RouteLocationPickerArgs.serviceType` dan `confirmLabel` tampak tidak dipakai di screen, karena tombol tetap hard-coded `Simpan`. `_mapsLookup` dibuat langsung sehingga sulit diinjeksi fake service; `_validLatLng()` mirip `AddressFormPresenter.isCoordinatePairValid()`, dan `_distanceMeters()` bisa dipertimbangkan memakai helper shared.
- Service fee notice: tidak ada logic service fee; file ini menyebut Courier sebagai service route, tetapi tidak terkait fitur "perlu 2 orang".
- Risiko: refactor screen ini rawan memutus flow chatbot yang mem-patch lokasi pickup/dropoff/destination, default saved address, behavior map hidden/visible, validasi jarak minimum, dan test `route_location_picker_screen_test.dart`; ekstraksi sebaiknya dimulai dari helper kecil non-UI dulu.

## frontend_bangdeliv/lib/features/addresses/presentation/screens/saved_addresses_screen.dart

- Fungsi: screen daftar alamat tersimpan, dengan mode normal untuk tambah/edit alamat dan `selectionMode` untuk memilih alamat utama.
- Logic penting: data diambil lewat `AuthService.fetchSavedAddresses()` ke `FutureBuilder`; tambah/edit membuka `AppRoutes.addAddress` dan reload jika route mengembalikan `true`; `selectionMode` memilih alamat dengan menjadikan alamat tersebut default via `AuthService.updateSavedAddress()`, refresh `authSessionProvider`, lalu `pop(true)`.
- Redundansi/minimalisasi: sama seperti `add_address_screen`, screen ini masih memanggil `AuthService` langsung walau ada `AddressRepository/addressRepositoryProvider`, sehingga abstraction alamat belum konsisten. Extra edit address dibangun manual sebagai `Map`, padahal router lalu parse lagi ke `SavedAddressModel`; bisa dibuat helper serialization kecil jika pola ini muncul lagi. UI card/list cukup sederhana, tetapi card style dan loading/error/empty state bisa nanti diseragamkan dengan komponen Bang UI jika audit UI selesai.
- Service fee notice: tidak ada logic service fee.
- Risiko: perubahan fetch/reload/default selection bisa memengaruhi profile/session address default, flow chatbot route picker yang butuh alamat utama, dan navigation result `true` untuk refresh list; migrasi ke repository perlu tetap menjaga `authSessionProvider.refreshSession()`.

## frontend_bangdeliv/lib/features/auth/application/auth_session_provider.dart

- Fungsi: pusat state sesi auth Riverpod, termasuk initialized/authenticated, role user, status akses driver, profil aktif, refresh session, sync profile, dan logout.
- Logic penting: `initialize()` mencegah init ganda dengan `_isInitializing`; `refreshSession()` cek token lokal, fetch profile, map role/status driver, lalu clear local session dan sync token FCM jika token/profile invalid; `logout()` unregister FCM token lewat `deviceTokenApiServiceProvider`, panggil `AuthService.logout()`, clear sync flag, dan set guest.
- Redundansi/minimalisasi: blok `on AuthException` dan `catch (_)` di `refreshSession()` identik, bisa disatukan ke helper kecil seperti `_clearSessionToGuest()` agar clean. `handleLoginSuccess()` hanya wrapper `refreshSession()` tetapi masih boleh dipertahankan untuk intent. `AuthSessionState` belum punya `copyWith`/equality, namun karena state biasanya diganti utuh ini belum prioritas. Banyak test membuat `_FakeAuthSessionNotifier` sendiri berulang, kandidat helper test shared belakangan.
- Service fee notice: tidak ada logic service fee.
- Risiko: file ini punya blast radius besar ke router redirect, realtime bootstrap, FCM token sync, driver/customer provider, chatbot, profile, dan banyak test override; refactor harus sangat kecil dan diverifikasi dengan test provider/router terkait.

## frontend_bangdeliv/lib/features/auth/presentation/screens/forgot_password_screen.dart

- Fungsi: screen UI lupa password dengan input email/WhatsApp, header ikon reset, dan tombol kirim instruksi pemulihan.
- Logic penting: belum ada `Form`, validator, loading state, atau call API; tombol hanya menampilkan snackbar sukses simulasi lalu `context.pop()` setelah 2 detik.
- Redundansi/minimalisasi: layout dan style mirip pola auth/login tetapi belum memakai reusable auth scaffold/form field. Karena fitur belum tersambung backend, kandidat minimal adalah tandai sebagai placeholder atau hubungkan ke endpoint nyata sebelum dirapikan visualnya. `TextField` email/WhatsApp juga belum memvalidasi input.
- Service fee notice: tidak ada logic service fee.
- Risiko: jika user menganggap fitur ini aktif, simulasi sukses bisa menyesatkan; refactor UI harus menunggu keputusan apakah backend reset password memang akan dibuat atau route ini di-hide sementara.

## frontend_bangdeliv/lib/features/auth/presentation/screens/login_screen.dart

- Fungsi: screen login customer/driver/admin berbasis email-password, prefill last login email, validasi form, toggle password visibility, navigasi register/forgot password, dan redirect setelah login.
- Logic penting: `initState()` mengambil last email dari `AuthService.getLastLoginEmail()`; `_handleLogin()` validasi form, panggil `AuthService.loginWithEmail()`, refresh `authSessionProvider`, lalu redirect ke `returnTo` jika aman atau `AppRoutes.splash`; `PopScope` mengarahkan back tanpa stack ke home.
- Redundansi/minimalisasi: `_isValidEmail()` sudah terkonfirmasi duplikat dengan `register_screen.dart`, jadi kandidat validator shared kecil. Layout auth header/card juga mirip register dan forgot password, sehingga bisa dishare bertahap setelah flow auth stabil. Komentar `Top Header`/`Bottom Card` hanya menjelaskan struktur yang sudah jelas; bisa dihapus saat cleanup kecil. `returnTo` filtering masih lokal di screen, berpotensi lebih rapi jika jadi helper router/auth.
- Service fee notice: tidak ada logic service fee.
- Risiko: perubahan login berdampak ke auth session, router redirect, saved last email, dan semua flow protected route; harus verifikasi login success, invalid credential snackbar, `returnTo`, dan back behavior.

## frontend_bangdeliv/lib/features/auth/presentation/screens/register_driver_screen.dart

- Fungsi: screen upgrade akun customer menjadi driver, menampilkan data profil readonly, input jenis/merk/tipe motor, plat kendaraan terpisah, nomor SIM, submit upgrade, refresh session, lalu navigasi ke status verifikasi driver.
- Logic penting: memakai `authSessionProvider` untuk nama/email, `VehicleInfoFields` untuk data kendaraan, validator manual untuk plat dan SIM, `AuthService.upgradeToDriver()` ke `/user/upgrade-to-driver`, lalu `authSessionProvider.refreshSession()` dan `context.go(AppRoutes.driverVerificationStatus)`.
- Redundansi/minimalisasi: `_UpperCaseTextFormatter` duplikat dengan formatter internal di `VehicleInfoFields`; bisa dipindah ke util formatter shared jika dipakai lagi. `_inputDecoration`, `_plainInputDecoration`, label field, card style, dan submit loading cukup mirip pola form lain sehingga kandidat konsolidasi kecil setelah audit auth/profile form selesai. Plat kendaraan punya 3 controller/focus node dan validasi custom; jangan digabung sembarangan, tetapi helper `vehiclePlate` bisa diekstrak agar lebih testable.
- Service fee notice: tidak ada logic service fee.
- Risiko: perubahan screen ini berdampak pada alur upgrade driver, status verifikasi, data kendaraan di profil/order, dan redirect router untuk driver pending/rejected; perlu cek endpoint backend dan test manual submit valid/invalid sebelum refactor lebih jauh.

## frontend_bangdeliv/lib/features/auth/presentation/screens/register_screen.dart

- Fungsi: screen registrasi customer baru dengan input nama, nomor WhatsApp, email, password, loading submit, dan navigasi ke halaman sukses register.
- Logic penting: form memakai validator lokal untuk nama/phone/email/password; `_handleRegister()` mencegah double submit, memanggil `AuthService.registerCustomer()`, lalu `context.go(AppRoutes.registerSuccess)` jika berhasil dan menampilkan snackbar dari `AuthException` jika gagal.
- Redundansi/minimalisasi: `_isValidEmail()` sama dengan `login_screen.dart`; `_isValidPhone()` bisa menjadi validator shared jika dipakai profile/checkout. Layout header orange + card putih, style field, password visibility, divider `atau`, dan copy login mirip `login_screen.dart`, sehingga kandidat auth scaffold/form helper kecil. Komentar `Top Header`/`Bottom Card` bisa dihapus saat cleanup.
- Service fee notice: tidak ada logic service fee.
- Risiko: perubahan file ini berdampak ke flow daftar akun, format phone/email yang diterima frontend, handling error backend, dan route `registerSuccess`; perlu test manual validasi form, submit sukses, dan submit gagal.

## frontend_bangdeliv/lib/features/auth/presentation/screens/register_success_screen.dart

- Fungsi: halaman konfirmasi sederhana setelah registrasi customer berhasil, menampilkan ikon centang, pesan sukses, dan tombol kembali login.
- Logic penting: tombol utama selalu `context.go(AppRoutes.login)` sehingga stack register dibersihkan dan user masuk dari login.
- Redundansi/minimalisasi: file kecil dan clean. UI success state bisa nanti digabung menjadi reusable success screen/dialog jika ada banyak halaman sukses serupa, tetapi untuk refactor minimal belum prioritas.
- Service fee notice: tidak ada logic service fee.
- Risiko: mengubah route tombol bisa memengaruhi flow setelah register; tetap pertahankan `go` ke login kecuali flow auto-login memang diubah.

## frontend_bangdeliv/lib/features/auth/presentation/screens/splash_screen.dart

- Fungsi: screen loading auth/router awal dengan background primary dan spinner putih.
- Logic penting: tidak ada logic init di widget; keputusan auth/redirect ada di `app_router.dart` dan `auth_session_provider.dart`.
- Redundansi/minimalisasi: file sangat minimal dan tidak perlu refactor. Bisa tetap dipisah agar router punya placeholder visual yang jelas.
- Service fee notice: tidak ada logic service fee.
- Risiko: rendah; perubahan visual splash hanya perlu pastikan redirect router tetap berjalan dan spinner kontras.

## frontend_bangdeliv/lib/features/chatbot/application/chatbot_conversation_provider.dart

- Fungsi: pusat state conversation chatbot Riverpod untuk bootstrap session, pilih/refresh session, kirim pesan, apply action dari map/route/merchant picker, restart/clear session setelah order dibuat, guard alamat tersimpan, dan mapping response backend menjadi action button UI.
- Logic penting: session disimpan per `serviceType` di `SharedPreferences`; `sendMessage()` menambah pesan user lalu append hasil backend; `applyMapPinAction()`, `applyRoutePickerAction()`, dan `applyMerchantPickerAction()` patch draft chatbot; `_resolveActionHints*()` menerjemahkan `next_actions`/`action_payloads` backend menjadi `ChatbotMessageActionHint`; `pendingClearAfterOrderCreated` mencegah chat lanjut sebelum session selesai dibersihkan.
- Redundansi/minimalisasi: file sangat besar karena mencampur state management, storage session, mapping contract backend, copy UI/action label, dan address guard. Tiga method apply action punya pola guard/persist canonical session/append result/refresh/error yang mirip dan bisa diekstrak kecil. String action seperti `OPEN_ROUTE_PICKER`, `OPEN_MAP_PICKER_*`, `SET_PAYMENT_*`, `CONFIRM_DRAFT` dan service type `antar_jemput`/`kurir`/`nitip` berulang; kandidat minimal adalah konstanta private dulu sebelum split besar. `ShoppingMerchantPlacePayload` masih diambil dari `customer_order_api_service.dart`, selaras dengan catatan coupling di repository chatbot.
- Service fee notice: tidak ada logic service fee/careful carry. Payment action `SET_PAYMENT_COD`/`SET_PAYMENT_TRANSFER` adalah pilihan metode bayar draft Nitip, bukan service fee; jangan dihapus karena tidak termasuk plan service fee.
- Risiko: blast radius tinggi ke `chatbot_screen.dart`, `chatbot_model.dart`, `chatbot_api_service.dart`, route picker/merchant picker, persisted session key, dan banyak test `chatbot_screen_test.dart`; audit `chatbot_screen.dart` mengonfirmasi action hint provider adalah kontrak UI utama, jadi refactor provider harus per helper kecil dengan test existing.

## frontend_bangdeliv/lib/features/chatbot/presentation/screens/chatbot_screen.dart

- Fungsi: screen utama BangBot untuk layanan Nitip, Kurir, dan Antar Jemput; mengelola UI chat, input/suggestion, menu riwayat/restart, guard alamat, navigasi map/merchant/route picker, tracking/activity setelah order dibuat, serta rendering khusus pesan draft dari backend.
- Logic penting: `_serviceContext` menentukan copy/icon/suggestion per `service_type`; `_bootstrapConversation()` bootstrap provider dan auto-open alamat jika profil belum siap; `_sendMessage()` juga menangkap command `refresh`; `build()` merender AppBar, error banner, bubble chat, action buttons, dan input bar; parser `_tryParse*Message()` mengubah teks assistant menjadi layout draft yang lebih terstruktur; handler `_handleOpen*Action()` menjembatani action hint provider ke route picker, merchant picker, address picker, tracking, dan activity.
- Redundansi/minimalisasi: file 2267 line dan terlalu banyak tanggung jawab untuk satu screen: service config/copy, widget chat bubble, parser teks, session sheet/dialog, navigation action handler, dan address guard. Kandidat minimal paling aman adalah ekstrak parser pesan ke helper pure Dart terlebih dulu karena banyak perilaku sudah dilindungi `chatbot_screen_test.dart`, lalu ekstrak `_ServiceContext` ke config kecil dan pecah widget bubble/input/session sheet. Banyak `TextStyle`, border, padding, dan button style inline bisa nanti diserap ke komponen chat kecil setelah parser aman.
- Service fee notice: tidak ada `service_fee`, `fee_breakdown`, `careful_carry_required`, atau "Perlu 2 orang". Nama lokal `feeLine/feeLines` hanya parsing teks "Estimasi ongkir sementara" dan "Estimasi total sementara" dari balasan chatbot, bukan `service_fee_lines`/`order_fee_lines`; jangan dihapus karena tidak termasuk plan service fee, tetapi bisa diganti nama menjadi `amountLines`/`estimateLines` agar tidak rancu saat cleanup.
- Risiko: blast radius sangat tinggi ke flow chat semua service, auto-open alamat, persisted session, parser format teks backend, route picker, merchant picker, tracking/activity cleanup, dan banyak test `chatbot_screen_test.dart`/`chatbot_courier_prompt_style_test.dart`; refactor harus bertahap dan idealnya mulai dari ekstraksi pure parser dengan test yang sudah ada.

## frontend_bangdeliv/lib/features/driver_orders/application/driver_availability_location_reporter_provider.dart

- Fungsi: provider Riverpod untuk mengirim lokasi driver saat driver sedang `available`, tanpa terikat order aktif.
- Logic penting: provider aktif dari `main.dart`; `build()` membaca auth session dan `driverAvailabilityProvider`, lalu `_syncReporting()` start/stop reporter; `_startReporting()` cek service lokasi, permission, ambil posisi awal, listen stream posisi, dan kirim periodik tiap `driverAvailabilityLocationReportInterval`; `_sendLatestPosition()` memanggil `updateCurrentDriverLocation()`.
- Redundansi/minimalisasi: pola permission, stream subscription, timer, `_sendInFlight`, snapshot, error, dan dispose sangat mirip dengan `driver_location_reporter_provider.dart`. Kandidat minimal setelah audit lengkap adalah ekstrak helper/lifecycle lokasi shared atau base utility kecil, tetapi jangan digabung dulu sebelum test availability reporter dibuat. Interval global mutable membantu test, tetapi bisa dirapikan menjadi provider/config jika makin banyak reporter.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: refactor bisa memutus update lokasi driver available yang dipakai dispatch/order matching backend; karena file ini dipicu global di `main.dart` dan belum terlihat punya test khusus, perubahan harus disertai test provider untuk available/unavailable, permission denied, dan stop reporting.

## frontend_bangdeliv/lib/features/driver_orders/application/driver_dispatch_presenter.dart

- Fungsi: presenter kecil untuk mengubah `DriverDispatchModel?` menjadi data UI jarak dispatch (`label`, `bucket`, `priorityRank`).
- Logic penting: null dispatch atau label kosong fallback ke `Jarak belum tersedia`; `distanceBucket` dinormalisasi uppercase dan fallback `UNKNOWN`; dipakai oleh `DriverDistanceBadge`.
- Redundansi/minimalisasi: file sudah minimal dan punya test model/presenter. Belum perlu refactor; cukup pertahankan sebagai boundary UI agar widget tidak tahu detail fallback model.
- Service fee notice: tidak ada logic service fee.
- Risiko: rendah, tetapi perubahan label/bucket memengaruhi badge jarak di daftar order driver dan test `driver_order_dispatch_model_test.dart`/`driver_distance_badge_test.dart`.

## frontend_bangdeliv/lib/features/driver_orders/application/driver_location_reporter_provider.dart

- Fungsi: provider Riverpod untuk tracking lokasi driver saat ada running order yang statusnya boleh dilacak customer.
- Logic penting: `driverLocationSourceProvider` membungkus Geolocator agar bisa di-test; `build()` membaca auth session dan `driverOrdersProvider`, memilih order pertama di `orders.running` yang lolos `isDriverLocationTrackable()`; `_startTracking()` cek service/permission, ambil posisi awal, subscribe stream, lalu kirim periodik tiap `driverLocationReportInterval` ke `updateDriverLocation(orderId, lat, lng, updatedAt)`.
- Redundansi/minimalisasi: lifecycle tracking lokasi sangat mirip dengan availability reporter, hanya target dan endpoint yang beda. Kandidat minimal adalah ekstrak helper permission/position stream/timer atau service `DriverLocationReportingLoop`, tapi pertahankan `DriverLocationSource` karena test sudah memanfaatkannya. `driverLocationReportInterval` global mutable dipakai test; nanti bisa diganti provider override jika ingin lebih clean.
- Service fee notice: tidak ada logic service fee/careful carry; status `CANCELLED_WITH_FEE` hanya muncul di test provider lain sebagai status terminal yang tetap dipertahankan sesuai plan penalti 50%, bukan logic file ini.
- Risiko: blast radius ke tracking customer, map active order driver, realtime/order provider, dan test `driver_order_providers_test.dart`; refactor harus memastikan tracking berhenti saat order tidak trackable, tidak mengirim saat permission/service gagal, dan tetap mengirim posisi terbaru dari stream.

## frontend_bangdeliv/lib/features/driver_orders/application/driver_order_providers.dart

- Fungsi: pusat provider application driver order untuk incoming/running orders, action processing key, accept/reject/transition, pembayaran COD/transfer, revisi ongkir, proof upload, flow Shopping/Nitip, realtime driver orders, realtime running order, availability, detail reconciliation, history, active order, dan incoming count.
- Logic penting: `DriverOrdersNotifier` fetch order awal, subscribe realtime incoming order driver, reconcile incoming tiap `driverOrdersReconciliationInterval`, retain/release realtime hub untuk running order, optimistic accept/reject, mutasi running order via `_mutateRunningOrder()`, invalidasi detail/availability/history, dan menjaga `suppressedIncomingOrderIds` agar order yang sudah ditolak tidak muncul ulang. `DriverAvailabilityNotifier` sinkron status kerja driver, `driverOrderDetailRealtimeProvider` invalidasi detail dari event realtime, dan `driverOrderTransferProofReconciliationProvider` polling detail sampai proof transfer muncul.
- Redundansi/minimalisasi: file sangat besar dan mencampur beberapa boundary: order list notifier, action key builder, availability notifier, detail realtime provider, proof reconciliation, history notifier, dan derived provider. Kandidat minimal: pecah per provider/domain kecil setelah test coverage tetap hijau; ekstrak helper mutasi running order yang sudah mirip dengan beberapa method manual (`collectCod`, `updateShoppingItems`, `recordShoppingPickupFailed`); pertimbangkan provider/config untuk interval global mutable; nama `confirmQris` kurang presisi karena method-nya `confirmTransferPayment`.
- Service fee notice: `updateDeliveryFeeOverride()` masih membawa `carefulCarryRequired`, ini kandidat hapus/refactor sesuai plan Courier "perlu 2 orang". `recordShoppingPickupFailed()` dan action key `pickupFailed` harus dipertahankan karena terkait flow gagal pickup merchant yang dapat memicu penalti 50% setelah 3 kali gagal; jangan ikut dihapus saat cleanup service fee.
- Risiko: blast radius sangat tinggi ke `driver_active_order_screen.dart`, `driver_orders_screen.dart`, availability reporter, location reporter, realtime hub, driver history, active order map, transfer proof reconciliation, dan banyak test `driver_order_providers_test.dart`; refactor harus kecil-kecil dan selalu jaga optimistic update, rollback error, realtime retry/degraded polling, suppressed rejected orders, dan invalidasi detail/history/availability.

## frontend_bangdeliv/lib/features/driver_orders/application/driver_realtime_bootstrap_provider.dart

- Fungsi: provider bootstrap kecil untuk driver aktif agar unread chat running order ikut di-retain dan dipantau lewat `orderChatUnreadCountProvider`.
- Logic penting: aktif hanya jika session authenticated, role driver, driver access active, dan profile ada; membaca `driverOrdersProvider`, mengambil running order id yang bukan sedang processing, lalu `ref.watch(orderChatUnreadCountProvider(orderId))` untuk tiap order.
- Redundansi/minimalisasi: file sudah kecil dan cukup jelas. Logic parsing order id dari running order mirip dengan `_syncRunningOrderRealtime()` di `driver_order_providers.dart`; bisa diekstrak nanti jika muncul helper shared untuk running order id aktif, tetapi belum prioritas.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini bisa memengaruhi badge unread chat driver dan retention realtime order di `app_realtime_bootstrap_provider`; tetap jaga filter active driver dan skip order yang sedang processing.

## frontend_bangdeliv/lib/features/driver_orders/presentation/screens/driver_active_order_screen.dart

- Fungsi: screen detail order aktif driver yang merangkai map, metadata, checklist proof, revisi ongkir manual, item Shopping/Nitip, pembayaran transfer, timeline, tombol aksi status, chat unread, realtime detail, reconciliation proof, dan lokasi driver terbaru.
- Logic penting: validasi `orderId` harus numeric; watch `driverOrderDetailProvider`, `driverOrderDetailRealtimeProvider`, `driverOrderTransferProofReconciliationProvider`, `driverOrdersProvider`, `driverLocationReporterProvider`, dan `orderChatUnreadCountProvider`; semua aksi driver diteruskan ke `driverOrdersProvider.notifier`, lalu detail order di-invalidate jika sukses.
- Redundansi/minimalisasi: file ini mostly orchestration tetapi callback-nya panjang dan berulang pola `call notifier -> if error null invalidate detail -> snackbar`. Kandidat minimal adalah helper kecil untuk invalidate-on-success/snackbar atau action binder per section setelah widget detail driver lain diaudit. Banyak logic UI sudah dipisah ke widget `driver_active_order_*`, jadi jangan digabung ulang ke screen.
- Service fee notice: `DriverManualDeliveryFeeCard.onSave` masih menerima dan meneruskan `carefulCarryRequired` ke `updateDeliveryFeeOverride()`, ini kandidat hapus/refactor sesuai plan Courier "perlu 2 orang". `recordShoppingPickupFailed()` di Shopping dan action card harus dipertahankan karena terkait flow gagal pickup merchant/penalti 50% setelah 3 kali gagal.
- Risiko: blast radius tinggi ke order aktif driver, chat badge, map tracking, upload proof, revisi ongkir, shopping checkout/quote, QRIS confirmation, status transition, dan test widget driver; refactor harus jaga disable/loading action keys dan invalidasi detail setelah aksi sukses.

## frontend_bangdeliv/lib/features/driver_orders/presentation/screens/driver_history_screen.dart

- Fungsi: screen riwayat driver dengan filter Semua/Hari Ini/Minggu Ini, summary order selesai, summary pendapatan, list riwayat, pull-to-refresh, dan navigasi ke detail history.
- Logic penting: data dari `driverHistoryProvider`; default filter `Minggu Ini`; summary hanya menghitung order dengan status literal `Selesai`; item bisa dibuka jika `order.orderId` ada; filter tanggal memakai `wibNow()`, `toWib()`, dan `formatDateTime()`.
- Redundansi/minimalisasi: status selesai masih hard-coded string `Selesai`, lebih aman nanti jika memakai kode status/model helper ketika model history diaudit. UI summary card dan history card private cukup bersih, tetapi pola summary pendapatan/order selesai mungkin overlap dengan driver home. Filter minggu memakai logic lokal; bisa diekstrak/test jika dipakai ulang.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan filter/status dapat mengubah angka pendapatan dan jumlah order selesai; perlu jaga navigasi `driverHistoryDetailPath()` untuk payload lama yang masih punya `orderId`.

## frontend_bangdeliv/lib/features/driver_orders/presentation/screens/driver_home_screen.dart

- Fungsi: beranda driver untuk status kerja, ringkasan total pendapatan/order selesai, dan kartu order aktif.
- Logic penting: membaca `driverActiveOrderProvider`, `authSessionProvider`, dan `driverAvailabilityProvider`; toggle online/offline memanggil `setOnline()` dan menampilkan snackbar sukses/gagal; order aktif hanya dibuka jika `activeOrder.id` numeric, jika tidak diarahkan ke daftar order driver.
- Redundansi/minimalisasi: `_isServerOrderId()` duplikat dengan beberapa screen detail order. Pola card, shadow, radius, dan summary pendapatan/order selesai overlap dengan driver history; kandidat refactor kecil hanya jika pola ini terus berulang setelah audit widget driver selesai. `_availabilityLabel()` dan `_availabilityColor()` bisa dipindah ke presenter availability bila status kerja dipakai di screen lain.
- Service fee notice: tidak ada logic service fee/careful carry. `totalPaid` hanya agregat pendapatan driver dari profil, bukan `service_fee_lines`.
- Risiko: perubahan file ini berdampak ke toggle availability, label status online/busy/offline, navigasi order aktif, dan fallback ID order invalid; perlu cek manual status kerja dan buka detail order aktif.

## frontend_bangdeliv/lib/features/driver_orders/presentation/screens/driver_order_history_detail_screen.dart

- Fungsi: detail riwayat pesanan driver dengan app bar chat, state loading/error/retry, pull-to-refresh, meta order, item belanja, bukti foto, preview foto, dan timeline status.
- Logic penting: validasi `orderId` harus numeric; data detail dari `driverOrderDetailProvider(orderId)`; badge chat dari `orderChatUnreadCountProvider`; back button/PopScope selalu kembali ke `AppRoutes.driverHistory`; error 404 diterjemahkan menjadi pesan riwayat tidak ditemukan.
- Redundansi/minimalisasi: `_isServerOrderId()` duplikat dengan home/active detail. `_HistoryCardShell` mengulang pola card AppColors/shadow yang juga muncul di screen driver lain; bisa disatukan nanti jika widget history/active sudah selesai diaudit. `DriverOrderMetaCard` dan `DriverOrderTimelineCard` dipakai ulang dari widget active order, jadi refactor detail history perlu mengikuti kontrak widget tersebut.
- Service fee notice: tidak ada logic service fee/careful carry langsung di file ini. Audit `driver_active_order_meta_widgets.dart` mengonfirmasi tampilan nominal didelegasikan ke `DriverOrderMetaCard`, dan di widget itu ada kandidat hapus/refactor untuk `carefulCarryRequired`, teks "perlu 2 orang", serta `feeBreakdown`/`ShoppingFeeBreakdown` yang terkait service fee lines.
- Risiko: perubahan file ini memengaruhi akses detail riwayat, unread chat badge, preview bukti foto, dan refresh detail; jaga agar order lama dengan foto kosong tetap tidak merender thumbnail rusak.

## frontend_bangdeliv/lib/features/driver_orders/presentation/screens/driver_orders_screen.dart

- Fungsi: screen daftar order driver dengan tab order masuk dan order berjalan, accept/reject order, refresh, empty state berdasarkan status kerja, kartu order, badge jarak dispatch, detail barang Kurir, rute pickup/dropoff, fee driver, dan navigasi ke detail order aktif.
- Logic penting: `TabController` auto pindah ke tab berjalan jika ada running order; `driverOrdersProvider` menjadi sumber incoming/running dan action processing key; `driverAvailabilityProvider` menentukan apakah driver bisa menerima order; accept sukses langsung `go` ke `driverOrderActivePath()` jika id numeric, sedangkan reject memakai snackbar dan optimistic state dari provider.
- Redundansi/minimalisasi: file 1146 baris dan sudah menjadi campuran screen orchestration + banyak widget private. `_isServerOrderId()` duplikat dengan screen driver lain; `_OrderCard`, `_OrderRouteSection`, `_EmptyOrderState`, `_StatusPill`, dan `_CustomerAvatar` kandidat diekstrak ke widget driver kecil setelah audit widget driver selesai. Logic status availability `available/online/busy/offline` overlap dengan `driver_home_screen.dart`; bisa dibuat presenter/helper shared. Style button/card banyak inline walau tetap pakai `AppColors`.
- Service fee notice: tidak ada `service_fee`, `fee_lines`, atau `carefulCarry`. Label `Fee driver` dan `formatCurrency(order.fee)` adalah tampilan pendapatan/fee driver dari order, bukan `service_fee_lines`; jangan ikut dihapus dalam refactor service fee.
- Risiko: blast radius tinggi ke penerimaan/penolakan order, navigasi order aktif, realtime incoming/running provider, badge dispatch, flow driver availability, dan test `driver_orders_accept_navigation_test.dart`; refactor harus menjaga disabled/loading state per action key agar accept/reject tidak double submit.

## frontend_bangdeliv/lib/features/driver_orders/presentation/widgets/driver_active_order_action_widgets.dart

- Fungsi: kumpulan widget aksi/status order aktif driver: timeline status, card aksi driver, pesan COD, tombol status/action, dialog laporan merchant tutup/gagal pickup dengan upload foto.
- Logic penting: `DriverOrderTimelineCard` hanya menampilkan item timeline `STATUS_CHANGE`; `DriverOrderActionCard` membaca `availableActions`, status `CANCELLED_WITH_FEE`, status pembayaran, service type Kurir, dan `shoppingPricing`; dialog `_FailedPickupDialog` memilih merchant aktif, alasan, serta foto wajib sebelum mengirim report gagal pickup.
- Redundansi/minimalisasi: nama file action widgets juga berisi timeline card, jadi kandidat dipisah menjadi `timeline` dan `action` jika refactor widget driver dilakukan. Dialog gagal pickup dan dialog edit ongkir di fee widget punya pola modal/`AnimatedPadding`/`driverDialogInputDecoration` mirip; bisa dibuat helper setelah audit semua dialog driver selesai. `_canReportPickupFailed()` saat ini selalu `false`, sehingga UI report gagal pickup tidak pernah tampil walau callback tersedia dari screen.
- Service fee notice: `CANCELLED_WITH_FEE`, pesan biaya pembatalan, `failedAttemptCount/failedAttemptThreshold`, dan dialog gagal pickup harus dipertahankan karena ini terkait pengecualian plan: fee pembatalan 50% setelah 3 kali gagal bayar/pickup. Jangan ikut dihapus saat cleanup service fee.
- Risiko: perubahan file ini berdampak ke tombol status driver, blocked action, alur COD Kurir/Nitip, pembayaran biaya pembatalan, dan report merchant tutup. Risiko khusus: karena `_canReportPickupFailed()` hardcoded `false`, refactor nanti harus memutuskan apakah ini bug yang perlu diaktifkan lagi atau sengaja dinonaktifkan sementara.

## frontend_bangdeliv/lib/features/driver_orders/presentation/widgets/driver_active_order_fee_widgets.dart

- Fungsi: card revisi ongkir manual driver, ringkasan jarak/ongkir/sumber, status negosiasi ongkir, aksi setujui tawaran customer, dan dialog input nominal/alasan revisi ongkir.
- Logic penting: `DriverManualDeliveryFeeCard` memakai `order.deliveryFee`, `order.deliveryFeeSource`, `order.deliveryFeeNegotiation`, `canDriverSubmitQuote`, dan `canDriverAcceptCounter`; dialog parsing nominal lewat `parseDriverCurrencyInput()` dan wajib alasan sebelum `onSave()`.
- Redundansi/minimalisasi: logic manual ongkir dan negotiation masih cukup terpisah dari screen, tetapi dialog/modal style mirip dengan failed pickup dialog. `deliveryFeeSourceLabel` hanya remap `driver_manual`; kandidat helper kecil jika sumber ongkir tampil di tempat lain. Jika keputusan bisnis akhirnya hanya driver edit ongkir manual tanpa negosiasi panjang, bagian `DeliveryFeeNegotiationModel`/counter bisa disederhanakan setelah backend selesai diaudit.
- Service fee notice: `carefulCarryRequired`, `serviceTypeSupportsCarefulCarry()`, summary chip `Perlu 2 orang`, checkbox `Perlu 2 orang / hati-hati`, alasan otomatis "Perlu 2 orang / barang harus hati-hati", dan parameter `carefulCarryRequired` pada `onSave()` adalah kandidat hapus/refactor sesuai plan Courier yang menghapus service type perlu 2 orang. Manual ongkir/revisi ongkir sendiri bukan `service_fee_lines` dan tetap dipertahankan.
- Risiko: perubahan file ini langsung memengaruhi revisi ongkir driver di `driver_active_order_screen.dart`, kontrak `updateDeliveryFeeOverride()`, tampilan negosiasi ongkir, dan test/manual flow pembayaran; hapus `carefulCarry` harus sinkron dengan model, provider, service API, dan backend request field.

## frontend_bangdeliv/lib/features/driver_orders/presentation/widgets/driver_active_order_map_widgets.dart

- Fungsi: card Google Map untuk order aktif driver, menampilkan marker pickup/merchant, dropoff, posisi driver, polyline rute, fallback garis lurus, badge jarak, hint rute belum tersedia, dan auto-fit kamera ke titik order.
- Logic penting: `_pickupPoints()` memprioritaskan `route.orderedPickupLocationIds`, lalu `sequenceNo`, dan fallback ke koordinat pickup default; `_buildPolylines()` pakai encoded polyline jika valid, fallback ke pickup/dropoff; `didUpdateWidget()` fit kamera sekali saat posisi driver pertama kali tersedia; `_decodePolyline()` decode polyline lokal.
- Redundansi/minimalisasi: decode polyline dan fit bounds adalah logic util yang bisa diekstrak/test jika map customer/tracking punya pola sama. `_pickupPoints()` mencampur sorting merchant multi-stop dan rendering map; bisa dipindah ke presenter kecil bila flow multi-merchant makin kompleks. UI badge map mirip `_MapDistanceBadge`/`_RouteUnavailableBadge` bisa tetap private karena file masih fokus.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke tracking visual driver, multi-merchant route order Nitip/Shopping, fallback route saat polyline backend kosong, dan test `driver_active_order_ui_test.dart`; pastikan GoogleMap tetap tidak blank dan kamera tidak crash saat koordinat parsial.

## frontend_bangdeliv/lib/features/driver_orders/presentation/widgets/driver_active_order_meta_widgets.dart

- Fungsi: card metadata order aktif/riwayat driver: identitas customer, order id, service/status/payment pill, visual rute pickup/merchant/dropoff, jarak, detail barang Kurir, ringkasan fee driver/ongkir/total, dan breakdown biaya shopping.
- Logic penting: `DriverOrderMetaCard` dipakai oleh active detail dan history detail; route visualizer menampilkan merchant aktif dan status merchant gagal/dilewati; `_buildPricingSummary()` membandingkan `order.fee` dan `totalPrice`, menampilkan `deliveryFee`, `deliveryFeeSource`, `carefulCarryRequired`, serta `pricing.feeBreakdown` atau fallback `order.feeBreakdown`.
- Redundansi/minimalisasi: `_buildAvatar()`, pill, route visualizer, dan pricing card overlap dengan `driver_orders_screen.dart`; kandidat ekstrak hanya setelah widget driver lain selesai diaudit. `buildCourierPackageDetails()` dipakai juga di daftar order, sehingga row detail barang bisa dibuat komponen kecil. `fee != total` sebagai trigger tampil "Fee Driver" perlu dicek dengan model/backend karena bisa membingungkan bila total mencakup item, ongkir, service fee, dan penalti.
- Service fee notice: `carefulCarryRequired`, `serviceTypeSupportsCarefulCarry()`, dan teks `perlu 2 orang` adalah kandidat hapus/refactor sesuai plan Courier. `pricing.feeBreakdown`, `order.feeBreakdown`, dan `ShoppingFeeBreakdown` juga kandidat terkait service fee lines; saat refactor, pastikan tidak menghapus tampilan/logic penalti gagal pickup 3 kali/50% jika backend tetap mengirimkannya lewat field lain.
- Risiko: blast radius ke active order dan history detail karena widget ini dipakai di kedua screen. Menghapus `feeBreakdown`/careful carry harus sinkron dengan `driver_order_model.dart`, `customer_order_model.dart`, tracking widget, service API, dan backend response agar total/ongkir tetap jelas.

## frontend_bangdeliv/lib/features/driver_orders/presentation/widgets/driver_active_order_proof_widgets.dart

- Fungsi: card checklist bukti foto order aktif driver untuk upload/ganti foto bukti, preview foto, dan snackbar hasil upload.
- Logic penting: requirement bukti ditentukan dari `serviceType`: Kurir butuh `pickup` dan `delivery`, Shopping/Nitip butuh `receipt`, dan `store_closed` hanya ditampilkan jika order sudah punya proof tersebut; upload memakai `pickDriverOrderImage()` lalu callback `onUploadProof(type, photo, note)`.
- Redundansi/minimalisasi: preview foto dialog mirip dengan preview bukti di `driver_order_history_detail_screen.dart`, kandidat widget/helper image preview shared. Requirement proof masih hardcoded string (`pickup`, `delivery`, `receipt`, `store_closed`) walau ada konstanta proof di domain test; bisa dipindah ke domain/helper agar tidak raw string. Parameter callback `pickupLocationId` tidak dipakai dari widget ini, hanya relevan untuk flow gagal pickup di screen/action widget.
- Service fee notice: tidak ada logic service fee/careful carry. Proof `store_closed` terkait flow gagal pickup merchant yang harus dipertahankan untuk pengecualian fee gagal 3 kali/50%, bukan kandidat hapus.
- Risiko: perubahan file ini berdampak ke upload bukti pickup/delivery/receipt, reconciliation proof transfer di active screen secara tidak langsung, dan test `driver_order_action_loading_test.dart`; jaga loading per proof type agar spinner tidak salah baris.

## frontend_bangdeliv/lib/features/driver_orders/presentation/widgets/driver_active_order_shopping_widgets.dart

- Fungsi: widget flow Shopping/Nitip order aktif driver: respon request item customer, input quote harga merchant, bypass approval harga, edit ketersediaan item, tandai merchant buka/tutup, upload struk, preview bukti, simpan checkout, dan payload item.
- Logic penting: `DriverShoppingItemChangeRequestCard` approve/reject request customer; `DriverShoppingMerchantQuotePanel` mengirim harga merchant atau lanjut tanpa respons customer; `DriverShoppingItemsCard` menyimpan state lokal `_availability` dan `_heavy`, sinkron dari `shoppingItems`, mengunci edit saat ada pending request/quote, dan membangun payload `id`, `quantity`, `is_available`, `notes`, `is_heavy`.
- Redundansi/minimalisasi: file 1217 baris dan mencampur tiga komponen besar plus banyak helper. Kandidat refactor minimal: pisah request item card, quote panel, stop section, proof preview, dan heavy/availability state presenter. Preview foto dialog sama dengan proof/history widgets; `store_closed`, `receipt`, dan status string sebaiknya pakai konstanta domain seperti `ProofTypeCode.storeClosed`. Logic `_syncControllers()` hanya trigger saat `shoppingItems` berubah, padahal stop/items nested juga bisa berubah dari realtime.
- Service fee notice: `is_heavy`, toggle `Item berat`, subtitle `Tambahan biaya Rp6.000 sekali per order`, dan payload `is_heavy` adalah kandidat hapus/refactor karena termasuk item surcharge/service fee line yang ingin dibuang. Flow `Resto tutup/order batal`, `onMarkMerchantClosed`, proof `store_closed`, dan bypass/quote harga merchant harus dipertahankan karena terkait operasional merchant dan pengecualian fee gagal pickup 3 kali/50%.
- Risiko: blast radius tinggi ke checkout Nitip/Shopping, multi-merchant stop, quote approval customer, request perubahan item, bukti struk, flow merchant tutup, dan test `driver_order_action_loading_test.dart`. Menghapus `is_heavy` harus sinkron dengan model `DriverShoppingItemModel`/`CustomerShoppingItemModel`, backend payload, fee breakdown, dan test yang masih mengenal "Item berat".

## frontend_bangdeliv/lib/features/driver_orders/presentation/widgets/driver_active_order_widget_helpers.dart

- Fungsi: helper UI kecil untuk dialog driver order: input decoration, parsing nominal driver, dan bottom sheet pilih sumber gambar kamera/galeri.
- Logic penting: `driverDialogInputDecoration()` membakukan style field dialog driver; `parseDriverCurrencyInput()` hanya wrapper ke `parseCurrencyInput()`; `pickDriverOrderImage()` membuka modal source picker lalu `ImagePicker().pickImage()` dengan `imageQuality: 76` dan `maxWidth: 1600`.
- Redundansi/minimalisasi: wrapper `parseDriverCurrencyInput()` tipis dan bisa dihapus jika tidak butuh nama domain khusus. `pickDriverOrderImage()` dipakai proof/action/shopping dan belum injectable, sehingga test upload image sulit tanpa mock. Style dialog ini bisa jadi shared untuk semua dialog driver, tetapi tidak perlu diperbesar.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan helper ini berdampak ke dialog edit ongkir, gagal pickup merchant, upload proof, dan upload struk; jaga kompatibilitas `ImageSource` dan ukuran/quality foto agar upload backend tetap aman.

## frontend_bangdeliv/lib/features/driver_orders/presentation/widgets/driver_distance_badge.dart

- Fungsi: badge jarak dispatch driver ke pickup pada daftar order masuk.
- Logic penting: memakai `DriverDispatchPresenter.present()` untuk label/bucket, lalu memetakan bucket `NEAR/MEDIUM/FAR/UNKNOWN` ke warna dan membungkusnya dengan `Semantics` label.
- Redundansi/minimalisasi: file sudah kecil dan clean. Warna bucket masih lokal di widget, tetapi cocok karena presenter hanya menyiapkan data. Bisa dipertahankan kecuali badge jarak dipakai di banyak tempat.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: rendah; perubahan label/warna bisa memengaruhi scannability daftar order driver dan test `driver_distance_badge_test.dart`.

## frontend_bangdeliv/lib/features/home/presentation/screens/home_screen.dart

- Fungsi: beranda customer berisi header alamat aktif, kartu layanan Antar Jemput/Kurir/Nitip ke chatbot, pesanan aktif, dan daftar ringkas toko/resto terdekat.
- Logic penting: `_homeScreenDataProvider` local family memanggil `fetchHomeData(limitMerchants: 5, latitude, longitude)` berdasarkan alamat default; pull-to-refresh invalidate provider lokal; active order dari `customerActiveOrderProvider`; merchant card membuka `merchantDetailPath()` dengan `extra` merchant.
- Redundansi/minimalisasi: provider lokal `_homeScreenDataProvider` overlap dengan global `homeDataProvider`, sedangkan `app_router.dart` meng-invalidate `homeDataProvider`; ini berpotensi membuat invalidasi global tidak menyentuh data home screen. Kartu merchant di home dan nearby screen punya layout mirip tetapi beda implementasi. Service card masih hardcoded tiga service type; bisa jadi konstanta service entry shared dengan chatbot/router jika dipakai di banyak tempat.
- Service fee notice: tidak ada logic service fee/careful carry. `totalAmount` pada pesanan aktif hanya total pembayaran order untuk display, bukan service fee lines.
- Risiko: perubahan file ini memengaruhi halaman utama customer, pemilihan alamat untuk estimasi merchant terdekat, navigasi chatbot service, tracking active order, dan cache home data; perlu jaga empty/loading/error state agar beranda tetap usable saat API gagal.

## frontend_bangdeliv/lib/features/home/presentation/screens/menu_detail_screen.dart

- Fungsi: detail menu makanan dengan gambar, nama, merchant, harga, deskripsi, dan tombol pesan via Nitip.
- Logic penting: jika `initialMenu` tersedia langsung render; jika tidak, fallback mencari `menuId` di `homeDataProvider.popularMenus`; tombol utama membuka chatbot dengan `service_type=nitip`.
- Redundansi/minimalisasi: fallback detail hanya bisa menemukan menu yang ada di `popularMenus`, bukan detail endpoint per menu; deep link/menu lama bisa gagal walau menu valid di backend. Error state mirip `merchant_detail_screen.dart`; bisa dijadikan helper kecil. Tombol "Pesan via Nitip" belum mengirim preset menu/merchant ke chatbot, sehingga user tetap harus mengetik ulang.
- Service fee notice: tidak ada logic service fee/careful carry. `formattedPrice` adalah harga menu, bukan service fee.
- Risiko: perubahan file ini berdampak ke route menu detail, extra payload dari router, dan flow Nitip dari menu; perlu validasi direct open tanpa `initialMenu` dan image error fallback.

## frontend_bangdeliv/lib/features/home/presentation/screens/merchant_detail_screen.dart

- Fungsi: detail toko/resto sederhana dengan gambar, nama, jarak, dan placeholder informasi detail merchant.
- Logic penting: jika `initialMerchant` tersedia langsung render; jika tidak, fallback mencari `merchantId` di `homeDataProvider.nearbyMerchants`; back memakai `Navigator.maybePop()`.
- Redundansi/minimalisasi: fallback hanya mencari merchant di data nearby global, sedangkan `HomeScreen` memakai provider lokal dengan koordinat alamat; bisa tidak konsisten untuk direct route atau alamat berbeda. UI error state duplikat dengan menu detail. Konten detail masih placeholder, sehingga kandidat minimal adalah tetap sederhana atau hubungkan ke detail merchant endpoint sebelum dipoles.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini memengaruhi navigasi dari home/nearby merchant dan fallback direct route; jangan menghapus fallback `initialMerchant` karena itu mencegah fetch ulang saat navigasi dari list.

## frontend_bangdeliv/lib/features/home/presentation/screens/nearby_merchants_screen.dart

- Fungsi: screen daftar semua toko/resto terdekat dengan search, pull-to-refresh, info lokasi, empty/loading/error state, dan navigasi ke detail merchant.
- Logic penting: memakai `Future<HomeDataModel> _future` manual, `_query` dari search submit, dan `homeApiServiceProvider.fetchHomeData(search: _query)`; refresh mengganti future; "Ubah Lokasi" menuju `AppRoutes.addresses`.
- Redundansi/minimalisasi: file ini tidak memakai `homeDataProvider` atau provider lokal dengan koordinat alamat seperti `HomeScreen`, sehingga data nearby/search bisa tidak konsisten dengan alamat aktif. Stateful manual `FutureBuilder` bisa diganti provider family `search + coordinate` agar refresh/invalidation seragam. Merchant card mirip card di home dan belum memakai image merchant walau model punya `imageUrl`.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke pencarian merchant, refresh, navigasi detail, dan konsistensi lokasi aktif; perlu cek search kosong, search tidak ada hasil, API error, dan tombol ubah lokasi.

## frontend_bangdeliv/lib/features/navigation/presentation/screens/driver_main_layout.dart

- Fungsi: shell layout driver dengan bottom navigation Beranda/Orderan/Riwayat/Profil, badge jumlah order masuk, dan bootstrap tracking lokasi driver aktif.
- Logic penting: tab aktif dihitung dari path GoRouter; `authSessionProvider` menentukan `DriverAccessState.active`; jika driver aktif, layout me-watch `driverLocationReporterProvider` dan `driverIncomingOrderCountProvider`; bottom nav disembunyikan jika driver belum aktif.
- Redundansi/minimalisasi: struktur bottom nav sangat mirip `main_layout.dart`, hanya route/label dan badge yang berbeda; kandidat helper config nav item atau shared bottom-nav shell. Memulai `driverLocationReporterProvider` dari layout membuat tracking bergantung pada shell ini; baik untuk bootstrap global driver, tetapi perlu jelas agar tidak dipindah sembarangan saat refactor router.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke navigasi utama driver, badge order masuk, dan background tracking lokasi driver; jaga agar driver non-active tidak mendapat bottom nav driver dan provider tracking tidak aktif.

## frontend_bangdeliv/lib/features/navigation/presentation/screens/main_layout.dart

- Fungsi: shell layout customer dengan bottom navigation Beranda/Aktivitas/Riwayat/Akun dan double-back-to-exit.
- Logic penting: tab aktif dihitung dari path GoRouter, termasuk `nearbyMerchants` tetap tab Beranda; tap nav memakai `context.go()`; `PopScope(canPop: false)` memanggil `_handleSystemBack()` dan `SystemNavigator.pop()` jika back ditekan dua kali dalam 2 detik.
- Redundansi/minimalisasi: bottom nav item/warna/route switch duplikat dengan `driver_main_layout.dart`; kandidat helper kecil untuk selected index + item tap bila route shell makin banyak. Double-back logic hanya ada di customer layout, belum ada di driver layout; perlu putusan apakah memang hanya customer yang boleh exit dari shell.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke navigasi utama customer, state selected tab, nested route seperti nearby merchants, dan perilaku tombol back Android; perlu test manual back dari tab root dan halaman anak.

## frontend_bangdeliv/lib/features/orders/application/customer_order_providers.dart

- Fungsi: pusat provider order customer untuk list order, auto refresh, realtime retain/release active order, filter completed/activity/ongoing/cancelled, detail order, dan active order.
- Logic penting: `CustomerOrdersNotifier` hanya aktif untuk session customer authenticated; fetch list lewat `customerOrderRepositoryProvider.fetchOrders(page: 1, perPage: 50)`; realtime hub patch status event langsung ke state, content/unknown order menjadwalkan reconciliation debounce 800ms; active order diretain ke `orderRealtimeHubProvider`; `customerOrdersAutoRefreshProvider` polling 8 detik saat ada order non-terminal.
- Redundansi/minimalisasi: file mencampur list notifier, realtime hub lifecycle, polling auto refresh, derived filters, dan detail provider; masih cukup readable tapi kandidat pecah kecil jika order customer bertambah kompleks. Ada dua jalur refresh data aktif: realtime reconciliation dan auto-refresh polling; bisa ditinjau apakah polling masih diperlukan setelah realtime stabil. Sorting/filter provider sudah simple, tetapi invalidasi manual di `app_router.dart` untuk banyak derived provider mungkin berlebihan karena derived provider akan ikut berubah dari source.
- Service fee notice: tidak ada logic service fee/careful carry/fee breakdown di file ini. Status terminal dan list order harus tetap netral terhadap `CANCELLED_WITH_FEE`; jangan menghapus status penalti 50% dari model/status helper hanya karena cleanup service fee.
- Risiko: blast radius tinggi ke home active order, activity, history, tracking, order chat detail, realtime retain/release, dan test `customer_order_providers_test.dart`; refactor harus menjaga release hub saat role berubah/logout, silent refresh tidak menimpa error state sembarangan, dan active order tetap order non-terminal pertama.

## frontend_bangdeliv/lib/features/orders/application/order_chat_provider.dart

- Fungsi: provider state chat order per `orderId` untuk fetch halaman pesan, load older, optimistic send text/attachment, realtime chat, fallback polling, reconciliation, merge pesan, dan release retain order.
- Logic penting: hanya session customer/driver authenticated yang boleh build; initial fetch `fetchMessages(limit: 50)`; `sendMessage()`/`sendAttachment()` membuat optimistic message dengan `clientMessageId`; jika send gagal mencoba recover lewat fetch terbaru sebelum menandai failed; realtime retain order lewat `orderRealtimeHubProvider`; reconciliation polling tiap `orderChatReconciliationInterval` 2 detik dan degraded polling saat connection issue.
- Redundansi/minimalisasi: file besar karena mencampur state model, send optimistic, realtime lifecycle, fallback degraded mode, reconciliation, dan merge/dedupe. Lifecycle realtime/timer/release mirip dengan `order_chat_unread_provider.dart` dan customer/driver order providers; kandidat helper kecil untuk retain/release hub + degraded polling. `orderChatReconciliationInterval` global mutable dipakai test; bisa jadi provider/config override jika ingin lebih clean. Merge pesan sudah pure-ish dan bisa diekstrak/test mandiri bila refactor.
- Service fee notice: tidak ada logic service fee/careful carry/fee breakdown.
- Risiko: blast radius tinggi ke `order_chat_screen.dart`, unread badge, driver/customer chat realtime, attachment upload, optimistic UI, dan test `order_chat_provider_test.dart`; refactor harus menjaga dedupe `clientMessageId`, recovery setelah send gagal, release retained order on dispose, dan fallback saat realtime backend bermasalah.

## frontend_bangdeliv/lib/features/orders/application/order_chat_unread_provider.dart

- Fungsi: provider unread count chat order per `orderId`, termasuk fetch unread summary, mark read, realtime increment, reconciliation, degraded refresh, dan retain/release order.
- Logic penting: hanya session customer/driver authenticated yang eligible; initial build subscribe realtime lalu fetch unread; realtime chat dari user lain dengan server id lebih besar dari `_lastReadMessageId` menaikkan count lokal; `markReadThrough()` memanggil API `markRead()` dan fallback `refreshUnread()` bila gagal; reconciliation polling tiap 4 detik dan degraded refresh tiap 8 detik.
- Redundansi/minimalisasi: lifecycle realtime/degraded timer/reconciliation/release sangat mirip `order_chat_provider.dart`; bisa diekstrak sebagai helper shared agar tidak beda perilaku diam-diam. `_countedRealtimeMessageIds` menjaga increment lokal, tapi state akhir kadang dari `summary.unreadCount` dan kadang max realtime count; perlu hati-hati jika nanti server summary terlambat. Interval global mutable untuk test bisa dirapikan seperti provider config.
- Service fee notice: tidak ada logic service fee/careful carry/fee breakdown.
- Risiko: perubahan file ini berdampak ke badge chat customer/driver, active order, history detail, realtime bootstrap driver, dan test `order_chat_provider_test.dart`/`driver_order_providers_test.dart`; jaga agar pesan dari diri sendiri tidak menambah unread dan `markReadThrough()` membersihkan count lokal.

## frontend_bangdeliv/lib/features/orders/presentation/screens/activity_screen.dart

- Fungsi: screen aktivitas customer untuk daftar order aktif/non-terminal, pull-to-refresh, auto-refresh provider, tracking, dan pembatalan order pending dengan dialog alasan.
- Logic penting: saat screen dibuka `_refreshOnOpen()` invalidate `customerOrdersProvider`; build me-watch `customerOrdersAutoRefreshProvider`; list mengambil order non-terminal yang disortir terbaru; cancel hanya muncul jika status normalized `PENDING`; `_cancelOrder()` memanggil `customerOrderRepositoryProvider.cancelOrder()`, refresh list, invalidate detail, dan menjaga loading per order id.
- Redundansi/minimalisasi: sorting terbaru lokal menduplikasi `customerSortedOrdersProvider`; bisa pakai `customerOngoingOrdersProvider` agar filter/sort konsisten. Dialog alasan pembatalan cukup panjang di file screen; kandidat ekstrak widget terpisah jika screen order lain butuh cancellation. `_isOpeningRefresh` membuat loading awal manual di luar `customerOrdersProvider`, bisa disederhanakan jika provider refresh behavior sudah cukup.
- Service fee notice: tidak ada logic service fee/careful carry. Cancel di screen ini hanya untuk status `PENDING` sebelum driver, jadi berbeda dari flow `CANCELLED_WITH_FEE`/penalti 50% yang harus dipertahankan di flow gagal pickup merchant.
- Risiko: perubahan file ini berdampak ke tab Aktivitas, cancel order customer, tracking navigation, auto-refresh order aktif, dan `CustomerOrderCard`; perlu cek pending order bisa cancel, order non-pending tidak bisa cancel, refresh error, dan loading cancellation per item.

## frontend_bangdeliv/lib/features/orders/presentation/screens/order_chat_screen.dart

- Fungsi: screen chat order customer/driver untuk membaca pesan, load older, kirim teks/foto, render bubble attachment, mark read, fallback sync banner, dan navigasi back sesuai role.
- Logic penting: watch `orderChatProvider(orderId)` dan `orderChatUnreadCountProvider(orderId)`; customer juga fetch `customerOrderDetailProvider` untuk nama/avatar driver; `ref.listen` auto mark read dan scroll bawah saat pesan bertambah; refresh invalidate chat provider dan mark read ulang; attachment image picker langsung di screen lalu `sendAttachment()`.
- Redundansi/minimalisasi: file 754 baris mencampur screen orchestration, composer, bubble, attachment renderer, title avatar, sync banner, dan image picker. Image picker bottom sheet duplikat dengan `pickDriverOrderImage()` di widget helper driver; kandidat helper image picker shared. `_latestServerMessageId()` duplikat dengan provider chat. `_MessageBubble`, `_Composer`, dan `_MessageAttachment` bisa diekstrak bila screen makin besar, tetapi jaga test existing.
- Service fee notice: tidak ada logic service fee/careful carry/fee breakdown.
- Risiko: blast radius ke chat customer/driver, unread badge, attachment local/remote preview, mark-read, back navigation dari driver/customer, dan test `order_chat_provider_test.dart`; refactor harus menjaga auto-scroll, optimistic message display, disabled composer saat chat selesai, dan mark-read tidak spam API.

## frontend_bangdeliv/lib/features/orders/presentation/screens/order_history_screen.dart

- Fungsi: screen riwayat pesanan customer dengan tab Semua/Selesai/Dibatalkan, refresh awal, pull-to-refresh, empty state, dan navigasi ke tracking detail dari history.
- Logic penting: refresh awal invalidate `customerOrdersProvider`; tab data memakai `customerSortedOrdersProvider`, `customerCompletedOrdersProvider`, dan `customerCancelledOrdersProvider`; item `CustomerOrderCard` membuka `AppRoutes.track` dengan extra `{orderId, fromHistory: true}` dan menyembunyikan info payment.
- Redundansi/minimalisasi: pola `_isOpeningRefresh`, `_refreshOrders()`, error/empty/list mirip `activity_screen.dart`; kandidat shared order list scaffold kecil. Tab default `initialIndex: 1` membuat layar langsung ke "Selesai", perlu dipastikan memang keputusan UX. `historyOrders` filter terminal lokal masih wajar, tapi bisa jadi provider turunan jika dipakai lagi.
- Service fee notice: tidak ada logic service fee/careful carry langsung. Karena `CustomerOrderCard` menampilkan harga inline, audit card/model tetap perlu membedakan total pembayaran vs service fee lines.
- Risiko: perubahan file ini berdampak ke tab Riwayat, filter completed/cancelled, tracking dari history, dan tampilan `CustomerOrderCard`; perlu cek order `CANCELLED_WITH_FEE` tetap masuk cancelled/terminal sesuai status helper.

## frontend_bangdeliv/lib/features/profile/presentation/screens/change_password_screen.dart

- Fungsi: screen form ganti password dengan validasi password lama, password baru, konfirmasi, toggle visibility, tips keamanan, dan submit ke `AuthService.changePassword()`.
- Logic penting: validasi lokal minimal 8 karakter, password baru harus berbeda dari password lama, konfirmasi harus sama; sukses menampilkan snackbar lalu `context.pop(true)`, error memakai pesan `AuthException`.
- Redundansi/minimalisasi: pola password field, visibility toggle, input decoration, validator, dan snackbar error mirip auth/register screen; kandidat helper form/password field kecil bila refactor UI form dilakukan.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke endpoint ganti password dan validasi UX; jaga agar validasi frontend tetap selaras dengan backend.

## frontend_bangdeliv/lib/features/profile/presentation/screens/driver_profile_screen.dart

- Fungsi: profil utama driver dengan hero profile, kartu statistik, status operasional, status verifikasi, aksi cepat, bantuan, logout, dan refresh profile.
- Logic penting: memakai `Future<UserProfileModel>` manual dari `AuthService.fetchCurrentUserProfile()`, lalu sync ke `authSessionProvider`; quick actions menuju edit profile, ganti password, notifikasi, privacy map, dan status verifikasi.
- Redundansi/minimalisasi: profile fetching manual mirip `profile_screen.dart`, sementara status label/color driver juga mirip driver home/verifikasi. Quick action tile bisa diseragamkan dengan menu profile customer jika ingin clean.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke navigasi profile driver, refresh setelah edit/verifikasi, logout, dan status akses driver; jangan memindahkan logic yang membuat session profile tetap tersinkron tanpa pengganti.

## frontend_bangdeliv/lib/features/profile/presentation/screens/driver_verification_status_screen.dart

- Fungsi: screen status verifikasi driver dan upload ulang dokumen KTP, SIM, selfie dari kamera/galeri.
- Logic penting: load status lewat `DriverVerificationService.fetchMyStatus()`, submit dokumen lewat `submitDocuments()`, refresh auth session setelah load/submit, dan blok upload jika status `active` atau `suspended`.
- Redundansi/minimalisasi: picker kamera/galeri dan kartu dokumen mirip pola edit avatar/order proof/chat attachment; kandidat helper image picker shared. Mapping warna/label status driver duplikat dengan profile driver.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke flow verifikasi driver, upload multipart dokumen, status route, dan auth session refresh; perlu jaga back navigation ke `AppRoutes.driverProfile`.

## frontend_bangdeliv/lib/features/profile/presentation/screens/edit_profile_screen.dart

- Fungsi: form edit profil customer/driver untuk nama, telepon, email, avatar, dan field kendaraan khusus driver.
- Logic penting: load profil dari `AuthService.fetchCurrentUserProfile()`, isi controller, tampilkan `VehicleInfoFields` hanya untuk role driver, submit ke `AuthService.updateCurrentUserProfile()`, lalu sync `authSessionProvider`.
- Redundansi/minimalisasi: input decoration/validator/snackbar manual mirip form auth dan change password. Avatar picker dialog kamera/galeri/remove mirip verification/chat/proof picker; kandidat helper shared untuk memilih gambar dan option sheet.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke update data user, avatar upload/remove, dan data kendaraan driver; validasi phone/email dan sync profile harus tetap terjaga.

## frontend_bangdeliv/lib/features/profile/presentation/screens/notification_settings_screen.dart

- Fungsi: screen pengaturan notifikasi dengan switch lokal untuk update pesanan, pesan chat, dan pembaruan aplikasi.
- Logic penting: state `_orderUpdates`, `_chatMessages`, `_appUpdates` hanya hidup di widget; belum persist ke storage/backend dan belum terhubung ke Firebase/local notification preference.
- Redundansi/minimalisasi: bila belum ada backend preference, file ini bisa dianggap placeholder UX; kandidat minimal adalah hide route/menu atau simpan lokal sederhana sampai fitur notifikasi preference benar-benar aktif.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: user bisa mengira setting sudah berlaku padahal hanya state sementara; refactor harus memutuskan apakah fitur dihubungkan, disimpan lokal, atau disembunyikan.

## frontend_bangdeliv/lib/features/profile/presentation/screens/privacy_map_screen.dart

- Fungsi: placeholder screen kebijakan privasi dengan app bar dan body kosong.
- Logic penting: hanya render `Scaffold` + `AppBar('Kebijakan Privasi')` + `SizedBox.shrink()`.
- Redundansi/minimalisasi: kandidat kuat hapus/hide route/menu jika belum ada konten; atau isi dengan dokumen privasi sungguhan agar tidak menjadi dead page.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: route masih dipakai dari quick action driver; jika dihapus, update route/menu bersamaan agar tidak ada navigasi ke halaman kosong.

## frontend_bangdeliv/lib/features/profile/presentation/screens/profile_screen.dart

- Fungsi: profil utama customer dengan hero profile, statistik order/total bayar, menu akun, upgrade driver, bantuan, logout, refresh, dan error state.
- Logic penting: memakai `Future<UserProfileModel>` manual + cache `_cachedProfile`; edit profile reload data, alamat reload setelah kembali, logout lewat `authSessionProvider.notifier.logout()` lalu `context.go(AppRoutes.login)`.
- Redundansi/minimalisasi: profile fetching manual mirip `driver_profile_screen.dart` tetapi tidak sync profile ke `authSessionProvider` saat fetch sukses. Menu tile/logout/help card punya pola mirip driver profile; kandidat shared profile/menu widgets bila ingin ringkas.
- Service fee notice: tidak ada logic service fee/careful carry. Statistik `totalPaid` adalah agregat pembayaran, bukan service fee lines.
- Risiko: perubahan file ini berdampak ke tab Akun customer, upgrade driver, alamat, edit profile, dan logout; pastikan refresh profile tetap menjaga cached data saat fetch gagal.

## frontend_bangdeliv/lib/features/realtime/application/app_realtime_bootstrap_provider.dart

- Fungsi: bootstrap realtime global untuk driver aktif agar realtime driver tetap hidup walau tidak sedang berada di shell driver.
- Logic penting: hanya aktif jika session authenticated, role driver, `DriverAccessState.active`, dan profile tersedia; me-watch `driverRealtimeBootstrapProvider` lalu expose `driverBootstrapActive` dan `retainedDriverUnreadOrderIds`.
- Redundansi/minimalisasi: file ini tipis, tetapi namanya `appRealtimeBootstrap` sementara isinya khusus driver realtime; bisa tetap dipertahankan sebagai pintu bootstrap global atau diganti nama jika nanti hanya driver yang dibootstrap.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke realtime driver di luar layout driver dan test bootstrap; jangan hapus wrapper ini tanpa memastikan `main.dart` tetap mengaktifkan bootstrap driver.

## frontend_bangdeliv/lib/features/realtime/application/chat_heads_up_notification_provider.dart

- Fungsi: listener heads-up notifikasi lokal untuk pesan chat order yang masuk dari realtime hub.
- Logic penting: hanya aktif untuk customer/driver authenticated; listen event `OrderRealtimeEventType.chat`; abaikan pesan dari user sendiri, pesan tanpa server id, dan chat yang sedang dibuka; tampilkan local notification via `FirebaseNotificationService.showLocalOrderChatNotification()`.
- Redundansi/minimalisasi: format title/body notifikasi bisa disatukan dengan formatter notification lain bila ada. Provider ini hanya mendengar order yang sudah diretained oleh hub, jadi coverage notifikasi bergantung pada lifecycle retain order di provider lain.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke notifikasi chat foreground, duplikasi notifikasi, dan route comparison chat; pastikan tidak menampilkan notifikasi ketika user sedang di halaman chat order yang sama.

## frontend_bangdeliv/lib/features/realtime/application/firebase_notification_provider.dart

- Fungsi: bootstrap Firebase notification, foreground display guard, deep-link route dari notifikasi, dan sync device token ke backend.
- Logic penting: memanggil `FirebaseNotificationService.initializeNotifications()` dengan callback `onOpenRoute` dan `shouldShowForegroundMessage`; pending route disimpan lewat `NotificationNavigationService`; token disync hanya untuk customer/driver authenticated, selain itu backend token sync dibersihkan.
- Redundansi/minimalisasi: provider ini berisi side effect tanpa state dan akan rebuild mengikuti router/session/device token API; aman jika service idempotent, tetapi kandidat clean-up adalah membuat bootstrap/init idempotency lebih eksplisit. Logic route drain bisa diekstrak kecil bila dipakai provider notifikasi lain.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke izin/penerimaan notifikasi, token device backend, dan navigasi dari push notification; jaga agar pending route tidak hilang sebelum session initialized.

## frontend_bangdeliv/lib/features/realtime/application/order_realtime_hub_provider.dart

- Fungsi: hub realtime order terpusat untuk retain/release subscription per order, broadcast event status/content/location/chat, dan retry koneksi.
- Logic penting: `retainOrder()` memakai retain count agar beberapa provider bisa subscribe order yang sama; `_subscribe()` connect ke `OrderRealtimeClient` lalu subscribe tracking; `releaseOrder()` cancel subscription saat retain count habis; connection issue emit event dan schedule retry bertahap 250ms sampai 30s.
- Redundansi/minimalisasi: file ini sudah menjadi shared abstraction utama; jangan dipecah terlalu cepat. Potensi clean-up kecil ada di lifecycle pending subscription/retry agar lebih mudah diuji, karena pola retain/release dipakai banyak provider order/chat/tracking.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: blast radius tinggi ke tracking customer, order list customer, order driver, chat, unread badge, heads-up notification, dan router logout invalidation; refactor harus menjaga retain count, cancel timer/subscription, retry delay, dan broadcast event tetap kompatibel.

## frontend_bangdeliv/lib/features/shopping/application/shopping_item_draft.dart

- Fungsi: model draft item belanja lokal sebelum dikirim sebagai payload order Nitip.
- Logic penting: menyimpan merchant, optional Google place merchant, `menuId`, nama item, quantity, notes, optional `unitPrice`, dan flag `isFromMenu`; getter `itemSource` menghasilkan `MENU_DB` atau `MANUAL`, `isExternalMerchant` true jika merchant id <= 0 dan ada place payload.
- Redundansi/minimalisasi: file ini kecil dan fokus; potensi minimalisasi hanya menyatukan konstanta `MENU_DB`/`MANUAL` dengan `ShoppingItemDraftPayload` agar tidak ada string contract ganda.
- Service fee notice: tidak ada logic service fee/careful carry. `unitPrice` adalah harga item draft, bukan service fee lines atau surcharge.
- Risiko: perubahan file ini berdampak ke `shopping_add_item_screen.dart`, `shopping_draft_items_section.dart`, dan serialisasi `ShoppingItemDraftPayload`; jaga kontrak `item_source` tetap kompatibel dengan backend/chatbot.

## frontend_bangdeliv/lib/features/shopping/presentation/screens/shopping_add_item_screen.dart

- Fungsi: screen tambah/edit item Nitip untuk order berjalan, termasuk cari merchant, pilih merchant dari map, pilih menu resto, tambah item manual, edit/remove draft item, dan submit ke API tambah item atau request perubahan item unavailable.
- Logic penting: bootstrap detail order jika `initialDetail` kosong; mode request ditentukan dari `canEditShoppingItems`, `canRequestAddShoppingStop`, `targetPickupLocationId`, dan `canEditUnavailableShoppingItems`; merchant map dicocokkan dulu ke database merchant berdasarkan nama + jarak 180m; draft maksimal 30 item; submit mengirim `ShoppingItemDraftPayload` via `addShoppingItems()` atau `requestShoppingItemChange(action: ADD, requestKind: EDIT_UNAVAILABLE)`.
- Redundansi/minimalisasi: file 888 baris mencampur orchestration API, matching merchant, draft item state, distance calculation, scroll behavior, dan submit result. Helper pure seperti `_normalizeMerchantName`, `_isSameMerchantOption`, `_distanceMeters`, upsert/increment draft, dan route result bisa dipindah kecil ke application/helper agar screen lebih ringkas dan lebih mudah dites. `oldTotal/newTotal/oldStopCount/newStopCount` di `ShoppingAddItemResult` perlu dicek apakah masih dipakai penuh atau hanya `deliveryFeeChanged/message`.
- Service fee notice: tidak ada `service_fee`, fee lines, atau careful-carry. Ada logic `oldDeliveryFee/newDeliveryFee`, `deliveryFeeChanged`, dan pesan "Ongkir diperbarui"; ini bukan target hapus langsung dari service-fee-lines, tetapi perlu ditinjau saat refactor pricing karena plan menekankan penyesuaian ongkir oleh driver secara manual.
- Risiko: blast radius ke flow tambah item Nitip dari tracking, edit item unavailable, merchant map picker, payload item/menu/place, dan test `shopping_add_item_screen_test.dart`; refactor harus menjaga mode request vs direct add, matching merchant eksternal, batas 30 item, dan kontrak payload backend.

## frontend_bangdeliv/lib/features/shopping/presentation/screens/shopping_merchant_map_picker_screen.dart

- Fungsi: screen peta untuk memilih merchant Nitip dari Google Places search atau tap manual di map, lalu mengembalikan `ShoppingMerchantPlacePayload`.
- Logic penting: initial camera dari args atau fallback Salatiga; search memakai `GoogleMapsLookupService.searchPlaces()` dengan session token; prediction di-resolve ke detail place; tap peta reverse geocode; confirm melakukan `Navigator.pop(place)`.
- Redundansi/minimalisasi: pola Google Map + search + reverse geocode mirip address/route location picker, sehingga kandidat helper/shared component bila map picker lain ikut dirapikan. `_newSessionToken()` dan `_firstAddressSegment()` bisa dipindah ke util kecil bila dipakai lintas picker.
- Service fee notice: tidak ada logic service fee/careful carry/ongkir.
- Risiko: `searchPlaces()`, `resolvePlace()`, dan `reverseGeocode()` belum dibungkus `try/catch`, sehingga error service/network berpotensi membuat loading state nyangkut atau error tidak tampil. Perubahan file ini berdampak ke tambah item manual Nitip dan merchant picker chatbot yang memakai payload place yang sama.

## frontend_bangdeliv/lib/features/shopping/presentation/widgets/shopping_draft_items_section.dart

- Fungsi: widget ringkasan daftar draft item Nitip, dikelompokkan per merchant, dengan aksi edit/hapus per item.
- Logic penting: grouping memakai merchant id, Google place id, atau fallback nama+koordinat; item menu database tidak bisa diedit manual; harga menu hanya ditampilkan jika `unitPrice > 0`.
- Redundansi/minimalisasi: `_merchantGroupKey()` adalah logic domain kecil yang bisa dipindah dekat `ShoppingItemDraft` jika grouping dipakai di tempat lain. Tampilan item tile cukup spesifik untuk screen tambah item, jadi jangan diekstrak lebih jauh sebelum ada reuse nyata.
- Service fee notice: tidak ada logic service fee/careful carry. `unitPrice` hanya harga menu item, bukan service fee lines.
- Risiko: perubahan file ini berdampak ke ringkasan draft sebelum submit; jaga grouping merchant eksternal agar item dari Google place yang sama tidak terpecah.

## frontend_bangdeliv/lib/features/shopping/presentation/widgets/shopping_inline_info_panel.dart

- Fungsi: panel info/error inline kecil untuk pesan status di flow shopping.
- Logic penting: memilih warna berdasarkan `isError`, memakai icon + teks dalam container full width.
- Redundansi/minimalisasi: komponen ini sangat kecil dan berpotensi reuse lintas fitur sebagai generic inline info/error panel, tetapi saat ini masih aman dibiarkan khusus shopping agar scope refactor minimal.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: risiko rendah; perubahan visual berdampak ke error/menu loading/info di screen tambah item.

## frontend_bangdeliv/lib/features/shopping/presentation/widgets/shopping_manual_item_section.dart

- Fungsi: form input item manual Nitip, quick-pick menu resto, catatan, quantity stepper, dan tombol tambah/simpan item.
- Logic penting: jika `showMenus` true, tampilkan 8 menu pertama; menu loading/error/empty memakai `ShoppingInlineInfoPanel`; tombol tambah memanggil callback parent, harga item manual tetap dikonfirmasi driver dari nota.
- Redundansi/minimalisasi: section ini masih presentational, tetapi quick-pick menu + form manual cukup padat. Jika refactor screen besar, helper menu list dan form field bisa tetap di file ini; jangan pindah ke screen lagi.
- Service fee notice: tidak ada logic service fee/careful carry/ongkir. Teks harga dikonfirmasi driver sejalan dengan arah refactor bahwa harga final tidak dihitung otomatis dari fee lines.
- Risiko: perubahan file ini berdampak ke input item manual/menu dan editing item; jaga disabled state submit, quantity minimum, dan pesan bahwa harga dikonfirmasi driver.

## frontend_bangdeliv/lib/features/shopping/presentation/widgets/shopping_merchant_search_section.dart

- Fungsi: section pilih merchant untuk tambah item Nitip, termasuk search field, tombol map picker, loading/empty state, list merchant, badge tipe merchant, dan selected state.
- Logic penting: search field hanya tampil saat `canSearch`; map button hanya aktif jika callback tersedia; selected merchant dibandingkan lewat id jika ada, fallback nama+alamat untuk merchant eksternal.
- Redundansi/minimalisasi: `_isSameMerchantOption()` mirip helper pembanding merchant di `shopping_add_item_screen.dart`, tetapi versinya lebih sederhana dan tidak cek jarak. Kandidat satukan helper pembanding merchant agar selected state, edit draft, dan matching map tidak beda diam-diam.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke pemilihan merchant dan flow map picker; pastikan merchant eksternal tetap bisa terlihat selected walau tidak punya id database.

## frontend_bangdeliv/lib/features/shopping/presentation/widgets/shopping_submit_bar.dart

- Fungsi: bottom submit bar sticky untuk menyimpan draft item Nitip.
- Logic penting: tombol disabled saat `itemCount == 0` atau `isSubmitting`; menampilkan jumlah item dan total quantity; loading spinner menggantikan label tombol saat submit.
- Redundansi/minimalisasi: file kecil dan fokus. Bisa dibuat lebih reusable sebagai bottom action bar shopping, tetapi saat ini cukup spesifik untuk copy "harga dikonfirmasi driver".
- Service fee notice: tidak ada logic service fee/careful carry/ongkir. Copy harga dikonfirmasi driver relevan dengan refactor pricing manual.
- Risiko: risiko rendah; perubahan file ini berdampak ke affordance submit dan pencegahan submit kosong.

## frontend_bangdeliv/lib/features/shopping/presentation/widgets/shopping_widget_helpers.dart

- Fungsi: kumpulan helper widget dan helper merchant type untuk shopping: title section, label field, quantity stepper, badge tipe merchant, empty panel, icon/label merchant, cek resto, dan infer tipe merchant dari Google place.
- Logic penting: `shoppingMerchantTypeFromPlace()` mengklasifikasikan minimarket dari Google types/nama, fotokopi/ATK sebagai `other`, resto dari types food/resto/warung/kedai/ayam/bakso/mie; `ShoppingQuantityStepper` menjaga tombol minus disabled saat quantity <= 1.
- Redundansi/minimalisasi: file ini campuran widget presentational dan heuristic domain merchant type. Agar lebih clean, helper klasifikasi merchant bisa dipindah ke util/application shopping, sementara widget kecil tetap di presentation helpers.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan helper merchant type berdampak ke pilihan ikon/badge, keputusan load menu resto, dan payload merchant place dari map/chatbot; refactor harus menjaga hasil klasifikasi yang sudah diharapkan test/manual flow.

## frontend_bangdeliv/lib/features/tracking/application/customer_order_tracking_provider.dart

- Fungsi: provider tracking customer per order untuk fetch detail, retain realtime order, patch status/content/lokasi driver dari realtime, reconcile detail, dan auto-refresh selama order belum terminal.
- Logic penting: hanya aktif untuk session customer authenticated; `build()` retain order di `orderRealtimeHubProvider`, fetch detail, lalu auto-refresh 5 detik untuk non-terminal; event status patch summary/timeline dan schedule reconciliation 700ms; event content patch pricing/payment fields; event location patch koordinat driver; terminal order melepas retain realtime.
- Redundansi/minimalisasi: file ini mencampur lifecycle realtime, patch payload, timeline merge, reconciliation, dan polling; masih wajar sebagai provider utama, tetapi helper parsing payload pricing/timeline merge bisa dipisah kecil jika refactor tracking membesar. Pola retain/release + fallback refresh mirip customer/driver order/chat providers.
- Service fee notice: ada kandidat hapus/refactor sesuai plan, yaitu parsing `pricing['careful_carry_required']` lalu patch `carefulCarryRequired` ke summary/detail. Ini bagian frontend target cleanup Courier "Perlu 2 orang". `delivery_fee`, `delivery_fee_source`, dan `delivery_fee_change_note` bukan service fee lines dan perlu dipertahankan untuk ongkir manual driver.
- Risiko: blast radius tinggi ke `track_order_screen`, tracking map, notification focus, realtime hub retain/release, order pricing display, dan terminal status; refactor harus menjaga release order, reconciliation setelah realtime, timeline tidak mundur, dan auto-refresh berhenti di status terminal.

## frontend_bangdeliv/lib/features/tracking/application/track_order_presenter.dart

- Fungsi: presenter/helper pure untuk argumen route tracking, judul app bar, keputusan tampil map, status message, payment method normalisasi, dan ETA driver.
- Logic penting: `extractRouteArgs()` menerima `int`, `String`, atau `Map`; map tracking disembunyikan untuk terminal status; ride menunggu status trackable driver, sedangkan service non-ride lebih longgar; payment method prioritas detail lalu summary, fallback `COD`; ETA membedakan target `DROPOFF`.
- Redundansi/minimalisasi: file ini sudah clean dan testable; kandidat kecil hanya memastikan helper status label tidak menduplikasi terlalu banyak logic di `order_status.dart`/`service_type.dart`. Pertahankan sebagai layer pure agar `track_order_screen.dart` tidak makin besar.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan file ini berdampak ke judul halaman tracking/history, kapan map tampil, copy status tetap, payment label, ETA, dan test `track_order_presenter_test.dart`.

## frontend_bangdeliv/lib/features/tracking/application/tracking_focus_target.dart

- Fungsi: model/helper target fokus scroll pada tracking dari query notification, seperti payment, delivery fee, dan harga shopping per pickup location.
- Logic penting: `fromUri()` membaca query `focus`; `shopping_price` ikut parse `pickup_location_id`; `query()` membangun query string yang kompatibel dengan route notification.
- Redundansi/minimalisasi: file kecil dan fokus. Bisa dibiarkan karena menjaga kontrak route notification tetap eksplisit; jangan digabung ke screen agar parsing deep link tetap mudah dites.
- Service fee notice: tidak ada service fee lines/careful carry. `delivery_fee` di sini adalah focus target untuk ongkir manual/price notification, bukan master service fee atau fee breakdown.
- Risiko: perubahan nilai string `payment`, `delivery_fee`, atau `shopping_price` berdampak ke `FirebaseNotificationService`, test notification route, dan auto-scroll `track_order_screen.dart`; harus kompatibel dengan payload push notification backend.

## frontend_bangdeliv/lib/features/tracking/presentation/screens/track_order_screen.dart

- Fungsi: screen tracking/detail order customer untuk route langsung atau active order, dengan peta live, bottom sheet detail, layout status tetap, detail order, item Nitip, bukti foto, pembayaran QRIS/COD, revisi ongkir, chat driver, timeline, dan focus scroll dari notifikasi.
- Logic penting: route args diambil dari `TrackOrderPresenter` atau `initialOrderId`; jika tidak ada order id, fallback ke `customerActiveOrderProvider`; tracking memakai `customerOrderTrackingProvider`; focus query `payment/delivery_fee/shopping_price` diarahkan ke `GlobalKey`; map memakai `TrackingMapSection`, sedangkan status waiting/terminal memakai fixed layout; payment transfer/cancelled-with-fee bisa upload bukti QRIS dan download QRIS.
- Redundansi/minimalisasi: file 2205 baris dan masih mencampur orchestration provider, layout map/sheet, card order, payment, proof preview, QRIS upload/download, route card, timeline, dan helper teks. Kandidat clean utama adalah ekstrak payment card/action, route card, timeline card, status progress, dan proof preview ke widget/presenter kecil. `_paymentMessage()` dan `_paymentActionMessage()` sama-sama menangani courier/transfer/cancelled-with-fee sehingga bisa disatukan. Upload image proof mirip pola chat/driver proof/edit avatar.
- Service fee notice: tidak ada `service_fee`, fee lines, `fee_breakdown`, atau careful-carry di file ini. `deliveryFeeNegotiation`, `_deliveryFeeNotice()` dengan source `driver_manual`, dan focus `delivery_fee` harus dipertahankan karena itu ongkir manual driver. `CANCELLED_WITH_FEE` dan copy fee pembatalan merchant 50% juga harus dipertahankan sesuai pengecualian service fee gagal pickup 3 kali.
- Risiko: blast radius sangat tinggi ke tracking customer, notification deep link/focus scroll, peta realtime, chat unread, revisi ongkir, QRIS proof upload, cancelled-with-fee payment, shopping item management, dan tests source-based `track_order_payment_behavior_test.dart`/`chatbot_screen_test.dart`; refactor sebaiknya menunggu audit `track_order_widgets.dart` agar boundary ekstraksi tidak salah.

## frontend_bangdeliv/lib/features/tracking/presentation/widgets/track_order_widgets.dart

- Fungsi: kumpulan widget dan action untuk bagian tracking order: card item Nitip, merchant stop, harga quote per merchant, item tidak tersedia, failed merchant notice, ringkasan pricing, waiting-driver hero, delivery fee notice, route point, dan info row.
- Logic penting: `TrackShoppingOrderItemsCard` mengelompokkan active/failed shopping stops, membuka tambah/edit item, menerima/menolak quote harga merchant, hapus item, request `EDIT_UNAVAILABLE`, lanjut tanpa item, atau batal merchant; setelah action selalu invalidate `customerOrderTrackingProvider` dan `customerOrdersProvider`. `TrackWaitingDriverHeroCard`, `TrackDeliveryFeeNotice`, `TrackRoutePoint`, dan `TrackInfoRow` dipakai oleh `track_order_screen.dart`.
- Redundansi/minimalisasi: file ini masih mencampur UI card, state expand stop, action API, dialog/bottom sheet, pricing rendering, dan helper sanitasi alamat. Kandidat clean adalah pisah `TrackShoppingOrderItemsCard` action/controller dari widget presentational, dan ekstrak failed/unavailable merchant notices. `TrackDeliveryFeeNotice`, `TrackRoutePoint`, dan `TrackInfoRow` kecil dan bisa tetap sebagai shared tracking primitives.
- Service fee notice: ada kandidat hapus/refactor sesuai plan, yaitu import `shopping_fee_breakdown.dart`, render `ShoppingFeeBreakdown`, dan akses `pricing.feeBreakdown`. `pricing.serviceFee` sebagai angka tunggal masih boleh dipertahankan. Failed merchant/unavailable merchant flow dan action `CANCEL_MERCHANT` harus dijaga karena berkaitan dengan pengecualian penalti gagal pickup merchant 3 kali, bukan fee lines yang akan dihapus.
- Risiko: blast radius ke `track_order_screen.dart`, shopping add item flow, tests `track_shopping_order_items_card_test.dart`, model pricing `CustomerShoppingPricingModel`, dan backend payload shopping; refactor fee breakdown harus sinkron dengan model `customer_order_model.dart`, `driver_order_model.dart`, dan widget `shopping_fee_breakdown.dart`.

## frontend_bangdeliv/lib/models/address_location_picker_result.dart

- Fungsi: model hasil picker lokasi alamat berisi latitude, longitude, dan source.
- Logic penting: `source` default `map_pin`; dipakai saat address picker/chatbot mengembalikan titik koordinat.
- Redundansi/minimalisasi: file sudah minimal; kandidat kecil hanya jika semua location picker result ingin disatukan dengan model route/location yang lebih umum.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: risiko rendah; perubahan field berdampak ke `address_location_picker_screen.dart`, `add_address_screen.dart`, dan handler chatbot map picker.

## frontend_bangdeliv/lib/models/amount_negotiation_model.dart

- Fungsi: model generic negosiasi nominal untuk quote/revisi harga, dipakai oleh delivery fee negotiation dan shopping price negotiation.
- Logic penting: parse snake_case/camelCase dengan `ModelParseUtils`; status dinormalisasi; expose `hasQuote`, `isPendingCustomer`, `isPendingDriver`, dan `displayAmount` prioritas `approvedAmount > counterAmount > quotedAmount`; `isApproved` bisa dioverride callback caller.
- Redundansi/minimalisasi: file ini sudah tepat sebagai shared abstraction; jangan dipecah. Potensi clean kecil adalah membuat enum/status constants agar string `PENDING_CUSTOMER`, `PENDING_DRIVER`, `APPROVED` tidak tersebar.
- Service fee notice: tidak ada service fee lines/careful carry. Model ini perlu dipertahankan karena mendukung ongkir manual driver dan quote harga Nitip, bukan master service fee.
- Risiko: blast radius ke `delivery_fee_negotiation_model.dart`, `shopping_negotiation_model.dart`, driver active order fee/shopping widgets, tracking, dan tests `amount_negotiation_model_test.dart`.

## frontend_bangdeliv/lib/models/category_model.dart

- Fungsi: model kategori home/menu dengan id, nama, dan icon.
- Logic penting: `fromApiJson()` parse `id` dan `name`, tetapi `icon` masih hardcoded ke string emoji yang tampak encoding-rusak (`ðŸ½ï¸`).
- Redundansi/minimalisasi: kandidat cleanup jelas: perbaiki icon hardcoded atau ambil icon dari API/config; model juga bisa dibuat immutable dengan `final` constructor `const` jika tidak perlu mutasi.
- Service fee notice: tidak ada logic service fee/careful carry.
- Risiko: perubahan berdampak ke `home_api_service.dart`, `home_data_model.dart`, dan tampilan kategori home; cek encoding asset/icon agar tidak muncul karakter rusak di UI.

## frontend_bangdeliv/lib/models/chatbot_model.dart

- Fungsi: kumpulan model response chatbot untuk intent, item order, draft Nitip multi-stop, pricing, validation, hasil order, session summary, history message, dan history page.
- Logic penting: `ChatbotResult.fromApiJson()` membaca envelope `data`, `service_context`, `validation`, `action_payloads`, `shopping`, `pricing`, dan `order`; `toAssistantText()` membuat fallback teks assistant untuk courier, Nitip, out-of-domain, validasi gagal, matched/unmatched item, dan normalisasi label `Ongkir:` menjadi `Estimasi ongkir sementara:`.
- Redundansi/minimalisasi: file ini cukup besar karena semua model chatbot disatukan; masih wajar untuk kontrak API chatbot, tetapi bisa dipisah per domain jika bertambah. Ada beberapa string fallback dengan karakter encoding rusak seperti `â€¢`, perlu dibersihkan bersama audit encoding kategori. Parsing list/map manual berulang bisa pakai helper parse kecil jika model lain juga butuh pola sama.
- Service fee notice: `ChatbotPricing.serviceFee` adalah angka tunggal dan masih boleh dipertahankan. Kandidat review/hapus sesuai plan adalah `ChatbotShoppingItem.isHeavy` dari `is_heavy` jika field ini hanya dipakai backend untuk overweight surcharge otomatis. Tidak ada `fee_breakdown`, fee lines, atau careful-carry di file ini.
- Risiko: perubahan file ini berdampak ke `chatbot_api_service.dart`, `chatbot_repository.dart`, `chatbot_conversation_provider.dart`, `chatbot_screen.dart`, dan tests chatbot; jaga kompatibilitas response backend, session history payload, action hints, dan copy fallback chatbot.

## frontend_bangdeliv/lib/models/customer_order_model.dart

- Fungsi: model kontrak order customer untuk summary, detail tracking, ETA driver, proof, item/stop Nitip, merchant, pricing, dan fee breakdown.
- Logic penting: parse service/status/route/distance/payment/delivery fee/manual driver fee, order locations, timeline, shopping stops/items, proofs, negotiation, capabilities, unavailable item request; pricing parse subtotal, delivery fee, service fee, total, cancellation penalty, dan failed attempts.
- Redundansi/minimalisasi: file sangat besar dan memuat banyak domain sekaligus; kandidat pecah ke `customer_order_summary_model.dart`, `customer_order_detail_model.dart`, `customer_shopping_models.dart`, dan `customer_order_proof_model.dart`. Parsing snake/camel dan helper `_firstNonEmptyString`/numeric/date mirip dengan model lain, jadi bisa dibersihkan setelah kontrak API stabil.
- Service fee notice: kandidat hapus/refactor sesuai plan adalah `carefulCarryRequired` di summary/detail, `CustomerShoppingItemModel.isHeavy` jika hanya untuk overweight surcharge, `itemSurcharge`, `overweightSurcharge`, `feeBreakdown`, `_parseFeeBreakdown`, `CustomerShoppingFeeBreakdownModel`, fallback `ITEM_BLOCK_SURCHARGE`, `OVERWEIGHT_FLAT_SURCHARGE`, dan payload `fee_breakdown`. Pertahankan `serviceFee` sebagai angka tunggal, `deliveryFee`/manual delivery fee, serta `cancellationPenalty`, failed attempt fields, `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS`, dan proof `store_closed` karena itu jalur penalti gagal pickup merchant 3 kali.
- Risiko: blast radius tinggi ke tracking, activity/history cards, shopping add item, `customer_order_tracking_provider.dart`, tests tracking/shopping/model, backend payload customer order, dan pasangan model `driver_order_model.dart`; refactor fee breakdown harus sinkron dengan widget `shopping_fee_breakdown.dart` dan backend sebelum field dihapus.

## frontend_bangdeliv/lib/models/delivery_fee_negotiation_model.dart

- Fungsi: wrapper model negosiasi ongkir yang membungkus `AmountNegotiationModel` dan menambah konteks `oldDeliveryFee` serta flag `carefulCarryRequired`.
- Logic penting: semua status, quote, counter, approved amount, note, updatedAt, dan capability customer/driver diteruskan langsung dari `amount`; `fromRaw()` parse raw map memakai `ModelParseUtils` untuk `old_delivery_fee` dan `careful_carry_required`.
- Redundansi/minimalisasi: file sudah minimal dan tepat sebagai adapter domain delivery fee; tidak perlu dipisah. Delegasi getter cukup banyak, tetapi masih menjaga caller tidak perlu tahu struktur `AmountNegotiationModel`.
- Service fee notice: `carefulCarryRequired` adalah kandidat hapus/refactor sesuai plan Courier "Perlu 2 orang". `amount` dan `oldDeliveryFee` harus dipertahankan karena ini mendukung negosiasi ongkir/manual delivery fee, bukan `service_fee_lines`.
- Risiko: perubahan berdampak ke `customer_order_model.dart`, `driver_order_model.dart`, driver active order fee widgets, tracking reconciliation, service/repository driver order, dan test `amount_negotiation_model_test.dart`; hapus `carefulCarryRequired` harus satu paket dengan cleanup backend payload/parameter.

## frontend_bangdeliv/lib/models/driver_order_model.dart

- Fungsi: model kontrak driver untuk incoming/running order, detail aktif, proofs, action, timeline, item/stop Nitip, merchant, pricing, history order, dispatch metadata, dan payload daftar order.
- Logic penting: `DriverOrderModel.fromJson()` parse snake/camel payload, normalisasi service/status, route/distance, delivery fee/source, shopping pricing, delivery/shopping negotiation, capabilities, item change request, fee breakdown top-level, proof termasuk `store_closed`, dan dispatch. Pricing Nitip menghitung fallback `serviceFee` dari fee breakdown jika `service_fee` kosong.
- Redundansi/minimalisasi: file sangat besar dan banyak duplikasi struktur dengan `customer_order_model.dart`; kandidat pecah ke `driver_order_core_model.dart`, `driver_shopping_models.dart`, `driver_order_proof_model.dart`, `driver_order_history_model.dart`, dan `driver_dispatch_model.dart` setelah refactor fee stabil. Field `manualDeliveryFee`/`manualDeliveryFeeReason` ada di model/copyWith tetapi `fromJson()` selalu null, jadi perlu ditinjau apakah masih dibutuhkan.
- Service fee notice: kandidat hapus/refactor sesuai plan adalah `carefulCarryRequired` top-level/pricing snapshot, `DriverShoppingItemModel.isHeavy` jika hanya untuk overweight surcharge, `itemSurcharge`, `overweightSurcharge`, `feeBreakdown` top-level/pricing, `_parseTopLevelFeeBreakdown`, `DriverShoppingFeeBreakdownModel`, fallback `ITEM_BLOCK_SURCHARGE`, `OVERWEIGHT_FLAT_SURCHARGE`, dan payload `fee_breakdown`. Pertahankan `serviceFee` scalar, `deliveryFee`, `DeliveryFeeNegotiationModel`, history `serviceFee`, serta `cancellationPenalty`, failed attempt fields, `canCancelWithFee`, `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS`, dan proof `store_closed` karena itu jalur penalti gagal pickup merchant 3 kali.
- Risiko: blast radius sangat tinggi ke driver provider/realtime, driver active order UI, meta/fee/shopping/proof widgets, driver history/detail, Pusher order available, tests model/provider/widget, backend payload driver order, dan pasangan customer model; cleanup harus sinkron dengan service/repository `careful_carry_required` serta UI `ShoppingFeeBreakdown`.

## frontend_bangdeliv/lib/models/driver_verification_model.dart

- Fungsi: model status verifikasi driver berisi data driver dan dokumen verifikasi.
- Logic penting: `DriverVerificationStatusModel.fromJson()` parse `driver` dan list `documents`; `documentByType()` mencari dokumen case-insensitive; document punya `displayName` untuk `ktp`, `sim`, dan `selfie`; helper lokal parse int/date/null string.
- Redundansi/minimalisasi: file cukup minimal. Helper `_asInt`, `_parseDateTime`, dan `_nullableString` mirip helper parse model lain; bisa diganti `ModelParseUtils` kalau ingin konsistensi. `fileUrl` belum lewat `AppEnv.resolveBackendAssetUrl`, perlu cek apakah backend selalu kirim absolute URL.
- Service fee notice: tidak ada logic service fee, fee lines, failed pickup penalty, atau careful-carry.
- Risiko: perubahan berdampak ke `driver_verification_service.dart` dan `driver_verification_status_screen.dart`; hati-hati pada nama document type karena dipakai untuk mapping UI/upload status.

## frontend_bangdeliv/lib/models/food_model.dart

- Fungsi: model menu/food untuk home dan detail menu.
- Logic penting: `fromApiJson()` parse id, name, description, restaurant name dari caller, price, image backend asset URL, dan getter `formattedPrice`.
- Redundansi/minimalisasi: file kecil; `_toDouble()` duplikat dengan `MerchantModel` dan model lain. Constructor bisa dibuat `const` jika tidak ada alasan runtime khusus.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke `home_api_service.dart`, `menu_detail_screen.dart`, router extra `FoodModel`, dan tampilan popular menu/home.

## frontend_bangdeliv/lib/models/home_data_model.dart

- Fungsi: aggregate model data home berisi kategori, popular menus, dan nearby merchants.
- Logic penting: hanya container immutable list tanpa parsing; parsing dilakukan di `home_api_service.dart`.
- Redundansi/minimalisasi: sudah minimal; tidak perlu refactor kecuali nanti ingin memindahkan factory parse home payload ke model.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: rendah; perubahan field berdampak ke `homeDataProvider`, `home_screen.dart`, dan `nearby_merchants_screen.dart`.

## frontend_bangdeliv/lib/models/merchant_model.dart

- Fungsi: model merchant ringkas untuk home, nearby merchant, dan detail merchant awal.
- Logic penting: `fromApiJson()` parse id, name, banner image backend asset URL, dan distance label dari `distance_km`.
- Redundansi/minimalisasi: `_toDouble()` duplikat dengan `FoodModel`; distance disimpan sebagai string display sehingga nilai numerik tidak tersedia untuk sort/filter lanjutan. Constructor bisa dibuat `const` jika tidak ada kebutuhan mutasi.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke `home_api_service.dart`, `home_screen.dart`, `nearby_merchants_screen.dart`, `merchant_detail_screen.dart`, dan router extra `MerchantModel`.

## frontend_bangdeliv/lib/models/order_chat_model.dart

- Fungsi: model chat order untuk message, page list, unread summary, dan hasil kirim pesan/attachment.
- Logic penting: `OrderChatMessageModel.fromJson()` parse sender, body, client message id, timestamp backend, attachment dari `attachments` pertama atau `attachment`, lalu resolve URL asset; page parse envelope `data.messages`, pagination, `can_send`, unread count, dan last read id; send/unread result parse envelope `data`.
- Redundansi/minimalisasi: helper `_asInt` ada di `OrderChatMessageModel` dan `OrderChatMessagesPage`; parsing envelope `data` berulang di page/unread/send. Attachment saat ini hanya mengambil attachment pertama, cocok jika chat hanya single attachment tetapi perlu dicatat jika backend nanti multi attachment.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: blast radius ke `order_chat_api_service.dart`, `order_chat_provider.dart`, unread provider, realtime/Pusher chat, heads-up notification, `order_chat_screen.dart`, dan tests order chat; jangan ubah merge key `clientMessageId`/server `id` tanpa audit provider.

## frontend_bangdeliv/lib/models/order_model.dart

- Fungsi: model order lama/sederhana berisi id, restaurant name, items, price, date, status, dan getter formatted price.
- Logic penting: tidak ada parsing API; hanya data holder dan `formatRupiah(price)`.
- Redundansi/minimalisasi: hasil `rg` hanya menemukan definisi `OrderModel` di file ini, jadi kandidat legacy unused untuk dihapus setelah konfirmasi tidak dipakai di import tersembunyi/generated. Komentar status masih hardcoded `Selesai/Diantar/Dibatalkan` dan tidak memakai constants status order baru.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: rendah jika benar unused; cek test/route lama sebelum hapus agar tidak memutus mock atau screen historis yang belum diaudit.

## frontend_bangdeliv/lib/models/order_route_model.dart

- Fungsi: model rute order/shopping route berisi urutan pickup location id, polyline, jarak meter/km, dan label jarak.
- Logic penting: `fromJson()` parse snake/camel distance dan fallback km dari meter; `fromRaw()` bisa membaca route langsung atau fallback dari `shoppingOrder.pricing_snapshot.shopping_route`.
- Redundansi/minimalisasi: file sudah fokus dan dipakai customer/driver model. Helper parse int/double/text lokal mirip helper model lain, tetapi masih wajar karena model kecil.
- Service fee notice: tidak ada logic service fee/careful-carry; fallback dari `pricing_snapshot` hanya untuk route/distance, bukan fee lines.
- Risiko: perubahan berdampak ke `customer_order_model.dart`, `driver_order_model.dart`, route visualizer/map/tracking, dan tests `order_route_model_test.dart`.

## frontend_bangdeliv/lib/models/route_location_picker_result.dart

- Fungsi: argumen dan hasil route picker untuk dua titik lokasi seperti pickup dan destination/dropoff.
- Logic penting: `RouteLocationPickerArgs` membawa label, target, title, confirm label, default pickup, dan initial coordinate/address; `RouteLocationPickerResult` mengembalikan list `RouteLocationPickerPoint`.
- Redundansi/minimalisasi: file sudah minimal. Strukturnya mirip `AddressLocationPickerResult` tetapi route picker memang butuh multi-point dan metadata lebih banyak, jadi jangan digabung paksa.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke `route_location_picker_screen.dart`, `chatbot_screen.dart`, router extra, dan tests route picker.

## frontend_bangdeliv/lib/models/shopping_negotiation_model.dart

- Fungsi: model negosiasi harga belanja/Nitip per order dan per merchant.
- Logic penting: membungkus `AmountNegotiationModel`, parse `merchant_quotes`, `approvedSubtotal`, `allRequiredQuotesApproved`, `checkoutAllowed`; `isApproved` dianggap true jika amount approved atau checkout allowed; `quoteForPickup()` cari quote per pickup location.
- Redundansi/minimalisasi: delegasi getter ke `amount` cukup banyak seperti `DeliveryFeeNegotiationModel`, tetapi menjaga caller tetap sederhana. Struktur merchant quote sudah tepat untuk multi-stop Nitip.
- Service fee notice: tidak ada service fee lines/careful-carry. Model ini harus dipertahankan karena terkait quote/subtotal belanja manual, bukan master service fee; jangan disamakan dengan ongkir/service fee cleanup.
- Risiko: perubahan berdampak ke `driver_order_model.dart`, `customer_order_model.dart`, driver shopping widgets, tests action/loading dan amount negotiation; hati-hati pada semantik `checkoutAllowed` karena dipakai sebagai approval override.

## frontend_bangdeliv/lib/models/shopping_order_capability_model.dart

- Fungsi: model capability/action gate untuk flow Nitip: edit item customer, add stop, unavailable item, failed merchant, driver mark merchant, quote, upload receipt, dan pending item change request.
- Logic penting: `ShoppingOrderCapabilitiesModel.fromRaw()` parse capability snake/camel dan set `isExplicit`; `ShoppingItemChangeRequestModel.fromRaw()` parse request log, action/kind, target pickup, item list, requested stops, note, updatedAt, dan driver response capability; stop/item request model parse merchant place dan item metadata.
- Redundansi/minimalisasi: file mulai besar karena mencampur capabilities dan item-change request payload. Kandidat split ringan ke `shopping_capabilities_model.dart` dan `shopping_item_change_request_model.dart` jika file bertambah, tetapi saat ini masih koheren.
- Service fee notice: tidak ada fee lines/careful-carry. `canCustomerResolveFailedMerchant` dan `canDriverMarkMerchantClosed` harus dipertahankan karena terkait flow merchant gagal/pickup failed yang menjadi pengecualian service fee plan, bukan target hapus.
- Risiko: blast radius ke customer/driver order models, driver shopping widgets, tracking item widgets, `bang_shopping_merchant_request_summary.dart`, repository/service mutation response, dan tests shopping/driver action.

## frontend_bangdeliv/lib/models/user_profile_model.dart

- Fungsi: model profil user, driver profile, statistik user, dan saved address.
- Logic penting: `UserProfileModel.fromJson()` parse driver profile, stats, dan addresses; `SavedAddressModel.displayAddress` membersihkan suffix `Indonesia` lalu menambahkan detail; address menyimpan latitude/longitude default 0.
- Redundansi/minimalisasi: helper parse `_asInt/_asDouble/_asNullableString` berulang di beberapa class; bisa diganti `ModelParseUtils` setelah audit model selesai. `avatarUrl` belum di-resolve via `AppEnv.resolveBackendAssetUrl`; perlu cek apakah backend selalu absolute.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: blast radius ke auth session, profile/driver profile, register driver, saved address, home active address, chatbot address guard/default address, providers tests; perubahan coordinate default harus hati-hati karena address readiness memakai koordinat.

## frontend_bangdeliv/lib/services/api_client.dart

- Fungsi: HTTP client JSON umum untuk GET/POST/PATCH/DELETE dengan base URL `AppEnv.apiBaseUrl`, timeout, default JSON headers, decode response, dan `ApiException`.
- Logic penting: `_buildUri()` menggabungkan base path `/api` dengan path relatif dan query params; `_decodeResponse()` wajib response berupa `Map<String, dynamic>`, return untuk 2xx, selain itu ambil `message` lalu throw `ApiException`.
- Redundansi/minimalisasi: method HTTP punya pola try/timeout/client exception yang berulang; bisa diekstrak ke helper private `_sendJson()`. `jsonDecode`/format response invalid di luar `http.ClientException` belum ditangkap sebagai pesan API yang konsisten.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: dipakai banyak service (`home`, `chatbot`, `customer_order`, `order_chat`, `ride_order`, `device_token`); perubahan header, timeout, URI builder, atau format error berdampak luas dan perlu test request path.

## frontend_bangdeliv/lib/services/api_exception.dart

- Fungsi: exception umum API dengan `message`, optional `statusCode`, dan `toString()` ringkas.
- Logic penting: kalau `statusCode` null return message saja, kalau ada return `[$statusCode] message`.
- Redundansi/minimalisasi: sudah minimal. Potensi konsolidasi jangka panjang dengan `AuthException` dan `DriverOrderApiException`, tapi jangan dipaksa sebelum error handling service diseragamkan.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: rendah, tetapi banyak provider/UI mengecek tipe `ApiException`; perubahan nama/tipe bisa memutus error mapping.

## frontend_bangdeliv/lib/services/auth_service.dart

- Fungsi: service statis untuk auth/profile/address/password/session token, termasuk register customer, upgrade driver, login/logout, profile update avatar multipart, saved address CRUD, address validation, token secure storage, dan parsing error auth.
- Logic penting: akses token disimpan di `FlutterSecureStorage` dengan migrasi token legacy dari `SharedPreferences`; `authorizedHeaders()` menjadi sumber bearer token untuk service lain; `_normalizeAvatarUrl()` memperbaiki URL avatar relatif/loopback; `_extractErrorMessage()` membaca `message`/`errors` dan menyembunyikan raw SQL error.
- Redundansi/minimalisasi: file besar dan masih memakai `http` langsung, timeout, URI, decode, error handling, dan profile parsing manual padahal sudah ada `ApiClient`. Kandidat refactor bertahap: pindahkan address CRUD ke service/repository khusus, pakai `ApiClient` untuk JSON request non-multipart, satukan `AuthException`/`ApiException` mapping, dan pindahkan `AddressValidationResult` ke model/address domain jika dipakai lebih luas.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: blast radius tinggi ke login/register, auth session bootstrap, profile/edit profile, change password, saved address screens, address repository, driver verification/order/chat services via `authorizedHeaders()` dan `extractErrorMessage()`. Refactor harus kecil per endpoint karena menyentuh token/session behavior.

## frontend_bangdeliv/lib/services/chatbot_api_service.dart

- Fungsi: service API chatbot untuk kirim pesan, list session, history session, patch lokasi tunggal, patch lokasi route, patch merchant, dan clear session.
- Logic penting: semua request memakai `ApiClient` + `AuthService.authorizedHeaders()`; `sendMessage()` kirim `message/service_type/session_id`; patch route memakai `ChatbotLocationPatchRequest.toJson()`; patch merchant memakai `ShoppingMerchantPlacePayload` dari customer order service; response status `error` diubah ke `ApiException`.
- Redundansi/minimalisasi: pola `try AuthException -> ApiException`, validasi session kosong, dan cek `status == error` berulang di beberapa method. Kandidat helper kecil untuk request authenticated chatbot dan error status. Ada coupling aneh ke `customer_order_api_service.dart` hanya untuk `ShoppingMerchantPlacePayload`; DTO merchant place sebaiknya dipindah ke model/shared shopping.
- Service fee notice: tidak ada service fee lines/careful-carry. `service_type` hanya konteks layanan chatbot dan tidak menjadi target cleanup service fee.
- Risiko: blast radius ke `chatbot_repository.dart`, `chatbot_conversation_provider.dart`, `chatbot_screen.dart`, shopping merchant picker, session history, dan tests chatbot; ubah payload harus sinkron backend chatbot.

## frontend_bangdeliv/lib/services/customer_order_api_service.dart

- Fungsi: service API customer order untuk list/detail order, upload bukti transfer, cancel order, tambah/edit/hapus item Nitip, request item change, search merchant/menu, respond quote harga belanja, dan respond delivery fee override.
- Logic penting: list/detail memakai endpoint `/v1/orders`; mutation item memakai `_shoppingItemRequest()` untuk cek `success` dan extract `data`; upload transfer evidence memakai `MultipartRequest`; DTO lokal `ShoppingMerchantOption`, `ShoppingMerchantPlacePayload`, `ShoppingItemDraftPayload`, dan `ShoppingMenuOption` memegang kontrak shopping add item/merchant map.
- Redundansi/minimalisasi: `_buildUri()` dan multipart decode/error handling menduplikasi pola `ApiClient`/service lain. DTO shopping cukup besar dan dipakai lintas chatbot/shopping UI, jadi kandidat pindah ke `models/shopping_*` agar service tidak menjadi tempat model domain. `addShoppingItem()` hanya wrapper tipis ke `addShoppingItems()`.
- Service fee notice: tidak ada `service_fee`, `fee_breakdown`, item surcharge, overweight surcharge, atau careful-carry. `respondDeliveryFeeOverride()` dan `counter_amount` adalah flow ongkir/manual delivery fee yang harus dipertahankan, bukan target hapus service fee lines.
- Risiko: blast radius ke `customer_order_repository.dart`, tracking screen/widgets, shopping add item flow, chatbot merchant picker payload, transfer payment behavior, tests shopping/customer order; refactor DTO perlu update banyak import.

## frontend_bangdeliv/lib/services/device_token_api_service.dart

- Fungsi: service API untuk register dan unregister device token push notification.
- Logic penting: `registerDeviceToken()` POST `/v1/device-tokens` dengan `token/device_type`; `unregisterDeviceToken()` DELETE endpoint sama dengan body token; keduanya authenticated dan timeout 10 detik.
- Redundansi/minimalisasi: file sudah minimal. Pola `AuthException -> ApiException` sama seperti service lain dan bisa ikut helper umum jika nanti dibuat.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke firebase notification bootstrap/device token sync; pastikan unregister tetap best-effort jika dipanggil saat logout atau token refresh.

## frontend_bangdeliv/lib/services/driver_order_service.dart

- Fungsi: service API driver untuk accept/reject order, detail/list/history, transisi status, COD/QRIS, ongkir manual, lokasi driver, proof upload, checkout/item/quote Nitip, merchant open/failed, availability, dan lokasi standby.
- Logic penting: memakai `http.Client` langsung dengan helper `_get/_safeGet/_post/_patch/_multipart`; mutation response dibaca lewat `_orderFromMutationResponse()` dengan fallback `fetchOrderDetail()` jika data order kosong; `recordShoppingPickupFailed()` POST `/v1/orders/{id}/attempt-failed` lalu refresh detail; error memakai `DriverOrderApiException`.
- Redundansi/minimalisasi: banyak duplikasi dengan `ApiClient` dan service lain untuk URI builder, JSON decode, timeout, auth header, multipart, dan error mapping. `transitionOrderStatus()` dan `transitionStatus()` overlap endpoint. Kandidat refactor bertahap: pakai helper request internal tunggal atau `ApiClient` untuk JSON request, pertahankan multipart khusus, dan konsolidasikan exception jika aman.
- Service fee notice: kandidat hapus/refactor sesuai plan adalah parameter/body `carefulCarryRequired` dan payload `careful_carry_required` di `updateDeliveryFeeOverride()`. Pertahankan `delivery-fee-override`, `accept-counter`, dan `amount/reason` karena ini flow ongkir/manual delivery fee. Pertahankan `recordShoppingPickupFailed()` dan endpoint `attempt-failed` karena terkait merchant gagal/pickup failed dan penalti 50% setelah 3 kali.
- Risiko: blast radius sangat tinggi ke `driver_order_repository.dart`, `driver_order_providers.dart`, active order screen, fee/shopping/proof widgets, realtime/list/history tests, dan backend driver endpoints; cleanup `careful_carry_required` harus sinkron dengan model, repository, provider, widget, dan backend.

## frontend_bangdeliv/lib/services/driver_verification_service.dart

- Fungsi: service statis untuk mengambil status verifikasi driver dan upload dokumen KTP/SIM/selfie.
- Logic penting: `fetchMyStatus()` GET `/v1/driver/verification`; `submitDocuments()` validasi minimal satu file lalu multipart POST `/v1/driver/verification/documents`; `_parseStatus()` decode envelope `data` ke `DriverVerificationStatusModel`; error memakai `DriverVerificationException` dan `AuthService.extractErrorMessage()`.
- Redundansi/minimalisasi: masih memakai `http` langsung dan static methods seperti `AuthService`, sehingga decode/error/multipart pattern duplikat dengan service lain. Kandidat clean kecil: pindahkan ke injectable service kalau ingin testability setara `DriverOrderService`, dan gunakan helper multipart/request umum jika sudah dibuat.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke `driver_verification_status_screen.dart`, dokumen upload, dan model verifikasi; jangan ubah field multipart `ktp`, `sim`, `selfie` tanpa sinkron backend.

## frontend_bangdeliv/lib/services/firebase_notification_service.dart

- Fungsi: service Firebase Cloud Messaging dan local notification untuk init Firebase, permission/token sync, route dari payload notifikasi, foreground local notification, dedupe notification key, dan tap navigation.
- Logic penting: background handler init Firebase; `routeForNotificationData()` menerima tipe `order_chat_message`, `order_status_changed`, `order_price_changed`, dan `payment_proof_required`; route bisa eksplisit dari payload atau dibangun dari `order_id`; local channel dipisah chat/status; token backend dihindari double-register dengan `_registeredUserId/_registeredToken`.
- Redundansi/minimalisasi: file besar dan static state cukup banyak; bisa dipisah antara token sync, route mapper, dan local notification renderer. `_trackingFocusFromNotificationData()` memakai `changeType.contains('FEE')` yang luas dan bisa dibuat lebih eksplisit jika backend payload sudah stabil.
- Service fee notice: tidak ada service fee lines/careful-carry. `order_price_changed`, `TrackingFocusTarget.deliveryFee`, dan `change_type DELIVERY_FEE/FEE` adalah routing notifikasi harga/ongkir yang perlu dipertahankan; jangan dihapus sebagai bagian cleanup `service_fee_lines`.
- Risiko: blast radius ke app bootstrap, realtime notification provider, chat heads-up, navigation pending route, Firebase token sync, tests notification route; perubahan payload route harus sinkron backend FCM data.

## frontend_bangdeliv/lib/services/google_maps_lookup_service.dart

- Fungsi: service lookup Google Maps untuk autocomplete, place details, geocode query, reverse geocode, dan pembersihan alamat.
- Logic penting: mendukung scope Indonesia dan Salatiga service area; autocomplete memakai `components=country:id`, optional session token, radius/strictbounds untuk Salatiga; resolve place mencoba place id dulu lalu fallback geocode; reverse geocode tidak di-scope agar pin/GPS bebas; result menyimpan place id, name, address, types, dan `LatLng`.
- Redundansi/minimalisasi: sudah cukup fokus. Ada silent catch yang mengembalikan null/list kosong sehingga debugging API key/quota bisa sulit; pertimbangkan debug log ringan. Model `GoogleMapsPrediction/ResolvedPlace` bisa dipindah ke models jika makin sering dipakai lintas layer.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: berdampak ke address picker, route picker, shopping merchant map picker, dan tests maps lookup; perubahan scope/bounds bisa mengubah hasil lokasi user.

## frontend_bangdeliv/lib/services/home_api_service.dart

- Fungsi: service home untuk mengambil kategori, menu populer, dan merchant terdekat dari `/v1/home` dengan fallback legacy endpoints.
- Logic penting: `fetchHomeData()` kirim search/latitude/longitude/limit; jika `/v1/home` 404, `_fetchFromLegacyEndpoints()` ambil `/v1/restaurants`, lalu sampling menu dari beberapa restaurant untuk membangun categories dan popular menus.
- Redundansi/minimalisasi: fallback legacy cukup kompleks dan dapat dihapus jika backend `/v1/home` sudah pasti ada. `_extractList()` lokal sama seperti service lain. Mapping kategori/menu/merchant tersebar di model masing-masing dan sudah cukup bersih.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: berdampak ke home provider, home screen, nearby merchants, menu/merchant detail seed data; menghapus fallback perlu pastikan backend TA selalu menyediakan `/v1/home`.

## frontend_bangdeliv/lib/services/notification_navigation_service.dart

- Fungsi: service queue route dari tap notifikasi, disimpan di memory dan `SharedPreferences`.
- Logic penting: `queueRoute()` normalisasi lalu simpan route; `takePendingRoute()` mengambil memory/prefs lalu clear; `normalizeRoute()` hanya mengizinkan route order chat, order track, dan driver active order dengan id positif, termasuk query string.
- Redundansi/minimalisasi: file sudah minimal. Regex whitelist route hardcoded perlu diupdate jika route notifikasi baru ditambah.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: berdampak ke Firebase notification bootstrap dan tests notification; route whitelist terlalu ketat bisa membuat notifikasi baru tidak membuka layar.

## frontend_bangdeliv/lib/services/order_chat_api_service.dart

- Fungsi: service API chat order untuk fetch messages, send text, upload attachment, fetch unread, dan mark read.
- Logic penting: JSON request memakai `ApiClient` + `AuthService.authorizedHeaders()`; attachment memakai `MultipartRequest` langsung; semua response wajib `success == true`; result diparse ke `OrderChatMessagesPage`, `OrderChatSendResult`, atau `OrderChatUnreadSummary`.
- Redundansi/minimalisasi: pola `AuthException -> ApiException` dan `success` check berulang; multipart upload menduplikasi `_buildUri`/decode/error pattern service lain. Bisa dibuat helper request chat kecil atau helper multipart umum setelah audit service selesai.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: blast radius ke order chat provider, unread provider, order chat screen, realtime chat merge/read state, and tests order chat; jangan ubah `client_message_id` contract tanpa audit optimistic message merge.

## frontend_bangdeliv/lib/services/pusher_service.dart

- Fungsi: client realtime Laravel Reverb/Pusher untuk channel private order tracking dan driver orders.
- Logic penting: connect WebSocket ke `AppEnv` Reverb, auth private channel lewat `/broadcasting/auth`, retain/release channel dengan ref count, reconnect bertahap, idle disconnect, decode protocol/event payload, lalu dispatch driver location, order status, order content update, chat message, driver order available, dan driver order removed.
- Redundansi/minimalisasi: file sangat besar dan memuat connection lifecycle, channel retain, auth, protocol parsing, event matching, payload parsing, dan mapped subscription. Kandidat split setelah stabil: connection/protocol client, channel manager, dan event payload mapper. Helper parse `_asDouble/_asInt/_asBool` duplikat dengan model utils, tetapi masih lokal untuk payload realtime.
- Service fee notice: tidak ada parsing langsung `service_fee`, `fee_breakdown`, atau `careful_carry`. Event `driver.order.available` memakai `DriverOrderModel.fromJson()`, jadi cleanup fee/careful-carry dilakukan di model/service driver, bukan di sini. `OrderContentUpdated` payload harus tetap diteruskan karena bisa membawa perubahan harga/ongkir yang masih valid.
- Risiko: blast radius tinggi ke `order_realtime_hub_provider.dart`, `driver_order_providers.dart`, order chat provider/unread, fake realtime tests, Reverb auth config, dan semua realtime update order; refactor harus menjaga reconnect/resubscribe behavior.

## frontend_bangdeliv/lib/services/qris_download_service.dart

- Fungsi: service download QRIS dari asset URL dan simpan ke galeri Android lewat MethodChannel.
- Logic penting: download `PaymentAssets.qrisUrl` dengan timeout 20 detik, validasi status 2xx dan bytes tidak kosong, simpan via channel `bangdeliv/gallery` method `saveImageToGallery`, serta generate default filename timestamp.
- Redundansi/minimalisasi: file sudah fokus dan cukup minimal. `mimeType` hardcoded `image/jpeg`; cocok jika asset QRIS selalu JPEG, tetapi perlu disesuaikan jika file berubah PNG.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: berdampak ke tracking/payment proof screen dan native Android method channel; perubahan nama channel/method harus sinkron native code.

## frontend_bangdeliv/lib/services/ride_order_api_service.dart

- Fungsi: service API untuk validasi alamat tujuan dan membuat order Antar Jemput.
- Logic penting: `validateDestinationAddress()` POST `/v1/orders/ride/validate-destination`; `createRideOrder()` POST `/v1/orders/ride` dengan `address_id` dan `destination_address`; response sukses diparse ke validation result atau submission result berisi `orderId`, `orderNumber`, dan `deliveryFee`.
- Redundansi/minimalisasi: file kecil dan cukup bersih. Pola `AuthException -> ApiException` dan `success` check sama dengan service lain; result model bisa dipindah ke `models` jika ride flow membesar.
- Service fee notice: tidak ada service fee lines/careful-carry. `deliveryFee` di `RideOrderSubmissionResult` adalah ongkir hasil order dan harus dipertahankan.
- Risiko: berdampak ke ride/antar-jemput order creation flow dan router/tracking setelah order dibuat; perubahan kontrak response harus sinkron backend.

## frontend_bangdeliv/lib/utils/address_readiness.dart

- Fungsi: helper validasi saved address usable dan memilih default usable address.
- Logic penting: alamat usable jika `fullAddress` terisi, lat/lng dalam range, dan bukan 0,0; `defaultUsableSavedAddress()` pilih address default yang usable, fallback first usable.
- Redundansi/minimalisasi: sudah minimal; bisa reuse helper ini lebih luas agar validasi alamat tidak tersebar.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke chatbot address guard/default address, home active address, dan add/saved address flow.

## frontend_bangdeliv/lib/utils/app_time.dart

- Fungsi: helper waktu WIB dan parsing timestamp backend.
- Logic penting: semua DateTime diformat/diubah via UTC+7; date-only dianggap tanggal WIB; timestamp tanpa timezone dianggap UTC; `toBackendWibIsoString()` kirim format `+07:00`.
- Redundansi/minimalisasi: sudah fokus; hati-hati karena banyak model memakai `parseBackendDateTime()` via export `order_formatters.dart`.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak luas ke timeline/status/order/chat/ETA dan tests `app_time_test.dart`.

## frontend_bangdeliv/lib/utils/courier_package_formatter.dart

- Fungsi: presenter kecil detail paket kurir dari `DriverOrderModel`.
- Logic penting: `isCourier` berdasarkan normalized service type courier; description dari `packageDescription`.
- Redundansi/minimalisasi: sudah minimal; bisa digabung ke presenter driver meta jika hanya dipakai satu tempat, tapi tidak wajib.
- Service fee notice: tidak ada careful-carry/service fee; ini hanya deskripsi paket kurir dan harus tidak ikut terhapus.
- Risiko: perubahan berdampak ke UI detail paket kurir dan tests `courier_package_formatter_test.dart`.

## frontend_bangdeliv/lib/utils/currency_formatter.dart

- Fungsi: formatter rupiah sederhana tanpa spasi.
- Logic penting: round nilai, titik per 3 digit, negative `-Rp`.
- Redundansi/minimalisasi: ada duplikasi konsep dengan `order_formatters.formatCurrency()` yang menghasilkan `Rp 1.000` dengan spasi; kandidat konsolidasi format rupiah agar UI konsisten.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan format berdampak ke banyak tampilan nominal dan snapshot/golden tests jika ada.

## frontend_bangdeliv/lib/utils/currency_input_parser.dart

- Fungsi: parser input uang dengan menghapus semua non-digit.
- Logic penting: input kosong menjadi 0; desimal tidak dipertahankan.
- Redundansi/minimalisasi: sudah minimal; cocok untuk Rupiah integer. Jika nanti nominal desimal diperlukan, parser ini perlu diperluas.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke form ongkir/quote/nominal manual yang memakai parser ini.

## frontend_bangdeliv/lib/utils/map_marker_icons.dart

- Fungsi: generator marker bitmap driver motor untuk Google Maps.
- Logic penting: gambar marker custom dengan Canvas, pixel ratio, warna brand, icon two wheeler, fallback marker orange jika bytes kosong.
- Redundansi/minimalisasi: sudah fokus; bisa cache `BitmapDescriptor` per size agar tidak render ulang jika dipanggil sering.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke tampilan tracking map; perlu visual check marker desktop/mobile kalau diubah.

## frontend_bangdeliv/lib/utils/model_parse_utils.dart

- Fungsi: helper parse umum untuk status/text/bool/int/double/date.
- Logic penting: normalize status uppercase, optional text menghapus kosong/`-`, bool menerima true/1/yes dan false/0/no, date delegasi ke `parseBackendDateTime()`.
- Redundansi/minimalisasi: ini seharusnya jadi pusat helper parse; banyak model lama masih punya helper lokal sehingga kandidat refactor bertahap memakai `ModelParseUtils`.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan helper berdampak ke negotiation/capability model dan model apa pun yang nanti dimigrasi; jaga fallback behavior.

## frontend_bangdeliv/lib/utils/order_formatters.dart

- Fungsi: formatter nominal dan waktu order plus re-export helper waktu.
- Logic penting: `formatCurrency()` pakai `Rp ` dengan spasi; waktu selalu WIB; `formatBackendTimeText()` parse backend text atau tambahkan WIB.
- Redundansi/minimalisasi: duplikat nominal dengan `currency_formatter.formatRupiah()`; kandidat pilih satu format rupiah canonical.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: banyak UI memakai format waktu/uang, perubahan kecil bisa menyebar luas ke order/tracking/chat/history.

## frontend_bangdeliv/lib/utils/order_status.dart

- Fungsi: pusat normalisasi, grouping, label, tracking step, dan location-trackable status order.
- Logic penting: wrap `OrderStatusCode` domain; terminal/cancelled/running status memasukkan `cancelledWithFee`; tracking step punya fallback compact label untuk status backend lama.
- Redundansi/minimalisasi: sudah penting sebagai adapter status; jangan dipecah. Bisa kurangi fallback string lama setelah backend status final.
- Service fee notice: `cancelledWithFee` harus dipertahankan karena terkait penalti 50% setelah gagal pickup merchant; bukan kandidat hapus service fee lines.
- Risiko: blast radius tinggi ke driver/customer order provider, tracking map, activity/history, realtime, tests status/presenter.

## frontend_bangdeliv/lib/utils/order_ui_helpers.dart

- Fungsi: helper UI warna/icon status/service type dan label pembayaran.
- Logic penting: warna/icon status dari order status, icon/warna service type, `isPaymentPaid`, `paymentStatusLabel`, `paymentMethodLabel`.
- Redundansi/minimalisasi: file mulai mencampur UI umum dan bisnis fee; `carefulCarryDefaultDeliveryFee()` tidak semestinya berada di UI helper setelah careful-carry dihapus.
- Service fee notice: `carefulCarryDefaultDeliveryFee()` adalah kandidat hapus/refactor sesuai plan Courier "Perlu 2 orang"/careful carry. Helper pembayaran dan status tetap dipertahankan.
- Risiko: perubahan berdampak ke driver active order fee/meta widgets dan status UI; hapus careful-carry harus sinkron dengan `service_type.dart`, model, service, provider, widget.

## frontend_bangdeliv/lib/utils/service_type.dart

- Fungsi: pusat normalisasi service type, label, dan capability service type frontend.
- Logic penting: wrap `ServiceTypeCode`; label ride/courier/shopping; proofs didukung courier/shopping; careful-carry saat ini hanya courier.
- Redundansi/minimalisasi: sudah kecil; default label unknown ke `Kurir` bisa dipertimbangkan menjadi `Layanan`/unknown agar tidak misleading.
- Service fee notice: `serviceTypeSupportsCarefulCarry()` adalah kandidat hapus/refactor sesuai plan Courier "Perlu 2 orang"; `serviceTypeSupportsOrderProofs()` tetap dipertahankan.
- Risiko: perubahan berdampak ke routing label/icon, driver/customer model parsing, order UI helper, courier package formatter, driver fee/meta widgets.

## frontend_bangdeliv/lib/utils/vehicle_options.dart

- Fungsi: list opsi tipe dan brand kendaraan driver dengan selected custom value.
- Logic penting: options default motor/brand; `_optionsWithSelected()` menambahkan selected value jika tidak ada di list agar edit form tetap menampilkan data backend.
- Redundansi/minimalisasi: sudah minimal; data opsi bisa dipindah ke config jika akan dipakai backend/admin juga.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke register/edit driver profile form.

## frontend_bangdeliv/lib/firebase_options.dart

- Fungsi: konfigurasi Firebase hasil FlutterFire CLI untuk inisialisasi Firebase app.
- Logic penting: hanya Android yang dikonfigurasi; web/iOS/macOS/windows/linux melempar `UnsupportedError`; nilai Android dipakai oleh `FirebaseNotificationService.initializeFirebase()`.
- Redundansi/minimalisasi: generated file, jangan refactor manual kecuali regenerasi FlutterFire. Bisa dibiarkan apa adanya selama target TA hanya Android.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan manual bisa memutus FCM/Firebase init; jika target platform bertambah, perlu regenerasi config bukan edit parsial.

## frontend_bangdeliv/lib/main.dart

- Fungsi: entry point Flutter, setup system UI, init Firebase notification, log env, pasang `ProviderScope`, dan mount `MaterialApp.router`.
- Logic penting: auth session diinisialisasi via microtask `authSessionProvider.initialize()`; app-level provider realtime/notification/chat heads-up/driver availability di-watch di root agar lifecycle berjalan global; router dari `appRouterProvider`.
- Redundansi/minimalisasi: file sudah ringkas. Komentar `ProviderScope` bisa dihapus untuk minimalisme; bootstrap provider root sudah tepat selama fitur realtime global masih dibutuhkan.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan urutan init bisa berdampak ke FCM, auth guard route, realtime subscription, dan driver location availability reporter.

## Backend_Bangdeliv/app/Enums/DriverActionCode.php

- Fungsi: enum kode aksi driver untuk lifecycle order.
- Logic penting: memuat aksi pickup/dropoff/delivery, COD collection, dan `CancelWithFee`.
- Redundansi/minimalisasi: sudah minimal; cocok sebagai single source action code untuk payload driver.
- Service fee notice: `CancelWithFee` harus dipertahankan sementara karena terkait exception penalti gagal pickup merchant 3 kali/50%, bukan service fee lines.
- Risiko: perubahan value enum berdampak ke `DriverOrderPayloadFactory`, endpoint action driver, frontend driver action widgets, dan tests lifecycle driver.

## Backend_Bangdeliv/app/Enums/DriverDistanceBucket.php

- Fungsi: enum bucket jarak driver untuk dispatch metadata.
- Logic penting: bucket `NEAR`, `MEDIUM`, `FAR`, dan `UNKNOWN`.
- Redundansi/minimalisasi: sudah sangat minimal; tidak perlu refactor.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan value berdampak ke `DriverDispatchMetadataFactory` dan payload dispatch/order available.

## Backend_Bangdeliv/app/Enums/OrderStatusCode.php

- Fungsi: enum status order dan helper grouping status driver.
- Logic penting: `normalize()` uppercase trim; `runningDriverStatuses()` memasukkan `CancelledWithFee`; `driverLocationTrackableStatuses()` hanya status yang masih butuh tracking lokasi.
- Redundansi/minimalisasi: sudah penting sebagai pusat status backend; harus sinkron dengan frontend `order_status.dart` dan master status database.
- Service fee notice: `CancelledWithFee` harus dipertahankan karena terkait penalti 50% setelah gagal pickup merchant; bukan kandidat hapus service fee lines.
- Risiko: blast radius tinggi ke `OrderService`, tracking, realtime, history/activity, payment proof, dan tests provider/status.

## Backend_Bangdeliv/app/Enums/PaymentMethod.php

- Fungsi: enum metode pembayaran order.
- Logic penting: hanya `COD` dan `TRANSFER`; `normalize()` fallback ke COD kecuali value persis transfer.
- Redundansi/minimalisasi: sudah minimal; ada normalisasi payment method lain di service chatbot/payment yang nanti bisa dievaluasi untuk konsolidasi.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: fallback COD yang terlalu permisif bisa menyembunyikan payload payment invalid; perubahan harus sinkron dengan chatbot, order payment service, dan frontend label pembayaran.

## Backend_Bangdeliv/app/Enums/ProofType.php

- Fungsi: enum tipe bukti order dan mapper dari evidence type backend.
- Logic penting: tipe proof `pickup`, `delivery`, `receipt`, `store_closed`, `payment_transfer`; mapper menormalisasi evidence seperti `STORE_CLOSED_PHOTO` ke `store_closed`.
- Redundansi/minimalisasi: ada logic canonical proof serupa di model/order payload lama; kandidat konsolidasi agar semua mapping pakai enum/policy ini.
- Service fee notice: `store_closed` harus dipertahankan karena bagian dari flow gagal pickup merchant/penalti exception, bukan service fee lines.
- Risiko: perubahan berdampak ke `OrderProofPolicyService`, upload bukti driver, shopping store closed proof, payment transfer proof, dan tests proof policy.

## Backend_Bangdeliv/app/Enums/ServiceTypeCode.php

- Fungsi: enum dan normalisasi service type backend.
- Logic penting: alias `ANTAR_JEMPUT`, `KURIR`, `NITIP`, dan alias lama dipetakan ke `RIDE`, `COURIER`, `SHOPPING`; kosong menjadi `UNKNOWN`.
- Redundansi/minimalisasi: sudah minimal; harus tetap sinkron dengan frontend `service_type.dart`. Tidak ada capability careful-carry di enum ini.
- Service fee notice: tidak ada service fee lines/careful-carry; service type courier tetap dipertahankan sebagai jenis layanan, yang dihapus nanti hanya fitur careful-carry/perlu 2 orang.
- Risiko: perubahan normalisasi berdampak ke order creation, chatbot draft, driver dispatch, proof policy, ETA resolver, dan semua payload order.

## Backend_Bangdeliv/app/Events/DriverLocationUpdated.php

- Fungsi: event realtime lokasi driver untuk channel tracking order.
- Logic penting: broadcast sekarang juga ke private channel `order.tracking.{orderId}` dengan payload `order_id`, `latitude`, `longitude`, dan `updated_at` fallback `now()`.
- Redundansi/minimalisasi: sudah minimal; property public lama bisa dirapikan ke constructor property promotion jika nanti ingin konsisten, tetapi tidak perlu untuk refactor service fee.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan channel/payload berdampak ke `OrderRealtimeBroadcaster`, Pusher frontend, tracking map, dan tests workflow driver.

## Backend_Bangdeliv/app/Events/DriverOrderAvailable.php

- Fungsi: event realtime saat order tersedia untuk driver tertentu.
- Logic penting: broadcast ke private channel `driver.orders.user.{driverUserId}` dengan event alias `driver.order.available` dan payload `order`.
- Redundansi/minimalisasi: sudah minimal; event hanya envelope, detail payload dibentuk di `DriverOrderPayloadFactory`.
- Service fee notice: event tidak punya logic service fee/careful-carry. Jika payload order masih membawa careful-carry/service fee lines, pembersihannya harus di payload factory/model, bukan di event ini.
- Risiko: perubahan nama event/channel berdampak ke `DriverOrderRealtimeService`, frontend realtime driver orders, dan tests dispatch/order available.

## Backend_Bangdeliv/app/Events/DriverOrderRemoved.php

- Fungsi: event realtime untuk menghapus order dari daftar driver tertentu.
- Logic penting: broadcast ke private channel `driver.orders.user.{driverUserId}` dengan alias `driver.order.removed`, payload `order_id` dan optional `reason`.
- Redundansi/minimalisasi: sudah minimal; tidak perlu refactor.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan payload/channel berdampak ke driver order provider frontend, accept/reject flow, dan tests workflow driver.

## Backend_Bangdeliv/app/Events/OrderChatMessageSent.php

- Fungsi: event realtime pesan chat order.
- Logic penting: broadcast ke private channel `order.tracking.{orderId}` dengan alias `order.chat.message.sent`, payload `order_id` dan `message`.
- Redundansi/minimalisasi: sudah minimal; event hanya envelope, format message dibentuk service chat.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan nama event/payload berdampak ke order chat realtime, unread/chat provider frontend, dan tests `OrderChatTest`.

## Backend_Bangdeliv/app/Events/OrderContentUpdated.php

- Fungsi: event realtime saat konten/pricing order berubah.
- Logic penting: broadcast ke private channel `order.tracking.{orderId}` dengan alias `order.content.updated`, payload `change_type`, `pricing`, dan `updated_at`.
- Redundansi/minimalisasi: sudah minimal sebagai envelope. Validasi/kebersihan isi `pricing` sebaiknya di `OrderRealtimeBroadcaster`/service pembentuk payload.
- Service fee notice: event ini boleh tetap ada untuk update ongkir, item belanja, dan perubahan harga valid. Jika nanti `pricing` berisi `service_fee_lines`/careful-carry, hapus di sumber payload, bukan menghapus event.
- Risiko: perubahan event berdampak ke shopping item edit, payment/price notification, tracking focus frontend, dan tests `ShoppingOrderItemEditTest`.

## Backend_Bangdeliv/app/Events/OrderStatusChanged.php

- Fungsi: event realtime perubahan status order.
- Logic penting: broadcast ke private channel `order.tracking.{orderId}` dengan payload status baru, status sebelumnya, history id, timestamp, label, dan flag terminal.
- Redundansi/minimalisasi: sudah minimal; belum ada `broadcastAs()` sehingga frontend membaca event class/default Laravel, pastikan konsisten sebelum mengubah.
- Service fee notice: tidak ada service fee lines/careful-carry. Status `CANCELLED_WITH_FEE` bisa lewat payload event ini dan harus tetap didukung untuk penalti gagal pickup merchant.
- Risiko: blast radius ke `OrderRealtimeBroadcaster`, `OrderService`, tracking/activity frontend, notification status, dan tests workflow/status.

## Backend_Bangdeliv/app/Exceptions/ApiException.php

- Fungsi: exception API sederhana dengan HTTP status dan payload errors.
- Logic penting: constructor menyimpan `message`, `status`, dan `errors`; accessor `status()` dan `errors()` dipakai controller/service untuk response API.
- Redundansi/minimalisasi: sudah minimal. Bisa diberi generic docblock `array<string, mixed>` untuk `errors` jika ingin static analysis lebih jelas, tapi tidak wajib.
- Service fee notice: tidak ada logic service fee/careful-carry di class ini; ia hanya wrapper error yang dipakai luas, termasuk flow order/chatbot/shopping.
- Risiko: perubahan signature/status behavior berdampak ke banyak service dan tests yang mengharapkan `ApiException` tertentu.

## Backend_Bangdeliv/app/Http/Controllers/Admin/DriverVerificationController.php

- Fungsi: controller web admin untuk antrian, detail, review, hapus, dan preview dokumen verifikasi driver.
- Logic penting: filter queue `all/pending/needs_revision/rejected`; operasi utama didelegasikan ke `DriverVerificationService`; `ApiException` diubah menjadi abort/flash redirect sesuai aksi.
- Redundansi/minimalisasi: sudah cukup tipis sebagai web adapter. Ada controller API dengan domain sama, tetapi logic bisnis tetap di service sehingga tidak perlu refactor besar.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan route/flash/abort berdampak ke view admin verification, tests `DriverVerificationWebTest`, dan akses preview dokumen.

## Backend_Bangdeliv/app/Http/Controllers/Admin/RestaurantController.php

- Fungsi: controller web admin CRUD restoran, pencarian, filter status, dan toggle active/inactive.
- Logic penting: index query `Restaurant` dengan count menus/orders, filter active/inactive, search name/slug/address/phone, paginate 10; `closed` masih placeholder karena schema belum punya status closed.
- Redundansi/minimalisasi: query masih langsung di controller tapi cukup kecil. `closedCount`/filter closed bisa dihapus atau disesuaikan setelah keputusan schema jam buka/closed jelas.
- Service fee notice: tidak ada logic service fee/careful-carry; data restoran hanya master merchant untuk Nitip.
- Risiko: perubahan berdampak ke admin restaurant views, route web restoran, merchant/menu data yang dipakai home dan shopping flow, serta tests `RestaurantCrudTest`.

## Backend_Bangdeliv/app/Http/Controllers/Admin/RestaurantMenuController.php

- Fungsi: controller web admin untuk melihat, tambah, update, dan hapus menu restoran beserta kategori.
- Logic penting: index eager load categories/menus terurut; store/update dalam transaction; `resolveCategoryId()` membuat kategori baru jika `new_category_name` terisi, fallback ke `menu_category_id`; update/destroy memastikan menu milik restaurant.
- Redundansi/minimalisasi: payload create/update menu duplikatif dan bisa diekstrak helper kecil jika ingin lebih clean. Pastikan validasi request menjamin `menu_category_id` milik restaurant.
- Service fee notice: tidak ada logic service fee/careful-carry; `price` adalah harga item menu, bukan service fee.
- Risiko: perubahan berdampak ke admin menu catalog, data menu di home/restaurant API, shopping manual/restaurant item flow, dan tests `RestaurantCrudTest`.

## Backend_Bangdeliv/app/Http/Controllers/Api/Driver/OrderExecutionController.php

- Fungsi: controller API legacy untuk update status order aktif driver.
- Logic penting: validasi `status_code`, pastikan order milik driver login, mapping status lama ke `action_code`, lalu delegasi ke `OrderService::transitionStatusByDriver()`.
- Redundansi/minimalisasi: file ini adalah backward-compat adapter; setelah frontend sepenuhnya pakai endpoint action baru, kandidat dihapus agar lifecycle driver tidak punya dua pintu status.
- Service fee notice: tidak ada logic service fee/careful-carry. Endpoint ini hanya transisi status; `CANCEL_WITH_FEE` tidak dipetakan di adapter legacy ini.
- Risiko: perubahan mapping bisa memutus flow driver lama dan tests workflow; harus cek apakah frontend masih memanggil route `/driver/orders/{orderId}/status`.

## Backend_Bangdeliv/app/Http/Controllers/Api/AuthController.php

- Fungsi: controller API auth/profile: register customer, login/logout, upgrade driver, profile, avatar, password, saved address CRUD, validasi alamat, dan payload profile.
- Logic penting: register/login manual via Validator/Hash/Sanctum token; login revoke token lama; update profile menangani avatar dan vehicle info driver; address CRUD delegasi ke `AddressService`; `buildProfilePayload()` menyusun addresses, driver_profile, avatar_url, dan stats.
- Redundansi/minimalisasi: file besar dan memegang banyak concern. Kandidat split bertahap: `AuthSessionController`, `ProfileController`, `AddressController`, atau minimal pindahkan `buildProfilePayload()/resolveAvatarUrl()` ke presenter/resource.
- Service fee notice: hanya ada `delivery_fee` untuk menghitung total paid/statistik driver selesai; ini ongkir/pendapatan driver dan harus dipertahankan. Tidak ada service fee lines/careful-carry.
- Risiko: blast radius tinggi ke login/register frontend, auth session provider, profile/edit profile/change password, saved addresses, driver onboarding, dan tests auth/profile/address.

## Backend_Bangdeliv/app/Http/Controllers/Api/ChatbotController.php

- Fungsi: controller API chatbot untuk proses chat, daftar/history session, patch map pin/route/merchant, archive session, logging chat, context model, dan payload action hints.
- Logic penting: map service type `antar_jemput/kurir/nitip`; route transport ke `ChatbotRideOrderService`/`ChatbotCourierOrderService` dan nitip ke `ChatbotShoppingOrderService`; fallback Gemini ke parser deterministik; fast command confirm/reset/payment/add merchant; simpan `ai_chat_sessions`, `AiChatLog`, dan `aiDetail`; enrich transport action menjadi `OPEN_ROUTE_PICKER`, map picker, payment, confirm, reset, dan change pickup.
- Redundansi/minimalisasi: file sangat besar dan memegang banyak concern. Kandidat split setelah service diaudit: `ChatbotSessionController`, `ChatbotPatchActionController`, `ChatbotLogService`, `ChatbotModelContextBuilder`, dan `TransportActionPayloadBuilder`.
- Service fee notice: tidak ada service fee lines/careful-carry langsung. Controller hanya meneruskan payload service ke `ai_response`; jika ada `service_fee`, `fee_breakdown`, atau careful-carry di payload chatbot, pembersihannya dilakukan di `Chatbot*OrderService`, bukan di controller ini. Payment action `COD/TRANSFER` tetap dipertahankan.
- Risiko: blast radius sangat tinggi ke semua flow chatbot ride/courier/shopping, frontend `chatbot_conversation_provider`, session history, map/merchant/route picker, Gemini fallback, dan tests `ChatbotRideFlowTest`, `ChatbotCourierFlowTest`, `ChatbotShoppingFlowTest`, `ChatbotAccessTest`.

## Backend_Bangdeliv/app/Http/Controllers/Api/DeviceTokenController.php

- Fungsi: controller API register dan deactivate FCM/device token user.
- Logic penting: store validasi token max 4096 dan `device_type` hanya `android`; delegasi register/deactivate ke `DeviceTokenService`; response memakai trait `ApiResponse`.
- Redundansi/minimalisasi: sudah minimal. Jika platform bertambah, whitelist `device_type` perlu diperluas bersama Firebase config/frontend.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan kontrak berdampak ke Firebase notification bootstrap frontend, token sync, dan tests `DeviceTokenTest`.

## Backend_Bangdeliv/app/Http/Controllers/Api/DriverVerificationController.php

- Fungsi: controller API status/verifikasi driver untuk user dan admin.
- Logic penting: `myStatus()` dan `submitDocuments()` untuk driver; `adminIndex/adminShow/adminReview` untuk admin; semua logic bisnis didelegasikan ke `DriverVerificationService`; error memakai `ApiException`.
- Redundansi/minimalisasi: sudah tipis; ada controller web admin paralel dengan domain sama, tetapi tetap aman karena logic bisnis di service. Bisa konsolidasi response/admin filter setelah service diaudit.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke register/verification frontend, admin API, upload dokumen, dan tests `DriverVerificationTest`.

## Backend_Bangdeliv/app/Http/Controllers/Api/HomeController.php

- Fungsi: controller API beranda untuk merchant/menu/category agregat.
- Logic penting: validasi search, limit sections, latitude/longitude; delegasi payload ke `HomeService`.
- Redundansi/minimalisasi: sudah sangat minimal; tidak perlu refactor selain menjaga request validation tetap sinkron dengan frontend.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke home screen, nearby merchants, restaurant/menu API fallback frontend, dan tests `HomeApiTest`.

## Backend_Bangdeliv/app/Http/Controllers/Api/OrderChatController.php

- Fungsi: controller API chat order untuk list, unread summary, mark read, dan send message/attachment.
- Logic penting: list validasi `before_id/after_id/limit`; store menerima body atau attachment image dengan `client_message_id`; semua operasi delegasi ke `OrderChatService`; response/error memakai `ApiResponse` dan `ApiException`.
- Redundansi/minimalisasi: sudah cukup tipis. Kontrak `client_message_id` penting untuk optimistic message frontend, jangan diubah saat cleanup.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke order chat screen/provider, unread badge, realtime chat merge, attachment upload, dan tests route/order chat.

## Backend_Bangdeliv/app/Http/Controllers/Api/OrderController.php

- Fungsi: controller API utama order untuk customer, driver, dan admin: ride order, list/detail/cancel, payment, shopping item/change/quote, delivery fee override, driver proofs, driver orders/history/location/availability, status transition, failed attempt, COD/transfer, dan settlement.
- Logic penting: hampir semua method hanya validasi request lalu delegasi ke `OrderService`/`RideOrderService`; endpoint deprecated `skipFailedShoppingStop()` dan `acceptShoppingCounter()` sudah mengembalikan 410; `recordFailedAttemptByDriver/Admin()` mencatat gagal pickup; `uploadDriverProof()` menerima `store_closed`; delivery fee override punya approve/counter/accept flow.
- Redundansi/minimalisasi: file terlalu gemuk dan mencampur banyak domain. Kandidat split setelah service diaudit: `CustomerOrderController`, `DriverOrderController`, `ShoppingOrderController`, `DeliveryFeeOverrideController`, `OrderPaymentController`, dan `OrderProofController`; deprecated endpoint 410 bisa dipertimbangkan dihapus setelah frontend/tests tidak bergantung route contract.
- Service fee notice: `careful_carry_required` pada `updateDeliveryFeeOverride()` adalah kandidat hapus/refactor sesuai plan Courier "Perlu 2 orang". `delivery-fee-override`/`delivery_fee_override` adalah ongkir manual dan harus dipertahankan. `store_closed` proof dan `recordFailedAttempt*()` harus dipertahankan untuk exception penalti gagal pickup 3 kali/50%.
- Risiko: blast radius sangat tinggi ke hampir semua flow order frontend/backend, route contract tests, driver active order actions, shopping edit/checkout, payment proof, realtime status/pricing, failed pickup penalty, dan COD settlement.

## Backend_Bangdeliv/app/Http/Controllers/Api/RestaurantController.php

- Fungsi: controller API katalog restoran untuk list, detail, dan menu.
- Logic penting: `index()` memakai `ListRestaurantsRequest` dan pagination service; `show()` resolve id/slug; `menus()` validasi category/search/only_available lalu delegasi ke `RestaurantService`.
- Redundansi/minimalisasi: sudah tipis dan bersih sebagai adapter API. Validasi `category_id exists` belum memastikan kategori milik restaurant, sehingga kepemilikan harus dijaga di `RestaurantService`.
- Service fee notice: tidak ada logic service fee/careful-carry; harga menu adalah harga item merchant.
- Risiko: perubahan kontrak berdampak ke home/merchant/menu frontend, chatbot Nitip item menu, shopping add item, dan tests route/API restoran.

## Backend_Bangdeliv/app/Http/Controllers/AdminAuthController.php

- Fungsi: controller web login/logout admin berbasis session.
- Logic penting: tampilkan view `auth.login`; login validasi email/password, cek role harus `admin`, `Auth::attempt()`, regenerate session, redirect dashboard; logout invalidate session dan regenerate token.
- Redundansi/minimalisasi: konsep login terpisah dari API `AuthController` karena ini session web admin; masih minimal. Komentar Indonesia bisa dihapus jika ingin kode lebih bersih.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke route web `/admin/login`, layout/admin auth, dan akses dashboard admin.

## Backend_Bangdeliv/app/Http/Controllers/Controller.php

- Fungsi: base abstract controller Laravel untuk semua controller app.
- Logic penting: saat ini kosong, hanya inheritance anchor.
- Redundansi/minimalisasi: sudah minimal; tidak perlu refactor.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: menambah behavior global di sini akan berdampak ke semua controller, jadi hindari perubahan tanpa kebutuhan kuat.

## Backend_Bangdeliv/app/Http/Middleware/EnsureDriverIsActive.php

- Fungsi: middleware gate API driver yang memastikan user login, role driver, punya profile driver, dan `registration_status` active.
- Logic penting: response JSON saat `expectsJson()`, otherwise abort; status suspended punya pesan khusus, status selain active diarahkan untuk menyelesaikan verifikasi.
- Redundansi/minimalisasi: sudah minimal. Ada pengecekan driver active lain di broadcast channel dan service order; tetap wajar karena konteks akses berbeda.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke semua route dalam middleware `driver.active`, driver order flow, availability/location, dan akses driver yang belum diverifikasi.

## Backend_Bangdeliv/app/Http/Middleware/EnsureUserRole.php

- Fungsi: middleware role gate untuk route web/API.
- Logic penting: abort 401 jika tidak login; abort 403 jika `user->role` tidak termasuk varargs role yang diminta.
- Redundansi/minimalisasi: sudah sangat minimal; jika API ingin format JSON konsisten, bisa dipertimbangkan response JSON seperti `EnsureDriverIsActive`, tapi tidak wajib.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke semua route `role:*`, termasuk admin web, customer API, driver API, dan upgrade-to-driver.

## Backend_Bangdeliv/app/Http/Requests/Admin/StoreMenuRequest.php

- Fungsi: validasi form admin tambah menu restoran.
- Logic penting: wajib `name`, `price`, `is_available`; optional description/sort/category/new category; `menu_category_id` hanya dicek exists.
- Redundansi/minimalisasi: rules identik dengan `UpdateMenuRequest`; kandidat ekstrak base request/helper rules agar tidak duplikatif. Validasi ownership kategori terhadap restaurant masih di controller/service, bukan request.
- Service fee notice: tidak ada logic service fee/careful-carry; `price` adalah harga menu merchant.
- Risiko: perubahan berdampak ke admin menu catalog dan data menu yang dipakai home/chatbot/shopping.

## Backend_Bangdeliv/app/Http/Requests/Admin/StoreRestaurantRequest.php

- Fungsi: validasi form admin tambah restoran/merchant.
- Logic penting: normalize koordinat koma ke titik, auto-swap lat/lng jika tampak tertukar, default `merchant_type` ke restaurant, slug unique, status active/inactive.
- Redundansi/minimalisasi: banyak logic sama dengan `UpdateRestaurantRequest`; kandidat ekstrak base request/helper untuk normalize coordinate dan merchant type.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan validasi koordinat/merchant type berdampak ke admin restaurant CRUD, home nearby merchant, chatbot merchant selection, dan route shopping.

## Backend_Bangdeliv/app/Http/Requests/Admin/UpdateMenuRequest.php

- Fungsi: validasi form admin update menu restoran.
- Logic penting: rules sama dengan store: menu name/price/availability/category/new category.
- Redundansi/minimalisasi: duplikat penuh dengan `StoreMenuRequest`; kandidat konsolidasi rules.
- Service fee notice: tidak ada logic service fee/careful-carry; `price` adalah harga menu merchant.
- Risiko: perubahan berdampak ke edit menu admin dan data menu yang sudah dipakai order/chatbot.

## Backend_Bangdeliv/app/Http/Requests/Admin/UpdateRestaurantRequest.php

- Fungsi: validasi form admin update restoran/merchant.
- Logic penting: normalize koordinat dan auto-swap lat/lng seperti store; default `merchant_type` dari route restaurant; slug unique ignore current restaurant.
- Redundansi/minimalisasi: duplikat besar dengan `StoreRestaurantRequest`; kandidat ekstrak shared coordinate normalization, merchant type normalization, messages, dan common rules.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke edit restaurant admin, merchant data di katalog/home/chatbot/shopping, dan slug route API.

## Backend_Bangdeliv/app/Http/Requests/Api/AddShoppingOrderItemRequest.php

- Fungsi: validasi tambah satu item belanja customer.
- Logic penting: `item_source` wajib MANUAL/MENU_DB; `menu_id` wajib jika MENU_DB; `menu_name` wajib jika MANUAL; quantity 1-99.
- Redundansi/minimalisasi: rules inti mirip dengan bulk request dan inline rules di `OrderController::requestShoppingItemChange()`; kandidat ekstrak shared shopping item rules setelah service diaudit.
- Service fee notice: tidak ada logic service fee/careful-carry; item ini bisa memicu pricing downstream, tetapi request tidak memuat fee.
- Risiko: perubahan berdampak ke add shopping item frontend, chatbot/order shopping item flow, dan validasi menu/manual item.

## Backend_Bangdeliv/app/Http/Requests/Api/AddShoppingOrderItemsRequest.php

- Fungsi: validasi bulk tambah item belanja, termasuk merchant Google/place payload.
- Logic penting: `items` 1-30; tiap item boleh punya `merchant_id` atau `merchant_place`; validasi place name/address/lat/lng/types; item source MANUAL/MENU_DB, menu id/name, quantity, notes.
- Redundansi/minimalisasi: duplikat dengan rules item change di `OrderController` dan sebagian chatbot payload; kandidat shared request/rules object untuk item + merchant place.
- Service fee notice: tidak ada service fee/careful-carry langsung.
- Risiko: perubahan berdampak ke multi-merchant Nitip, shopping add item screen, merchant picker, dan tests shopping edit/chatbot.

## Backend_Bangdeliv/app/Http/Requests/Api/AdminReviewDriverDocumentsRequest.php

- Fungsi: validasi review dokumen driver oleh admin.
- Logic penting: `documents` min 1; tipe dokumen ktp/sim/selfie distinct; status approved/rejected; rejected wajib punya `rejection_reason` via `withValidator()`.
- Redundansi/minimalisasi: sudah fokus; dipakai API admin dan web admin, jadi perubahan harus mempertahankan dua konteks response.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke driver verification API/web dan tests verifikasi driver.

## Backend_Bangdeliv/app/Http/Requests/Api/CancelOrderRequest.php

- Fungsi: validasi cancel order customer.
- Logic penting: hanya `reason` required string max 500.
- Redundansi/minimalisasi: sudah minimal.
- Service fee notice: tidak ada service fee lines/careful-carry. Cancel biasa berbeda dari `CANCELLED_WITH_FEE` penalty flow.
- Risiko: perubahan berdampak ke customer cancel order frontend dan order cancellation service.

## Backend_Bangdeliv/app/Http/Requests/Api/CreateRideOrderRequest.php

- Fungsi: validasi pembuatan order Antar Jemput.
- Logic penting: `address_id` wajib, destination address wajib; destination lat/lng optional tapi harus berpasangan; payment method optional COD/TRANSFER.
- Redundansi/minimalisasi: sudah fokus; pola koordinat berpasangan mirip address request dan bisa distandarkan nanti.
- Service fee notice: tidak ada service fee/careful-carry; payment method dan ongkir ride tetap dipertahankan.
- Risiko: perubahan berdampak ke ride order creation, chatbot ride confirmation, dan frontend route picker.

## Backend_Bangdeliv/app/Http/Requests/Api/ListRestaurantsRequest.php

- Fungsi: validasi query list restoran/merchant API.
- Logic penting: search/page/per_page/sort/merchant_type/lat/lng; `sort=nearest` wajib menyertakan latitude dan longitude via `withValidator()`.
- Redundansi/minimalisasi: sudah minimal; merchant type enum string tersebar di request admin/API dan bisa dipusatkan kalau sering berubah.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke restaurant list API, nearby merchants, home/search frontend, dan shopping merchant selection.

## Backend_Bangdeliv/app/Http/Requests/Api/RecordCodPaymentRequest.php

- Fungsi: validasi pencatatan pembayaran COD oleh driver/admin.
- Logic penting: `amount` required min 0.01 max 99999999.99; optional `paid_at`, `note`, dan metadata array.
- Redundansi/minimalisasi: sudah minimal; amount/payment validation bisa distandarkan dengan transfer payment jika nanti dibuat request class terpisah.
- Service fee notice: tidak ada service fee/careful-carry; amount adalah pembayaran COD/order payment dan harus dipertahankan.
- Risiko: perubahan berdampak ke COD collection, settlement report, payment status, dan tests COD flow.

## Backend_Bangdeliv/app/Http/Requests/Api/RecordFailedAttemptRequest.php

- Fungsi: validasi pencatatan failed attempt order.
- Logic penting: `failure_type` wajib DRIVER_ASSIGNMENT/PICKUP/DELIVERY; reason wajib; optional `pickup_location_id` harus ada di `order_locations`.
- Redundansi/minimalisasi: sudah minimal; ownership pickup location diverifikasi di service, bukan request.
- Service fee notice: harus dipertahankan karena ini jalur exception penalti gagal pickup merchant 3 kali/50%; bukan service fee lines yang dihapus.
- Risiko: perubahan berdampak ke failed pickup merchant, `CANCELLED_WITH_FEE`, proof/store closed flow, dan tests driver order workflow.

## Backend_Bangdeliv/app/Http/Requests/Api/StoreAddressRequest.php

- Fungsi: validasi tambah alamat user.
- Logic penting: label/recipient/phone/full address wajib; lat/lng optional tapi harus berpasangan; `is_default` boolean.
- Redundansi/minimalisasi: duplikat dengan `UpdateAddressRequest`; kandidat shared address rules.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke saved address, chatbot address guard, route picker fallback, dan profile address UI.

## Backend_Bangdeliv/app/Http/Requests/Api/SubmitDriverDocumentsRequest.php

- Fungsi: validasi upload dokumen driver.
- Logic penting: ktp/sim/selfie optional image jpg/jpeg/png max 5MB; `withValidator()` mewajibkan minimal satu file.
- Redundansi/minimalisasi: sudah fokus; file rules bisa di-share dengan request dokumen lain jika bertambah.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke upgrade/verification driver dan tests upload dokumen.

## Backend_Bangdeliv/app/Http/Requests/Api/UpdateAddressRequest.php

- Fungsi: validasi update alamat user.
- Logic penting: rules sama dengan store address; lat/lng optional tapi harus berpasangan.
- Redundansi/minimalisasi: duplikat penuh dengan `StoreAddressRequest`; kandidat shared address rules.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke edit saved address, default address, dan readiness alamat frontend.

## Backend_Bangdeliv/app/Http/Requests/Api/UpdateDriverShoppingItemsRequest.php

- Fungsi: validasi update item belanja dari driver/nota.
- Logic penting: optional `pickup_location_id`; items 1-100; tiap item wajib id, optional quantity/unit_price/is_available/notes/is_heavy.
- Redundansi/minimalisasi: rules item driver berbeda dari customer item rules; tetap bisa dibuat helper khusus item pricing/update setelah service diaudit.
- Service fee notice: `is_heavy` adalah kandidat hapus/refactor jika hanya dipakai untuk overweight surcharge/service fee lines. `unit_price` tetap dipertahankan sebagai harga barang/nota, bukan service fee.
- Risiko: perubahan berdampak ke driver shopping receipt/checkout, price recalculation, shopping pricing service, dan tests shopping order edit/workflow.

## Backend_Bangdeliv/app/Http/Requests/Api/UpdateShoppingOrderItemRequest.php

- Fungsi: validasi update item belanja oleh customer.
- Logic penting: quantity wajib 1-99; notes optional; menu_name optional untuk item manual.
- Redundansi/minimalisasi: sebagian overlap dengan add item request; bisa share rules quantity/notes/menu name.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke customer edit item, shopping item change request, dan chatbot shopping edit context.

## Backend_Bangdeliv/app/Http/Requests/Api/UpgradeToDriverRequest.php

- Fungsi: validasi upgrade customer menjadi driver.
- Logic penting: trim vehicle fields; vehicle type/brand/model/plate/license required; plate dan license unique pada drivers non-deleted; custom messages Indonesia.
- Redundansi/minimalisasi: vehicle field validation mirip frontend/profile edit; bisa distandarkan dengan config opsi kendaraan jika backend perlu mengunci enum.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke onboarding driver, profile model, admin verification, dan tests upgrade driver.

## Backend_Bangdeliv/app/Http/Requests/Api/ValidateAddressRequest.php

- Fungsi: validasi request cek alamat user.
- Logic penting: `full_address` wajib string max 1000.
- Redundansi/minimalisasi: sudah minimal; sama pola dengan validate ride destination tapi target service berbeda.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke address form/picker dan address validation service.

## Backend_Bangdeliv/app/Http/Requests/Api/ValidateRideDestinationRequest.php

- Fungsi: validasi request cek alamat tujuan ride.
- Logic penting: `destination_address` wajib string max 1000.
- Redundansi/minimalisasi: sudah minimal; bisa share max length/address text rules dengan address validation.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke ride order creation/chatbot ride destination validation.

## Backend_Bangdeliv/app/Http/Responses/ApiResponse.php

- Fungsi: trait helper response JSON sukses/error untuk controller API.
- Logic penting: `success()` selalu mengirim `success`, `message`, `data`, dan optional `meta`; `error()` mengirim `success=false`, `message`, dan optional `errors`.
- Redundansi/minimalisasi: sudah minimal dan dipakai luas. Controller lama seperti `AuthController`/`ChatbotController` masih manual response sehingga kandidat konsolidasi bertahap jika ingin format API lebih konsisten.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan struktur response berdampak luas ke frontend repository/service parsing dan banyak tests API.

## Backend_Bangdeliv/app/Jobs/SendPaymentProofReminderJob.php

- Fungsi: queued job untuk reminder bukti pembayaran transfer/QRIS.
- Logic penting: load order dengan `user`, `payments`, `evidences`, `statusRef`; stop jika order tidak ada atau `shouldRemind()` false; `sendIfNeeded()` lalu redispatch selama masih dalam window reminder berdasarkan konstanta service.
- Redundansi/minimalisasi: sudah minimal; keputusan reminder berada di `PaymentProofReminderNotificationService`, job hanya orkestrasi queue dan interval.
- Service fee notice: tidak ada logic service fee/careful-carry. Payment proof reminder harus dipertahankan untuk flow pembayaran transfer/QRIS.
- Risiko: perubahan berdampak ke notifikasi bukti pembayaran, queue worker, dan potensi reminder berulang jika window/throttle berubah.

## Backend_Bangdeliv/app/Models/Address.php

- Fungsi: model saved address user.
- Logic penting: fillable user/label/recipient/phone/full address/lat/lng/default; casts lat/lng decimal dan `is_default` boolean; relation `user`.
- Redundansi/minimalisasi: sudah minimal; validasi usable address ada di service/frontend helper, bukan model.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan casts/fillable berdampak ke address CRUD, chatbot address readiness, ride/courier pickup, dan profile payload.

## Backend_Bangdeliv/app/Models/AiChatLog.php

- Fungsi: model `ai_chat_messages` untuk log chat chatbot dengan adapter detail AI.
- Logic penting: timestamps off; intercept attribute `ai_response/model_used/intent/order_id` lalu simpan ke `AiMessageDetail` saat save assistant; accessor mengambil detail lewat relation; relation user, order via hasOneThrough, dan aiDetail.
- Redundansi/minimalisasi: cukup kompleks untuk model karena menyimpan side-effect detail. Bisa dipertimbangkan pindah persist detail ke service/repository agar model lebih sederhana.
- Service fee notice: tidak ada logic service fee/careful-carry langsung, tetapi `ai_response` bisa menyimpan payload service fee dari chatbot service; cleanup dilakukan di service payload sebelum log.
- Risiko: perubahan berdampak besar ke `ChatbotController`, semua `Chatbot*OrderService`, session history, dan tests chatbot.

## Backend_Bangdeliv/app/Models/AiMessageDetail.php

- Fungsi: model detail metadata untuk pesan chatbot assistant.
- Logic penting: fillable `chat_message_id`, `ai_response`, `model_used`, `intent`, `order_id`; cast `ai_response` array; relation ke `AiChatLog` dan `Order`.
- Redundansi/minimalisasi: sudah minimal; nama relation `message()` cukup jelas meski table parent bernama `ai_chat_messages`.
- Service fee notice: tidak ada logic service fee/careful-carry langsung; hanya menyimpan payload yang diberikan service chatbot.
- Risiko: perubahan berdampak ke riwayat chatbot, model context, session list/history, dan relasi order chatbot.

## Backend_Bangdeliv/app/Models/CourierOrder.php

- Fungsi: model detail khusus order kurir.
- Logic penting: table `courier_order_details`; fillable `order_id`, `package_description`, `careful_carry_required`; cast careful-carry boolean; relation `order`.
- Redundansi/minimalisasi: setelah careful-carry dihapus, model ini kemungkinan tinggal `package_description`; tetap bisa dipertahankan sebagai detail kurir atau disederhanakan bersama order detail jika tidak ada field lain.
- Service fee notice: `careful_carry_required` adalah kandidat hapus/refactor sesuai plan Courier "Perlu 2 orang"/careful-carry. `package_description` harus dipertahankan.
- Risiko: perubahan berdampak ke `ChatbotCourierOrderService`, `OrderService`, `DriverOrderPayloadFactory`, `DeliveryFeeNegotiationService`, frontend driver order model, dan migration courier order details.

## Backend_Bangdeliv/app/Models/DeviceToken.php

- Fungsi: model token perangkat untuk push notification.
- Logic penting: fillable user/token/device_type/is_active; cast `is_active` boolean; relation user.
- Redundansi/minimalisasi: sudah minimal.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke `DeviceTokenService`, FCM sender, notification tests, dan token sync frontend.

## Backend_Bangdeliv/app/Models/Driver.php

- Fungsi: model profile driver.
- Logic penting: SoftDeletes; fillable vehicle info, registration/status, lokasi standby; casts lat/lng decimal dan `location_updated_at`; relation user, driverDocuments, orders.
- Redundansi/minimalisasi: sudah cukup fokus; status kerja dan registration status masih string bebas, bisa dipertimbangkan enum/constant setelah audit.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke driver onboarding/verification, dispatch, availability/location reporter, order assignment, dan admin driver views.

## Backend_Bangdeliv/app/Models/DriverDocument.php

- Fungsi: model dokumen verifikasi driver.
- Logic penting: fillable document type/path/status/reason/verified metadata; cast `verified_at`; relation driver dan verifier user.
- Redundansi/minimalisasi: sudah minimal; komentar relation verifier bisa dihapus jika ingin clean.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke driver verification service, admin/API verification views, dan preview/delete document.

## Backend_Bangdeliv/app/Models/Menu.php

- Fungsi: model menu restoran/merchant.
- Logic penting: SoftDeletes; fillable restaurant/category/name/description/price/image/availability/sort; casts price decimal, availability boolean; relation restaurant, category, orderItems.
- Redundansi/minimalisasi: sudah minimal; `image` ada di fillable tetapi perlu cek apakah UI/admin upload image benar-benar dipakai.
- Service fee notice: tidak ada service fee/careful-carry; `price` adalah harga item menu merchant.
- Risiko: perubahan berdampak ke restaurant catalog, home/menu API, chatbot Nitip MENU_DB item, dan order item relations.

## Backend_Bangdeliv/app/Models/MenuCategory.php

- Fungsi: model kategori menu restoran.
- Logic penting: fillable restaurant/name/sort_order; cast sort_order integer; relation restaurant dan menus.
- Redundansi/minimalisasi: sudah minimal.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke admin menu catalog, home categories, restaurant menus API, dan chatbot/menu item selection.

## Backend_Bangdeliv/app/Models/Order.php

- Fungsi: model pusat order, relasi order, dan banyak accessor payload untuk API/frontend.
- Logic penting: fillable order core; appends delivery/payment/shopping route/negotiation/capability/pricing/proofs; relation user, service type, driver, status, feeLines, items, locations, evidence, logs, payment, chat, ride/courier/shopping receipt; accessor menghitung alamat tujuan, payment state, shopping stops, route snapshot, pricing snapshot, fee breakdown, dan proof mapping.
- Redundansi/minimalisasi: model terlalu gemuk karena memanggil service dan membentuk payload presentasi; kandidat dipindah bertahap ke resource/presenter/factory. `legacyShoppingRouteSnapshot()` dead karena selalu null; alias `locations()` dan `orderLocations()` perlu cek pemakai sebelum disederhanakan.
- Service fee notice: `feeLines`, `fee_breakdown`, `shoppingFeeBreakdown()`, deskripsi `ITEM_BLOCK_SURCHARGE`/`OVERWEIGHT_FLAT_SURCHARGE`, `careful_carry_required`, dan `carefulCarrySurchargeAmount()` adalah kandidat hapus/refactor. `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS` tetap dipertahankan sebagai exception 50% ongkir setelah 3 kali gagal, tetapi idealnya tidak lagi bergantung ke generic service fee lines.
- Risiko: perubahan sangat besar karena model ini dipakai hampir semua endpoint order, driver payload, tracking, chatbot, pricing, payment, dan test workflow.

## Backend_Bangdeliv/app/Models/OrderChatMessage.php

- Fungsi: model pesan chat pada order.
- Logic penting: fillable sender, body, client id, dan attachment metadata; relation order dan sender user.
- Redundansi/minimalisasi: sudah minimal; snapshot nama sender ada untuk stabilitas tampilan histori.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke chat API, realtime event, unread count, dan tampilan chat customer/driver.

## Backend_Bangdeliv/app/Models/OrderChatRead.php

- Fungsi: model penanda posisi baca chat order per user.
- Logic penting: fillable order/user/last_read_message/read_at; cast `read_at`; relation order, user, dan lastReadMessage.
- Redundansi/minimalisasi: sudah minimal.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke unread badge, heads-up notification, dan sinkronisasi status baca.

## Backend_Bangdeliv/app/Models/OrderEvidence.php

- Fungsi: model bukti foto/file order.
- Logic penting: table `order_evidence`; fillable order/driver/type/file/uploaded_at/notes; cast uploaded_at; relation order dan driver; `verification_status` selalu accessor `PENDING`.
- Redundansi/minimalisasi: `getVerificationStatusAttribute()` hardcoded bisa disederhanakan jika tidak ada workflow verifikasi evidence.
- Service fee notice: tidak ada logic service fee/careful-carry; bukti `STORE_CLOSED_PHOTO` tetap relevan untuk flow gagal pickup merchant.
- Risiko: perubahan berdampak ke proof upload driver, failed pickup evidence, receipt, dan payload proofs order.

## Backend_Bangdeliv/app/Models/OrderFeeLine.php

- Fungsi: model baris biaya tambahan order.
- Logic penting: fillable order/code/label/amount; cast amount decimal; relation order.
- Redundansi/minimalisasi: kandidat hapus penuh jika table `order_fee_lines` dihapus sesuai plan; exception penalti gagal 3 kali perlu dipindah ke mekanisme lebih sederhana.
- Service fee notice: ini target utama service fee lines. Hapus/refactor bersama `feeLines` di `Order`, `ShoppingPricingService`, payload driver/customer, migration, dan tests.
- Risiko: penghapusan langsung memutus pricing shopping, fee breakdown, driver payload, dan tests yang masih membaca surcharge line.

## Backend_Bangdeliv/app/Models/OrderItem.php

- Fungsi: model item belanja pada order shopping.
- Logic penting: table `shopping_order_items`; fillable menu/pickup/source/name/qty/price/subtotal/notes/metadata/availability/heavy; casts numeric, metadata, boolean; relation order, menu, pickupLocation; accessor `line_service_fee` dan `line_total`.
- Redundansi/minimalisasi: `line_service_fee` adalah warisan service fee item dan kandidat hapus; `line_total` hanya alias subtotal, bisa dipertahankan untuk kompatibilitas frontend atau dipindah ke transformer.
- Service fee notice: `line_service_fee` kandidat hapus. `is_heavy` perlu dicek ulang karena selama ini terkait overweight surcharge; jika tidak ada kebutuhan non-fee, bisa ikut disederhanakan.
- Risiko: perubahan berdampak ke shopping item CRUD, driver item edit, receipt/price negotiation, chatbot Nitip, dan payload shopping stops.

## Backend_Bangdeliv/app/Models/OrderLocation.php

- Fungsi: model titik lokasi order, termasuk pickup/dropoff dan merchant stop.
- Logic penting: table `order_locations`; fillable role, kontak, alamat, koordinat, urutan, fulfillment status, failed attempt count/reason/timestamps; casts koordinat, sequence, failed count, failed/resolved time; relation order dan restaurant.
- Redundansi/minimalisasi: sudah fokus; field failed pickup memang domain shopping, tetapi masih wajar karena lokasi pickup merchant yang gagal ada di sini.
- Service fee notice: `failed_attempt_count`/`failure_reason`/`failed_at` perlu dipertahankan karena mendukung exception penalti 50% setelah 3 kali gagal pickup merchant.
- Risiko: perubahan berdampak ke route picker, chatbot draft route, shopping stops, driver pickup workflow, tracking, dan failed merchant flow.

## Backend_Bangdeliv/app/Models/OrderLog.php

- Fungsi: model event/log order umum di table `order_events`.
- Logic penting: timestamps off; fillable event/log/status/trigger/actor/note/metadata/created_at; cast metadata dan created_at; alias `log_type` ke `event_type`; relation order, changedBy, dan newStatus.
- Redundansi/minimalisasi: satu table dipakai juga oleh `OrderStatusHistory`, jadi ada duplikasi model/alias terhadap `order_events`; bisa dirapikan setelah jelas mana event status dan mana event domain.
- Service fee notice: metadata bisa berisi pricing snapshot, fee breakdown, atau careful-carry dari service lain; cleanup dilakukan di service pembuat log.
- Risiko: perubahan berdampak ke status timeline, negotiation logs, pricing recalculation, shopping item change request, dan driver payload history.

## Backend_Bangdeliv/app/Models/OrderPayment.php

- Fungsi: model pembayaran order.
- Logic penting: fillable method/status/amount/recorded_by/driver/paid_at/metadata; casts amount, paid_at, metadata; relation order, recordedBy user, dan driver.
- Redundansi/minimalisasi: sudah minimal; payment state di `Order` masih banyak dihitung dari relation ini.
- Service fee notice: tidak ada logic service fee/careful-carry langsung; amount harus mengikuti total final setelah refactor pricing.
- Risiko: perubahan berdampak ke COD/QRIS, record payment, order paid state, dan tracking/history payment info.

## Backend_Bangdeliv/app/Models/OrderStatus.php

- Fungsi: master status order.
- Logic penting: fillable code/display/is_terminal/sort; casts boolean dan integer; relation orders dan histories.
- Redundansi/minimalisasi: sudah minimal; kode status masih string, enum sudah ada di layer lain.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke semua status transition, filter order, history, dan dispatch.

## Backend_Bangdeliv/app/Models/OrderStatusHistory.php

- Fungsi: model histori perubahan status order di table `order_events`.
- Logic penting: timestamps off; fillable status_id/new_status_id/event/note/price_snapshot/created_at; casts status, metadata, created_at; alias `status_id` ke `new_status_id` dan `price_snapshot` ke `metadata`; relation order, changedBy, statusRef.
- Redundansi/minimalisasi: overlap dengan `OrderLog` pada table yang sama; komentar inline tentang migration bisa dibersihkan; perlu konsolidasi konsep `metadata` vs `price_snapshot` jika refactor event dilakukan.
- Service fee notice: `price_snapshot`/`metadata` bisa menyimpan fee breakdown lama; hapus payload service fee lines/careful-carry dari service pencatat, tetapi tetap simpan status penalti gagal 3 kali bila diperlukan.
- Risiko: perubahan berdampak ke timeline order, delivered_at fallback, driver assignment history, dispatch selector, dan status event tests.

## Backend_Bangdeliv/app/Models/Restaurant.php

- Fungsi: model merchant/restoran untuk katalog dan titik pickup.
- Logic penting: SoftDeletes; fillable identitas merchant, alamat, koordinat, kontak, banner, status; casts koordinat dan merchant_type; relation menuCategories, menus, orderLocations, dan orders via orderLocations.
- Redundansi/minimalisasi: sudah cukup minimal; relation `orders()` via `hasManyThrough` perlu hati-hati karena order bisa punya banyak pickup merchant.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke admin restaurant, home/menu API, chatbot Nitip merchant selection, dan shopping multi-stop.

## Backend_Bangdeliv/app/Models/RideOrder.php

- Fungsi: model detail khusus order antar-jemput.
- Logic penting: table `ride_order_details`; fillable order_id, picked_up_at, arrived_at; casts timestamp; relation order.
- Redundansi/minimalisasi: sudah minimal.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke ride order creation, driver workflow pickup/arrival, dan status tests antar-jemput.

## Backend_Bangdeliv/app/Models/ServiceFeeRule.php

- Fungsi: model master rule biaya layanan.
- Logic penting: fillable service_type_id, rule_code, rule_config, is_active, starts_at; casts config array, active boolean, starts_at datetime; relation serviceType.
- Redundansi/minimalisasi: kandidat hapus penuh jika master `service_fee_rules` dihapus. Pemakai utama masih `ShoppingPricingService` dan test `ShoppingServiceFeeRulesTest`.
- Service fee notice: target langsung plan service fee. Rule item surcharge dan overweight harus dihapus; rule `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS` dipertahankan secara konsep tetapi idealnya dipindah ke konstanta/config sederhana tanpa table master.
- Risiko: penghapusan memutus pricing shopping, migration seed `service_fee_rules`, dan test service fee rules sampai logic penalti dipindahkan.

## Backend_Bangdeliv/app/Models/ServiceType.php

- Fungsi: model master jenis layanan.
- Logic penting: fillable code/display/description/sort_order; cast sort_order; relation orders dan feeRules.
- Redundansi/minimalisasi: `feeRules()` menjadi kandidat hapus jika `ServiceFeeRule`/`service_fee_rules` dihapus; sisanya minimal.
- Service fee notice: hapus/refactor relation `feeRules()` bersama cleanup `ServiceFeeRule`.
- Risiko: perubahan berdampak ke order creation, routing service type, chatbot service selection, dan pricing yang masih query service type.

## Backend_Bangdeliv/app/Models/ShoppingReceipt.php

- Fungsi: model struk/total belanja shopping.
- Logic penting: table `shopping_order_receipts`; fillable order_id, total_amount, recorded_by_user_id, recorded_at; casts total dan waktu; relation order dan recordedBy.
- Redundansi/minimalisasi: sudah minimal; berbeda dari `OrderEvidence` karena ini menyimpan nominal receipt, bukan file bukti.
- Service fee notice: tidak ada service fee lines; `total_amount` harus dipertahankan sebagai total belanja aktual.
- Risiko: perubahan berdampak ke driver input total belanja, payment total, pricing lock, dan shopping receipt payload.

## Backend_Bangdeliv/app/Models/User.php

- Fungsi: model autentikasi user/customer/driver/admin.
- Logic penting: Sanctum, factory, notification, SoftDeletes; fillable profile dasar, role, avatar, active/blacklist, phone verification; hidden password/token; casts password hashed dan status boolean; relation driver, deviceTokens, addresses, orders, aiChatLogs.
- Redundansi/minimalisasi: komentar default Laravel bisa dibersihkan; relation belum diberi return type, bisa dirapikan ringan setelah audit.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke auth, profile, driver onboarding, order ownership, notification token, saved address, dan chatbot log.

## Backend_Bangdeliv/app/Providers/AppServiceProvider.php

- Fungsi: provider aplikasi untuk bootstrap global.
- Logic penting: mendefinisikan rate limiter `chatbot` dengan limit per menit/per jam dari config `bangdeliv.chatbot`, dibedakan per user login atau IP.
- Redundansi/minimalisasi: `register()` kosong dan komentar default Laravel bisa dibersihkan jika ingin clean; logic rate limiter sudah ringkas.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke throttle endpoint chatbot dan pengalaman user saat chat terlalu sering.

## Backend_Bangdeliv/app/Services/Address/AddressService.php

- Fungsi: service CRUD dan validasi alamat user.
- Logic penting: store/update menormalisasi alamat dan phone, menerima koordinat manual atau geocode via Google Maps, menjaga satu default address dalam transaction, dan validateAddress membatasi area layanan.
- Redundansi/minimalisasi: logic store/update banyak paralel; bisa diekstrak payload builder kecil agar lebih clean. `resolveAddressForWrite()` memakai alamat input sebagai formatted address saat geocode tanpa koordinat, perlu dipastikan memang sengaja.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke saved address, readiness chatbot, route picker, dan semua flow order yang memakai alamat tersimpan.

## Backend_Bangdeliv/app/Services/Address/ChatbotAddressReadinessService.php

- Fungsi: menentukan apakah user punya alamat tersimpan yang usable untuk chatbot dan membentuk payload lokasi.
- Logic penting: mencari address user, memprioritaskan default/latest, validasi full address, lat/lng valid, dan menolak koordinat 0,0.
- Redundansi/minimalisasi: `hasUsableSavedAddress()` dan `resolveDefaultUsableAddress()` sama-sama `get()` lalu filter di PHP; bisa diperkecil dengan helper query atau validasi terpusat jika data address membesar.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke guard chatbot sebelum membuka map/merchant/route picker dan auto-fill lokasi dari alamat default.

## Backend_Bangdeliv/app/Services/Catalog/RestaurantService.php

- Fungsi: service katalog restaurant/merchant untuk list, detail, dan menu payload.
- Logic penting: paginate merchant aktif dengan search/type/sort, transform payload list, hitung jarak haversine opsional, find detail by id/slug, buat detailPayload dan menusPayload dengan kategori/menu available.
- Redundansi/minimalisasi: service merangkap query dan transformer payload; sorting `nearest` dilakukan setelah pagination sehingga bukan nearest global. `isOpenNow()` hanya alias status active dan `operating_hours` masih array kosong.
- Service fee notice: tidak ada logic service fee/careful-carry; `price` menu adalah harga item merchant.
- Risiko: perubahan berdampak ke home merchant list, nearby merchant, detail merchant, menu list, dan chatbot Nitip merchant/menu selection.

## Backend_Bangdeliv/app/Services/Chatbot/ChatbotCourierOrderService.php

- Fungsi: service inti chatbot Kurir untuk membaca pesan/NLU, menyimpan progres draft, patch lokasi map, validasi draft, konfirmasi, membuat order, dan membentuk response chatbot.
- Logic penting: guard user customer aktif; ambil draft terakhir dari `AiChatLog`; command `confirm`/`reset_destination`; default pickup dari saved address; parser regex + NLU untuk pickup/dropoff/paket/payment; geocoding dan distance matrix; validasi jarak minimum/maksimum; evaluasi `CourierPackagePolicyService`; hitung `delivery_fee`; simpan `Order`, `CourierOrder`, `OrderLocation`, `OrderStatusHistory`, pending payment, lalu broadcast order available.
- Redundansi/minimalisasi: file sangat gemuk (1862 line) dan mencampur parser, state machine draft, validasi, map routing, pricing, persistence, payload builder, dan copywriting chatbot. Kandidat pecah minimal: customer guard, courier draft normalizer, courier payload builder, route/distance resolver, dan courier order creator.
- Redundansi/minimalisasi detail: payload `courier/validation/order/action_payloads` berulang di `handleResetDestination()`, `confirmPendingDraft()`, `buildValidationPayload()`, dan `buildDraftPayload()`; validasi package/distance/pricing dihitung di draft lalu dihitung ulang saat create; `resolveLatestDraftSeed()` dan `resolvePendingDraft()` sama-sama query `AiChatLog` dan normalisasi field courier; normalisasi field paket/lokasi/payment tersebar di `buildDraftSeedFromNlu()`, `mergeCourierDraftSeed()`, dan resolver draft.
- Redundansi/dead code: `extractAddressByPatterns()` tidak dipakai; `formatPackageSizeLine()` tidak dipakai; jika `extractAddressByPatterns()` dihapus maka `isLikelyAddressFragment()` dan array `locationHints` juga kemungkinan dead. Ini kandidat hapus paling aman setelah test courier flow dicek.
- Service fee notice: tidak ada `OrderFeeLine`, `ServiceFeeRule`, `feeLines`, atau `careful_carry_required` langsung. `delivery_fee` adalah ongkir dan harus dipertahankan; `service_fee` hanya diset `0.0` saat create order dan bisa disederhanakan/di-default-kan. `delivery_pricing` dalam route snapshot mungkin punya `fee_breakdown` dari `DeliveryPricingService`, perlu dicek di service pricing apakah ada sisa service fee lines.
- Risiko: sangat tinggi karena banyak test `ChatbotCourierFlowTest`, frontend action payload route picker, session history chatbot, payment method COD/QRIS, geocoding/distance matrix, dan create order kurir bergantung pada bentuk payload file ini.

## Backend_Bangdeliv/app/Services/Chatbot/ChatbotGeminiService.php

- Fungsi: adapter NLU Gemini untuk Nitip, Kurir, dan Antar Jemput.
- Logic penting: membangun system instruction dan response schema per layanan; `generateJson()` mencoba beberapa model Gemini dari config, mengirim request JSON schema, handle 429 dengan fallback model berikutnya, lalu normalisasi payload food/courier/ride sebelum dipakai service order.
- Redundansi/minimalisasi: schema item food terduplikasi antara `items` dan `stops.items`; normalisasi item juga berulang di dua loop. Instruksi/schema/fallback untuk tiap layanan inline di satu file, sehingga bisa diperkecil dengan helper pembuat schema atau config prompt terpisah.
- Redundansi/minimalisasi detail: `normalizeCommand()` menyatukan command Nitip/Kurir/Ride sehingga command `add_merchant` tetap ada walau transport tidak memakai; aman saat ini, tetapi bisa dibuat per-service agar kontrak lebih ketat. `generateJson()` tidak short-circuit saat API key kosong, sehingga tetap mencoba request dan baru gagal 503.
- Service fee notice: tidak ada `ServiceFeeRule`, `OrderFeeLine`, `feeLines`, `fee_breakdown`, atau `careful_carry_required`. Prompt Nitip menyuruh AI tidak menentukan item berat; ini sejalan dengan cleanup overweight/item surcharge karena keputusan berat/biaya tidak boleh dari NLU. Field berat/ukuran Kurir tetap dipakai untuk package policy, bukan service fee lines.
- Risiko: perubahan berdampak ke semua alur chatbot karena payload NLU menentukan parser backend, session draft, command confirm/reset/add merchant, dan banyak feature test chatbot.

## Backend_Bangdeliv/app/Services/Chatbot/ChatbotOrderValidationService.php

- Fungsi: validator legacy payload chatbot makanan/Nitip terhadap data restaurant dan menu.
- Logic penting: hanya menerima intent `pesan_makanan`, normalisasi resto/items, cari restaurant aktif by name/slug, cari menu available by name dan optional restaurant, lalu membentuk matched/unmatched items serta alasan rejection.
- Redundansi/minimalisasi: kemungkinan legacy karena alur baru memakai `shopping_order` dan `ChatbotShoppingOrderService`; pemakaian utama yang terlihat hanya unit test `ChatbotOrderValidationServiceTest`. Perlu putuskan apakah service ini masih dipakai runtime atau bisa dihapus/diarsipkan.
- Redundansi/minimalisasi detail: matching restaurant/menu masih `LIKE` sederhana dan hanya mendukung satu restoran, sedangkan flow Nitip baru sudah multi-stop/merchant. `normalizeItems()` hanya membaca `menu/qty`, tidak membaca schema baru `name/quantity/operation/notes`.
- Service fee notice: tidak ada `ServiceFeeRule`, `OrderFeeLine`, `feeLines`, `fee_breakdown`, `careful_carry_required`, atau surcharge item/overweight.
- Risiko: jika masih ada endpoint lama yang memakai intent `pesan_makanan`, penghapusan bisa memutus validasi makanan lama; jika hanya test legacy, ini kandidat cleanup yang cukup aman setelah konfirmasi pemakaian runtime.

## Backend_Bangdeliv/app/Services/Chatbot/ChatbotRideOrderService.php

- Fungsi: service chatbot Antar Jemput untuk membaca pesan/NLU, menyimpan draft, patch titik route, validasi draft, konfirmasi, dan membuat order lewat `RideOrderService`.
- Logic penting: guard customer aktif; command confirm/reset; default pickup dari saved address; destination dari NLU/regex/map patch; validasi tujuan via `RideOrderService`, route via distance matrix, minimum/maksimum jarak, hitung `delivery_fee`, payload COD/QRIS, dan pending draft dari `AiChatLog`.
- Redundansi/minimalisasi: sangat paralel dengan `ChatbotCourierOrderService` untuk guard user, patch location, reset destination, payload `ride/validation/order/action_payloads`, draft seed merge, latest/pending draft query, distance helper, command/payment helpers, dan copywriting. Kandidat refactor bersama ke shared transport draft/payload helper setelah dua service dibaca lengkap.
- Redundansi/minimalisasi detail: dependency `GoogleMapsGeocodingService $geocodingService` di-constructor tidak dipakai langsung; validasi tujuan sudah lewat `RideOrderService`. `resolveLatestDraftSeed()` dan `resolvePendingDraft()` melakukan query/log parsing serupa; `buildMissingDraftPayload()` dan validation payload mengulang struktur response yang sama.
- Service fee notice: tidak ada `OrderFeeLine`, `ServiceFeeRule`, `feeLines`, `fee_breakdown`, atau `careful_carry_required`. `delivery_fee` adalah ongkir dan harus dipertahankan; service fee lines tidak muncul di file ini.
- Risiko: tinggi karena payload ride dipakai frontend route picker, confirm/reset chatbot, session history, payment COD/QRIS, `RideOrderService::create()`, dan test flow antar jemput/driver workflow.

## Backend_Bangdeliv/app/Services/Chatbot/ChatbotShoppingItemIntentParser.php

- Fungsi: parser ringan untuk intent tambah/set/hapus item belanja Nitip dari pesan teks tanpa Gemini.
- Logic penting: normalisasi pesan, deteksi operation add/set/remove dari kata kunci, split item dengan koma/plus/dan, ekstrak quantity `2x` atau angka untuk set, bersihkan kata perintah/merchant tail, dan hasilkan item dengan `name`, `quantity`, `operation`, `notes`, `is_heavy`.
- Redundansi/minimalisasi: regex parser cukup kecil dan fokus; beberapa normalisasi item juga ada di `ChatbotGeminiService` dan `ChatbotShoppingOrderService`, jadi nanti bisa disatukan lewat item normalizer kecil.
- Redundansi/minimalisasi detail: `stripMerchantTail()` agresif menghapus semua tail `di/dari ...`, berisiko pada nama item yang memang mengandung frasa itu; perlu test sebelum mengubah. `notes` selalu null dan `is_heavy` selalu false dari parser ini.
- Service fee notice: tidak ada `ServiceFeeRule`, `OrderFeeLine`, `feeLines`, atau `fee_breakdown`. Field `is_heavy` adalah jalur lama menuju overweight surcharge; karena parser selalu false, tidak menghitung fee, tetapi field ini perlu ditinjau/hapus bersama cleanup overweight surcharge.
- Risiko: perubahan berdampak ke quick edit item di chatbot Nitip, terutama perintah tambah/set/remove tanpa Gemini dan test shopping flow.

## Backend_Bangdeliv/app/Services/Chatbot/ChatbotShoppingOrderService.php

- Fungsi: service inti chatbot Nitip untuk draft multi-merchant, patch merchant/delivery map, item merge, route/pricing, konfirmasi, create order, dan response chatbot.
- Logic penting: guard customer; command confirm/add merchant/payment; incoming seed dari Gemini/parser teks; merge draft multi-stop maksimal 3 merchant; resolve merchant internal/external map; resolve delivery default/map/chat text; resolve item dari menu DB atau manual; hitung route via `ShoppingRouteService`; hitung pricing via `ShoppingPricingService`; create `Order`, pickup/dropoff `OrderLocation`, `OrderItem`, `OrderStatusHistory`, pending payment, dan broadcast order.
- Redundansi/minimalisasi: file sangat gemuk (1878 line) dan mencampur state machine draft, multi-merchant merge, item parser fallback, merchant resolver, delivery resolver, route/pricing, persistence, payload/action builder, dan copywriting. Kandidat pecah minimal: draft state/stop merger, item normalizer, payload builder, order creator, dan assistant text builder.
- Redundansi/minimalisasi detail: `mergeIncomingStop()` paling kompleks dan rawan karena mengatur active stop, same merchant, mode select/add/auto, limit merchant, dan item merge; `syncLegacySeedFields()` menandakan masih ada kompatibilitas legacy single-merchant. Normalisasi item muncul di `normalizeIncomingItems()`, `extractItemsFromMessage()`, `mergeItems()`, `resolveLatestDraftSeed()`, dan parser terpisah.
- Service fee notice: ini target besar service fee cleanup. Kandidat hapus/refactor: `ShoppingPricingService->calculateForItems()` dependency pada service fee rules, `service_fee` dari pricing, `syncFeeLines($order, $pricing['fee_breakdown'])`, relation eager load `feeLines`, `emptyPricing()` field `item_surcharge`, `overweight_surcharge`, `has_overweight_item`, serta seluruh penggunaan `is_heavy` untuk overweight surcharge.
- Service fee notice: `delivery_fee` adalah ongkir dan tetap dipertahankan. `cancellation_penalty`/penalti gagal pickup merchant 3 kali perlu dipertahankan secara konsep, tetapi jangan lagi bergantung ke generic fee lines setelah cleanup.
- Risiko: sangat tinggi karena file ini menjadi pusat Chatbot Nitip, multi-merchant, item manual/menu DB, payment COD/QRIS, route/pricing, order creation, frontend action payload, driver payload pricing, dan banyak feature test `ChatbotShoppingFlowTest`/shopping item edit.

## Backend_Bangdeliv/app/Services/Chatbot/CourierPackagePolicyService.php

- Fungsi: policy validasi paket Kurir berdasarkan deskripsi, berat, ukuran, keyword terlarang, oversize, dan kebutuhan klarifikasi.
- Logic penting: status `ALLOWED/NEEDS_CLARIFICATION/PROHIBITED/OVERSIZE`; batas 10 kg dan dimensi 40 cm; keyword prohibited/oversize/clarification; ekstrak berat/ukuran dari payload atau teks; return safety flags, reason, size class, estimated weight/dimensions, dan packing note.
- Redundansi/minimalisasi: rule masih hardcoded di service, tetapi cukup terlokalisir. Bisa dipertahankan sebagai policy domain kecil; jika butuh config, pindahkan keyword/batas ke config tanpa membuat table baru.
- Redundansi/minimalisasi detail: `STATUS_OVERSIZE` didefinisikan tetapi `resolveStatus()` tidak pernah mengembalikannya; oversize hanya muncul sebagai `size_class`. Ini membingungkan dan bisa disederhanakan atau dibuat konsisten.
- Service fee notice: tidak ada service fee lines langsung, tetapi teks reason menyebut "memakai bantuan 2 orang" tiga kali. Karena plan menghapus logic Courier "perlu 2 orang"/careful-carry, ubah teks ini agar hanya menyebut driver dapat menyesuaikan ongkir/manual handling, tanpa konsep bantuan 2 orang.
- Risiko: perubahan berdampak ke `ChatbotCourierOrderService` dan `ChatbotCourierFlowTest` untuk status prohibited/oversize/clarification serta copywriting validasi paket.

## Backend_Bangdeliv/app/Services/Device/DeviceTokenService.php

- Fungsi: service registrasi dan deaktivasi device token push notification.
- Logic penting: `register()` memakai `updateOrCreate` berdasarkan token untuk set user/device_type/is_active; `deactivate()` menonaktifkan token milik user tertentu.
- Redundansi/minimalisasi: sudah sangat minimal. Perlu disadari token yang sama bisa berpindah user karena key update hanya `token`, ini mungkin memang sengaja untuk device re-login.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke sync token frontend, FCM notification, dan logout/deactivate token.

## Backend_Bangdeliv/app/Services/Driver/Dispatch/DriverCandidateSelector.php

- Fungsi: memilih kandidat driver aktif/available untuk order dan memberi metadata dispatch per driver.
- Logic penting: query driver aktif, user role driver aktif dan tidak blacklist; exclude driver yang pernah reject order via `OrderStatusHistory`; buat metadata dispatch, sort kandidat dari jarak pickup terdekat, lokasi terbaru, lalu id; isi ulang `priority_rank` setelah ranking final.
- Redundansi/minimalisasi: metadata dispatch dihitung dua kali untuk semua kandidat, pertama untuk sorting dan kedua untuk memasukkan rank. `dispatchForDriver()` juga memanggil `candidatesForOrder()` sehingga bisa melakukan full scan/recompute hanya untuk satu driver. Kandidat refactor: hitung metadata sekali lalu tambahkan rank ke array yang sama, atau sediakan method rank/metadata tunggal.
- Service fee notice: tidak ada logic service fee/careful-carry; `distance_to_pickup_meters` hanya untuk urutan dispatch, bukan fee.
- Risiko: perubahan berdampak ke urutan broadcast driver, driver rejection filtering, dan payload realtime driver order.

## Backend_Bangdeliv/app/Services/Driver/Dispatch/DriverDispatchMetadataFactory.php

- Fungsi: membentuk metadata dispatch driver terhadap pickup order.
- Logic penting: resolve titik pickup order, cek freshness lokasi driver dari config `bangdeliv.dispatch.fresh_location_minutes`, validasi koordinat driver/pickup, hitung jarak haversine, label jarak, bucket `near/medium/far/unknown`, dan optional `priority_rank`.
- Redundansi/minimalisasi: pickup order di-resolve ulang setiap driver, padahal nilainya sama untuk satu order; config threshold juga dibaca per driver. Kandidat clean: resolve pickup sekali di selector atau cache ringan per order saat ranking. Label selalu "titik jemput" walau shopping pickup bisa merchant, masih aman tetapi kurang spesifik.
- Service fee notice: tidak ada logic service fee/careful-carry; bucket jarak dispatch tidak terkait `ServiceFeeRule` atau `OrderFeeLine`.
- Risiko: perubahan berdampak ke sorting kandidat, badge jarak driver, dan fallback "Jarak belum tersedia" saat koordinat/lokasi tidak fresh.

## Backend_Bangdeliv/app/Services/Driver/Dispatch/DriverDistanceCalculator.php

- Fungsi: helper validasi koordinat dan hitung jarak haversine dalam meter.
- Logic penting: koordinat harus numeric, range latitude/longitude valid, tidak boleh 0,0; `distanceMeters()` memakai radius bumi 6.371.000 meter dan return integer rounded.
- Redundansi/minimalisasi: sudah kecil dan fokus. Namun logika haversine juga ada di area lain seperti katalog/restaurant distance; nanti bisa dipertimbangkan jadi geo helper bersama bila refactor global, tanpa memaksa perubahan sekarang.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan formula/validasi berdampak ke ranking driver dan semua metadata jarak dispatch.

## Backend_Bangdeliv/app/Services/Driver/Dispatch/OrderPickupPointResolver.php

- Fungsi: menentukan titik pickup order untuk kebutuhan dispatch driver.
- Logic penting: untuk Shopping ambil `OrderLocation` role `PICKUP` paling awal berdasarkan `sequence_no/id`, fallback ke restaurant address/coordinate; untuk layanan lain ambil lokasi role `PICKUP`; konversi koordinat kosong ke null.
- Redundansi/minimalisasi: non-shopping memakai `first()` tanpa sorting eksplisit, berbeda dengan shopping yang disortir. Jika orderLocations tidak dipreload, akses collection bisa memicu lazy load. Kandidat clean: samakan helper pencarian pickup role dengan sorting deterministik dan fallback yang jelas per service.
- Service fee notice: tidak ada logic service fee/careful-carry; ini hanya titik acuan dispatch.
- Risiko: perubahan berdampak ke ranking driver terdekat, terutama order shopping multi-location dan order yang koordinat pickup-nya belum lengkap.

## Backend_Bangdeliv/app/Services/Driver/DriverOnboardingService.php

- Fungsi: service upgrade akun customer menjadi driver.
- Logic penting: hanya role `customer` yang boleh upgrade; tolak jika sudah punya profil driver; dalam transaction buat `Driver` status `pending/offline` dari payload kendaraan/lisensi lalu update role user menjadi `driver`; return user ringkas dan driver profile.
- Redundansi/minimalisasi: sudah kecil dan fokus. Potensi clean kecil: trimming payload dilakukan langsung di service dan bergantung pada request validation upstream; bisa dipertahankan selama `UpgradeToDriverRequest` tetap menjadi sumber validasi.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke alur register/upgrade driver, status verifikasi driver, middleware role, dan profil driver frontend.

## Backend_Bangdeliv/app/Services/Driver/DriverOrderPayloadFactory.php

- Fungsi: factory payload order untuk sisi driver, termasuk relasi eager load, detail pickup/dropoff, proof, pricing, shopping items/stops, action driver, timeline, dan snapshot negosiasi ongkir.
- Logic penting: `serialize()` menentukan service/status/payment, resolve pickup/dropoff, bukti foto dan status bukti, pending manual price Nitip, shopping negotiation, delivery fee negotiation, available actions per layanan, pricing snapshot, route snapshot, merchant/items/stops Nitip, dan optional status timeline. `driverActionRules()` memuat flow action Ride/Courier/Shopping, termasuk COD collection dan cancel Nitip dengan fee setelah threshold gagal pickup.
- Redundansi/minimalisasi: file cukup gemuk (856 line) karena mencampur serializer payload, rule action driver, proof serialization, pricing/fee breakdown, shopping stop/item serializer, dan helper status. `serializeShoppingItems()` dan `serializeShoppingItem()` hampir duplikat; `orderRouteSnapshot()` dan `shoppingRouteSnapshot()` juga redundant. Kandidat pecah minimal: action rule provider, shopping payload serializer, proof serializer, dan pricing payload builder.
- Service fee notice: target cleanup besar. Kandidat hapus/refactor: eager relation `feeLines`, payload `fee_breakdown` generic, `pricing.shopping_pricing.fee_breakdown`, `ShoppingPricingService->feeLineAmount()` untuk `ITEM_BLOCK_SURCHARGE` dan `OVERWEIGHT_FLAT_SURCHARGE`, field `item_surcharge`, `overweight_surcharge`, `is_heavy`, `careful_carry_required`, `careful_carry` breakdown, `carefulCarryRequired()`, dan `carefulCarrySurchargeFromOrder()`.
- Service fee notice: `delivery_fee`, `delivery_fee_source`, `delivery_fee_negotiation`, dan manual override driver tetap dipertahankan. `CANCEL_WITH_FEE`, `CANCELLED_WITH_FEE`, dan `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS` tetap dipertahankan sebagai penalti 50% setelah gagal pickup 3 kali, tetapi nanti jangan bergantung ke generic `OrderFeeLine`.
- Risiko: sangat tinggi karena payload ini dipakai driver active order, driver order list/detail, realtime order available/content update, proof upload gating, checkout Nitip, negosiasi ongkir, action state machine driver, dan beberapa model frontend yang membaca pricing/shopping/action payload.

## Backend_Bangdeliv/app/Services/Driver/DriverOrderRealtimeService.php

- Fungsi: service broadcast realtime order tersedia/dihapus untuk driver.
- Logic penting: `broadcastOrderAvailable()` refresh order dengan relasi dari `DriverOrderPayloadFactory`, ambil kandidat dari `DriverCandidateSelector`, serialize payload order, tambahkan metadata dispatch per driver, lalu broadcast `DriverOrderAvailable`. Removal broadcast dikirim ke semua driver available atau satu driver tertentu dengan `DriverOrderRemoved`; semua broadcast dibungkus `safelyBroadcast()` dengan log debug/warning.
- Redundansi/minimalisasi: sudah cukup kecil. `availableDriverUserIds(?Order $order = null)` punya parameter order tetapi caller removal tidak mengirim order, sehingga removal dikirim ke semua driver available tanpa filter reject/order; ini mungkin sengaja untuk membersihkan list global, tapi perlu disadari. Payload heavy karena serialize penuh dilakukan per kandidat, termasuk bagian yang sama antar driver selain `dispatch`.
- Service fee notice: tidak membuat logic service fee/careful-carry sendiri, tetapi menyiarkan payload dari `DriverOrderPayloadFactory`; setelah cleanup factory, event realtime otomatis mengikuti bentuk payload baru.
- Risiko: perubahan berdampak ke realtime order available/removed frontend driver, ranking dispatch per driver, dan sinkronisasi list order saat order diterima/dibatalkan.

## Backend_Bangdeliv/app/Services/Driver/DriverVerificationService.php

- Fungsi: service upload, status, queue admin, review, delete, dan preview dokumen verifikasi driver.
- Logic penting: driver upload dokumen wajib `ktp/sim/selfie`, simpan file public dan `updateOrCreate` dokumen sebagai pending, reset registration/status ke pending/offline; admin list queue dengan filter/search/status, review dokumen dan tentukan registration status, delete file dokumen, preview file via storage response, dan build payload status dokumen.
- Redundansi/minimalisasi: file sedang (472 line) tapi domainnya masih jelas. Ada pola cek role admin/driver berulang di beberapa method; mapping payload dokumen mengecek `Storage::exists()` per dokumen sehingga queue besar bisa cukup mahal. `queueSummaryCounts()` menghitung `needs_revision` dari dokumen rejected walau driver bisa punya status lain, perlu konsisten dengan kebutuhan UI.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke upload dokumen driver, dashboard/admin verification, preview file storage public, status driver aktif/pending/rejected, dan middleware driver active.

## Backend_Bangdeliv/app/Services/Home/HomeService.php

- Fungsi: menyusun payload home customer berisi kategori, popular menus, dan nearby merchants.
- Logic penting: filter search restaurant/menu, limit merchant/menu/category, optional lokasi user, eager load kategori/menu aktif, sort merchant berdasarkan pendekatan `distance_sort` saat lokasi ada, lalu hitung `distance_km` haversine untuk payload.
- Redundansi/minimalisasi: cukup fokus, tetapi logika jarak muncul lagi di sini terpisah dari `DriverDistanceCalculator`/`RestaurantService`; `distance_sort` SQL memakai kuadrat beda lat/lng sederhana, sedangkan payload memakai haversine, jadi urutan nearby bisa sedikit berbeda dari jarak yang ditampilkan. Kandidat clean: pakai helper geo bersama atau query distance yang konsisten.
- Service fee notice: tidak ada logic service fee/careful-carry; `price` popular menu hanya harga item merchant.
- Risiko: perubahan berdampak ke home screen, search merchant/menu, nearby merchants, kategori home, dan urutan popular menu.

## Backend_Bangdeliv/app/Services/Maps/GoogleMapsDistanceMatrixService.php

- Fungsi: service kalkulasi rute Google Maps untuk single-leg route dan rute belanja multi-pickup.
- Logic penting: `resolveRoute()` mencoba Routes API lalu fallback Distance Matrix; validasi status Google API dan element route; output distance/duration/segments/polyline/provider. `resolveOptimizedShoppingRoute()` memilih origin kandidat terbaik untuk multi-merchant, menghitung route optimized via Routes API, fallback travel mode `TWO_WHEELER` ke `DRIVE`, dan menyusun `ordered_pickup_location_ids` serta segment per leg.
- Redundansi/minimalisasi: banyak struktur output distance/duration/segment diulang antara Distance Matrix fallback, Routes API single-leg, dan optimized route. Request Routes API single-leg dan optimized punya pola header/config/parse yang mirip; kandidat clean: helper response normalizer/route DTO kecil agar output konsisten.
- Service fee notice: tidak ada `service_fee`, `feeLines`, `OrderFeeLine`, atau careful-carry. Output route/distance dapat dipakai service pricing ongkir, tetapi file ini tidak menghitung service fee lines.
- Risiko: perubahan berdampak ke chatbot Kurir/Ride/Nitip route validation, `delivery_distance_*`, route snapshot, optimized shopping route, polyline tracking, dan kalkulasi ongkir berbasis jarak.

## Backend_Bangdeliv/app/Services/Maps/GoogleMapsGeocodingService.php

- Fungsi: service geocoding/reverse geocoding Google Maps, place lookup, dan validasi service area.
- Logic penting: `resolveAddress()` geocode alamat dengan optional batas service area; `reverseGeocode()` validasi koordinat; `reverseGeocodeWithPlaceName()` enrich pin dengan Places Nearby Search; `resolvePlace()` pakai Places Text Search lalu fallback geocode; `selectBestResult()` memilih hasil Google dengan scoring location type/types/partial match; service area memakai bounds/config.
- Redundansi/minimalisasi: cek API key, request config bahasa/region/timeout, fallback ke `resolveAddress()`, dan parsing result muncul berulang. Endpoint Places Nearby/Text Search masih hardcoded. Ada komentar yang tampak mojibake sehingga saat refactor bisa sekalian dibersihkan ke ASCII/teks normal.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke validasi alamat tersimpan, map pin address, chatbot lokasi, merchant/place picker, service-area restriction, dan pesan error geocoding di frontend.

## Backend_Bangdeliv/app/Services/Notification/ChatPushNotificationService.php

- Fungsi: mengirim push notification FCM untuk chat order.
- Logic penting: menentukan recipient lawan bicara berdasarkan sender customer/driver, membangun `CloudMessage` dengan title/body singkat, data route `/orders/{id}/chat`, channel Android chat high, lalu delegasi pengiriman ke `UserPushNotificationSender`.
- Redundansi/minimalisasi: sudah kecil dan fokus. Route chat masih hardcoded customer route; jika driver chat screen punya route berbeda, perlu disinkronkan dengan notification navigation frontend.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke notifikasi chat customer/driver, unread/chat navigation, dan FCM data payload.

## Backend_Bangdeliv/app/Services/Notification/OrderPricingPushNotificationService.php

- Fungsi: mengirim push notification perubahan harga/order total, termasuk negosiasi ongkir dan harga belanja.
- Logic penting: `sendPriceChanged()` normalisasi recipient role, hindari notify actor sendiri, kirim message dengan change type, amount, old/new total, event id, pickup location, focus, dan route driver/customer. `sendOrderTotalChanged()` notify lawan actor jika order sudah punya driver dan total berubah. Copy notifikasi membedakan delivery fee, counter, approved, cancel, dan perubahan total.
- Redundansi/minimalisasi: deteksi `str_contains($type, 'FEE')` terlalu luas sehingga semua change type yang mengandung `FEE` dianggap fokus `delivery_fee`; setelah service fee cleanup sebaiknya dipersempit ke `DELIVERY_FEE`/ongkir manual saja. Copy notifikasi pricing dan route focus bisa dipindah ke enum/helper change type agar tidak bergantung substring.
- Service fee notice: tidak ada `OrderFeeLine`/`fee_breakdown`, tetapi perlu refactor agar tidak lagi menganggap semua `FEE` sebagai delivery fee. Pertahankan notifikasi negosiasi `delivery_fee` dan perubahan total belanja; penalti `CANCELLED_WITH_FEE` tetap boleh diberi copy khusus, tetapi jangan dikaitkan ke generic service fee lines.
- Risiko: perubahan berdampak ke notifikasi tawar ongkir customer-driver, shopping price approval/counter/cancel, route focus tracking, dan heads-up notification frontend.

## Backend_Bangdeliv/app/Services/Notification/OrderRealtimeBroadcaster.php

- Fungsi: wrapper broadcast realtime untuk chat, lokasi driver, status order, dan content/pricing update.
- Logic penting: setiap method membentuk event (`OrderChatMessageSent`, `DriverLocationUpdated`, `OrderStatusChanged`, `OrderContentUpdated`) lalu memakai `safelyBroadcast()` untuk log sukses/gagal dan return boolean.
- Redundansi/minimalisasi: pola broadcast aman sama dengan `DriverOrderRealtimeService`; bisa disatukan di trait/helper kecil bila ingin minimalisasi logging broadcast. Method `orderContentUpdated()` menerima array `$pricing` bebas sehingga kontraknya bergantung caller/event.
- Service fee notice: tidak membuat service fee sendiri, tetapi dapat menyiarkan `$pricing` dari caller. Saat cleanup, pastikan caller tidak lagi mengirim `fee_breakdown`, service fee lines, item/overweight surcharge, atau careful-carry.
- Risiko: perubahan berdampak ke realtime chat, tracking lokasi driver, status timeline customer, dan refresh content/pricing order di frontend.

## Backend_Bangdeliv/app/Services/Notification/OrderStatusPushNotificationService.php

- Fungsi: mengirim push notification perubahan status order ke customer.
- Logic penting: load user/status/service, build message berdasarkan status code dan service code, route ke `/orders/{id}/track`, data previous status/history id, dan channel Android order status high.
- Redundansi/minimalisasi: copy status hardcoded di match; cukup kecil, tetapi bisa dipindah ke helper status copy bila status makin banyak. `CANCELLED_WITH_FEE` memakai copy umum "biaya sesuai ketentuan" tanpa detail nominal.
- Service fee notice: tidak ada service fee lines. Status `CANCELLED_WITH_FEE` tetap dipertahankan sesuai exception penalti 50% setelah gagal pickup 3 kali; copy bisa dibuat lebih eksplisit nanti tanpa menghidupkan generic service fee.
- Risiko: perubahan berdampak ke notifikasi status customer, route tracking, dan ekspektasi copy status lintas layanan.

## Backend_Bangdeliv/app/Services/Notification/PaymentProofReminderNotificationService.php

- Fungsi: mengirim dan menjadwalkan reminder upload bukti pembayaran QRIS/transfer.
- Logic penting: throttle reminder per order-user, chain reminder setelah status `DELIVERED`, skip actor customer, skip terminal status termasuk `CANCELLED_WITH_FEE`, cek payment terbaru metode transfer belum paid, cek evidence `PAYMENT_TRANSFER_PHOTO`, lalu kirim push route `/orders/{id}/track?focus=payment`.
- Redundansi/minimalisasi: sudah cukup fokus. Query latest payment/evidence mendukung loaded relation dan query fallback; pola ini bisa dipertahankan. Konstanta delay/throttle hardcoded di service, bisa dipindah config jika perlu tuning.
- Service fee notice: tidak ada logic service fee/careful-carry. `CANCELLED_WITH_FEE` hanya dipakai sebagai terminal status agar reminder bukti bayar berhenti.
- Risiko: perubahan berdampak ke QRIS transfer flow, completion order, payment proof upload, job reminder chain, dan push notification customer.

## Backend_Bangdeliv/app/Services/Notification/UserPushNotificationSender.php

- Fungsi: pengirim FCM reusable untuk user tertentu.
- Logic penting: ambil token android aktif user, kirim multicast via Firebase Messaging, nonaktifkan token invalid/unknown, log partial failure atau failure total, return true jika ada success.
- Redundansi/minimalisasi: sudah sangat minimal dan menjadi helper shared notification. Saat ini hanya `device_type = android`; jika iOS/web diperlukan, filter ini harus diperluas.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke semua push notification yang memakai service ini, termasuk chat, status order, pricing, dan reminder payment proof.

## Backend_Bangdeliv/app/Services/Order/DeliveryFeeNegotiationService.php

- Fungsi: service pencatatan dan snapshot negosiasi ongkir antara driver dan customer.
- Logic penting: memakai `OrderNegotiationLogService` dengan event `DELIVERY_FEE_NEGOTIATION`; trigger quote/approve/counter/requote/cancel; snapshot status pending customer/driver/approved/cancelled; blokir progress driver saat revisi ongkir pending; validasi order/status yang boleh edit; cegah quote jika payment sudah paid atau bukti transfer sudah ada; default nominal dari route pricing atau `order.delivery_fee`.
- Redundansi/minimalisasi: domain ongkirnya jelas dan perlu dipertahankan. Potensi clean: status trigger bisa dibuat enum/helper agar tidak mengulang string; `hasPendingTransferEvidence()` namanya "pending" tapi hanya cek adanya evidence transfer, bukan status verifikasi; `supportsOrder()` menerima semua layanan sehingga rule block action harus tetap sinkron dengan flow driver.
- Service fee notice: fitur ini tetap dipertahankan karena berhubungan dengan ongkir manual/negosiasi driver. Kandidat hapus/refactor hanya bagian careful-carry: `careful_carry_required`, `careful_carry_surcharge`, parameter `carefulCarryRequired` di `quoteAmounts()`, rumus surcharge 50%, dan `carefulCarryRequired()`. Setelah cleanup, `quoteAmounts()` cukup base/final ongkir tanpa surcharge service-fee/careful-carry.
- Risiko: tinggi karena dipakai payload driver/customer, action gating driver, notifikasi pricing, payment transfer guard, dan flow negosiasi ongkir manual yang memang jadi alasan service fee lines tidak perlu dipertahankan.

## Backend_Bangdeliv/app/Services/Order/DriverArrivalEtaService.php

- Fungsi: menghitung ETA kedatangan driver untuk customer tracking.
- Logic penting: resolve target order via `OrderEtaTargetResolver`, cek lokasi driver fresh, validasi koordinat driver, cache ETA 45 detik berdasarkan order/driver/status/target/koordinat/timestamp lokasi, hitung route via `GoogleMapsDistanceMatrixService`, lalu return duration/distance/estimated arrival/provider.
- Redundansi/minimalisasi: freshness lokasi dan validasi koordinat mirip dengan dispatch metadata; format durasi/jarak juga mirip service Maps. Kandidat clean: helper geo/location freshness bersama agar behavior ranking driver dan ETA konsisten.
- Service fee notice: tidak ada logic service fee/careful-carry; distance di sini hanya untuk ETA tracking, bukan fee.
- Risiko: perubahan berdampak ke tracking screen customer, cache ETA, biaya request Google Maps, dan update saat lokasi driver berubah.

## Backend_Bangdeliv/app/Services/Order/OrderChatService.php

- Fungsi: service list/send/read chat order antara customer dan driver.
- Logic penting: authorize order untuk customer pemilik atau driver assigned; pagination before/after id; unread summary via `OrderChatRead`; send message dengan optional attachment public storage; idempotency via `client_message_id`; broadcast realtime dan push notification saat message baru.
- Redundansi/minimalisasi: cukup fokus tapi masih mencampur authorization, pagination, read state, upload attachment, serializer, broadcast, dan push. `resolveReadableOrder()` query driver id tiap request driver; bisa dipadatkan jika auth session sudah punya driver relation. Route attachment hanya URL public, tidak menyimpan path untuk cleanup file.
- Service fee notice: tidak ada logic service fee/careful-carry.
- Risiko: perubahan berdampak ke chat realtime, unread badge, upload bukti/attachment chat, push chat notification, dan idempotency pesan frontend.

## Backend_Bangdeliv/app/Services/Order/OrderEtaTargetResolver.php

- Fungsi: menentukan target koordinat ETA driver untuk customer tracking.
- Logic penting: Ride/Courier pada `DRIVER_ASSIGNED` target ke pickup via `OrderPickupPointResolver`; Shopping pada `ON_THE_WAY` target ke dropoff customer; validasi koordinat lat/lng sebelum return target.
- Redundansi/minimalisasi: coordinate validation sama dengan `DriverArrivalEtaService` dan dispatch; label pickup selalu "Titik jemput" walau Courier bisa "Titik ambil". Untuk Shopping ETA hanya saat `ON_THE_WAY`, belum ada ETA ke merchant saat driver assigned/arrived flow.
- Service fee notice: tidak ada logic service fee/careful-carry; target hanya untuk ETA tracking.
- Risiko: perubahan berdampak ke ETA tracking customer dan pemilihan titik tujuan berdasarkan status order.

## Backend_Bangdeliv/app/Services/Order/OrderNegotiationLogService.php

- Fungsi: helper generic untuk mencatat dan membaca log negosiasi order serta normalisasi tipe data metadata.
- Logic penting: `record()` membuat `OrderLog` dengan order, event/log type, trigger, actor, note, metadata; `latest()` mencari log terbaru untuk event type; helper `iso/intOrNull/floatOrNull/boolOrNull` dipakai snapshot negosiasi.
- Redundansi/minimalisasi: `record()` menulis `log_type` sedangkan `latest()` mencari `event_type`; catatan model `OrderLog` sebelumnya menunjukkan ada alias `log_type` ke `event_type`, jadi ini bukan bug langsung, tetapi hidden coupling yang membingungkan. Saat refactor, samakan penamaan ke `event_type` atau bungkus akses lewat method model agar tidak rawan salah baca.
- Service fee notice: service ini generic; dipakai `DeliveryFeeNegotiationService` untuk ongkir manual yang dipertahankan. Tidak ada service fee lines/careful-carry langsung, tetapi metadata yang disimpan caller bisa membawa `careful_carry_*` dan perlu dibersihkan di caller.
- Risiko: tinggi untuk negosiasi ongkir/shopping price log karena hidden coupling alias field log bisa membingungkan refactor dan memengaruhi snapshot, pending approval, serta action gating driver/customer.

## Backend_Bangdeliv/app/Services/Order/OrderPaymentService.php

- Fungsi: service sinkronisasi dan pencatatan payment order COD/TRANSFER.
- Logic penting: ensure pending payment saat order dibuat, update pending method/amount jika belum paid, set pending transfer, mark paid dengan recorder/driver/timestamp/metadata, sync amount pending dengan `order.total_price`, dan normalisasi metode payment ke COD/TRANSFER.
- Redundansi/minimalisasi: sudah ringkas. `ensurePendingPayment()` hanya memakai payment pertama untuk order; jika secara data ada banyak payment rows, behavior tetap single-active payment. `syncPendingCodAmount()` hanya alias `syncPendingAmount()` dan bisa dihapus bila tidak dipakai untuk readability.
- Service fee notice: tidak ada service fee lines langsung. Amount mengikuti `order.total_price`, jadi setelah service fee cleanup pastikan total sudah hanya berisi subtotal + delivery fee + penalti gagal pickup yang dipertahankan.
- Risiko: perubahan berdampak ke payment pending COD/TRANSFER, upload bukti transfer, record COD driver, dan status paid/complete order.

## Backend_Bangdeliv/app/Services/Order/OrderProofPolicyService.php

- Fungsi: policy tipe bukti foto order dan mapping ke `OrderEvidence.evidence_type`.
- Logic penting: Courier mendukung proof pickup/delivery; Shopping mendukung receipt/store_closed; validasi tipe proof dari enum; mapping proof ke evidence type termasuk payment transfer; lifecycle proof mencakup pickup/delivery/receipt/store_closed.
- Redundansi/minimalisasi: cukup kecil dan layak dipertahankan. Pesan unsupported masih menyebut bukti pengambilan/diterima hanya untuk order kurir; jika Ride nanti butuh proof, policy harus diperluas.
- Service fee notice: tidak ada service fee/careful-carry. `store_closed`/`STORE_CLOSED_PHOTO` tetap dipertahankan karena terkait bukti merchant tutup/gagal pickup, bukan service fee lines; ini mendukung pengecualian penalti gagal pickup 3 kali.
- Risiko: perubahan berdampak ke upload proof driver, gating action driver, receipt Nitip, store closed flow, payment transfer proof, dan frontend proof UI.

## Backend_Bangdeliv/app/Services/Order/OrderService.php

- Fungsi: service orkestrator utama order untuk customer dan driver, mencakup list/detail/cancel, pembayaran, failed attempt, availability/lokasi driver, incoming/running/history driver, accept/reject/status transition, proof upload, checkout Nitip, negosiasi harga Nitip, perubahan item, negosiasi ongkir, COD/QRIS, dan broadcast/notifikasi.
- Logic penting: customer order detail menambahkan ETA; driver order memakai `DriverOrderPayloadFactory`; failed attempt Shopping menandai pickup gagal, item unavailable, recalculate pricing, dan bisa cancel dengan fee; transition driver memvalidasi payment/proof/shopping quote/ongkir pending; flow Nitip multi-merchant mengatur merchant open, item availability, quote harga, edit unavailable item, checkout receipt; delivery fee override memakai `DeliveryFeeNegotiationService`; status/content update dikirim lewat realtime/push.
- Redundansi/minimalisasi: file sangat gemuk (4545 line) dan mencampur authorization, state machine order, driver lifecycle, shopping merchant workflow, item mutation, pricing recalculation, payment, proof, notification, route/pickup helper, dan serializer glue. Kandidat pecah minimal: driver lifecycle service, shopping merchant workflow service, shopping item mutation service, payment/proof orchestration, delivery fee negotiation controller service, dan shared order query/loader helper.
- Redundansi/minimalisasi detail: eager load `feeLines`/`shoppingReceipt` dan `refresh()->load(...)` berulang puluhan kali; status/action validation tersebar antara `OrderService`, `DriverOrderPayloadFactory`, dan capability services; pickup resolution, merchant candidate, rough haversine, coordinate/status helpers duplikatif dengan service lain; `syncPrimaryRestaurantFromFirstPickup()` hanya `unsetRelation`, namanya lebih besar dari efeknya.
- Redundansi/minimalisasi lanjutan: re-scan menunjukkan 37 public method, 87 private method, total 124 method. Ini bukan sekadar service panjang, tapi beberapa modul aplikasi menumpuk dalam satu class. Refactor minimal sebaiknya memindahkan workflow yang sudah punya batas jelas, bukan rewrite seluruh order flow sekaligus.
- Redundansi/minimalisasi lanjutan: `feeLines` muncul 49 kali, `shoppingReceipt` 47 kali, `refresh()->load(...)` 29 kali, `fresh(...)` 69 kali, dan `shoppingPricingService->recalculate(...)` 15 kali. Kandidat helper paling aman: central order relation loader untuk customer/driver/shopping, wrapper `recalculateAndReloadShoppingOrder()`, dan satu post-action result builder.
- Redundansi/minimalisasi lanjutan: pola side effect setelah action berulang: serialize driver payload, broadcast content update, push price/delivery fee negotiation, status history, dan order log. Kandidat ekstraksi: `OrderPostActionNotifier`/`OrderMutationResultFactory` yang menerima order, trigger type, actor, dan snapshot pricing.
- Redundansi/minimalisasi lanjutan: cluster Shopping/Nitip terlalu besar di sini: quote/bypass/respond harga, cancel merchant, unavailable item decision, mark merchant open, update checkout, add/update/remove item, resolve pickup merchant, dan failed pickup. Kandidat pecah minimal ke `ShoppingOrderWorkflowService` dan `ShoppingOrderItemMutationService`, tetap dipanggil dari controller/service lama agar endpoint tidak berubah dulu.
- Redundansi/minimalisasi lanjutan: helper pickup/merchant seperti `resolveShoppingNegotiationPickup()`, `shoppingPickupById()`, `resolvePickupLocationForCandidate()`, `resolvePickupLocationForMerchant()`, `resolvePickupLocationForExternalPlace()`, `createPickupLocationFor...()`, `merchantPayloadFromPickup()`, `pickupMerchantName()`, `activeShoppingPickupCount()`, dan `hasActiveShoppingPickupWithAvailableItems()` layak digabung ke service kecil `ShoppingPickupLocationService`.
- Redundansi/minimalisasi lanjutan: `roughDistanceMeters()` dan normalisasi lokasi di file ini beririsan dengan service Maps/driver dispatch. Jika tidak dipakai untuk presisi tinggi, jadikan helper bersama atau delegasikan ke existing distance calculator agar tidak ada dua sumber rumus jarak.
- Redundansi/minimalisasi lanjutan: proof/payment masih bercampur dengan state machine. Method `uploadProof()`, `recordCodPayment...()`, `confirmTransferPaymentByDriver()`, `uploadTransferEvidenceByCustomer()`, dan helper proof bisa dipindah bertahap ke payment/proof orchestration karena policy bukti sudah ada di `OrderProofPolicyService`.
- Service fee notice: target cleanup besar. Kandidat hapus/refactor: semua eager load/reload `feeLines`, payload `fee_lines`, `service_fee` di driver history dan total calculation, `SERVICE_FEE` di price change record, `ShoppingPricingService->syncFeeLines()` untuk generic fee lines, serta ketergantungan recalculate ke `OrderFeeLine`/`feeBreakdownForOrder()`.
- Service fee notice: pengecualian yang tetap dipertahankan adalah penalti Shopping gagal pickup/merchant tutup 3 kali: `CANCELLED_WITH_FEE`, `CANCEL_WITH_FEE`, `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS`, upload bukti QRIS setelah cancel with fee, dan `store_closed`/failed pickup flow. Namun implementasinya nanti jangan lagi lewat generic `feeLines`; simpan sebagai field/status/payment penalty yang eksplisit.
- Service fee notice: hapus/refactor jalur `is_heavy`, `submittedHasHeavyItem`, `has_heavy_item`, overweight surcharge dependency, serta seluruh careful-carry Courier: input `careful_carry_required`, teks "Perlu 2 orang", `careful_carry_surcharge`, `supportsCarefulCarry()`, `carefulCarryRequired()`, `setCourierCarefulCarryRequired()`, dan metadata careful-carry pada negosiasi ongkir. `delivery_fee`, `delivery_fee_source`, dan negosiasi ongkir manual driver tetap dipertahankan.
- Risiko: sangat tinggi karena file ini menyentuh hampir semua workflow order backend dan frontend: customer activity/tracking, driver active order, realtime broadcast, payment proof, COD/QRIS, shopping checkout, merchant failed attempts, delivery fee negotiation, order status history, driver availability, dan banyak test feature order/chatbot/shopping/driver.

## Backend_Bangdeliv/app/Services/Order/OrderTransferEvidenceService.php

- Fungsi: service kecil untuk menyimpan/mencatat bukti transfer QRIS ke `OrderEvidence`.
- Logic penting: upload file ke `orders/{orderId}/payments` disk public; jika gagal throw `ApiException`; `recordFromUrl()` membuat evidence dengan `evidence_type = PAYMENT_TRANSFER_PHOTO`, optional `driver_id`, dan default note "Bukti QRIS menunggu verifikasi.".
- Redundansi/minimalisasi: sudah ringkas dan cukup layak dipertahankan. Potensi duplikasi kecil dengan helper `storeOrderPhoto()` di `OrderService` dan mapping proof di `OrderProofPolicyService`; saat refactor proof/payment, arahkan evidence type lewat policy/enum agar string tidak tersebar.
- Service fee notice: tidak ada service fee lines/careful-carry. Tetap relevan untuk QRIS termasuk skenario penalti gagal pickup 3 kali bayar 50%.
- Risiko: rendah-menengah; perubahan berdampak ke upload bukti transfer customer/driver, konfirmasi QRIS, dan histori evidence payment.

## Backend_Bangdeliv/app/Services/Order/RideOrderService.php

- Fungsi: service pembuatan order Antar Jemput dan validasi alamat tujuan.
- Logic penting: ambil alamat pickup tersimpan atau koordinat payload; validasi koordinat pickup/tujuan; geocode tujuan jika koordinat tidak dikirim; cek pickup dan destination tidak terlalu dekat; resolve route lewat Distance Matrix; validasi max distance; hitung ongkir lewat `DeliveryPricingService`; normalisasi payment; buat `Order`, pending payment, status history, `RideOrder`, dua `orderLocations`, lalu broadcast order available ke driver.
- Redundansi/minimalisasi: validasi koordinat/required pair/range ditulis manual dan berpotensi sama dengan request validation, chatbot ride, route picker, dan service lain. Kandidat helper kecil: `CoordinatePairValidator` atau method shared untuk normalize lat/lng + range check.
- Redundansi/minimalisasi: `generateOrderNumber()` lokal berpotensi duplikat dengan pembuatan order Courier/Shopping; sebaiknya ada generator order number bersama agar format dan collision handling konsisten.
- Redundansi/minimalisasi: `roughDistanceMeters()` menduplikasi rumus jarak kasar yang juga muncul di `OrderService`; gabungkan ke helper distance bersama atau delegasikan ke existing calculator agar tidak ada dua sumber rumus.
- Redundansi/minimalisasi: lookup `ServiceType` dan `OrderStatus` memakai string code langsung (`RIDE`, `PENDING`); setelah refactor minimal, pertimbangkan enum/central resolver agar tidak tersebar.
- Service fee notice: tidak ada `feeLines`; `service_fee` selalu `0.0` dan ikut `total_price`. Saat cleanup service fee, bagian ini kandidat disederhanakan menjadi total `subtotal + delivery_fee` tanpa scalar service fee jika kolomnya ikut dihapus; `delivery_fee` dan pricing rute tetap dipertahankan.
- Risiko: tinggi karena file ini adalah jalur utama pembuatan order Antar Jemput dari API dan chatbot, memengaruhi payment pending, route snapshot, driver broadcast, tracking, dan driver incoming order.

## Backend_Bangdeliv/app/Services/Pricing/DeliveryPricingService.php

- Fungsi: calculator ongkir berdasarkan jarak.
- Logic penting: sanitasi distance meters; tarif dasar dari config; 1.5 km pertama flat tanpa billed km; pembulatan km naik jika fraksi >= 0.7; rate per km bertingkat 0-10, 10-25, 25-50 km; expose max distance dan `fee_breakdown` ongkir (`base_fee`, `distance_fee`).
- Redundansi/minimalisasi: sudah kecil dan pure, layak dipertahankan. Potensi kecil: `fee_breakdown` di sini adalah breakdown ongkir, bukan service fee lines; jika nanti nama `fee_breakdown` membingungkan frontend/backend setelah cleanup, bisa rename ke `delivery_fee_breakdown` di snapshot/API.
- Service fee notice: tidak ada `ServiceFeeRule`, `OrderFeeLine`, `service_fee`, `careful_carry`, atau surcharge item. Ini bukan target hapus; `delivery_fee` dan rumus ongkir tetap dipertahankan karena ongkir bisa diedit manual driver di flow lain.
- Risiko: perubahan berdampak ke Ride/Courier/Shopping route pricing, chatbot draft, max distance validation, dan snapshot ongkir.

## Backend_Bangdeliv/app/Services/Pricing/ShoppingPricingService.php

- Fungsi: pricing service Nitip/Shopping untuk menghitung subtotal, ongkir, service fee/surcharge, penalti gagal pickup, sinkron pending payment, log perubahan harga, status history, realtime broadcast, dan push notification.
- Logic penting: `calculateForItems()` menghitung subtotal item available, total quantity, surcharge banyak item dari `ServiceFeeRule`, overweight surcharge dari `is_heavy`, cancellation penalty, `service_fee`, `total_price`, dan `fee_breakdown`; `recalculate()` reload order, resolve locked delivery fee, baca penalty dari `OrderFeeLine`, sync fee lines, update order totals, sync pending COD, buat `OrderLog`/`OrderStatusHistory`, broadcast content update, dan push total changed.
- Redundansi/minimalisasi: terlalu banyak tanggung jawab dalam satu class: pure calculator, rule lookup DB, persistence `OrderFeeLine`, payment sync, order log/status history, realtime, dan notification. Refactor minimal: pisahkan pure calculator dari `ShoppingPricingRecalculator`/notifier, atau setidaknya buat method kecil khusus `applyPricingToOrder()` dan `broadcastPricingChanged()`.
- Redundansi/minimalisasi: `approvedShoppingSubtotalAmount()` memakai `app(ShoppingPriceNegotiationService::class)` service locator, bukan dependency injection; lebih sulit dites dan menyembunyikan dependency. Kandidat pindah ke constructor jika service ini tetap dipakai.
- Redundansi/minimalisasi: `latestRecalculationVersion()` query `event_type`, sedangkan create memakai field `log_type`; jika ini bergantung pada alias model `OrderLog`, catat sebagai naming membingungkan. Saat refactor, samakan istilah agar log pricing tidak mudah salah query.
- Service fee notice: target cleanup utama. Kandidat hapus/refactor: dependency `ServiceFeeRule`, model `OrderFeeLine`, constant `ITEM_BLOCK_SURCHARGE`, `OVERWEIGHT_FLAT_SURCHARGE`, `$shoppingFeeCodes`, `syncFeeLines()`, `feeBreakdownForOrder()`, `feeLineAmount()` untuk generic fee, `feeLineLabel()/feeLineDescription()` untuk surcharge item/berat, `item_surcharge`, `overweight_surcharge`, `has_overweight_item`, `is_heavy`, `SERVICE_FEE` di `recordPriceChange()`/`pricingAmounts()`, dan `service_fee` sebagai hasil akumulasi generic surcharge.
- Service fee notice: pengecualian yang tetap dipertahankan adalah `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS` untuk gagal pickup/merchant tutup 3 kali bayar 50% ongkir. Namun jangan lagi disimpan lewat generic `OrderFeeLine`/`ServiceFeeRule`; jadikan rule eksplisit/config sederhana atau field penalty khusus yang mudah dilacak.
- Service fee notice: field broadcast `careful_carry_required => false` tidak relevan di pricing Shopping dan ikut kandidat hapus saat cleanup careful-carry/service fee.
- Risiko: sangat tinggi karena dipakai oleh `OrderService`, `ChatbotShoppingOrderService`, driver/customer shopping flow, pending COD/QRIS amount, status history, realtime content update, push perubahan total, dan test pricing/service fee lama.

## Backend_Bangdeliv/app/Services/Shopping/ShoppingDeliveryFeeLockResolver.php

- Fungsi: resolver untuk mengunci ongkir Shopping setelah negosiasi ongkir disetujui.
- Logic penting: cari `OrderLog` terbaru dengan event `DeliveryFeeNegotiationService::EVENT_TYPE`; lock hanya untuk trigger `CUSTOMER_FEE_APPROVED` atau `DRIVER_COUNTER_APPROVED`; amount diambil dari metadata `approved_amount/final_amount/counter_amount/quoted_amount/amount`, fallback ke `order.delivery_fee`.
- Redundansi/minimalisasi: kecil dan cukup layak dipertahankan, tapi secara domain bisa dipindah/delegasikan ke `DeliveryFeeNegotiationService` agar log metadata amount tidak perlu dipahami oleh service Shopping.
- Service fee notice: tidak ada service fee lines. Ini harus dipertahankan karena `delivery_fee`/ongkir manual driver tetap menjadi fitur inti.
- Risiko: perubahan berdampak ke recalculate pricing Shopping, route recalculation, dan agar ongkir yang sudah disepakati tidak tertimpa hasil Distance Matrix.

## Backend_Bangdeliv/app/Services/Shopping/ShoppingItemChangeRequestService.php

- Fungsi: pencatat dan snapshot request perubahan item Nitip dari customer ke driver.
- Logic penting: record request/approve/reject/applied ke `OrderLog`; latest/pending lookup; snapshot mengembalikan status, action, item, target pickup, requested stops, merchant metadata, dan apakah driver bisa merespons.
- Redundansi/minimalisasi: cukup fokus, tapi shaping `requestedStops`, merchant metadata, dan item summary beririsan dengan payload driver/order detail di service lain. Bisa dipertahankan dulu, lalu ekstrak formatter jika payload Shopping mulai dirapikan.
- Service fee notice: tidak ada service fee lines. Perubahan item akan memicu repricing; setelah cleanup pastikan repricing tidak lagi menambahkan item surcharge/overweight surcharge.
- Risiko: sedang-tinggi karena status pending item change menahan quote, checkout, dan capability driver/customer.

## Backend_Bangdeliv/app/Services/Shopping/ShoppingMerchantCandidate.php

- Fungsi: value object kandidat merchant Shopping dari restaurant DB atau Google Place.
- Logic penting: factory `fromRestaurant()`/`fromGooglePlace()`; deteksi database merchant; klasifikasi `merchantType()` dari tipe Google/name heuristics; key unik per restaurant/place/external; metadata sumber merchant.
- Redundansi/minimalisasi: heuristic `merchantType()` dan normalisasi nama berpotensi duplikat dengan `OrderService::merchantTypeFromPlaceTypes()` dan parser/chatbot merchant. Kandidat jadikan satu helper merchant classifier.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: perubahan berdampak ke merchant external Google, grouping pickup, metadata order location, dan tampilan jenis merchant.

## Backend_Bangdeliv/app/Services/Shopping/ShoppingMerchantCandidateResolver.php

- Fungsi: resolver merchant Shopping dari payload order atau payload standalone chatbot.
- Logic penting: menerima `merchant_place` Google atau `merchant_id`; validasi merchant aktif; fallback ke restaurant order/pickup; Google place bisa dicocokkan ke restaurant DB jika nama normalized sama dan jarak <= 150 meter.
- Redundansi/minimalisasi: validasi text/coordinate, normalisasi nama, dan `distanceMeters()` haversine menduplikasi pola di route/order service. Resolver pickup/merchant juga overlap dengan helper besar di `OrderService`; ini kandidat masuk ke `ShoppingPickupLocationService`/shared geo helper.
- Service fee notice: tidak ada service fee lines.
- Risiko: perubahan berdampak ke add item/manual item, add merchant dari chatbot/map, dedupe Google Place ke merchant DB, dan multi-merchant Shopping.

## Backend_Bangdeliv/app/Services/Shopping/ShoppingOrderCapabilityService.php

- Fungsi: policy capability aksi Shopping untuk customer/driver.
- Logic penting: capability berdasarkan service type SHOPPING, status order, pending item change, status merchant pickup, item unavailable, dan approval price negotiation; expose boolean direct edit, request item change, mark merchant open/closed, update availability, submit quote, upload receipt.
- Redundansi/minimalisasi: wrapper `canX()` memanggil `capabilities()` ulang sehingga bisa mengulang load/query jika beberapa capability dibaca berurutan. Dependency `ShoppingPriceNegotiationService` dan `ShoppingItemChangeRequestService` memakai `app(...)`, sebaiknya constructor injection agar jelas dan lebih mudah dites.
- Redundansi/minimalisasi: beberapa flag masih hardcoded false (`can_customer_request_add_stop`, `can_customer_resolve_failed_merchant`); jika fitur tidak jadi dipakai, kandidat hapus dari payload setelah frontend dicek.
- Service fee notice: tidak ada service fee/careful-carry. Capability merchant failed tetap relevan dengan flow gagal pickup, tapi saat ini resolve failed merchant masih false.
- Risiko: tinggi karena flag ini mengatur tombol/aksi frontend customer dan driver pada order Shopping.

## Backend_Bangdeliv/app/Services/Shopping/ShoppingPriceNegotiationService.php

- Fungsi: service snapshot dan log negosiasi harga belanja/merchant Shopping.
- Logic penting: trigger driver quote, customer approve/counter, driver approve counter, bypass, cancel merchant/order, dan re-quote setelah item berubah; snapshot multi-merchant menghitung approved subtotal, all required quotes approved, checkout allowed, dan capability submit quote per pickup.
- Redundansi/minimalisasi: memakai `app(ShoppingItemChangeRequestService::class)` beberapa kali; pindahkan ke constructor injection. Helper active pickup, pickup by id, pickup has available items, dan status terminal duplikat dengan `ShoppingRouteService`, `ShoppingUnavailableItemDecisionService`, `ShoppingOrderCapabilityService`, dan `OrderService`.
- Redundansi/minimalisasi: `latestForPickup()` mengambil semua log lalu filter metadata di PHP; masih bisa diterima untuk TA, tapi jika log banyak bisa dipertimbangkan query JSON/index atau cache snapshot per pickup.
- Service fee notice: tidak ada service fee lines langsung. `approvedSubtotal()` dipakai `ShoppingPricingService` sebagai subtotal final belanja dan tetap dipertahankan; jangan dicampur dengan ongkir/service fee cleanup.
- Risiko: tinggi karena menentukan checkout allowed, receipt upload, repricing subtotal, status quote per merchant, dan payload frontend.

## Backend_Bangdeliv/app/Services/Shopping/ShoppingRouteService.php

- Fungsi: service kalkulasi rute dan ongkir Shopping multi-merchant.
- Logic penting: hitung rute merchant ke dropoff sequential atau optimized; validasi jarak minimal/maksimal; hitung ongkir via `DeliveryPricingService`; apply route ke order; menjaga locked delivery fee dari negosiasi; resequence pickup/dropoff; simpan route snapshot.
- Redundansi/minimalisasi: `roughDistanceMeters()`, active pickup filtering, dan available item filtering menduplikasi logic di beberapa service order/shopping. Kandidat shared geo helper + shared `ShoppingPickupLocationService`.
- Redundansi/minimalisasi: `calculateForPoints()`/optimized route menghasilkan `delivery_pricing`, tetapi `storeRouteSnapshot()` tidak menyimpan `delivery_pricing`; snapshot Shopping jadi tidak sekonsisten Ride route snapshot. Perlu dipastikan apakah sengaja disembunyikan atau bug data snapshot.
- Service fee notice: tidak ada service fee lines. `delivery_fee`, `delivery_fee_locked`, dan `delivery_fee_lock_source` harus dipertahankan karena ongkir manual driver/negosiasi tetap fitur inti.
- Risiko: tinggi karena memengaruhi ongkir Shopping, route snapshot, urutan merchant, ETA/track map, dan repricing setelah merchant/item berubah.

## Backend_Bangdeliv/app/Services/Shopping/ShoppingUnavailableItemDecisionService.php

- Fungsi: policy keputusan saat item Shopping tidak tersedia di merchant.
- Logic penting: aksi per pickup `can_edit`, `can_continue_without_item`, `can_cancel_merchant`; validasi lanjut tanpa item hanya boleh untuk item unavailable milik pickup tersebut dan masih ada item available tersisa.
- Redundansi/minimalisasi: kecil dan fokus, tapi helper `hasUnavailableItems`, `hasAvailableItemsAfterRemovingUnavailable`, dan `pickupById` beririsan dengan capability/route/negotiation service. Bisa tetap dulu, atau disatukan ke helper pickup/item availability saat refactor Shopping.
- Service fee notice: tidak ada service fee lines. Flow cancel merchant tetap relevan karena berkaitan dengan failed pickup/merchant tutup, tetapi tidak boleh otomatis menghidupkan surcharge selain penalti gagal pickup 3 kali yang dikecualikan.
- Risiko: sedang karena memengaruhi pilihan customer ketika item unavailable dan bisa memicu repricing/cancel merchant.

## Backend_Bangdeliv/bootstrap/app.php

- Fungsi: bootstrap konfigurasi Laravel application.
- Logic penting: register route `web`, `api`, `console`, health `/up`; aktifkan broadcasting channel dari `routes/channels.php` dengan middleware `api` dan `auth:sanctum`; alias middleware `role` dan `driver.active`; exceptions closure masih kosong.
- Redundansi/minimalisasi: sudah minimal. Tidak ada logic bisnis; tetap biarkan sebagai entrypoint framework. Jika middleware bertambah, cukup pastikan alias tetap konsisten dengan route.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah, tapi salah ubah di sini bisa memutus routing API/web, broadcasting auth, role guard admin/customer/driver, dan driver active guard.

## Backend_Bangdeliv/bootstrap/providers.php

- Fungsi: daftar service provider Laravel yang diload aplikasi.
- Logic penting: hanya memuat `App\Providers\AppServiceProvider::class`.
- Redundansi/minimalisasi: sudah sangat minimal. Tidak ada kandidat refactor.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah; perubahan berdampak ke booting aplikasi dan provider binding global.

## Backend_Bangdeliv/config/app.php

- Fungsi: konfigurasi dasar Laravel app.
- Logic penting: app name/env/debug/url, timezone `Asia/Jakarta`, locale/fallback/faker, encryption key, previous keys, dan maintenance mode.
- Redundansi/minimalisasi: mostly default Laravel dan sudah minimal; tidak perlu refactor kecuali ingin ubah locale default ke `id` jika seluruh app berbahasa Indonesia.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah-menengah; salah ubah berdampak ke timezone, URL generator, encryption, dan mode maintenance.

## Backend_Bangdeliv/config/auth.php

- Fungsi: konfigurasi auth Laravel.
- Logic penting: default guard `web`, provider `users` memakai `App\Models\User`, password reset token table, expiry 60 menit, throttle 60 detik, password timeout.
- Redundansi/minimalisasi: default config dan sudah cukup minimal. Password reset config ada, tetapi frontend forgot password sebelumnya perlu dicocokkan dengan route/controller backend.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang; perubahan bisa memutus Sanctum/web auth, provider user, dan reset password.

## Backend_Bangdeliv/config/bangdeliv.php

- Fungsi: konfigurasi domain BangDeliv untuk Maps, route, dispatch, ongkir, anti-fraud, dan chatbot.
- Logic penting: Google Maps geocoding/distance matrix/routes, service area bounds, optimized Shopping waypoints, driver dispatch thresholds, delivery fee base/rate/max distance, new account order limit, chatbot rate limit, dan Gemini models/API key.
- Redundansi/minimalisasi: file ini tepat sebagai domain config. Comment rumus ongkir punya karakter encoding rusak pada simbol perkalian dan rumus comment tidak sepenuhnya sama dengan `DeliveryPricingService` karena service punya flat 1.5 km dan pembulatan fraksi 0.7; komentar sebaiknya disesuaikan agar tidak menyesatkan.
- Service fee notice: tidak ada `ServiceFeeRule`/`OrderFeeLine`. Config ongkir (`base_delivery_fee`, rate per km, max distance) harus dipertahankan karena `delivery_fee` tetap fitur inti dan bisa dinegosiasi/manual driver.
- Risiko: tinggi karena memengaruhi Maps, ongkir Ride/Courier/Shopping, dispatch driver, chatbot Gemini, dan rate limit.

## Backend_Bangdeliv/config/broadcasting.php

- Fungsi: konfigurasi broadcaster Laravel.
- Logic penting: default `BROADCAST_CONNECTION`; koneksi Reverb, Pusher, Ably, log, null; timeout HTTP broadcast dibuat pendek.
- Redundansi/minimalisasi: default Laravel plus timeout custom, sudah cukup minimal. Pertahankan Reverb/Pusher karena realtime order/chat bergantung ke sini.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: tinggi untuk realtime; salah config bisa memutus order updates, driver incoming, chat, dan notification heads-up.

## Backend_Bangdeliv/config/cache.php

- Fungsi: konfigurasi cache store Laravel.
- Logic penting: default `database`; store array, database, file, memcached, redis, dynamodb, octane, failover; cache prefix dari app name.
- Redundansi/minimalisasi: mostly default Laravel. Tidak ada kandidat refactor domain.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah-menengah; perubahan bisa memengaruhi cache, locks, rate limit, dan performance.

## Backend_Bangdeliv/config/database.php

- Fungsi: konfigurasi koneksi database dan Redis.
- Logic penting: default `sqlite`; koneksi sqlite/mysql/mariadb/pgsql/sqlsrv; migration table; redis default/cache dengan retry/backoff.
- Redundansi/minimalisasi: default Laravel dan lengkap. Tidak ada logic domain; biarkan kecuali env produksi ingin default eksplisit ke MySQL/MariaDB.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: tinggi; salah ubah bisa memutus database, migration, Redis/cache/session/queue.

## Backend_Bangdeliv/config/filesystems.php

- Fungsi: konfigurasi disk filesystem.
- Logic penting: default disk dari env; local private, public disk `storage/app/public` dengan URL `/storage`, S3, dan symbolic link public storage.
- Redundansi/minimalisasi: sudah minimal. Public disk dipakai upload evidence, driver docs, menu/resto image, dan QRIS proof.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang; salah ubah berdampak ke upload/view foto bukti, dokumen driver, receipt, menu, dan storage URL.

## Backend_Bangdeliv/config/logging.php

- Fungsi: konfigurasi channel logging Laravel.
- Logic penting: stack default, single/daily/slack/papertrail/stderr/syslog/errorlog/null/emergency.
- Redundansi/minimalisasi: mostly default Laravel; tidak ada kandidat refactor domain.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah-menengah; salah ubah mengurangi observability error, Maps failure, realtime failure, dan job failure.

## Backend_Bangdeliv/config/mail.php

- Fungsi: konfigurasi mailer Laravel.
- Logic penting: default `log`; mailer smtp/ses/postmark/resend/sendmail/log/array/failover/roundrobin; from address/name dari env.
- Redundansi/minimalisasi: default Laravel dan cukup minimal. Jika reset password email ingin aktif, perlu pastikan mailer bukan hanya `log` di environment target.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk fitur email/reset password/notifikasi email jika dipakai.

## Backend_Bangdeliv/config/queue.php

- Fungsi: konfigurasi queue Laravel.
- Logic penting: default `database`; koneksi sync/database/beanstalkd/sqs/redis/deferred/background/failover; batching dan failed jobs table.
- Redundansi/minimalisasi: mostly default Laravel. Jobs reminder/payment proof dan push background bergantung ke pilihan queue ini.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang-tinggi; salah ubah bisa membuat job reminder/payment notification/push tidak jalan.

## Backend_Bangdeliv/config/reverb.php

- Fungsi: konfigurasi Laravel Reverb server dan app.
- Logic penting: server host/port/path, scaling Redis optional, app key/secret/id, allowed origins `*`, ping/activity timeout, max message/request size, client event policy, dan rate limiting.
- Redundansi/minimalisasi: mostly default Reverb. Untuk produksi, `allowed_origins => ['*']` terlalu longgar; untuk TA/local masih praktis.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: tinggi untuk realtime order/chat; salah config bisa memutus WebSocket auth/broadcast atau membuka origin terlalu luas.

## Backend_Bangdeliv/config/sanctum.php

- Fungsi: konfigurasi Laravel Sanctum.
- Logic penting: stateful domains localhost dan current app URL; guard `web`; token expiration null; token prefix; middleware authenticate session, encrypt cookies, CSRF.
- Redundansi/minimalisasi: default Sanctum dan cukup minimal. Pastikan domain frontend mobile/web development sesuai env jika auth stateful dipakai.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: tinggi untuk auth API, mobile token, broadcasting channel auth, dan route protected.

## Backend_Bangdeliv/config/services.php

- Fungsi: konfigurasi kredensial third-party services Laravel.
- Logic penting: Postmark, Resend, SES, dan Slack notification credentials dari env.
- Redundansi/minimalisasi: default Laravel dan minimal. Tidak ada service domain BangDeliv di sini karena Maps/Gemini ditempatkan di `config/bangdeliv.php`.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah-menengah; berdampak ke email provider/slack jika dipakai.

## Backend_Bangdeliv/config/session.php

- Fungsi: konfigurasi session Laravel.
- Logic penting: default driver `database`, lifetime 120 menit, encrypt off, session table, cookie name/path/domain/secure/http_only/same_site/partitioned.
- Redundansi/minimalisasi: default Laravel dan cukup minimal. Jika API mobile murni token, session tetap penting untuk admin web dan Sanctum stateful/broadcast auth.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang; salah ubah bisa memutus admin session, CSRF/session auth, dan cookie behavior.

## Backend_Bangdeliv/database/factories/UserFactory.php

- Fungsi: factory test/seeding untuk model `User`.
- Logic penting: generate name/email unik, email verified, password default `password` dengan hash cached statis, remember token, dan state `unverified()`.
- Redundansi/minimalisasi: default Laravel dan sudah minimal. Belum mengisi field domain tambahan user seperti `phone`/`role`; jika test butuh user customer/driver/admin, lebih baik tambah state eksplisit daripada ubah default global.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah; perubahan berdampak ke test/seeder yang memakai `User::factory()`.

## Backend_Bangdeliv/database/migrations/0001_01_01_000000_create_users_table.php

- Fungsi: membuat tabel `users`, `password_reset_tokens`, dan `sessions`.
- Logic penting: user punya phone unique nullable, role enum customer/driver/admin, avatar, active/blacklisted, soft delete; password reset dan session database default Laravel.
- Redundansi/minimalisasi: cukup wajar. Role enum sederhana cocok untuk TA; jika role bertambah banyak baru perlu tabel role/permission.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: tinggi karena menyentuh auth, admin, customer, driver, session, dan reset password.

## Backend_Bangdeliv/database/migrations/0001_01_01_000001_create_cache_table.php

- Fungsi: membuat tabel cache dan cache locks.
- Logic penting: `cache.key` primary, value medium text, expiration index; locks punya owner dan expiration.
- Redundansi/minimalisasi: default Laravel, tidak perlu refactor.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah-menengah; berdampak ke cache, locks, rate limit, dan session/cache driver database.

## Backend_Bangdeliv/database/migrations/0001_01_01_000002_create_jobs_table.php

- Fungsi: membuat tabel queue jobs, job batches, dan failed jobs.
- Logic penting: database queue payload, attempts/reserved/available timestamps; batch tracking; failed job UUID unique.
- Redundansi/minimalisasi: default Laravel, tidak perlu refactor.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang karena job reminder/push/payment proof bisa bergantung ke queue database.

## Backend_Bangdeliv/database/migrations/2026_03_07_174820_create_personal_access_tokens_table.php

- Fungsi: membuat tabel Sanctum personal access tokens.
- Logic penting: morph tokenable, token unique, abilities, last used, expires index, timestamps.
- Redundansi/minimalisasi: default Sanctum, tidak perlu refactor.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: tinggi untuk auth API mobile/backend.

## Backend_Bangdeliv/database/migrations/2026_03_30_115801_create_device_tokens_table.php

- Fungsi: menyimpan token device user untuk push notification.
- Logic penting: user FK cascade, token unique, device type android/ios/web, active flag, index user+active.
- Redundansi/minimalisasi: sudah ringkas. Jika token bisa berpindah user, unique global sudah menjaga satu token satu record.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk push notification order/chat.

## Backend_Bangdeliv/database/migrations/2026_03_30_115802_create_drivers_table.php

- Fungsi: schema profil driver dan posisi terakhir.
- Logic penting: user unique, data kendaraan/SIM, registration status, availability status, latitude/longitude, location updated, soft delete, index status.
- Redundansi/minimalisasi: sudah cukup minimal. Koordinat driver ada di driver table, bukan history; cocok untuk kebutuhan current location.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: tinggi untuk onboarding driver, dispatch, availability, realtime location, dan active order.

## Backend_Bangdeliv/database/migrations/2026_03_30_115803_create_driver_documents_table.php

- Fungsi: schema dokumen verifikasi driver.
- Logic penting: driver FK cascade, document type ktp/sim/selfie, file path, verification status, rejection reason, verified by admin, unique driver+type.
- Redundansi/minimalisasi: sudah ringkas. Enum dokumen cukup selama jenis dokumen tetap tiga.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk verifikasi driver dan admin review.

## Backend_Bangdeliv/database/migrations/2026_03_30_115804_create_restaurants_table.php

- Fungsi: schema merchant/restaurant.
- Logic penting: name, slug unique, merchant type, address, lat/lng, phone wajib, banner, active/inactive, soft delete, index status/type.
- Redundansi/minimalisasi: cukup baik. Comment phone punya karakter encoding rusak; rapikan komentar saat cleanup migration/docs.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang-tinggi untuk katalog, merchant map, Shopping/Nitip, dan menu.

## Backend_Bangdeliv/database/migrations/2026_03_30_115806_create_menu_categories_table.php

- Fungsi: schema kategori menu per restaurant.
- Logic penting: restaurant FK cascade, name, sort order, unique restaurant+name.
- Redundansi/minimalisasi: sudah minimal.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah-menengah untuk katalog menu.

## Backend_Bangdeliv/database/migrations/2026_03_30_115807_create_menus_table.php

- Fungsi: schema menu merchant.
- Logic penting: restaurant FK, category nullable nullOnDelete, name/description/price/image, availability, sort order, soft delete, index restaurant+availability.
- Redundansi/minimalisasi: sudah cukup minimal. FK restaurant tidak cascade eksplisit; karena restaurant soft delete, ini masih masuk akal.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk katalog dan Shopping item dari menu DB.

## Backend_Bangdeliv/database/migrations/2026_03_30_115808_create_addresses_table.php

- Fungsi: schema alamat tersimpan customer.
- Logic penting: user FK cascade, label, recipient, phone, full address, lat/lng, default flag, timestamps.
- Redundansi/minimalisasi: sudah minimal. Belum ada unique default per user di DB; aturan default kemungkinan dijaga service.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk address book, chatbot guard, pickup/dropoff default.

## Backend_Bangdeliv/database/migrations/2026_03_30_115810_create_order_statuses_table.php

- Fungsi: membuat master status order dan seed awal.
- Logic penting: status lifecycle PENDING sampai COMPLETED/CANCELLED/COMPLAINT; `CANCELLED_WITH_FEE` terminal disediakan untuk order batal dengan biaya.
- Redundansi/minimalisasi: status master masih wajar. Jika enum `OrderStatusCode` selalu sinkron, pastikan seed dan enum tidak drift.
- Service fee notice: `CANCELLED_WITH_FEE` harus dipertahankan untuk penalti gagal pickup/merchant tutup 3 kali bayar 50%.
- Risiko: tinggi karena status dipakai state machine backend, frontend activity/tracking, driver action, payment, dan tests.

## Backend_Bangdeliv/database/migrations/2026_03_30_115810_create_service_types_table.php

- Fungsi: membuat master service type dan seed RIDE/COURIER/SHOPPING.
- Logic penting: service type code/display/description/sort; seed Antar Jemput, Kurir, Nitip.
- Redundansi/minimalisasi: sudah minimal. Pastikan enum `ServiceTypeCode` sinkron dengan seed.
- Service fee notice: tidak ada service fee lines. Tidak perlu service type khusus untuk fee.
- Risiko: tinggi karena FK utama semua order dan gating fitur per layanan.

## Backend_Bangdeliv/database/migrations/2026_03_30_115811_create_orders_table.php

- Fungsi: membuat tabel `orders` dan `order_fee_lines`.
- Logic penting: order number, customer, service type, driver, assigned time, subtotal, delivery_fee, service_fee, total_price, delivery_fee_source, route snapshot, cancellation, delivered, status, indexes; `order_fee_lines` menyimpan code/label/amount per order.
- Redundansi/minimalisasi: `order_fee_lines` dan scalar `service_fee` adalah target cleanup schema. Jika service fee tinggal penalti gagal pickup 3 kali, jangan gunakan fee lines generic; pertimbangkan field/metadata eksplisit untuk cancellation penalty atau hitung dari status/payment.
- Service fee notice: hapus/refactor `order_fee_lines`; hapus/sederhanakan `orders.service_fee` jika tidak lagi diperlukan. Pertahankan `delivery_fee` dan `delivery_fee_source` karena ongkir bisa manual/negosiasi driver.
- Risiko: sangat tinggi karena tabel pusat seluruh order, payment amount, tracking, driver payload, activity, dan semua relasi.

## Backend_Bangdeliv/database/migrations/2026_03_30_115812_create_order_locations_table.php

- Fungsi: schema titik pickup/dropoff order.
- Logic penting: order FK, restaurant nullable, role PICKUP/DROPOFF, contact/address/coordinate, sequence, fulfillment status, failed attempt count/reason/time/resolved, index role/status.
- Redundansi/minimalisasi: cukup baik untuk multi-stop Shopping dan route. `failed_attempt_count` penting untuk penalti gagal pickup.
- Service fee notice: tidak ada fee lines; `failed_attempt_count` tetap dipertahankan karena mendukung penalti gagal pickup 3 kali.
- Risiko: tinggi untuk route, merchant pickup, failed pickup, tracking, dan driver map.

## Backend_Bangdeliv/database/migrations/2026_03_30_115813_create_order_items_table.php

- Fungsi: schema item order Shopping/Nitip.
- Logic penting: `shopping_order_items` dengan order/menu/pickup location, source menu/manual, snapshot nama/harga/subtotal, availability, metadata, `is_heavy`; trigger MySQL menjaga hanya service SHOPPING yang boleh punya items.
- Redundansi/minimalisasi: trigger service-type ditulis manual seperti detail order lain; SQLite/test tidak enforce trigger. Secara minimal masih bisa dipertahankan, tapi validasi service juga harus tetap di service layer.
- Service fee notice: `is_heavy` dan index `shopping_order_items_order_heavy_idx` kandidat hapus karena overweight surcharge akan dihapus. Jangan hapus availability/pickup item karena dibutuhkan Shopping flow.
- Risiko: tinggi untuk Shopping cart, item availability, unavailable item decision, pricing, dan chatbot Nitip.

## Backend_Bangdeliv/database/migrations/2026_03_30_115815_create_ai_chat_tables.php

- Fungsi: schema sesi, pesan, dan detail respons AI chatbot.
- Logic penting: session per user+session_id, completed order link; messages role user/assistant; details ai_response JSON, model, intent, order link.
- Redundansi/minimalisasi: sudah cukup minimal. Detail response JSON fleksibel tapi bisa menampung field lama saat cleanup; pastikan payload service fee lama tidak dipakai lagi oleh frontend.
- Service fee notice: tidak ada schema service fee langsung.
- Risiko: sedang-tinggi untuk chatbot history, draft order, session restore, dan action hints.

## Backend_Bangdeliv/database/migrations/2026_04_09_000003_create_service_fee_rules_table.php

- Fungsi: membuat master `service_fee_rules` dan seed rule fee Shopping.
- Logic penting: rule per service type dengan code/config/is_active/starts_at; seed `ITEM_BLOCK_SURCHARGE`, `OVERWEIGHT_FLAT_SURCHARGE`, dan `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS`.
- Redundansi/minimalisasi: target hapus penuh jika master service fee dihapus. Dua rule surcharge item/berat sudah tidak dibutuhkan; rule penalti gagal pickup 3 kali sebaiknya dipindah ke config eksplisit/simple constant.
- Service fee notice: hapus table `service_fee_rules` dan dependency `ServiceFeeRule`; pertahankan behavior `CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS` 3 kali/50% ongkir lewat mekanisme non-generic.
- Risiko: tinggi karena sekarang dipakai `ShoppingPricingService`; cleanup harus sinkron dengan seeder, tests, model, dan pricing.

## Backend_Bangdeliv/database/migrations/2026_04_09_000008_create_order_evidence_table.php

- Fungsi: schema bukti foto/evidence order.
- Logic penting: evidence per order/driver, enum proof courier pickup/delivery/receiver, shopping receipt, store closed, payment transfer, URL file, uploaded_at, notes.
- Redundansi/minimalisasi: sudah fokus. Enum proof harus sinkron dengan `ProofType` dan `OrderProofPolicyService`.
- Service fee notice: tidak ada fee lines. `STORE_CLOSED_PHOTO` dan `PAYMENT_TRANSFER_PHOTO` tetap dipertahankan karena mendukung merchant tutup/gagal pickup dan QRIS/payment penalty.
- Risiko: tinggi untuk proof driver, receipt Shopping, store closed flow, QRIS proof, dan tracking/order detail.

## Backend_Bangdeliv/database/migrations/2026_04_09_000009_create_order_events_table.php

- Fungsi: schema event/log/status history order.
- Logic penting: order FK, event type, new status, trigger type, changed by, note, metadata JSON, created_at, indexes order+created/type/status.
- Redundansi/minimalisasi: nama table `order_events` dipakai model `OrderLog`/history aliases; pastikan naming `event_type` vs `log_type` konsisten saat refactor agar tidak membingungkan.
- Service fee notice: tidak ada schema fee langsung, tetapi metadata lama bisa menyimpan price/service fee changes; cleanup harus menghindari menulis `SERVICE_FEE` generic baru.
- Risiko: tinggi untuk audit trail, status history, negotiation logs, pricing logs, dan realtime snapshots.

## Backend_Bangdeliv/database/migrations/2026_04_09_000010_create_order_payments_table.php

- Fungsi: schema pembayaran order.
- Logic penting: satu payment unik per order, method COD/TRANSFER, status PENDING/PAID/VOID, amount, recorded by, driver, paid_at, metadata, indexes status/driver/paid_at.
- Redundansi/minimalisasi: single-active payment model sederhana dan cocok dengan flow sekarang.
- Service fee notice: tidak ada fee lines. Amount mengikuti `order.total_price`; setelah cleanup, pastikan total tetap benar untuk ongkir + item + penalti gagal pickup 50%.
- Risiko: tinggi untuk COD/QRIS, payment proof, settlement driver, dan completion.

## Backend_Bangdeliv/database/migrations/2026_04_09_000011_create_ride_orders_table.php

- Fungsi: schema detail khusus Ride/Antar Jemput.
- Logic penting: one-to-one order detail dengan picked_up_at/arrived_at; trigger MySQL memastikan hanya service RIDE.
- Redundansi/minimalisasi: trigger service-type mirip courier/shopping; bisa diterima, tapi validasi service layer tetap wajib karena SQLite/test tidak enforce trigger.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk lifecycle ride dan timestamp pickup/arrival.

## Backend_Bangdeliv/database/migrations/2026_04_09_000012_create_courier_orders_table.php

- Fungsi: schema detail khusus Courier/Kurir.
- Logic penting: one-to-one order detail, package description, `careful_carry_required`, trigger MySQL memastikan hanya service COURIER.
- Redundansi/minimalisasi: trigger service-type mirip ride/shopping. `careful_carry_required` memperpanjang flow yang ingin dihapus.
- Service fee notice: `careful_carry_required` kandidat hapus/refactor karena service type courier 2 orang/careful-carry tidak dibutuhkan. `package_description` tetap dipertahankan.
- Risiko: tinggi untuk create courier order, driver payload, delivery fee negotiation metadata, dan cleanup careful-carry frontend/backend.

## Backend_Bangdeliv/database/migrations/2026_04_09_000013_create_shopping_receipts_table.php

- Fungsi: schema receipt/total belanja Shopping.
- Logic penting: one-to-one order receipt, total amount, recorded_by user, recorded_at; trigger MySQL memastikan hanya service SHOPPING.
- Redundansi/minimalisasi: sudah fokus. Trigger pattern sama seperti detail order lain.
- Service fee notice: tidak ada fee lines. Receipt total adalah subtotal belanja final, bukan service fee, jadi tetap dipertahankan.
- Risiko: tinggi untuk upload receipt, checkout allowed, payment amount, dan Shopping completion.

## Backend_Bangdeliv/database/migrations/2026_05_02_000001_create_order_chat_messages_table.php

- Fungsi: schema pesan chat per order.
- Logic penting: order FK, sender user/role/name snapshot, body, client message id, optional attachment metadata, index order/sender, unique dedupe order+sender+client_message_id.
- Redundansi/minimalisasi: sudah cukup minimal. Attachment generic bisa dipakai foto/dokumen jika diperlukan.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk chat customer-driver, unread, realtime, dan push chat.

## Backend_Bangdeliv/database/migrations/2026_05_10_000001_create_order_chat_reads_table.php

- Fungsi: schema read receipt chat order.
- Logic penting: unique order+user, last read message nullable, read_at, index order+last_read.
- Redundansi/minimalisasi: sudah minimal.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk unread badge, heads-up notification, dan chat UX.

## Backend_Bangdeliv/database/seeders/AccessAccountSeeder.php

- Fungsi: membuat akun akses demo tetap untuk customer dan driver.
- Logic penting: customer `mhnzayyan@gmail.com`; driver `zaky@gmail.com`; password demo `password`; driver dibuat active/available dengan kendaraan; dokumen ktp/sim/selfie dibuat approved dengan path dummy `driver-docs/{id}/{type}.jpg`.
- Redundansi/minimalisasi: overlap dengan `UserSeeder`/`CustomerSeeder` sebagai akun demo, tapi punya tujuan khusus access account. Bisa dipertahankan jika memang dipakai presentasi/demo; lebih baik beri dokumentasi kredensial demo terpusat.
- Service fee notice: tidak ada service fee/careful-carry/heavy.
- Risiko: sedang; seeder menyimpan akun demo dan password predictable, aman untuk local/TA tapi jangan dipakai produksi.

## Backend_Bangdeliv/database/seeders/CleanupDemoStorageSeeder.php

- Fungsi: membersihkan folder upload demo sebelum data demo dibuat ulang.
- Logic penting: skip production; hapus directory public storage `avatars`, `driver-documents`, `driver-docs`, `orders`, `ktp`, `selfie`, `sim`.
- Redundansi/minimalisasi: cukup kecil. Ada dua pola nama folder dokumen driver (`driver-documents` dan `driver-docs`); pertahankan sementara untuk cleanup legacy, tapi saat refactor storage path sebaiknya pilih satu nama.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang; salah pakai di environment non-production bisa menghapus upload demo/order proof.

## Backend_Bangdeliv/database/seeders/CustomerSeeder.php

- Fungsi: membuat tiga customer demo.
- Logic penting: seed/update Hassan, Sari, Budi dengan email/phone tetap, password `password123`, role customer, active dan not blacklisted.
- Redundansi/minimalisasi: overlap akun customer dengan `UserSeeder` dan `AccessAccountSeeder`, tapi masih wajar untuk data demo. Bisa digabung ke seeder demo account jika ingin lebih minimal.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah-menengah; perubahan berdampak ke data demo/login testing.

## Backend_Bangdeliv/database/seeders/DatabaseSeeder.php

- Fungsi: orchestrator urutan seeding database.
- Logic penting: memanggil cleanup storage, user, customer, driver cleanup, access account, restaurant menu, dan order cleanup.
- Redundansi/minimalisasi: sudah minimal. Nama `DriverSeeder` dan `OrderSeeder` agak misleading karena sekarang justru cleanup dummy lama, bukan membuat driver/order baru.
- Service fee notice: tidak ada service fee/careful-carry langsung.
- Risiko: sedang; urutan seeding memengaruhi akun demo, restaurant/menu, dan cleanup data lama.

## Backend_Bangdeliv/database/seeders/DriverSeeder.php

- Fungsi: membersihkan driver dummy lama.
- Logic penting: cari user email `agus.driver`, `dwi.driver`, `siti.driver`; force delete driver dengan `withTrashed`, lalu force delete user dummy.
- Redundansi/minimalisasi: nama class tidak lagi sesuai isi karena bukan seed driver baru. Kandidat rename ke cleanup seeder atau gabungkan ke `CleanupDemoStorageSeeder`/cleanup demo data.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang; force delete bisa menghapus data dummy yang masih direferensikan jika dipakai test lama.

## Backend_Bangdeliv/database/seeders/OrderSeeder.php

- Fungsi: membersihkan order seed lama.
- Logic penting: hapus order number `BD-SEED-0001` dan `BD-SEED-0002`.
- Redundansi/minimalisasi: nama class misleading karena hanya cleanup. Jika tidak ada order seed lagi, bisa digabung ke cleanup demo data.
- Service fee notice: tidak ada service fee/careful-carry. Tidak membuat `service_fee`, `order_fee_lines`, atau penalty dummy.
- Risiko: rendah-menengah; delete order dapat memicu cascade relasi jika nomor demo lama masih dipakai.

## Backend_Bangdeliv/database/seeders/RestaurantMenuSeeder.php

- Fungsi: membuat merchant/menu demo untuk Nitip.
- Logic penting: seed 8 merchant dummy restaurant/warung/convenience_store dengan koordinat sekitar area layanan, phone/status active, kategori menu, dan menu harga; memakai `updateOrCreate` by slug/category/name.
- Redundansi/minimalisasi: data cukup panjang tapi masih domain demo. Jika ingin minimal, pisahkan data array ke fixture JSON/PHP config atau kurangi jumlah merchant; namun untuk demo multi-merchant, data ini berguna.
- Service fee notice: tidak ada service fee/careful-carry/heavy. Aman dari cleanup service fee.
- Risiko: sedang; perubahan koordinat/menu memengaruhi demo Nitip, map picker, chatbot merchant, dan route pricing.

## Backend_Bangdeliv/database/seeders/UserSeeder.php

- Fungsi: membuat akun admin dan customer testing.
- Logic penting: admin `admin@bangdeliv.com` dan customer `customer@bangdeliv.com`, password `password123`, role sesuai, active.
- Redundansi/minimalisasi: overlap dengan `AccessAccountSeeder`/`CustomerSeeder`; bisa tetap karena akun admin penting. Untuk minimal, gabungkan semua akun demo ke satu `DemoAccountSeeder`.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah-menengah; akun/password demo jangan dipakai di production.

## Backend_Bangdeliv/resources/views/admin/ai-monitor/index.blade.php

- Fungsi: halaman admin monitor log AI/Gemini.
- Logic penting: query `AiChatLog` langsung di Blade untuk total/today/model success/log terbaru; tampilkan stat cards, tabel log, fallback strategy, dan health panel.
- Redundansi/minimalisasi: query dan agregasi sebaiknya dipindah ke controller/service view model. Ada data hardcoded/semu seperti API health ping dan active connection; tombol Refresh/lihat log tidak punya aksi nyata. Banyak inline style yang bisa masuk class/component.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang; perubahan berdampak ke observability chatbot/admin dashboard.

## Backend_Bangdeliv/resources/views/admin/customers/index.blade.php

- Fungsi: halaman list/filter customer admin.
- Logic penting: query customer langsung di Blade, filter status/search, count active/new/blacklisted, hitung sukses/batal order termasuk `CANCELLED_WITH_FEE`.
- Redundansi/minimalisasi: query/filter/count sebaiknya ke controller; pola tabs/search/table mirip drivers/restaurants/orders dan bisa pakai partial/component. `get()` tanpa pagination bisa berat jika customer banyak; tombol Filter belum punya fungsi selain visual.
- Service fee notice: `CANCELLED_WITH_FEE` tetap dipertahankan sebagai status batal dengan biaya untuk penalti gagal pickup; tidak ada fee lines/careful-carry.
- Risiko: sedang untuk admin customer search/history.

## Backend_Bangdeliv/resources/views/admin/dashboard/index.blade.php

- Fungsi: dashboard KPI/admin analytics.
- Logic penting: query GMV/order/user/status/top restaurant/top driver/daily chart langsung di Blade; pakai `<x-stat-card>` dan Chart.js CDN untuk chart revenue/status.
- Redundansi/minimalisasi: query agregasi harus pindah ke controller/service karena ada banyak query per render dan potensi N+1 top restaurant revenue dalam loop. Status map/count `CANCELLED_WITH_FEE` duplikat dengan halaman lain.
- Service fee notice: tidak ada service fee lines; GMV memakai `total_price`, jadi setelah cleanup total harus sudah benar tanpa service fee generic.
- Risiko: sedang-tinggi untuk metrik admin dan performa halaman.

## Backend_Bangdeliv/resources/views/admin/drivers/verification/index.blade.php

- Fungsi: halaman antrean verifikasi driver.
- Logic penting: tampilkan flash, tabs status verification, search, table driver/document status, link ke detail review.
- Redundansi/minimalisasi: cukup rapi karena data sudah datang dari controller. Flash alert dan header tabs/search berulang dengan halaman lain; bisa pakai partial/component.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk admin onboarding/verifikasi driver.

## Backend_Bangdeliv/resources/views/admin/drivers/verification/show.blade.php

- Fungsi: halaman detail review dokumen driver.
- Logic penting: summary driver, form review dokumen via form eksternal `review-form`, preview/hapus dokumen per tipe, validasi rejected reason.
- Redundansi/minimalisasi: markup card/form cukup panjang tapi masih fokus. Ada karakter encoding rusak pada separator teks email/phone; flash/error panel berulang. Bisa ekstrak document review card.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang-tinggi karena bisa approve/reject/hapus dokumen driver.

## Backend_Bangdeliv/resources/views/admin/drivers/index.blade.php

- Fungsi: halaman list/filter driver admin.
- Logic penting: query driver langsung di Blade, filter status/search, hitung online/offline/suspended/pending, avatar dari storage, link ke verification detail.
- Redundansi/minimalisasi: query dan `Storage::exists()` sebaiknya tidak di Blade; pola tabs/search/table sama dengan customer/restaurant. `get()` tanpa pagination bisa berat jika driver banyak.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk admin driver management.

## Backend_Bangdeliv/resources/views/admin/orders/index.blade.php

- Fungsi: halaman list/filter order admin untuk semua layanan.
- Logic penting: filter service/status/search, count service/status, eager load order relations, render cabang tabel Courier/Ride/default, status map termasuk `CANCELLED_WITH_FEE`, total harga, bukti foto courier, dan link detail.
- Redundansi/minimalisasi: file besar (434 line) dengan query, filter, maps, dan branch UI di Blade. Kandidat pindah query/filter/maps ke controller/view model; pecah row partial per service; status/service badge map jadikan helper/component bersama.
- Service fee notice: tidak ada `fee_lines`/careful-carry/is_heavy. `CANCELLED_WITH_FEE` tetap dipertahankan. Tampilan hanya pakai `total_price`, jadi aman selama total backend sudah dibersihkan.
- Risiko: tinggi karena halaman ini menjadi pusat observasi admin untuk semua order.

## Backend_Bangdeliv/resources/views/admin/orders/show.blade.php

- Fungsi: halaman detail order admin.
- Logic penting: ringkasan order, service/status badge, action hints, detail Shopping/Courier/Ride, payment, lokasi, status history, dan log perubahan.
- Redundansi/minimalisasi: service/status maps duplikat dengan orders index/dashboard/customer; beberapa data seperti payment dibaca dari relasi tapi juga fallback ke field order lama. Banyak inline style dan grid panel bisa dipartialkan.
- Service fee notice: teks action hint "biaya layanan" kandidat ubah saat cleanup agar tidak menghidupkan istilah service fee generic. `CANCELLED_WITH_FEE`, failed attempt count, payment transfer tetap dipertahankan; tidak ada fee lines/careful-carry/is_heavy display.
- Risiko: tinggi untuk audit order, payment, failed pickup, log harga, dan admin support.

## Backend_Bangdeliv/resources/views/admin/restaurants/menus/index.blade.php

- Fungsi: halaman kelola menu per restaurant.
- Logic penting: flash/error, form tambah menu, table menu, delete menu, dan modal edit menu lewat component `menu-edit-modal`.
- Redundansi/minimalisasi: form add dan modal edit punya field yang mirip; bisa jadikan shared menu form partial. Inline style banyak tapi masih manageable.
- Service fee notice: tidak ada service fee/careful-carry/heavy.
- Risiko: sedang untuk katalog menu Nitip.

## Backend_Bangdeliv/resources/views/admin/restaurants/create.blade.php

- Fungsi: form tambah restaurant/warung.
- Logic penting: form info dasar, merchant type, address, phone/status, deskripsi, koordinat, geolocation browser, normalize koma ke titik, swap lat/lng.
- Redundansi/minimalisasi: hampir duplikat penuh dengan `edit.blade.php`, termasuk section form dan script koordinat. Kandidat ekstrak partial `restaurant-form.blade.php` dan script shared.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk data merchant dan koordinat routing Shopping.

## Backend_Bangdeliv/resources/views/admin/restaurants/edit.blade.php

- Fungsi: form edit restaurant/warung.
- Logic penting: field sama seperti create dengan value dari `$restaurant`, update via PUT, script koordinat sama.
- Redundansi/minimalisasi: duplikasi tinggi dengan create; gabungkan partial form dan script agar validasi/error/UI konsisten.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk update merchant dan koordinat routing.

## Backend_Bangdeliv/resources/views/admin/restaurants/index.blade.php

- Fungsi: halaman list/filter merchant restaurant/warung.
- Logic penting: search/tabs status, table merchant, counts menu/order, action kelola menu/edit/toggle/delete, pagination.
- Redundansi/minimalisasi: pola header/tabs/search/table sama dengan halaman lain; bisa pakai reusable page/table partial. Label "Tutup (Luar Jam)" sebenarnya status inactive/suspended, perlu konsisten dengan domain.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk katalog merchant dan status aktif/inaktif.

## Backend_Bangdeliv/resources/views/admin/settings/index.blade.php

- Fungsi: UI pengaturan sistem admin.
- Logic penting: panel tarif ongkir, estimator lokal JS, banner darurat, profil admin, ganti password, toast sukses lokal.
- Redundansi/minimalisasi: halaman ini masih mock/UI-only; tombol simpan tidak POST ke backend. Rumus ongkir hardcoded beda dengan `DeliveryPricingService` (backend punya flat 1.5 km, tier rate, pembulatan 0.7, max 50 km). Jika belum disambungkan, lebih aman hide/ubah menjadi read-only info agar tidak menyesatkan.
- Service fee notice: bukan service fee lines, tapi pengaturan ongkir. Pertahankan konsep `delivery_fee`; jangan tambahkan master service fee baru dari halaman ini.
- Risiko: tinggi secara UX/admin karena terlihat seperti setting nyata padahal tidak persist dan bisa berbeda dari config backend.

## Backend_Bangdeliv/resources/views/auth/login.blade.php

- Fungsi: halaman login admin.
- Logic penting: form email/password POST login, error email, tombol submit, theme toggle lokal.
- Redundansi/minimalisasi: cukup kecil. Theme toggle memakai `body[data-theme]`, sementara admin layout memakai `documentElement[data-theme]`; pastikan CSS mendukung keduanya atau samakan.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk akses admin dan pengalaman login.

## Backend_Bangdeliv/resources/views/components/badge.blade.php

- Fungsi: component badge sederhana.
- Logic penting: map status visual success/warning/danger/info ke class badge dan label default ucfirst.
- Redundansi/minimalisasi: bagus tapi belum banyak dipakai; banyak view masih hardcode `<span class="badge ...">`. Kandidat pakai component ini atau perluas untuk order/service status.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah.

## Backend_Bangdeliv/resources/views/components/menu-edit-modal.blade.php

- Fungsi: modal edit menu reusable.
- Logic penting: form PUT menu, field menu/category/status/description, JS buka/tutup modal dari `.js-edit-menu-btn`, isi data dari dataset, close overlay/Escape.
- Redundansi/minimalisasi: cukup berguna. Aksesibilitas bisa ditingkatkan dengan role dialog, aria label, focus trap, dan restore focus ke tombol edit.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang untuk edit menu admin.

## Backend_Bangdeliv/resources/views/components/page-header.blade.php

- Fungsi: component header panel dengan actions/search/tabs slot.
- Logic penting: title, optional actions slot, optional search input non-form, optional tabs slot.
- Redundansi/minimalisasi: component ini bisa mengurangi duplikasi header/tabs/search, tetapi belum dipakai luas dan search input belum terintegrasi form/query. Kandidat refactor bertahap halaman list memakai component ini.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah.

## Backend_Bangdeliv/resources/views/components/stat-card.blade.php

- Fungsi: component KPI/stat card.
- Logic penting: props title/value/icon/color/change, map warna inline style, optional change icon.
- Redundansi/minimalisasi: sudah dipakai dashboard; AI monitor masih hardcode stat card manual, bisa ikut component ini.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah.

## Backend_Bangdeliv/resources/views/layouts/admin.blade.php

- Fungsi: layout utama admin dengan sidebar, top nav, theme, search autosubmit, dan stack scripts.
- Logic penting: load `admin.css`, Boxicons CDN, set theme sebelum paint, query sidebar counts langsung di layout, menu service order, logout, theme toggle, search debounce auto submit.
- Redundansi/minimalisasi: query count di layout berjalan di semua halaman admin; lebih baik via view composer/cache/controller shared data. Sidebar menu/status service count bisa jadi partial/component. Banyak inline style di sidebar/footer/topbar.
- Service fee notice: tidak ada service fee/careful-carry. Menu layanan Courier tetap ada, tapi tidak menampilkan careful-carry/2 orang.
- Risiko: tinggi karena layout dipakai semua halaman admin; perubahan memengaruhi navigasi, auth logout, theme, search behavior, dan performance.

## Backend_Bangdeliv/resources/views/layouts/auth.blade.php

- Fungsi: layout halaman auth/login.
- Logic penting: load `admin.css`, Boxicons CDN, default body dark theme, apply saved theme ke body.
- Redundansi/minimalisasi: minimal. Theme target beda dengan admin layout (`body` vs `html`); samakan jika CSS theme token butuh konsistensi.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah-menengah untuk login UI/theme.

## Backend_Bangdeliv/routes/api.php

- Fungsi: registry route API auth, chatbot, home/restaurants, order customer, chat order, driver, device token, dan admin API.
- Logic penting: public auth login/register; `auth:sanctum` untuk chatbot/user/address; `v1` public home/restaurants; role customer untuk order/shopping/payment/delivery-fee override; role driver + `driver.active` untuk availability, active order, proof, payment, failed attempt; role admin untuk failed attempt/COD/driver verification.
- Redundansi/minimalisasi: file sudah cukup padat dan `OrderController` menampung banyak endpoint lintas customer/driver/admin. Untuk clean code, pecah route group/order concern atau controller per fitur saat refactor besar. Ada dua jalur status driver (`status-transition` ke `OrderController` dan `status` ke `OrderExecutionController`) yang perlu dicek agar tidak overlap.
- Service fee notice: endpoint `delivery-fee-override` adalah negosiasi/manual edit ongkir dan sebaiknya dipertahankan. Endpoint `attempt-failed` driver/admin juga dipertahankan untuk aturan gagal 3 kali bayar 50%. Tidak ada route eksplisit untuk `service_fee_rules`/`order_fee_lines`.
- Risiko: tinggi karena route API adalah kontrak frontend-backend; rename/hapus endpoint perlu sinkron dengan Flutter services/repositories.

## Backend_Bangdeliv/routes/channels.php

- Fungsi: otorisasi private broadcast channel untuk user, tracking order, dan order driver.
- Logic penting: debug logging broadcast auth saat `app.debug`; `App.Models.User.{id}` cocok user sendiri; `order.tracking.{orderId}` boleh customer pemilik order atau driver assigned; `driver.orders.user.{userId}` hanya driver aktif.
- Redundansi/minimalisasi: closure channel berisi query dan rule domain langsung; bisa dipindah ke policy/helper agar tidak duplikatif dengan middleware `driver.active` dan logic akses order.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: sedang-tinggi untuk realtime tracking/driver order; refactor harus menjaga auth channel Pusher/Reverb.

## Backend_Bangdeliv/routes/console.php

- Fungsi: route command console bawaan Laravel `inspire`.
- Logic penting: hanya command demo untuk menampilkan quote.
- Redundansi/minimalisasi: jika ingin project minimal, command ini bisa dihapus karena tidak terkait aplikasi.
- Service fee notice: tidak ada service fee/careful-carry.
- Risiko: rendah.

## Backend_Bangdeliv/routes/web.php

- Fungsi: route web admin untuk login/logout, dashboard, pesanan, driver/verifikasi, pelanggan, restoran/menu, AI monitor, dan pengaturan.
- Logic penting: root redirect ke dashboard; admin protected memakai `auth` + `role:admin`; sebagian halaman hanya return view closure, order show load relasi lengkap lalu render detail; restaurant/menu dan driver verification memakai controller.
- Redundansi/minimalisasi: banyak route admin masih closure dan mengandalkan query di Blade; lebih clean jika data halaman dipindah ke controller/view model. Order show closure cukup berat dan bisa jadi controller khusus.
- Service fee notice: `admin.orders.show` masih eager-load relasi `feeLines`; ini kandidat hapus/refactor saat `order_fee_lines` dihapus. Route settings membuka halaman pengaturan tarif yang masih mock; jangan dijadikan sumber master service fee baru.
- Risiko: sedang-tinggi untuk admin panel; perubahan route name/path harus sinkron dengan Blade navigation/action.
