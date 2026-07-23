<?php

namespace App\Models;

use App\Services\Admin\AdminPaymentProofStatusService;
use App\Services\Order\DeliveryFeeNegotiationService;
use App\Services\Pricing\ShoppingPricingService;
use App\Services\Shopping\ShoppingItemChangeRequestService;
use App\Services\Shopping\ShoppingOrderCapabilityService;
use App\Services\Shopping\ShoppingPriceNegotiationService;
use App\Services\Shopping\ShoppingUnavailableItemDecisionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * @property int $id
 * @property string $order_number
 * @property int $user_id
 * @property int $service_type_id
 * @property string|null $delivery_address
 * @property string|null $delivery_latitude
 * @property string|null $delivery_longitude
 * @property string $delivery_fee
 * @property string|null $delivery_fee_source
 * @property array<string, mixed>|null $route_snapshot
 * @property string|null $total_price
 * @property int $status_id
 * @property string $payment_status
 * @property string|null $payment_method
 * @property string|null $paid_amount
 * @property int|null $paid_by_user_id
 * @property \Carbon\Carbon|null $paid_at
 * @property string|null $cancellation_reason
 * @property string|null $cancelled_by
 * @property \Carbon\Carbon|null $delivered_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Restaurant|null $restaurant
 * @property-read \App\Models\ServiceType|null $serviceType
 * @property-read \App\Models\Driver|null $driver
 * @property-read \App\Models\Address|null $address
 * @property-read \App\Models\OrderStatus|null $statusRef
 * @property-read \App\Models\User|null $paidBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderStatusHistory> $statusHistories
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderLocation> $orderLocations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderLocation> $locations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderEvidence> $evidences
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderLog> $logs
 * @property-read \App\Models\OrderPayment|null $payment
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderPayment> $payments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderChatMessage> $chatMessages
 * @property-read \App\Models\RideOrder|null $rideOrder
 * @property-read \App\Models\CourierOrder|null $courierOrder
 * @property-read \App\Models\ShoppingReceipt|null $shoppingReceipt
 */
class Order extends Model
{
    protected $fillable = [
        'order_number',
        'user_id',
        'service_type_id',
        'driver_id',
        'assigned_at',
        'delivery_fee',
        'delivery_fee_source',
        'route_snapshot',
        'total_price',
        'status_id',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
        'delivered_at',
    ];

    protected $hidden = [
        'route_snapshot',
    ];

    protected $appends = [
        'restaurant_id',
        'delivery_address',
        'delivery_latitude',
        'delivery_longitude',
        'payment_status',
        'payment_method',
        'paid_amount',
        'paid_by_user_id',
        'paid_at',
        'subtotal',
        'service_fee',
        'shopping_stops',
        'route',
        'shopping_route',
        'delivery_fee_negotiation',
        'shopping_negotiation',
        'shopping_item_change_request',
        'shopping_capabilities',
        'delivery_distance_km',
        'delivery_distance_text',
        'pricing_snapshot',
        'fee_breakdown',
        'delivery_fee_change_note',
        'payment_proof_feedback',
        'proofs',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'delivery_fee' => 'decimal:2',
            'route_snapshot' => 'array',
            'total_price' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function setAttribute($key, $value)
    {
        if (in_array($key, ['restaurant_id', 'subtotal', 'service_fee'], true)) {
            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOneThrough<Restaurant, OrderLocation, $this>
     */
    public function restaurant(): HasOneThrough
    {
        return $this->hasOneThrough(
            Restaurant::class,
            OrderLocation::class,
            'order_id',
            'id',
            'id',
            'restaurant_id'
        )
            ->where('order_locations.location_role', 'PICKUP')
            ->whereNotNull('order_locations.restaurant_id')
            ->orderBy('order_locations.sequence_no');
    }

    /**
     * @return BelongsTo<ServiceType, $this>
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<OrderStatus, $this>
     */
    public function statusRef(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /**
     * @return HasMany<OrderLocation, $this>
     */
    public function orderLocations(): HasMany
    {
        return $this->hasMany(OrderLocation::class);
    }

    /**
     * @return HasMany<OrderLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->orderLocations();
    }

    /**
     * @return HasMany<OrderEvidence, $this>
     */
    public function evidences(): HasMany
    {
        return $this->hasMany(OrderEvidence::class);
    }

    /**
     * @return HasMany<OrderLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(OrderLog::class);
    }

    /**
     * @return HasOne<OrderPayment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(OrderPayment::class);
    }

    /**
     * @return HasMany<OrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    /**
     * @return HasMany<OrderChatMessage, $this>
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(OrderChatMessage::class);
    }

    /**
     * @return HasOne<RideOrder, $this>
     */
    public function rideOrder(): HasOne
    {
        return $this->hasOne(RideOrder::class);
    }

    /**
     * @return HasOne<CourierOrder, $this>
     */
    public function courierOrder(): HasOne
    {
        return $this->hasOne(CourierOrder::class);
    }

    /**
     * @return HasOne<ShoppingReceipt, $this>
     */
    public function shoppingReceipt(): HasOne
    {
        return $this->hasOne(ShoppingReceipt::class);
    }

    public function getTotalAmountAttribute(): ?string
    {
        return $this->total_price;
    }

    public function getRestaurantIdAttribute(): ?int
    {
        if (! $this->relationLoaded('orderLocations')) {
            $this->setRelation('orderLocations', $this->orderLocations()->get());
        }

        $pickup = $this->orderLocations
            ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
            ->first(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP'
                && $location->restaurant_id !== null);

        return $pickup?->restaurant_id !== null ? (int) $pickup->restaurant_id : null;
    }

    public function getDriverIdAttribute(mixed $value = null): ?int
    {
        $driverId = $value ?? ($this->attributes['driver_id'] ?? null);

        return $driverId !== null
            ? (int) $driverId
            : null;
    }

    public function getSubtotalAttribute(mixed $value = null): string
    {
        $stored = $value ?? ($this->attributes['subtotal'] ?? null);
        if ($stored !== null) {
            return number_format((float) $stored, 2, '.', '');
        }

        if (strtoupper((string) ($this->serviceType?->code ?? '')) === 'SHOPPING') {
            return number_format(app(ShoppingPricingService::class)->subtotalAmount($this), 2, '.', '');
        }

        return '0.00';
    }

    public function getDeliveryFeeAttribute(mixed $value = null): string
    {
        return number_format((float) ($value ?? $this->attributes['delivery_fee'] ?? 0), 2, '.', '');
    }

    public function getDeliveryFeeSourceAttribute(mixed $value = null): string
    {
        $source = strtolower((string) ($value ?? $this->attributes['delivery_fee_source'] ?? 'system'));

        return $source === 'manual' ? 'driver_manual' : $source;
    }

    public function getDeliveryFeeChangeNoteAttribute(): ?string
    {
        if ($this->delivery_fee_source !== 'driver_manual') {
            return null;
        }

        $driverQuote = $this->logs()
            ->where('event_type', DeliveryFeeNegotiationService::EVENT_TYPE)
            ->whereIn('trigger_type', [
                DeliveryFeeNegotiationService::DRIVER_FEE_QUOTED,
                DeliveryFeeNegotiationService::DRIVER_FEE_REQUOTED,
            ])
            ->latest('created_at')
            ->latest('id')
            ->first();

        $driverQuoteNote = trim((string) ($driverQuote?->note ?? ''));
        if ($driverQuoteNote !== '') {
            return $driverQuoteNote;
        }

        $event = $this->logs()
            ->whereIn('event_type', ['PRICE_RECALCULATION', 'PRICE_UPDATE'])
            ->whereIn('trigger_type', [
                'DRIVER_DELIVERY_FEE_OVERRIDE',
                'DRIVER_SHOPPING_CHECKOUT_DELIVERY_FEE',
                'CUSTOMER_DELIVERY_FEE_APPROVED',
                'DRIVER_DELIVERY_FEE_COUNTER_APPROVED',
                DeliveryFeeNegotiationService::DRIVER_FEE_APPROVED_BY_DRIVER_BYPASS,
            ])
            ->latest('created_at')
            ->latest('id')
            ->first();

        if (! $event) {
            return null;
        }

        $reason = trim((string) data_get($event->metadata ?? [], 'reason', ''));
        if ($reason !== '') {
            return $reason;
        }

        $note = trim((string) ($event->note ?? ''));

        return $note !== '' ? $note : null;
    }

    public function getServiceFeeAttribute(mixed $value = null): string
    {
        $stored = $value ?? ($this->attributes['service_fee'] ?? null);
        if ($stored !== null) {
            return number_format((float) $stored, 2, '.', '');
        }

        if (strtoupper((string) ($this->serviceType?->code ?? '')) === 'SHOPPING') {
            return number_format(app(ShoppingPricingService::class)->serviceFeeAmount($this), 2, '.', '');
        }

        return '0.00';
    }

    public function getTotalPriceAttribute(mixed $value = null): string
    {
        return number_format((float) ($value ?? $this->attributes['total_price'] ?? 0), 2, '.', '');
    }

    public function getDeliveryDistanceKmAttribute(mixed $value = null): ?float
    {
        $distance = $value ?? data_get($this->route_snapshot, 'distance_km');
        if ($distance === null) {
            $meters = data_get($this->route_snapshot, 'distance_meters');
            $distance = is_numeric($meters) ? ((float) $meters / 1000) : null;
        }

        return is_numeric($distance) ? round((float) $distance, 2) : null;
    }

    public function getDeliveryDistanceTextAttribute(mixed $value = null): ?string
    {
        $text = $value ?? data_get($this->route_snapshot, 'distance_text');
        $normalized = trim((string) ($text ?? ''));
        if ($normalized !== '') {
            return $normalized;
        }

        $distance = $this->delivery_distance_km;

        return $distance !== null ? number_format($distance, 1, ',', '.').' km' : null;
    }

    public function getRouteSnapshotAttribute(mixed $value = null): ?array
    {
        $snapshot = $value ?? ($this->attributes['route_snapshot'] ?? null);

        if (is_array($snapshot)) {
            return $snapshot;
        }

        if (is_string($snapshot) && $snapshot !== '') {
            $decoded = json_decode($snapshot, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    public function getCancellationReasonAttribute(mixed $value = null): ?string
    {
        return $value ?? ($this->attributes['cancellation_reason'] ?? null);
    }

    public function getCancelledByAttribute(mixed $value = null): ?string
    {
        return $value ?? ($this->attributes['cancelled_by'] ?? null);
    }

    public function getDeliveredAtAttribute(mixed $value = null): mixed
    {
        if ($value !== null || ($this->attributes['delivered_at'] ?? null) !== null) {
            return $this->asDateTime($value ?? $this->attributes['delivered_at']);
        }

        if (! $this->relationLoaded('statusHistories')) {
            $this->setRelation('statusHistories', $this->statusHistories()->with('statusRef')->orderBy('created_at')->get());
        } else {
            $this->statusHistories->loadMissing('statusRef');
        }

        return $this->statusHistories
            ->first(fn (OrderStatusHistory $history): bool => in_array(
                strtoupper((string) ($history->statusRef?->code ?? '')),
                ['DELIVERED', 'COMPLETED'],
                true
            ))
            ?->created_at;
    }

    public function getDeliveryAddressAttribute(): ?string
    {
        return $this->resolvedDropoffLocation()?->full_address;
    }

    public function getDeliveryLatitudeAttribute(): ?string
    {
        return $this->resolvedDropoffLocation()?->latitude;
    }

    public function getDeliveryLongitudeAttribute(): ?string
    {
        return $this->resolvedDropoffLocation()?->longitude;
    }

    public function getPaymentStatusAttribute(): string
    {
        return $this->resolvedPaidPayment() !== null ? 'paid' : 'unpaid';
    }

    public function getPaymentMethodAttribute(): string
    {
        return (string) ($this->resolvedLatestPayment()?->payment_method ?? 'COD');
    }

    public function getPaidAmountAttribute(): string
    {
        return (string) ($this->resolvedPaidPayment()?->amount ?? '0.00');
    }

    public function getPaidByUserIdAttribute(): ?int
    {
        $recordedBy = $this->resolvedPaidPayment()?->recorded_by_user_id;

        return $recordedBy !== null ? (int) $recordedBy : null;
    }

    public function getPaidAtAttribute(): mixed
    {
        return $this->resolvedPaidPayment()?->paid_at;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getShoppingStopsAttribute(): array
    {
        $serviceCode = strtoupper((string) ($this->serviceType?->code ?? ''));
        if ($serviceCode !== 'SHOPPING') {
            return [];
        }

        if (! $this->relationLoaded('orderLocations')) {
            $this->setRelation('orderLocations', $this->orderLocations()->with('restaurant')->get());
        } else {
            $this->orderLocations->loadMissing('restaurant');
        }

        if (! $this->relationLoaded('items')) {
            $this->setRelation('items', $this->items()->get());
        }

        $pickups = $this->orderLocations
            ->filter(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'PICKUP')
            ->sortBy([['sequence_no', 'asc'], ['id', 'asc']])
            ->values();

        if ($pickups->isEmpty()) {
            return [];
        }

        $firstPickupId = (int) $pickups->first()->id;

        return $pickups
            ->map(function (OrderLocation $pickup) use ($firstPickupId): array {
                $pickupId = (int) $pickup->id;
                $restaurantId = $pickup->restaurant_id !== null ? (int) $pickup->restaurant_id : null;

                $items = $this->items
                    ->filter(function (OrderItem $item) use ($pickupId, $firstPickupId, $restaurantId): bool {
                        if ($item->pickup_location_id !== null) {
                            return (int) $item->pickup_location_id === $pickupId;
                        }

                        return $pickupId === $firstPickupId
                            && $restaurantId !== null
                            && (int) $this->restaurant_id === $restaurantId;
                    })
                    ->map(fn (OrderItem $item): array => $this->serializeShoppingStopItem($item, $pickupId))
                    ->values()
                    ->all();
                $unavailableItemActions = app(ShoppingUnavailableItemDecisionService::class)
                    ->actionsForPickup($this, $pickupId);

                return [
                    'pickup_location_id' => $pickupId,
                    'sequence_no' => (int) $pickup->sequence_no,
                    'fulfillment_status' => strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')),
                    'failed_attempt_count' => (int) ($pickup->failed_attempt_count ?? 0),
                    'chain_id' => $unavailableItemActions['chain_id'] ?? 'pickup:'.$pickupId,
                    'chain_attempt_no' => (int) ($unavailableItemActions['chain_attempt_no'] ?? 1),
                    'chain_failed_attempt_count' => (int) ($unavailableItemActions['chain_failed_attempt_count'] ?? 0),
                    'chain_failed_attempt_limit' => (int) ($unavailableItemActions['chain_failed_attempt_limit'] ?? 3),
                    'order_failed_trip_count' => (int) ($unavailableItemActions['order_failed_trip_count'] ?? 0),
                    'verified_failed_trip_count' => (int) ($unavailableItemActions['verified_failed_trip_count'] ?? 0),
                    'compensation_eligible' => (bool) ($unavailableItemActions['compensation_eligible'] ?? false),
                    'state_version' => (int) ($unavailableItemActions['state_version'] ?? 0),
                    'unavailable_item_actions' => $unavailableItemActions,
                    'merchant' => [
                        'id' => $restaurantId,
                        'name' => $pickup->restaurant?->name ?? $pickup->label,
                        'merchant_type' => $pickup->restaurant?->merchant_type,
                        'address' => $pickup->full_address,
                        'phone' => $pickup->restaurant?->phone,
                        'latitude' => $pickup->latitude !== null ? (float) $pickup->latitude : null,
                        'longitude' => $pickup->longitude !== null ? (float) $pickup->longitude : null,
                    ],
                    'items' => $items,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRouteAttribute(): ?array
    {
        $route = $this->route_snapshot;
        if (is_array($route)) {
            return $route;
        }

        $serviceCode = strtoupper((string) ($this->serviceType?->code ?? ''));

        return $serviceCode === 'SHOPPING' ? $this->legacyShoppingRouteSnapshot() : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getShoppingRouteAttribute(): ?array
    {
        $serviceCode = strtoupper((string) ($this->serviceType?->code ?? ''));
        if ($serviceCode !== 'SHOPPING') {
            return null;
        }

        $route = $this->route_snapshot;
        if (is_array($route)) {
            return $route;
        }

        return $this->legacyShoppingRouteSnapshot();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getShoppingNegotiationAttribute(): ?array
    {
        return app(ShoppingPriceNegotiationService::class)->snapshot($this);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getShoppingItemChangeRequestAttribute(): ?array
    {
        return app(ShoppingItemChangeRequestService::class)->snapshot($this);
    }

    /**
     * @return array<string, bool>
     */
    public function getShoppingCapabilitiesAttribute(): array
    {
        return app(ShoppingOrderCapabilityService::class)->capabilities($this);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDeliveryFeeNegotiationAttribute(): ?array
    {
        return app(DeliveryFeeNegotiationService::class)->snapshot($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPricingSnapshotAttribute(): array
    {
        $route = $this->route;
        $deliveryPricing = is_array($route) && is_array($route['delivery_pricing'] ?? null)
            ? $route['delivery_pricing']
            : [];

        return [
            'delivery_pricing' => $deliveryPricing,
            'delivery_fee' => round((float) $this->delivery_fee, 2),
            'delivery_fee_source' => $this->delivery_fee_source,
            'route' => $route,
            'shopping_pricing' => [
                'receipt_total_amount' => $this->shoppingReceipt?->total_amount !== null
                    ? round((float) $this->shoppingReceipt->total_amount, 2)
                    : null,
                'fee_breakdown' => $this->shoppingFeeBreakdown(),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFeeBreakdownAttribute(): array
    {
        $pricing = $this->pricing_snapshot;
        $deliveryPricing = $pricing['delivery_pricing'] ?? [];
        $breakdown = is_array($deliveryPricing) && is_array($deliveryPricing['fee_breakdown'] ?? null)
            ? $deliveryPricing['fee_breakdown']
            : [];

        $serviceFee = round((float) $this->service_fee, 2);
        if ($serviceFee > 0) {
            array_push($breakdown, ...$this->shoppingFeeBreakdown());
        }

        if ($this->delivery_fee_source === 'driver_manual') {
            $breakdown[] = [
                'code' => 'manual_override',
                'label' => 'Ongkir manual driver',
                'amount' => round((float) $this->delivery_fee, 2),
            ];
        }

        return array_values($breakdown);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProofsAttribute(): array
    {
        if (! $this->relationLoaded('evidences')) {
            $this->setRelation('evidences', $this->evidences()->get());
        }

        $proofStatuses = app(AdminPaymentProofStatusService::class);
        $logs = $proofStatuses->decisionLogsForOrder($this);
        $payment = $this->resolvedLatestPayment();
        $evidencePickupMap = $this->evidencePickupLocationMap();

        return $this->evidences
            ->sortByDesc(fn (OrderEvidence $evidence): int => $evidence->uploaded_at?->getTimestamp() ?? $evidence->created_at?->getTimestamp() ?? 0)
            ->map(function (OrderEvidence $evidence) use ($proofStatuses, $logs, $payment, $evidencePickupMap): array {
                $decision = strtoupper((string) $evidence->evidence_type) === AdminPaymentProofStatusService::PAYMENT_TRANSFER_EVIDENCE_TYPE
                    ? $proofStatuses->decisionFor($evidence, $payment, $logs)
                    : null;

                return [
                    'id' => (int) $evidence->id,
                    'user_id' => (int) $evidence->user_id,
                    'uploader_user_id' => (int) $evidence->user_id,
                    'type' => $this->canonicalProofType((string) $evidence->evidence_type),
                    'evidence_type' => strtoupper((string) $evidence->evidence_type),
                    'photo_url' => $evidence->file_url,
                    'file_url' => $evidence->file_url,
                    'pickup_location_id' => $evidencePickupMap[(int) $evidence->id] ?? null,
                    'status' => $decision['status'] ?? 'pending',
                    'verification_status' => $decision['status'] ?? 'pending',
                    'rejection_reason' => $decision['reason'] ?? null,
                    'decision_note' => $decision['note'] ?? null,
                    'decided_by' => $decision['decided_by'] ?? null,
                    'decided_at' => $decision['decided_at'] ?? null,
                    'uploaded_at' => $evidence->uploaded_at?->toIso8601String() ?? $evidence->created_at?->toIso8601String(),
                    'note' => $evidence->notes,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Peta evidence_id -> pickup_location_id dari event kegagalan Nitip, supaya
     * foto "toko tutup" bisa ditautkan ke stop yang benar di UI (order_evidence
     * sendiri tidak menyimpan pickup_location_id).
     *
     * @return array<int, int>
     */
    private function evidencePickupLocationMap(): array
    {
        return OrderLog::query()
            ->where('order_id', $this->id)
            ->where('event_type', 'SHOPPING_FAILED_TRIP')
            ->get()
            ->reduce(function (array $carry, OrderLog $log): array {
                $metadata = is_array($log->metadata) ? $log->metadata : [];
                $evidenceId = (int) ($metadata['evidence_id'] ?? 0);
                $pickupId = (int) ($metadata['pickup_location_id'] ?? 0);
                if ($evidenceId > 0 && $pickupId > 0) {
                    $carry[$evidenceId] = $pickupId;
                }

                return $carry;
            }, []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentProofFeedbackAttribute(): array
    {
        return app(AdminPaymentProofStatusService::class)->feedbackForOrder($this);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function legacyShoppingRouteSnapshot(): ?array
    {
        return null;
    }

    private function canonicalProofType(string $evidenceType): string
    {
        return match (strtoupper($evidenceType)) {
            'PICKUP_PHOTO' => 'pickup',
            'DELIVERY_PHOTO', 'COURIER_DELIVERY_PHOTO', 'COURIER_RECEIVER_PHOTO' => 'delivery',
            'SHOPPING_RECEIPT' => 'receipt',
            'STORE_CLOSED_PHOTO' => 'store_closed',
            'PAYMENT_TRANSFER_PHOTO' => 'payment_transfer',
            default => strtolower($evidenceType),
        };
    }

    private function resolvedDropoffLocation(): ?OrderLocation
    {
        if (! $this->relationLoaded('orderLocations')) {
            $this->setRelation('orderLocations', $this->orderLocations()->get());
        }

        return $this->orderLocations
            ->sortBy('sequence_no')
            ->first(fn (OrderLocation $location): bool => strtoupper((string) $location->location_role) === 'DROPOFF');
    }

    private function resolvedLatestPayment(): ?OrderPayment
    {
        if ($this->relationLoaded('payment')) {
            return $this->payment;
        }

        if ($this->relationLoaded('payments')) {
            return $this->payments
                ->sortByDesc(fn (OrderPayment $payment): int => $payment->paid_at?->getTimestamp() ?? 0)
                ->first();
        }

        $payment = $this->payment()->first();
        $this->setRelation('payment', $payment);

        return $payment;
    }

    private function resolvedPaidPayment(): ?OrderPayment
    {
        if ($this->relationLoaded('payment')) {
            return strtoupper((string) $this->payment?->payment_status) === 'PAID'
                ? $this->payment
                : null;
        }

        if ($this->relationLoaded('payments')) {
            return $this->payments
                ->where('payment_status', 'PAID')
                ->sortByDesc(fn (OrderPayment $payment): int => $payment->paid_at?->getTimestamp() ?? 0)
                ->first();
        }

        $payment = $this->payment()
            ->where('payment_status', 'PAID')
            ->first();

        if ($payment !== null) {
            $this->setRelation('payment', $payment);
        }

        return $payment;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function shoppingFeeBreakdown(): array
    {
        return app(ShoppingPricingService::class)->feeBreakdownForOrder($this);
    }

    private function feeLineDescription(string $code): string
    {
        return match (strtoupper($code)) {
            'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS' => '50% ongkir setelah batas percobaan gagal',
            default => '',
        };
    }

    private function statusCode(): string
    {
        if (! $this->relationLoaded('statusRef')) {
            $this->setRelation('statusRef', $this->statusRef()->first());
        }

        return strtoupper((string) ($this->statusRef?->code ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeShoppingStopItem(OrderItem $item, int $fallbackPickupLocationId): array
    {
        $isManual = strtoupper((string) $item->item_source) === 'MANUAL';
        $isAvailable = (bool) $item->is_available;
        $unitPrice = (float) $item->unit_price;

        return [
            'id' => (int) $item->id,
            'pickup_location_id' => $item->pickup_location_id !== null
                ? (int) $item->pickup_location_id
                : $fallbackPickupLocationId,
            'menu_id' => $item->menu_id !== null ? (int) $item->menu_id : null,
            'item_source' => strtoupper((string) $item->item_source),
            'name' => (string) $item->menu_name,
            'menu_name' => (string) $item->menu_name,
            'quantity' => (int) $item->quantity,
            'unit_price' => round($unitPrice, 2),
            'subtotal' => round((float) $item->subtotal, 2),
            'line_total' => round((float) $item->subtotal, 2),
            'notes' => $item->notes,
            'is_available' => $isAvailable,
            'price_status' => $this->shoppingItemPriceStatus($item, $isManual, $isAvailable, $unitPrice),
        ];
    }

    private function shoppingItemPriceStatus(OrderItem $item, bool $isManual, bool $isAvailable, float $unitPrice): string
    {
        $metadataStatus = (string) data_get($item->metadata, 'price_status', '');
        if ($metadataStatus !== '') {
            return $metadataStatus;
        }

        return $isManual && $isAvailable && $unitPrice <= 0
            ? 'PENDING_DRIVER_INPUT'
            : 'CONFIRMED';
    }
}
