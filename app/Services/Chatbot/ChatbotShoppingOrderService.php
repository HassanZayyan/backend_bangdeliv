<?php

namespace App\Services\Chatbot;

use App\Exceptions\ApiException;
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
    private const MAX_MERCHANT_STOPS = 3;

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
        private readonly ChatbotDraftStore $draftStore,
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
    public function applyMerchantPatch(User $user, string $sessionId, array $merchantPayload, string $mode = 'select'): array
    {
        $this->assertCustomerCanOrder($user);

        $candidate = $this->merchantCandidateResolver->resolveStandalone($merchantPayload);
        $incomingSeed = $this->draftSeedFromCandidate($candidate);
        $incomingSeed['merchant_mode'] = $mode === 'add' ? 'add' : 'select';
        $draftSeed = $this->mergeDraftSeed(
            $this->resolveLatestDraftSeed($user, $sessionId),
            $incomingSeed,
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
        if ($this->isAddMerchantCommand($message, $nluPayload)) {
            return ['draft_action' => 'add_merchant'];
        }

        $paymentMethod = $this->extractPaymentMethod($message)
            ?? $this->normalizePaymentMethodOrNull($nluPayload['payment_method'] ?? null);
        if ($this->isPaymentMethodOnlyMessage($message, $paymentMethod)) {
            return ['payment_method' => $paymentMethod];
        }

        $parsedItemIntents = $this->itemIntentParser->parse($message);
        $incomingStops = $parsedItemIntents === []
            ? $this->normalizeIncomingStops($nluPayload['stops'] ?? [])
            : [];
        $seed = [
            'merchant_name' => $this->normalizeOptionalString($nluPayload['merchant'] ?? $nluPayload['resto'] ?? null),
            'items' => $parsedItemIntents === [] && $incomingStops === []
                ? $this->normalizeIncomingItems($nluPayload['items'] ?? [])
                : $parsedItemIntents,
            'payment_method' => $paymentMethod,
        ];
        if ($incomingStops !== []) {
            $seed['stops'] = $incomingStops;
        }

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
        return ChatbotShoppingItemNormalizer::incomingItems($items);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeIncomingStops(mixed $stops): array
    {
        if (! is_array($stops)) {
            return [];
        }

        $normalized = [];
        foreach ($stops as $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $merchantName = $this->normalizeOptionalString(
                $stop['merchant'] ?? $stop['resto'] ?? $stop['merchant_name'] ?? null
            );
            $items = $this->normalizeIncomingItems($stop['items'] ?? []);
            if ($merchantName === null && $items === []) {
                continue;
            }

            $normalized[] = array_filter([
                'merchant_name' => $merchantName,
                'items' => $items,
            ], fn (mixed $value): bool => $value !== null && $value !== []);
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
        $merged = $this->normalizeDraftSeed($base);

        if (is_array($incoming['delivery'] ?? null)) {
            $merged['delivery'] = array_merge(
                is_array($merged['delivery'] ?? null) ? $merged['delivery'] : [],
                $incoming['delivery'],
            );
        }

        if (array_key_exists('payment_method', $incoming)) {
            $paymentMethod = $this->normalizePaymentMethodOrNull($incoming['payment_method']);
            if ($paymentMethod !== null) {
                $merged['payment_method'] = $paymentMethod;
            }
        }

        if (($incoming['draft_action'] ?? null) === 'add_merchant') {
            $merged['draft_action'] = 'add_merchant';
            $merged['active_stop_index'] = max(0, count($merged['stops'] ?? []) - 1);

            return $merged;
        }

        $incomingStops = is_array($incoming['stops'] ?? null) ? $incoming['stops'] : [];
        if ($incomingStops !== []) {
            foreach ($incomingStops as $incomingStop) {
                if (! is_array($incomingStop)) {
                    continue;
                }

                $merged = $this->mergeIncomingStop($merged, $incomingStop, 'auto');
            }

            return $this->syncLegacySeedFields($merged);
        }

        $incomingStop = array_intersect_key($incoming, array_flip([
            'merchant_id',
            'merchant_name',
            'merchant_place',
            'items',
        ]));
        if ($incomingStop !== []) {
            $merged = $this->mergeIncomingStop(
                $merged,
                $incomingStop,
                (string) ($incoming['merchant_mode'] ?? 'auto')
            );
        }

        return $this->syncLegacySeedFields($merged);
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array<string, mixed>
     */
    private function normalizeDraftSeed(array $seed): array
    {
        $stops = [];
        if (is_array($seed['stops'] ?? null)) {
            foreach ($seed['stops'] as $stop) {
                if (! is_array($stop)) {
                    continue;
                }

                $normalized = $this->normalizeStopSeed($stop);
                if ($normalized !== []) {
                    $stops[] = $normalized;
                }
            }
        }

        if ($stops === []) {
            $legacyStop = $this->normalizeStopSeed($seed);
            if ($legacyStop !== []) {
                $stops[] = $legacyStop;
            }
        }

        $activeStopIndex = isset($seed['active_stop_index']) && is_numeric($seed['active_stop_index'])
            ? max(0, (int) $seed['active_stop_index'])
            : max(0, count($stops) - 1);
        if ($stops !== []) {
            $activeStopIndex = min($activeStopIndex, count($stops) - 1);
        }

        return array_filter([
            'stops' => $stops,
            'active_stop_index' => $activeStopIndex,
            'delivery' => is_array($seed['delivery'] ?? null) ? $seed['delivery'] : null,
            'payment_method' => $this->normalizePaymentMethodOrNull($seed['payment_method'] ?? null),
            'draft_action' => $seed['draft_action'] ?? null,
            'merchant_limit_reached' => $seed['merchant_limit_reached'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $seed
     * @return array<string, mixed>
     */
    private function normalizeStopSeed(array $seed): array
    {
        $stop = [];
        foreach (['merchant_id', 'merchant_name', 'merchant_place'] as $key) {
            if (array_key_exists($key, $seed) && $seed[$key] !== null && $seed[$key] !== '') {
                $stop[$key] = $seed[$key];
            }
        }

        if (array_key_exists('merchant_place', $stop) && is_array($stop['merchant_place'] ?? null)) {
            unset($stop['merchant_id']);
        }

        $items = is_array($seed['items'] ?? null) ? $seed['items'] : [];
        if ($items !== []) {
            $stop['items'] = array_values(array_filter(
                $items,
                fn (mixed $item): bool => is_array($item)
                    && $this->normalizeOptionalString($item['name'] ?? $item['menu_name'] ?? null) !== null
            ));
        }

        return $stop;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $incomingStop
     * @return array<string, mixed>
     */
    private function mergeIncomingStop(array $draft, array $incomingStop, string $mode): array
    {
        $incomingStop = $this->normalizeStopSeed($incomingStop);
        if ($incomingStop === []) {
            return $draft;
        }

        $stops = is_array($draft['stops'] ?? null) ? $draft['stops'] : [];
        $incomingMerchantKey = $this->merchantSeedKey($incomingStop);
        $incomingItems = is_array($incomingStop['items'] ?? null) ? $incomingStop['items'] : [];
        $targetIndex = null;
        $ignoreIncomingMerchant = false;

        if ($incomingItems !== [] && $mode === 'auto') {
            foreach ($stops as $index => $stop) {
                if (! is_array($stop)) {
                    continue;
                }

                $hasMerchant = $this->merchantSeedKey($stop) !== null;
                $hasItems = is_array($stop['items'] ?? null) && $stop['items'] !== [];
                if ($hasMerchant && ! $hasItems) {
                    $targetIndex = $index;
                    $ignoreIncomingMerchant = true;
                    break;
                }
            }
        }

        if ($targetIndex === null && $incomingMerchantKey !== null) {
            foreach ($stops as $index => $stop) {
                if (! is_array($stop)) {
                    continue;
                }

                if ($this->sameMerchantSeed($stop, $incomingStop)) {
                    $targetIndex = $index;
                    break;
                }
            }
        }

        if ($targetIndex === null) {
            if ($incomingMerchantKey === null) {
                foreach ($stops as $index => $stop) {
                    if (! is_array($stop)) {
                        continue;
                    }

                    $hasMerchant = $this->merchantSeedKey($stop) !== null;
                    $hasItems = is_array($stop['items'] ?? null) && $stop['items'] !== [];
                    if ($hasMerchant && ! $hasItems) {
                        $targetIndex = $index;
                        break;
                    }
                }
                $targetIndex ??= isset($draft['active_stop_index']) && is_numeric($draft['active_stop_index'])
                    ? min(max(0, (int) $draft['active_stop_index']), max(0, count($stops) - 1))
                    : max(0, count($stops) - 1);
                if ($stops === []) {
                    $stops[] = [];
                    $targetIndex = 0;
                }
            } else {
                $activeIndex = isset($draft['active_stop_index']) && is_numeric($draft['active_stop_index'])
                    ? min(max(0, (int) $draft['active_stop_index']), max(0, count($stops) - 1))
                    : max(0, count($stops) - 1);
                $activeStop = is_array($stops[$activeIndex] ?? null) ? $stops[$activeIndex] : [];
                $activeHasItems = is_array($activeStop['items'] ?? null) && $activeStop['items'] !== [];
                $activeHasMerchant = $this->merchantSeedKey($activeStop) !== null;
                $shouldReplaceActive = $stops === []
                    || $mode === 'select'
                    || (! $activeHasMerchant && ! $activeHasItems)
                    || ($activeHasMerchant && ! $activeHasItems && $mode !== 'add');

                if ($shouldReplaceActive) {
                    if ($stops === []) {
                        $stops[] = [];
                        $activeIndex = 0;
                    }
                    $targetIndex = $activeIndex;
                    $existingItems = is_array($stops[$targetIndex]['items'] ?? null)
                        ? $stops[$targetIndex]['items']
                        : [];
                    $stops[$targetIndex] = array_filter([
                        'merchant_id' => $incomingStop['merchant_id'] ?? null,
                        'merchant_name' => $incomingStop['merchant_name'] ?? null,
                        'merchant_place' => $incomingStop['merchant_place'] ?? null,
                        'items' => $incomingItems === [] ? $existingItems : $incomingItems,
                    ], fn (mixed $value): bool => $value !== null && $value !== []);
                    $draft['stops'] = $stops;
                    $draft['active_stop_index'] = $targetIndex;

                    return $draft;
                }

                if (count($stops) >= self::MAX_MERCHANT_STOPS) {
                    $draft['merchant_limit_reached'] = true;
                    $draft['active_stop_index'] = $activeIndex;

                    return $draft;
                }

                $stops[] = array_filter([
                    'merchant_id' => $incomingStop['merchant_id'] ?? null,
                    'merchant_name' => $incomingStop['merchant_name'] ?? null,
                    'merchant_place' => $incomingStop['merchant_place'] ?? null,
                    'items' => $incomingItems,
                ], fn (mixed $value): bool => $value !== null && $value !== []);
                $targetIndex = count($stops) - 1;
            }
        }

        $targetStop = is_array($stops[$targetIndex] ?? null) ? $stops[$targetIndex] : [];
        if (! $ignoreIncomingMerchant) {
            foreach (['merchant_id', 'merchant_name', 'merchant_place'] as $key) {
                if (array_key_exists($key, $incomingStop) && $incomingStop[$key] !== null && $incomingStop[$key] !== '') {
                    $targetStop[$key] = $incomingStop[$key];
                }
            }
            if (array_key_exists('merchant_place', $incomingStop) && is_array($incomingStop['merchant_place'] ?? null)) {
                unset($targetStop['merchant_id']);
            }
        }

        if ($incomingItems !== []) {
            $targetStop['items'] = $this->mergeItems(
                is_array($targetStop['items'] ?? null) ? $targetStop['items'] : [],
                $incomingItems,
            );
        }

        $stops[$targetIndex] = $targetStop;
        $draft['stops'] = array_values($stops);
        $draft['active_stop_index'] = $targetIndex;
        unset($draft['draft_action']);

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function syncLegacySeedFields(array $draft): array
    {
        $stops = is_array($draft['stops'] ?? null) ? $draft['stops'] : [];
        $activeIndex = isset($draft['active_stop_index']) && is_numeric($draft['active_stop_index'])
            ? min(max(0, (int) $draft['active_stop_index']), max(0, count($stops) - 1))
            : max(0, count($stops) - 1);
        $activeStop = is_array($stops[$activeIndex] ?? null) ? $stops[$activeIndex] : [];

        foreach (['merchant_id', 'merchant_name', 'merchant_place', 'items'] as $key) {
            unset($draft[$key]);
        }
        foreach (['merchant_id', 'merchant_name', 'merchant_place', 'items'] as $key) {
            if (array_key_exists($key, $activeStop)) {
                $draft[$key] = $activeStop[$key];
            }
        }

        return $draft;
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
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function sameMerchantSeed(array $left, array $right): bool
    {
        $leftKey = $this->merchantSeedKey($left);
        $rightKey = $this->merchantSeedKey($right);
        if ($leftKey !== null && $rightKey !== null && $leftKey === $rightKey) {
            return true;
        }

        $leftName = $this->normalizeOptionalString($left['merchant_name'] ?? data_get($left, 'merchant_place.name'));
        $rightName = $this->normalizeOptionalString($right['merchant_name'] ?? data_get($right, 'merchant_place.name'));

        return $leftName !== null
            && $rightName !== null
            && Str::of($leftName)->lower()->squish()->toString()
                === Str::of($rightName)->lower()->squish()->toString();
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
            $normalizedItem = ChatbotShoppingItemNormalizer::draftItem($item);
            if ($normalizedItem === null) {
                continue;
            }

            $name = $normalizedItem['name'];
            $key = ChatbotShoppingItemNormalizer::itemKey($name);
            $itemsByName[$key] = [
                'name' => $name,
                'quantity' => $normalizedItem['quantity'],
                'notes' => $normalizedItem['notes'],
            ];
        }

        foreach ($incomingItems as $item) {
            $normalizedItem = ChatbotShoppingItemNormalizer::draftItem($item, includeOperation: true);
            if ($normalizedItem === null) {
                continue;
            }

            $name = $normalizedItem['name'];
            $key = ChatbotShoppingItemNormalizer::itemKey($name);
            $operation = $normalizedItem['operation'] ?? ChatbotShoppingItemIntentParser::OP_ADD;
            if ($operation === ChatbotShoppingItemIntentParser::OP_REMOVE) {
                unset($itemsByName[$key]);

                continue;
            }

            $incomingQty = $normalizedItem['quantity'];
            $currentQty = (int) ($itemsByName[$key]['quantity'] ?? 0);
            $quantity = $operation === ChatbotShoppingItemIntentParser::OP_SET
                ? $incomingQty
                : $currentQty + $incomingQty;

            $itemsByName[$key] = [
                'name' => $name,
                'quantity' => max(1, $quantity),
                'notes' => $normalizedItem['notes']
                    ?? ($itemsByName[$key]['notes'] ?? null),
            ];
        }

        return array_values($itemsByName);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDraftPayload(User $user, array $draftSeed): array
    {
        $draftSeed = $this->normalizeDraftSeed($draftSeed);
        $delivery = $this->resolveDelivery($user, $draftSeed);
        $stopSeeds = is_array($draftSeed['stops'] ?? null) ? $draftSeed['stops'] : [];
        $activeStopIndex = isset($draftSeed['active_stop_index']) && is_numeric($draftSeed['active_stop_index'])
            ? min(max(0, (int) $draftSeed['active_stop_index']), max(0, count($stopSeeds) - 1))
            : max(0, count($stopSeeds) - 1);
        $resolvedStops = [];
        $routePoints = [];
        $items = [];
        $missingFields = [];
        $rejectionReasons = [];

        foreach ($stopSeeds as $index => $stopSeed) {
            if (! is_array($stopSeed)) {
                continue;
            }

            $candidate = $this->resolveMerchantCandidate($stopSeed);
            $merchant = $candidate?->restaurant;
            $stopItems = $this->resolveItems($merchant, is_array($stopSeed['items'] ?? null) ? $stopSeed['items'] : []);
            $merchantPayload = $candidate === null
                ? [
                    'id' => null,
                    'name' => $this->normalizeOptionalString($stopSeed['merchant_name'] ?? data_get($stopSeed, 'merchant_place.name')),
                    'merchant_place' => is_array($stopSeed['merchant_place'] ?? null) ? $stopSeed['merchant_place'] : null,
                ]
                : $this->merchantPayload($candidate);
            if (! is_array($merchantPayload['merchant_place'] ?? null)) {
                unset($merchantPayload['merchant_place']);
            }
            $stopNumber = $index + 1;

            if ($candidate === null) {
                $missingFields[] = 'merchant';
                $rejectionReasons[] = $stopNumber === 1
                    ? 'Merchant/toko belum dipilih.'
                    : "Merchant ke-{$stopNumber} belum dipilih.";
            } elseif (! $this->hasUsableCoordinatePair($candidate->latitude, $candidate->longitude)) {
                $missingFields[] = 'merchant_location';
                $rejectionReasons[] = "Koordinat {$candidate->name} belum lengkap.";
            } else {
                $routePoints[] = $this->routePointFromCandidate($candidate);
            }

            if ($stopItems === []) {
                $missingFields[] = 'items';
                $merchantName = trim((string) ($merchantPayload['name'] ?? 'merchant ini'));
                $rejectionReasons[] = "Item belanja untuk {$merchantName} belum disebutkan.";
            }

            foreach ($stopItems as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $items[] = [
                    ...$item,
                    'stop_index' => $stopNumber,
                    'merchant_name' => (string) ($merchantPayload['name'] ?? ''),
                ];
            }

            $resolvedStops[] = [
                'index' => $stopNumber,
                'is_active' => $index === $activeStopIndex,
                'merchant' => $merchantPayload,
                'items' => $stopItems,
                'ready' => $candidate !== null
                    && $this->hasUsableCoordinatePair($candidate->latitude, $candidate->longitude)
                    && $stopItems !== [],
            ];
        }

        if ($resolvedStops === []) {
            $missingFields[] = 'merchant';
            $missingFields[] = 'items';
            $rejectionReasons[] = 'Merchant/toko belum dipilih.';
            $rejectionReasons[] = 'Item belanja belum disebutkan.';
        }

        if ($delivery['latitude'] === null || $delivery['longitude'] === null || trim((string) $delivery['address']) === '') {
            $missingFields[] = 'delivery_address';
            $rejectionReasons[] = 'Titik antar belum lengkap.';
        }

        $missingFields = array_values(array_unique($missingFields));
        $rejectionReasons = array_values(array_unique($rejectionReasons));
        $route = null;
        $pricing = $this->emptyPricing();
        $routeFailureNeedsMerchantPicker = false;
        $missingDeliveryAddress = in_array('delivery_address', $missingFields, true);
        $missingMerchantRoutePoint = in_array('merchant', $missingFields, true)
            || in_array('merchant_location', $missingFields, true);
        if (! $missingDeliveryAddress && ! $missingMerchantRoutePoint && $routePoints !== []) {
            try {
                $route = $this->shoppingRouteService->calculateForPoints(
                    $routePoints,
                    [
                        'label' => $delivery['address'] ?? 'Titik Antar',
                        'latitude' => $delivery['latitude'] ?? null,
                        'longitude' => $delivery['longitude'] ?? null,
                    ]
                );
                if ($missingFields === []) {
                    $serviceTypeId = $this->resolveServiceTypeId();
                    $pricing = $this->shoppingPricingService->calculateForItems(
                        $serviceTypeId,
                        $items,
                        (float) $route['delivery_fee'],
                    );
                }
            } catch (ApiException $exception) {
                $message = trim($exception->getMessage());
                $routeFailureNeedsMerchantPicker = data_get($exception->errors(), 'code')
                    === ShoppingRouteService::ERROR_ROUTE_DISTANCE_LIMIT;
                if (! $routeFailureNeedsMerchantPicker) {
                    throw $exception;
                }

                $missingFields = array_values(array_diff($missingFields, ['items']));
                $rejectionReasons = array_values(array_filter(
                    $rejectionReasons,
                    static fn (string $reason): bool => ! str_contains($reason, 'Item belanja')
                ));
                $missingFields[] = 'merchant_distance';
                $rejectionReasons[] = $message === ''
                    ? 'Rute Nitip belum valid.'
                    : $message;
            }
        }
        $missingFields = array_values(array_unique($missingFields));
        $rejectionReasons = array_values(array_unique($rejectionReasons));

        $ready = $missingFields === [] && $route !== null;
        $nextActions = [];
        $needsAddressBook = $missingDeliveryAddress
            && ! $this->addressReadinessService->hasUsableSavedAddress($user);
        if ($needsAddressBook) {
            $nextActions[] = 'OPEN_ADDRESSES';
        }
        $draftActionAddMerchant = ($draftSeed['draft_action'] ?? null) === 'add_merchant';
        $completeStopCount = count(array_filter(
            $resolvedStops,
            static fn (array $stop): bool => ($stop['ready'] ?? false) === true
        ));
        $merchantCount = count(array_filter(
            $resolvedStops,
            static fn (array $stop): bool => trim((string) data_get($stop, 'merchant.name', '')) !== ''
        ));
        $needsMerchantPicker = in_array('merchant', $missingFields, true)
            || in_array('merchant_location', $missingFields, true)
            || $draftActionAddMerchant
            || $routeFailureNeedsMerchantPicker;
        if ($needsMerchantPicker) {
            $nextActions[] = $routeFailureNeedsMerchantPicker
                ? 'OPEN_MERCHANT_PICKER'
                : (($completeStopCount > 0 || $draftActionAddMerchant)
                ? 'OPEN_ADD_MERCHANT_PICKER'
                : 'OPEN_MERCHANT_PICKER');
        } elseif ($ready && $merchantCount < self::MAX_MERCHANT_STOPS) {
            $nextActions[] = 'OPEN_ADD_MERCHANT_PICKER';
        }
        if (($missingDeliveryAddress && ! $needsAddressBook) || $ready || $routeFailureNeedsMerchantPicker) {
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

        $legacyStop = is_array($resolvedStops[0] ?? null) ? $resolvedStops[0] : [];
        $merchantPayload = is_array($legacyStop['merchant'] ?? null)
            ? $legacyStop['merchant']
            : [
                'id' => null,
                'name' => null,
            ];
        $initialLatitude = $delivery['latitude'];
        $initialLongitude = $delivery['longitude'];
        $activeStop = is_array($resolvedStops[$activeStopIndex] ?? null) ? $resolvedStops[$activeStopIndex] : [];
        $activeMerchant = is_array($activeStop['merchant'] ?? null) ? $activeStop['merchant'] : [];
        $activeLatitude = $this->nullableCoordinate($activeMerchant['latitude'] ?? null);
        $activeLongitude = $this->nullableCoordinate($activeMerchant['longitude'] ?? null);
        if ($activeLatitude !== null && $activeLongitude !== null) {
            $initialLatitude = $activeLatitude;
            $initialLongitude = $activeLongitude;
        }

        $actionPayloads = [
            'OPEN_ADDRESSES' => [
                'label' => 'Isi Alamat Saya',
            ],
            'OPEN_MERCHANT_PICKER' => [
                'label' => 'Pilih Merchant di Map',
                'mode' => 'select',
                'query' => $this->normalizeOptionalString($draftSeed['merchant_name'] ?? null),
                'initial_latitude' => $initialLatitude,
                'initial_longitude' => $initialLongitude,
            ],
            'OPEN_ADD_MERCHANT_PICKER' => [
                'label' => 'Tambah Merchant',
                'mode' => 'add',
                'query' => null,
                'initial_latitude' => $initialLatitude,
                'initial_longitude' => $initialLongitude,
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
                'stops' => $resolvedStops,
                'active_stop_index' => $activeStopIndex,
                'merchant_limit' => self::MAX_MERCHANT_STOPS,
                'merchant_limit_reached' => (bool) ($draftSeed['merchant_limit_reached'] ?? false),
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
        $delivery = $shopping['delivery'];
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

        $stopPayloads = is_array($shopping['stops'] ?? null) && $shopping['stops'] !== []
            ? $shopping['stops']
            : [[
                'merchant' => is_array($shopping['merchant'] ?? null) ? $shopping['merchant'] : [],
                'items' => is_array($shopping['items'] ?? null) ? $shopping['items'] : [],
            ]];
        $orderStops = [];
        foreach ($stopPayloads as $stopPayload) {
            if (! is_array($stopPayload)) {
                continue;
            }

            $merchantPayload = is_array($stopPayload['merchant'] ?? null) ? $stopPayload['merchant'] : [];
            $candidate = $this->resolveMerchantCandidate($this->draftSeedFromMerchantPayload($merchantPayload));
            if (! $candidate instanceof ShoppingMerchantCandidate) {
                throw new ApiException('Merchant draft tidak ditemukan.', 404);
            }

            $stopItems = is_array($stopPayload['items'] ?? null) ? $stopPayload['items'] : [];
            if ($stopItems === []) {
                throw new ApiException('Item draft Nitip belum lengkap.', 422);
            }

            $orderStops[] = [
                'candidate' => $candidate,
                'items' => $stopItems,
            ];
        }

        if ($orderStops === []) {
            throw new ApiException('Draft Nitip belum lengkap.', 422);
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
            $orderStops,
            $delivery,
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
                'delivery_fee' => round((float) ($pricing['delivery_fee'] ?? 0), 2),
                'route_snapshot' => $routeSnapshot,
                'total_price' => round((float) ($pricing['total_price'] ?? 0), 2),
                'status_id' => $pendingStatusId,
            ]);

            $pickupLocations = [];
            foreach ($orderStops as $index => $orderStop) {
                /** @var ShoppingMerchantCandidate $candidate */
                $candidate = $orderStop['candidate'];
                $pickupLocations[$index] = $order->orderLocations()->create([
                    'restaurant_id' => $candidate->restaurant instanceof Restaurant ? (int) $candidate->restaurant->id : null,
                    'location_role' => 'PICKUP',
                    'label' => $candidate->name,
                    'full_address' => $candidate->address,
                    'latitude' => $candidate->latitude,
                    'longitude' => $candidate->longitude,
                    'sequence_no' => $index + 1,
                ]);
            }

            if ($routeSnapshot !== null) {
                $routeSnapshot = [
                    ...$routeSnapshot,
                    'ordered_pickup_location_ids' => array_map(
                        static fn ($pickupLocation): int => (int) $pickupLocation->id,
                        $pickupLocations
                    ),
                ];
                $order->update(['route_snapshot' => $routeSnapshot]);
            }

            $order->orderLocations()->create([
                'location_role' => 'DROPOFF',
                'label' => 'Titik Antar',
                'full_address' => (string) ($delivery['address'] ?? ''),
                'latitude' => (float) ($delivery['latitude'] ?? 0),
                'longitude' => (float) ($delivery['longitude'] ?? 0),
                'sequence_no' => count($orderStops) + 1,
            ]);

            foreach ($orderStops as $index => $orderStop) {
                /** @var ShoppingMerchantCandidate $candidate */
                $candidate = $orderStop['candidate'];
                $pickupLocation = $pickupLocations[$index];
                $items = is_array($orderStop['items'] ?? null) ? $orderStop['items'] : [];
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
                    ]);
                }
            }

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
        $payload = $this->draftStore->latestPayload($user, $sessionId);
        if (! is_array($payload)) {
            return null;
        }

        if (
            ($payload['intent'] ?? null) !== 'shopping_order' ||
            (bool) data_get($payload, 'shopping.ready_to_confirm') !== true ||
            (bool) data_get($payload, 'order.created') === true
        ) {
            return null;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLatestDraftSeed(User $user, string $sessionId): array
    {
        $payload = $this->draftStore->latestPayload($user, $sessionId);
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
        $stops = [];
        if (is_array($shopping['stops'] ?? null) && $shopping['stops'] !== []) {
            foreach ($shopping['stops'] as $stop) {
                if (! is_array($stop)) {
                    continue;
                }

                $stopMerchant = is_array($stop['merchant'] ?? null) ? $stop['merchant'] : [];
                $stopItems = is_array($stop['items'] ?? null) ? $stop['items'] : [];
                $stops[] = [
                    'merchant_id' => $stopMerchant['id'] ?? null,
                    'merchant_name' => $stopMerchant['name'] ?? null,
                    'merchant_place' => is_array($stopMerchant['merchant_place'] ?? null) ? $stopMerchant['merchant_place'] : null,
                    'items' => array_map(fn ($item): array => [
                        'name' => (string) ($item['name'] ?? $item['menu_name'] ?? ''),
                        'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                        'notes' => $item['notes'] ?? null,
                    ], $stopItems),
                ];
            }
        }

        return [
            'merchant_id' => $merchant['id'] ?? null,
            'merchant_name' => $merchant['name'] ?? null,
            'merchant_place' => is_array($merchant['merchant_place'] ?? null) ? $merchant['merchant_place'] : null,
            'delivery' => $delivery,
            'stops' => $stops,
            'active_stop_index' => isset($shopping['active_stop_index']) && is_numeric($shopping['active_stop_index'])
                ? (int) $shopping['active_stop_index']
                : max(0, count($stops) - 1),
            'items' => array_map(fn ($item): array => [
                'name' => (string) ($item['name'] ?? $item['menu_name'] ?? ''),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'notes' => $item['notes'] ?? null,
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
        $stops = is_array($shopping['stops'] ?? null) ? $shopping['stops'] : [];
        $validation = is_array($payload['validation'] ?? null) ? $payload['validation'] : [];

        if (($validation['is_valid_order'] ?? false) !== true) {
            return $this->buildIncompleteDraftText($validation, $merchant, $shopping);
        }

        $name = trim($userName) === '' ? 'Kak' : trim($userName);
        if ($stops === []) {
            $stops = [[
                'merchant' => $merchant,
                'items' => $items,
            ]];
        }

        $lines = [
            count($stops) === 1
                ? 'Draft Nitip merchant pertama sudah aman.'
                : "Baik {$name}, saya sudah siapkan draft Nitip multi-merchant.",
            '',
        ];

        foreach ($stops as $stopIndex => $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $stopMerchant = is_array($stop['merchant'] ?? null) ? $stop['merchant'] : [];
            $stopItems = is_array($stop['items'] ?? null) ? $stop['items'] : [];
            $lines[] = count($stops) === 1 ? 'Merchant' : 'Merchant '.($stopIndex + 1);
            $lines[] = (string) ($stopMerchant['name'] ?? '-');
            $lines[] = '';
            $lines[] = 'Daftar belanja';

            $itemNumber = 1;
            foreach ($stopItems as $item) {
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
        }
        $lines[] = 'Alamat antar';
        $lines[] = (string) ($delivery['address'] ?? '-');
        $lines[] = '';
        $lines[] = 'Estimasi ongkir sementara: Rp '.number_format((float) data_get($payload, 'pricing.delivery_fee', 0), 0, ',', '.');
        $lines[] = 'Estimasi total sementara: Rp '.number_format((float) data_get($payload, 'pricing.total_price', 0), 0, ',', '.');
        $paymentMethod = $this->normalizePaymentMethodOrNull(data_get($payload, 'shopping.payment_method'));
        $lines[] = $paymentMethod === null
            ? 'Metode pembayaran: pilih COD atau QRIS.'
            : 'Metode pembayaran: '.$this->paymentMethodLabel($paymentMethod).'.';
        $lines[] = '';
        if ((bool) data_get($payload, 'shopping.merchant_limit_reached', false)) {
            $lines[] = 'Maksimal 3 merchant dalam satu pesanan Nitip. Draft yang sudah ada tetap aman.';
            $lines[] = '';
        } elseif (count($stops) < self::MAX_MERCHANT_STOPS) {
            $lines[] = 'Mau tambah merchant lain? Pilih merchantnya dulu.';
            $lines[] = 'Contoh setelah merchant berikutnya dipilih:';
            $lines[] = '- susu 1';
            $lines[] = '- roti tawar 2';
            $lines[] = '';
        }
        $lines[] = 'Ketik "konfirmasi" kalau sudah oke.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $validation
     * @param  array<string, mixed>  $merchant
     */
    private function buildIncompleteDraftText(array $validation, array $merchant, array $shopping = []): string
    {
        $missingFields = array_values(array_filter(array_map(
            static fn (mixed $field): string => trim((string) $field),
            is_array($validation['missing_fields'] ?? null) ? $validation['missing_fields'] : []
        )));
        $rejectionReasons = array_values(array_filter(array_map(
            static fn (mixed $reason): string => trim((string) $reason),
            is_array($validation['rejection_reasons'] ?? null) ? $validation['rejection_reasons'] : []
        )));
        if ($missingFields === [] && $rejectionReasons !== []) {
            return implode("\n", $rejectionReasons);
        }
        if (in_array('merchant_distance', $missingFields, true) && $rejectionReasons !== []) {
            return implode("\n", $rejectionReasons);
        }

        $missing = implode(', ', $missingFields);
        $baseText = 'Draft Nitip belum lengkap. Lengkapi: '.($missing === '' ? 'draft' : $missing).'.';
        if ((bool) ($shopping['merchant_limit_reached'] ?? false)) {
            return 'Maksimal 3 merchant dalam satu pesanan Nitip. Draft yang sudah ada tetap aman, kamu bisa pilih pembayaran atau konfirmasi.';
        }

        $stops = is_array($shopping['stops'] ?? null) ? $shopping['stops'] : [];
        $completeStopCount = count(array_filter(
            $stops,
            static fn (array $stop): bool => ($stop['ready'] ?? false) === true
        ));
        if (in_array('merchant', $missingFields, true) && $completeStopCount > 0) {
            return implode("\n", [
                'Mau tambah merchant lain? Pilih merchantnya dulu.',
                '',
                'Contoh setelah merchant berikutnya dipilih:',
                '- susu 1',
                '- roti tawar 2',
            ]);
        }

        $activeStop = null;
        foreach ($stops as $stop) {
            if (is_array($stop) && ($stop['is_active'] ?? false) === true) {
                $activeStop = $stop;
                break;
            }
        }
        if ($activeStop === null && $stops !== []) {
            $activeStop = is_array($stops[array_key_last($stops)] ?? null) ? $stops[array_key_last($stops)] : null;
        }
        $activeMerchant = is_array($activeStop['merchant'] ?? null) ? $activeStop['merchant'] : $merchant;
        $merchantName = trim((string) ($merchant['name'] ?? ''));
        $activeMerchantName = trim((string) ($activeMerchant['name'] ?? $merchantName));

        if ($activeMerchantName === '' || ! in_array('items', $missingFields, true)) {
            return $baseText;
        }

        return implode("\n", [
            $baseText,
            '',
            'Merchant',
            $activeMerchantName,
            '',
            'Tulis item dan jumlah untuk merchant ini.',
            'Contoh:',
            '- susu 1',
            '- roti tawar 2',
            '- air mineral 1',
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

    /**
     * @param  array<string, mixed>|null  $nluPayload
     */
    private function isAddMerchantCommand(string $message, ?array $nluPayload = null): bool
    {
        $command = strtolower(trim((string) ($nluPayload['command'] ?? '')));
        if (in_array($command, ['add_merchant', 'tambah_merchant'], true)) {
            return true;
        }

        $normalized = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $message)));
        $normalized = $this->normalizeWhitespace($normalized);

        return preg_match('/\b(?:tambah|nambah|add)\s+(?:merchant|toko|resto|restaurant|warung|minimarket|order)\b/u', $normalized) === 1
            || preg_match('/\border\s+baru\b/u', $normalized) === 1;
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
