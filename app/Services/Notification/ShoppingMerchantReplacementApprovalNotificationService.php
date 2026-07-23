<?php

namespace App\Services\Notification;

use App\Models\Order;
use App\Models\User;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * Notifikasi seputar penggantian toko/resto Nitip oleh customer.
 *
 * Berbeda dari notifikasi Nitip lain yang menyasar customer, sebagian pesan di
 * sini menyasar DRIVER -- karena driver-lah yang menanggung ongkos jalan saat
 * tujuannya diubah. Ada tiga peristiwa:
 *  - request : customer minta ganti ke resto jauh (> radius) -> driver diminta
 *              Terima/Tolak.
 *  - notice  : customer ganti resto dalam radius -> driver cukup diberi tahu.
 *  - result  : driver menyetujui/menolak -> customer diberi tahu hasilnya.
 */
class ShoppingMerchantReplacementApprovalNotificationService
{
    public function __construct(private readonly UserPushNotificationSender $sender) {}

    public function sendMerchantChangeApprovalRequest(
        Order $order,
        string $oldMerchantName,
        string $newMerchantName,
        float $distanceKm,
        int $eventId,
    ): bool {
        $order->loadMissing('driver.user');
        $recipient = $order->driver?->user;
        if (! $recipient instanceof User) {
            return false;
        }

        $distanceText = $this->formatDistance($distanceKm);

        return $this->sender->send(
            $recipient,
            CloudMessage::new()
                ->withNotification(Notification::create(
                    'Persetujuan ganti toko/resto',
                    'Customer minta ganti '.$oldMerchantName.' ke '.$newMerchantName.' ('.$distanceText.' dari toko lama). Buka order untuk Terima atau Tolak.',
                ))
                ->withData([
                    'type' => 'shopping_merchant_replacement_approval_request',
                    'order_id' => (string) $order->id,
                    'recipient_role' => 'driver',
                    'event_id' => (string) $eventId,
                    'distance_km' => number_format(round($distanceKm, 2), 2, '.', ''),
                    'focus' => 'shopping_replacement_approval',
                    'route' => $this->driverRoute($order),
                ])
                ->withAndroidConfig($this->androidConfig()),
            'Notifikasi permintaan persetujuan ganti toko/resto FCM gagal dikirim.',
            'Sebagian notifikasi permintaan persetujuan ganti toko/resto FCM gagal terkirim.',
            [
                'order_id' => $order->id,
                'event_id' => $eventId,
                'driver_user_id' => $recipient->id,
            ],
        );
    }

    public function sendMerchantChangedNotice(
        Order $order,
        string $oldMerchantName,
        string $newMerchantName,
    ): bool {
        $order->loadMissing('driver.user');
        $recipient = $order->driver?->user;
        if (! $recipient instanceof User) {
            return false;
        }

        return $this->sender->send(
            $recipient,
            CloudMessage::new()
                ->withNotification(Notification::create(
                    'Toko/resto Nitip diganti customer',
                    $oldMerchantName.' diganti ke '.$newMerchantName.'. Buka order untuk melihat tujuan dan rute terbaru.',
                ))
                ->withData([
                    'type' => 'shopping_merchant_changed_notice',
                    'order_id' => (string) $order->id,
                    'recipient_role' => 'driver',
                    'focus' => 'shopping_route',
                    'route' => $this->driverRoute($order),
                ])
                ->withAndroidConfig($this->androidConfig()),
            'Notifikasi ganti toko/resto oleh customer FCM gagal dikirim.',
            'Sebagian notifikasi ganti toko/resto oleh customer FCM gagal terkirim.',
            [
                'order_id' => $order->id,
                'driver_user_id' => $recipient->id,
            ],
        );
    }

    public function sendMerchantChangeApprovalResult(
        Order $order,
        bool $approved,
        string $oldMerchantName,
        string $newMerchantName,
        ?string $reason = null,
    ): bool {
        $order->loadMissing('user');
        $recipient = $order->user;
        if (! $recipient instanceof User) {
            return false;
        }

        $title = $approved ? 'Driver menyetujui ganti toko/resto' : 'Driver menolak ganti toko/resto';
        $body = $approved
            ? $oldMerchantName.' diganti ke '.$newMerchantName.'. Buka order untuk meninjau rute dan ongkir terbaru.'
            : 'Permintaan ganti ke '.$newMerchantName.' ditolak'.($reason !== null && $reason !== '' ? ' ('.$reason.')' : '').'. Pilih opsi lain untuk '.$oldMerchantName.'.';

        return $this->sender->send(
            $recipient,
            CloudMessage::new()
                ->withNotification(Notification::create($title, $body))
                ->withData([
                    'type' => $approved
                        ? 'shopping_merchant_replacement_approved'
                        : 'shopping_merchant_replacement_rejected',
                    'order_id' => (string) $order->id,
                    'recipient_role' => 'customer',
                    'focus' => 'shopping_price',
                    'route' => $this->customerRoute($order),
                ])
                ->withAndroidConfig($this->androidConfig()),
            'Notifikasi hasil persetujuan ganti toko/resto FCM gagal dikirim.',
            'Sebagian notifikasi hasil persetujuan ganti toko/resto FCM gagal terkirim.',
            [
                'order_id' => $order->id,
                'approved' => $approved,
            ],
        );
    }

    private function formatDistance(float $distanceKm): string
    {
        return number_format(round($distanceKm, 1), 1, ',', '.').' km';
    }

    private function driverRoute(Order $order): string
    {
        return "/driver/orders/{$order->id}/active";
    }

    private function customerRoute(Order $order): string
    {
        return "/orders/{$order->id}/track?".http_build_query(['focus' => 'shopping_price']);
    }

    /** @return array<string, mixed> */
    private function androidConfig(): array
    {
        return [
            'priority' => 'high',
            'notification' => [
                'channel_id' => 'bangdeliv_order_status_high',
                'icon' => 'ic_stat_bangdeliv',
                'color' => '#F05B24',
                'sound' => 'default',
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ],
        ];
    }
}
