<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPriceChangeLine extends Model
{
    protected $fillable = [
        'order_price_change_id',
        'component_code',
        'old_amount',
        'new_amount',
        'delta_amount',
    ];

    protected function casts(): array
    {
        return [
            'old_amount' => 'decimal:2',
            'new_amount' => 'decimal:2',
            'delta_amount' => 'decimal:2',
        ];
    }

    public function priceChange(): BelongsTo
    {
        return $this->belongsTo(OrderPriceChange::class, 'order_price_change_id');
    }
}
