<?php

namespace App\Services\Order;

use App\Exceptions\ApiException;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderChatRead;
use App\Models\User;
use App\Services\Notification\ChatPushNotificationService;
use App\Services\Notification\OrderRealtimeBroadcaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class OrderChatService
{
    public function __construct(
        private readonly OrderRealtimeBroadcaster $realtimeBroadcaster,
        private readonly ChatPushNotificationService $chatPushNotificationService,
    ) {}

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
        $readSummary = $this->readSummary($actor, $order);

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
                ...$readSummary,
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
            ...$readSummary,
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
        $attachment = $payload['attachment'] ?? null;
        if ($body === '' && ! $attachment instanceof UploadedFile) {
            throw new ApiException('Pesan tidak boleh kosong.', 422, [
                'body' => ['Pesan tidak boleh kosong.'],
            ]);
        }

        $attachmentAttributes = $attachment instanceof UploadedFile
            ? $this->storeAttachment($attachment, $order->id, (string) ($payload['attachment_type'] ?? 'image'))
            : [];
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
                    ...$attachmentAttributes,
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
                ...$attachmentAttributes,
            ]);
        }

        $serialized = $this->serializeMessage($message);

        $broadcasted = ! $message->wasRecentlyCreated
            || $this->realtimeBroadcaster->orderChatMessageSent($order->id, $serialized);

        if ($message->wasRecentlyCreated) {
            $this->chatPushNotificationService->sendOrderChatNotification(
                $order,
                $actor,
                $message,
            );
        }

        return [
            'message' => $serialized,
            'can_send' => $this->canSend($order),
            'broadcasted' => $broadcasted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function unreadSummary(User $actor, int $orderId): array
    {
        $order = $this->resolveReadableOrder($actor, $orderId);

        return $this->readSummary($actor, $order);
    }

    /**
     * @return array<string, mixed>
     */
    public function markRead(User $actor, int $orderId, int $messageId): array
    {
        $order = $this->resolveReadableOrder($actor, $orderId);
        if ($messageId <= 0) {
            throw new ApiException('Message ID tidak valid.', 422, [
                'message_id' => ['Message ID tidak valid.'],
            ]);
        }

        $targetMessageId = (int) OrderChatMessage::query()
            ->where('order_id', $order->id)
            ->where('id', '<=', $messageId)
            ->max('id');

        $currentLastRead = $this->lastReadMessageId($actor, $order);
        $nextLastRead = max($currentLastRead, $targetMessageId);

        OrderChatRead::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                'user_id' => $actor->id,
            ],
            [
                'last_read_message_id' => $nextLastRead > 0 ? $nextLastRead : null,
                'read_at' => now(),
            ],
        );

        return $this->readSummary($actor, $order);
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

    /**
     * @return array{unread_count: int, last_read_message_id: int}
     */
    private function readSummary(User $actor, Order $order): array
    {
        $lastReadMessageId = $this->lastReadMessageId($actor, $order);

        return [
            'unread_count' => $this->unreadCount($actor, $order, $lastReadMessageId),
            'last_read_message_id' => $lastReadMessageId,
        ];
    }

    private function lastReadMessageId(User $actor, Order $order): int
    {
        return (int) (OrderChatRead::query()
            ->where('order_id', $order->id)
            ->where('user_id', $actor->id)
            ->value('last_read_message_id') ?? 0);
    }

    private function unreadCount(User $actor, Order $order, int $lastReadMessageId): int
    {
        return OrderChatMessage::query()
            ->where('order_id', $order->id)
            ->where('id', '>', $lastReadMessageId)
            ->where('sender_user_id', '!=', $actor->id)
            ->count();
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
            'attachment_type' => $message->attachment_type,
            'attachment_url' => $message->attachment_url,
            'attachment_mime_type' => $message->attachment_mime_type,
            'attachment_size' => $message->attachment_size !== null ? (int) $message->attachment_size : null,
            'attachment' => $message->attachment_url !== null ? [
                'type' => $message->attachment_type,
                'url' => $message->attachment_url,
                'mime_type' => $message->attachment_mime_type,
                'size' => $message->attachment_size !== null ? (int) $message->attachment_size : null,
            ] : null,
            'created_at' => $createdAt?->toIso8601String(),
            'created_at_ms' => $createdAt !== null ? ((int) $createdAt->getTimestamp()) * 1000 : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storeAttachment(UploadedFile $attachment, int $orderId, string $type): array
    {
        $path = $attachment->store('orders/'.$orderId.'/chat', 'public');
        if (! is_string($path) || $path === '') {
            throw new ApiException('Lampiran gagal disimpan.', 500);
        }

        return [
            'attachment_type' => strtolower(str_replace('-', '_', trim($type))) ?: 'image',
            'attachment_url' => Storage::disk('public')->url($path),
            'attachment_mime_type' => $attachment->getMimeType(),
            'attachment_size' => $attachment->getSize(),
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
