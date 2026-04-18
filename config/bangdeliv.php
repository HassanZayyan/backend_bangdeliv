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
    | Google Maps Geocoding API
    |--------------------------------------------------------------------------
    |
    | Konfigurasi geocoding untuk validasi alamat tujuan layanan antar jemput.
    |
    */
    'geocoding' => [
        'endpoint' => env('GOOGLE_MAPS_GEOCODING_ENDPOINT', 'https://maps.googleapis.com/maps/api/geocode/json'),
        'timeout_seconds' => (int) env('GOOGLE_MAPS_GEOCODING_TIMEOUT', 8),
        'language' => env('GOOGLE_MAPS_GEOCODING_LANGUAGE', 'id'),
        'region' => env('GOOGLE_MAPS_GEOCODING_REGION', 'id'),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Chatbot Rate Limit
    |--------------------------------------------------------------------------
    |
    | Batas request chatbot per user terautentikasi untuk mencegah abuse.
    |
    */
    'chatbot' => [
        'rate_limit_per_minute' => env('CHATBOT_RATE_LIMIT_PER_MINUTE', 12),
        'rate_limit_per_hour' => env('CHATBOT_RATE_LIMIT_PER_HOUR', 120),
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'timeout_seconds' => env('GEMINI_TIMEOUT_SECONDS', 12),
            'models' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env(
                    'GEMINI_MODELS',
                    'gemini-3.1-flash-lite-preview,gemini-2.5-flash,gemini-2.5-flash-lite,gemini-3-flash-preview'
                ))
            ))),
        ],
    ],

];
