<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $failed_attempt_count
 * @property string $item_surcharge
 * @property string $overweight_surcharge
 * @property string $cancellation_penalty
 * @property bool $has_overweight_item
 * @property int $recalculation_version
 * @property \Carbon\Carbon|null $last_recalculated_at
 * @property array<string, mixed>|null $pricing_snapshot
 *
 * @property-read \App\Models\Order $order
 */
class ShoppingOrder extends Model
{
    protected $appends = [
        'fee_breakdown',
    ];

    protected $fillable = [
        'order_id',
        'failed_attempt_count',
        'item_surcharge',
        'overweight_surcharge',
        'cancellation_penalty',
        'has_overweight_item',
        'recalculation_version',
        'last_recalculated_at',
        'pricing_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'failed_attempt_count' => 'integer',
            'item_surcharge' => 'decimal:2',
            'overweight_surcharge' => 'decimal:2',
            'cancellation_penalty' => 'decimal:2',
            'has_overweight_item' => 'boolean',
            'recalculation_version' => 'integer',
            'last_recalculated_at' => 'datetime',
            'pricing_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFeeBreakdownAttribute(): array
    {
        $snapshot = is_array($this->pricing_snapshot) ? $this->pricing_snapshot : [];
        $breakdown = $snapshot['fee_breakdown'] ?? null;
        if (is_array($breakdown)) {
            return $breakdown;
        }

        $rows = [];
        if ((float) $this->item_surcharge > 0) {
            $rows[] = [
                'code' => 'ITEM_BLOCK_SURCHARGE',
                'label' => 'Biaya banyak item',
                'description' => 'Tambahan berdasarkan jumlah item',
                'amount' => round((float) $this->item_surcharge, 2),
            ];
        }
        if ((float) $this->overweight_surcharge > 0) {
            $rows[] = [
                'code' => 'OVERWEIGHT_FLAT_SURCHARGE',
                'label' => 'Item berat',
                'description' => 'Dikenakan sekali per order',
                'amount' => round((float) $this->overweight_surcharge, 2),
            ];
        }
        if ((float) $this->cancellation_penalty > 0) {
            $rows[] = [
                'code' => 'CANCELLATION_PENALTY_AFTER_FAILED_ATTEMPTS',
                'label' => 'Penalty merchant gagal',
                'description' => '50% dari ongkir setelah batas percobaan gagal',
                'amount' => round((float) $this->cancellation_penalty, 2),
            ];
        }

        return $rows;
    }
}
