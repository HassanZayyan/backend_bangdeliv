<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $order_number
 * @property int $user_id
 * @property int $service_type_id
 * @property string|null $delivery_address
 * @property string|null $delivery_latitude
 * @property string|null $delivery_longitude
 * @property string|null $subtotal
 * @property string $delivery_fee
 * @property string|null $delivery_fee_source
 * @property string|null $manual_delivery_fee
 * @property string|null $manual_delivery_fee_reason
 * @property bool $careful_carry_required
 * @property string|null $service_fee
 * @property float|null $delivery_distance_km
 * @property string|null $delivery_distance_text
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
 * @property \Carbon\Carbon|null $estimated_delivery
 * @property \Carbon\Carbon|null $delivered_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Restaurant|null $restaurant
 * @property-read \App\Models\ServiceType|null $serviceType
 * @property-read \App\Models\Driver|null $driver
 * @property-read \App\Models\Address|null $address
 * @property-read \App\Models\OrderStatus|null $statusRef
 * @property-read \App\Models\User|null $paidBy
 * @property-read \App\Models\OrderPricing|null $pricing
 * @property-read \App\Models\OrderDeliveryFeeOverride|null $deliveryFeeOverride
 * @property-read \App\Models\OrderRoute|null $routeInfo
 * @property-read \App\Models\OrderAssignment|null $assignment
 * @property-read \App\Models\OrderCancellation|null $cancellation
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderFeeLine> $feeLines
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderStatusHistory> $statusHistories
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderLocation> $orderLocations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderLocation> $locations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderEvidence> $evidences
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderLog> $logs
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderPayment> $payments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderChatMessage> $chatMessages
 * @property-read \App\Models\RideOrder|null $rideOrder
 * @property-read \App\Models\CourierOrder|null $courierOrder
 * @property-read \App\Models\ShoppingReceipt|null $shoppingReceipt
 */
class Order extends Model
{
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    private array $pendingPricingAttributes = [];

    /**
     * @var array<string, mixed>
     */
    private array $pendingDeliveryFeeOverrideAttributes = [];

    /**
     * @var array<string, mixed>
     */
    private array $pendingRouteAttributes = [];

    /**
     * @var array<string, mixed>
     */
    private array $pendingAssignmentAttributes = [];

    /**
     * @var array<string, mixed>
     */
    private array $pendingCancellationAttributes = [];

    protected $fillable = [
        'order_number',
        'user_id',
        'restaurant_id',
        'service_type_id',
        'driver_id',
        'subtotal',
        'delivery_fee',
        'delivery_fee_source',
        'manual_delivery_fee',
        'manual_delivery_fee_reason',
        'careful_carry_required',
        'service_fee',
        'delivery_distance_km',
        'delivery_distance_text',
        'route_snapshot',
        'total_price',
        'status_id',
        'cancellation_reason',
        'cancelled_by',
        'estimated_delivery',
        'delivered_at',
    ];

    protected $hidden = [
        'route_snapshot',
    ];

    protected $appends = [
        'restaurant_id',
        'driver_id',
        'subtotal',
        'delivery_fee',
        'delivery_fee_source',
        'manual_delivery_fee',
        'manual_delivery_fee_reason',
        'careful_carry_required',
        'service_fee',
        'delivery_distance_km',
        'delivery_distance_text',
        'total_price',
        'estimated_delivery',
        'cancellation_reason',
        'cancelled_by',
        'delivered_at',
        'delivery_address',
        'delivery_latitude',
        'delivery_longitude',
        'payment_status',
        'payment_method',
        'paid_amount',
        'paid_by_user_id',
        'paid_at',
        'shopping_stops',
        'route',
        'shopping_route',
        'pricing_snapshot',
        'fee_breakdown',
        'proofs',
    ];

    protected function casts(): array
    {
        return [
        ];
    }

    public function setAttribute($key, $value)
    {
        if (in_array($key, [
            'subtotal',
            'delivery_fee',
            'careful_carry_required',
            'total_price',
        ], true)) {
            $this->pendingPricingAttributes[$key] = $value;
            return $this;
        }

        if (in_array($key, [
            'delivery_fee_source',
            'manual_delivery_fee',
            'manual_delivery_fee_reason',
        ], true)) {
            $this->pendingDeliveryFeeOverrideAttributes[$key] = $value;
            return $this;
        }

        if ($key === 'service_fee') {
            return $this;
        }

        if (in_array($key, [
            'delivery_distance_km',
            'delivery_distance_text',
            'route_snapshot',
            'estimated_delivery',
        ], true)) {
            $this->pendingRouteAttributes[$key] = $value;
            return $this;
        }

        if ($key === 'driver_id') {
            $this->pendingAssignmentAttributes[$key] = $value;
            return $this;
        }

        if ($key === 'cancellation_reason') {
            $this->pendingCancellationAttributes['reason'] = $value;
            return $this;
        }

        if ($key === 'cancelled_by') {
            $this->pendingCancellationAttributes['cancelled_by'] = $value;
            return $this;
        }

        if (in_array($key, ['restaurant_id', 'delivered_at'], true)) {
            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function save(array $options = []): bool
    {
        $saved = parent::save($options);

        if ($saved && $this->exists) {
            $this->persistPendingDetailAttributes();
        }

        return $saved;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function driver(): HasOneThrough
    {
        return $this->hasOneThrough(
            Driver::class,
            OrderAssignment::class,
            'order_id',
            'id',
            'id',
            'driver_id'
        );
    }

    public function statusRef(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status_id');
    }

    public function pricing(): HasOne
    {
        return $this->hasOne(OrderPricing::class);
    }

    public function deliveryFeeOverride(): HasOne
    {
        return $this->hasOne(OrderDeliveryFeeOverride::class);
    }

    public function feeLines(): HasMany
    {
        return $this->hasMany(OrderFeeLine::class);
    }

    public function routeInfo(): HasOne
    {
        return $this->hasOne(OrderRoute::class);
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(OrderAssignment::class);
    }

    public function cancellation(): HasOne
    {
        return $this->hasOne(OrderCancellation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function orderLocations(): HasMany
    {
        return $this->hasMany(OrderLocation::class);
    }

    public function locations(): HasMany
    {
        return $this->orderLocations();
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(OrderEvidence::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(OrderLog::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(OrderChatMessage::class);
    }

    public function rideOrder(): HasOne
    {
        return $this->hasOne(RideOrder::class);
    }

    public function courierOrder(): HasOne
    {
        return $this->hasOne(CourierOrder::class);
    }

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

    public function getDriverIdAttribute(): ?int
    {
        $assignment = $this->resolvedAssignment();

        return $assignment?->driver_id !== null
            ? (int) $assignment->driver_id
            : null;
    }

    public function getSubtotalAttribute(): string
    {
        return (string) ($this->resolvedPricing()?->subtotal ?? '0.00');
    }

    public function getDeliveryFeeAttribute(): string
    {
        return (string) ($this->resolvedPricing()?->delivery_fee ?? '0.00');
    }

    public function getDeliveryFeeSourceAttribute(): string
    {
        return $this->resolvedDeliveryFeeOverride() !== null ? 'manual' : 'system';
    }

    public function getManualDeliveryFeeAttribute(): ?string
    {
        return $this->resolvedDeliveryFeeOverride()?->amount;
    }

    public function getManualDeliveryFeeReasonAttribute(): ?string
    {
        return $this->resolvedDeliveryFeeOverride()?->reason;
    }

    public function getCarefulCarryRequiredAttribute(): bool
    {
        return (bool) ($this->resolvedPricing()?->careful_carry_required ?? false);
    }

    public function getServiceFeeAttribute(): string
    {
        return number_format($this->resolvedServiceFee(), 2, '.', '');
    }

    public function getTotalPriceAttribute(): string
    {
        return (string) ($this->resolvedPricing()?->total_price ?? '0.00');
    }

    public function getDeliveryDistanceKmAttribute(): ?float
    {
        return $this->resolvedRouteInfo()?->delivery_distance_km;
    }

    public function getDeliveryDistanceTextAttribute(): ?string
    {
        return $this->resolvedRouteInfo()?->delivery_distance_text;
    }

    public function getRouteSnapshotAttribute(): ?array
    {
        return $this->resolvedRouteInfo()?->route_snapshot;
    }

    public function getEstimatedDeliveryAttribute(): mixed
    {
        return $this->resolvedRouteInfo()?->estimated_delivery;
    }

    public function getCancellationReasonAttribute(): ?string
    {
        return $this->resolvedCancellation()?->reason;
    }

    public function getCancelledByAttribute(): ?string
    {
        return $this->resolvedCancellation()?->cancelled_by;
    }

    public function getDeliveredAtAttribute(): mixed
    {
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

                return [
                    'pickup_location_id' => $pickupId,
                    'sequence_no' => (int) $pickup->sequence_no,
                    'fulfillment_status' => strtoupper((string) ($pickup->fulfillment_status ?? 'PENDING')),
                    'failed_attempt_count' => (int) ($pickup->failed_attempt_count ?? 0),
                    'failure_reason' => $pickup->failure_reason,
                    'failed_at' => $pickup->failed_at?->toIso8601String(),
                    'resolved_at' => $pickup->resolved_at?->toIso8601String(),
                    'merchant' => [
                        'id' => $restaurantId,
                        'name' => $pickup->restaurant?->name ?? $pickup->contact_name ?? $pickup->label,
                        'merchant_type' => $pickup->restaurant?->merchant_type,
                        'address' => $pickup->full_address,
                        'phone' => $pickup->contact_phone,
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
            'manual_delivery_fee' => $this->manual_delivery_fee !== null
                ? round((float) $this->manual_delivery_fee, 2)
                : null,
            'manual_delivery_fee_reason' => $this->manual_delivery_fee_reason,
            'careful_carry_required' => (bool) $this->careful_carry_required,
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

        if ((bool) $this->careful_carry_required) {
            $breakdown[] = [
                'code' => 'careful_carry',
                'label' => 'Bawa hati-hati',
                'amount' => $this->carefulCarrySurchargeAmount(),
            ];
        }

        if ($this->delivery_fee_source === 'manual') {
            $breakdown[] = [
                'code' => 'manual_override',
                'label' => 'Ongkir manual driver',
                'amount' => round((float) ($this->manual_delivery_fee ?? $this->delivery_fee), 2),
                'reason' => $this->manual_delivery_fee_reason,
            ];
        }

        return array_values($breakdown);
    }

    private function carefulCarrySurchargeAmount(): float
    {
        if ($this->delivery_fee_source === 'manual' && $this->manual_delivery_fee !== null) {
            return round(max(0.0, (float) $this->manual_delivery_fee) * 0.5, 2);
        }

        $route = $this->route;
        $systemFee = is_array($route) && is_numeric(data_get($route, 'delivery_pricing.total_fee'))
            ? (float) data_get($route, 'delivery_pricing.total_fee')
            : (float) $this->delivery_fee;

        return round(max(0.0, $systemFee) * 0.5, 2);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProofsAttribute(): array
    {
        if (! $this->relationLoaded('evidences')) {
            $this->setRelation('evidences', $this->evidences()->get());
        }

        return $this->evidences
            ->sortByDesc(fn (OrderEvidence $evidence): int => $evidence->uploaded_at?->getTimestamp() ?? $evidence->created_at?->getTimestamp() ?? 0)
            ->map(function (OrderEvidence $evidence): array {
                return [
                    'id' => (int) $evidence->id,
                    'type' => $this->canonicalProofType((string) $evidence->evidence_type),
                    'evidence_type' => strtoupper((string) $evidence->evidence_type),
                    'photo_url' => $evidence->file_url,
                    'file_url' => $evidence->file_url,
                    'status' => strtolower((string) ($evidence->verification_status ?? 'pending')),
                    'uploaded_at' => $evidence->uploaded_at?->toIso8601String() ?? $evidence->created_at?->toIso8601String(),
                    'note' => $evidence->notes,
                ];
            })
            ->values()
            ->all();
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
        if (! $this->relationLoaded('payments')) {
            $this->setRelation('payments', $this->payments()->get());
        }

        return $this->payments
            ->sortByDesc(fn (OrderPayment $payment): int => $payment->paid_at?->getTimestamp() ?? 0)
            ->first();
    }

    private function resolvedPaidPayment(): ?OrderPayment
    {
        if (! $this->relationLoaded('payments')) {
            $this->setRelation('payments', $this->payments()->get());
        }

        return $this->payments
            ->where('payment_status', 'PAID')
            ->sortByDesc(fn (OrderPayment $payment): int => $payment->paid_at?->getTimestamp() ?? 0)
            ->first();
    }

    private function resolvedPricing(): ?OrderPricing
    {
        if (! $this->relationLoaded('pricing')) {
            $this->setRelation('pricing', $this->pricing()->first());
        }

        return $this->getRelation('pricing');
    }

    private function resolvedDeliveryFeeOverride(): ?OrderDeliveryFeeOverride
    {
        if (! $this->relationLoaded('deliveryFeeOverride')) {
            $this->setRelation('deliveryFeeOverride', $this->deliveryFeeOverride()->first());
        }

        return $this->getRelation('deliveryFeeOverride');
    }

    private function resolvedServiceFee(): float
    {
        if (! $this->relationLoaded('feeLines')) {
            $this->setRelation('feeLines', $this->feeLines()->get());
        }

        return round((float) $this->feeLines->sum(fn (OrderFeeLine $line): float => (float) $line->amount), 2);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function shoppingFeeBreakdown(): array
    {
        if (! $this->relationLoaded('feeLines')) {
            $this->setRelation('feeLines', $this->feeLines()->get());
        }

        return $this->feeLines
            ->filter(fn (OrderFeeLine $line): bool => (float) $line->amount > 0)
            ->map(fn (OrderFeeLine $line): array => [
                'code' => (string) $line->code,
                'label' => (string) $line->label,
                'description' => $this->feeLineDescription((string) $line->code),
                'amount' => round((float) $line->amount, 2),
            ])
            ->values()
            ->all();
    }

    private function feeLineDescription(string $code): string
    {
        return match (strtoupper($code)) {
            'ITEM_BLOCK_SURCHARGE' => 'Tambahan saat jumlah item melewati batas gratis',
            'OVERWEIGHT_FLAT_SURCHARGE' => 'Dikenakan sekali per order',
            'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS' => '50% ongkir setelah batas percobaan gagal',
            default => '',
        };
    }

    private function resolvedRouteInfo(): ?OrderRoute
    {
        if (! $this->relationLoaded('routeInfo')) {
            $this->setRelation('routeInfo', $this->routeInfo()->first());
        }

        return $this->getRelation('routeInfo');
    }

    private function resolvedAssignment(): ?OrderAssignment
    {
        if (! $this->relationLoaded('assignment')) {
            $this->setRelation('assignment', $this->assignment()->first());
        }

        return $this->getRelation('assignment');
    }

    private function resolvedCancellation(): ?OrderCancellation
    {
        if (! $this->relationLoaded('cancellation')) {
            $this->setRelation('cancellation', $this->cancellation()->first());
        }

        return $this->getRelation('cancellation');
    }

    private function persistPendingDetailAttributes(): void
    {
        if ($this->pendingPricingAttributes !== []) {
            $pricing = $this->pricing()->updateOrCreate(
                ['order_id' => $this->id],
                $this->pendingPricingAttributes
            );
            $this->setRelation('pricing', $pricing);
            $this->pendingPricingAttributes = [];
        }

        if ($this->pendingDeliveryFeeOverrideAttributes !== []) {
            $source = (string) ($this->pendingDeliveryFeeOverrideAttributes['delivery_fee_source'] ?? $this->delivery_fee_source);
            $manualAmount = $this->pendingDeliveryFeeOverrideAttributes['manual_delivery_fee'] ?? $this->manual_delivery_fee;
            $reason = trim((string) ($this->pendingDeliveryFeeOverrideAttributes['manual_delivery_fee_reason'] ?? $this->manual_delivery_fee_reason ?? ''));

            if ($source === 'manual' && $manualAmount !== null && $manualAmount !== '') {
                $override = $this->deliveryFeeOverride()->updateOrCreate(
                    ['order_id' => $this->id],
                    [
                        'amount' => round((float) $manualAmount, 2),
                        'reason' => $reason !== '' ? $reason : 'Ongkir manual driver.',
                    ]
                );
                $this->setRelation('deliveryFeeOverride', $override);
            } else {
                $this->deliveryFeeOverride()->delete();
                $this->unsetRelation('deliveryFeeOverride');
            }

            $this->pendingDeliveryFeeOverrideAttributes = [];
        }

        if ($this->pendingRouteAttributes !== []) {
            $route = $this->routeInfo()->updateOrCreate(
                ['order_id' => $this->id],
                $this->pendingRouteAttributes
            );
            $this->setRelation('routeInfo', $route);
            $this->pendingRouteAttributes = [];
        }

        if (array_key_exists('driver_id', $this->pendingAssignmentAttributes)) {
            $driverId = $this->pendingAssignmentAttributes['driver_id'];
            if ($driverId === null || $driverId === '') {
                $this->assignment()->delete();
                $this->unsetRelation('assignment');
            } else {
                $assignment = $this->assignment()->updateOrCreate(
                    ['order_id' => $this->id],
                    [
                        'driver_id' => (int) $driverId,
                        'assigned_at' => $this->resolvedAssignment()?->assigned_at ?? now(),
                    ]
                );
                $this->setRelation('assignment', $assignment);
            }

            $this->pendingAssignmentAttributes = [];
        }

        if ($this->pendingCancellationAttributes !== []) {
            $reason = trim((string) ($this->pendingCancellationAttributes['reason'] ?? $this->cancellation_reason ?? ''));
            $cancelledBy = trim((string) ($this->pendingCancellationAttributes['cancelled_by'] ?? $this->cancelled_by ?? ''));
            if ($reason !== '' && $cancelledBy !== '') {
                $cancellation = $this->cancellation()->updateOrCreate(
                    ['order_id' => $this->id],
                    [
                        'reason' => $reason,
                        'cancelled_by' => $cancelledBy,
                        'cancelled_at' => $this->resolvedCancellation()?->cancelled_at ?? now(),
                    ]
                );
                $this->setRelation('cancellation', $cancellation);
            }

            $this->pendingCancellationAttributes = [];
        }
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
            'is_heavy' => (bool) $item->is_heavy,
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
