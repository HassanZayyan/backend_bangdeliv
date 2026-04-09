<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'log_type',
        'trigger_type',
        'old_subtotal',
        'new_subtotal',
        'old_delivery_fee',
        'new_delivery_fee',
        'old_service_fee',
        'new_service_fee',
        'old_total_price',
        'new_total_price',
        'delta_total_price',
        'recalculation_version',
        'changed_by_user_id',
        'note',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_subtotal' => 'decimal:2',
            'new_subtotal' => 'decimal:2',
            'old_delivery_fee' => 'decimal:2',
            'new_delivery_fee' => 'decimal:2',
            'old_service_fee' => 'decimal:2',
            'new_service_fee' => 'decimal:2',
            'old_total_price' => 'decimal:2',
            'new_total_price' => 'decimal:2',
            'delta_total_price' => 'decimal:2',
            'recalculation_version' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
