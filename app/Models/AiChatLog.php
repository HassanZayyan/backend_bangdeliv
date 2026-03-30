<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChatLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'session_id',
        'role',
        'message',
        'ai_response',
        'model_used',
        'intent',
        'order_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'ai_response' => 'array', // Konversi string JSON DB ke array PHP
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class); // Opsional, hanya isi jika intent=order
    }
}
