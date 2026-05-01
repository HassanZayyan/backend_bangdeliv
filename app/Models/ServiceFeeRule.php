<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $service_type_id
 * @property string $rule_code
 * @property array<string, mixed>|null $rule_config
 * @property bool $is_active
 * @property \Carbon\Carbon|null $starts_at
 * @property \Carbon\Carbon|null $ends_at
 *
 * @property-read \App\Models\ServiceType $serviceType
 */
class ServiceFeeRule extends Model
{
    protected $fillable = [
        'service_type_id',
        'rule_code',
        'rule_config',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'rule_config' => 'array',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
