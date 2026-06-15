<?php

namespace App\Services\Notification;

use App\Models\DeviceToken;
use App\Models\Order;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class OrderStatusPushNotificationService
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<string, bool|int|string|null>  $statusPayload
     */
    public function sendOrderStatusNotification(Order $order, array $statusPayload): bool
    {
        try {
            $order->loadMissing(['user', 'statusRef', 'serviceType']);
            $recipient = $order->user;
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
                $this->buildMessage($order, $statusPayload),
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
                Log::warning('Sebagian notifikasi status order FCM gagal terkirim.', [
                    'order_id' => $order->id,
                    'status_code' => $statusPayload['status_code'] ?? null,
                    'recipient_user_id' => $recipient->id,
                    'failed_count' => $report->failures()->count(),
                ]);
            }

            return $report->successes()->count() > 0;
        } catch (\Throwable $exception) {
            Log::warning('Notifikasi status order FCM gagal dikirim.', [
                'order_id' => $order->id,
                'status_code' => $statusPayload['status_code'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function messaging(): Messaging
    {
        return $this->container->make(Messaging::class);
    }

    /**
     * @param  array<string, bool|int|string|null>  $statusPayload
     */
    private function buildMessage(Order $order, array $statusPayload): CloudMessage
    {
        $statusCode = strtoupper((string) ($statusPayload['status_code'] ?? ''));
        $statusLabel = trim((string) ($statusPayload['status_label'] ?? ''));
        if ($statusLabel === '') {
            $statusLabel = $order->statusRef?->display_name ?: $statusCode;
        }

        $serviceCode = strtoupper((string) ($order->serviceType?->code ?? ''));
        [$title, $body] = $this->notificationCopy($statusCode, $serviceCode, $statusLabel);

        $route = "/orders/{$order->id}/track";
        $historyId = $statusPayload['history_id'] ?? null;

        return CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData([
                'type' => 'order_status_changed',
                'order_id' => (string) $order->id,
                'status_code' => $statusCode,
                'previous_status_code' => (string) ($statusPayload['previous_status_code'] ?? ''),
                'history_id' => $historyId !== null ? (string) $historyId : '',
                'route' => $route,
            ])
            ->withAndroidConfig([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'bangdeliv_order_status_high',
                    'sound' => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function notificationCopy(string $statusCode, string $serviceCode, string $statusLabel): array
    {
        return match ($statusCode) {
            'DRIVER_ASSIGNED' => [
                'Driver sudah ditugaskan',
                'Driver sudah menerima order kamu dan akan menuju titik awal.',
            ],
            'ARRIVED_MERCHANT' => [
                'Driver tiba di merchant',
                'Driver sudah tiba di merchant dan mulai memproses pesanan kamu.',
            ],
            'ARRIVED_PICKUP' => [
                'Driver tiba di titik jemput',
                $serviceCode === 'RIDE'
                    ? 'Driver sudah tiba di titik jemput. Silakan bersiap untuk berangkat.'
                    : 'Driver sudah tiba di titik ambil. Silakan serahkan barang ke driver.',
            ],
            'PICKED_UP' => [
                $serviceCode === 'SHOPPING' ? 'Belanjaan sudah diproses' : 'Pesanan sudah diambil',
                $serviceCode === 'SHOPPING'
                    ? 'Belanjaan kamu sudah selesai diproses. Driver akan menuju alamat antar.'
                    : 'Barang sudah diambil driver dan akan segera diantar ke tujuan.',
            ],
            'ON_THE_WAY' => [
                'Dalam perjalanan',
                $serviceCode === 'RIDE'
                    ? 'Kamu sedang dalam perjalanan menuju tujuan.'
                    : 'Driver sedang menuju alamat tujuan.',
            ],
            'ARRIVED_DROPOFF' => [
                'Driver tiba di tujuan',
                $serviceCode === 'RIDE'
                    ? 'Driver sudah tiba di tujuan.'
                    : 'Driver sudah tiba di alamat tujuan. Pesanan siap diserahkan.',
            ],
            'DELIVERED' => [
                'Pesanan sudah diterima',
                $serviceCode === 'RIDE'
                    ? 'Perjalanan sudah selesai. Terima kasih sudah memakai BangDeliv.'
                    : 'Pesanan sudah diterima. Driver akan menyelesaikan order.',
            ],
            'COMPLETED' => [
                'Order selesai',
                'Order kamu sudah selesai. Terima kasih sudah memakai BangDeliv.',
            ],
            'CANCELLED_WITH_FEE' => [
                'Order dibatalkan',
                'Order dibatalkan dengan biaya sesuai ketentuan.',
            ],
            'CANCELLED' => [
                'Order dibatalkan',
                'Order kamu sudah dibatalkan.',
            ],
            default => [
                'Status order diperbarui',
                $statusLabel !== ''
                    ? 'Status order kamu sekarang: '.$statusLabel.'.'
                    : 'Status order kamu sudah diperbarui.',
            ],
        };
    }
}
