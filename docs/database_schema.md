# Database Schema Notes

Dokumen ini menjelaskan alasan tabel utama BangDeliv disimpan di database setelah cleanup migration. Prinsipnya: database hanya menyimpan data master, snapshot transaksi, audit, operational runtime yang perlu bertahan lintas request, dan infrastruktur Laravel.

## Master Data

- `users`, `drivers`, `driver_documents`: identitas user, profil driver, dan dokumen verifikasi.
- `restaurants`, `menu_categories`, `menus`: katalog merchant/menu yang dapat berubah dari admin.
- `service_types`, `order_statuses`, `service_fee_rules`: referensi domain dan aturan biaya.
- `addresses`: alamat tersimpan milik customer.

## Transactional Snapshot

- `orders`: header transaksi, status aktif, driver aktif, total harga, timestamp cancel/deliver, dan `route_snapshot`. Order tidak memakai soft delete karena pembatalan adalah status terminal yang diaudit.
- `route_snapshot` adalah snapshot rute/ongkir dari Google Maps saat order dibuat atau dihitung ulang. Field ini tetap disimpan agar harga, jarak, polyline, dan audit tidak berubah saat hasil API eksternal berubah.
- `order_locations`: snapshot pickup/dropoff order, termasuk multi-pickup untuk shopping. `contact_name`, `contact_phone`, `restaurant_id`, dan field failed/resolved dipertahankan karena stop order harus tetap bisa dibaca walaupun profil user/merchant berubah atau merchant gagal diproses.
- `shopping_order_items`: snapshot item saat order. `menu_name`, `unit_price`, dan `subtotal` sengaja disimpan agar riwayat tidak berubah saat menu berubah.
- `order_fee_lines`: breakdown biaya final pada order.
- `ride_order_details`, `courier_order_details`, `shopping_order_receipts`: detail khusus service type.

## Audit Log And Evidence

- `order_events`: audit trail status, payment update, shopping stop update, price recalculation, dan aksi penting lain. Snapshot driver saat accept order disimpan di `metadata.driver_snapshot`, sehingga histori tetap jelas jika data driver berubah.
- `order_evidence`: foto bukti pickup/delivery/receipt/store closed/payment transfer.
- `order_payments`: catatan pembayaran final, collector, waktu bayar, dan metadata audit.
- `ai_chat_sessions`, `ai_chat_messages`, `ai_message_details`: riwayat chatbot dan draft AI untuk recovery/debug.
- `order_chat_messages`: chat customer-driver yang menjadi bagian dari riwayat order.

## Operational Runtime

- `drivers.latitude`, `drivers.longitude`, `drivers.location_updated_at`: lokasi terakhir driver. BangDeliv hanya butuh latest location untuk dispatch, tracking, dan ETA; tidak menyimpan histori lokasi.
- `device_tokens`: token notifikasi aktif per device.
- `order_chat_reads`: cursor pesan terakhir dibaca untuk unread count.

## Laravel Infrastructure

- `cache`, `cache_locks`: cache database Laravel, termasuk cache ETA/retry ringan jika driver cache memakai database.
- `sessions`: session store Laravel.
- `jobs`, `job_batches`, `failed_jobs`: queue runtime.
- `personal_access_tokens`: token Sanctum.
- `password_reset_tokens`: token reset password sementara.

## Computed API Fields

- `delivery_distance_km` dan `delivery_distance_text` tidak ada sebagai kolom database. Keduanya computed dari `orders.route_snapshot` oleh model/presenter agar API lama tetap kompatibel.
- `driver_eta` tidak disimpan di database. ETA dihitung saat detail tracking customer diminta dan bisa di-cache pendek.
- `estimated_delivery` lama sudah dihapus karena BangDeliv tidak lagi menampilkan estimasi order selesai end-to-end.

## V2 Candidates

- `ride_order_details.picked_up_at` dan `arrived_at` overlap sebagian dengan `order_events`. Untuk clean aman TA, kolom ini dipertahankan. Refactor v2 bisa memilih satu sumber waktu lifecycle saja.
- `shopping_order_items.metadata` masih dipakai untuk status harga/receipt/failure detail. Jika schema ingin lebih eksplisit, field tertentu seperti `price_status` dapat dipromosikan menjadi kolom terpisah pada refactor berikutnya.
