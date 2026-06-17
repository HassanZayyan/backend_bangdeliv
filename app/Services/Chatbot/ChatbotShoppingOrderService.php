<?php

namespace App\Services\Chatbot;

use App\Exceptions\ApiException;
use App\Models\AiChatLog;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\Address\ChatbotAddressReadinessService;
use App\Services\Driver\DriverOrderRealtimeService;
use App\Services\Maps\GoogleMapsGeocodingService;
use App\Services\Order\OrderPaymentService;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingMerchantCandidate;
use App\Services\Shopping\ShoppingMerchantCandidateResolver;
use App\Services\Shopping\ShoppingRouteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatbotShoppingOrderService
{
    /**
     * @var array<int, string>
     */
    private array $confirmCommands = [
        'konfirmasi',
        'confirm',
        'lanjut',
    ];

    public function __construct(
        private readonly GoogleMapsGeocodingService $geocodingService,
        private readonly ShoppingRouteService $shoppingRouteService,
        private readonly ShoppingPricingService $shoppingPricingService,
        private readonly OrderPaymentService $orderPaymentService,
        private readonly DriverOrderRealtimeService $driverOrderRealtimeService,
        private readonly ChatbotAddressReadinessService $addressReadinessService,
        private readonly ChatbotShoppingItemIntentParser $itemIntentParser,
        private readonly ShoppingMerchantCandidateResolver $merchantCandidateResolver,
    ) {}

    /**
     * @param  array<string, mixed>|null  $nluPayload
     * @return array<string, mixed>
     */
    public function process(User $user, string $message, string $sessionId, ?array $nluPayload = null): array
    {
        $this->assertCustomerCanOrder($user);

        $normalizedMessage = $this->normalizeWhitespace($message);
        if ($this->resolveCommand($normalizedMessage, $nluPayload) === 'confirm') {
            return $this->confirmPendingDraft($user, $sessionId);
        }

        $incomingSeed = $this->buildIncomingDraftSeed($normalizedMessage, $nluPayload);
        $latestSeed = $this->resolveLatestDraftSeed($user, $sessionId);
        $draftSeed = $this->mergeDraftSeed($latestSeed, $incomingSeed);

        return $this->buildDraftPayload($user, $draftSeed);
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
        $this->assertCustomerCanOrder($user);

        if ($target !== 'delivery') {
            throw new ApiException('Target lokasi nitip tidak valid.', 422);
        }

        $draftSeed = $this->mergeDraftSeed(
            $this->resolveLatestDraftSeed($user, $sessionId),
            [
                'delivery' => [
                    'address' => $address,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'source' => 'map_pin',
                    'address_id' => null,
                ],
            ],
        );

        return $this->buildDraftPayload($user, $draftSeed);
    }

    /**
     * @param  array<string, mixed>  $merchantPayload
     * @return array<string, mixed>
     */
    public function applyMerchantPatch(User $user, string $sessionId, array $merchantPayload): array
    {
        $this->assertCustomerCanOrder($user);

        $candidate = $this->merchantCandidateResolver->resolveStandalone($merchantPayload);
        $draftSeed = $this->mergeDraftSeed(
            $this->resolveLatestDraftSeed($user, $sessionId),
            $this->draftSeedFromCandidate($candidate),
        );

        return $this->buildDraftPayload($user, $draftSeed);
    }

    private function assertCustomerCanOrder(User $user): void
    {
        $user->refresh();

        if ($user->role !== 'customer') {
            throw new ApiException('Hanya customer yang dapat membuat order Nitip dari chatbot.', 403);
        }

        if (! $user->is_active || $user->is_blacklisted) {
            throw new ApiException('Akun tidak memenuhi syarat untuk membuat order Nitip.', 403);
        }
    }

    /**
     * @param  array<string, mixed>|null  $nluPayload
     * @return array<string, mixed>
     */
    private function buildIncomingDraftSeed(string $message, ?array $nluPayload): array
    {
        $nluPayload ??= [];
        $paymentMethod = $this->extractPaymentMethod($message)
            ?? $this->normalizePaymentMethodOrNull($nluPayload['payment_method'] ?? null);
        if ($this->isPaymentMethodOnlyMessage($message, $paymentMethod)) {
            return ['payment_method' => $paymentMethod];
        }

        $parsedItemIntents = $this->itemIntentParser->parse($message);
        $seed = [
            'merchant_name' => $this->normalizeOptionalString($nluPayload['merchant'] ?? $nluPayload['resto'] ?? null),
            'items' => $parsedItemIntents === []
                ? $this->normalizeIncomingItems($nluPayload['items'] ?? [])
                : $parsedItemIntents,
            'payment_method' => $paymentMethod,
        ];

        $deliveryAddress = $this->normalizeOptionalString($nluPayload['delivery_address'] ?? null);
        if ($deliveryAddress !== null) {
            $seed['delivery'] = [
                'address' => $deliveryAddress,
                'source' => 'chat_text',
            ];
        }

        if ($seed['items'] === [] && ! $this->isPaymentMethodOnlyMessage($message, $paymentMethod)) {
            $seed['items'] = $this->extractItemsFromMessage($message);
        }

        return array_filter(
            $seed,
            fn (mixed $value): bool => $value !== null && $value !== []
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeIncomingItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->normalizeOptionalString($item['name'] ?? $item['menu'] ?? null);
            if ($name === null) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'quantity' => max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1)),
                'operation' => $this->itemIntentParser->normalizeOperation($item['operation'] ?? null)
                    ?? ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => $this->normalizeOptionalString($item['notes'] ?? null),
                'is_heavy' => false,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractItemsFromMessage(string $message): array
    {
        $message = preg_replace('/\b(di|dari)\s+[\pL\pN\s.&-]+$/iu', '', $message) ?: $message;
        $parts = preg_split('/(?:,|\bdan\b|\+)+/iu', $message) ?: [];
        $items = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) > 80) {
                continue;
            }

            if (preg_match('/(?:(\d+)\s*x?\s*)?([\pL\pN\s.&-]{3,})/u', $part, $match) !== 1) {
                continue;
            }

            $name = trim((string) ($match[2] ?? ''));
            $name = preg_replace('/^(titip|belikan|beli|pesan|mau|tolong)\s+/iu', '', $name) ?: $name;
            $name = trim($name);
            if ($name === '' || in_array(strtolower($name), ['halo', 'hai', 'test', 'tes'], true)) {
                continue;
            }

            $items[] = [
                'name' => $name,
                'quantity' => max(1, (int) ($match[1] ?? 1)),
                'operation' => ChatbotShoppingItemIntentParser::OP_ADD,
                'notes' => null,
                'is_heavy' => false,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeDraftSeed(array $base, array $incoming): array
    {
        $incomingMerchantKey = $this->merchantSeedKey($incoming);
        $baseMerchantKey = $this->merchantSeedKey($base);
        $incomingMerchantName = $this->normalizeOptionalString($incoming['merchant_name'] ?? null);
        $baseMerchantName = $this->normalizeOptionalString($base['merchant_name'] ?? null);
        $sameMerchantName = $incomingMerchantName !== null
            && $baseMerchantName !== null
            && Str::of($incomingMerchantName)->lower()->squish()->toString()
                === Str::of($baseMerchantName)->lower()->squish()->toString();
        $merchantChanged = ! $sameMerchantName
            && $incomingMerchantKey !== null
            && $baseMerchantKey !== null
            && $incomingMerchantKey !== $baseMerchantKey;

        $merged = $merchantChanged ? [] : $base;

        foreach (['merchant_id', 'merchant_name', 'merchant_place'] as $key) {
            if (array_key_exists($key, $incoming) && $incoming[$key] !== null && $incoming[$key] !== '') {
                $merged[$key] = $incoming[$key];
            }
        }

        if (array_key_exists('merchant_place', $incoming) && is_array($incoming['merchant_place'] ?? null)) {
            unset($merged['merchant_id']);
        }

        if (is_array($incoming['delivery'] ?? null)) {
            $merged['delivery'] = array_merge(
                is_array($merged['delivery'] ?? null) ? $merged['delivery'] : [],
                $incoming['delivery'],
            );
        }

        $incomingItems = is_array($incoming['items'] ?? null) ? $incoming['items'] : [];
        if ($incomingItems !== []) {
            $merged['items'] = $this->mergeItems(
                is_array($merged['items'] ?? null) ? $merged['items'] : [],
                $incomingItems,
            );
        }

        if (array_key_exists('payment_method', $incoming)) {
            $paymentMethod = $this->normalizePaymentMethodOrNull($incoming['payment_method']);
            if ($paymentMethod !== null) {
                $merged['payment_method'] = $paymentMethod;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $seed
     */
    private function merchantSeedKey(array $seed): ?string
    {
        if (isset($seed['merchant_id']) && is_numeric($seed['merchant_id']) && (int) $seed['merchant_id'] > 0) {
            return 'restaurant:'.(int) $seed['merchant_id'];
        }

        if (isset($seed['merchant_place']) && is_array($seed['merchant_place'])) {
            $place = $seed['merchant_place'];
            $placeId = $this->normalizeOptionalString($place['place_id'] ?? null);
            if ($placeId !== null) {
                return 'place:'.$placeId;
            }

            $name = $this->normalizeOptionalString($place['name'] ?? null);
            $latitude = $this->nullableCoordinate($place['latitude'] ?? null);
            $longitude = $this->nullableCoordinate($place['longitude'] ?? null);
            if ($name !== null && $latitude !== null && $longitude !== null) {
                return sprintf('external:%s:%0.6f:%0.6f', Str::of($name)->lower()->squish()->toString(), $latitude, $longitude);
            }
        }

        $merchantName = $this->normalizeOptionalString($seed['merchant_name'] ?? null);

        return $merchantName === null ? null : 'name:'.Str::of($merchantName)->lower()->squish()->toString();
    }

    /**
     * @param  array<int, array<string, mixed>>  $baseItems
     * @param  array<int, array<string, mixed>>  $incomingItems
     * @return array<int, array<string, mixed>>
     */
    private function mergeItems(array $baseItems, array $incomingItems): array
    {
        $itemsByName = [];

        foreach ($baseItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->normalizeOptionalString($item['name'] ?? $item['menu_name'] ?? null);
            if ($name === null) {
                continue;
            }

            $key = Str::of($name)->lower()->squish()->toString();
            $itemsByName[$key] = [
                'name' => $name,
                'quantity' => max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1)),
                'notes' => $this->normalizeOptionalString($item['notes'] ?? null),
                'is_heavy' => false,
            ];
        }

        foreach ($incomingItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->normalizeOptionalString($item['name'] ?? $item['menu_name'] ?? null);
            if ($name === null) {
                continue;
            }

            $key = Str::of($name)->lower()->squish()->toString();
            $operation = $this->itemIntentParser->normalizeOperation($item['operation'] ?? null)
                ?? ChatbotShoppingItemIntentParser::OP_ADD;
            if ($operation === ChatbotShoppingItemIntentParser::OP_REMOVE) {
                unset($itemsByName[$key]);

                continue;
            }

            $incomingQty = max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1));
            $currentQty = (int) ($itemsByName[$key]['quantity'] ?? 0);
            $quantity = $operation === ChatbotShoppingItemIntentParser::OP_SET
                ? $incomingQty
                : $currentQty + $incomingQty;

            $itemsByName[$key] = [
                'name' => $name,
                'quantity' => max(1, $quantity),
                'notes' => $this->normalizeOptionalString($item['notes'] ?? null)
                    ?? ($itemsByName[$key]['notes'] ?? null),
                'is_heavy' => false,
            ];
        }

        return array_values($itemsByName);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDraftPayload(User $user, array $draftSeed): array
    {
        $merchantCandidate = $this->resolveMerchantCandidate($draftSeed);
        $merchant = $merchantCandidate?->restaurant;
        $delivery = $this->resolveDelivery($user, $draftSeed);
        $items = $this->resolveItems($merchant, is_array($draftSeed['items'] ?? null) ? $draftSeed['items'] : []);

        $missingFields = [];
        $rejectionReasons = [];

        if ($merchantCandidate === null) {
            $missingFields[] = 'merchant';
            $rejectionReasons[] = 'Merchant/toko belum dipilih.';
        } elseif (! $this->hasUsableCoordinatePair($merchantCandidate->latitude, $merchantCandidate->longitude)) {
            $missingFields[] = 'merchant_location';
            $rejectionReasons[] = 'Koordinat merchant belum lengkap.';
        }

        if ($items === []) {
            $missingFields[] = 'items';
            $rejectionReasons[] = 'Item belanja belum disebutkan.';
        }

        if ($delivery['latitude'] === null || $delivery['longitude'] === null || trim((string) $delivery['address']) === '') {
            $missingFields[] = 'delivery_address';
            $rejectionReasons[] = 'Titik antar belum lengkap.';
        }

        $route = null;
        $pricing = $this->emptyPricing();
        if ($missingFields === [] && $merchantCandidate !== null) {
            $route = $this->shoppingRouteService->calculateForPoints(
                [$this->routePointFromCandidate($merchantCandidate)],
                [
                    'label' => $delivery['address'] ?? 'Titik Antar',
                    'latitude' => $delivery['latitude'] ?? null,
                    'longitude' => $delivery['longitude'] ?? null,
                ]
            );
            $serviceTypeId = $this->resolveServiceTypeId();
            $pricing = $this->shoppingPricingService->calculateForItems(
                $serviceTypeId,
                $items,
                (float) $route['delivery_fee'],
            );
        }

        $ready = $missingFields === [] && $route !== null;
        $nextActions = [];
        $missingDeliveryAddress = in_array('delivery_address', $missingFields, true);
        $needsAddressBook = $missingDeliveryAddress
            && ! $this->addressReadinessService->hasUsableSavedAddress($user);
        if ($needsAddressBook) {
            $nextActions[] = 'OPEN_ADDRESSES';
        }
        if (in_array('merchant', $missingFields, true) || in_array('merchant_location', $missingFields, true)) {
            $nextActions[] = 'OPEN_MERCHANT_PICKER';
        }
        if (($missingDeliveryAddress && ! $needsAddressBook) || $ready) {
            $nextActions[] = 'OPEN_MAP_PICKER_DELIVERY';
        }
        $paymentMethod = $this->normalizePaymentMethodOrNull($draftSeed['payment_method'] ?? null);
        if ($ready && $paymentMethod === null) {
            $nextActions[] = 'SET_PAYMENT_COD';
            $nextActions[] = 'SET_PAYMENT_TRANSFER';
        } elseif ($ready) {
            $nextActions[] = 'CONFIRM_DRAFT';
        }
        $deliveryActionLabel = $ready ? 'Ganti Titik Antar' : 'Pilih Titik Antar';

        $merchantPayload = $merchantCandidate === null ? [
            'id' => null,
            'name' => $this->normalizeOptionalString($draftSeed['merchant_name'] ?? null),
        ] : $this->merchantPayload($merchantCandidate);

        $actionPayloads = [
            'OPEN_ADDRESSES' => [
                'label' => 'Isi Alamat Saya',
            ],
            'OPEN_MERCHANT_PICKER' => [
                'label' => 'Pilih Merchant di Map',
                'query' => $this->normalizeOptionalString($draftSeed['merchant_name'] ?? null),
                'initial_latitude' => $merchantCandidate?->latitude ?? $delivery['latitude'],
                'initial_longitude' => $merchantCandidate?->longitude ?? $delivery['longitude'],
            ],
            'OPEN_MAP_PICKER_DELIVERY' => [
                'target' => 'delivery',
                'label' => $deliveryActionLabel,
                'initial_latitude' => $delivery['latitude'],
                'initial_longitude' => $delivery['longitude'],
            ],
            'CONFIRM_DRAFT' => [
                'label' => 'Konfirmasi Nitip',
                'message' => 'Konfirmasi',
            ],
            'SET_PAYMENT_COD' => [
                'label' => 'COD',
                'message' => 'COD',
            ],
            'SET_PAYMENT_TRANSFER' => [
                'label' => 'QRIS',
                'message' => 'QRIS',
            ],
        ];

        $payload = [
            'intent' => 'shopping_order',
            'legacy_intent' => 'pesan_makanan',
            'service_type' => 'nitip',
            'shopping' => [
                'merchant' => $merchantPayload,
                'delivery' => $delivery,
                'items' => $items,
                'route' => $route,
                'ready_to_confirm' => $ready,
                'payment_method' => $paymentMethod,
            ],
            'delivery' => $delivery,
            'pricing' => $pricing,
            'validation' => [
                'is_valid_order' => $ready,
                'rejection_reasons' => $rejectionReasons,
                'missing_fields' => $missingFields,
                'next_actions' => array_values(array_unique($nextActions)),
            ],
            'action_payloads' => $this->activeActionPayloads($actionPayloads, $nextActions),
            'order' => [
                'created' => false,
                'id' => null,
                'order_number' => null,
                'payment_method' => $paymentMethod,
            ],
        ];

        $payload['assistant_text'] = $this->buildAssistantText($payload, (string) $user->name);

        return $payload;
    }

    /**
     * @param  array<string, array<string, mixed>>  $payloads
     * @param  array<int, string>  $nextActions
     * @return array<string, array<string, mixed>>
     */
    private function activeActionPayloads(array $payloads, array $nextActions): array
    {
        $activeKeys = array_flip(array_values(array_unique($nextActions)));

        return array_intersect_key($payloads, $activeKeys);
    }

    /**
     * @param  array<string, mixed>  $draftSeed
     */
    private function resolveMerchantCandidate(array $draftSeed): ?ShoppingMerchantCandidate
    {
        if (isset($draftSeed['merchant_place']) && is_array($draftSeed['merchant_place'])) {
            return $this->merchantCandidateResolver->resolveStandalone([
                'merchant_place' => $draftSeed['merchant_place'],
            ]);
        }

        if (isset($draftSeed['merchant_id']) && is_numeric($draftSeed['merchant_id'])) {
            return $this->merchantCandidateResolver->resolveStandalone([
                'merchant_id' => (int) $draftSeed['merchant_id'],
            ]);
        }

        $merchantName = $this->normalizeOptionalString($draftSeed['merchant_name'] ?? null);
        if ($merchantName === null) {
            return null;
        }

        $normalized = Str::of($merchantName)->lower()->squish()->toString();
        $slug = Str::slug($merchantName);

        $merchant = Restaurant::query()
            ->where('status', 'active')
            ->where(function (Builder $query) use ($merchantName, $normalized, $slug): void {
                $query
                    ->whereRaw('LOWER(name) = ?', [$normalized])
                    ->orWhere('slug', $slug)
                    ->orWhere('name', 'like', '%'.$merchantName.'%');
            })
            ->orderByRaw('CASE WHEN LOWER(name) = ? THEN 0 ELSE 1 END', [$normalized])
            ->first();

        return $merchant instanceof Restaurant ? ShoppingMerchantCandidate::fromRestaurant($merchant) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function draftSeedFromCandidate(ShoppingMerchantCandidate $candidate): array
    {
        if ($candidate->restaurant instanceof Restaurant) {
            return [
                'merchant_id' => (int) $candidate->restaurant->id,
                'merchant_name' => $candidate->name,
            ];
        }

        return [
            'merchant_id' => null,
            'merchant_name' => $candidate->name,
            'merchant_place' => [
                'place_id' => $candidate->placeId,
                'name' => $candidate->name,
                'address' => $candidate->address,
                'latitude' => $candidate->latitude,
                'longitude' => $candidate->longitude,
                'types' => $candidate->placeTypes,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function routePointFromCandidate(ShoppingMerchantCandidate $candidate): array
    {
        return [
            'label' => $candidate->name,
            'latitude' => $candidate->latitude,
            'longitude' => $candidate->longitude,
        ];
    }

    /**
     * @param  array<string, mixed>  $draftSeed
     * @return array<string, mixed>
     */
    private function resolveDelivery(User $user, array $draftSeed): array
    {
        $delivery = is_array($draftSeed['delivery'] ?? null) ? $draftSeed['delivery'] : [];

        $address = $this->normalizeOptionalString($delivery['address'] ?? null);
        $latitude = $this->nullableCoordinate($delivery['latitude'] ?? null);
        $longitude = $this->nullableCoordinate($delivery['longitude'] ?? null);
        $source = $this->normalizeOptionalString($delivery['source'] ?? null);
        $addressId = isset($delivery['address_id']) && is_numeric($delivery['address_id'])
            ? (int) $delivery['address_id']
            : null;

        if ($address !== null && ($latitude === null || $longitude === null) && $source === 'chat_text') {
            $resolved = $this->geocodingService->resolveAddress($address);
            if ($resolved !== null) {
                $address = $resolved['formatted_address'];
                $latitude = (float) $resolved['latitude'];
                $longitude = (float) $resolved['longitude'];
            }
        }

        if ($address !== null && $this->hasUsableCoordinatePair($latitude, $longitude)) {
            return [
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'source' => $source ?: 'chat_text',
                'address_id' => $addressId,
            ];
        }

        $defaultAddress = $this->addressReadinessService->resolveDefaultUsableAddress($user);

        if (! $defaultAddress) {
            return [
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'source' => $source,
                'address_id' => $addressId,
            ];
        }

        $defaultFullAddress = trim((string) $defaultAddress->full_address);

        return [
            'address' => $defaultFullAddress,
            'latitude' => (float) $defaultAddress->latitude,
            'longitude' => (float) $defaultAddress->longitude,
            'source' => 'default_address',
            'address_id' => (int) $defaultAddress->id,
        ];
    }

    private function hasUsableCoordinatePair(?float $latitude, ?float $longitude): bool
    {
        return $latitude !== null
            && $longitude !== null
            && $latitude >= -90
            && $latitude <= 90
            && $longitude >= -180
            && $longitude <= 180
            && ! ($latitude == 0.0 && $longitude == 0.0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemSeeds
     * @return array<int, array<string, mixed>>
     */
    private function resolveItems(?Restaurant $merchant, array $itemSeeds): array
    {
        if ($itemSeeds === []) {
            return [];
        }

        $menusByName = $merchant === null
            ? collect()
            : $merchant->menus()
                ->where('is_available', true)
                ->get(['id', 'name', 'price'])
                ->keyBy(fn ($menu): string => Str::of((string) $menu->name)->lower()->squish()->toString());

        $items = [];
        foreach ($itemSeeds as $seed) {
            $name = $this->normalizeOptionalString($seed['name'] ?? $seed['menu_name'] ?? null);
            if ($name === null) {
                continue;
            }

            $quantity = max(1, (int) ($seed['quantity'] ?? 1));
            $notes = $this->normalizeOptionalString($seed['notes'] ?? null);
            $menu = $menusByName->get(Str::of($name)->lower()->squish()->toString());

            if ($menu !== null) {
                $unitPrice = round((float) $menu->price, 2);
                $menuName = (string) $menu->name;

                $items[] = [
                    'menu_id' => (int) $menu->id,
                    'item_source' => 'MENU_DB',
                    'name' => $menuName,
                    'menu_name' => $menuName,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => round($unitPrice * $quantity, 2),
                    'notes' => $notes,
                    'is_available' => true,
                    'is_heavy' => false,
                    'metadata' => [
                        'price_status' => 'CONFIRMED',
                        'source' => 'CHATBOT_MENU_MATCH',
                    ],
                ];

                continue;
            }

            $items[] = [
                'menu_id' => null,
                'item_source' => 'MANUAL',
                'name' => $name,
                'menu_name' => $name,
                'quantity' => $quantity,
                'unit_price' => 0,
                'subtotal' => 0,
                'notes' => $notes,
                'is_available' => true,
                'is_heavy' => false,
                'metadata' => [
                    'price_status' => 'PENDING_DRIVER_INPUT',
                    'source' => 'CHATBOT_MANUAL_CONTEXT',
                ],
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmPendingDraft(User $user, string $sessionId): array
    {
        $pendingPayload = $this->resolvePendingDraftPayload($user, $sessionId);
        if ($pendingPayload === null) {
            return [
                'intent' => 'shopping_order',
                'service_type' => 'nitip',
                'shopping' => [
                    'merchant' => null,
                    'delivery' => null,
                    'items' => [],
                    'ready_to_confirm' => false,
                ],
                'pricing' => $this->emptyPricing(),
                'validation' => [
                    'is_valid_order' => false,
                    'rejection_reasons' => ['Draft Nitip belum lengkap.'],
                    'missing_fields' => ['draft'],
                    'next_actions' => ['OPEN_MAP_PICKER_DELIVERY'],
                ],
                'order' => [
                    'created' => false,
                    'id' => null,
                    'order_number' => null,
                ],
                'assistant_text' => 'Draft Nitip belum lengkap. Tulis merchant dan itemnya dulu, lalu pilih titik antar.',
            ];
        }

        $shopping = $pendingPayload['shopping'];
        $merchantPayload = $shopping['merchant'];
        $delivery = $shopping['delivery'];
        $items = $shopping['items'];
        $pricing = $pendingPayload['pricing'];
        $route = is_array($shopping['route'] ?? null) ? $shopping['route'] : [];
        $paymentMethod = $this->normalizePaymentMethodOrNull($shopping['payment_method'] ?? ($pendingPayload['order']['payment_method'] ?? null));

        if ($paymentMethod === null) {
            $pendingPayload['validation']['next_actions'] = ['SET_PAYMENT_COD', 'SET_PAYMENT_TRANSFER'];
            $pendingPayload['action_payloads'] = $this->activeActionPayloads([
                'SET_PAYMENT_COD' => [
                    'label' => 'COD',
                    'message' => 'COD',
                ],
                'SET_PAYMENT_TRANSFER' => [
                    'label' => 'QRIS',
                    'message' => 'QRIS',
                ],
            ], $pendingPayload['validation']['next_actions']);
            $pendingPayload['assistant_text'] = $this->buildAssistantText($pendingPayload, (string) $user->name)
                ."\n\nPilih COD atau QRIS dulu sebelum konfirmasi.";

            return $pendingPayload;
        }

        $candidate = $this->resolveMerchantCandidate($this->draftSeedFromMerchantPayload($merchantPayload));
        if (! $candidate instanceof ShoppingMerchantCandidate) {
            throw new ApiException('Merchant draft tidak ditemukan.', 404);
        }

        $serviceTypeId = $this->resolveServiceTypeId();
        $pendingStatusId = $this->resolveStatusId('PENDING');
        $routeSnapshot = $route === []
            ? null
            : [
                ...$route,
                'delivery_fee' => round((float) ($pricing['delivery_fee'] ?? $route['delivery_fee'] ?? 0), 2),
            ];

        $order = DB::transaction(function () use (
            $user,
            $candidate,
            $delivery,
            $items,
            $pricing,
            $serviceTypeId,
            $pendingStatusId,
            $routeSnapshot,
            $paymentMethod,
        ): Order {
            $order = Order::query()->create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'service_type_id' => $serviceTypeId,
                'subtotal' => round((float) ($pricing['subtotal'] ?? 0), 2),
                'delivery_fee' => round((float) ($pricing['delivery_fee'] ?? 0), 2),
                'service_fee' => round((float) ($pricing['service_fee'] ?? 0), 2),
                'route_snapshot' => $routeSnapshot,
                'total_price' => round((float) ($pricing['total_price'] ?? 0), 2),
                'status_id' => $pendingStatusId,
            ]);

            $pickupLocation = $order->orderLocations()->create([
                'restaurant_id' => $candidate->restaurant instanceof Restaurant ? (int) $candidate->restaurant->id : null,
                'location_role' => 'PICKUP',
                'label' => 'Merchant',
                'contact_name' => $candidate->name,
                'contact_phone' => $candidate->restaurant instanceof Restaurant ? $candidate->restaurant->phone : null,
                'full_address' => $candidate->address,
                'latitude' => $candidate->latitude,
                'longitude' => $candidate->longitude,
                'sequence_no' => 1,
            ]);

            if ($routeSnapshot !== null) {
                $routeSnapshot = [
                    ...$routeSnapshot,
                    'ordered_pickup_location_ids' => [(int) $pickupLocation->id],
                ];
                $order->update(['route_snapshot' => $routeSnapshot]);
            }

            $order->orderLocations()->create([
                'location_role' => 'DROPOFF',
                'label' => 'Titik Antar',
                'contact_name' => $user->name,
                'contact_phone' => $user->phone,
                'full_address' => (string) ($delivery['address'] ?? ''),
                'latitude' => (float) ($delivery['latitude'] ?? 0),
                'longitude' => (float) ($delivery['longitude'] ?? 0),
                'sequence_no' => 2,
            ]);

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);
                $subtotal = array_key_exists('subtotal', $item)
                    ? round((float) $item['subtotal'], 2)
                    : round($unitPrice * $quantity, 2);
                $itemSource = strtoupper((string) ($item['item_source'] ?? 'MANUAL')) === 'MENU_DB'
                    ? 'MENU_DB'
                    : 'MANUAL';
                $metadata = is_array($item['metadata'] ?? null)
                    ? $item['metadata']
                    : [
                        'price_status' => $itemSource === 'MENU_DB' ? 'CONFIRMED' : 'PENDING_DRIVER_INPUT',
                        'source' => $itemSource === 'MENU_DB' ? 'CHATBOT_MENU_MATCH' : 'CHATBOT_MANUAL_CONTEXT',
                    ];
                $metadata = [
                    ...$candidate->metadata(),
                    ...$metadata,
                ];

                $order->items()->create([
                    'menu_id' => $itemSource === 'MENU_DB' ? ($item['menu_id'] ?? null) : null,
                    'pickup_location_id' => $pickupLocation->id,
                    'item_source' => $itemSource,
                    'menu_name' => (string) ($item['menu_name'] ?? $item['name'] ?? 'Item belanja'),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'notes' => $item['notes'] ?? null,
                    'metadata' => $metadata,
                    'is_available' => (bool) ($item['is_available'] ?? true),
                    'is_heavy' => (bool) ($item['is_heavy'] ?? false),
                ]);
            }

            $this->shoppingPricingService->syncFeeLines($order, $pricing['fee_breakdown'] ?? []);

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $pendingStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $user->id,
                'note' => 'Order Nitip dibuat melalui chatbot.',
            ]);

            $this->orderPaymentService->ensurePendingPayment($order, $paymentMethod);

            return $order->fresh([
                'restaurant',
                'orderLocations.restaurant',
                'items',
                'payments',
                'statusRef',
                'statusHistories.statusRef',
                'serviceType',
                'feeLines',

                'shoppingReceipt',
            ]);
        });

        $this->driverOrderRealtimeService->broadcastOrderAvailable($order);

        $pendingPayload['shopping']['ready_to_confirm'] = false;
        $pendingPayload['validation']['is_valid_order'] = true;
        $pendingPayload['validation']['next_actions'] = ['OPEN_TRACK_ORDER'];
        $pendingPayload['order'] = [
            'created' => true,
            'id' => (int) $order->id,
            'order_number' => $order->order_number,
        ];
        $deliveryFee = number_format((float) data_get($pendingPayload, 'pricing.delivery_fee', $order->delivery_fee), 0, ',', '.');
        $pendingPayload['assistant_text'] = "Order Nitip berhasil dibuat.\nEstimasi ongkir sementara: Rp {$deliveryFee}.\nDriver akan segera mengambil order ini.";

        return $pendingPayload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePendingDraftPayload(User $user, string $sessionId): ?array
    {
        $log = AiChatLog::query()
            ->with('aiDetail')
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->where('role', 'assistant')
            ->latest('id')
            ->get()
            ->first(function (AiChatLog $log): bool {
                $payload = $log->ai_response;
                if (! is_array($payload)) {
                    return false;
                }

                return ($payload['intent'] ?? null) === 'shopping_order'
                    && (bool) data_get($payload, 'shopping.ready_to_confirm') === true
                    && (bool) data_get($payload, 'order.created') !== true;
            });

        return is_array($log?->ai_response) ? $log->ai_response : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLatestDraftSeed(User $user, string $sessionId): array
    {
        $log = AiChatLog::query()
            ->with('aiDetail')
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        $payload = $log?->ai_response;
        if (! is_array($payload) || ! is_array($payload['shopping'] ?? null)) {
            return [];
        }

        if ((bool) data_get($payload, 'order.created') === true) {
            return [];
        }

        $shopping = $payload['shopping'];
        $merchant = is_array($shopping['merchant'] ?? null) ? $shopping['merchant'] : [];
        $delivery = is_array($shopping['delivery'] ?? null) ? $shopping['delivery'] : [];
        $items = is_array($shopping['items'] ?? null) ? $shopping['items'] : [];

        return [
            'merchant_id' => $merchant['id'] ?? null,
            'merchant_name' => $merchant['name'] ?? null,
            'merchant_place' => is_array($merchant['merchant_place'] ?? null) ? $merchant['merchant_place'] : null,
            'delivery' => $delivery,
            'items' => array_map(fn ($item): array => [
                'name' => (string) ($item['name'] ?? $item['menu_name'] ?? ''),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'notes' => $item['notes'] ?? null,
                'is_heavy' => false,
            ], $items),
            'payment_method' => $this->normalizePaymentMethodOrNull($shopping['payment_method'] ?? ($payload['order']['payment_method'] ?? null)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function draftSeedFromMerchantPayload(array $merchantPayload): array
    {
        if (isset($merchantPayload['id']) && is_numeric($merchantPayload['id']) && (int) $merchantPayload['id'] > 0) {
            return [
                'merchant_id' => (int) $merchantPayload['id'],
                'merchant_name' => $this->normalizeOptionalString($merchantPayload['name'] ?? null),
            ];
        }

        if (isset($merchantPayload['merchant_place']) && is_array($merchantPayload['merchant_place'])) {
            return [
                'merchant_id' => null,
                'merchant_name' => $this->normalizeOptionalString($merchantPayload['name'] ?? $merchantPayload['merchant_place']['name'] ?? null),
                'merchant_place' => $merchantPayload['merchant_place'],
            ];
        }

        return [
            'merchant_name' => $this->normalizeOptionalString($merchantPayload['name'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function merchantPayload(ShoppingMerchantCandidate $candidate): array
    {
        if ($candidate->restaurant instanceof Restaurant) {
            $merchant = $candidate->restaurant;

            return [
                'id' => (int) $merchant->id,
                'name' => $merchant->name,
                'merchant_type' => $merchant->merchant_type,
                'address' => $merchant->address,
                'phone' => $merchant->phone,
                'latitude' => $this->nullableCoordinate($merchant->latitude),
                'longitude' => $this->nullableCoordinate($merchant->longitude),
            ];
        }

        return [
            'id' => null,
            'name' => $candidate->name,
            'merchant_type' => $candidate->merchantType(),
            'address' => $candidate->address,
            'phone' => null,
            'latitude' => $candidate->latitude,
            'longitude' => $candidate->longitude,
            'merchant_place' => [
                'place_id' => $candidate->placeId,
                'name' => $candidate->name,
                'address' => $candidate->address,
                'latitude' => $candidate->latitude,
                'longitude' => $candidate->longitude,
                'types' => $candidate->placeTypes,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPricing(): array
    {
        return [
            'subtotal' => 0,
            'delivery_fee' => 0,
            'service_fee' => 0,
            'total_price' => 0,
            'item_surcharge' => 0,
            'overweight_surcharge' => 0,
            'cancellation_penalty' => 0,
            'item_count' => 0,
            'has_overweight_item' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildAssistantText(array $payload, string $userName): string
    {
        $shopping = is_array($payload['shopping'] ?? null) ? $payload['shopping'] : [];
        $merchant = is_array($shopping['merchant'] ?? null) ? $shopping['merchant'] : [];
        $delivery = is_array($shopping['delivery'] ?? null) ? $shopping['delivery'] : [];
        $items = is_array($shopping['items'] ?? null) ? $shopping['items'] : [];
        $validation = is_array($payload['validation'] ?? null) ? $payload['validation'] : [];

        if (($validation['is_valid_order'] ?? false) !== true) {
            return $this->buildIncompleteDraftText($validation, $merchant);
        }

        $name = trim($userName) === '' ? 'Kak' : trim($userName);

        $lines = [
            "Baik {$name}, saya sudah siapkan draft Nitip.",
            '',
            'Merchant',
            (string) ($merchant['name'] ?? '-'),
            '',
            'Alamat antar',
            (string) ($delivery['address'] ?? '-'),
            '',
            'Daftar belanja',
        ];
        $itemNumber = 1;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $priceText = ((string) ($item['item_source'] ?? '')) === 'MANUAL'
                ? 'harga menyusul dari nota'
                : 'Rp'.number_format((float) ($item['unit_price'] ?? 0), 0, ',', '.');
            $lines[] = $itemNumber.'. '.max(1, (int) ($item['quantity'] ?? 1)).'x '.(string) ($item['name'] ?? $item['menu_name'] ?? 'Item').' ('.$priceText.')';
            $itemNumber++;
        }
        $lines[] = '';
        $lines[] = 'Estimasi ongkir sementara: Rp '.number_format((float) data_get($payload, 'pricing.delivery_fee', 0), 0, ',', '.');
        $lines[] = 'Estimasi total sementara: Rp '.number_format((float) data_get($payload, 'pricing.total_price', 0), 0, ',', '.');
        $paymentMethod = $this->normalizePaymentMethodOrNull(data_get($payload, 'shopping.payment_method'));
        $lines[] = $paymentMethod === null
            ? 'Metode pembayaran: pilih COD atau QRIS.'
            : 'Metode pembayaran: '.$this->paymentMethodLabel($paymentMethod).'.';
        $lines[] = '';
        $lines[] = 'Ketik "konfirmasi" kalau sudah oke.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $validation
     * @param  array<string, mixed>  $merchant
     */
    private function buildIncompleteDraftText(array $validation, array $merchant): string
    {
        $missingFields = array_values(array_filter(array_map(
            static fn (mixed $field): string => trim((string) $field),
            is_array($validation['missing_fields'] ?? null) ? $validation['missing_fields'] : []
        )));
        $missing = implode(', ', $missingFields);
        $baseText = 'Draft Nitip belum lengkap. Lengkapi: '.($missing === '' ? 'draft' : $missing).'.';
        $merchantName = trim((string) ($merchant['name'] ?? ''));

        if ($merchantName === '' || ! in_array('items', $missingFields, true)) {
            return $baseText;
        }

        return implode("\n", [
            $baseText,
            '',
            'Merchant',
            $merchantName,
            '',
            'Contoh: Beli di '.$merchantName.':',
            '- ayam geprek 2',
            '- es teh 1',
        ]);
    }

    private function resolveCommand(string $message, ?array $nluPayload): string
    {
        $command = strtolower(trim((string) ($nluPayload['command'] ?? '')));
        if (in_array($command, ['confirm', 'konfirmasi', 'lanjut'], true)) {
            return 'confirm';
        }

        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $message)));

        return in_array($normalized, $this->confirmCommands, true) ? 'confirm' : 'none';
    }

    private function extractPaymentMethod(string $message): ?string
    {
        $normalized = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $message)));
        $normalized = $this->normalizeWhitespace($normalized);
        if (preg_match('/\b(?:transfer|tf|bank|qris|non tunai|nontunai)\b/u', $normalized) === 1) {
            return OrderPaymentService::METHOD_TRANSFER;
        }

        if (preg_match('/\b(?:cod|cash|tunai)\b/u', $normalized) === 1) {
            return OrderPaymentService::METHOD_COD;
        }

        return null;
    }

    private function normalizePaymentMethodOrNull(mixed $value): ?string
    {
        $normalized = strtoupper(trim((string) ($value ?? '')));
        if ($normalized === OrderPaymentService::METHOD_TRANSFER) {
            return OrderPaymentService::METHOD_TRANSFER;
        }
        if ($normalized === OrderPaymentService::METHOD_COD) {
            return OrderPaymentService::METHOD_COD;
        }

        return null;
    }

    private function paymentMethodLabel(mixed $value): string
    {
        return $this->normalizePaymentMethodOrNull($value) === OrderPaymentService::METHOD_TRANSFER
            ? 'QRIS'
            : 'COD';
    }

    private function isPaymentMethodOnlyMessage(string $message, ?string $paymentMethod): bool
    {
        if ($paymentMethod === null) {
            return false;
        }

        $normalized = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $message)));
        $normalized = $this->normalizeWhitespace($normalized);

        return in_array($normalized, [
            'cod',
            'cash',
            'tunai',
            'transfer',
            'tf',
            'bank',
            'qris',
            'non tunai',
            'nontunai',
        ], true);
    }

    private function resolveServiceTypeId(): int
    {
        $id = ServiceType::query()->where('code', 'SHOPPING')->value('id');
        if (! $id) {
            throw new ApiException('Konfigurasi service type SHOPPING belum tersedia.', 500);
        }

        return (int) $id;
    }

    private function resolveStatusId(string $code): int
    {
        $id = OrderStatus::query()->where('code', $code)->value('id');
        if (! $id) {
            throw new ApiException('Konfigurasi status order belum lengkap.', 500);
        }

        return (int) $id;
    }

    private function generateOrderNumber(): string
    {
        do {
            $candidate = 'BD-'.now()->format('ymd').'-'.random_int(1000, 9999);
        } while (Order::query()->where('order_number', $candidate)->exists());

        return $candidate;
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
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
        return is_numeric($value) ? (float) $value : null;
    }
}
