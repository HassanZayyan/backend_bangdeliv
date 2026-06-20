<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierOrder extends Model
{
    protected $table = 'courier_order_details';

    protected $fillable = [
        'order_id',
        'package_description',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
