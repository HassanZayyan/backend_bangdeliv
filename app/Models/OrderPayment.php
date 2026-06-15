<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property string $payment_method
 * @property string $payment_status
 * @property string $amount
 * @property int|null $recorded_by_user_id
 * @property int|null $driver_id
 * @property \Carbon\Carbon|null $paid_at
 * @property array<string, mixed>|null $metadata
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\User|null $recordedBy
 * @property-read \App\Models\Driver|null $driver
 */
class OrderPayment extends Model
{
    protected $fillable = [
        'order_id',
        'payment_method',
        'payment_status',
        'amount',
        'recorded_by_user_id',
        'driver_id',
        'paid_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
