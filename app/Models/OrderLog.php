<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLog extends Model
{
    protected $table = 'order_events';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'event_type',
        'log_type',
        'trigger_type',
        'changed_by_user_id',
        'note',
        'metadata',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (OrderLog $log): void {
            if ($log->created_at === null || $log->created_at === '') {
                $log->created_at = now((string) config('app.timezone', 'Asia/Jakarta'));
            }
        });

        static::saving(function (OrderLog $log): void {
            $eventType = trim((string) ($log->event_type ?? ''));
            if ($eventType === '') {
                $eventType = 'SYSTEM_EVENT';
            }

            $triggerType = trim((string) ($log->trigger_type ?? ''));

            $log->event_type = $eventType;
            $log->trigger_type = $triggerType !== '' ? $triggerType : $eventType;
            $metadata = $log->metadata;

            $log->note = $log->note ?? '';
            $log->metadata = ($metadata === null || $metadata === '') ? [] : $metadata;
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function setAttribute($key, $value)
    {
        if ($key === 'log_type') {
            $key = 'event_type';
        }

        return parent::setAttribute($key, $value);
    }

    public function getLogTypeAttribute(): ?string
    {
        return $this->event_type;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
