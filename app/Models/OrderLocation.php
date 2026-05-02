<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property string $location_role
 * @property string|null $label
 * @property string|null $contact_name
 * @property string|null $contact_phone
 * @property string $full_address
 * @property string $latitude
 * @property string $longitude
 * @property int $sequence_no
 * @property-read \App\Models\Order $order
 */
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
