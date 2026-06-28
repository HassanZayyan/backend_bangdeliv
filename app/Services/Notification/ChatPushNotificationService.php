<?php

namespace App\Services\Notification;

use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\User;
use Illuminate\Support\Str;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class ChatPushNotificationService
{
    public function __construct(private readonly UserPushNotificationSender $sender) {}

    public function sendOrderChatNotification(Order $order, User $sender, OrderChatMessage $message): bool
    {
        $recipient = $this->resolveRecipient($order, $sender);
        if ($recipient === null) {
            return false;
        }

        return $this->sender->send(
            $recipient,
            $this->buildMessage($order, $sender, $message),
            'Notifikasi chat FCM gagal dikirim.',
            'Sebagian notifikasi chat FCM gagal terkirim.',
            [
                'order_id' => $order->id,
                'message_id' => $message->id,
                'sender_user_id' => $sender->id,
            ],
        );
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
                    'icon' => 'ic_stat_bangdeliv',
                    'color' => '#F05B24',
                    'sound' => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ]);
    }
}
