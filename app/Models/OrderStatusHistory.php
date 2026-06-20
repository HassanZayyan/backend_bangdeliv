<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $status_id
 * @property string $event_type
 * @property int|null $changed_by_user_id
 * @property string|null $note
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $price_snapshot
 * @property \Carbon\Carbon|null $created_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\User|null $changedBy
 * @property-read \App\Models\OrderStatus|null $statusRef
 */
class OrderStatusHistory extends Model
{
    protected $table = 'order_status_histories';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'status_id',
        'event_type',
        'changed_by_user_id',
        'note',
        'metadata',
        'price_snapshot',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status_id' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function setAttribute($key, $value)
    {
        if ($key === 'price_snapshot') {
            $key = 'metadata';
        }

        if ($key === 'event_type') {
            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function getEventTypeAttribute(): string
    {
        return 'STATUS_CHANGE';
    }

    public function getPriceSnapshotAttribute(): ?array
    {
        return $this->metadata;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function statusRef(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status_id');
    }
}
