<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEvidence extends Model
{
    protected $table = 'order_evidence';

    protected $fillable = [
        'order_id',
        'driver_id',
        'evidence_type',
        'file_url',
        'verification_mode',
        'verification_status',
        'uploaded_at',
        'expires_at',
        'verified_at',
        'verified_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
