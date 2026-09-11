<?php

/*
|--------------------------------------------------------------------------
| Helper Pengujian Paket A
|--------------------------------------------------------------------------
|
| Fungsi bantu yang dipakai bersama oleh berkas uji dan perintah artisan
| pembuat laporan. Sengaja ditaruh di luar app/ agar tidak mencampuri kode
| produksi.
|
*/

if (! function_exists('bangdeliv_titik_pada_jarak_km')) {
    /**
     * Membangkitkan koordinat yang berjarak PERSIS $jarakKm dari titik pusat.
     *
     * Titik hasil digeser ke arah utara pada meridian yang sama (bujur tetap).
     * Pada perhitungan haversine, pergeseran sepanjang meridian menghasilkan
     * jarak = R x delta_lintang(radian), sehingga jaraknya dapat ditentukan
     * secara eksak tanpa perlu iterasi. R yang dipakai harus sama dengan
     * konstanta pada App\Support\GeoDistance (6.371.000 meter).
     *
     * @return array{0: float, 1: float} [lintang, bujur]
     */
    function bangdeliv_titik_pada_jarak_km(float $pusatLintang, float $pusatBujur, float $jarakKm): array
    {
        $radiusBumiKm = 6371.0;
        $deltaDerajat = ($jarakKm / $radiusBumiKm) * (180 / M_PI);

        return [$pusatLintang + $deltaDerajat, $pusatBujur];
    }
}

if (! function_exists('bangdeliv_rupiah')) {
    /**
     * Format angka menjadi teks rupiah gaya Indonesia untuk tabel laporan.
     */
    function bangdeliv_rupiah(float|int $nilai): string
    {
        return number_format((float) $nilai, 0, ',', '.');
    }
}

if (! function_exists('bangdeliv_desimal')) {
    /**
     * Format angka desimal dengan koma sebagai pemisah desimal.
     */
    function bangdeliv_desimal(float|int $nilai, int $digit = 2): string
    {
        return number_format((float) $nilai, $digit, ',', '.');
    }
}
