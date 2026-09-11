<?php

/*
|--------------------------------------------------------------------------
| Kasus Uji Paket A - Akurasi Estimasi Jarak dan Ongkos
|--------------------------------------------------------------------------
|
| Berkas ini adalah SATU-SATUNYA sumber data kasus uji Paket A. Dipakai oleh:
|   - tests/Feature/Pengujian/AkurasiOngkosTest.php  (A1 dan A4)
|   - php artisan pengujian:konsistensi-ongkos        (A3)
|   - php artisan pengujian:laporan-paket-a           (pembuat tabel laporan)
|
| PENTING UNTUK PERTANGGUNGJAWABAN ILMIAH:
| Seluruh angka pada kolom "harapan" di bawah dihitung TANGAN mengikuti rumus
| yang dirancang pada Bab III, BUKAN hasil pemanggilan DeliveryPricingService.
| Dengan begitu pengujian tidak tautologis (bukan menguji kode dengan kode itu
| sendiri). Kolom "hitung_manual" menuliskan langkah aritmetikanya agar dapat
| diperiksa ulang oleh penguji baris demi baris.
|
| Rumus acuan (Bab III):
|   Rumus (1) total_fee = base_fee + (billed_km x rate_per_km)
|   Rumus (2) billed_km = 0                  bila distance_km <= 1,5
|                       = ceil(distance_km)  bila pecahan >= 0,7
|                       = floor(distance_km) bila pecahan <  0,7
|   Rumus (3) rate_per_km: 0-10 km  -> Rp2.000
|                          10-25 km -> Rp2.500
|                          25-50 km -> Rp3.000
|   Rumus (9) order ditolak bila jarak titik ke pusat layanan > radius_km (50 km)
|
| base_fee = Rp5.000 (config bangdeliv.base_delivery_fee)
|
| Catatan revisi rumus (2):
| Cabang pertama (jarak <= 1,5 km dibebaskan dari ongkir jarak) ditambahkan ke
| Bab III setelah pengujian menemukan bahwa aturan tersebut sudah lama berlaku
| pada implementasi (konstanta FLAT_DISTANCE_KM) namun belum tertulis pada
| rancangan. Dasar kebijakannya: pesanan sangat dekat cukup ditagih tarif dasar
| karena tarif dasar sudah menutup biaya pelayanan minimum.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | A1 - Kesesuaian implementasi terhadap rumus (30 kasus)
    |----------------------------------------------------------------------
    |
    | kelompok: batas_tarif | batas_pembulatan | jarak_pendek | acak
    |
    */
    'a1' => [
        // --- Jarak sangat pendek -------------------------------------------------
        ['no' => 1, 'jarak_km' => 0.30, 'kelompok' => 'jarak_pendek', 'keterangan' => 'Jarak sangat pendek, di bawah ambang bebas ongkir jarak',
            'billed_km' => 0, 'rate_per_km' => 2000, 'total_fee' => 5000,
            'hitung_manual' => 'jarak 0,30 <= 1,5 -> billed 0 km; 5.000 + (0 x 2.000) = 5.000'],

        ['no' => 2, 'jarak_km' => 0.80, 'kelompok' => 'jarak_pendek', 'keterangan' => 'Pecahan >= 0,7 tetapi masih di bawah ambang bebas ongkir jarak',
            'billed_km' => 0, 'rate_per_km' => 2000, 'total_fee' => 5000,
            'hitung_manual' => 'jarak 0,80 <= 1,5 -> billed 0 km (cabang pertama menang atas pembulatan); 5.000 + (0 x 2.000) = 5.000'],

        ['no' => 3, 'jarak_km' => 1.00, 'kelompok' => 'jarak_pendek', 'keterangan' => 'Jarak bulat 1 km, masih di bawah ambang bebas ongkir jarak',
            'billed_km' => 0, 'rate_per_km' => 2000, 'total_fee' => 5000,
            'hitung_manual' => 'jarak 1,00 <= 1,5 -> billed 0 km; 5.000 + (0 x 2.000) = 5.000'],

        // --- Rentang tarif 0-10 km (Rp2.000/km) ---------------------------------
        ['no' => 4, 'jarak_km' => 1.60, 'kelompok' => 'acak', 'keterangan' => 'Tepat di atas ambang bebas ongkir jarak 1,5 km',
            'billed_km' => 1, 'rate_per_km' => 2000, 'total_fee' => 7000,
            'hitung_manual' => 'jarak 1,60 > 1,5; pecahan 0,60 < 0,7 -> floor(1,60)=1 km; 5.000 + (1 x 2.000) = 7.000'],

        ['no' => 5, 'jarak_km' => 2.20, 'kelompok' => 'acak', 'keterangan' => 'Rentang 0-10 km',
            'billed_km' => 2, 'rate_per_km' => 2000, 'total_fee' => 9000,
            'hitung_manual' => 'pecahan 0,20 < 0,7 -> floor(2,20)=2 km; 5.000 + (2 x 2.000) = 9.000'],

        ['no' => 6, 'jarak_km' => 2.69, 'kelompok' => 'batas_pembulatan', 'keterangan' => 'Tepat di bawah ambang 0,7',
            'billed_km' => 2, 'rate_per_km' => 2000, 'total_fee' => 9000,
            'hitung_manual' => 'pecahan 0,69 < 0,7 -> floor(2,69)=2 km; 5.000 + (2 x 2.000) = 9.000'],

        ['no' => 7, 'jarak_km' => 2.70, 'kelompok' => 'batas_pembulatan', 'keterangan' => 'Tepat pada ambang 0,7',
            'billed_km' => 3, 'rate_per_km' => 2000, 'total_fee' => 11000,
            'hitung_manual' => 'pecahan 0,70 >= 0,7 -> ceil(2,70)=3 km; 5.000 + (3 x 2.000) = 11.000'],

        ['no' => 8, 'jarak_km' => 2.71, 'kelompok' => 'batas_pembulatan', 'keterangan' => 'Tepat di atas ambang 0,7',
            'billed_km' => 3, 'rate_per_km' => 2000, 'total_fee' => 11000,
            'hitung_manual' => 'pecahan 0,71 >= 0,7 -> ceil(2,71)=3 km; 5.000 + (3 x 2.000) = 11.000'],

        ['no' => 9, 'jarak_km' => 4.45, 'kelompok' => 'acak', 'keterangan' => 'Rentang 0-10 km',
            'billed_km' => 4, 'rate_per_km' => 2000, 'total_fee' => 13000,
            'hitung_manual' => 'pecahan 0,45 < 0,7 -> floor(4,45)=4 km; 5.000 + (4 x 2.000) = 13.000'],

        ['no' => 10, 'jarak_km' => 6.80, 'kelompok' => 'acak', 'keterangan' => 'Rentang 0-10 km',
            'billed_km' => 7, 'rate_per_km' => 2000, 'total_fee' => 19000,
            'hitung_manual' => 'pecahan 0,80 >= 0,7 -> ceil(6,80)=7 km; 5.000 + (7 x 2.000) = 19.000'],

        ['no' => 11, 'jarak_km' => 8.35, 'kelompok' => 'acak', 'keterangan' => 'Rentang 0-10 km',
            'billed_km' => 8, 'rate_per_km' => 2000, 'total_fee' => 21000,
            'hitung_manual' => 'pecahan 0,35 < 0,7 -> floor(8,35)=8 km; 5.000 + (8 x 2.000) = 21.000'],

        // --- Batas tarif 10 km ---------------------------------------------------
        ['no' => 12, 'jarak_km' => 9.90, 'kelompok' => 'batas_tarif', 'keterangan' => 'Tepat di bawah batas 10 km',
            'billed_km' => 10, 'rate_per_km' => 2000, 'total_fee' => 25000,
            'hitung_manual' => 'jarak 9,90 < 10 -> tarif 2.000; pecahan 0,90 >= 0,7 -> ceil=10 km; 5.000 + (10 x 2.000) = 25.000'],

        ['no' => 13, 'jarak_km' => 10.00, 'kelompok' => 'batas_tarif', 'keterangan' => 'Tepat pada batas 10 km',
            'billed_km' => 10, 'rate_per_km' => 2500, 'total_fee' => 30000,
            'hitung_manual' => 'jarak 10,00 >= 10 -> tarif 2.500; pecahan 0,00 < 0,7 -> floor=10 km; 5.000 + (10 x 2.500) = 30.000'],

        ['no' => 14, 'jarak_km' => 10.10, 'kelompok' => 'batas_tarif', 'keterangan' => 'Tepat di atas batas 10 km',
            'billed_km' => 10, 'rate_per_km' => 2500, 'total_fee' => 30000,
            'hitung_manual' => 'jarak 10,10 >= 10 -> tarif 2.500; pecahan 0,10 < 0,7 -> floor=10 km; 5.000 + (10 x 2.500) = 30.000'],

        // --- Rentang tarif 10-25 km (Rp2.500/km) --------------------------------
        ['no' => 15, 'jarak_km' => 11.25, 'kelompok' => 'acak', 'keterangan' => 'Rentang 10-25 km',
            'billed_km' => 11, 'rate_per_km' => 2500, 'total_fee' => 32500,
            'hitung_manual' => 'pecahan 0,25 < 0,7 -> floor(11,25)=11 km; 5.000 + (11 x 2.500) = 32.500'],

        ['no' => 16, 'jarak_km' => 12.69, 'kelompok' => 'batas_pembulatan', 'keterangan' => 'Tepat di bawah ambang 0,7',
            'billed_km' => 12, 'rate_per_km' => 2500, 'total_fee' => 35000,
            'hitung_manual' => 'pecahan 0,69 < 0,7 -> floor(12,69)=12 km; 5.000 + (12 x 2.500) = 35.000'],

        ['no' => 17, 'jarak_km' => 12.70, 'kelompok' => 'batas_pembulatan', 'keterangan' => 'Tepat pada ambang 0,7',
            'billed_km' => 13, 'rate_per_km' => 2500, 'total_fee' => 37500,
            'hitung_manual' => 'pecahan 0,70 >= 0,7 -> ceil(12,70)=13 km; 5.000 + (13 x 2.500) = 37.500'],

        ['no' => 18, 'jarak_km' => 12.71, 'kelompok' => 'batas_pembulatan', 'keterangan' => 'Tepat di atas ambang 0,7',
            'billed_km' => 13, 'rate_per_km' => 2500, 'total_fee' => 37500,
            'hitung_manual' => 'pecahan 0,71 >= 0,7 -> ceil(12,71)=13 km; 5.000 + (13 x 2.500) = 37.500'],

        ['no' => 19, 'jarak_km' => 13.40, 'kelompok' => 'acak', 'keterangan' => 'Rentang 10-25 km',
            'billed_km' => 13, 'rate_per_km' => 2500, 'total_fee' => 37500,
            'hitung_manual' => 'pecahan 0,40 < 0,7 -> floor(13,40)=13 km; 5.000 + (13 x 2.500) = 37.500'],

        ['no' => 20, 'jarak_km' => 17.80, 'kelompok' => 'acak', 'keterangan' => 'Rentang 10-25 km',
            'billed_km' => 18, 'rate_per_km' => 2500, 'total_fee' => 50000,
            'hitung_manual' => 'pecahan 0,80 >= 0,7 -> ceil(17,80)=18 km; 5.000 + (18 x 2.500) = 50.000'],

        ['no' => 21, 'jarak_km' => 20.70, 'kelompok' => 'batas_pembulatan', 'keterangan' => 'Pecahan tepat 0,7 pada rentang 10-25 km',
            'billed_km' => 21, 'rate_per_km' => 2500, 'total_fee' => 57500,
            'hitung_manual' => 'pecahan 0,70 >= 0,7 -> ceil(20,70)=21 km; 5.000 + (21 x 2.500) = 57.500'],

        ['no' => 22, 'jarak_km' => 22.95, 'kelompok' => 'acak', 'keterangan' => 'Rentang 10-25 km',
            'billed_km' => 23, 'rate_per_km' => 2500, 'total_fee' => 62500,
            'hitung_manual' => 'pecahan 0,95 >= 0,7 -> ceil(22,95)=23 km; 5.000 + (23 x 2.500) = 62.500'],

        // --- Batas tarif 25 km ---------------------------------------------------
        ['no' => 23, 'jarak_km' => 24.90, 'kelompok' => 'batas_tarif', 'keterangan' => 'Tepat di bawah batas 25 km',
            'billed_km' => 25, 'rate_per_km' => 2500, 'total_fee' => 67500,
            'hitung_manual' => 'jarak 24,90 < 25 -> tarif 2.500; pecahan 0,90 >= 0,7 -> ceil=25 km; 5.000 + (25 x 2.500) = 67.500'],

        ['no' => 24, 'jarak_km' => 25.00, 'kelompok' => 'batas_tarif', 'keterangan' => 'Tepat pada batas 25 km',
            'billed_km' => 25, 'rate_per_km' => 3000, 'total_fee' => 80000,
            'hitung_manual' => 'jarak 25,00 >= 25 -> tarif 3.000; pecahan 0,00 < 0,7 -> floor=25 km; 5.000 + (25 x 3.000) = 80.000'],

        ['no' => 25, 'jarak_km' => 25.10, 'kelompok' => 'batas_tarif', 'keterangan' => 'Tepat di atas batas 25 km',
            'billed_km' => 25, 'rate_per_km' => 3000, 'total_fee' => 80000,
            'hitung_manual' => 'jarak 25,10 >= 25 -> tarif 3.000; pecahan 0,10 < 0,7 -> floor=25 km; 5.000 + (25 x 3.000) = 80.000'],

        // --- Rentang tarif 25-50 km (Rp3.000/km) --------------------------------
        ['no' => 26, 'jarak_km' => 27.60, 'kelompok' => 'acak', 'keterangan' => 'Rentang 25-50 km',
            'billed_km' => 27, 'rate_per_km' => 3000, 'total_fee' => 86000,
            'hitung_manual' => 'pecahan 0,60 < 0,7 -> floor(27,60)=27 km; 5.000 + (27 x 3.000) = 86.000'],

        ['no' => 27, 'jarak_km' => 33.70, 'kelompok' => 'batas_pembulatan', 'keterangan' => 'Pecahan tepat 0,7 pada rentang 25-50 km',
            'billed_km' => 34, 'rate_per_km' => 3000, 'total_fee' => 107000,
            'hitung_manual' => 'pecahan 0,70 >= 0,7 -> ceil(33,70)=34 km; 5.000 + (34 x 3.000) = 107.000'],

        ['no' => 28, 'jarak_km' => 41.85, 'kelompok' => 'acak', 'keterangan' => 'Rentang 25-50 km',
            'billed_km' => 42, 'rate_per_km' => 3000, 'total_fee' => 131000,
            'hitung_manual' => 'pecahan 0,85 >= 0,7 -> ceil(41,85)=42 km; 5.000 + (42 x 3.000) = 131.000'],

        // --- Batas tarif 50 km ---------------------------------------------------
        ['no' => 29, 'jarak_km' => 49.90, 'kelompok' => 'batas_tarif', 'keterangan' => 'Tepat di bawah batas 50 km',
            'billed_km' => 50, 'rate_per_km' => 3000, 'total_fee' => 155000,
            'hitung_manual' => 'jarak 49,90 -> tarif 3.000; pecahan 0,90 >= 0,7 -> ceil=50 km; 5.000 + (50 x 3.000) = 155.000'],

        ['no' => 30, 'jarak_km' => 50.00, 'kelompok' => 'batas_tarif', 'keterangan' => 'Tepat pada batas 50 km',
            'billed_km' => 50, 'rate_per_km' => 3000, 'total_fee' => 155000,
            'hitung_manual' => 'jarak 50,00 -> tarif 3.000; pecahan 0,00 < 0,7 -> floor=50 km; 5.000 + (50 x 3.000) = 155.000'],
    ],

    /*
    |----------------------------------------------------------------------
    | A3 - Uji konsistensi (10 jarak, masing-masing diulang 5 kali)
    |----------------------------------------------------------------------
    |
    | Sepuluh jarak dipilih menyebar pada ketiga rentang tarif, termasuk dua
    | jarak yang berada tepat di batas rentang (10,00 dan 25,00 km) dan satu
    | jarak berpecahan tepat 0,7 sebagai kasus paling rawan.
    |
    */
    'a3' => [
        ['no' => 1, 'jarak_km' => 0.75],
        ['no' => 2, 'jarak_km' => 3.40],
        ['no' => 3, 'jarak_km' => 5.70],
        ['no' => 4, 'jarak_km' => 9.90],
        ['no' => 5, 'jarak_km' => 10.00],
        ['no' => 6, 'jarak_km' => 14.25],
        ['no' => 7, 'jarak_km' => 20.70],
        ['no' => 8, 'jarak_km' => 25.00],
        ['no' => 9, 'jarak_km' => 32.60],
        ['no' => 10, 'jarak_km' => 47.35],
    ],

    /*
    |----------------------------------------------------------------------
    | A4 - Batas radius layanan (rumus 9)
    |----------------------------------------------------------------------
    |
    | Titik uji dibangkitkan pada meridian yang sama dengan titik kumpul
    | sehingga jarak haversine-nya persis sebesar jarak_km yang diminta
    | (lihat bangdeliv_titik_pada_jarak_km() di helper_pengujian.php).
    |
    | Rumus (9): ditolak bila jarak > radius (50 km). Batas 50 km bersifat
    | inklusif, artinya tepat 50 km masih DITERIMA.
    |
    */
    'a4' => [
        ['no' => 1, 'jarak_km' => 49.0, 'keputusan_seharusnya' => 'DITERIMA', 'alasan' => '49,0 <= 50 -> di dalam radius'],
        ['no' => 2, 'jarak_km' => 49.9, 'keputusan_seharusnya' => 'DITERIMA', 'alasan' => '49,9 <= 50 -> di dalam radius'],
        ['no' => 3, 'jarak_km' => 50.0, 'keputusan_seharusnya' => 'DITERIMA', 'alasan' => '50,0 <= 50 -> tepat di batas, inklusif'],
        ['no' => 4, 'jarak_km' => 50.1, 'keputusan_seharusnya' => 'DITOLAK', 'alasan' => '50,1 > 50 -> di luar radius'],
        ['no' => 5, 'jarak_km' => 51.0, 'keputusan_seharusnya' => 'DITOLAK', 'alasan' => '51,0 > 50 -> di luar radius'],
        ['no' => 6, 'jarak_km' => 60.0, 'keputusan_seharusnya' => 'DITOLAK', 'alasan' => '60,0 > 50 -> jauh di luar radius'],
    ],
];
