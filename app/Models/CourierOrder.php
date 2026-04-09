<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierOrder extends Model
{
    protected $fillable = [
        'order_id',
        'package_description',
        'requires_photo_evidence',
        'confirmation_deadline_at',
        'auto_confirmed_at',
        'complaint_reason',
    ];

    protected function casts(): array
    {
        return [
            'requires_photo_evidence' => 'boolean',
            'confirmation_deadline_at' => 'datetime',
            'auto_confirmed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
