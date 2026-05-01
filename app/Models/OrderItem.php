<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $menu_id
 * @property string $item_source
 * @property string $menu_name
 * @property int $quantity
 * @property string $unit_price
 * @property string $subtotal
 * @property string $line_service_fee
 * @property string $line_total
 * @property string|null $notes
 * @property array<string, mixed>|null $metadata
 * @property bool $is_available
 * @property bool $is_heavy
 *
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Menu|null $menu
 */
class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'menu_id',
        'item_source',
        'menu_name',
        'quantity',
        'unit_price',
        'subtotal',
        'line_service_fee',
        'line_total',
        'notes',
        'metadata',
        'is_available',
        'is_heavy',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'line_service_fee' => 'decimal:2',
            'line_total' => 'decimal:2',
            'metadata' => 'array',
            'is_available' => 'boolean',
            'is_heavy' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }
}
