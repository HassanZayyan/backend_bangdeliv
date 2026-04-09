<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'menu_id',
        'item_source',
        'menu_name',
        'quantity',
        'unit_price',
        'subtotal',
        'line_service_fee',
        'line_total',
        'notes',
        'metadata',
        'is_available',
        'is_heavy',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'line_service_fee' => 'decimal:2',
            'line_total' => 'decimal:2',
            'metadata' => 'array',
            'is_available' => 'boolean',
            'is_heavy' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }
}
