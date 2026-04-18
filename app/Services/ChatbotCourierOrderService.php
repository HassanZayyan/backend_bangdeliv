<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\CourierOrder;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\ServiceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ChatbotCourierOrderService
{
    /**
     * @var array<int, string>
     */
    private array $locationHints = [
        'jalan',
        'jl',
        'gang',
        'gg',
        'blok',
        'no',
        'rt',
        'rw',
        'desa',
        'kelurahan',
        'kecamatan',
        'kota',
        'kabupaten',
        'stasiun',
        'bandara',
        'terminal',
        'mall',
        'kantor',
        'rumah',
        'apartemen',
        'kampus',
        'perumahan',
        'kos',
    ];

    /**
     * @var array<int, string>
     */
    private array $packageOnlyKeywords = [
        'dokumen',
        'berkas',
        'paket',
        'barang',
        'makanan',
        'obat',
        'surat',
    ];

    /**
     * @return array<string, mixed>
     */
    public function process(User $user, string $message): array
    {
        if ($user->role !== 'customer') {
            throw new ApiException('Hanya customer yang dapat membuat order kurir dari chatbot.', 403);
        }

        if (!$user->is_active || $user->is_blacklisted) {
            throw new ApiException('Akun tidak memenuhi syarat untuk membuat order kurir.', 403);
        }

        $defaultPickupAddress = $this->resolveDefaultPickupAddress($user);
        $parsed = $this->parseCourierPayload($message, $defaultPickupAddress);

        if (($parsed['validation']['is_valid_order'] ?? false) !== true) {
            $validation = is_array($parsed['validation'] ?? null)
                ? $parsed['validation']
                : [
                    'is_valid_order' => false,
                    'rejection_reasons' => ['Data kurir belum lengkap.'],
                    'missing_fields' => [],
                    'next_actions' => [],
                ];

            return [
                'intent' => 'courier_order',
                'service_type' => 'kurir',
                'courier' => [
                    'pickup_address' => $parsed['pickup_address'],
                    'dropoff_address' => $parsed['dropoff_address'],
                    'package_description' => $parsed['package_description'],
                    'used_default_pickup' => $parsed['used_default_pickup'],
                ],
                'validation' => $validation,
                'order' => [
                    'created' => false,
                    'id' => null,
                    'order_number' => null,
                ],
                'assistant_text' => $this->buildValidationMessage($parsed),
            ];
        }

        $order = $this->createCourierOrder($user, $parsed, $defaultPickupAddress);

        return [
            'intent' => 'courier_order',
            'service_type' => 'kurir',
            'courier' => [
                'pickup_address' => $parsed['pickup_address'],
                'dropoff_address' => $parsed['dropoff_address'],
                'package_description' => $parsed['package_description'],
                'used_default_pickup' => $parsed['used_default_pickup'],
            ],
            'validation' => [
                'is_valid_order' => true,
                'rejection_reasons' => [],
                'missing_fields' => [],
                'next_actions' => [],
            ],
            'order' => [
                'created' => true,
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->statusRef?->code,
                'total_amount' => (float) $order->total_amount,
                'delivery_fee' => (float) $order->delivery_fee,
                'estimated_delivery' => $order->estimated_delivery,
            ],
            'assistant_text' => $this->buildSuccessMessage($order, $parsed),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCourierPayload(string $message, ?Address $defaultPickupAddress): array
    {
        $normalizedMessage = $this->normalizeWhitespace($message);

        $pickupAddress = null;
        $dropoffAddress = null;

        // Prioritaskan format cepat: "dari [pickup] ke [dropoff], isi paket: ..."
        if (
            preg_match(
                '/\bdari\s+(.+?)\s+ke\s+(.+?)(?=(?:\s*,\s*|\s+(?:isi\s+paket|deskripsi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
                $normalizedMessage,
                $routeMatch
            ) === 1
        ) {
            $pickupCandidate = $this->sanitizeAddressFragment($routeMatch[1] ?? null);
            $dropoffCandidate = $this->sanitizeAddressFragment($routeMatch[2] ?? null);

            if ($pickupCandidate !== null && $this->isLikelyAddressFragment($pickupCandidate)) {
                $pickupAddress = $pickupCandidate;
            }

            if ($dropoffCandidate !== null && $this->isLikelyAddressFragment($dropoffCandidate)) {
                $dropoffAddress = $dropoffCandidate;
            }
        }

        $pickupAddress ??= $this->extractAddressByPatterns($normalizedMessage, [
            '/\b(?:ambil(?:kan)?|pickup|pick\s*up|jemput(?:\s*barang)?)\s*(?:di|dari|lokasi)?\s*[:\-]?\s*(.+?)(?=(?:\s+(?:antar(?:kan)?|kirim(?:kan)?|drop\s*off|tujuan|isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
        ]);

        // Untuk pola eksplisit, izinkan lokasi single-word seperti "sraten".
        $pickupAddress ??= $this->extractPermissiveAddressByPatterns($normalizedMessage, [
            '/\bambil\s+di\s+(.+?)(?=(?:\s*,\s*|\.|\s+(?:kirim|antar|tujuan|isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
            '/\bpickup\s+di\s+(.+?)(?=(?:\s*,\s*|\.|\s+(?:kirim|antar|tujuan|isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
            '/\bjemput\s+di\s+(.+?)(?=(?:\s*,\s*|\.|\s+(?:kirim|antar|tujuan|isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
        ]);

        $dropoffAddress ??= $this->extractAddressByPatterns($normalizedMessage, [
            '/\b(?:tujuan|drop\s*off)\s*(?:ke|di|lokasi)?\s*[:\-]?\s*(.+?)(?=(?:\s*,\s*|\s+(?:isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
            '/\bantar(?:kan)?\s+ke\s+(.+?)(?=(?:\s*,\s*|\s+(?:isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
            '/\bkirim(?:kan)?\s+ke\s+(.+?)(?=(?:\s*,\s*|\s+(?:isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
        ]);

        $dropoffAddress ??= $this->extractPermissiveAddressByPatterns($normalizedMessage, [
            '/\bkirim\s+ke\s+(.+?)(?=(?:\s*,\s*|\.|\s+(?:isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
            '/\bantar\s+ke\s+(.+?)(?=(?:\s*,\s*|\.|\s+(?:isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
            '/\btujuan\s+ke\s+(.+?)(?=(?:\s*,\s*|\.|\s+(?:isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
        ]);

        if ($dropoffAddress === null && preg_match('/\bke\s+(.+)$/iu', $normalizedMessage, $match) === 1) {
            $dropoffCandidate = $this->sanitizeAddressFragment($match[1] ?? null);
            if ($dropoffCandidate !== null && $this->isLikelyAddressFragment($dropoffCandidate)) {
                $dropoffAddress = $dropoffCandidate;
            }
        }

        $packageDescription = $this->extractByPatterns($normalizedMessage, [
            '/\b(?:isi\s+paket|deskripsi\s+paket|paket(?:nya)?|barang(?:nya)?)\s*[:\-]?\s*(.+)$/iu',
            '/\b(?:kirim(?:kan)?|antar(?:kan)?)\s+(.+?)\s+\b(?:dari|ke)\b/iu',
        ]);

        if ($packageDescription === null && preg_match('/\b(dokumen|berkas|paket|barang|makanan|obat|surat)\b/iu', $normalizedMessage, $match) === 1) {
            $packageDescription = $this->normalizeWhitespace($match[1]);
        }

        if (
            $dropoffAddress !== null &&
            $packageDescription !== null &&
            strtolower($dropoffAddress) === strtolower($packageDescription)
        ) {
            $dropoffAddress = null;
        }

        $usedDefaultPickup = false;
        if ($pickupAddress === null && $defaultPickupAddress !== null) {
            $pickupAddress = $this->formatAddress($defaultPickupAddress);
            $usedDefaultPickup = true;
        }

        $coordinates = $this->extractCoordinateHints($normalizedMessage);

        $pickupLatitude = $coordinates['pickup_latitude'] ?? ($defaultPickupAddress ? (float) $defaultPickupAddress->latitude : null);
        $pickupLongitude = $coordinates['pickup_longitude'] ?? ($defaultPickupAddress ? (float) $defaultPickupAddress->longitude : null);

        $dropoffLatitude = $coordinates['dropoff_latitude'] ?? $coordinates['pickup_latitude'] ?? $pickupLatitude;
        $dropoffLongitude = $coordinates['dropoff_longitude'] ?? $coordinates['pickup_longitude'] ?? $pickupLongitude;

        $reasons = [];
        $missingFields = [];
        $nextActions = [];

        if ($pickupAddress === null) {
            $reasons[] = 'Lokasi ambil belum terbaca. Tulis contoh: "ambil di Jalan Melati No 3".';
            $missingFields[] = 'pickup_address';
        }

        if ($dropoffAddress === null) {
            $reasons[] = 'Lokasi tujuan belum terbaca. Tulis contoh: "kirim ke Jalan Sudirman No 10".';
            $missingFields[] = 'dropoff_address';
        }

        if ($packageDescription === null) {
            $reasons[] = 'Isi paket belum jelas. Tulis contoh: "isi paket: dokumen kontrak".';
            $missingFields[] = 'package_description';
        }

        if ($pickupLatitude === null || $pickupLongitude === null) {
            $reasons[] = 'Koordinat lokasi ambil belum tersedia. Atur Alamat Saya sebagai default terlebih dahulu.';
            $missingFields[] = 'pickup_coordinates';

            if ($defaultPickupAddress === null) {
                $nextActions[] = 'OPEN_ADDRESSES';
            }
        }

        if ($dropoffLatitude === null || $dropoffLongitude === null) {
            $reasons[] = 'Koordinat lokasi tujuan belum tersedia. Tambahkan koordinat (lat,lng) di chat jika perlu.';
            $missingFields[] = 'dropoff_coordinates';
        }

        $distanceKm = null;
        if ($pickupLatitude !== null && $pickupLongitude !== null && $dropoffLatitude !== null && $dropoffLongitude !== null) {
            $distanceKm = $this->distanceKm($pickupLatitude, $pickupLongitude, $dropoffLatitude, $dropoffLongitude);

            $maxDistance = (float) config('bangdeliv.max_delivery_distance', 15);
            if ($distanceKm > $maxDistance) {
                $reasons[] = sprintf(
                    'Jarak %.2f km melebihi batas layanan %.2f km.',
                    $distanceKm,
                    $maxDistance
                );
            }
        }

        return [
            'pickup_address' => $pickupAddress,
            'dropoff_address' => $dropoffAddress,
            'package_description' => $packageDescription,
            'pickup_latitude' => $pickupLatitude,
            'pickup_longitude' => $pickupLongitude,
            'dropoff_latitude' => $dropoffLatitude,
            'dropoff_longitude' => $dropoffLongitude,
            'distance_km' => $distanceKm,
            'used_default_pickup' => $usedDefaultPickup,
            'validation' => [
                'is_valid_order' => $reasons === [],
                'rejection_reasons' => $reasons,
                'missing_fields' => array_values(array_unique($missingFields)),
                'next_actions' => array_values(array_unique($nextActions)),
            ],
        ];
    }

    private function resolveDefaultPickupAddress(User $user): ?Address
    {
        return Address::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function createCourierOrder(User $user, array $parsed, ?Address $defaultPickupAddress): Order
    {
        $serviceTypeId = ServiceType::query()->where('code', 'COURIER')->value('id');
        $pendingStatusId = OrderStatus::query()->where('code', 'PENDING')->value('id');

        if (!$serviceTypeId || !$pendingStatusId) {
            throw new ApiException('Konfigurasi service type atau status order belum lengkap.', 500);
        }

        $distanceKm = isset($parsed['distance_km']) ? (float) $parsed['distance_km'] : null;
        $deliveryFee = $this->calculateDeliveryFee($distanceKm);
        $serviceFee = 0.0;
        $totalAmount = $deliveryFee + $serviceFee;

        $pickupAddress = (string) $parsed['pickup_address'];
        $dropoffAddress = (string) $parsed['dropoff_address'];
        $packageDescription = (string) $parsed['package_description'];

        $pickupLatitude = (float) $parsed['pickup_latitude'];
        $pickupLongitude = (float) $parsed['pickup_longitude'];
        $dropoffLatitude = (float) $parsed['dropoff_latitude'];
        $dropoffLongitude = (float) $parsed['dropoff_longitude'];

        $estimatedMinutes = $this->estimateDeliveryMinutes($distanceKm);

        return DB::transaction(function () use (
            $user,
            $defaultPickupAddress,
            $serviceTypeId,
            $pendingStatusId,
            $distanceKm,
            $deliveryFee,
            $serviceFee,
            $totalAmount,
            $pickupAddress,
            $pickupLatitude,
            $pickupLongitude,
            $dropoffAddress,
            $dropoffLatitude,
            $dropoffLongitude,
            $packageDescription,
            $estimatedMinutes
        ): Order {
            $order = Order::query()->create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'restaurant_id' => null,
                'service_type_id' => $serviceTypeId,
                'address_id' => $defaultPickupAddress?->id,
                'delivery_address' => $dropoffAddress,
                'delivery_latitude' => round($dropoffLatitude, 8),
                'delivery_longitude' => round($dropoffLongitude, 8),
                'subtotal' => 0,
                'delivery_fee' => round($deliveryFee, 2),
                'service_fee' => round($serviceFee, 2),
                'delivery_distance_km' => $distanceKm !== null ? round($distanceKm, 2) : null,
                'delivery_distance_text' => $distanceKm !== null ? number_format($distanceKm, 2).' km' : null,
                'total_amount' => round($totalAmount, 2),
                'total_price' => round($totalAmount, 2),
                'status_id' => $pendingStatusId,
                'payment_status' => 'unpaid',
                'payment_method' => 'COD',
                'notes' => "Order kurir dibuat via chatbot.\nPickup: {$pickupAddress}\nDropoff: {$dropoffAddress}\nPaket: {$packageDescription}",
                'estimated_delivery' => Carbon::now()->addMinutes($estimatedMinutes),
            ]);

            CourierOrder::query()->create([
                'order_id' => $order->id,
                'package_description' => $packageDescription,
                'requires_photo_evidence' => true,
                'confirmation_deadline_at' => Carbon::now()->addHours(24),
            ]);

            $order->orderLocations()->createMany([
                [
                    'location_role' => 'PICKUP',
                    'label' => 'Pickup',
                    'contact_name' => $defaultPickupAddress?->recipient_name ?? $user->name,
                    'contact_phone' => $defaultPickupAddress?->phone ?? $user->phone,
                    'full_address' => $pickupAddress,
                    'latitude' => round($pickupLatitude, 8),
                    'longitude' => round($pickupLongitude, 8),
                    'sequence_no' => 1,
                    'notes' => 'Lokasi ambil dari chatbot.',
                ],
                [
                    'location_role' => 'DROPOFF',
                    'label' => 'Dropoff',
                    'contact_name' => null,
                    'contact_phone' => null,
                    'full_address' => $dropoffAddress,
                    'latitude' => round($dropoffLatitude, 8),
                    'longitude' => round($dropoffLongitude, 8),
                    'sequence_no' => 2,
                    'notes' => 'Lokasi tujuan dari chatbot.',
                ],
            ]);

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $pendingStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $user->id,
                'note' => 'Order kurir dibuat oleh customer melalui chatbot.',
            ]);

            return $order->fresh(['statusRef', 'courierOrder']);
        });
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function extractByPatterns(string $message, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $match) !== 1) {
                continue;
            }

            $value = $this->sanitizeAddressFragment($match[1] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function extractAddressByPatterns(string $message, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $match) !== 1) {
                continue;
            }

            $value = $this->sanitizeAddressFragment($match[1] ?? null);
            if ($value === null) {
                continue;
            }

            if (!$this->isLikelyAddressFragment($value)) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function extractPermissiveAddressByPatterns(string $message, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $match) !== 1) {
                continue;
            }

            $value = $this->sanitizeAddressFragment($match[1] ?? null);
            if ($value === null) {
                continue;
            }

            $normalized = strtolower($this->normalizeWhitespace($value));
            if (in_array($normalized, $this->packageOnlyKeywords, true)) {
                continue;
            }

            if (strlen($normalized) < 3) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function sanitizeAddressFragment(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $cleaned = trim($value);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim((string) $cleaned, " \t\n\r\0\x0B,.;:-");

        if ($cleaned === '' || strlen($cleaned) < 4) {
            return null;
        }

        return $cleaned;
    }

    private function isLikelyAddressFragment(string $value): bool
    {
        $normalized = strtolower($this->normalizeWhitespace($value));

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, $this->packageOnlyKeywords, true)) {
            return false;
        }

        foreach ($this->locationHints as $hint) {
            if (preg_match('/\\b'.preg_quote($hint, '/').'\\b/u', $normalized) === 1) {
                return true;
            }
        }

        if (preg_match('/\d/', $normalized) === 1) {
            return true;
        }

        if (str_contains($normalized, ',')) {
            return true;
        }

        $parts = preg_split('/\s+/', $normalized);
        if (!is_array($parts)) {
            return false;
        }

        return count($parts) >= 2 && strlen($normalized) >= 8;
    }

    /**
     * @return array{pickup_latitude: float|null, pickup_longitude: float|null, dropoff_latitude: float|null, dropoff_longitude: float|null}
     */
    private function extractCoordinateHints(string $message): array
    {
        preg_match_all('/(-?\d{1,2}\.\d{4,})\s*,\s*(-?\d{1,3}\.\d{4,})/', $message, $matches, PREG_SET_ORDER);

        $points = [];
        foreach ($matches as $match) {
            $lat = (float) $match[1];
            $lng = (float) $match[2];

            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                continue;
            }

            $points[] = ['lat' => $lat, 'lng' => $lng];
        }

        return [
            'pickup_latitude' => $points[0]['lat'] ?? null,
            'pickup_longitude' => $points[0]['lng'] ?? null,
            'dropoff_latitude' => $points[1]['lat'] ?? null,
            'dropoff_longitude' => $points[1]['lng'] ?? null,
        ];
    }

    private function distanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadiusKm = 6371;
        $latFrom = deg2rad($fromLat);
        $lonFrom = deg2rad($fromLng);
        $latTo = deg2rad($toLat);
        $lonTo = deg2rad($toLng);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
                cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return round($earthRadiusKm * $angle, 2);
    }

    private function calculateDeliveryFee(?float $distanceKm): float
    {
        if ($distanceKm === null) {
            return (float) config('bangdeliv.min_delivery_fee', 5000);
        }

        $rate = (float) config('bangdeliv.delivery_rate_per_km', 3000);
        $minFee = (float) config('bangdeliv.min_delivery_fee', 5000);
        $maxFee = (float) config('bangdeliv.max_delivery_fee', 25000);

        $calculated = $distanceKm * $rate;

        return max($minFee, min($maxFee, $calculated));
    }

    private function estimateDeliveryMinutes(?float $distanceKm): int
    {
        if ($distanceKm === null) {
            return 30;
        }

        $estimated = (int) round(($distanceKm * 7) + 20);

        return max(20, min(180, $estimated));
    }

    private function formatAddress(Address $address): string
    {
        return trim($address->full_address.' '.($address->detail ?? ''));
    }

    private function generateOrderNumber(): string
    {
        do {
            $candidate = 'BD-'.now()->format('ymd').'-'.random_int(1000, 9999);
        } while (Order::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function buildValidationMessage(array $parsed): string
    {
        $reasons = $parsed['validation']['rejection_reasons'] ?? [];

        if (!is_array($reasons) || $reasons === []) {
            return 'Data kurir belum lengkap. Mohon isi lokasi ambil, tujuan kirim, dan isi paket.';
        }

        $buffer = "Order kurir belum bisa dibuat karena:\n";
        foreach ($reasons as $reason) {
            $buffer .= '- '.trim((string) $reason)."\n";
        }

        $buffer .= "\nFormat cepat: Kirim dokumen dari [lokasi ambil] ke [tujuan], isi paket: [deskripsi].";

        return trim($buffer);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function buildSuccessMessage(Order $order, array $parsed): string
    {
        $deliveryFee = number_format((float) $order->delivery_fee, 0, ',', '.');

        $buffer = "Siap, order kurir berhasil dibuat.\n";
        $buffer .= "Nomor order: {$order->order_number}\n";
        $buffer .= 'Pickup: '.(string) $parsed['pickup_address']."\n";
        $buffer .= 'Tujuan: '.(string) $parsed['dropoff_address']."\n";
        $buffer .= 'Isi paket: '.(string) $parsed['package_description']."\n";
        $buffer .= "Ongkir: Rp {$deliveryFee}.";

        return $buffer;
    }
}
