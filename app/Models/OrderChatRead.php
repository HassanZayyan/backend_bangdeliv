<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property int|null $last_read_message_id
 * @property \Carbon\Carbon|null $read_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\User $user
 * @property-read \App\Models\OrderChatMessage|null $lastReadMessage
 */
class OrderChatRead extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'last_read_message_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(OrderChatMessage::class, 'last_read_message_id');
    }
}
