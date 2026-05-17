<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Maps API
    |--------------------------------------------------------------------------
    |
    | API key untuk Google Maps API.
    | Digunakan untuk geocoding dan kalkulasi jarak rute.
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
    | Google Maps Distance Matrix API
    |--------------------------------------------------------------------------
    |
    | Konfigurasi distance matrix untuk hitung jarak rute jalan dan durasi.
    |
    */
    'distance_matrix' => [
        'endpoint' => env('GOOGLE_MAPS_DISTANCE_MATRIX_ENDPOINT', 'https://maps.googleapis.com/maps/api/distancematrix/json'),
        'timeout_seconds' => (int) env('GOOGLE_MAPS_DISTANCE_MATRIX_TIMEOUT', 8),
        'mode' => env('GOOGLE_MAPS_DISTANCE_MATRIX_MODE', 'driving'),
        'language' => env('GOOGLE_MAPS_DISTANCE_MATRIX_LANGUAGE', 'id'),
        'region' => env('GOOGLE_MAPS_DISTANCE_MATRIX_REGION', 'id'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Maps Routes API
    |--------------------------------------------------------------------------
    |
    | Fallback modern untuk Distance Matrix API legacy.
    |
    */
    'routes' => [
        'endpoint' => env('GOOGLE_MAPS_ROUTES_ENDPOINT', 'https://routes.googleapis.com/directions/v2:computeRoutes'),
        'timeout_seconds' => (int) env('GOOGLE_MAPS_ROUTES_TIMEOUT', 8),
        'travel_mode' => env('GOOGLE_MAPS_ROUTES_TRAVEL_MODE', 'TWO_WHEELER'),
        'routing_preference' => env('GOOGLE_MAPS_ROUTES_ROUTING_PREFERENCE', 'TRAFFIC_AWARE'),
        'language_code' => env('GOOGLE_MAPS_ROUTES_LANGUAGE', 'id'),
        'region_code' => env('GOOGLE_MAPS_ROUTES_REGION', 'ID'),
        'units' => env('GOOGLE_MAPS_ROUTES_UNITS', 'METRIC'),
        'optimize_shopping_waypoints' => (bool) env('GOOGLE_MAPS_ROUTES_OPTIMIZE_SHOPPING_WAYPOINTS', true),
        'shopping_route_max_origin_candidates' => (int) env('SHOPPING_ROUTE_MAX_ORIGIN_CANDIDATES', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery Fee Configuration
    |--------------------------------------------------------------------------
    |
    | Rumus ongkir:
    | billed_km = ceil(distance_meter / 1000)
    | delivery_fee = base_fee + (billed_km × rate_per_km)
    |
    */
    'base_delivery_fee'    => env('BASE_DELIVERY_FEE', 5000),         // Rp 5.000
    'delivery_rate_per_km' => env('DELIVERY_RATE_PER_KM', 2000),      // Rp 2.000 per km tertagih
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
                    'gemini-3.1-flash-lite,gemini-2.5-flash-lite,gemini-2.5-flash,gemini-3-flash-preview'
                ))
            ))),
        ],
    ],

];
