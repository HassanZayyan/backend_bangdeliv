<?php

namespace App\Services\Notification;

use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class DriverIncomingOrderPushNotificationService
{
    public function __construct(private readonly UserPushNotificationSender $sender) {}

    public function sendIncomingOrderNotification(Order $order, Driver $driver): bool
    {
        $driver->loadMissing('user');
        $recipient = $driver->user;
        if (! $recipient instanceof User) {
            Log::debug('Notifikasi order masuk driver tidak dikirim karena user driver tidak ditemukan.', [
                'order_id' => $order->id,
                'driver_id' => $driver->id,
                'driver_user_id' => $driver->user_id,
                'sent' => false,
            ]);

            return false;
        }

        $order->loadMissing(['serviceType']);

        $sent = $this->sender->send(
            $recipient,
            $this->buildMessage($order),
            'Notifikasi order masuk driver FCM gagal dikirim.',
            'Sebagian notifikasi order masuk driver FCM gagal terkirim.',
            [
                'order_id' => $order->id,
                'driver_id' => $driver->id,
                'driver_user_id' => $recipient->id,
            ],
        );

        Log::debug('Percobaan notifikasi order masuk driver selesai.', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'driver_user_id' => $recipient->id,
            'sent' => $sent,
        ]);

        return $sent;
    }

    private function buildMessage(Order $order): CloudMessage
    {
        $serviceName = trim((string) ($order->serviceType?->display_name ?? 'Order'));
        $serviceCode = strtoupper((string) ($order->serviceType?->code ?? ''));
        $orderNumber = trim((string) ($order->order_number ?? ''));
        $route = '/driver/orders';
        $feeText = $this->formatCurrency((float) $order->delivery_fee);

        $body = $serviceName !== ''
            ? "{$serviceName} baru tersedia. Estimasi ongkir {$feeText}."
            : "Order baru tersedia. Estimasi ongkir {$feeText}.";
        $title = 'Order masuk';

        return CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData([
                'type' => 'driver_order_available',
                'title' => $title,
                'body' => $body,
                'order_id' => (string) $order->id,
                'order_number' => $orderNumber,
                'service_type_code' => $serviceCode,
                'service_type_name' => $serviceName,
                'delivery_fee' => number_format(round((float) $order->delivery_fee, 2), 2, '.', ''),
                'event_id' => (string) $order->id,
                'route' => $route,
            ])
            ->withAndroidConfig([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'bangdeliv_driver_order_high',
                    'icon' => 'ic_stat_bangdeliv',
                    'color' => '#F05B24',
                    'sound' => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ]);
    }

    private function formatCurrency(float $amount): string
    {
        return 'Rp '.number_format(round($amount), 0, ',', '.');
    }
}
