<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingOrder extends Model
{
    protected $fillable = [
        'order_id',
        'failed_attempt_count',
        'item_surcharge',
        'overweight_surcharge',
        'cancellation_penalty',
        'has_overweight_item',
        'recalculation_version',
        'last_recalculated_at',
        'pricing_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'failed_attempt_count' => 'integer',
            'item_surcharge' => 'decimal:2',
            'overweight_surcharge' => 'decimal:2',
            'cancellation_penalty' => 'decimal:2',
            'has_overweight_item' => 'boolean',
            'recalculation_version' => 'integer',
            'last_recalculated_at' => 'datetime',
            'pricing_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
