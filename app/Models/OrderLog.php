<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLog extends Model
{
    protected $table = 'order_events';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'event_type',
        'log_type',
        'new_status_id',
        'trigger_type',
        'changed_by_user_id',
        'note',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function setAttribute($key, $value)
    {
        if ($key === 'log_type') {
            $key = 'event_type';
        }

        return parent::setAttribute($key, $value);
    }

    public function getLogTypeAttribute(): ?string
    {
        return $this->event_type;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function newStatus(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'new_status_id');
    }
}
