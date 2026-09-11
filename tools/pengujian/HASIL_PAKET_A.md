# Hasil Pengujian Paket A - Akurasi Estimasi Jarak dan Ongkos

Berkas ini dihasilkan otomatis oleh `php artisan pengujian:laporan-paket-a`.
Seluruh angka pada tabel di bawah berasal dari eksekusi nyata terhadap
`App\Services\Pricing\DeliveryPricingService` dan `App\Services\Geo\BangDelivServiceAreaService`.

## Konfigurasi Tarif yang Terbaca Sistem

| Parameter | Nilai | Sumber |
|---|---|---|
| Tarif dasar (base_fee) | Rp5.000 | `config/bangdeliv.php` -> `base_delivery_fee` |
| Tarif 0-10 km | Rp2.000/km | `delivery_rate_0_10_per_km` |
| Tarif 10-25 km | Rp2.500/km | `delivery_rate_10_25_per_km` |
| Tarif 25-50 km | Rp3.000/km | `delivery_rate_25_50_per_km` |
| Ambang pembulatan (ROUND_UP_FRACTION) | 0,7 | konstanta `DeliveryPricingService` |
| Jarak bebas ongkir jarak (FLAT_DISTANCE_KM) | 1,5 km | konstanta `DeliveryPricingService` |
| Radius layanan | 50 km | `service_area.radius_km` |

## Tabel A1 - Kesesuaian Perhitungan Ongkos terhadap Hitung Manual

| No | Jarak (km) | Billed km | Tarif/km (Rp) | Ongkos Sistem (Rp) | Ongkos Hitung Manual (Rp) | Sesuai |
|---|---|---|---|---|---|---|
| 1 | 0,30 | 0 | 2.000 | 5.000 | 5.000 | Ya |
| 2 | 0,80 | 0 | 2.000 | 5.000 | 5.000 | Ya |
| 3 | 1,00 | 0 | 2.000 | 5.000 | 5.000 | Ya |
| 4 | 1,60 | 1 | 2.000 | 7.000 | 7.000 | Ya |
| 5 | 2,20 | 2 | 2.000 | 9.000 | 9.000 | Ya |
| 6 | 2,69 | 2 | 2.000 | 9.000 | 9.000 | Ya |
| 7 | 2,70 | 3 | 2.000 | 11.000 | 11.000 | Ya |
| 8 | 2,71 | 3 | 2.000 | 11.000 | 11.000 | Ya |
| 9 | 4,45 | 4 | 2.000 | 13.000 | 13.000 | Ya |
| 10 | 6,80 | 7 | 2.000 | 19.000 | 19.000 | Ya |
| 11 | 8,35 | 8 | 2.000 | 21.000 | 21.000 | Ya |
| 12 | 9,90 | 10 | 2.000 | 25.000 | 25.000 | Ya |
| 13 | 10,00 | 10 | 2.500 | 30.000 | 30.000 | Ya |
| 14 | 10,10 | 10 | 2.500 | 30.000 | 30.000 | Ya |
| 15 | 11,25 | 11 | 2.500 | 32.500 | 32.500 | Ya |
| 16 | 12,69 | 12 | 2.500 | 35.000 | 35.000 | Ya |
| 17 | 12,70 | 13 | 2.500 | 37.500 | 37.500 | Ya |
| 18 | 12,71 | 13 | 2.500 | 37.500 | 37.500 | Ya |
| 19 | 13,40 | 13 | 2.500 | 37.500 | 37.500 | Ya |
| 20 | 17,80 | 18 | 2.500 | 50.000 | 50.000 | Ya |
| 21 | 20,70 | 21 | 2.500 | 57.500 | 57.500 | Ya |
| 22 | 22,95 | 23 | 2.500 | 62.500 | 62.500 | Ya |
| 23 | 24,90 | 25 | 2.500 | 67.500 | 67.500 | Ya |
| 24 | 25,00 | 25 | 3.000 | 80.000 | 80.000 | Ya |
| 25 | 25,10 | 25 | 3.000 | 80.000 | 80.000 | Ya |
| 26 | 27,60 | 27 | 3.000 | 86.000 | 86.000 | Ya |
| 27 | 33,70 | 34 | 3.000 | 107.000 | 107.000 | Ya |
| 28 | 41,85 | 42 | 3.000 | 131.000 | 131.000 | Ya |
| 29 | 49,90 | 50 | 3.000 | 155.000 | 155.000 | Ya |
| 30 | 50,00 | 50 | 3.000 | 155.000 | 155.000 | Ya |

Kesesuaian A1: **30 dari 30 kasus (100,00%)**.

## Tabel A3 - Uji Konsistensi Perhitungan Ongkos

| No | Jarak (km) | Hasil ke-1 (Rp) | Hasil ke-2 (Rp) | Hasil ke-3 (Rp) | Hasil ke-4 (Rp) | Hasil ke-5 (Rp) | Identik |
|---|---|---|---|---|---|---|---|
| 1 | 0,75 | 5.000 | 5.000 | 5.000 | 5.000 | 5.000 | Ya |
| 2 | 3,40 | 11.000 | 11.000 | 11.000 | 11.000 | 11.000 | Ya |
| 3 | 5,70 | 17.000 | 17.000 | 17.000 | 17.000 | 17.000 | Ya |
| 4 | 9,90 | 25.000 | 25.000 | 25.000 | 25.000 | 25.000 | Ya |
| 5 | 10,00 | 30.000 | 30.000 | 30.000 | 30.000 | 30.000 | Ya |
| 6 | 14,25 | 40.000 | 40.000 | 40.000 | 40.000 | 40.000 | Ya |
| 7 | 20,70 | 57.500 | 57.500 | 57.500 | 57.500 | 57.500 | Ya |
| 8 | 25,00 | 80.000 | 80.000 | 80.000 | 80.000 | 80.000 | Ya |
| 9 | 32,60 | 101.000 | 101.000 | 101.000 | 101.000 | 101.000 | Ya |
| 10 | 47,35 | 146.000 | 146.000 | 146.000 | 146.000 | 146.000 | Ya |

Konsistensi A3: **10 dari 10 jarak identik pada 5 pengulangan (100,00%)**.

## Tabel A4 - Batas Radius Layanan

| No | Jarak dari Titik Kumpul (km) | Keputusan Sistem | Keputusan Seharusnya | Sesuai |
|---|---|---|---|---|
| 1 | 49,0 | DITERIMA | DITERIMA | Ya |
| 2 | 49,9 | DITERIMA | DITERIMA | Ya |
| 3 | 50,0 | DITERIMA | DITERIMA | Ya |
| 4 | 50,1 | DITOLAK | DITOLAK | Ya |
| 5 | 51,0 | DITOLAK | DITOLAK | Ya |
| 6 | 60,0 | DITOLAK | DITOLAK | Ya |

Kesesuaian A4: **6 dari 6 kasus (100,00%)**.

Titik uji dibangkitkan pada meridian yang sama dengan titik kumpul sehingga jarak
haversine-nya persis sebesar jarak yang diuji. Koordinat titik uji:

| No | Jarak Target (km) | Lintang | Bujur | Jarak Terhitung Sistem (km) |
|---|---|---|---|---|
| 1 | 49,0 | -6.87924918 | 110.46393595 | 49,0000 |
| 2 | 49,9 | -6.87115529 | 110.46393595 | 49,9000 |
| 3 | 50,0 | -6.87025597 | 110.46393595 | 50,0000 |
| 4 | 50,1 | -6.86935665 | 110.46393595 | 50,1000 |
| 5 | 51,0 | -6.86126275 | 110.46393595 | 51,0000 |
| 6 | 60,0 | -6.78032381 | 110.46393595 | 60,0000 |

## Ringkasan Paket A

| Sub-pengujian | Jumlah Kasus | Sesuai | Persentase Kesesuaian |
|---|---|---|---|
| A1 - Kesesuaian rumus ongkos | 30 | 30 | 100,00% |
| A3 - Konsistensi hasil | 10 | 10 | 100,00% |
| A4 - Batas radius layanan | 6 | 6 | 100,00% |
| **Gabungan** | **46** | **46** | **100,00%** |

Sub-pengujian A2 (deviasi jarak terhadap pembacaan aplikasi Google Maps) dilaporkan
terpisah pada `tools/pengujian/HASIL_A2_DEVIASI_JARAK.md` karena memerlukan pengambilan
data manual dari aplikasi Google Maps.

## Catatan Temuan Pengujian

Pengujian A1 dijalankan dua kali: sekali pada kondisi kode sebelum perbaikan, dan
sekali setelahnya. Bagian ini mencatat keduanya agar penurunan dan kenaikan angka
dapat dipertanggungjawabkan.

| Eksekusi | Kasus Sesuai | Persentase |
|---|---|---|
| Sebelum perbaikan | 26 dari 30 | 86,67% |
| Setelah perbaikan | 30 dari 30 | 100,00% |

### Temuan 1 - Ambang pembulatan 0,7 berlaku tidak konsisten (galat pembulatan bilangan pecahan)

Pada eksekusi pertama, dua kasus batas pembulatan gagal:

| No | Jarak | Seharusnya (rumus 2) | Hasil sistem saat itu | Selisih tarif |
|---|---|---|---|---|
| 17 | 12,70 km | 13 km tertagih, Rp37.500 | 12 km tertagih, Rp35.000 | Rp2.500 |
| 21 | 20,70 km | 21 km tertagih, Rp57.500 | 20 km tertagih, Rp55.000 | Rp2.500 |

Penyebabnya bukan kesalahan rumus, melainkan keterbatasan representasi bilangan
pecahan IEEE-754 pada perhitungan `distance_km - floor(distance_km)`:

| Jarak | Nilai pecahan yang benar-benar dihitung | Hasil perbandingan terhadap 0,7 |
|---|---|---|
| 2,70 / 3,70 / 5,70 / 33,70 / 49,70 km | 0,70000000000000017 | lebih besar, dibulatkan ke atas (benar) |
| 9,70 / 12,70 / 15,70 / 20,70 / 24,70 km | 0,69999999999999929 | lebih kecil, dibulatkan ke bawah (salah) |

Akibatnya jarak 12,70 km ditagih lebih murah daripada 12,71 km, padahal menurut
rumus (2) keduanya sama-sama dibulatkan ke atas.

Perbaikan yang diterapkan: perbandingan ambang dipindahkan dari satuan kilometer
bertipe pecahan ke satuan meter bertipe bilangan bulat, yaitu `sisa_meter >= 700`
menggantikan `pecahan_km >= 0,7`. Perubahan ini tidak mengubah rumus (2), hanya
membuat implementasinya menghasilkan nilai yang benar-benar sesuai rumus.

### Temuan 2 - Cabang jarak <= 1,5 km belum tertulis pada rumus (2)

Dua kasus lain gagal karena implementasi membebaskan pesanan berjarak 1,5 km ke
bawah dari komponen ongkir jarak (`FLAT_DISTANCE_KM`), sedangkan rumus (2) pada
rancangan awal tidak memuat ketentuan tersebut:

| No | Jarak | Rumus awal | Hasil sistem |
|---|---|---|---|
| 2 | 0,80 km | Rp7.000 | Rp5.000 |
| 3 | 1,00 km | Rp7.000 | Rp5.000 |

Pemeriksaan menunjukkan ketentuan ini memang sudah berlaku sejak awal dan telah
diuji pada `tests/Unit/DeliveryPricingServiceTest.php`, sehingga bukan cacat
implementasi melainkan ketentuan yang belum terdokumentasi. Rumus (2) pada Bab III
diperbaiki dengan menambahkan cabang pertama, dan kode tidak diubah.

### Temuan 3 - Rumus (3) tidak memiliki batas atas 50 km

Implementasi menetapkan tarif Rp3.000/km untuk seluruh jarak 25 km ke atas tanpa
plafon, sedangkan rumus (3) menuliskan rentang tertinggi sebagai "25-50 km".
Radius layanan 50 km diukur dari titik kumpul, bukan dari panjang rute, sehingga
rute yang lebih panjang dari 50 km tetap mungkin terjadi selama seluruh titiknya
berada di dalam radius. Penulisan rentang tertinggi pada Bab III perlu dibaca
sebagai "25 km ke atas", atau plafon tarif ditetapkan sebagai pengembangan lanjutan.

### Temuan 4 - Tarif per km ditentukan dari jarak tempuh, bukan dari kilometer tertagih

Pada jarak 9,90 km, tarif yang berlaku adalah Rp2.000/km (rentang 0-10 km) meskipun
kilometer tertagihnya 10 km, sehingga ongkosnya Rp25.000. Pada jarak 10,00 km tarif
berpindah ke Rp2.500/km sehingga ongkosnya Rp30.000. Terdapat lompatan Rp5.000 pada
batas rentang. Perilaku ini konsisten dengan rumus (3) dan bukan kesalahan, namun
urutan penerapannya (tarif ditentukan lebih dulu dari jarak tempuh, baru dikalikan
kilometer tertagih) perlu dinyatakan eksplisit pada Bab III agar tidak menimbulkan
tafsir ganda.
## Perintah untuk Menjalankan Ulang Seluruh Pengujian

Dijalankan dari folder `Backend_Bangdeliv`:

```bash
# A1 dan A4 - kesesuaian rumus ongkos dan batas radius
php artisan test --filter=AkurasiOngkosTest

# A3 - uji konsistensi hasil perhitungan
php artisan pengujian:konsistensi-ongkos

# A2 - deviasi jarak (memerlukan tools/pengujian/rute_uji.csv terisi)
php artisan pengujian:deviasi-jarak --periksa   # memeriksa kelengkapan berkas lebih dulu
php artisan pengujian:deviasi-jarak             # memanggil Google Maps API

# Membuat ulang berkas tabel ini
php artisan pengujian:laporan-paket-a
```
