<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderLog extends Model
{
    protected $table = 'order_events';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'log_type',
        'trigger_type',
        'recalculation_version',
        'changed_by_user_id',
        'note',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
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

    public function priceChange(): HasOne
    {
        return $this->hasOne(OrderPriceChange::class, 'order_event_id');
    }
}
