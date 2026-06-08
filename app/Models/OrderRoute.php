<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRoute extends Model
{
    protected $fillable = [
        'order_id',
        'delivery_distance_km',
        'delivery_distance_text',
        'route_snapshot',
        'estimated_delivery',
    ];

    protected function casts(): array
    {
        return [
            'delivery_distance_km' => 'float',
            'route_snapshot' => 'array',
            'estimated_delivery' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
