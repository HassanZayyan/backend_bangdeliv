<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $menu_id
 * @property int|null $pickup_location_id
 * @property string $item_source
 * @property string $menu_name
 * @property int $quantity
 * @property string $unit_price
 * @property string $subtotal
 * @property string|null $notes
 * @property array<string, mixed>|null $metadata
 * @property bool $is_available
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Menu|null $menu
 * @property-read \App\Models\OrderLocation|null $pickupLocation
 */
class OrderItem extends Model
{
    protected $table = 'shopping_order_items';

    protected $fillable = [
        'order_id',
        'menu_id',
        'pickup_location_id',
        'item_source',
        'menu_name',
        'quantity',
        'unit_price',
        'subtotal',
        'notes',
        'metadata',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'metadata' => 'array',
            'is_available' => 'boolean',
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

    public function pickupLocation(): BelongsTo
    {
        return $this->belongsTo(OrderLocation::class, 'pickup_location_id');
    }

    /**
     * Status harga item Nitip. Sumber kebenaran = metadata.price_status; fallback
     * mengikuti logika kanonik DriverOrderPayloadFactory::shoppingItemPriceStatus():
     * item MANUAL yang tersedia namun belum berharga (unit_price <= 0) berarti
     * menunggu input harga driver (bukan harga Rp 0 yang benar). CATATAN: nilai
     * unit_price 0 pada item MENU_DB yang CONFIRMED memang gratis — jangan pakai
     * unit_price==0 sebagai penanda pending.
     */
    public function priceStatus(): string
    {
        $status = (string) data_get($this->metadata, 'price_status', '');
        if ($status !== '') {
            return $status;
        }

        return $this->item_source === 'MANUAL' && (bool) $this->is_available && (float) $this->unit_price <= 0
            ? 'PENDING_DRIVER_INPUT'
            : 'CONFIRMED';
    }

    public function isPricePending(): bool
    {
        return $this->priceStatus() === 'PENDING_DRIVER_INPUT';
    }
}
