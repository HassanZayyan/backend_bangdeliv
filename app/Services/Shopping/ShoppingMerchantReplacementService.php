<?php

namespace App\Services\Shopping;

use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Models\User;
use App\Services\Maps\GooglePlaceDetailsService;
use App\Services\Notification\ShoppingItemAvailabilityPushNotificationService;
use App\Services\Order\DeliveryFeeNegotiationService;
use App\Services\Pricing\ShoppingPricingService;
use Illuminate\Support\Facades\DB;

class ShoppingMerchantReplacementService
{
    public function __construct(
        private readonly ShoppingReplacementProjectionService $projection,
        private readonly ShoppingMerchantCandidateResolver $candidateResolver,
        private readonly ShoppingPickupLocationService $pickupLocations,
        private readonly ShoppingRouteService $routeService,
        private readonly ShoppingPricingService $pricing,
        private readonly ShoppingFailedTripCompensationService $failedTrips,
        private readonly GooglePlaceDetailsService $placeDetails,
        private readonly DeliveryFeeNegotiationService $deliveryFeeNegotiation,
        private readonly ShoppingItemAvailabilityPushNotificationService $availabilityNotifications,
    ) {}

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function preview(User $actor, int $orderId, int $pickupLocationId, array $payload, bool $asDriver): array
    {
        $order = Order::query()
            ->with(['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt', 'driver'])
            ->find($orderId);
        $this->assertParticipant($actor, $order, $asDriver);
        $pickup = $this->pickupLocations->pickupById($order, $pickupLocationId);
        $pickupProjection = $this->assertReplaceable($order, $pickup, (int) $payload['expected_version']);
        $candidatePayload = $this->verifiedCandidatePayload($payload);
        $candidate = $this->candidateResolver->resolve($order, $candidatePayload);
        $this->assertCandidateIsNew($order, $pickup, $candidate);
        $items = $this->normalizeItems($candidate, $payload['items'] ?? []);
        $route = $this->routePreview($order, $pickup, $candidate);
        // Pesanan yang berlanjut (ganti toko/resto) tidak menagih fee: fee
        // hanya muncul saat seluruh toko gagal (pembatalan). Preview cukup
        // menampilkan ongkir aktif.
        $activeDeliveryFee = round((float) ($route['delivery_fee'] ?? $order->delivery_fee), 2);

        return [
            'order_id' => (int) $order->id,
            'pickup_location_id' => (int) $pickup->id,
            'expected_version' => (int) $pickupProjection['state_version'],
            'chain_id' => $pickupProjection['chain_id'],
            'next_attempt_no' => (int) $pickupProjection['chain_attempt_no'] + 1,
            'old_merchant' => $this->merchantSnapshot($pickup),
            'new_merchant' => $this->candidateSnapshot($candidate),
            'items' => $items,
            'active_delivery_fee' => $activeDeliveryFee,
            'total_transport' => $activeDeliveryFee,
            'route' => $route,
            'payload_fingerprint' => $this->fingerprint($candidatePayload, $items),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function commit(
        User $actor,
        int $orderId,
        int $pickupLocationId,
        array $payload,
        string $idempotencyKey,
        bool $asDriver,
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 100) {
            throw new ApiException('Idempotency-Key wajib diisi dan maksimal 100 karakter.', 422);
        }

        $result = DB::transaction(function () use ($actor, $orderId, $pickupLocationId, $payload, $idempotencyKey, $asDriver): array {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt', 'driver'])
                ->lockForUpdate()
                ->find($orderId);
            $this->assertParticipant($actor, $order, $asDriver);

            $candidatePayload = $this->verifiedCandidatePayload($payload);
            $candidate = $this->candidateResolver->resolve($order, $candidatePayload);
            $items = $this->normalizeItems($candidate, $payload['items'] ?? []);
            $fingerprint = $this->fingerprint($candidatePayload, $items);
            $replay = $this->idempotencyEvent($order, $idempotencyKey);
            if ($replay instanceof OrderLog) {
                if ((string) data_get($replay->metadata, 'payload_fingerprint') !== $fingerprint) {
                    throw new ApiException('Idempotency-Key sudah dipakai untuk payload replacement yang berbeda.', 422);
                }

                return [
                    'order_id' => (int) $order->id,
                    'replacement_event_id' => (int) $replay->id,
                    'idempotent_replay' => true,
                ];
            }

            $pickup = OrderLocation::query()
                ->where('order_id', $order->id)
                ->whereKey($pickupLocationId)
                ->lockForUpdate()
                ->first();
            if (! $pickup instanceof OrderLocation || strtoupper((string) $pickup->location_role) !== 'PICKUP') {
                throw new ApiException('Merchant/pickup order tidak valid.', 422);
            }
            $lockedItems = OrderItem::query()->where('order_id', $order->id)->lockForUpdate()->get();
            $order->setRelation('items', $lockedItems);
            $order->unsetRelation('orderLocations');
            $order->load('orderLocations.restaurant');
            $pickup = $order->orderLocations->firstWhere('id', $pickup->id);
            $pickupProjection = $this->assertReplaceable($order, $pickup, (int) $payload['expected_version']);
            $this->assertCandidateIsNew($order, $pickup, $candidate);
            $routePreview = $this->routePreview($order, $pickup, $candidate);

            $failureEvent = $this->failedTripEventForPickup($order, (int) $pickup->id);
            if (! $failureEvent instanceof OrderLog) {
                $pickup->update([
                    'failed_attempt_count' => min(255, (int) ($pickup->failed_attempt_count ?? 0) + 1),
                ]);
                $failureEvent = $this->failedTrips->recordFailure(
                    $order,
                    $pickup,
                    (int) $actor->id,
                    'Merchant diganti karena item tidak tersedia.',
                    'MERCHANT_REPLACEMENT',
                    true,
                );
            }

            $order->unsetRelation('orderLocations');
            $order->load('orderLocations.restaurant');

            $oldItems = $this->itemsForPickup($order, $pickup);
            $oldItemSnapshots = $oldItems->map(fn (OrderItem $item): array => $this->itemSnapshot($item))->values()->all();
            $pickup->update(['fulfillment_status' => 'REPLACED']);

            $replacementPickup = $this->pickupLocations->createForCandidate($order, $candidate);
            $newItemSnapshots = [];
            foreach ($items as $item) {
                $created = $order->items()->create([
                    'pickup_location_id' => (int) $replacementPickup->id,
                    'menu_id' => $item['menu_id'],
                    'item_source' => $item['item_source'],
                    'menu_name' => $item['menu_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => round((float) $item['unit_price'] * (int) $item['quantity'], 2),
                    'notes' => $item['notes'],
                    'is_available' => true,
                    'metadata' => [
                        ...$candidate->metadata(),
                        'price_status' => (float) $item['unit_price'] > 0 ? 'REFERENCE_PRICE' : 'PENDING_DRIVER_INPUT',
                        'replaced_from_pickup_location_id' => (int) $pickup->id,
                    ],
                ]);
                $newItemSnapshots[] = $this->itemSnapshot($created);
            }

            $event = OrderLog::query()->create([
                'order_id' => $order->id,
                'event_type' => ShoppingReplacementProjectionService::REPLACEMENT_EVENT,
                'trigger_type' => $asDriver ? 'DRIVER_REPLACED_SHOPPING_MERCHANT' : 'CUSTOMER_REPLACED_SHOPPING_MERCHANT',
                'changed_by_user_id' => $actor->id,
                'note' => 'Toko/resto Nitip diganti dan item baru dipilih untuk toko/resto pengganti.',
                'metadata' => [
                    'chain_id' => $pickupProjection['chain_id'],
                    'attempt_no' => (int) $pickupProjection['chain_attempt_no'] + 1,
                    'source_pickup_location_id' => (int) $pickup->id,
                    'replacement_pickup_location_id' => (int) $replacementPickup->id,
                    'old_merchant' => $this->merchantSnapshot($pickup),
                    'merchant' => $this->candidateSnapshot($candidate),
                    'old_items' => $oldItemSnapshots,
                    'items' => $newItemSnapshots,
                    'actor_role' => $asDriver ? 'driver' : 'customer',
                    'idempotency_key' => $idempotencyKey,
                    'payload_fingerprint' => $fingerprint,
                    'failed_trip_event_id' => (int) $failureEvent->id,
                    'route_revision' => [
                        'delivery_fee' => round((float) ($routePreview['delivery_fee'] ?? 0), 2),
                        'distance_meters' => (int) ($routePreview['distance_meters'] ?? 0),
                    ],
                ],
            ]);

            $oldItemIds = $oldItems->modelKeys();
            if ($oldItemIds !== []) {
                OrderItem::query()->whereIn('id', $oldItemIds)->delete();
                $order->unsetRelation('items');
            }

            $this->supersedePendingDeliveryFeeProposal($order, (int) $actor->id, (int) $event->id);
            $routeFee = round((float) ($routePreview['delivery_fee'] ?? $order->delivery_fee), 2);
            $oldDeliveryFee = round((float) $order->delivery_fee, 2);
            if (abs($routeFee - $oldDeliveryFee) >= 0.01) {
                $this->recordDeliveryFeeRevision($order, $actor, $routeFee, $oldDeliveryFee, $asDriver, (int) $event->id);
            }
            $this->routeService->applyRouteToOrder(
                $order->refresh(),
                $asDriver ? $oldDeliveryFee : null,
                $asDriver ? 'PENDING_REPLACEMENT_ROUTE_APPROVAL' : null,
            );

            $this->pricing->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt', 'orderLocations']),
                (int) $actor->id,
                'SHOPPING_MERCHANT_REPLACED',
                true,
                'Harga dihitung ulang setelah merchant Nitip diganti.'
            );

            return [
                'order_id' => (int) $order->id,
                'replacement_event_id' => (int) $event->id,
                'replacement_pickup_location_id' => (int) $replacementPickup->id,
                'old_merchant_name' => $this->pickupMerchantName($pickup),
                'new_merchant_name' => $candidate->name,
                'idempotent_replay' => false,
            ];
        }, attempts: 3);

        if ($asDriver && $result['idempotent_replay'] === false) {
            $this->availabilityNotifications->sendMerchantReplaced(
                Order::query()->findOrFail((int) $result['order_id']),
                $pickupLocationId,
                $result['old_merchant_name'],
                $result['new_merchant_name'],
                (int) $result['replacement_event_id'],
            );
        }

        return $result;
    }

    private function assertParticipant(User $actor, ?Order $order, bool $asDriver): void
    {
        if (! $order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }
        if ($order->serviceType->code !== 'SHOPPING') {
            throw new ApiException('Ganti merchant hanya tersedia untuk order Nitip.', 409);
        }
        if ($asDriver) {
            $driver = Driver::query()->where('user_id', $actor->id)->first();
            if (! $driver || (int) $order->driver_id !== (int) $driver->id) {
                throw new ApiException('Order ini tidak ditugaskan kepada driver saat ini.', 403);
            }
        } elseif ((int) $order->user_id !== (int) $actor->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }
        if (strtoupper((string) $order->statusRef->code) !== 'ARRIVED_MERCHANT') {
            throw new ApiException('Ganti merchant hanya tersedia ketika driver memproses merchant.', 409);
        }
        if ($order->shoppingReceipt !== null) {
            throw new ApiException('Merchant tidak dapat diganti setelah checkout Nitip disimpan.', 409);
        }
    }

    /** @return array<string, mixed> */
    private function assertReplaceable(Order $order, OrderLocation $pickup, int $expectedVersion): array
    {
        $projection = $this->projection->forPickup($order, (int) $pickup->id);
        if ((int) $projection['state_version'] !== $expectedVersion) {
            throw new ApiException('State merchant sudah berubah. Muat ulang order sebelum mengganti merchant.', 409, [
                'code' => 'STATE_CHANGED',
                'state_version' => (int) $projection['state_version'],
                'latest_state' => $projection,
            ]);
        }
        if (! (bool) $projection['can_replace_merchant']) {
            throw new ApiException((string) ($projection['replacement_block_reason'] ?? 'Merchant tidak dapat diganti.'), 409);
        }

        return $projection;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function verifiedCandidatePayload(array $payload): array
    {
        if (isset($payload['merchant_id']) && is_numeric($payload['merchant_id'])) {
            return ['merchant_id' => (int) $payload['merchant_id']];
        }
        $placeId = trim((string) data_get($payload, 'merchant_place.place_id', ''));
        $place = $this->placeDetails->resolve($placeId);

        return ['merchant_place' => $place];
    }

    private function assertCandidateIsNew(Order $order, OrderLocation $source, ShoppingMerchantCandidate $candidate): void
    {
        $existing = $this->pickupLocations->resolveForCandidate($order, $candidate);
        if ($existing instanceof OrderLocation && (int) $existing->id !== (int) $source->id) {
            throw new ApiException('Merchant pengganti sudah aktif di order ini.', 409);
        }
        if (
            $candidate->restaurant?->id !== null
            && $source->restaurant_id !== null
            && (int) $candidate->restaurant->id === (int) $source->restaurant_id
        ) {
            throw new ApiException('Pilih merchant pengganti yang berbeda.', 422);
        }
        if (
            $candidate->restaurant === null
            && mb_strtolower(trim($candidate->name)) === mb_strtolower(trim((string) $source->label))
            && abs($candidate->latitude - (float) $source->latitude) < 0.0003
            && abs($candidate->longitude - (float) $source->longitude) < 0.0003
        ) {
            throw new ApiException('Pilih merchant pengganti yang berbeda.', 422);
        }
    }

    /** @param array<int, mixed> $rawItems @return array<int, array<string, mixed>> */
    private function normalizeItems(ShoppingMerchantCandidate $candidate, array $rawItems): array
    {
        if ($rawItems === []) {
            throw new ApiException('Minimal satu item merchant pengganti wajib tersedia.', 422);
        }

        return collect($rawItems)->map(function (mixed $raw) use ($candidate): array {
            if (! is_array($raw)) {
                throw new ApiException('Format item merchant pengganti tidak valid.', 422);
            }
            $source = strtoupper(trim((string) ($raw['item_source'] ?? 'MANUAL')));
            $name = trim((string) ($raw['menu_name'] ?? ''));
            $menuId = isset($raw['menu_id']) && is_numeric($raw['menu_id']) ? (int) $raw['menu_id'] : null;
            $unitPrice = 0.0;
            if ($source === 'MENU_DB') {
                if (! $candidate->restaurant || $menuId === null) {
                    throw new ApiException('Menu resmi hanya dapat dipilih untuk merchant resmi BangDeliv.', 422);
                }
                $menu = Menu::query()->whereKey($menuId)->where('restaurant_id', $candidate->restaurant->id)->first();
                if (! $menu) {
                    throw new ApiException('Menu tidak sesuai merchant pengganti.', 422);
                }
                $name = (string) $menu->name;
                $unitPrice = $menu->price !== null ? round((float) $menu->price, 2) : 0.0;
            } else {
                $source = 'MANUAL';
                $menuId = null;
            }
            if ($name === '') {
                throw new ApiException('Nama item merchant pengganti wajib diisi.', 422);
            }

            return [
                'item_source' => $source,
                'menu_id' => $menuId,
                'menu_name' => $name,
                'quantity' => max(1, min(99, (int) ($raw['quantity'] ?? 1))),
                'notes' => trim((string) ($raw['notes'] ?? '')) ?: null,
                'unit_price' => $unitPrice,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function routePreview(Order $order, OrderLocation $source, ShoppingMerchantCandidate $candidate): array
    {
        $order->loadMissing(['orderLocations.restaurant', 'items']);
        $pickupPoints = $order->orderLocations
            ->filter(function (OrderLocation $location) use ($source): bool {
                if (strtoupper((string) $location->location_role) !== 'PICKUP' || (int) $location->id === (int) $source->id) {
                    return false;
                }

                return ! in_array(strtoupper((string) ($location->fulfillment_status ?? 'PENDING')), [
                    'FAILED', 'SKIPPED', 'REPLACED', 'ABANDONED_AFTER_LIMIT',
                ], true);
            })
            ->map(fn (OrderLocation $location): array => [
                'id' => (int) $location->id,
                'label' => (string) $location->label,
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
            ])
            ->values()
            ->all();
        $pickupPoints[] = [
            'label' => $candidate->name,
            'latitude' => $candidate->latitude,
            'longitude' => $candidate->longitude,
        ];
        $dropoff = $order->orderLocations->first(
            fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'DROPOFF'
        );
        if (! $dropoff instanceof OrderLocation) {
            throw new ApiException('Titik antar order belum tersedia.', 422);
        }

        // Optimasi urutan waypoint, sama seperti rute nyata (applyRouteToOrder).
        // Kandidat pengganti bisa jadi lebih dekat customer daripada merchant
        // tersisa; tanpa optimasi, urutan naif memaksa rute bolak-balik yang
        // meng-over-estimate ongkir, lalu ongkir itu dikunci dan ditagihkan.
        return $this->routeService->calculateOptimizedForPoints($pickupPoints, [
            'label' => (string) $dropoff->label,
            'latitude' => (float) $dropoff->latitude,
            'longitude' => (float) $dropoff->longitude,
        ]);
    }

    private function idempotencyEvent(Order $order, string $key): ?OrderLog
    {
        return OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::REPLACEMENT_EVENT)
            ->orderByDesc('id')
            ->get()
            ->first(fn (OrderLog $event): bool => (string) data_get($event->metadata, 'idempotency_key') === $key);
    }

    private function failedTripEventForPickup(Order $order, int $pickupLocationId): ?OrderLog
    {
        return OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::FAILED_TRIP_EVENT)
            ->orderByDesc('id')
            ->get()
            ->first(fn (OrderLog $event): bool => (int) data_get($event->metadata, 'pickup_location_id', 0) === $pickupLocationId);
    }

    private function supersedePendingDeliveryFeeProposal(Order $order, int $actorId, int $replacementEventId): void
    {
        if (! $this->deliveryFeeNegotiation->hasPendingApproval($order)) {
            return;
        }
        $this->deliveryFeeNegotiation->record(
            $order,
            'SUPERSEDED_BY_ROUTE_REVISION',
            $actorId,
            'Proposal ongkir lama digantikan karena merchant berubah.',
            ['replacement_event_id' => $replacementEventId, 'status' => 'SUPERSEDED']
        );
    }

    private function recordDeliveryFeeRevision(Order $order, User $actor, float $newFee, float $oldFee, bool $asDriver, int $replacementEventId): void
    {
        $trigger = $asDriver
            ? DeliveryFeeNegotiationService::DRIVER_FEE_QUOTED
            : DeliveryFeeNegotiationService::CUSTOMER_FEE_APPROVED;
        $this->deliveryFeeNegotiation->record($order, $trigger, (int) $actor->id, 'Ongkir diperbarui karena toko/resto diganti.', [
            'old_delivery_fee' => $oldFee,
            'base_amount' => $newFee,
            'quoted_amount' => $newFee,
            'approved_amount' => $asDriver ? null : $newFee,
            'final_amount' => $newFee,
            'delivery_fee_source' => 'driver_manual',
            'status' => $asDriver ? 'PENDING_CUSTOMER' : 'APPROVED',
            'replacement_event_id' => $replacementEventId,
        ]);
        if (! $asDriver) {
            $order->update(['delivery_fee' => $newFee, 'delivery_fee_source' => 'driver_manual']);
        }
    }

    /** @return \Illuminate\Support\Collection<int, OrderItem> */
    private function itemsForPickup(Order $order, OrderLocation $pickup): \Illuminate\Support\Collection
    {
        return $order->items->filter(fn (OrderItem $item): bool => (int) ($item->pickup_location_id ?? 0) === (int) $pickup->id)->values();
    }

    /** @return array<string, mixed> */
    private function merchantSnapshot(OrderLocation $pickup): array
    {
        return [
            'pickup_location_id' => (int) $pickup->id,
            'restaurant_id' => $pickup->restaurant_id !== null ? (int) $pickup->restaurant_id : null,
            'name' => $this->pickupMerchantName($pickup),
            'address' => (string) $pickup->full_address,
            'latitude' => (float) $pickup->latitude,
            'longitude' => (float) $pickup->longitude,
        ];
    }

    /** @return array<string, mixed> */
    private function candidateSnapshot(ShoppingMerchantCandidate $candidate): array
    {
        return [
            'restaurant_id' => $candidate->restaurant?->id,
            'google_place_id' => $candidate->placeId,
            'name' => $candidate->name,
            'address' => $candidate->address,
            'latitude' => $candidate->latitude,
            'longitude' => $candidate->longitude,
            'merchant_type' => $candidate->merchantType(),
        ];
    }

    private function pickupMerchantName(OrderLocation $pickup): string
    {
        return $pickup->restaurant_id !== null
            ? (string) $pickup->restaurant->name
            : (string) $pickup->label;
    }

    /** @return array<string, mixed> */
    private function itemSnapshot(OrderItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'menu_id' => $item->menu_id !== null ? (int) $item->menu_id : null,
            'item_source' => (string) $item->item_source,
            'menu_name' => (string) $item->menu_name,
            'quantity' => (int) $item->quantity,
            'unit_price' => round((float) $item->unit_price, 2),
            'subtotal' => round((float) $item->subtotal, 2),
            'notes' => $item->notes,
        ];
    }

    /** @param array<string, mixed> $candidatePayload @param array<int, array<string, mixed>> $items */
    private function fingerprint(array $candidatePayload, array $items): string
    {
        return hash('sha256', json_encode([$candidatePayload, $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
