<?php

namespace App\Services\Notification;

use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Services\Driver\Dispatch\DriverCandidateSelector;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class NotificationDiagnosticService
{
    public function __construct(
        private readonly UserPushNotificationSender $sender,
        private readonly DriverCandidateSelector $candidateSelector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function diagnose(int $userId, string $type, ?int $orderId = null, bool $send = true): array
    {
        $user = User::query()->with('driver')->find($userId);
        if (! $user instanceof User) {
            return [
                'sent' => false,
                'reason' => 'user_not_found',
                'user_id' => $userId,
            ];
        }

        $tokens = DeviceToken::query()
            ->where('user_id', $user->id)
            ->where('device_type', 'android')
            ->where('is_active', true)
            ->pluck('token')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $order = $orderId !== null && $orderId > 0
            ? Order::query()->with(['serviceType', 'driver.user', 'user'])->find($orderId)
            : null;

        $driver = $user->driver;
        $candidateDiagnostic = null;
        if ($order instanceof Order && $driver instanceof Driver) {
            $candidateDiagnostic = $this->candidateSelector->explainDriverForOrder($order, $driver);
        }

        $sent = false;
        if ($send) {
            $sent = $this->sender->send(
                $user,
                $this->buildMessage($type, $user, $order),
                'Diagnostic notification FCM gagal dikirim.',
                'Sebagian diagnostic notification FCM gagal terkirim.',
                [
                    'diagnostic' => true,
                    'type' => $this->normalizeType($type),
                    'order_id' => $order?->id,
                ],
            );
        }

        return [
            'sent' => $sent,
            'dry_run' => ! $send,
            'user' => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'role' => (string) $user->role,
                'is_active' => (bool) $user->is_active,
                'is_blacklisted' => (bool) $user->is_blacklisted,
            ],
            'driver' => $driver instanceof Driver ? [
                'id' => (int) $driver->id,
                'registration_status' => (string) $driver->registration_status,
                'status' => (string) $driver->status,
            ] : null,
            'tokens' => [
                'count' => count($tokens),
                'prefixes' => array_map(fn (string $token): string => substr($token, 0, 14), $tokens),
            ],
            'order' => $order instanceof Order ? [
                'id' => (int) $order->id,
                'order_number' => (string) ($order->order_number ?? ''),
                'driver_id' => $order->driver_id !== null ? (int) $order->driver_id : null,
                'customer_user_id' => (int) $order->user_id,
            ] : null,
            'candidate' => $candidateDiagnostic,
        ];
    }

    private function buildMessage(string $type, User $user, ?Order $order): CloudMessage
    {
        $normalizedType = $this->normalizeType($type);
        $orderId = $order?->id ?? 999999;
        $title = match ($normalizedType) {
            'driver_order_available' => 'BangDeliv diagnostic driver',
            'order_price_changed' => 'BangDeliv diagnostic harga',
            'order_chat_message' => 'BangDeliv diagnostic chat',
            default => 'BangDeliv diagnostic status',
        };
        $body = "Tes {$normalizedType} untuk {$user->role} #{$user->id}";
        $recipientRole = strtolower((string) $user->role) === 'driver' ? 'driver' : 'customer';

        $route = match ($normalizedType) {
            'driver_order_available' => '/driver/orders',
            'order_chat_message' => "/orders/{$orderId}/chat",
            'order_price_changed' => $recipientRole === 'driver'
                ? "/driver/orders/{$orderId}/active"
                : "/orders/{$orderId}/track?focus=delivery_fee",
            default => "/orders/{$orderId}/track",
        };

        $data = [
            'type' => $normalizedType,
            'title' => $title,
            'body' => $body,
            'order_id' => (string) $orderId,
            'route' => $route,
        ];

        if ($normalizedType === 'driver_order_available') {
            $data['event_id'] = (string) $orderId;
        } elseif ($normalizedType === 'order_chat_message') {
            $data['message_id'] = (string) random_int(100000, 999999);
            $data['sender_user_id'] = '0';
            $data['sender_role'] = 'diagnostic';
        } elseif ($normalizedType === 'order_price_changed') {
            $data['change_type'] = 'DIAGNOSTIC_DELIVERY_FEE_UPDATED';
            $data['recipient_role'] = $recipientRole;
            $data['requires_response'] = '0';
            $data['amount'] = '12345.00';
            $data['price_event_id'] = (string) random_int(100000, 999999);
            $data['focus'] = 'delivery_fee';
        } else {
            $data['history_id'] = (string) random_int(100000, 999999);
            $data['status_code'] = 'DIAGNOSTIC';
        }

        return CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData($data)
            ->withAndroidConfig([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => $normalizedType === 'driver_order_available'
                        ? 'bangdeliv_driver_order_high'
                        : ($normalizedType === 'order_chat_message'
                            ? 'bangdeliv_chat_high'
                            : 'bangdeliv_order_status_high'),
                    'icon' => 'ic_stat_bangdeliv',
                    'color' => '#F05B24',
                    'sound' => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ]);
    }

    private function normalizeType(string $type): string
    {
        $normalized = strtolower(trim($type));

        return match ($normalized) {
            'driver', 'driver_order', 'driver_order_available' => 'driver_order_available',
            'chat', 'order_chat', 'order_chat_message' => 'order_chat_message',
            'price', 'pricing', 'order_price_changed' => 'order_price_changed',
            default => 'order_status_changed',
        };
    }
}
