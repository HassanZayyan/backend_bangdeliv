<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderPriceChange extends Model
{
    protected $fillable = [
        'order_event_id',
    ];

    protected function casts(): array
    {
        return [
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(OrderLog::class, 'order_event_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderPriceChangeLine::class);
    }

    public function getOldSubtotalAttribute(): ?string
    {
        return $this->componentAmount('SUBTOTAL', 'old_amount');
    }

    public function getNewSubtotalAttribute(): ?string
    {
        return $this->componentAmount('SUBTOTAL', 'new_amount');
    }

    public function getOldDeliveryFeeAttribute(): ?string
    {
        return $this->componentAmount('DELIVERY_FEE', 'old_amount');
    }

    public function getNewDeliveryFeeAttribute(): ?string
    {
        return $this->componentAmount('DELIVERY_FEE', 'new_amount');
    }

    public function getOldServiceFeeAttribute(): ?string
    {
        return $this->componentAmount('SERVICE_FEE', 'old_amount');
    }

    public function getNewServiceFeeAttribute(): ?string
    {
        return $this->componentAmount('SERVICE_FEE', 'new_amount');
    }

    public function getOldTotalPriceAttribute(): ?string
    {
        return $this->componentAmount('TOTAL_PRICE', 'old_amount');
    }

    public function getNewTotalPriceAttribute(): ?string
    {
        return $this->componentAmount('TOTAL_PRICE', 'new_amount');
    }

    public function getDeltaTotalPriceAttribute(): ?string
    {
        return $this->componentAmount('TOTAL_PRICE', 'delta_amount');
    }

    private function componentAmount(string $code, string $column): ?string
    {
        if (! $this->relationLoaded('lines')) {
            $this->setRelation('lines', $this->lines()->get());
        }

        $line = $this->lines->first(
            fn (OrderPriceChangeLine $line): bool => strtoupper((string) $line->component_code) === $code
        );

        return $line?->{$column};
    }
}
