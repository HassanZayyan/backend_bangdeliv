<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\AiChatLog;
use App\Models\Order;
use App\Models\User;

class ChatbotRideOrderService
{
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
        private readonly RideOrderService $rideOrderService,
        private readonly GoogleMapsGeocodingService $geocodingService,
        private readonly GoogleMapsDistanceMatrixService $distanceMatrixService,
        private readonly DeliveryPricingService $deliveryPricingService
    ) {}

    /**
     * @param  array<string, mixed>|null  $nluPayload
     * @return array<string, mixed>
     */
    public function process(User $user, string $message, string $sessionId, ?array $nluPayload = null): array
    {
        if ($user->role !== 'customer') {
            throw new ApiException('Hanya customer yang dapat membuat order antar jemput dari chatbot.', 403);
        }

        if (! $user->is_active || $user->is_blacklisted) {
            throw new ApiException('Akun tidak memenuhi syarat untuk membuat order antar jemput.', 403);
        }

        $normalizedMessage = $this->normalizeWhitespace($message);
        $command = $this->resolveCommand($normalizedMessage, $nluPayload);

        if ($command === 'reset_destination') {
            return $this->handleResetDestination($user, $sessionId);
        }

        if ($command === 'confirm') {
            return $this->confirmPendingDraft($user, $sessionId);
        }

        $defaultPickupAddress = $this->resolveDefaultPickupAddress($user);
        $incomingSeed = $this->buildIncomingDraftSeed($normalizedMessage, $nluPayload);
        $draftSeed = $this->mergeRideDraftSeed(
            $this->resolveLatestDraftSeed($user, $sessionId),
            $incomingSeed
        );
        $draft = $this->buildRideDraft($normalizedMessage, $defaultPickupAddress, $draftSeed);

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
            throw new ApiException('Hanya customer yang dapat membuat order antar jemput dari chatbot.', 403);
        }

        if (! $user->is_active || $user->is_blacklisted) {
            throw new ApiException('Akun tidak memenuhi syarat untuk membuat order antar jemput.', 403);
        }

        $incomingSeed = [];
        foreach ($locations as $location) {
            $target = (string) ($location['target'] ?? '');
            $latitude = (float) ($location['latitude'] ?? 0);
            $longitude = (float) ($location['longitude'] ?? 0);
            $address = trim((string) ($location['address'] ?? ''));

            if ($target === 'pickup') {
                $incomingSeed = $this->mergeRideDraftSeed($incomingSeed, [
                    'pickup_address' => $address,
                    'pickup_latitude' => $latitude,
                    'pickup_longitude' => $longitude,
                    'pickup_address_id' => null,
                    'used_default_pickup' => false,
                ]);
                continue;
            }

            if ($target === 'destination') {
                $incomingSeed = $this->mergeRideDraftSeed($incomingSeed, [
                    'destination_address' => $address,
                    'destination_latitude' => $latitude,
                    'destination_longitude' => $longitude,
                ]);
                continue;
            }

            throw new ApiException('Target lokasi antar jemput tidak valid.', 422);
        }

        $draftSeed = $this->mergeRideDraftSeed(
            $this->resolveLatestDraftSeed($user, $sessionId),
            $incomingSeed
        );
        $draft = $this->buildRideDraft('', $this->resolveDefaultPickupAddress($user), $draftSeed);

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
        $usedDefaultPickup = (bool) ($current['used_default_pickup'] ?? false);
        $name = trim((string) $user->name) === '' ? 'Kak' : trim((string) $user->name);

        if ($pickupText === null) {
            $text = "Baik {$name}, tujuan sebelumnya sudah saya reset.\n";
            $text .= "\nAlamat jemput dari profil belum tersedia.";
            $text .= "\nSilakan isi Alamat Saya terlebih dahulu.";
            $text .= "\n\nSetelah itu, klik tombol \"Atur Titik Jemput & Tujuan\" di bawah untuk memilih tujuan baru.";
        } else {
            $text = "Baik {$name}, tujuan sebelumnya sudah saya reset.\n";
            $text .= "\nJemput:";
            $text .= "\n{$pickupText}";
            $text .= "\n\nSilakan klik tombol \"Atur Titik Jemput & Tujuan\" di bawah untuk memilih tujuan baru.";
        }

        return [
            'intent' => 'ride_order',
            'service_type' => 'antar_jemput',
            'ride' => [
                'pickup_address' => $pickupText,
                'pickup_latitude' => $pickupLat,
                'pickup_longitude' => $pickupLng,
                'pickup_address_id' => $pickupAddressId,
                'used_default_pickup' => $usedDefaultPickup,
                'destination_address' => null,
                'destination_latitude' => null,
                'destination_longitude' => null,
                'ready_to_confirm' => false,
            ],
            'validation' => [
                'is_valid_order' => false,
                'rejection_reasons' => [],
                'missing_fields' => ['destination_address'],
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
            return $this->buildMissingDraftPayload($user);
        }

        $ridePayload = [
            'destination_address' => (string) $pendingDraft['destination_address'],
        ];

        $pickupAddressId = $pendingDraft['pickup_address_id'] ?? null;
        if (is_int($pickupAddressId) && $pickupAddressId > 0) {
            $ridePayload['address_id'] = $pickupAddressId;
        } else {
            $ridePayload['pickup_address'] = (string) ($pendingDraft['pickup_address'] ?? '');
            $ridePayload['pickup_latitude'] = $pendingDraft['pickup_latitude'] ?? null;
            $ridePayload['pickup_longitude'] = $pendingDraft['pickup_longitude'] ?? null;
        }

        if (
            isset($pendingDraft['destination_latitude'], $pendingDraft['destination_longitude']) &&
            is_numeric($pendingDraft['destination_latitude']) &&
            is_numeric($pendingDraft['destination_longitude'])
        ) {
            $ridePayload['destination_latitude'] = (float) $pendingDraft['destination_latitude'];
            $ridePayload['destination_longitude'] = (float) $pendingDraft['destination_longitude'];
        }

        $order = $this->rideOrderService->create($user, $ridePayload);

        return [
            'intent' => 'ride_order',
            'service_type' => 'antar_jemput',
            'ride' => [
                'pickup_address' => $pendingDraft['pickup_address'],
                'destination_address' => $pendingDraft['destination_address'],
                'ready_to_confirm' => false,
                'used_default_pickup' => (bool) ($pendingDraft['used_default_pickup'] ?? false),
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
                'rejection_reasons' => ['Data antar jemput belum lengkap.'],
                'missing_fields' => [],
                'next_actions' => [],
            ];

        return [
            'intent' => 'ride_order',
            'service_type' => 'antar_jemput',
            'ride' => [
                'pickup_address' => $draft['pickup_address'] ?? null,
                'destination_address' => $draft['destination_address'] ?? null,
                'ready_to_confirm' => false,
                'used_default_pickup' => (bool) ($draft['used_default_pickup'] ?? false),
                'pickup_latitude' => $draft['pickup_latitude'] ?? null,
                'pickup_longitude' => $draft['pickup_longitude'] ?? null,
                'destination_latitude' => $draft['destination_latitude'] ?? null,
                'destination_longitude' => $draft['destination_longitude'] ?? null,
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
            'intent' => 'ride_order',
            'service_type' => 'antar_jemput',
            'ride' => [
                'pickup_address' => $draft['pickup_address'],
                'destination_address' => $draft['destination_address'],
                'pickup_address_id' => $draft['pickup_address_id'],
                'ready_to_confirm' => true,
                'used_default_pickup' => (bool) ($draft['used_default_pickup'] ?? false),
                'pickup_latitude' => $draft['pickup_latitude'],
                'pickup_longitude' => $draft['pickup_longitude'],
                'destination_latitude' => $draft['destination_latitude'],
                'destination_longitude' => $draft['destination_longitude'],
                'distance_km' => $draft['distance_km'],
            ],
            'validation' => [
                'is_valid_order' => true,
                'rejection_reasons' => [],
                'missing_fields' => [],
                'next_actions' => ['CONFIRM_DRAFT', 'RESET_DESTINATION'],
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
     * @param  array<string, mixed>|null  $nluPayload
     * @return array<string, mixed>
     */
    private function buildRideDraft(string $message, ?Address $defaultPickupAddress, ?array $draftSeed = null): array
    {
        $draftSeed ??= [];
        $destinationRaw = $this->normalizeOptionalString($draftSeed['destination_address'] ?? null);
        $destinationRaw ??= $this->extractDestinationFromMessage($message);
        $pickupRaw = $this->normalizeOptionalString($draftSeed['pickup_address'] ?? null);

        $pickupAddress = null;
        $pickupLatitude = null;
        $pickupLongitude = null;
        $pickupAddressId = null;
        $usedDefaultPickup = false;

        $destinationAddress = null;
        $destinationLatitude = null;
        $destinationLongitude = null;
        $distanceKm = null;

        $reasons = [];
        $missingFields = [];
        $nextActions = [];

        if ($pickupRaw !== null && $this->hasCoordinatePair($draftSeed, 'pickup')) {
            $pickupAddress = $pickupRaw;
            $pickupLatitude = (float) $draftSeed['pickup_latitude'];
            $pickupLongitude = (float) $draftSeed['pickup_longitude'];
            $pickupAddressId = isset($draftSeed['pickup_address_id']) && is_numeric($draftSeed['pickup_address_id'])
                ? (int) $draftSeed['pickup_address_id']
                : null;
            $usedDefaultPickup = $pickupAddressId !== null && (bool) ($draftSeed['used_default_pickup'] ?? false);
        } elseif ($defaultPickupAddress === null) {
            $reasons[] = 'Lokasi jemput di profil belum tersedia. Isi Alamat Saya terlebih dahulu.';
            $missingFields[] = 'pickup_address';
            $nextActions[] = 'OPEN_ADDRESSES';
        } else {
            $resolvedPickup = $this->resolveProfilePickupAddress($defaultPickupAddress);
            if ($resolvedPickup === null) {
                $reasons[] = 'Lokasi jemput dari profil tidak valid di peta. Perbarui Alamat Saya terlebih dahulu.';
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

        if ($destinationRaw === null) {
            $reasons[] = 'Lokasi tujuan belum terbaca. Tulis contoh: "antar ke Stasiun Tawang" atau "tujuan ke Jalan Sudirman No 10".';
            $missingFields[] = 'destination_address';
        } else {
            try {
                if ($this->hasCoordinatePair($draftSeed, 'destination')) {
                    $destinationAddress = $destinationRaw;
                    $destinationLatitude = (float) $draftSeed['destination_latitude'];
                    $destinationLongitude = (float) $draftSeed['destination_longitude'];
                } else {
                    $resolvedDestination = $this->rideOrderService->validateDestination($destinationRaw);
                    $destinationAddress = $resolvedDestination['formatted_address'];
                    $destinationLatitude = $resolvedDestination['latitude'];
                    $destinationLongitude = $resolvedDestination['longitude'];
                }
            } catch (ApiException $exception) {
                if ($exception->status() === 422) {
                    $reasons[] = 'Lokasi tujuan tidak ditemukan di peta. Gunakan alamat yang lebih spesifik.';
                    $missingFields[] = 'destination_address';
                } else {
                    throw $exception;
                }
            }
        }

        $deliveryFee = (float) $this->deliveryPricingService->calculateFromDistanceMeters(0)['total_fee'];

        if (
            $pickupLatitude !== null &&
            $pickupLongitude !== null &&
            $destinationLatitude !== null &&
            $destinationLongitude !== null
        ) {
            try {
                $route = $this->distanceMatrixService->resolveRoute(
                    $pickupLatitude,
                    $pickupLongitude,
                    $destinationLatitude,
                    $destinationLongitude,
                );

                $distanceMeters = (float) $route['distance_meters'];
                $distanceKm = (float) $route['distance_km'];

                if (! $this->deliveryPricingService->isWithinMaxDistance($distanceMeters)) {
                    $reasons[] = sprintf(
                        'Jarak %.2f km melebihi batas layanan %.2f km.',
                        $distanceKm,
                        $this->deliveryPricingService->getMaxDistanceKm()
                    );
                }

                $deliveryFee = (float) $this->deliveryPricingService
                    ->calculateFromDistanceMeters($distanceMeters)['total_fee'];
            } catch (ApiException $exception) {
                if ($exception->status() === 422) {
                    $reasons[] = 'Rute jemput ke tujuan tidak ditemukan. Gunakan alamat yang lebih spesifik.';
                    $missingFields[] = 'destination_address';
                } else {
                    throw $exception;
                }
            }
        }

        return [
            'pickup_address' => $pickupAddress,
            'destination_address' => $destinationAddress,
            'pickup_address_id' => $pickupAddressId,
            'pickup_latitude' => $pickupLatitude,
            'pickup_longitude' => $pickupLongitude,
            'destination_latitude' => $destinationLatitude,
            'destination_longitude' => $destinationLongitude,
            'distance_km' => $distanceKm,
            'delivery_fee' => $deliveryFee,
            'used_default_pickup' => $usedDefaultPickup,
            'validation' => [
                'is_valid_order' => $reasons === [] && $pickupAddress !== null && $destinationAddress !== null,
                'rejection_reasons' => $reasons,
                'missing_fields' => array_values(array_unique($missingFields)),
                'next_actions' => array_values(array_unique($nextActions)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $nluPayload
     * @return array<string, mixed>
     */
    private function buildIncomingDraftSeed(string $message, ?array $nluPayload): array
    {
        $destination = $this->normalizeOptionalString($nluPayload['destination_address'] ?? null);
        $destination ??= $this->extractDestinationFromMessage($message);

        return [
            'destination_address' => $destination,
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
        if (! is_array($payload) || ($payload['intent'] ?? null) !== 'ride_order') {
            return [];
        }

        $order = $payload['order'] ?? null;
        if (is_array($order) && ($order['created'] ?? false) === true) {
            return [];
        }

        $ride = $payload['ride'] ?? null;
        if (! is_array($ride)) {
            return [];
        }

        return [
            'pickup_address' => $this->normalizeOptionalString($ride['pickup_address'] ?? null),
            'pickup_latitude' => $this->nullableCoordinate($ride['pickup_latitude'] ?? null),
            'pickup_longitude' => $this->nullableCoordinate($ride['pickup_longitude'] ?? null),
            'pickup_address_id' => isset($ride['pickup_address_id']) && is_numeric($ride['pickup_address_id'])
                ? (int) $ride['pickup_address_id']
                : null,
            'used_default_pickup' => (bool) ($ride['used_default_pickup'] ?? false),
            'destination_address' => $this->normalizeOptionalString($ride['destination_address'] ?? null),
            'destination_latitude' => $this->nullableCoordinate($ride['destination_latitude'] ?? null),
            'destination_longitude' => $this->nullableCoordinate($ride['destination_longitude'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>|null  $incoming
     * @return array<string, mixed>
     */
    private function mergeRideDraftSeed(array $base, ?array $incoming): array
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
            'destination_address',
            'destination_latitude',
            'destination_longitude'
        );

        if (array_key_exists('pickup_address_id', $incoming)) {
            $merged['pickup_address_id'] = isset($incoming['pickup_address_id']) && is_numeric($incoming['pickup_address_id'])
                ? (int) $incoming['pickup_address_id']
                : null;
        }

        if (array_key_exists('used_default_pickup', $incoming)) {
            $merged['used_default_pickup'] = (bool) $incoming['used_default_pickup'];
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

    private function normalizeWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function nullableCoordinate(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function resolveCommand(string $normalizedMessage, ?array $nluPayload): ?string
    {
        if ($this->isResetDestinationCommand($normalizedMessage)) {
            return 'reset_destination';
        }

        if ($this->isConfirmCommand($normalizedMessage)) {
            return 'confirm';
        }

        $nluCommand = strtolower(trim((string) ($nluPayload['command'] ?? '')));
        if ($nluCommand === 'confirm' && $this->isSoftConfirmCommand($normalizedMessage)) {
            return 'confirm';
        }

        if ($nluCommand === 'reset_destination' && $this->isSoftResetCommand($normalizedMessage)) {
            return 'reset_destination';
        }

        return null;
    }

    private function isConfirmCommand(string $message): bool
    {
        $normalized = $this->normalizeCommandToken($message);

        return in_array($normalized, $this->confirmCommands, true);
    }

    private function isResetDestinationCommand(string $message): bool
    {
        $normalized = $this->normalizeCommandToken($message);

        return in_array($normalized, $this->resetCommands, true);
    }

    private function isSoftConfirmCommand(string $message): bool
    {
        $normalized = $this->normalizeCommandToken($message);

        return preg_match('/^(?:ok(?:e|ay)?\s+)?(?:konfirmasi|confirm|lanjut)(?:\s+(?:ya|aja|dong))?$/u', $normalized) === 1;
    }

    private function isSoftResetCommand(string $message): bool
    {
        $normalized = $this->normalizeCommandToken($message);

        return preg_match('/^(?:tolong\s+)?(?:ubah|ganti|reset)\s+tujuan(?:\s+(?:ya|aja|dong))?$/u', $normalized) === 1;
    }

    private function normalizeCommandToken(string $message): string
    {
        $normalized = strtolower($this->normalizeWhitespace($message));

        return trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $normalized));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMissingDraftPayload(User $user): array
    {
        $defaultPickupAddress = $this->resolveDefaultPickupAddress($user);
        $resolvedPickup = $defaultPickupAddress === null
            ? null
            : $this->resolveProfilePickupAddress($defaultPickupAddress);

        if ($resolvedPickup === null) {
            return [
                'intent' => 'ride_order',
                'service_type' => 'antar_jemput',
                'ride' => [
                    'pickup_address' => null,
                    'destination_address' => null,
                    'ready_to_confirm' => false,
                    'used_default_pickup' => false,
                    'pickup_latitude' => null,
                    'pickup_longitude' => null,
                    'destination_latitude' => null,
                    'destination_longitude' => null,
                ],
                'validation' => [
                    'is_valid_order' => false,
                    'rejection_reasons' => [
                        'Alamat jemput belum tersedia. Isi Alamat Saya terlebih dahulu atau pilih titik jemput lewat peta.',
                    ],
                    'missing_fields' => ['pickup_address', 'destination_address'],
                    'next_actions' => ['OPEN_ADDRESSES'],
                ],
                'order' => [
                    'created' => false,
                    'id' => null,
                    'order_number' => null,
                    'delivery_fee' => null,
                ],
                'assistant_text' => 'Alamat jemput kamu belum tersedia. Isi Alamat Saya dulu, atau pilih titik jemput dan tujuan langsung di peta.',
            ];
        }

        return [
            'intent' => 'ride_order',
            'service_type' => 'antar_jemput',
            'ride' => [
                'pickup_address' => $resolvedPickup['formatted_address'],
                'destination_address' => null,
                'ready_to_confirm' => false,
                'used_default_pickup' => true,
                'pickup_latitude' => $resolvedPickup['latitude'],
                'pickup_longitude' => $resolvedPickup['longitude'],
                'destination_latitude' => null,
                'destination_longitude' => null,
            ],
            'validation' => [
                'is_valid_order' => false,
                'rejection_reasons' => [
                    'Belum ada draft antar jemput yang siap dikonfirmasi. Kirim tujuan terlebih dahulu.',
                ],
                'missing_fields' => ['destination_address'],
                'next_actions' => ['OPEN_MAP_PICKER_DESTINATION'],
            ],
            'order' => [
                'created' => false,
                'id' => null,
                'order_number' => null,
                'delivery_fee' => null,
            ],
            'assistant_text' => 'Belum ada draft antar jemput yang siap dikonfirmasi. Kirim dulu tujuanmu, ketik "Konfirmasi", atau pilih titik tujuan di peta.',
        ];
    }

    private function extractDestinationFromMessage(string $message): ?string
    {
        $patterns = [
            '/\b(?:pergi\s+ke|menuju\s+ke|mau\s+ke|antar(?:kan)?\s+ke|drop\s?off\s+(?:di|ke)|tujuan(?:nya)?\s*(?:di|ke)?)\s+(.+)$/iu',
            '/\bke\s+(.+)$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $match) !== 1) {
                continue;
            }

            $candidate = $this->normalizeOptionalString($match[1] ?? null);
            if ($candidate === null || strlen($candidate) < 4) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveProfilePickupAddress(Address $address): ?array
    {
        return [
            'formatted_address' => trim((string) $address->full_address),
            'latitude' => (float) $address->latitude,
            'longitude' => (float) $address->longitude,
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
     * @param  array<string, mixed>  $draft
     */
    private function buildValidationMessage(array $draft): string
    {
        $reasons = $draft['validation']['rejection_reasons'] ?? [];
        $missingFields = $draft['validation']['missing_fields'] ?? [];

        if (! is_array($reasons) || $reasons === []) {
            return 'Data antar jemput belum lengkap. Mohon isi lokasi tujuan.';
        }

        if (! is_array($missingFields)) {
            $missingFields = [];
        }

        $buffer = "Order antar jemput belum bisa dibuat karena:\n";
        foreach ($reasons as $reason) {
            $buffer .= '- '.trim((string) $reason)."\n";
        }

        if (in_array('destination_address', $missingFields, true)) {
            $buffer .= "\nContoh: antar ke Stasiun Tawang, tujuan ke Jalan Sudirman No 10, atau saya mau ke Polines.";
        }

        return trim($buffer);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function buildDraftMessage(array $draft, string $userName): string
    {
        $name = trim($userName) === '' ? 'Kak' : trim($userName);
        $deliveryFee = number_format((float) ($draft['delivery_fee'] ?? 0), 0, ',', '.');

        $buffer = "Baik {$name}, saya sudah siapkan draft Antar Jemput.\n";
        $buffer .= 'Jemput: '.(string) $draft['pickup_address']."\n";
        $buffer .= 'Tujuan: '.(string) $draft['destination_address']."\n";
        $buffer .= "Estimasi ongkir sementara: Rp {$deliveryFee} (kalkulasi detail menyusul).\n";
        $buffer .= 'Ketik "Konfirmasi" untuk lanjut atau "Ubah Tujuan" untuk ganti tujuan.';

        return $buffer;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function buildSuccessMessage(Order $order, array $draft): string
    {
        $deliveryFee = number_format((float) $order->delivery_fee, 0, ',', '.');

        $buffer = "Siap, order antar jemput berhasil dibuat.\n";
        $buffer .= "Nomor order: {$order->order_number}\n";
        $buffer .= 'Jemput: '.(string) $draft['pickup_address']."\n";
        $buffer .= 'Tujuan: '.(string) $draft['destination_address']."\n";
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
        if (! is_array($payload)) {
            return null;
        }

        if (($payload['intent'] ?? null) !== 'ride_order') {
            return null;
        }

        $order = $payload['order'] ?? null;
        if (! is_array($order) || ($order['created'] ?? false) === true) {
            return null;
        }

        $validation = $payload['validation'] ?? null;
        $ride = $payload['ride'] ?? null;
        if (! is_array($validation) || ! is_array($ride)) {
            return null;
        }

        if (($validation['is_valid_order'] ?? false) !== true) {
            return null;
        }

        if (($ride['ready_to_confirm'] ?? false) !== true) {
            return null;
        }

        $pickupAddress = trim((string) ($ride['pickup_address'] ?? ''));
        $destinationAddress = trim((string) ($ride['destination_address'] ?? ''));
        $pickupAddressId = isset($ride['pickup_address_id']) ? (int) $ride['pickup_address_id'] : null;
        $pickupLatitude = isset($ride['pickup_latitude']) && is_numeric($ride['pickup_latitude'])
            ? (float) $ride['pickup_latitude']
            : null;
        $pickupLongitude = isset($ride['pickup_longitude']) && is_numeric($ride['pickup_longitude'])
            ? (float) $ride['pickup_longitude']
            : null;
        $destinationLatitude = isset($ride['destination_latitude']) && is_numeric($ride['destination_latitude'])
            ? (float) $ride['destination_latitude']
            : null;
        $destinationLongitude = isset($ride['destination_longitude']) && is_numeric($ride['destination_longitude'])
            ? (float) $ride['destination_longitude']
            : null;

        if (
            $pickupAddress === '' ||
            $destinationAddress === '' ||
            $pickupLatitude === null ||
            $pickupLongitude === null
        ) {
            return null;
        }

        return [
            'pickup_address' => $pickupAddress,
            'destination_address' => $destinationAddress,
            'pickup_address_id' => $pickupAddressId,
            'pickup_latitude' => $pickupLatitude,
            'pickup_longitude' => $pickupLongitude,
            'destination_latitude' => $destinationLatitude,
            'destination_longitude' => $destinationLongitude,
            'delivery_fee' => isset($order['delivery_fee']) ? (float) $order['delivery_fee'] : null,
            'used_default_pickup' => (bool) ($ride['used_default_pickup'] ?? false),
        ];
    }
}
