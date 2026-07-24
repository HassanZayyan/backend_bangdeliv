<?php

namespace App\Services\Notification;

use App\Models\Order;
use App\Models\User;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * Notifikasi push untuk alur bukti pembayaran QRIS/transfer:
 * - Customer mengunggah bukti  -> beri tahu DRIVER agar memverifikasi.
 * - Driver menolak bukti       -> beri tahu CUSTOMER agar mengirim ulang.
 * Sebelumnya kedua event hanya memicu refresh konten realtime (silent),
 * sehingga penerima tidak mendapatkan notifikasi apa pun.
 */
class PaymentProofPushNotificationService
{
    public function __construct(private readonly UserPushNotificationSender $sender) {}

    public function notifyProofUploadedToDriver(Order $order, ?User $actor = null): bool
    {
        $order->loadMissing(['driver.user']);
        $driver = $order->driver?->user;

        if (! $driver instanceof User || ($actor !== null && (int) $actor->id === (int) $driver->id)) {
            return false;
        }

        return $this->sender->send(
            $driver,
            $this->buildMessage(
                order: $order,
                recipientRole: 'driver',
                changeType: 'PAYMENT_PROOF_UPLOADED',
                title: 'Bukti QRIS diterima',
                body: 'Customer mengirim bukti pembayaran QRIS. Buka order untuk memverifikasi.',
            ),
            'Notifikasi bukti QRIS ke driver gagal dikirim.',
            'Sebagian notifikasi bukti QRIS ke driver gagal terkirim.',
            ['order_id' => $order->id, 'change_type' => 'PAYMENT_PROOF_UPLOADED', 'recipient_role' => 'driver'],
        );
    }

    public function notifyProofRejectedToCustomer(Order $order, ?User $actor = null, ?string $reason = null): bool
    {
        $order->loadMissing(['user']);
        $customer = $order->user;

        if (! $customer instanceof User || ($actor !== null && (int) $actor->id === (int) $customer->id)) {
            return false;
        }

        $reason = trim((string) $reason);
        $body = $reason !== ''
            ? 'Bukti QRIS ditolak driver: '.$reason.'. Silakan kirim ulang bukti yang sesuai.'
            : 'Bukti QRIS kamu ditolak driver. Silakan kirim ulang bukti yang sesuai.';

        return $this->sender->send(
            $customer,
            $this->buildMessage(
                order: $order,
                recipientRole: 'customer',
                changeType: 'PAYMENT_PROOF_REJECTED',
                title: 'Bukti QRIS ditolak',
                body: $body,
            ),
            'Notifikasi penolakan bukti QRIS ke customer gagal dikirim.',
            'Sebagian notifikasi penolakan bukti QRIS ke customer gagal terkirim.',
            ['order_id' => $order->id, 'change_type' => 'PAYMENT_PROOF_REJECTED', 'recipient_role' => 'customer'],
        );
    }

    private function buildMessage(
        Order $order,
        string $recipientRole,
        string $changeType,
        string $title,
        string $body,
    ): CloudMessage {
        $route = $recipientRole === 'driver'
            ? "/driver/orders/{$order->id}/active"
            : "/orders/{$order->id}/track?focus=payment";

        return CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData([
                'type' => 'order_payment_proof',
                'order_id' => (string) $order->id,
                'change_type' => $changeType,
                'recipient_role' => $recipientRole,
                'route' => $route,
            ])
            ->withAndroidConfig([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'bangdeliv_order_status_high',
                    'icon' => 'ic_stat_bangdeliv',
                    'color' => '#F05B24',
                    'sound' => 'default',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ]);
    }
}
