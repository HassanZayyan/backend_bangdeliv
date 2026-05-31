<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class ChatPushNotificationService
{
    public function __construct(private readonly Container $container) {}

    public function sendOrderChatNotification(Order $order, User $sender, OrderChatMessage $message): bool
    {
        try {
            $recipient = $this->resolveRecipient($order, $sender);
            if ($recipient === null) {
                return false;
            }

            $tokens = DeviceToken::query()
                ->where('user_id', $recipient->id)
                ->where('device_type', 'android')
                ->where('is_active', true)
                ->pluck('token')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($tokens === []) {
                return false;
            }

            $report = $this->messaging()->sendMulticast(
                $this->buildMessage($order, $sender, $message),
                $tokens,
            );

            $inactiveTokens = array_values(array_unique([
                ...$report->invalidTokens(),
                ...$report->unknownTokens(),
            ]));

            if ($inactiveTokens !== []) {
                DeviceToken::query()
                    ->whereIn('token', $inactiveTokens)
                    ->update(['is_active' => false]);
            }

            if ($report->hasFailures()) {
                Log::warning('Sebagian notifikasi chat FCM gagal terkirim.', [
                    'order_id' => $order->id,
                    'message_id' => $message->id,
                    'recipient_user_id' => $recipient->id,
                    'failed_count' => $report->failures()->count(),
                ]);
            }

            return $report->successes()->count() > 0;
        } catch (\Throwable $exception) {
            Log::warning('Notifikasi chat FCM gagal dikirim.', [
                'order_id' => $order->id,
                'message_id' => $message->id,
                'sender_user_id' => $sender->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function messaging(): Messaging
    {
        return $this->container->make(Messaging::class);
    }

    private function resolveRecipient(Order $order, User $sender): ?User
    {
        $order->loadMissing(['driver.user', 'user']);

        if ((int) $order->user_id === (int) $sender->id) {
            return $order->driver?->user;
        }

        return $order->user;
    }

    private function buildMessage(Order $order, User $sender, OrderChatMessage $message): CloudMessage
    {
        $senderRole = $message->sender_role === 'driver' ? 'Driver' : 'Customer';
        $senderName = trim((string) $message->sender_name_snapshot);
        $title = trim("Pesan dari {$senderRole} {$senderName}");
        $body = Str::limit(trim((string) $message->body), 120, '...');
        $route = "/orders/{$order->id}/chat";

        return CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData([
                'type' => 'order_chat_message',
                'order_id' => (string) $order->id,
                'message_id' => (string) $message->id,
                'sender_user_id' => (string) $sender->id,
                'sender_role' => (string) $message->sender_role,
                'route' => $route,
            ])
            ->withAndroidConfig([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'bangdeliv_chat_high',
                    'sound' => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ]);
    }
}
