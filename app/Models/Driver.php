<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $vehicle_plate
 * @property string|null $vehicle_type
 * @property string|null $vehicle_brand
 * @property string|null $vehicle_model
 * @property string|null $license_number
 * @property string $registration_status
 * @property string $status
 * @property string|null $current_latitude
 * @property string|null $current_longitude
 * @property string|null $avg_rating
 * @property int $total_deliveries
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\DriverDocument> $driverDocuments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 */
class Driver extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'vehicle_plate',
        'vehicle_type',
        'vehicle_brand',
        'vehicle_model',
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
