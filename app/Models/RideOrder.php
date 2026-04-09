<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RideOrder extends Model
{
    protected $fillable = [
        'order_id',
        'picked_up_at',
        'arrived_at',
        'notes',
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
