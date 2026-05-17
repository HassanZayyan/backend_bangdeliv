<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $restaurant_id
 * @property string $location_role
 * @property string|null $label
 * @property string|null $contact_name
 * @property string|null $contact_phone
 * @property string $full_address
 * @property string $latitude
 * @property string $longitude
 * @property int $sequence_no
 * @property string $fulfillment_status
 * @property int $failed_attempt_count
 * @property string|null $failure_reason
 * @property \Carbon\Carbon|null $failed_at
 * @property \Carbon\Carbon|null $resolved_at
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Restaurant|null $restaurant
 */
class OrderLocation extends Model
{
    protected $table = 'order_locations';

    protected $fillable = [
        'order_id',
        'restaurant_id',
        'location_role',
        'label',
        'contact_name',
        'contact_phone',
        'full_address',
        'latitude',
        'longitude',
        'sequence_no',
        'fulfillment_status',
        'failed_attempt_count',
        'failure_reason',
        'failed_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'sequence_no' => 'integer',
            'failed_attempt_count' => 'integer',
            'failed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
