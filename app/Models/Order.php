<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $order_number
 * @property int $user_id
 * @property int|null $restaurant_id
 * @property int $service_type_id
 * @property int|null $driver_id
 * @property int|null $address_id
 * @property string|null $delivery_address
 * @property string|null $delivery_latitude
 * @property string|null $delivery_longitude
 * @property string|null $subtotal
 * @property string $delivery_fee
 * @property string|null $service_fee
 * @property float|null $delivery_distance_km
 * @property string|null $delivery_distance_text
 * @property string $total_amount
 * @property string|null $total_price
 * @property int $status_id
 * @property string $payment_status
 * @property string|null $payment_method
 * @property string|null $paid_amount
 * @property int|null $paid_by_user_id
 * @property \Carbon\Carbon|null $paid_at
 * @property string|null $cancellation_reason
 * @property string|null $cancelled_by
 * @property string|null $notes
 * @property \Carbon\Carbon|null $estimated_delivery
 * @property \Carbon\Carbon|null $delivered_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
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
        'address_id',
        'delivery_address',
        'delivery_latitude',
        'delivery_longitude',
        'subtotal',
        'delivery_fee',
        'service_fee',
        'delivery_distance_km',
        'delivery_distance_text',
        'total_amount',
        'total_price',
        'status_id',
        'payment_status',
        'payment_method',
        'paid_amount',
        'paid_by_user_id',
        'paid_at',
        'cancellation_reason',
        'cancelled_by',
        'notes',
        'estimated_delivery',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'delivery_latitude' => 'decimal:8',
            'delivery_longitude' => 'decimal:8',
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'delivery_distance_km' => 'float',
            'total_amount' => 'decimal:2',
            'total_price' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'datetime',
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
}
