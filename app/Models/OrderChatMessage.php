<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $sender_user_id
 * @property string $sender_role
 * @property string $sender_name_snapshot
 * @property string $body
 * @property string|null $client_message_id
 * @property string|null $attachment_type
 * @property string|null $attachment_url
 * @property string|null $attachment_mime_type
 * @property int|null $attachment_size
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\User $sender
 */
class OrderChatMessage extends Model
{
    protected $fillable = [
        'order_id',
        'sender_user_id',
        'sender_role',
        'sender_name_snapshot',
        'body',
        'client_message_id',
        'attachment_type',
        'attachment_url',
        'attachment_mime_type',
        'attachment_size',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
