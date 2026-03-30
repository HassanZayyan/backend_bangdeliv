<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Maps API
    |--------------------------------------------------------------------------
    |
    | API key untuk Google Maps Distance Matrix API.
    | Digunakan untuk kalkulasi jarak & ongkir.
    |
    */
    'google_maps_api_key' => env('GOOGLE_MAPS_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Delivery Fee Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi tarif ongkir dinamis berdasarkan jarak.
    | delivery_fee = clamp(jarak_km × rate_per_km, min_fee, max_fee)
    |
    */
    'delivery_rate_per_km' => env('DELIVERY_RATE_PER_KM', 3000),     // Rp 3.000/km
    'min_delivery_fee'     => env('MIN_DELIVERY_FEE', 5000),          // Rp 5.000
    'max_delivery_fee'     => env('MAX_DELIVERY_FEE', 25000),         // Rp 25.000
    'max_delivery_distance' => env('MAX_DELIVERY_DISTANCE', 15),      // 15 km

    /*
    |--------------------------------------------------------------------------
    | Anti-Fraud: New Account Limits
    |--------------------------------------------------------------------------
    |
    | Akun baru dibatasi maksimum order untuk mencegah fake order.
    | Setelah melampaui threshold, limit dicabut.
    |
    */
    'new_account_order_limit' => env('NEW_ACCOUNT_ORDER_LIMIT', 50000), // Rp 50.000
    'new_account_threshold'   => env('NEW_ACCOUNT_THRESHOLD', 3),       // 3 order pertama

];
