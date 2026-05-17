<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AiChatLog;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
use App\Models\ServiceType;
use App\Models\ShoppingOrder;
use App\Models\User;
use Carbon\Carbon;
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

    private function assertCustomerCanOrder(User $user): void
    {
        $user->refresh();

        if ($user->role !== 'customer') {
            throw new ApiException('Hanya customer yang dapat membuat order titip belanja dari chatbot.', 403);
        }

        if (! $user->is_active || $user->is_blacklisted) {
            throw new ApiException('Akun tidak memenuhi syarat untuk membuat order titip belanja.', 403);
        }
    }

    /**
     * @param  array<string, mixed>|null  $nluPayload
     * @return array<string, mixed>
     */
    private function buildIncomingDraftSeed(string $message, ?array $nluPayload): array
    {
        $seed = [
            'merchant_name' => $this->normalizeOptionalString($nluPayload['merchant'] ?? $nluPayload['resto'] ?? null),
            'items' => $this->normalizeIncomingItems($nluPayload['items'] ?? []),
        ];

        $deliveryAddress = $this->normalizeOptionalString($nluPayload['delivery_address'] ?? null);
        if ($deliveryAddress !== null) {
            $seed['delivery'] = [
                'address' => $deliveryAddress,
                'source' => 'chat_text',
            ];
        }

        if ($seed['items'] === []) {
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
        $merchantChanged = isset($incoming['merchant_name']) &&
            strtolower((string) ($incoming['merchant_name'] ?? '')) !== strtolower((string) ($base['merchant_name'] ?? ''));

        $merged = $merchantChanged ? [] : $base;

        foreach (['merchant_id', 'merchant_name'] as $key) {
            if (array_key_exists($key, $incoming) && $incoming[$key] !== null && $incoming[$key] !== '') {
                $merged[$key] = $incoming[$key];
            }
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

        return $merged;
    }

    /**
     * @param  array<int, array<string, mixed>>  $baseItems
     * @param  array<int, array<string, mixed>>  $incomingItems
     * @return array<int, array<string, mixed>>
     */
    private function mergeItems(array $baseItems, array $incomingItems): array
    {
        $itemsByName = [];

        foreach ([...$baseItems, ...$incomingItems] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->normalizeOptionalString($item['name'] ?? $item['menu_name'] ?? null);
            if ($name === null) {
                continue;
            }

            $key = Str::of($name)->lower()->squish()->toString();
            $currentQty = (int) ($itemsByName[$key]['quantity'] ?? 0);
            $itemsByName[$key] = [
                'name' => $name,
                'quantity' => max(1, $currentQty + max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1))),
                'notes' => $this->normalizeOptionalString($item['notes'] ?? null),
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
        $merchant = $this->resolveMerchant($draftSeed);
        $delivery = $this->resolveDelivery($user, $draftSeed);
        $items = $this->resolveItems($merchant, is_array($draftSeed['items'] ?? null) ? $draftSeed['items'] : []);

        $missingFields = [];
        $rejectionReasons = [];

        if ($merchant === null) {
            $missingFields[] = 'merchant';
            $rejectionReasons[] = 'Merchant/toko belum ditemukan di database.';
        } elseif ($merchant->latitude === null || $merchant->longitude === null) {
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
        if ($missingFields === [] && $merchant !== null) {
            $route = $this->shoppingRouteService->calculateForMerchantAndDelivery($merchant, $delivery);
            $serviceTypeId = $this->resolveServiceTypeId();
            $pricing = $this->shoppingPricingService->calculateForItems(
                $serviceTypeId,
                $items,
                (float) $route['delivery_fee'],
            );
        }

        $ready = $missingFields === [] && $route !== null;
        $nextActions = [];
        $needsAddressBook = in_array('delivery_address', $missingFields, true)
            && ! $this->addressReadinessService->hasUsableSavedAddress($user);
        $nextActions[] = $needsAddressBook ? 'OPEN_ADDRESSES' : 'OPEN_MAP_PICKER_DELIVERY';
        if ($ready) {
            $nextActions[] = 'CONFIRM_DRAFT';
        }

        $merchantPayload = $merchant === null ? [
            'id' => null,
            'name' => $this->normalizeOptionalString($draftSeed['merchant_name'] ?? null),
        ] : $this->merchantPayload($merchant);

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
            ],
            'delivery' => $delivery,
            'pricing' => $pricing,
            'validation' => [
                'is_valid_order' => $ready,
                'rejection_reasons' => $rejectionReasons,
                'missing_fields' => $missingFields,
                'next_actions' => array_values(array_unique($nextActions)),
            ],
            'action_payloads' => [
                'OPEN_ADDRESSES' => [
                    'label' => 'Isi Alamat Saya',
                ],
                'OPEN_MAP_PICKER_DELIVERY' => [
                    'target' => 'delivery',
                    'label' => 'Pilih Titik Antar',
                    'initial_latitude' => $delivery['latitude'],
                    'initial_longitude' => $delivery['longitude'],
                ],
                'CONFIRM_DRAFT' => [
                    'label' => 'Konfirmasi Titip Belanja',
                ],
            ],
            'order' => [
                'created' => false,
                'id' => null,
                'order_number' => null,
            ],
        ];

        $payload['assistant_text'] = $this->buildAssistantText($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $draftSeed
     */
    private function resolveMerchant(array $draftSeed): ?Restaurant
    {
        if (isset($draftSeed['merchant_id']) && is_numeric($draftSeed['merchant_id'])) {
            $merchant = Restaurant::query()
                ->where('status', 'active')
                ->where('id', (int) $draftSeed['merchant_id'])
                ->first();
            if ($merchant) {
                return $merchant;
            }
        }

        $merchantName = $this->normalizeOptionalString($draftSeed['merchant_name'] ?? null);
        if ($merchantName === null) {
            return null;
        }

        $normalized = Str::of($merchantName)->lower()->squish()->toString();
        $slug = Str::slug($merchantName);

        return Restaurant::query()
            ->where('status', 'active')
            ->where(function (Builder $query) use ($merchantName, $normalized, $slug): void {
                $query
                    ->whereRaw('LOWER(name) = ?', [$normalized])
                    ->orWhere('slug', $slug)
                    ->orWhere('name', 'like', '%'.$merchantName.'%');
            })
            ->orderByRaw('CASE WHEN LOWER(name) = ? THEN 0 ELSE 1 END', [$normalized])
            ->first();
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

        $defaultFullAddress = trim($defaultAddress->full_address.' '.($defaultAddress->detail ?? ''));

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

        $items = [];
        foreach ($itemSeeds as $seed) {
            $name = $this->normalizeOptionalString($seed['name'] ?? $seed['menu_name'] ?? null);
            if ($name === null) {
                continue;
            }

            $quantity = max(1, (int) ($seed['quantity'] ?? 1));
            $notes = $this->normalizeOptionalString($seed['notes'] ?? null);

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
                    'rejection_reasons' => ['Draft titip belanja belum lengkap.'],
                    'missing_fields' => ['draft'],
                    'next_actions' => ['OPEN_MAP_PICKER_DELIVERY'],
                ],
                'order' => [
                    'created' => false,
                    'id' => null,
                    'order_number' => null,
                ],
                'assistant_text' => 'Draft titip belanja belum lengkap. Tulis merchant dan itemnya dulu, lalu pilih titik antar.',
            ];
        }

        $shopping = $pendingPayload['shopping'];
        $merchantPayload = $shopping['merchant'];
        $delivery = $shopping['delivery'];
        $items = $shopping['items'];
        $pricing = $pendingPayload['pricing'];
        $route = is_array($shopping['route'] ?? null) ? $shopping['route'] : [];

        $merchant = Restaurant::query()
            ->where('status', 'active')
            ->find((int) ($merchantPayload['id'] ?? 0));

        if (! $merchant) {
            throw new ApiException('Merchant draft tidak ditemukan.', 404);
        }

        $serviceTypeId = $this->resolveServiceTypeId();
        $pendingStatusId = $this->resolveStatusId('PENDING');
        $routeMinutes = $this->estimateTravelMinutes((int) ($route['duration_seconds'] ?? 0));
        $routeSnapshot = $route === []
            ? null
            : [
                ...$route,
                'delivery_fee' => round((float) ($pricing['delivery_fee'] ?? $route['delivery_fee'] ?? 0), 2),
            ];

        $order = DB::transaction(function () use (
            $user,
            $merchant,
            $delivery,
            $items,
            $pricing,
            $route,
            $serviceTypeId,
            $pendingStatusId,
            $routeMinutes,
            $routeSnapshot,
        ): Order {
            $order = Order::query()->create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'restaurant_id' => $merchant->id,
                'service_type_id' => $serviceTypeId,
                'subtotal' => round((float) ($pricing['subtotal'] ?? 0), 2),
                'delivery_fee' => round((float) ($pricing['delivery_fee'] ?? 0), 2),
                'service_fee' => round((float) ($pricing['service_fee'] ?? 0), 2),
                'delivery_distance_km' => isset($route['distance_km']) ? round((float) $route['distance_km'], 2) : null,
                'delivery_distance_text' => isset($route['distance_text']) ? (string) $route['distance_text'] : null,
                'route_snapshot' => $routeSnapshot,
                'total_price' => round((float) ($pricing['total_price'] ?? 0), 2),
                'status_id' => $pendingStatusId,
                'estimated_delivery' => Carbon::now()->addMinutes((int) $merchant->estimated_prep_time + $routeMinutes),
            ]);

            $pickupLocation = $order->orderLocations()->create([
                'restaurant_id' => $merchant->id,
                'location_role' => 'PICKUP',
                'label' => 'Merchant',
                'contact_name' => $merchant->name,
                'contact_phone' => $merchant->phone,
                'full_address' => $merchant->address,
                'latitude' => $merchant->latitude,
                'longitude' => $merchant->longitude,
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

                $order->items()->create([
                    'menu_id' => null,
                    'pickup_location_id' => $pickupLocation->id,
                    'item_source' => 'MANUAL',
                    'menu_name' => (string) ($item['menu_name'] ?? $item['name'] ?? 'Item belanja'),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'unit_price' => 0,
                    'subtotal' => 0,
                    'notes' => $item['notes'] ?? null,
                    'metadata' => [
                        'price_status' => 'PENDING_DRIVER_INPUT',
                        'source' => 'CHATBOT_MANUAL_CONTEXT',
                    ],
                    'is_available' => (bool) ($item['is_available'] ?? true),
                    'is_heavy' => false,
                ]);
            }

            ShoppingOrder::query()->create([
                'order_id' => $order->id,
                'failed_attempt_count' => 0,
                'item_surcharge' => round((float) ($pricing['item_surcharge'] ?? 0), 2),
                'overweight_surcharge' => round((float) ($pricing['overweight_surcharge'] ?? 0), 2),
                'cancellation_penalty' => round((float) ($pricing['cancellation_penalty'] ?? 0), 2),
                'has_overweight_item' => (bool) ($pricing['has_overweight_item'] ?? false),
                'recalculation_version' => 0,
                'last_recalculated_at' => now(),
                'pricing_snapshot' => $routeSnapshot === null
                    ? $pricing
                    : [
                        ...$pricing,
                        'shopping_route' => $routeSnapshot,
                    ],
            ]);

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'status_id' => $pendingStatusId,
                'event_type' => 'STATUS_CHANGE',
                'changed_by_user_id' => $user->id,
                'note' => 'Order titip belanja dibuat melalui chatbot.',
            ]);

            $this->orderPaymentService->ensurePendingCodPayment($order);

            return $order->fresh(['restaurant', 'orderLocations.restaurant', 'items', 'payments', 'statusRef', 'statusHistories.statusRef', 'shoppingOrder', 'serviceType']);
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
        $pendingPayload['assistant_text'] = 'Order titip belanja berhasil dibuat. Driver akan segera mengambil order ini.';

        return $pendingPayload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePendingDraftPayload(User $user, string $sessionId): ?array
    {
        $log = AiChatLog::query()
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
            'delivery' => $delivery,
            'items' => array_map(fn ($item): array => [
                'name' => (string) ($item['name'] ?? $item['menu_name'] ?? ''),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'notes' => $item['notes'] ?? null,
                'is_heavy' => false,
            ], $items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function merchantPayload(Restaurant $merchant): array
    {
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
    private function buildAssistantText(array $payload): string
    {
        $shopping = is_array($payload['shopping'] ?? null) ? $payload['shopping'] : [];
        $merchant = is_array($shopping['merchant'] ?? null) ? $shopping['merchant'] : [];
        $delivery = is_array($shopping['delivery'] ?? null) ? $shopping['delivery'] : [];
        $items = is_array($shopping['items'] ?? null) ? $shopping['items'] : [];
        $validation = is_array($payload['validation'] ?? null) ? $payload['validation'] : [];

        if (($validation['is_valid_order'] ?? false) !== true) {
            $missing = implode(', ', $validation['missing_fields'] ?? []);

            return 'Draft titip belanja belum lengkap. Lengkapi: '.$missing.'.';
        }

        $lines = ['Siap, draft titip belanja sudah lengkap:'];
        $lines[] = 'Merchant: '.(string) ($merchant['name'] ?? '-');
        $lines[] = 'Antar ke: '.(string) ($delivery['address'] ?? '-');
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $priceText = ((string) ($item['item_source'] ?? '')) === 'MANUAL'
                ? 'harga menyusul dari nota'
                : 'Rp'.number_format((float) ($item['unit_price'] ?? 0), 0, ',', '.');
            $lines[] = '- '.max(1, (int) ($item['quantity'] ?? 1)).'x '.(string) ($item['name'] ?? $item['menu_name'] ?? 'Item').' ('.$priceText.')';
        }
        $lines[] = 'Estimasi total sementara: Rp'.number_format((float) data_get($payload, 'pricing.total_price', 0), 0, ',', '.');
        $lines[] = 'Ketik "konfirmasi" kalau sudah oke.';

        return implode("\n", $lines);
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

    private function estimateTravelMinutes(int $durationSeconds): int
    {
        $minutes = (int) ceil(max(0, $durationSeconds) / 60);

        return max(10, min(120, $minutes));
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
