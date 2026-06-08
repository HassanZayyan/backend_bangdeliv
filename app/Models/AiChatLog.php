<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class AiChatLog extends Model
{
    protected $table = 'chat_messages';

    public $timestamps = false;

    /**
     * @var array<string, mixed>
     */
    private array $pendingAiDetailAttributes = [];

    protected $fillable = [
        'user_id',
        'session_id',
        'role',
        'message',
        'ai_response',
        'model_used',
        'intent',
        'order_id',
        'created_at',
    ];

    public function setAttribute($key, $value)
    {
        if (in_array($key, ['ai_response', 'model_used', 'intent', 'order_id'], true)) {
            $this->pendingAiDetailAttributes[$key] = $value;

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function save(array $options = []): bool
    {
        $saved = parent::save($options);

        if ($saved && $this->exists) {
            $this->persistPendingAiDetailAttributes();
        }

        return $saved;
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): HasOneThrough
    {
        return $this->hasOneThrough(
            Order::class,
            AiMessageDetail::class,
            'chat_message_id',
            'id',
            'id',
            'order_id'
        );
    }

    public function aiDetail(): HasOne
    {
        return $this->hasOne(AiMessageDetail::class, 'chat_message_id');
    }

    public function getAiResponseAttribute(): ?array
    {
        return $this->resolvedAiDetail()?->ai_response;
    }

    public function getModelUsedAttribute(): ?string
    {
        return $this->resolvedAiDetail()?->model_used;
    }

    public function getIntentAttribute(): ?string
    {
        return $this->resolvedAiDetail()?->intent;
    }

    public function getOrderIdAttribute(): ?int
    {
        $orderId = $this->resolvedAiDetail()?->order_id;

        return $orderId !== null ? (int) $orderId : null;
    }

    private function resolvedAiDetail(): ?AiMessageDetail
    {
        if (! $this->relationLoaded('aiDetail')) {
            $this->setRelation('aiDetail', $this->aiDetail()->first());
        }

        return $this->getRelation('aiDetail');
    }

    private function persistPendingAiDetailAttributes(): void
    {
        if ($this->pendingAiDetailAttributes === []) {
            return;
        }

        if ((string) $this->role !== 'assistant') {
            $this->pendingAiDetailAttributes = [];

            return;
        }

        $detail = $this->aiDetail()->updateOrCreate(
            ['chat_message_id' => $this->id],
            [
                'ai_response' => is_array($this->pendingAiDetailAttributes['ai_response'] ?? null)
                    ? $this->pendingAiDetailAttributes['ai_response']
                    : [],
                'model_used' => $this->pendingAiDetailAttributes['model_used'] ?? 'deterministic-command',
                'intent' => $this->pendingAiDetailAttributes['intent'] ?? 'unknown',
                'order_id' => $this->pendingAiDetailAttributes['order_id'] ?? null,
            ]
        );

        $this->setRelation('aiDetail', $detail);
        $this->pendingAiDetailAttributes = [];
    }
}
