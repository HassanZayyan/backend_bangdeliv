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
    | Production Admin Seed
    |--------------------------------------------------------------------------
    |
    | Akun admin awal untuk deployment production. Seeder production akan gagal
    | jika email atau password tidak diisi agar tidak ada akun default lemah.
    |
    */
    'production_admin' => [
        'email' => env('BANGDELIV_ADMIN_EMAIL'),
        'password' => env('BANGDELIV_ADMIN_PASSWORD'),
        'name' => env('BANGDELIV_ADMIN_NAME', 'Super Admin'),
        'phone' => env('BANGDELIV_ADMIN_PHONE'),
    ],

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
        'service_area' => [
            'enabled' => (bool) env('ADDRESS_GEOCODING_SERVICE_AREA_ENABLED', true),
            'bounds' => [
                'southwest' => [
                    'latitude' => (float) env('ADDRESS_GEOCODING_SW_LATITUDE', -7.77),
                    'longitude' => (float) env('ADDRESS_GEOCODING_SW_LONGITUDE', 110.01),
                ],
                'northeast' => [
                    'latitude' => (float) env('ADDRESS_GEOCODING_NE_LATITUDE', -6.87),
                    'longitude' => (float) env('ADDRESS_GEOCODING_NE_LONGITUDE', 110.92),
                ],
            ],
            'components' => env('ADDRESS_GEOCODING_COMPONENTS', 'country:ID'),
        ],
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
    | Driver Dispatch
    |--------------------------------------------------------------------------
    |
    | Konfigurasi ringan untuk prioritas driver terdekat. Jarak dispatch
    | dihitung on the fly dari lokasi terakhir driver dan titik pickup order.
    |
    */
    'dispatch' => [
        'near_km' => (float) env('DRIVER_DISPATCH_NEAR_KM', 3),
        'medium_km' => (float) env('DRIVER_DISPATCH_MEDIUM_KM', 7),
        'fresh_location_minutes' => (int) env('DRIVER_DISPATCH_FRESH_LOCATION_MINUTES', 10),
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
    'base_delivery_fee' => env('BASE_DELIVERY_FEE', 5000),         // Rp 5.000
    'delivery_rate_per_km' => env('DELIVERY_RATE_PER_KM', 2000),      // Legacy fallback
    'delivery_rate_0_10_per_km' => env('DELIVERY_RATE_0_10_PER_KM', 2000),
    'delivery_rate_10_25_per_km' => env('DELIVERY_RATE_10_25_PER_KM', 2500),
    'delivery_rate_25_50_per_km' => env('DELIVERY_RATE_25_50_PER_KM', 3000),
    'driver_admin_fee_percent' => env('DRIVER_ADMIN_FEE_PERCENT', 10),

    /*
    |--------------------------------------------------------------------------
    | Pelanggan 15 Service Area
    |--------------------------------------------------------------------------
    |
    | Batas layanan dihitung sebagai radius dari titik kumpul Pelanggan 15.
    | Ongkir tetap dihitung dari jarak rute aktual.
    |
    */
    'service_area' => [
        'name' => env('BANGDELIV_SERVICE_AREA_NAME', 'Angkringan 54'),
        'address' => env(
            'BANGDELIV_SERVICE_AREA_ADDRESS',
            'Jl. Raya Sraten No.8, Sraten Satu, Gedangan, Kec. Tuntang, Kabupaten Semarang, Jawa Tengah 50773'
        ),
        'center' => [
            'latitude' => (float) env('BANGDELIV_SERVICE_AREA_CENTER_LATITUDE', -7.319916770351389),
            'longitude' => (float) env('BANGDELIV_SERVICE_AREA_CENTER_LONGITUDE', 110.46393594806243),
        ],
        'radius_km' => (float) env('BANGDELIV_SERVICE_AREA_RADIUS_KM', 50),
    ],

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
    'new_account_threshold' => env('NEW_ACCOUNT_THRESHOLD', 3),       // 3 order pertama

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
        'draft_ttl_minutes' => env('CHATBOT_DRAFT_TTL_MINUTES', 120),
        'out_of_context_limit' => env('CHATBOT_OUT_OF_CONTEXT_LIMIT', 3),
        'out_of_context_window_minutes' => env('CHATBOT_OUT_OF_CONTEXT_WINDOW_MINUTES', 30),
        'out_of_context_block_minutes' => env('CHATBOT_OUT_OF_CONTEXT_BLOCK_MINUTES', 15),
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
