<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AiChatLog;
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
     * @var array<int, string>
     */
    private array $pickupProfileAliases = [
        'rumah',
        'di rumah',
        'ambil di rumah',
        'jemput di rumah',
        'pickup di rumah',
        'kantor',
        'di kantor',
        'ambil di kantor',
        'jemput di kantor',
        'pickup di kantor',
    ];

    /**
     * @var array<int, string>
     */
    private array $confirmCommands = [
        'konfirmasi',
        'confirm',
        'lanjut',
    ];

    /**
     * @var array<int, string>
     */
    private array $resetCommands = [
        'ubah tujuan',
        'ganti tujuan',
        'reset tujuan',
    ];

    public function __construct(
        private readonly GoogleMapsGeocodingService $geocodingService,
        private readonly GoogleMapsDistanceMatrixService $distanceMatrixService,
        private readonly DeliveryPricingService $deliveryPricingService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function process(User $user, string $message, string $sessionId, ?array $nluPayload = null): array
    {
        if ($user->role !== 'customer') {
            throw new ApiException('Hanya customer yang dapat membuat order kurir dari chatbot.', 403);
        }

        if (!$user->is_active || $user->is_blacklisted) {
            throw new ApiException('Akun tidak memenuhi syarat untuk membuat order kurir.', 403);
        }

        $normalizedMessage = $this->normalizeWhitespace($message);
        $command = $this->resolveCommand($normalizedMessage, $nluPayload);

        if ($command === 'reset_destination') {
            return $this->handleResetDestination($user);
        }

        if ($command === 'confirm') {
            return $this->confirmPendingDraft($user, $sessionId);
        }

        $defaultPickupAddress = $this->resolveDefaultPickupAddress($user);
        $draftSeed = $this->buildDraftSeedFromNlu($nluPayload);
        $draft = $this->buildCourierDraft($normalizedMessage, $defaultPickupAddress, $draftSeed);

        if (($draft['validation']['is_valid_order'] ?? false) !== true) {
            return $this->buildValidationPayload($draft);
        }

        return $this->buildDraftPayload($draft, $user->name);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResetDestination(User $user): array
    {
        $defaultPickupAddress = $this->resolveDefaultPickupAddress($user);
        $pickupText = null;

        if ($defaultPickupAddress !== null) {
            $resolved = $this->resolveProfilePickupAddress($defaultPickupAddress);
            if ($resolved !== null) {
                $pickupText = $resolved['formatted_address'];
            }
        }

        $name = trim((string) $user->name) === '' ? 'Kak' : trim((string) $user->name);

        if ($pickupText === null) {
            $text =
                'Baik '.$name.', tujuan sebelumnya saya reset. Alamat jemput dari profil belum tersedia. Isi Alamat Saya dulu, lalu kirim tujuan baru.';
        } else {
            $text =
                'Baik '.$name.', tujuan sebelumnya saya reset. Alamat jemput kamu di '.$pickupText.'. Sekarang kirim tujuan baru, misalnya: "Kirim ke Jalan XXX".';
        }

        return [
            'intent' => 'courier_order',
            'service_type' => 'kurir',
            'courier' => [
                'pickup_address' => $pickupText,
                'dropoff_address' => null,
                'package_description' => null,
                'ready_to_confirm' => false,
                'used_default_pickup' => $pickupText !== null,
            ],
            'validation' => [
                'is_valid_order' => false,
                'rejection_reasons' => [],
                'missing_fields' => ['dropoff_address', 'package_description'],
                'next_actions' => $pickupText === null ? ['OPEN_ADDRESSES'] : [],
            ],
            'order' => [
                'created' => false,
                'id' => null,
                'order_number' => null,
                'delivery_fee' => null,
            ],
            'assistant_text' => $text,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmPendingDraft(User $user, string $sessionId): array
    {
        $pendingDraft = $this->resolvePendingDraft($user, $sessionId);

        if ($pendingDraft === null) {
            return [
                'intent' => 'courier_order',
                'service_type' => 'kurir',
                'courier' => [
                    'pickup_address' => null,
                    'dropoff_address' => null,
                    'package_description' => null,
                    'ready_to_confirm' => false,
                    'used_default_pickup' => false,
                ],
                'validation' => [
                    'is_valid_order' => false,
                    'rejection_reasons' => [
                        'Belum ada draft kurir yang siap dikonfirmasi. Kirim detail pickup, tujuan, dan isi paket terlebih dahulu.',
                    ],
                    'missing_fields' => [],
                    'next_actions' => [],
                ],
                'order' => [
                    'created' => false,
                    'id' => null,
                    'order_number' => null,
                    'delivery_fee' => null,
                ],
                'assistant_text' =>
                    'Belum ada draft pengiriman yang siap dikonfirmasi. Kirim dulu detail pickup, tujuan, dan isi paket, lalu ketik "Konfirmasi".',
            ];
        }

        $profilePickupAddress = null;
        $pickupAddressId = $pendingDraft['pickup_address_id'] ?? null;
        if ($pickupAddressId !== null) {
            $profilePickupAddress = Address::query()
                ->where('user_id', $user->id)
                ->whereKey((int) $pickupAddressId)
                ->first();
        }

        $order = $this->createCourierOrder($user, $pendingDraft, $profilePickupAddress);

        return [
            'intent' => 'courier_order',
            'service_type' => 'kurir',
            'courier' => [
                'pickup_address' => $pendingDraft['pickup_address'],
                'dropoff_address' => $pendingDraft['dropoff_address'],
                'package_description' => $pendingDraft['package_description'],
                'used_default_pickup' => (bool) $pendingDraft['used_default_pickup'],
                'ready_to_confirm' => false,
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
            'assistant_text' => $this->buildSuccessMessage($order, $pendingDraft),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function buildValidationPayload(array $draft): array
    {
        $validation = is_array($draft['validation'] ?? null)
            ? $draft['validation']
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
                'pickup_address' => $draft['pickup_address'] ?? null,
                'dropoff_address' => $draft['dropoff_address'] ?? null,
                'package_description' => $draft['package_description'] ?? null,
                'used_default_pickup' => (bool) ($draft['used_default_pickup'] ?? false),
                'ready_to_confirm' => false,
                'pickup_latitude' => $draft['pickup_latitude'] ?? null,
                'pickup_longitude' => $draft['pickup_longitude'] ?? null,
                'dropoff_latitude' => $draft['dropoff_latitude'] ?? null,
                'dropoff_longitude' => $draft['dropoff_longitude'] ?? null,
            ],
            'validation' => $validation,
            'order' => [
                'created' => false,
                'id' => null,
                'order_number' => null,
                'delivery_fee' => $draft['delivery_fee'] ?? null,
            ],
            'assistant_text' => $this->buildValidationMessage($draft),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function buildDraftPayload(array $draft, string $userName): array
    {
        return [
            'intent' => 'courier_order',
            'service_type' => 'kurir',
            'courier' => [
                'pickup_address' => $draft['pickup_address'],
                'dropoff_address' => $draft['dropoff_address'],
                'package_description' => $draft['package_description'],
                'used_default_pickup' => (bool) $draft['used_default_pickup'],
                'pickup_address_id' => $draft['pickup_address_id'],
                'ready_to_confirm' => true,
                'pickup_latitude' => $draft['pickup_latitude'],
                'pickup_longitude' => $draft['pickup_longitude'],
                'dropoff_latitude' => $draft['dropoff_latitude'],
                'dropoff_longitude' => $draft['dropoff_longitude'],
                'distance_km' => $draft['distance_km'],
            ],
            'validation' => [
                'is_valid_order' => true,
                'rejection_reasons' => [],
                'missing_fields' => [],
                'next_actions' => [],
            ],
            'order' => [
                'created' => false,
                'id' => null,
                'order_number' => null,
                'delivery_fee' => $draft['delivery_fee'],
            ],
            'assistant_text' => $this->buildDraftMessage($draft, $userName),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCourierDraft(string $message, ?Address $defaultPickupAddress, ?array $draftSeed = null): array
    {
        $extracted = $draftSeed ?? $this->extractCourierPayload($message);

        $pickupRawAddress = $extracted['pickup_address'] ?? null;
        $dropoffRawAddress = $extracted['dropoff_address'] ?? null;
        $packageDescription = $extracted['package_description'] ?? null;

        $pickupAddress = null;
        $pickupLatitude = null;
        $pickupLongitude = null;
        $pickupAddressId = null;
        $usedDefaultPickup = false;

        $dropoffAddress = null;
        $dropoffLatitude = null;
        $dropoffLongitude = null;

        $reasons = [];
        $missingFields = [];
        $nextActions = [];

        $shouldUseProfilePickup =
            $pickupRawAddress === null ||
            $this->isProfilePickupAlias($pickupRawAddress);

        if ($shouldUseProfilePickup) {
            if ($defaultPickupAddress === null) {
                $reasons[] = 'Lokasi ambil di profil belum tersedia. Isi Alamat Saya terlebih dahulu.';
                $missingFields[] = 'pickup_address';
                $nextActions[] = 'OPEN_ADDRESSES';
            } else {
                $resolvedPickup = $this->resolveProfilePickupAddress($defaultPickupAddress);
                if ($resolvedPickup === null) {
                    $reasons[] = 'Lokasi ambil dari profil tidak valid di peta. Perbarui Alamat Saya terlebih dahulu.';
                    $missingFields[] = 'pickup_address';
                    $nextActions[] = 'OPEN_ADDRESSES';
                } else {
                    $pickupAddress = $resolvedPickup['formatted_address'];
                    $pickupLatitude = $resolvedPickup['latitude'];
                    $pickupLongitude = $resolvedPickup['longitude'];
                    $pickupAddressId = $defaultPickupAddress->id;
                    $usedDefaultPickup = true;
                }
            }
        } else {
            $resolvedPickup = $this->resolveAddressViaGeocoding((string) $pickupRawAddress);
            if ($resolvedPickup === null) {
                $reasons[] = 'Lokasi ambil tidak ditemukan di peta. Gunakan alamat yang lebih spesifik.';
                $missingFields[] = 'pickup_address';
            } else {
                $pickupAddress = $resolvedPickup['formatted_address'];
                $pickupLatitude = $resolvedPickup['latitude'];
                $pickupLongitude = $resolvedPickup['longitude'];
            }
        }

        if ($dropoffRawAddress === null) {
            $reasons[] = 'Lokasi tujuan belum terbaca. Tulis contoh: "kirim ke Jalan Sudirman No 10".';
            $missingFields[] = 'dropoff_address';
        } else {
            $resolvedDropoff = $this->resolveAddressViaGeocoding((string) $dropoffRawAddress);
            if ($resolvedDropoff === null) {
                $reasons[] = 'Lokasi tujuan tidak ditemukan di peta. Gunakan alamat yang lebih spesifik.';
                $missingFields[] = 'dropoff_address';
            } else {
                $dropoffAddress = $resolvedDropoff['formatted_address'];
                $dropoffLatitude = $resolvedDropoff['latitude'];
                $dropoffLongitude = $resolvedDropoff['longitude'];
            }
        }

        if ($packageDescription === null) {
            $reasons[] = 'Isi paket belum jelas. Tulis contoh: "isi paket: dokumen kontrak".';
            $missingFields[] = 'package_description';
        }

        $distanceKm = null;
        $distanceMeters = 0.0;
        if (
            $pickupLatitude !== null &&
            $pickupLongitude !== null &&
            $dropoffLatitude !== null &&
            $dropoffLongitude !== null
        ) {
            try {
                $route = $this->distanceMatrixService->resolveRoute(
                    $pickupLatitude,
                    $pickupLongitude,
                    $dropoffLatitude,
                    $dropoffLongitude,
                );

                $distanceMeters = (float) $route['distance_meters'];
                $distanceKm = (float) $route['distance_km'];

                if (!$this->deliveryPricingService->isWithinMaxDistance($distanceMeters)) {
                    $reasons[] = sprintf(
                        'Jarak %.2f km melebihi batas layanan %.2f km.',
                        $distanceKm,
                        $this->deliveryPricingService->getMaxDistanceKm()
                    );
                }
            } catch (ApiException $exception) {
                if ($exception->status() === 422) {
                    $reasons[] = 'Rute pickup ke tujuan tidak ditemukan. Gunakan alamat yang lebih spesifik.';
                    $missingFields[] = 'dropoff_address';
                } else {
                    throw $exception;
                }
            }
        }

        $deliveryFee = (float) $this->deliveryPricingService
            ->calculateFromDistanceMeters($distanceMeters)['total_fee'];

        return [
            'pickup_address' => $pickupAddress,
            'dropoff_address' => $dropoffAddress,
            'package_description' => $packageDescription,
            'pickup_latitude' => $pickupLatitude,
            'pickup_longitude' => $pickupLongitude,
            'dropoff_latitude' => $dropoffLatitude,
            'dropoff_longitude' => $dropoffLongitude,
            'pickup_address_id' => $pickupAddressId,
            'distance_km' => $distanceKm,
            'delivery_fee' => $deliveryFee,
            'used_default_pickup' => $usedDefaultPickup,
            'validation' => [
                'is_valid_order' => $reasons === [] &&
                    $pickupAddress !== null &&
                    $dropoffAddress !== null &&
                    $packageDescription !== null,
                'rejection_reasons' => $reasons,
                'missing_fields' => array_values(array_unique($missingFields)),
                'next_actions' => array_values(array_unique($nextActions)),
            ],
        ];
    }

    /**
     * @return array{pickup_address: string|null, dropoff_address: string|null, package_description: string|null}
     */
    private function extractCourierPayload(string $message): array
    {
        $pickupAddress = null;
        $dropoffAddress = null;

        if (
            preg_match(
                '/\bdari\s+(.+?)\s+ke\s+(.+?)(?=(?:\s*,\s*|\.|\s+(?:isi\s+paket|deskripsi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
                $message,
                $routeMatch
            ) === 1
        ) {
            $pickupAddress = $this->sanitizeAddressFragment($routeMatch[1] ?? null);
            $dropoffAddress = $this->sanitizeAddressFragment($routeMatch[2] ?? null);
        }

        $pickupAddress ??= $this->extractPermissiveAddressByPatterns($message, [
            '/\blokasi\s+ambil\s+(.+?)(?=(?:\s*,\s*|\.|\s+(?:tujuan|kirim|antar|drop\s*off|isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
            '/\b(?:ambil(?:kan)?|pickup|pick\s*up|jemput(?:\s*barang)?)\s*(?:di|dari|lokasi)?\s*[:\-]?\s*(.+?)(?=(?:\s*,\s*|\.|\s+(?:tujuan|kirim|antar|drop\s*off|isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
        ]);

        $dropoffAddress ??= $this->extractPermissiveAddressByPatterns($message, [
            '/\btujuan\s+(?:kirim|antar(?:kan)?)?\s*(?:ke|di)?\s*[:\-]?\s*(.+?)(?=(?:\s*,\s*|\.|\s+(?:isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
            '/\b(?:kirim(?:kan)?|antar(?:kan)?|drop\s*off)\s+ke\s+(.+?)(?=(?:\s*,\s*|\.|\s+(?:isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
        ]);

        $dropoffAddress ??= $this->extractPermissiveAddressByPatterns($message, [
            '/\bke\s+(.+?)(?=(?:\s*,\s*|\.|\s+(?:isi\s+paket|paket(?:nya)?|barang(?:nya)?|catatan)\b|$))/iu',
        ]);

        $packageDescription = $this->extractByPatterns($message, [
            '/\b(?:isi\s+paket|deskripsi\s+paket|paket(?:nya)?|barang(?:nya)?)\s*[:\-]?\s*(.+)$/iu',
            '/\b(?:kirim(?:kan)?|antar(?:kan)?)\s+(.+?)\s+\b(?:dari|ke)\b/iu',
        ]);

        if (
            $packageDescription === null &&
            preg_match('/\b(dokumen|berkas|paket|barang|makanan|obat|surat)\b/iu', $message, $packageMatch) === 1
        ) {
            $packageDescription = $this->normalizeWhitespace((string) $packageMatch[1]);
        }

        if (
            $dropoffAddress !== null &&
            $packageDescription !== null &&
            strtolower($dropoffAddress) === strtolower($packageDescription)
        ) {
            $dropoffAddress = null;
        }

        return [
            'pickup_address' => $pickupAddress,
            'dropoff_address' => $dropoffAddress,
            'package_description' => $packageDescription,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAddressViaGeocoding(string $rawAddress): ?array
    {
        $normalized = $this->normalizeWhitespace($rawAddress);
        if ($normalized === '') {
            return null;
        }

        $resolved = $this->geocodingService->resolveAddress($normalized);
        if ($resolved === null) {
            return null;
        }

        return [
            'formatted_address' => trim((string) $resolved['formatted_address']),
            'latitude' => (float) $resolved['latitude'],
            'longitude' => (float) $resolved['longitude'],
        ];
    }

    private function resolveProfilePickupAddress(Address $address): ?array
    {
        $resolved = $this->resolveAddressViaGeocoding((string) $address->full_address);
        if ($resolved === null) {
            return null;
        }

        return $resolved;
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
    private function createCourierOrder(User $user, array $parsed, ?Address $profilePickupAddress): Order
    {
        $serviceTypeId = ServiceType::query()->where('code', 'COURIER')->value('id');
        $pendingStatusId = OrderStatus::query()->where('code', 'PENDING')->value('id');

        if (!$serviceTypeId || !$pendingStatusId) {
            throw new ApiException('Konfigurasi service type atau status order belum lengkap.', 500);
        }

        $pickupAddress = trim((string) ($parsed['pickup_address'] ?? ''));
        $dropoffAddress = trim((string) ($parsed['dropoff_address'] ?? ''));
        $packageDescription = trim((string) ($parsed['package_description'] ?? ''));

        if ($pickupAddress === '' || $dropoffAddress === '' || $packageDescription === '') {
            throw new ApiException('Draft kurir tidak valid untuk dikonfirmasi. Kirim ulang detail pengiriman.', 422);
        }

        $pickupLatitude = (float) $parsed['pickup_latitude'];
        $pickupLongitude = (float) $parsed['pickup_longitude'];
        $dropoffLatitude = (float) $parsed['dropoff_latitude'];
        $dropoffLongitude = (float) $parsed['dropoff_longitude'];

        $route = $this->distanceMatrixService->resolveRoute(
            $pickupLatitude,
            $pickupLongitude,
            $dropoffLatitude,
            $dropoffLongitude,
        );

        $distanceMeters = (float) $route['distance_meters'];
        $distanceKm = (float) $route['distance_km'];

        if (!$this->deliveryPricingService->isWithinMaxDistance($distanceMeters)) {
            throw new ApiException(sprintf(
                'Jarak %.2f km melebihi batas layanan %.2f km.',
                $distanceKm,
                $this->deliveryPricingService->getMaxDistanceKm()
            ), 422);
        }

        $pricing = $this->deliveryPricingService->calculateFromDistanceMeters($distanceMeters);
        $deliveryFee = (float) $pricing['total_fee'];
        $serviceFee = 0.0;
        $totalAmount = $deliveryFee + $serviceFee;

        $estimatedMinutes = $this->estimateDeliveryMinutes((int) $route['duration_seconds']);

        return DB::transaction(function () use (
            $user,
            $profilePickupAddress,
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
            $route,
            $packageDescription,
            $estimatedMinutes
        ): Order {
            $order = Order::query()->create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'restaurant_id' => null,
                'service_type_id' => $serviceTypeId,
                'address_id' => $profilePickupAddress?->id,
                'delivery_address' => $dropoffAddress,
                'delivery_latitude' => round($dropoffLatitude, 8),
                'delivery_longitude' => round($dropoffLongitude, 8),
                'subtotal' => 0,
                'delivery_fee' => round($deliveryFee, 2),
                'service_fee' => round($serviceFee, 2),
                'delivery_distance_km' => $distanceKm !== null ? round($distanceKm, 2) : null,
                'delivery_distance_text' => $distanceKm !== null
                    ? (string) ($route['distance_text'] ?? number_format($distanceKm, 2).' km')
                    : null,
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
                    'contact_name' => $profilePickupAddress?->recipient_name ?? $user->name,
                    'contact_phone' => $profilePickupAddress?->phone ?? $user->phone,
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

        if ($cleaned === '' || strlen($cleaned) < 3) {
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

    private function isProfilePickupAlias(string $value): bool
    {
        $normalized = strtolower($this->normalizeWhitespace($value));

        return in_array($normalized, $this->pickupProfileAliases, true);
    }

    private function isConfirmCommand(string $message): bool
    {
        $normalized = strtolower($this->normalizeWhitespace($message));

        return in_array($normalized, $this->confirmCommands, true);
    }

    private function isResetDestinationCommand(string $message): bool
    {
        $normalized = strtolower($this->normalizeWhitespace($message));

        return in_array($normalized, $this->resetCommands, true);
    }

    private function resolveCommand(string $normalizedMessage, ?array $nluPayload): ?string
    {
        $nluCommand = strtolower(trim((string) ($nluPayload['command'] ?? '')));

        if ($nluCommand === 'confirm') {
            return 'confirm';
        }

        if ($nluCommand === 'reset_destination') {
            return 'reset_destination';
        }

        if ($this->isResetDestinationCommand($normalizedMessage)) {
            return 'reset_destination';
        }

        if ($this->isConfirmCommand($normalizedMessage)) {
            return 'confirm';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $nluPayload
     * @return array{pickup_address: string|null, dropoff_address: string|null, package_description: string|null}|null
     */
    private function buildDraftSeedFromNlu(?array $nluPayload): ?array
    {
        if (!is_array($nluPayload)) {
            return null;
        }

        $pickupAddress = $this->sanitizeAddressFragment(isset($nluPayload['pickup_address']) ? (string) $nluPayload['pickup_address'] : null);
        $dropoffAddress = $this->sanitizeAddressFragment(isset($nluPayload['dropoff_address']) ? (string) $nluPayload['dropoff_address'] : null);
        $packageDescription = $this->sanitizeAddressFragment(isset($nluPayload['package_description']) ? (string) $nluPayload['package_description'] : null);

        if ($pickupAddress === null && $dropoffAddress === null && $packageDescription === null) {
            return null;
        }

        return [
            'pickup_address' => $pickupAddress,
            'dropoff_address' => $dropoffAddress,
            'package_description' => $packageDescription,
        ];
    }

    private function estimateDeliveryMinutes(int $durationSeconds): int
    {
        $estimated = (int) ceil(max(0, $durationSeconds) / 60);

        return max(20, min(180, $estimated + 10));
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
    private function buildDraftMessage(array $parsed, string $userName): string
    {
        $name = trim($userName) === '' ? 'Kak' : trim($userName);
        $deliveryFee = number_format((float) ($parsed['delivery_fee'] ?? 0), 0, ',', '.');

        $buffer = "Baik {$name}, saya sudah siapkan draft pengiriman Kurir.\n";
        $buffer .= 'Pickup: '.(string) $parsed['pickup_address']."\n";
        $buffer .= 'Tujuan: '.(string) $parsed['dropoff_address']."\n";
        $buffer .= 'Isi paket: '.(string) $parsed['package_description']."\n";
        $buffer .= "Estimasi ongkir sementara: Rp {$deliveryFee} (kalkulasi detail menyusul).\n";
        $buffer .= 'Ketik "Konfirmasi" untuk lanjut atau "Ubah Tujuan" untuk ganti tujuan.';

        return $buffer;
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

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePendingDraft(User $user, string $sessionId): ?array
    {
        $latestAssistantLog = AiChatLog::query()
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        if ($latestAssistantLog === null) {
            return null;
        }

        $payload = $latestAssistantLog->ai_response;
        if (!is_array($payload)) {
            return null;
        }

        if (($payload['intent'] ?? null) !== 'courier_order') {
            return null;
        }

        $order = $payload['order'] ?? null;
        if (!is_array($order) || ($order['created'] ?? false) === true) {
            return null;
        }

        $validation = $payload['validation'] ?? null;
        $courier = $payload['courier'] ?? null;
        if (!is_array($validation) || !is_array($courier)) {
            return null;
        }

        if (($validation['is_valid_order'] ?? false) !== true) {
            return null;
        }

        if (($courier['ready_to_confirm'] ?? false) !== true) {
            return null;
        }

        $pickupAddress = trim((string) ($courier['pickup_address'] ?? ''));
        $dropoffAddress = trim((string) ($courier['dropoff_address'] ?? ''));
        $packageDescription = trim((string) ($courier['package_description'] ?? ''));

        if ($pickupAddress === '' || $dropoffAddress === '' || $packageDescription === '') {
            return null;
        }

        $pickupLatitude = isset($courier['pickup_latitude']) ? (float) $courier['pickup_latitude'] : null;
        $pickupLongitude = isset($courier['pickup_longitude']) ? (float) $courier['pickup_longitude'] : null;
        $dropoffLatitude = isset($courier['dropoff_latitude']) ? (float) $courier['dropoff_latitude'] : null;
        $dropoffLongitude = isset($courier['dropoff_longitude']) ? (float) $courier['dropoff_longitude'] : null;

        if (
            $pickupLatitude === null ||
            $pickupLongitude === null ||
            $dropoffLatitude === null ||
            $dropoffLongitude === null
        ) {
            return null;
        }

        return [
            'pickup_address' => $pickupAddress,
            'dropoff_address' => $dropoffAddress,
            'package_description' => $packageDescription,
            'pickup_latitude' => $pickupLatitude,
            'pickup_longitude' => $pickupLongitude,
            'dropoff_latitude' => $dropoffLatitude,
            'dropoff_longitude' => $dropoffLongitude,
            'distance_km' => isset($courier['distance_km']) ? (float) $courier['distance_km'] : null,
            'delivery_fee' => isset($order['delivery_fee']) ? (float) $order['delivery_fee'] : null,
            'used_default_pickup' => (bool) ($courier['used_default_pickup'] ?? false),
            'pickup_address_id' => isset($courier['pickup_address_id']) ? (int) $courier['pickup_address_id'] : null,
        ];
    }
}
