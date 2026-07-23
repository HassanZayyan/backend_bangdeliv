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
use App\Services\Notification\ShoppingMerchantReplacementApprovalNotificationService;
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
        private readonly ShoppingMerchantReplacementApprovalNotificationService $approvalNotifications,
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

    /** @param array<string, mixed> $payload @param array<string, mixed> $options @return array<string, mixed> */
    public function commit(
        User $actor,
        int $orderId,
        int $pickupLocationId,
        array $payload,
        string $idempotencyKey,
        bool $asDriver,
        array $options = [],
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 100) {
            throw new ApiException('Idempotency-Key wajib diisi dan maksimal 100 karakter.', 422);
        }

        // Gerbang persetujuan jarak DINONAKTIFKAN secara default (radius 0):
        // customer boleh ganti/tambah toko ke jarak berapa pun karena toh
        // membayar ongkir committed-nya. Gerbang hanya aktif bila radius > 0
        // (mis. disetel ulang lewat config); mekanismenya dipertahankan.
        // Eksekusi hasil-approval driver memakai bypass_approval agar tidak
        // masuk gerbang lagi.
        $bypassApproval = (bool) ($options['bypass_approval'] ?? false);
        if (! $asDriver && ! $bypassApproval && $this->approvalRadiusKm() > 0) {
            $gate = $this->evaluateApprovalGate($actor, $orderId, $pickupLocationId, $payload, $idempotencyKey);
            if ($gate !== null) {
                return $gate;
            }
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
            // Ongkir deterministik: dihitung ulang dari rute committed (yang kini
            // menyertakan toko yang baru di-REPLACED, karena driver benar-benar
            // mendatanginya). Tidak lagi mengunci selisih ongkir sebagai
            // 'driver_manual', supaya konsisten dengan estimasi chatbot.
            $this->routeService->applyRouteToOrder($order->refresh());

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

        if ($result['idempotent_replay'] === false) {
            if ($asDriver) {
                $this->availabilityNotifications->sendMerchantReplaced(
                    Order::query()->findOrFail((int) $result['order_id']),
                    $pickupLocationId,
                    $result['old_merchant_name'],
                    $result['new_merchant_name'],
                    (int) $result['replacement_event_id'],
                );
            } elseif (! $bypassApproval) {
                // Penggantian customer dalam radius: driver tidak dimintai
                // persetujuan, tapi tetap wajib diberi tahu tujuannya berubah.
                $this->approvalNotifications->sendMerchantChangedNotice(
                    Order::query()->findOrFail((int) $result['order_id']),
                    $result['old_merchant_name'],
                    $result['new_merchant_name'],
                );
            }
        }

        return $result;
    }

    /**
     * Bila penggantian oleh customer melewati radius, tahan sebagai proposal
     * yang menunggu persetujuan driver dan kembalikan status PENDING. Bila masih
     * dalam radius, kembalikan null agar commit berjalan seperti biasa.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function evaluateApprovalGate(User $actor, int $orderId, int $pickupLocationId, array $payload, string $idempotencyKey): ?array
    {
        $order = Order::query()
            ->with(['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt', 'driver'])
            ->find($orderId);
        $this->assertParticipant($actor, $order, false);

        // Replay dari replacement yang SUDAH ter-commit (Idempotency-Key sama)
        // tidak boleh masuk gerbang -- biarkan commit menangani replay-nya,
        // sebab assertReplaceable akan gagal (state sudah berubah).
        if ($this->idempotencyEvent($order, $idempotencyKey) instanceof OrderLog) {
            return null;
        }

        $pickup = $this->pickupLocations->pickupById($order, $pickupLocationId);

        // Retry submit yang sama (Idempotency-Key sama) mengembalikan proposal
        // yang sudah ada -- dicek sebelum assertReplaceable karena membuat
        // proposal menaikkan state_version, sehingga expected_version lama tak
        // lagi cocok.
        $existing = $this->pendingApprovalEvent($order, (int) $pickup->id);
        if ($existing instanceof OrderLog
            && strtoupper((string) data_get($existing->metadata, 'status')) === 'PENDING'
            && (string) data_get($existing->metadata, 'idempotency_key') === $idempotencyKey) {
            return $this->pendingGateResult($order, $pickup, $existing, (float) data_get($existing->metadata, 'distance_km', 0));
        }

        $pickupProjection = $this->assertReplaceable($order, $pickup, (int) $payload['expected_version']);
        $candidatePayload = $this->verifiedCandidatePayload($payload);
        $candidate = $this->candidateResolver->resolve($order, $candidatePayload);
        $this->assertCandidateIsNew($order, $pickup, $candidate);

        $distanceKm = $this->haversineKm(
            (float) $pickup->latitude,
            (float) $pickup->longitude,
            $candidate->latitude,
            $candidate->longitude,
        );
        if ($distanceKm <= $this->approvalRadiusKm()) {
            return null;
        }

        $items = $this->normalizeItems($candidate, $payload['items'] ?? []);
        $fingerprint = $this->fingerprint($candidatePayload, $items);

        $event = OrderLog::query()->create([
            'order_id' => $order->id,
            'event_type' => ShoppingReplacementProjectionService::REPLACEMENT_PENDING_EVENT,
            'trigger_type' => 'CUSTOMER_REQUESTED_MERCHANT_REPLACEMENT_APPROVAL',
            'changed_by_user_id' => $actor->id,
            'note' => 'Customer meminta ganti toko/resto ke lokasi jauh; menunggu persetujuan driver.',
            'metadata' => [
                'status' => 'PENDING',
                'requested_by' => 'customer',
                'chain_id' => $pickupProjection['chain_id'],
                'source_pickup_location_id' => (int) $pickup->id,
                'pickup_location_id' => (int) $pickup->id,
                'distance_km' => round($distanceKm, 2),
                'radius_km' => $this->approvalRadiusKm(),
                'old_merchant' => $this->merchantSnapshot($pickup),
                'merchant' => $this->candidateSnapshot($candidate),
                'items' => $items,
                'idempotency_key' => $idempotencyKey,
                'payload_fingerprint' => $fingerprint,
                'request_payload' => $payload,
            ],
        ]);

        $this->approvalNotifications->sendMerchantChangeApprovalRequest(
            $order,
            $this->pickupMerchantName($pickup),
            $candidate->name,
            round($distanceKm, 2),
            (int) $event->id,
        );

        return $this->pendingGateResult($order, $pickup, $event, $distanceKm);
    }

    /**
     * Driver menyetujui proposal penggantian yang tertahan: proposal ditandai
     * APPROVED lalu commit dijalankan dengan identitas customer (bypass gerbang).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function approveByDriver(User $actor, int $orderId, int $pickupLocationId, array $payload, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 100) {
            throw new ApiException('Idempotency-Key wajib diisi dan maksimal 100 karakter.', 422);
        }

        $execution = DB::transaction(function () use ($actor, $orderId, $pickupLocationId, $payload, $idempotencyKey): array {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'orderLocations.restaurant', 'items', 'shoppingReceipt', 'driver', 'user'])
                ->lockForUpdate()
                ->find($orderId);
            $this->assertParticipant($actor, $order, true);

            $event = $this->pendingApprovalEvent($order, $pickupLocationId);
            $this->assertPendingApprovalActionable($event, $pickupLocationId, (int) ($payload['approval_event_id'] ?? 0));

            $customer = $order->user;
            if (! $customer instanceof User) {
                throw new ApiException('Customer order tidak ditemukan.', 422);
            }

            $metadata = is_array($event->metadata) ? $event->metadata : [];
            $requestPayload = is_array($metadata['request_payload'] ?? null) ? $metadata['request_payload'] : [];
            $storedKey = (string) ($metadata['idempotency_key'] ?? '');

            // Tandai APPROVED dulu agar projection tak lagi memblok penggantian,
            // lalu selaraskan expected_version ke state terkini sebelum commit.
            $event->update(['metadata' => [
                ...$metadata,
                'status' => 'APPROVED',
                'resolved_by_user_id' => (int) $actor->id,
                'resolved_role' => 'driver',
            ]]);
            $requestPayload['expected_version'] = (int) $this->projection->forPickup($order, $pickupLocationId)['state_version'];

            $result = $this->commit(
                $customer,
                (int) $order->id,
                $pickupLocationId,
                $requestPayload,
                $storedKey !== '' ? $storedKey : $idempotencyKey,
                false,
                ['bypass_approval' => true],
            );

            return [
                'result' => $result,
                'old_merchant_name' => (string) data_get($metadata, 'old_merchant.name', 'Toko/resto'),
                'new_merchant_name' => (string) data_get($metadata, 'merchant.name', 'Toko/resto'),
            ];
        }, attempts: 3);

        $this->approvalNotifications->sendMerchantChangeApprovalResult(
            Order::query()->with('user')->findOrFail($orderId),
            true,
            $execution['old_merchant_name'],
            $execution['new_merchant_name'],
        );

        return $execution['result'];
    }

    /**
     * Driver menolak proposal: proposal ditandai REJECTED, toko lama tetap di
     * state keputusan (customer bisa pilih opsi lain), tidak ada pembatalan.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function rejectByDriver(User $actor, int $orderId, int $pickupLocationId, array $payload): array
    {
        $rejection = DB::transaction(function () use ($actor, $orderId, $pickupLocationId, $payload): array {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'orderLocations.restaurant', 'driver', 'user'])
                ->lockForUpdate()
                ->find($orderId);
            $this->assertParticipant($actor, $order, true);

            $event = $this->pendingApprovalEvent($order, $pickupLocationId);
            $this->assertPendingApprovalActionable($event, $pickupLocationId, (int) ($payload['approval_event_id'] ?? 0));

            $metadata = is_array($event->metadata) ? $event->metadata : [];
            $reason = trim((string) ($payload['reason'] ?? '')) ?: 'Toko/resto pengganti terlalu jauh.';
            $event->update(['metadata' => [
                ...$metadata,
                'status' => 'REJECTED',
                'resolved_by_user_id' => (int) $actor->id,
                'resolved_role' => 'driver',
                'rejection_reason' => $reason,
            ]]);

            return [
                'order_id' => (int) $order->id,
                'old_merchant_name' => (string) data_get($metadata, 'old_merchant.name', 'Toko/resto'),
                'new_merchant_name' => (string) data_get($metadata, 'merchant.name', 'Toko/resto'),
                'reason' => $reason,
            ];
        }, attempts: 3);

        $this->approvalNotifications->sendMerchantChangeApprovalResult(
            Order::query()->with('user')->findOrFail($orderId),
            false,
            $rejection['old_merchant_name'],
            $rejection['new_merchant_name'],
            $rejection['reason'],
        );

        return [
            'order_id' => $rejection['order_id'],
            'pickup_location_id' => $pickupLocationId,
            'status' => 'REJECTED',
            'reason' => $rejection['reason'],
        ];
    }

    private function pendingApprovalEvent(Order $order, int $pickupLocationId): ?OrderLog
    {
        return OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', ShoppingReplacementProjectionService::REPLACEMENT_PENDING_EVENT)
            ->orderByDesc('id')
            ->get()
            ->first(fn (OrderLog $event): bool => (int) data_get($event->metadata, 'source_pickup_location_id', 0) === $pickupLocationId);
    }

    private function assertPendingApprovalActionable(?OrderLog $event, int $pickupLocationId, int $expectedEventId): void
    {
        if (! $event instanceof OrderLog
            || (int) data_get($event->metadata, 'source_pickup_location_id', 0) !== $pickupLocationId
            || strtoupper((string) data_get($event->metadata, 'status')) !== 'PENDING') {
            throw new ApiException('Tidak ada permintaan penggantian toko/resto yang menunggu persetujuan.', 409);
        }
        if ($expectedEventId > 0 && (int) $event->id !== $expectedEventId) {
            throw new ApiException('Permintaan penggantian sudah berubah. Muat ulang order.', 409, ['code' => 'STATE_CHANGED']);
        }
    }

    /** @return array<string, mixed> */
    private function pendingGateResult(Order $order, OrderLocation $pickup, OrderLog $event, float $distanceKm): array
    {
        return [
            'order_id' => (int) $order->id,
            'pickup_location_id' => (int) $pickup->id,
            'status' => 'PENDING_DRIVER_APPROVAL',
            'requires_driver_approval' => true,
            'approval_event_id' => (int) $event->id,
            'distance_km' => round($distanceKm, 2),
            'idempotent_replay' => false,
        ];
    }

    private function approvalRadiusKm(): float
    {
        // 0 (default) = gerbang jarak nonaktif / tanpa batas. > 0 = radius aktif.
        return (float) config('bangdeliv.shopping.merchant_replacement_approval_radius_km', 0);
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);
        $haversine = sin($deltaLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($deltaLon / 2) ** 2;
        $safeHaversine = min(1.0, max(0.0, $haversine));

        return $earthRadiusKm * 2 * atan2(sqrt($safeHaversine), sqrt(1 - $safeHaversine));
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
        // Preview memakai committed-set: SEMUA toko/resto yang jadi bagian order
        // (termasuk sumber yang akan di-REPLACED dan yang sudah FAILED, karena
        // driver mendatanginya) + kandidat pengganti. Dengan begitu ongkir yang
        // dilihat customer sebelum konfirmasi = ongkir yang benar-benar ditagih
        // (applyRouteToOrder juga memakai committed).
        $pickupPoints = $order->orderLocations
            ->filter(function (OrderLocation $location): bool {
                if (strtoupper((string) $location->location_role) !== 'PICKUP') {
                    return false;
                }

                return strtoupper((string) ($location->fulfillment_status ?? 'PENDING')) !== 'SKIPPED';
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
