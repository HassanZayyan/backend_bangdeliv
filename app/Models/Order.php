<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $order_number
 * @property int $user_id
 * @property int|null $restaurant_id
 * @property int $service_type_id
 * @property int|null $driver_id
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
 * @property-read \App\Models\ShoppingOrder|null $shoppingOrder
 * @property-read \App\Models\Review|null $review
 */
class Order extends Model
{
    use SoftDeletes;

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
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'manual_delivery_fee' => 'decimal:2',
            'careful_carry_required' => 'boolean',
            'service_fee' => 'decimal:2',
            'delivery_distance_km' => 'float',
            'route_snapshot' => 'array',
            'total_price' => 'decimal:2',
            'estimated_delivery' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function statusRef(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
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

    public function shoppingOrder(): HasOne
    {
        return $this->hasOne(ShoppingOrder::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function getTotalAmountAttribute(): ?string
    {
        return $this->attributes['total_price'] ?? null;
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
            'delivery_fee' => round((float) ($this->attributes['delivery_fee'] ?? 0), 2),
            'delivery_fee_source' => $this->attributes['delivery_fee_source'] ?? 'system',
            'manual_delivery_fee' => isset($this->attributes['manual_delivery_fee'])
                ? round((float) $this->attributes['manual_delivery_fee'], 2)
                : null,
            'manual_delivery_fee_reason' => $this->attributes['manual_delivery_fee_reason'] ?? null,
            'careful_carry_required' => (bool) ($this->attributes['careful_carry_required'] ?? false),
            'route' => $route,
            'shopping_pricing' => $this->shoppingOrder?->pricing_snapshot,
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

        $serviceFee = round((float) ($this->attributes['service_fee'] ?? 0), 2);
        if ($serviceFee > 0) {
            $breakdown[] = [
                'code' => 'service_fee',
                'label' => 'Biaya layanan',
                'amount' => $serviceFee,
            ];
        }

        if ((bool) ($this->attributes['careful_carry_required'] ?? false)) {
            $breakdown[] = [
                'code' => 'careful_carry',
                'label' => 'Bawa hati-hati',
                'amount' => $this->carefulCarrySurchargeAmount(),
            ];
        }

        if (($this->attributes['delivery_fee_source'] ?? 'system') === 'manual') {
            $breakdown[] = [
                'code' => 'manual_override',
                'label' => 'Ongkir manual driver',
                'amount' => round((float) ($this->attributes['manual_delivery_fee'] ?? $this->attributes['delivery_fee'] ?? 0), 2),
                'reason' => $this->attributes['manual_delivery_fee_reason'] ?? null,
            ];
        }

        return array_values($breakdown);
    }

    private function carefulCarrySurchargeAmount(): float
    {
        if (($this->attributes['delivery_fee_source'] ?? 'system') === 'manual' && isset($this->attributes['manual_delivery_fee'])) {
            return round(max(0.0, (float) $this->attributes['manual_delivery_fee']) * 0.5, 2);
        }

        $route = $this->route;
        $systemFee = is_array($route) && is_numeric(data_get($route, 'delivery_pricing.total_fee'))
            ? (float) data_get($route, 'delivery_pricing.total_fee')
            : (float) ($this->attributes['delivery_fee'] ?? 0);

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
        if (! $this->relationLoaded('shoppingOrder')) {
            $this->load('shoppingOrder');
        }

        $snapshot = $this->shoppingOrder?->pricing_snapshot;
        if (! is_array($snapshot)) {
            return null;
        }

        $route = $snapshot['shopping_route'] ?? null;

        return is_array($route) ? $route : null;
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
