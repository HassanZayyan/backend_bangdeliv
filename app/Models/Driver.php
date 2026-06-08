<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $vehicle_plate
 * @property string $vehicle_type
 * @property string $vehicle_brand
 * @property string $vehicle_model
 * @property string|null $license_number
 * @property string $registration_status
 * @property string $status
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driverDocuments(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    public function orders(): HasManyThrough
    {
        return $this->hasManyThrough(
            Order::class,
            OrderAssignment::class,
            'driver_id',
            'id',
            'id',
            'order_id'
        );
    }
}
