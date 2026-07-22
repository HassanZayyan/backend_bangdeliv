<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderLocation;
use App\Models\OrderLog;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Driver\DriverOrderPayloadFactory;
use App\Services\Notification\ShoppingItemAvailabilityPushNotificationService;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingItemChangeRequestService;
use App\Services\Shopping\ShoppingMerchantCandidate;
use App\Services\Shopping\ShoppingMerchantCandidateResolver;
use App\Services\Shopping\ShoppingOrderCapabilityService;
use App\Services\Shopping\ShoppingPickupLocationService;
use App\Services\Shopping\ShoppingPriceNegotiationService;
use App\Services\Shopping\ShoppingRouteService;
use App\Services\Shopping\ShoppingUnavailableItemDecisionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class ShoppingOrderItemEditService
{
    public function __construct(
        private readonly ShoppingPricingService $shoppingPricingService,
        private readonly ShoppingRouteService $shoppingRouteService,
        private readonly ShoppingPickupLocationService $shoppingPickupLocationService,
        private readonly ShoppingMerchantCandidateResolver $shoppingMerchantCandidateResolver,
        private readonly ShoppingOrderCapabilityService $shoppingOrderCapabilityService,
        private readonly ShoppingPriceNegotiationService $shoppingPriceNegotiationService,
        private readonly ShoppingItemChangeRequestService $shoppingItemChangeRequestService,
        private readonly ShoppingUnavailableItemDecisionService $shoppingUnavailableItemDecisionService,
        private readonly ShoppingItemAvailabilityPushNotificationService $shoppingItemAvailabilityPushNotificationService,
        private readonly DeliveryFeeNegotiationService $deliveryFeeNegotiationService,
        private readonly OrderEvidenceService $orderEvidenceService,
        private readonly DriverOrderPayloadFactory $driverOrderPayloadFactory,
        private readonly OrderStatusResolver $orderStatusResolver,
        private readonly DriverOrderResolver $driverOrderResolver,
        private readonly OrderTotalsSynchronizer $orderTotalsSynchronizer
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addShoppingItem(User $user, int $orderId, array $payload): Order
    {
        return $this->addShoppingItems($user, $orderId, [$payload]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function addShoppingItems(User $user, int $orderId, array $items): Order
    {
        return DB::transaction(function () use ($user, $orderId, $items): Order {
            $order = $this->getEditableShoppingOrder($user, $orderId);

            if ($items === []) {
                throw new ApiException('Minimal satu item belanja wajib ditambahkan.', 422);
            }

            $routeChanged = $this->applyShoppingItemAdditions(
                $order,
                $items,
                allowNewMerchant: true
            );

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                $user->id,
                $routeChanged ? 'SHOPPING_ROUTE_UPDATED' : 'CUSTOMER_ADD_ITEM',
                true,
                $routeChanged
                    ? 'Customer menambahkan merchant/item belanja sehingga rute dihitung ulang.'
                    : 'Customer menambahkan item belanja.'
            );
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateShoppingItem(User $user, int $orderId, int $itemId, array $payload): Order
    {
        return DB::transaction(function () use ($user, $orderId, $itemId, $payload): Order {
            $order = $this->getEditableShoppingOrder($user, $orderId);
            $item = $order->items()->where('id', $itemId)->first();

            if (! $item) {
                throw new ApiException('Item order tidak ditemukan.', 404);
            }

            $quantity = (int) $payload['quantity'];
            $unitPrice = (float) $item->unit_price;
            $menuName = (string) $item->menu_name;

            if ($item->item_source === 'MANUAL') {
                if (array_key_exists('menu_name', $payload) && $payload['menu_name'] !== null) {
                    $menuName = (string) $payload['menu_name'];
                }
            }

            $lineSubtotal = round($unitPrice * $quantity, 2);

            $item->update([
                'menu_name' => $menuName,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'subtotal' => $lineSubtotal,
                'notes' => array_key_exists('notes', $payload) ? ($payload['notes'] !== null ? (string) $payload['notes'] : null) : $item->notes,
            ]);

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                $user->id,
                'CUSTOMER_UPDATE_ITEM',
                true,
                'Customer memperbarui item belanja.'
            );
        });
    }

    public function removeShoppingItem(User $user, int $orderId, int $itemId): Order
    {
        return DB::transaction(function () use ($user, $orderId, $itemId): Order {
            $order = $this->getEditableShoppingOrder($user, $orderId);
            $item = $order->items()->where('id', $itemId)->first();

            if (! $item) {
                throw new ApiException('Item order tidak ditemukan.', 404);
            }

            if ($order->items()->count() <= 1) {
                throw new ApiException('Order belanja harus memiliki minimal satu item.', 409);
            }

            $pickupLocationId = $item->pickup_location_id !== null ? (int) $item->pickup_location_id : null;
            $item->delete();
            $routeChanged = false;

            if ($pickupLocationId !== null && ! $order->items()->where('pickup_location_id', $pickupLocationId)->exists()) {
                $pickup = OrderLocation::query()
                    ->where('order_id', $order->id)
                    ->where('id', $pickupLocationId)
                    ->where('location_role', 'PICKUP')
                    ->first();

                if ($pickup) {
                    $pickup->delete();
                    $this->syncPrimaryRestaurantFromFirstPickup($order);
                    $this->shoppingRouteService->applyRouteToOrder($order->refresh()->load(['orderLocations.restaurant']));
                    $routeChanged = true;
                }
            }

            return $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                $user->id,
                $routeChanged ? 'SHOPPING_ROUTE_UPDATED' : 'CUSTOMER_REMOVE_ITEM',
                true,
                $routeChanged
                    ? 'Customer menghapus item terakhir pada merchant sehingga rute dihitung ulang.'
                    : 'Customer menghapus item belanja.'
            );
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateShoppingItemsByDriver(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);
        /** @var array{pickup_location_id: int, item_names: list<string>, event_id: int}|null $unavailableItemNotification */
        $unavailableItemNotification = null;

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload, &$unavailableItemNotification): Order {
            $order = Order::query()
                ->with(['statusRef', 'serviceType', 'items', 'orderLocations', 'shoppingReceipt'])
                ->lockForUpdate()
                ->find($orderId);

            if (! $order) {
                throw new ApiException('Order tidak ditemukan.', 404);
            }

            if ((int) ($order->driver_id ?? 0) !== (int) $driver->id) {
                throw new ApiException('Order ini tidak ditugaskan kepada driver saat ini.', 403);
            }

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Update nota hanya tersedia untuk order SHOPPING.', 409);
            }

            if (! $this->shoppingOrderCapabilityService->canDriverUpdateItemAvailability($order)) {
                throw new ApiException('Item Nitip hanya bisa diperbarui saat driver tiba di merchant.', 409);
            }

            $targetPickupLocationId = isset($payload['pickup_location_id']) && is_numeric($payload['pickup_location_id'])
                ? (int) $payload['pickup_location_id']
                : null;
            if ($targetPickupLocationId !== null && $targetPickupLocationId > 0) {
                $targetPickup = $this->shoppingPickupLocationService->pickupById($order, $targetPickupLocationId);
            } else {
                $targetPickupLocationId = null;
                $targetPickup = null;
            }

            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $requiresRequote = false;
            $affectedPickupIds = [];
            $submittedPickupIds = [];
            $submittedItemIds = [];
            $submittedHasUnavailableItem = false;
            $newUnavailableItemNamesByPickup = [];
            $availabilityEvent = null;

            foreach ($items as $itemPayload) {
                if (! is_array($itemPayload)) {
                    continue;
                }

                $itemId = (int) ($itemPayload['id'] ?? 0);
                if ($itemId <= 0) {
                    continue;
                }

                $item = $order->items()->where('id', $itemId)->lockForUpdate()->first();
                if (! $item) {
                    throw new ApiException('Item belanja tidak ditemukan pada order ini.', 404);
                }

                $itemPickupId = $item->pickup_location_id !== null ? (int) $item->pickup_location_id : null;
                if ($targetPickupLocationId !== null && $itemPickupId !== $targetPickupLocationId) {
                    throw new ApiException('Item tidak sesuai dengan merchant Nitip yang sedang diperbarui.', 422);
                }

                $quantity = array_key_exists('quantity', $itemPayload)
                    ? max(1, (int) $itemPayload['quantity'])
                    : (int) $item->quantity;
                $unitPrice = array_key_exists('unit_price', $itemPayload) && $itemPayload['unit_price'] !== null
                    ? max(0.0, (float) $itemPayload['unit_price'])
                    : (float) $item->unit_price;
                $wasAvailable = (bool) $item->is_available;
                $isAvailable = array_key_exists('is_available', $itemPayload)
                    ? (bool) $itemPayload['is_available']
                    : $wasAvailable;
                $itemChanged = $quantity !== (int) $item->quantity
                    || $unitPrice !== (float) $item->unit_price
                    || $isAvailable !== $wasAvailable;
                $requiresRequote = $requiresRequote || $itemChanged;
                if ($itemChanged && $item->pickup_location_id !== null) {
                    $affectedPickupIds[(int) $item->pickup_location_id] = true;
                }
                if ($itemPickupId !== null) {
                    $submittedPickupIds[$itemPickupId] = true;
                }
                $submittedItemIds[] = (int) $item->id;
                $submittedHasUnavailableItem = $submittedHasUnavailableItem || ! $isAvailable;
                if ($wasAvailable && ! $isAvailable && $itemPickupId !== null) {
                    $itemName = trim((string) ($item->menu_name ?? ''));
                    $newUnavailableItemNamesByPickup[$itemPickupId][] = $itemName !== ''
                        ? $itemName
                        : 'Item Nitip';
                }

                $metadata = is_array($item->metadata) ? $item->metadata : [];
                if ($item->item_source === 'MANUAL') {
                    $metadata['price_status'] = (! $isAvailable)
                        ? 'UNAVAILABLE'
                        : ($unitPrice > 0 ? 'DRIVER_CONFIRMED' : 'PENDING_DRIVER_INPUT');
                }

                $lineSubtotal = $isAvailable ? round($unitPrice * $quantity, 2) : 0.0;

                $item->update([
                    'quantity' => $quantity,
                    'unit_price' => round($unitPrice, 2),
                    'subtotal' => $lineSubtotal,
                    'notes' => array_key_exists('notes', $itemPayload)
                        ? ($itemPayload['notes'] !== null ? (string) $itemPayload['notes'] : null)
                        : $item->notes,
                    'is_available' => $isAvailable,
                    'metadata' => $metadata === [] ? null : $metadata,
                ]);
            }

            if ($targetPickupLocationId === null && count($submittedPickupIds) === 1) {
                $targetPickupLocationId = (int) array_key_first($submittedPickupIds);
                $targetPickup = $this->shoppingPickupLocationService->pickupById($order, $targetPickupLocationId);
            }

            if ($targetPickupLocationId !== null) {
                $targetPickup ??= $this->shoppingPickupLocationService->pickupById($order, $targetPickupLocationId);
                $fulfillmentStatus = strtoupper((string) ($targetPickup->fulfillment_status ?? 'PENDING'));
                if (! in_array($fulfillmentStatus, ['OPEN_CONFIRMED', 'ITEMS_PENDING_CUSTOMER', 'ITEMS_CONFIRMED'], true)) {
                    throw new ApiException('Konfirmasi Resto buka sebelum mengecek item merchant ini.', 409);
                }
            }

            if ($targetPickupLocationId !== null) {
                $availabilityEvent = OrderLog::query()->create([
                    'order_id' => $order->id,
                    'event_type' => 'SHOPPING_ITEM_AVAILABILITY',
                    'trigger_type' => 'DRIVER_CONFIRMED_ITEM_AVAILABILITY',
                    'changed_by_user_id' => $actor->id,
                    'note' => 'Driver mencatat ketersediaan item merchant.',
                    'metadata' => [
                        'pickup_location_id' => $targetPickupLocationId,
                        'item_ids' => $submittedItemIds,
                        'has_unavailable_item' => $submittedHasUnavailableItem,
                    ],
                ]);

                $targetPickup->update([
                    'fulfillment_status' => $submittedHasUnavailableItem
                        ? 'ITEMS_PENDING_CUSTOMER'
                        : 'ITEMS_CONFIRMED',
                ]);
            }

            if ($requiresRequote) {
                foreach (array_keys($affectedPickupIds) as $pickupLocationId) {
                    $this->shoppingPriceNegotiationService->record(
                        $order,
                        ShoppingPriceNegotiationService::DRIVER_ITEM_CHANGE_REQUIRES_REQUOTE,
                        $actor->id,
                        'Driver memperbarui ketersediaan item. Harga Nitip perlu dikirim ulang.',
                        [
                            'status' => 'NEEDS_REQUOTE',
                            'pickup_location_id' => $pickupLocationId,
                        ]
                    );
                }
            }

            $newUnavailableItemNames = $targetPickupLocationId !== null
                ? ($newUnavailableItemNamesByPickup[$targetPickupLocationId] ?? [])
                : [];
            $shouldNotifyUnavailableItems = $availabilityEvent !== null && $newUnavailableItemNames !== [];

            $freshOrder = $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt']),
                $actor->id,
                'DRIVER_RECEIPT_UPDATE',
                true,
                'Driver memperbarui ketersediaan item Nitip.',
                ! $shouldNotifyUnavailableItems
            );

            if ($shouldNotifyUnavailableItems) {
                $unavailableItemNotification = [
                    'pickup_location_id' => (int) $targetPickupLocationId,
                    'item_names' => $newUnavailableItemNames,
                    'event_id' => (int) $availabilityEvent->id,
                ];
            }

            return $freshOrder;
        });

        if (is_array($unavailableItemNotification)) {
            $this->shoppingItemAvailabilityPushNotificationService->sendUnavailableItems(
                $order,
                $unavailableItemNotification['pickup_location_id'],
                $unavailableItemNotification['item_names'],
                $unavailableItemNotification['event_id'],
            );
        }

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateShoppingCheckout(User $actor, int $orderId, array $payload): array
    {
        $driver = $this->driverOrderResolver->resolveActiveDriverProfile($actor);

        $order = DB::transaction(function () use ($actor, $driver, $orderId, $payload): Order {
            $order = $this->driverOrderResolver->lockedAssignedDriverOrder(
                $orderId,
                $driver->id,
                ['statusRef', 'serviceType', 'items', 'shoppingReceipt']
            );

            if (($order->serviceType->code ?? null) !== 'SHOPPING') {
                throw new ApiException('Checkout nitip hanya tersedia untuk order SHOPPING.', 409);
            }

            if (! $this->shoppingOrderCapabilityService->canDriverUploadReceipt($order)) {
                throw new ApiException('Checkout Nitip hanya bisa disimpan saat driver tiba di merchant.', 409);
            }

            if (! $this->shoppingPriceNegotiationService->isApproved($order)) {
                throw new ApiException('Harga Nitip harus disetujui customer sebelum checkout disimpan.', 409);
            }

            if ($this->shoppingItemChangeRequestService->hasPending($order)) {
                throw new ApiException('Request perubahan item harus diselesaikan sebelum checkout disimpan.', 409);
            }

            if ($this->deliveryFeeNegotiationService->hasPendingApproval($order)) {
                throw new ApiException('Revisi ongkir harus disetujui customer sebelum checkout disimpan.', 409);
            }

            if (array_key_exists('delivery_fee_override', $payload) && $payload['delivery_fee_override'] !== null) {
                throw new ApiException('Revisi ongkir Nitip harus dikirim sebagai proposal ongkir.', 409);
            }

            $receiptPhoto = $payload['receipt_photo'] ?? null;
            if ($order->shoppingReceipt !== null && ! ($receiptPhoto instanceof UploadedFile)) {
                return $order->refresh();
            }

            $shoppingTotal = $this->shoppingPricingService->approvedShoppingSubtotalAmount($order);
            if ($shoppingTotal === null || $shoppingTotal <= 0) {
                throw new ApiException('Harga Nitip approved belum valid untuk checkout.', 409);
            }

            $order->shoppingReceipt()->updateOrCreate(
                ['order_id' => $order->id],
                [
                    'total_amount' => $shoppingTotal,
                    'recorded_by_user_id' => $actor->id,
                    'recorded_at' => now(),
                ]
            );
            $order->unsetRelation('shoppingReceipt');
            $order = $this->shoppingPricingService->recalculate(
                $order->refresh()->load(['items', 'statusRef', 'serviceType', 'shoppingReceipt', 'orderLocations']),
                $actor->id,
                'SHOPPING_CHECKOUT_SAVED',
                true,
                'Driver menyimpan checkout Nitip.'
            );
            $this->orderTotalsSynchronizer->syncPendingPaymentAmount($order->refresh());

            $order->orderLocations()
                ->where('location_role', 'PICKUP')
                ->whereIn('fulfillment_status', ['PRICE_APPROVED'])
                ->update([
                    'fulfillment_status' => 'COMPLETED',
                ]);

            if ($receiptPhoto instanceof UploadedFile) {
                $this->orderEvidenceService->storeAndRecordDriverEvidence(
                    $order,
                    $receiptPhoto,
                    (int) $actor->id,
                    'SHOPPING_RECEIPT',
                    'receipts',
                );
            }

            return $order->refresh();
        });

        return $this->driverOrderPayloadFactory->serialize(
            $order->fresh($this->driverOrderPayloadFactory->relations()),
            includeTimeline: true,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function withTargetPickupMerchantPayload(Order $order, array $items, int $targetPickupLocationId): array
    {
        if ($items === []) {
            return [];
        }

        $merchantPayload = $this->shoppingPickupLocationService->merchantPayloadFromPickup(
            $this->shoppingPickupLocationService->pickupById($order, $targetPickupLocationId)
        );

        return array_map(function (array $item) use ($merchantPayload): array {
            $hasMerchantPayload = (isset($item['merchant_id']) && is_numeric($item['merchant_id']))
                || (isset($item['merchant_place']) && is_array($item['merchant_place']));

            return $hasMerchantPayload ? $item : [...$merchantPayload, ...$item];
        }, $items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function assertShoppingEditUnavailableTarget(
        Order $order,
        int $targetPickupLocationId,
        array $items,
        string $action,
        ?int $itemId,
    ): void {
        $order->loadMissing(['orderLocations.restaurant', 'items']);
        $pickup = $order->orderLocations->first(
            fn (OrderLocation $location): bool => (int) $location->id === $targetPickupLocationId
                && strtoupper((string) $location->location_role) === 'PICKUP'
        );
        if (! $pickup instanceof OrderLocation) {
            throw new ApiException('Merchant yang ingin diedit tidak valid.', 422);
        }

        $hasUnavailableItem = $order->items->contains(
            fn (OrderItem $item): bool => (int) ($item->pickup_location_id ?? 0) === $targetPickupLocationId
                && ! (bool) ($item->is_available ?? true)
        );
        if (! $hasUnavailableItem) {
            throw new ApiException('Edit merchant hanya tersedia jika ada item yang tidak tersedia.', 409);
        }

        if (in_array($action, ['UPDATE', 'REMOVE'], true)) {
            $targetItem = $order->items->first(fn (OrderItem $item): bool => (int) $item->id === (int) $itemId);
            if (! $targetItem instanceof OrderItem || (int) ($targetItem->pickup_location_id ?? 0) !== $targetPickupLocationId) {
                throw new ApiException('Item yang ingin diubah tidak sesuai merchant.', 422);
            }
            if ($action === 'REMOVE' && (bool) ($targetItem->is_available ?? true)) {
                throw new ApiException('Lanjut tanpa item hanya tersedia untuk item yang tidak tersedia.', 409);
            }
            if ($action === 'REMOVE') {
                $this->shoppingUnavailableItemDecisionService->assertCanContinueWithoutItem(
                    $order,
                    $targetPickupLocationId,
                    (int) $itemId
                );
            }
        }

        if ($action === 'CANCEL_MERCHANT') {
            return;
        }

        foreach ($items as $payload) {
            $candidate = $this->shoppingMerchantCandidateResolver->resolve($order, $payload);
            $resolvedPickup = $this->shoppingPickupLocationService->resolveForCandidate($order, $candidate);
            if (! $resolvedPickup instanceof OrderLocation || (int) $resolvedPickup->id !== $targetPickupLocationId) {
                throw new ApiException('Edit item tidak tersedia hanya boleh untuk merchant yang sama.', 409);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function applyShoppingItemAdditions(
        Order $order,
        array $items,
        bool $allowNewMerchant = true,
    ): bool {
        $statusCode = $this->orderStatusResolver->orderStatusCode($order);
        $routeChanged = false;
        $pickupLocationsByCandidateKey = [];

        foreach ($items as $payload) {
            $candidate = $this->shoppingMerchantCandidateResolver->resolve($order, $payload);
            $candidateKey = $candidate->key();
            $pickupLocation = $pickupLocationsByCandidateKey[$candidateKey] ?? null;

            if (! $pickupLocation) {
                $pickupLocation = $this->shoppingPickupLocationService->resolveForCandidate($order, $candidate);
            }

            if (! $pickupLocation) {
                if (! $allowNewMerchant || ! in_array($statusCode, ['PENDING', 'DRIVER_ASSIGNED'], true)) {
                    throw new ApiException('Merchant baru hanya bisa ditambahkan sebelum driver mulai belanja.', 409);
                }

                if ($this->shoppingPickupLocationService->activePickupCount($order) >= 3) {
                    throw new ApiException('Maksimal merchant Nitip adalah 3.', 422);
                }

                $pickupLocation = $this->shoppingPickupLocationService->createForCandidate($order, $candidate);
                $routeChanged = true;
            }

            $pickupLocationsByCandidateKey[$candidateKey] = $pickupLocation;

            $order->items()->create([
                ...$this->shoppingItemCreatePayload($candidate, $payload),
                'pickup_location_id' => $pickupLocation->id,
                'is_available' => true,
            ]);
        }

        if ($routeChanged) {
            $this->shoppingRouteService->applyRouteToOrder($order->refresh()->load(['orderLocations.restaurant']));
        }

        return $routeChanged;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveShoppingMenu(Restaurant $merchant, array $payload): Menu
    {
        $menuId = isset($payload['menu_id']) && is_numeric($payload['menu_id'])
            ? (int) $payload['menu_id']
            : 0;

        if ($menuId < 1) {
            throw new ApiException('Menu wajib dipilih untuk item katalog.', 422);
        }

        $menu = Menu::query()
            ->whereKey($menuId)
            ->where('restaurant_id', $merchant->id)
            ->where('is_available', true)
            ->first();

        if (! $menu) {
            throw new ApiException('Menu tidak ditemukan atau tidak sesuai merchant.', 422);
        }

        return $menu;
    }

    private function getEditableShoppingOrder(User $user, int $orderId): Order
    {
        $order = Order::query()
            ->with(['items', 'statusRef', 'serviceType', 'restaurant', 'orderLocations.restaurant', 'shoppingReceipt'])
            ->find($orderId);

        if (! $order || $order->user_id !== $user->id) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        if (($order->serviceType->code ?? null) !== 'SHOPPING') {
            throw new ApiException('Perubahan item hanya diizinkan untuk order SHOPPING.', 409);
        }

        if (! $this->shoppingOrderCapabilityService->canCustomerDirectEditItems($order)) {
            throw new ApiException('Item tidak bisa diubah pada status order saat ini.', 409);
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function shoppingItemCreatePayload(ShoppingMerchantCandidate $candidate, array $payload): array
    {
        $itemSource = strtoupper((string) ($payload['item_source'] ?? 'MANUAL'));
        $quantity = max(1, (int) ($payload['quantity'] ?? 1));
        $notes = isset($payload['notes']) && trim((string) $payload['notes']) !== ''
            ? trim((string) $payload['notes'])
            : null;

        if ($itemSource === 'MENU_DB') {
            $merchant = $candidate->restaurant;
            if (! $merchant instanceof Restaurant) {
                throw new ApiException('Menu database hanya tersedia untuk merchant resmi BangDeliv.', 422);
            }

            $menu = $this->resolveShoppingMenu($merchant, $payload);
            if ($menu->price === null) {
                return [
                    'menu_id' => null,
                    'item_source' => 'MANUAL',
                    'menu_name' => (string) $menu->name,
                    'quantity' => $quantity,
                    'unit_price' => 0,
                    'subtotal' => 0,
                    'notes' => $notes,
                    'metadata' => [
                        ...$candidate->metadata(),
                        'price_status' => 'PENDING_DRIVER_INPUT',
                        'source' => 'CUSTOMER_MENU_DB_PENDING_PRICE',
                        'catalog_menu_id' => (int) $menu->id,
                    ],
                ];
            }

            $unitPrice = round((float) $menu->price, 2);

            return [
                'menu_id' => (int) $menu->id,
                'item_source' => 'MENU_DB',
                'menu_name' => (string) $menu->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => round($unitPrice * $quantity, 2),
                'notes' => $notes,
                'metadata' => [
                    ...$candidate->metadata(),
                    'price_status' => 'CONFIRMED',
                    'source' => 'CUSTOMER_MENU_DB',
                ],
            ];
        }

        $menuName = trim((string) ($payload['menu_name'] ?? ''));
        if ($menuName === '') {
            throw new ApiException('Nama item manual wajib diisi.', 422);
        }

        return [
            'menu_id' => null,
            'item_source' => 'MANUAL',
            'menu_name' => $menuName,
            'quantity' => $quantity,
            'unit_price' => 0,
            'subtotal' => 0,
            'notes' => $notes,
            'metadata' => [
                ...$candidate->metadata(),
                'price_status' => 'PENDING_DRIVER_INPUT',
            ],
        ];
    }

    private function syncPrimaryRestaurantFromFirstPickup(Order $order): void
    {
        $order->unsetRelation('restaurant');
    }
}
