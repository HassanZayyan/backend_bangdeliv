<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $phone
 * @property string $code_hash
 * @property \Carbon\Carbon $expires_at
 * @property int $attempts
 * @property \Carbon\Carbon|null $last_sent_at
 */
class PhoneVerificationCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone',
        'code_hash',
        'expires_at',
        'attempts',
        'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }
}
