<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property \Carbon\Carbon|null $picked_up_at
 * @property \Carbon\Carbon|null $arrived_at
 * @property-read \App\Models\Order $order
 */
class RideOrder extends Model
{
    protected $table = 'ride_order_details';

    protected $fillable = [
        'order_id',
        'picked_up_at',
        'arrived_at',
    ];

    protected function casts(): array
    {
        return [
            'picked_up_at' => 'datetime',
            'arrived_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
