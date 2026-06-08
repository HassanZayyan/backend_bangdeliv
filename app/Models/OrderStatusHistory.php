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
 * @property array<string, mixed>|null $price_snapshot
 * @property \Carbon\Carbon|null $created_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\User|null $changedBy
 * @property-read \App\Models\OrderStatus|null $statusRef
 */
class OrderStatusHistory extends Model
{
    protected $table = 'order_events';

    public $timestamps = false; // Karena migration hanya punya created_at secara manual, atau kita handle secara implicit. Migration kita: `$table->timestamp('created_at')->useCurrent();`

    protected $fillable = [
        'order_id',
        'status_id',
        'new_status_id',
        'event_type',
        'changed_by_user_id',
        'note',
        'price_snapshot',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'new_status_id' => 'integer',
            'price_snapshot' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function setAttribute($key, $value)
    {
        if ($key === 'status_id') {
            $key = 'new_status_id';
        }

        if ($key === 'price_snapshot') {
            $key = 'metadata';
        }

        return parent::setAttribute($key, $value);
    }

    public function getStatusIdAttribute(): ?int
    {
        $statusId = $this->attributes['new_status_id'] ?? null;

        return $statusId !== null ? (int) $statusId : null;
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
        return $this->belongsTo(OrderStatus::class, 'new_status_id');
    }
}
