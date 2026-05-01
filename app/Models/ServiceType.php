<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $display_name
 * @property string|null $description
 * @property int $sort_order
 */
class ServiceType extends Model
{
    protected $fillable = [
        'code',
        'display_name',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function feeRules(): HasMany
    {
        return $this->hasMany(ServiceFeeRule::class);
    }
}
