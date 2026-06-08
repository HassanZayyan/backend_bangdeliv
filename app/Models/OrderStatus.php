<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $display_name
 * @property bool $is_terminal
 * @property int $sort_order
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class OrderStatus extends Model
{
    protected $fillable = [
        'code',
        'display_name',
        'is_terminal',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_terminal' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'status_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'new_status_id');
    }
}
