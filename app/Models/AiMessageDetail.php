<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessageDetail extends Model
{
    protected $fillable = [
        'chat_message_id',
        'ai_response',
        'model_used',
        'intent',
        'order_id',
    ];

    protected function casts(): array
    {
        return [
            'ai_response' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AiChatLog::class, 'chat_message_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
