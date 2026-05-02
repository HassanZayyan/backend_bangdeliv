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
 * @property string|null $service_fee
 * @property float|null $delivery_distance_km
 * @property string|null $delivery_distance_text
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
        'service_fee',
        'delivery_distance_km',
        'delivery_distance_text',
        'total_price',
        'status_id',
        'cancellation_reason',
        'cancelled_by',
        'estimated_delivery',
        'delivered_at',
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
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'delivery_distance_km' => 'float',
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
}
