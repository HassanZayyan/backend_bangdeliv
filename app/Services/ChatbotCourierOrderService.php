<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\AiChatLog;
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
        'kacamata',
        'kunci',
        'charger',
        'earphone',
        'baju',
        'buku',
        'makanan',
        'obat',
        'sabun',
        'surat',
    ];

    /**
     * @var array<int, string>
     */
    private array $pickupProfileAliases = [
        'rumah',
        'rumahku',
        'rumah saya',
        'di rumahku',
        'di rumah saya',
        'ambil di rumahku',
        'ambil di rumah saya',
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
        private readonly DeliveryPricingService $deliveryPricingService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly CourierPackagePolicyService $packagePolicyService,
        private readonly DriverOrderRealtimeService $driverOrderRealtimeService,
        private readonly ChatbotAddressReadinessService $addressReadinessService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function process(User $user, string $message, string $sessionId, ?array $nluPayload = null): array
    {
        if ($user->role !== 'customer') {
            throw new ApiException('Hanya customer yang dapat membuat order kurir dari chatbot.', 403);
        }

        if (! $user->is_active || $user->is_blacklisted) {
            throw new ApiException('Akun tidak memenuhi syarat untuk membuat order kurir.', 403);
        }

        $normalizedMessage = $this->normalizeWhitespace($message);
        $latestDraftSeed = $this->resolveLatestDraftSeed($user, $sessionId);
        $command = $this->resolveCommand($normalizedMessage, $nluPayload);

        if ($command === 'reset_destination') {
            return $this->handleResetDestination($user, $sessionId);
        }

        if ($command === 'confirm') {
            return $this->confirmPendingDraft($user, $sessionId);
        }

        $defaultPickupAddress = $this->resolveDefaultPickupAddress($user);
        $incomingSeed = $this->mergeCourierDraftSeed(
            $this->buildDraftSeedFromNlu($nluPayload) ?? [],
            $this->extractCourierPayload($normalizedMessage)
        );

        if (
            $this->isAwaitingPackageOnly($latestDraftSeed) &&
            $this->normalizeOptionalString($incomingSeed['package_description'] ?? null) === null
        ) {
            $packageCompletion = $this->extractPackageCompletion($normalizedMessage);
            if ($packageCompletion !== null) {
                $incomingSeed = $this->mergeCourierDraftSeed($incomingSeed, [
                    'package_description' => $packageCompletion,
                ]);
            }
        }

        $draftSeed = $this->mergeCourierDraftSeed(
            $latestDraftSeed,
            $incomingSeed
        );
        $draft = $this->buildCourierDraft($normalizedMessage, $defaultPickupAddress, $draftSeed);

        if (($draft['validation']['is_valid_order'] ?? false) !== true) {
            return $this->buildValidationPayload($draft);
        }

        return $this->buildDraftPayload($draft, $user->name);
    }

    /**
     * @return array<string, mixed>
     */
    public function applyLocationPatch(
        User $user,
        string $sessionId,
        string $target,
        float $latitude,
        float $longitude,
        string $address
    ): array {
        return $this->applyLocationPatches($user, $sessionId, [[
            'target' => $target,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $address,
        ]]);
    }

    /**
     * @param  array<int, array{target: string, latitude: float, longitude: float, address: string}>  $locations
     * @return array<string, mixed>
     */
    public function applyLocationPatches(User $user, string $sessionId, array $locations): array
    {
        if ($user->role !== 'customer') {
            throw new ApiException('Hanya customer yang dapat membuat order kurir dari chatbot.', 403);
        }

        if (! $user->is_active || $user->is_blacklisted) {
            throw new ApiException('Akun tidak memenuhi syarat untuk membuat order kurir.', 403);
        }

        $incomingSeed = [];
        foreach ($locations as $location) {
            $target = (string) ($location['target'] ?? '');
            $latitude = (float) ($location['latitude'] ?? 0);
            $longitude = (float) ($location['longitude'] ?? 0);
            $address = trim((string) ($location['address'] ?? ''));

            if ($target === 'pickup') {
                $incomingSeed = $this->mergeCourierDraftSeed($incomingSeed, [
                    'pickup_address' => $address,
                    'pickup_latitude' => $latitude,
                    'pickup_longitude' => $longitude,
                    'pickup_address_id' => null,
                    'used_default_pickup' => false,
                ]);

                continue;
            }

            if ($target === 'dropoff') {
                $incomingSeed = $this->mergeCourierDraftSeed($incomingSeed, [
                    'dropoff_address' => $address,
                    'dropoff_latitude' => $latitude,
                    'dropoff_longitude' => $longitude,
                ]);

                continue;
            }

            throw new ApiException('Target lokasi kurir tidak valid.', 422);
        }

        $draftSeed = $this->mergeCourierDraftSeed(
            $this->resolveLatestDraftSeed($user, $sessionId),
            $incomingSeed
        );

        $draft = $this->buildCourierDraft('', $this->resolveDefaultPickupAddress($user), $draftSeed);

        if (($draft['validation']['is_valid_order'] ?? false) === true) {
            return $this->buildDraftPayload($draft, $user->name);
        }

        return $this->buildValidationPayload($draft);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResetDestination(User $user, string $sessionId): array
    {
        $current = $this->resolveLatestDraftSeed($user, $sessionId);
        $defaultPickupAddress = $this->resolveDefaultPickupAddress($user);

        if (trim((string) ($current['pickup_address'] ?? '')) === '' && $defaultPickupAddress !== null) {
            $resolved = $this->resolveProfilePickupAddress($defaultPickupAddress);
            if ($resolved !== null) {
                $current['pickup_address'] = $resolved['formatted_address'];
                $current['pickup_latitude'] = $resolved['latitude'];
                $current['pickup_longitude'] = $resolved['longitude'];
                $current['pickup_address_id'] = $defaultPickupAddress->id;
                $current['used_default_pickup'] = true;
            }
        }

        $pickupText = $this->normalizeOptionalString($current['pickup_address'] ?? null);
        $pickupLat = $this->nullableCoordinate($current['pickup_latitude'] ?? null);
        $pickupLng = $this->nullableCoordinate($current['pickup_longitude'] ?? null);
        $pickupAddressId = isset($current['pickup_address_id']) && is_numeric($current['pickup_address_id'])
            ? (int) $current['pickup_address_id']
            : null;
        $packageDescription = $this->normalizeOptionalString($current['package_description'] ?? null);

        $missingFields = [];
        if ($pickupText === null || $pickupLat === null || $pickupLng === null) {
            $missingFields[] = 'pickup_address';
        }
        $missingFields[] = 'dropoff_address';
        if ($packageDescription === null) {
            $missingFields[] = 'package_description';
        }

        $name = trim((string) $user->name) === '' ? 'Kak' : trim((string) $user->name);

        if ($pickupText === null) {
            $text =
                'Baik '.$name.', tujuan sebelumnya saya reset. Alamat ambil dari profil belum tersedia. Isi Alamat Saya dulu, lalu kirim tujuan baru.';
        } else {
            $text =
                'Baik '.$name.', tujuan sebelumnya saya reset. Alamat ambil kamu di '.$pickupText.'. Sekarang kirim tujuan baru, misalnya: "Kirim ke Jalan XXX".';
        }

        return [
            'intent' => 'courier_order',
            'service_type' => 'kurir',
            'courier' => [
                'pickup_address' => $pickupText,
                'pickup_latitude' => $pickupLat,
                'pickup_longitude' => $pickupLng,
                'pickup_address_id' => $pickupAddressId,
                'used_default_pickup' => $pickupText !== null,
                'dropoff_address' => null,
                'dropoff_latitude' => null,
                'dropoff_longitude' => null,
                'package_description' => $packageDescription,
                ...$this->packagePolicyPayload($current),
                'ready_to_confirm' => false,
            ],
            'validation' => [
                'is_valid_order' => false,
                'rejection_reasons' => [],
                'missing_fields' => array_values(array_unique($missingFields)),
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
                'assistant_text' => 'Belum ada draft pengiriman yang siap dikonfirmasi. Kirim dulu detail pickup, tujuan, dan isi paket, lalu ketik "Konfirmasi".',
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
                'total_price' => (float) $order->total_price,
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
                ...$this->packagePolicyPayload($draft),
            ],
            'validation' => $validation,
            'order' => [
                'created' => false,
                'id' => null,
                'order_number' => null,
                'delivery_fee' => $draft['delivery_fee'] ?? null,
            ],
            'action_payloads' => is_array($draft['action_payloads'] ?? null)
                ? $draft['action_payloads']
                : [],
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
                ...$this->packagePolicyPayload($draft),
            ],
            'validation' => [
                'is_valid_order' => true,
                'rejection_reasons' => [],
                'missing_fields' => [],
                'next_actions' => ['CONFIRM_DRAFT', 'RESET_DESTINATION', 'CHANGE_PICKUP'],
            ],
            'action_payloads' => [
                'CONFIRM_DRAFT' => [
                    'label' => 'Konfirmasi',
                    'message' => 'Konfirmasi',
                ],
                'RESET_DESTINATION' => [
                    'label' => 'Ubah Tujuan',
                    'message' => 'Ubah Tujuan',
                ],
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
        $actionPayloads = [];

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
            if ($this->hasCoordinatePair($extracted, 'pickup')) {
                $pickupAddress = (string) $pickupRawAddress;
                $pickupLatitude = (float) $extracted['pickup_latitude'];
                $pickupLongitude = (float) $extracted['pickup_longitude'];
                $pickupAddressId = isset($extracted['pickup_address_id']) && is_numeric($extracted['pickup_address_id'])
                    ? (int) $extracted['pickup_address_id']
                    : null;
                $usedDefaultPickup = $pickupAddressId !== null && (bool) ($extracted['used_default_pickup'] ?? false);
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
        }

        if ($dropoffRawAddress === null) {
            $reasons[] = 'Lokasi tujuan belum terbaca. Tulis contoh: "kirim ke Jalan Sudirman No 10".';
            $missingFields[] = 'dropoff_address';
        } else {
            if ($this->hasCoordinatePair($extracted, 'dropoff')) {
                $dropoffAddress = (string) $dropoffRawAddress;
                $dropoffLatitude = (float) $extracted['dropoff_latitude'];
                $dropoffLongitude = (float) $extracted['dropoff_longitude'];
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
        }

        if ($packageDescription === null) {
            $reasons[] = 'Isi paket belum jelas. Tulis contoh: "isi paket kunci" atau "dokumen kontrak".';
            $missingFields[] = 'package_description';
        }

        $packagePolicy = $this->packagePolicyService->evaluate([
            'package_description' => $packageDescription,
            'message' => $message,
            'estimated_weight_kg' => $extracted['estimated_weight_kg'] ?? null,
            'package_length_cm' => $extracted['package_length_cm'] ?? null,
            'package_width_cm' => $extracted['package_width_cm'] ?? null,
            'package_height_cm' => $extracted['package_height_cm'] ?? null,
            'packing_note' => $extracted['packing_note'] ?? null,
        ]);

        if ($packageDescription !== null) {
            $packageSafetyStatus = (string) ($packagePolicy['safety_status'] ?? CourierPackagePolicyService::STATUS_ALLOWED);
            if ($packageSafetyStatus !== CourierPackagePolicyService::STATUS_ALLOWED) {
                $reasons[] = (string) ($packagePolicy['safety_reason'] ?? 'Barang belum memenuhi kebijakan layanan kurir motor.');
                $missingFields[] = 'package_description';
            }
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

                if (! $this->deliveryPricingService->isWithinMaxDistance($distanceMeters)) {
                    $mapField = $dropoffRawAddress !== null
                        ? 'dropoff_address'
                        : (! $usedDefaultPickup && $pickupRawAddress !== null ? 'pickup_address' : null);

                    if ($mapField === 'dropoff_address') {
                        $reasons[] = $this->buildMapSelectionReason('tujuan', $dropoffRawAddress);
                        $missingFields[] = 'dropoff_address';
                        $nextActions[] = 'OPEN_MAP_PICKER_DROPOFF';
                        $actionPayloads['OPEN_MAP_PICKER_DROPOFF'] = [
                            'target' => 'dropoff',
                            'label' => 'Pilih Titik Tujuan di Map',
                            'initial_latitude' => null,
                            'initial_longitude' => null,
                        ];
                        $dropoffAddress = is_string($dropoffRawAddress) ? $dropoffRawAddress : $dropoffAddress;
                        $dropoffLatitude = null;
                        $dropoffLongitude = null;
                        $distanceMeters = 0.0;
                        $distanceKm = null;
                    } elseif ($mapField === 'pickup_address') {
                        $reasons[] = $this->buildMapSelectionReason('ambil', $pickupRawAddress);
                        $missingFields[] = 'pickup_address';
                        $nextActions[] = 'OPEN_MAP_PICKER_PICKUP';
                        $actionPayloads['OPEN_MAP_PICKER_PICKUP'] = [
                            'target' => 'pickup',
                            'label' => 'Pilih Titik Ambil di Map',
                            'initial_latitude' => null,
                            'initial_longitude' => null,
                        ];
                        $pickupAddress = is_string($pickupRawAddress) ? $pickupRawAddress : $pickupAddress;
                        $pickupLatitude = null;
                        $pickupLongitude = null;
                        $distanceMeters = 0.0;
                        $distanceKm = null;
                    } else {
                        $reasons[] = sprintf(
                            'Jarak %.2f km melebihi batas layanan %.2f km.',
                            $distanceKm,
                            $this->deliveryPricingService->getMaxDistanceKm()
                        );
                    }
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
            ...$this->packagePolicyPayload($packagePolicy),
            'validation' => [
                'is_valid_order' => $reasons === [] &&
                    $pickupAddress !== null &&
                    $dropoffAddress !== null &&
                    $packageDescription !== null &&
                    ($packagePolicy['safety_status'] ?? CourierPackagePolicyService::STATUS_ALLOWED) === CourierPackagePolicyService::STATUS_ALLOWED,
                'rejection_reasons' => $reasons,
                'missing_fields' => array_values(array_unique($missingFields)),
                'next_actions' => array_values(array_unique($nextActions)),
            ],
            'action_payloads' => $actionPayloads,
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

        $packageKeywordPattern = implode('|', array_map(
            static fn (string $keyword): string => preg_quote($keyword, '/'),
            $this->packageOnlyKeywords
        ));

        if (
            $packageDescription === null &&
            preg_match('/\b('.$packageKeywordPattern.')\b/iu', $message, $packageMatch) === 1
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

    private function buildMapSelectionReason(string $target, mixed $rawAddress): string
    {
        $label = $target === 'ambil' ? 'lokasi ambil' : 'alamat tujuan';
        $raw = $this->normalizeWhitespace(is_string($rawAddress) ? $rawAddress : '');

        if ($raw === '') {
            return ucfirst($label).' belum pas di peta. Pilih titiknya langsung di map.';
        }

        return ucfirst($label).' "'.$raw.'" belum pas di peta. Pilih titiknya langsung di map.';
    }

    private function resolveProfilePickupAddress(Address $address): ?array
    {
        return $this->addressReadinessService->toLocationPayload($address);
    }

    private function resolveDefaultPickupAddress(User $user): ?Address
    {
        return $this->addressReadinessService->resolveDefaultUsableAddress($user);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function createCourierOrder(User $user, array $parsed, ?Address $profilePickupAddress): Order
    {
        $serviceTypeId = ServiceType::query()->where('code', 'COURIER')->value('id');
        $pendingStatusId = OrderStatus::query()->where('code', 'PENDING')->value('id');

        if (! $serviceTypeId || ! $pendingStatusId) {
            throw new ApiException('Konfigurasi service type atau status order belum lengkap.', 500);
        }

        $pickupAddress = trim((string) ($parsed['pickup_address'] ?? ''));
        $dropoffAddress = trim((string) ($parsed['dropoff_address'] ?? ''));
        $packageDescription = trim((string) ($parsed['package_description'] ?? ''));

        if ($pickupAddress === '' || $dropoffAddress === '' || $packageDescription === '') {
            throw new ApiException('Draft kurir tidak valid untuk dikonfirmasi. Kirim ulang detail pengiriman.', 422);
        }

        $packagePolicy = $this->packagePolicyService->evaluate([
            'package_description' => $packageDescription,
            'estimated_weight_kg' => $parsed['estimated_weight_kg'] ?? null,
            'package_length_cm' => $parsed['package_length_cm'] ?? null,
            'package_width_cm' => $parsed['package_width_cm'] ?? null,
            'package_height_cm' => $parsed['package_height_cm'] ?? null,
            'packing_note' => $parsed['packing_note'] ?? null,
        ]);

        if (($packagePolicy['safety_status'] ?? null) !== CourierPackagePolicyService::STATUS_ALLOWED) {
            throw new ApiException((string) ($packagePolicy['safety_reason'] ?? 'Barang belum memenuhi kebijakan layanan kurir motor.'), 422);
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

        if (! $this->deliveryPricingService->isWithinMaxDistance($distanceMeters)) {
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

        $order = DB::transaction(function () use (
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
            $packagePolicy,
            $estimatedMinutes
        ): Order {
            $order = Order::query()->create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'restaurant_id' => null,
                'service_type_id' => $serviceTypeId,
                'subtotal' => 0,
                'delivery_fee' => round($deliveryFee, 2),
                'service_fee' => round($serviceFee, 2),
                'delivery_distance_km' => $distanceKm !== null ? round($distanceKm, 2) : null,
                'delivery_distance_text' => $distanceKm !== null
                    ? (string) ($route['distance_text'] ?? number_format($distanceKm, 2).' km')
                    : null,
                'total_price' => round($totalAmount, 2),
                'status_id' => $pendingStatusId,
                'estimated_delivery' => Carbon::now()->addMinutes($estimatedMinutes),
            ]);

            $this->orderPaymentService->ensurePendingCodPayment($order);

            CourierOrder::query()->create([
                'order_id' => $order->id,
                'package_description' => $packageDescription,
                'estimated_weight_kg' => $packagePolicy['estimated_weight_kg'] ?? null,
                'package_length_cm' => $packagePolicy['package_length_cm'] ?? null,
                'package_width_cm' => $packagePolicy['package_width_cm'] ?? null,
                'package_height_cm' => $packagePolicy['package_height_cm'] ?? null,
                'package_size_class' => $packagePolicy['size_class'] ?? null,
                'package_safety_status' => $packagePolicy['safety_status'] ?? null,
                'package_safety_flags' => $packagePolicy['safety_flags'] ?? [],
                'package_safety_reason' => $packagePolicy['safety_reason'] ?? null,
                'package_packing_note' => $packagePolicy['packing_note'] ?? null,
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
                ],
            ]);

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $pendingStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $user->id,
                'note' => 'Order kurir dibuat oleh customer melalui chatbot.',
            ]);

            return $order->fresh(['statusRef', 'courierOrder', 'orderLocations', 'payments']);
        });

        $this->driverOrderRealtimeService->broadcastOrderAvailable($order);

        return $order;
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = $this->normalizeWhitespace($value);

        return $normalized === '' ? null : $normalized;
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

            if (! $this->isLikelyAddressFragment($value)) {
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
        if (! is_string($value)) {
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
        if (! is_array($parts)) {
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

        if ($this->isResetDestinationCommand($normalizedMessage)) {
            return 'reset_destination';
        }

        if ($this->isConfirmCommand($normalizedMessage)) {
            return 'confirm';
        }

        if ($nluCommand === 'reset_destination') {
            return 'reset_destination';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draftSeed
     */
    private function isAwaitingPackageOnly(array $draftSeed): bool
    {
        return $this->normalizeOptionalString($draftSeed['pickup_address'] ?? null) !== null &&
            $this->nullableCoordinate($draftSeed['pickup_latitude'] ?? null) !== null &&
            $this->nullableCoordinate($draftSeed['pickup_longitude'] ?? null) !== null &&
            $this->normalizeOptionalString($draftSeed['dropoff_address'] ?? null) !== null &&
            $this->nullableCoordinate($draftSeed['dropoff_latitude'] ?? null) !== null &&
            $this->nullableCoordinate($draftSeed['dropoff_longitude'] ?? null) !== null &&
            $this->normalizeOptionalString($draftSeed['package_description'] ?? null) === null;
    }

    private function extractPackageCompletion(string $message): ?string
    {
        $candidate = $this->sanitizeAddressFragment(
            preg_replace(
                '/^\s*(?:isi\s+paket|deskripsi\s+paket|paket(?:nya)?|barang(?:nya)?|kirim(?:kan)?|antar(?:kan)?)\s*[:\-]?\s*/iu',
                '',
                $message
            )
        );

        if ($candidate === null) {
            return null;
        }

        $normalized = strtolower($this->normalizeWhitespace($candidate));
        if (
            $this->isConfirmCommand($normalized) ||
            $this->isResetDestinationCommand($normalized) ||
            preg_match('/\b(?:jalan|jl\.?|gang|desa|kelurahan|kecamatan|kota|kabupaten|stasiun|bandara|terminal|mall|kampus|perumahan|kos)\b/iu', $normalized) === 1 ||
            preg_match('/\d|,/u', $normalized) === 1
        ) {
            return null;
        }

        $words = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($words) || count($words) > 4 || strlen($normalized) > 60) {
            return null;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>|null  $nluPayload
     * @return array<string, mixed>|null
     */
    private function buildDraftSeedFromNlu(?array $nluPayload): ?array
    {
        if (! is_array($nluPayload)) {
            return null;
        }

        $pickupAddress = $this->sanitizeAddressFragment(isset($nluPayload['pickup_address']) ? (string) $nluPayload['pickup_address'] : null);
        $dropoffAddress = $this->sanitizeAddressFragment(isset($nluPayload['dropoff_address']) ? (string) $nluPayload['dropoff_address'] : null);
        $packageDescription = $this->sanitizeAddressFragment(isset($nluPayload['package_description']) ? (string) $nluPayload['package_description'] : null);
        $estimatedWeightKg = $this->nullablePositiveFloat($nluPayload['estimated_weight_kg'] ?? null);
        $packageLengthCm = $this->nullablePositiveInt($nluPayload['package_length_cm'] ?? null);
        $packageWidthCm = $this->nullablePositiveInt($nluPayload['package_width_cm'] ?? null);
        $packageHeightCm = $this->nullablePositiveInt($nluPayload['package_height_cm'] ?? null);
        $packingNote = $this->sanitizeAddressFragment(isset($nluPayload['packing_note']) ? (string) $nluPayload['packing_note'] : null);

        if (
            $pickupAddress === null &&
            $dropoffAddress === null &&
            $packageDescription === null &&
            $estimatedWeightKg === null &&
            $packageLengthCm === null &&
            $packageWidthCm === null &&
            $packageHeightCm === null &&
            $packingNote === null
        ) {
            return null;
        }

        return [
            'pickup_address' => $pickupAddress,
            'dropoff_address' => $dropoffAddress,
            'package_description' => $packageDescription,
            'estimated_weight_kg' => $estimatedWeightKg,
            'package_length_cm' => $packageLengthCm,
            'package_width_cm' => $packageWidthCm,
            'package_height_cm' => $packageHeightCm,
            'packing_note' => $packingNote,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLatestDraftSeed(User $user, string $sessionId): array
    {
        $latestAssistantLog = AiChatLog::query()
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        $payload = $latestAssistantLog?->ai_response;
        if (! is_array($payload) || ($payload['intent'] ?? null) !== 'courier_order') {
            return [];
        }

        $order = $payload['order'] ?? null;
        if (is_array($order) && ($order['created'] ?? false) === true) {
            return [];
        }

        $courier = $payload['courier'] ?? null;
        if (! is_array($courier)) {
            return [];
        }

        return [
            'pickup_address' => $this->normalizeOptionalString($courier['pickup_address'] ?? null),
            'pickup_latitude' => $this->nullableCoordinate($courier['pickup_latitude'] ?? null),
            'pickup_longitude' => $this->nullableCoordinate($courier['pickup_longitude'] ?? null),
            'pickup_address_id' => isset($courier['pickup_address_id']) && is_numeric($courier['pickup_address_id'])
                ? (int) $courier['pickup_address_id']
                : null,
            'used_default_pickup' => (bool) ($courier['used_default_pickup'] ?? false),
            'dropoff_address' => $this->normalizeOptionalString($courier['dropoff_address'] ?? null),
            'dropoff_latitude' => $this->nullableCoordinate($courier['dropoff_latitude'] ?? null),
            'dropoff_longitude' => $this->nullableCoordinate($courier['dropoff_longitude'] ?? null),
            'package_description' => $this->normalizeOptionalString($courier['package_description'] ?? null),
            'estimated_weight_kg' => $this->nullablePositiveFloat($courier['estimated_weight_kg'] ?? null),
            'package_length_cm' => $this->nullablePositiveInt($courier['package_length_cm'] ?? null),
            'package_width_cm' => $this->nullablePositiveInt($courier['package_width_cm'] ?? null),
            'package_height_cm' => $this->nullablePositiveInt($courier['package_height_cm'] ?? null),
            'packing_note' => $this->normalizeOptionalString($courier['packing_note'] ?? null),
            'safety_status' => $courier['safety_status'] ?? null,
            'safety_flags' => is_array($courier['safety_flags'] ?? null) ? $courier['safety_flags'] : [],
            'safety_reason' => $courier['safety_reason'] ?? null,
            'size_class' => $courier['size_class'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>|null  $incoming
     * @return array<string, mixed>
     */
    private function mergeCourierDraftSeed(array $base, ?array $incoming): array
    {
        $merged = $base;
        $incoming ??= [];

        $merged = $this->mergeLocationSeed(
            $merged,
            $incoming,
            'pickup_address',
            'pickup_latitude',
            'pickup_longitude'
        );
        $merged = $this->mergeLocationSeed(
            $merged,
            $incoming,
            'dropoff_address',
            'dropoff_latitude',
            'dropoff_longitude'
        );

        if (array_key_exists('pickup_address_id', $incoming)) {
            $merged['pickup_address_id'] = isset($incoming['pickup_address_id']) && is_numeric($incoming['pickup_address_id'])
                ? (int) $incoming['pickup_address_id']
                : null;
        }

        if (array_key_exists('used_default_pickup', $incoming)) {
            $merged['used_default_pickup'] = (bool) $incoming['used_default_pickup'];
        }

        foreach (['package_description', 'packing_note'] as $field) {
            if (! array_key_exists($field, $incoming)) {
                continue;
            }

            $value = $this->normalizeOptionalString($incoming[$field]);
            if ($value !== null) {
                $merged[$field] = $value;
            }
        }

        foreach (['estimated_weight_kg', 'package_length_cm', 'package_width_cm', 'package_height_cm'] as $field) {
            if (! array_key_exists($field, $incoming)) {
                continue;
            }

            $value = $field === 'estimated_weight_kg'
                ? $this->nullablePositiveFloat($incoming[$field])
                : $this->nullablePositiveInt($incoming[$field]);
            if ($value !== null) {
                $merged[$field] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $merged
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeLocationSeed(
        array $merged,
        array $incoming,
        string $addressKey,
        string $latitudeKey,
        string $longitudeKey
    ): array {
        if (! array_key_exists($addressKey, $incoming)) {
            return $merged;
        }

        $address = $this->normalizeOptionalString($incoming[$addressKey]);
        if ($address === null) {
            return $merged;
        }

        $previousAddress = $this->normalizeOptionalString($merged[$addressKey] ?? null);
        $merged[$addressKey] = $address;

        $latitude = $this->nullableCoordinate($incoming[$latitudeKey] ?? null);
        $longitude = $this->nullableCoordinate($incoming[$longitudeKey] ?? null);
        if ($latitude !== null && $longitude !== null) {
            $merged[$latitudeKey] = $latitude;
            $merged[$longitudeKey] = $longitude;
        } elseif ($previousAddress === null || strcasecmp($previousAddress, $address) !== 0) {
            $merged[$latitudeKey] = null;
            $merged[$longitudeKey] = null;
        }

        if ($addressKey === 'pickup_address' && ($previousAddress === null || strcasecmp($previousAddress, $address) !== 0)) {
            $merged['pickup_address_id'] = null;
            $merged['used_default_pickup'] = false;
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function hasCoordinatePair(array $source, string $prefix): bool
    {
        return $this->nullableCoordinate($source[$prefix.'_latitude'] ?? null) !== null
            && $this->nullableCoordinate($source[$prefix.'_longitude'] ?? null) !== null;
    }

    private function estimateDeliveryMinutes(int $durationSeconds): int
    {
        $estimated = (int) ceil(max(0, $durationSeconds) / 60);

        return max(20, min(180, $estimated + 10));
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function packagePolicyPayload(array $source): array
    {
        return [
            'safety_status' => $source['safety_status'] ?? null,
            'safety_flags' => $source['safety_flags'] ?? [],
            'safety_reason' => $source['safety_reason'] ?? null,
            'size_class' => $source['size_class'] ?? null,
            'estimated_weight_kg' => $source['estimated_weight_kg'] ?? null,
            'package_length_cm' => $source['package_length_cm'] ?? null,
            'package_width_cm' => $source['package_width_cm'] ?? null,
            'package_height_cm' => $source['package_height_cm'] ?? null,
            'packing_note' => $source['packing_note'] ?? null,
        ];
    }

    private function nullablePositiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $parsed = round((float) $value, 2);

        return $parsed > 0 ? $parsed : null;
    }

    private function nullableCoordinate(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $parsed = (int) round((float) $value);

        return $parsed > 0 ? $parsed : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatPackageSizeLine(array $payload): string
    {
        $parts = [];
        if (is_numeric($payload['estimated_weight_kg'] ?? null)) {
            $parts[] = rtrim(rtrim(number_format((float) $payload['estimated_weight_kg'], 2, ',', '.'), '0'), ',').' kg';
        }

        $dimensions = array_filter([
            $payload['package_length_cm'] ?? null,
            $payload['package_width_cm'] ?? null,
            $payload['package_height_cm'] ?? null,
        ], fn ($value): bool => is_numeric($value) && (int) $value > 0);

        if ($dimensions !== []) {
            $parts[] = implode('x', array_map(fn ($value): string => (string) (int) $value, $dimensions)).' cm';
        }

        if ($parts === []) {
            return 'kecil/ringan untuk motor';
        }

        return implode(' - ', $parts);
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
        $nextActions = $parsed['validation']['next_actions'] ?? [];

        if (! is_array($reasons) || $reasons === []) {
            return 'Data kurir belum lengkap. Mohon isi lokasi ambil, tujuan kirim, dan isi paket.';
        }

        if ($this->isAwaitingPackageOnly($parsed)) {
            return 'Titik ambil dan tujuan sudah saya simpan. Barang apa yang mau dikirim? Contoh: "isi paket sabun".';
        }

        if (
            is_array($nextActions) &&
            (in_array('OPEN_MAP_PICKER_DROPOFF', $nextActions, true) ||
                in_array('OPEN_MAP_PICKER_PICKUP', $nextActions, true))
        ) {
            $message = trim((string) $reasons[0]);

            return $message === ''
                ? 'Alamat belum pas di peta. Pilih titiknya langsung di map.'
                : $message;
        }

        if (count($reasons) === 1) {
            $reason = trim((string) $reasons[0]);
            if (str_contains($reason, 'Isi paket belum spesifik')) {
                return $reason.' Contoh: kacamata, dokumen, kunci, atau charger.';
            }

            if ($reason === 'Estimasi berat atau ukuran paket belum jelas.') {
                return 'Barang ini perlu sedikit klarifikasi. Sebutkan jenis barangnya atau perkiraan ukurannya supaya driver tidak salah ambil.';
            }
        }

        $buffer = "Order kurir belum bisa dibuat karena:\n";
        foreach ($reasons as $reason) {
            $buffer .= '- '.trim((string) $reason)."\n";
        }

        $buffer .= "\nLengkapi bagian yang diminta saja, atau pilih titik di map jika alamatnya belum pas.";

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
        $buffer .= 'Ambil: '.(string) $parsed['pickup_address']."\n";
        $buffer .= 'Tujuan: '.(string) $parsed['dropoff_address']."\n";
        $buffer .= 'Barang: '.(string) $parsed['package_description']."\n";
        $buffer .= 'Ukuran/Berat: '.$this->formatPackageSizeLine($parsed)."\n";
        $buffer .= 'Status barang: '.(string) ($parsed['safety_reason'] ?? 'Paket aman untuk layanan kurir motor.')."\n";
        $buffer .= "Estimasi ongkir sementara: Rp {$deliveryFee} (kalkulasi detail menyusul).\n";
        $buffer .= 'Ketik "Konfirmasi" untuk lanjut. Pembayaran dilakukan tunai saat driver tiba dan mengecek barang di titik ambil.';

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
        $buffer .= 'Ambil: '.(string) $parsed['pickup_address']."\n";
        $buffer .= 'Tujuan: '.(string) $parsed['dropoff_address']."\n";
        $buffer .= 'Barang: '.(string) $parsed['package_description']."\n";
        $buffer .= 'Ukuran/Berat: '.$this->formatPackageSizeLine($parsed)."\n";
        $buffer .= "Ongkir: Rp {$deliveryFee}.\n";
        $buffer .= 'Bayar tunai ke driver saat menyerahkan barang di titik ambil.';

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
        if (! is_array($payload)) {
            return null;
        }

        if (($payload['intent'] ?? null) !== 'courier_order') {
            return null;
        }

        $order = $payload['order'] ?? null;
        if (! is_array($order) || ($order['created'] ?? false) === true) {
            return null;
        }

        $validation = $payload['validation'] ?? null;
        $courier = $payload['courier'] ?? null;
        if (! is_array($validation) || ! is_array($courier)) {
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
            'safety_status' => $courier['safety_status'] ?? null,
            'safety_flags' => is_array($courier['safety_flags'] ?? null) ? $courier['safety_flags'] : [],
            'safety_reason' => $courier['safety_reason'] ?? null,
            'size_class' => $courier['size_class'] ?? null,
            'estimated_weight_kg' => isset($courier['estimated_weight_kg']) && is_numeric($courier['estimated_weight_kg']) ? (float) $courier['estimated_weight_kg'] : null,
            'package_length_cm' => isset($courier['package_length_cm']) && is_numeric($courier['package_length_cm']) ? (int) $courier['package_length_cm'] : null,
            'package_width_cm' => isset($courier['package_width_cm']) && is_numeric($courier['package_width_cm']) ? (int) $courier['package_width_cm'] : null,
            'package_height_cm' => isset($courier['package_height_cm']) && is_numeric($courier['package_height_cm']) ? (int) $courier['package_height_cm'] : null,
            'packing_note' => $courier['packing_note'] ?? null,
        ];
    }
}
