<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLocation extends Model
{
    protected $table = 'order_locations';

    protected $fillable = [
        'order_id',
        'location_role',
        'label',
        'contact_name',
        'contact_phone',
        'full_address',
        'latitude',
        'longitude',
        'sequence_no',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'sequence_no' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
