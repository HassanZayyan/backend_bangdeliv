<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'vehicle_plate',
        'license_number',
        'registration_status',
        'status',
        'current_latitude',
        'current_longitude',
        'avg_rating',
        'total_deliveries',
    ];

    protected function casts(): array
    {
        return [
            'current_latitude' => 'decimal:8',
            'current_longitude' => 'decimal:8',
            'avg_rating' => 'decimal:2',
            'total_deliveries' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driverDocuments(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
