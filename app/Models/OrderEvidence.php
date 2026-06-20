<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEvidence extends Model
{
    protected $table = 'order_evidence';

    protected $fillable = [
        'order_id',
        'user_id',
        'evidence_type',
        'file_url',
        'uploaded_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
        ];
    }

    public function getVerificationStatusAttribute(): string
    {
        return 'PENDING';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->user();
    }
}
