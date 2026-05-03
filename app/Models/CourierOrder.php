<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierOrder extends Model
{
    protected $fillable = [
        'order_id',
        'package_description',
        'estimated_weight_kg',
        'package_length_cm',
        'package_width_cm',
        'package_height_cm',
        'package_size_class',
        'package_safety_status',
        'package_safety_flags',
        'package_safety_reason',
        'package_packing_note',
        'requires_photo_evidence',
        'confirmation_deadline_at',
        'auto_confirmed_at',
        'complaint_reason',
    ];

    protected function casts(): array
    {
        return [
            'estimated_weight_kg' => 'decimal:2',
            'package_length_cm' => 'integer',
            'package_width_cm' => 'integer',
            'package_height_cm' => 'integer',
            'package_safety_flags' => 'array',
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
