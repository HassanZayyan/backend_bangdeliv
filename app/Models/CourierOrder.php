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
        'careful_carry_required',
    ];

    protected function casts(): array
    {
        return [
            'careful_carry_required' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
