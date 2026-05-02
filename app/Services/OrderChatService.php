<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class OrderChatService
{
    public function __construct(private readonly OrderRealtimeBroadcaster $realtimeBroadcaster) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listMessages(User $actor, int $orderId, array $filters): array
    {
        $order = $this->resolveReadableOrder($actor, $orderId);
        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 100);
        $beforeId = $this->positiveIntOrNull($filters['before_id'] ?? null);
        $afterId = $this->positiveIntOrNull($filters['after_id'] ?? null);

        $query = OrderChatMessage::query()
            ->where('order_id', $order->id);

        if ($afterId !== null) {
            $messages = $query
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($limit)
                ->get();

            return [
                'messages' => $messages
                    ->map(fn (OrderChatMessage $message): array => $this->serializeMessage($message))
                    ->values()
                    ->all(),
                'pagination' => [
                    'has_more' => false,
                    'next_before_id' => null,
                ],
                'can_send' => $this->canSend($order),
            ];
        }

        $messages = $query
            ->when($beforeId !== null, fn (Builder $builder): Builder => $builder->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $messages->count() > $limit;
        $visibleMessages = $messages->take($limit)->reverse()->values();
        $nextBeforeId = $hasMore && $visibleMessages->isNotEmpty()
            ? (int) $visibleMessages->first()->id
            : null;

        return [
            'messages' => $visibleMessages
                ->map(fn (OrderChatMessage $message): array => $this->serializeMessage($message))
                ->values()
                ->all(),
            'pagination' => [
                'has_more' => $hasMore,
                'next_before_id' => $nextBeforeId,
            ],
            'can_send' => $this->canSend($order),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMessage(User $actor, int $orderId, array $payload): array
    {
        $order = $this->resolveReadableOrder($actor, $orderId);

        if (! $this->canSend($order)) {
            throw new ApiException('Chat hanya aktif saat order berlangsung dan driver sudah ditugaskan.', 409);
        }

        $body = trim((string) ($payload['body'] ?? ''));
        if ($body === '') {
            throw new ApiException('Pesan tidak boleh kosong.', 422, [
                'body' => ['Pesan tidak boleh kosong.'],
            ]);
        }

        $clientMessageId = $this->normalizeClientMessageId($payload['client_message_id'] ?? null);
        $senderRole = $this->resolveSenderRole($actor, $order);

        if ($clientMessageId !== null) {
            $message = OrderChatMessage::query()->firstOrCreate(
                [
                    'order_id' => $order->id,
                    'sender_user_id' => $actor->id,
                    'client_message_id' => $clientMessageId,
                ],
                [
                    'sender_role' => $senderRole,
                    'sender_name_snapshot' => $actor->name,
                    'body' => $body,
                ],
            );
        } else {
            $message = OrderChatMessage::query()->create([
                'order_id' => $order->id,
                'sender_user_id' => $actor->id,
                'sender_role' => $senderRole,
                'sender_name_snapshot' => $actor->name,
                'body' => $body,
                'client_message_id' => null,
            ]);
        }

        $serialized = $this->serializeMessage($message);

        $broadcasted = ! $message->wasRecentlyCreated
            || $this->realtimeBroadcaster->orderChatMessageSent($order->id, $serialized);

        return [
            'message' => $serialized,
            'can_send' => $this->canSend($order),
            'broadcasted' => $broadcasted,
        ];
    }

    private function resolveReadableOrder(User $actor, int $orderId): Order
    {
        $order = Order::query()
            ->with(['driver', 'statusRef'])
            ->find($orderId);

        if (! $order) {
            throw new ApiException('Order tidak ditemukan.', 404);
        }

        if ($actor->role === 'customer' && (int) $order->user_id === (int) $actor->id) {
            return $order;
        }

        if ($actor->role === 'driver') {
            $driverId = Driver::query()
                ->where('user_id', $actor->id)
                ->value('id');

            if ($driverId !== null && (int) ($order->driver_id ?? 0) === (int) $driverId) {
                return $order;
            }
        }

        throw new ApiException('Order tidak ditemukan.', 404);
    }

    private function canSend(Order $order): bool
    {
        if ((int) ($order->driver_id ?? 0) <= 0) {
            return false;
        }

        $order->loadMissing('statusRef');

        return $order->statusRef?->is_terminal !== true;
    }

    private function resolveSenderRole(User $actor, Order $order): string
    {
        if ((int) $order->user_id === (int) $actor->id) {
            return 'customer';
        }

        return 'driver';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(OrderChatMessage $message): array
    {
        $createdAt = $message->created_at;

        return [
            'id' => (int) $message->id,
            'order_id' => (int) $message->order_id,
            'sender_user_id' => (int) $message->sender_user_id,
            'sender_role' => $message->sender_role,
            'sender_name' => $message->sender_name_snapshot,
            'body' => $message->body,
            'client_message_id' => $message->client_message_id,
            'created_at' => $createdAt?->toIso8601String(),
            'created_at_ms' => $createdAt !== null ? ((int) $createdAt->getTimestamp()) * 1000 : null,
        ];
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    private function normalizeClientMessageId(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }
}
