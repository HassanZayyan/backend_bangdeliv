<?php

namespace App\Services\Notification;

use App\Models\Order;
use App\Models\OrderLocation;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * Notifikasi saat driver menandai sebuah toko/resto Nitip tutup.
 *
 * Satu notifikasi memuat sebab sekaligus akibatnya -- nama resto, total baru,
 * dan tindakan yang perlu customer ambil. Sebelumnya peristiwa ini hanya
 * melahirkan notifikasi harga generik "Total order diperbarui", sehingga
 * customer melihat totalnya turun tanpa tahu resto mana yang tutup.
 */
class ShoppingMerchantFailurePushNotificationService
{
    public function __construct(private readonly UserPushNotificationSender $sender) {}

    public function sendMerchantClosed(
        Order $order,
        int $pickupLocationId,
        float $newTotalPrice,
        int $remainingAttempts,
        int $eventId,
    ): bool {
        $order->loadMissing(['user', 'orderLocations.restaurant']);
        $recipient = $order->user;
        if ($recipient === null) {
            return false;
        }

        return $this->sender->send(
            $recipient,
            $this->buildMessage($order, $pickupLocationId, $newTotalPrice, $remainingAttempts, $eventId),
            'Notifikasi resto Nitip tutup FCM gagal dikirim.',
            'Sebagian notifikasi resto Nitip tutup FCM gagal terkirim.',
            [
                'order_id' => $order->id,
                'pickup_location_id' => $pickupLocationId,
                'event_id' => $eventId,
            ],
        );
    }

    private function buildMessage(
        Order $order,
        int $pickupLocationId,
        float $newTotalPrice,
        int $remainingAttempts,
        int $eventId,
    ): CloudMessage {
        $merchantName = $this->merchantName($order, $pickupLocationId);
        $totalText = $this->formatCurrency($newTotalPrice);

        // Nama resto dan kata "tutup" diletakkan paling depan karena Android
        // memotong isi notifikasi yang panjang saat belum dibuka.
        $body = $remainingAttempts > 0
            ? $merchantName.' tutup. Total kini '.$totalText.' -- pilih resto pengganti, sisa '.$remainingAttempts.' percobaan.'
            : $merchantName.' tutup dan tidak bisa diganti lagi. Total kini '.$totalText.' -- buka order untuk melanjutkan.';

        return CloudMessage::new()
            ->withNotification(Notification::create('Resto tutup', $body))
            ->withData([
                'type' => 'shopping_merchant_failed',
                'order_id' => (string) $order->id,
                'recipient_role' => 'customer',
                'pickup_location_id' => (string) $pickupLocationId,
                'merchant_name' => $merchantName,
                'new_total_price' => number_format(round($newTotalPrice, 2), 2, '.', ''),
                'remaining_attempts' => (string) $remainingAttempts,
                'focus' => 'shopping_price',
                'event_id' => (string) $eventId,
                'route' => $this->customerRoute($order, $pickupLocationId),
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

    private function customerRoute(Order $order, int $pickupLocationId): string
    {
        return "/orders/{$order->id}/track?".http_build_query([
            'focus' => 'shopping_price',
            'pickup_location_id' => (string) $pickupLocationId,
        ]);
    }

    private function merchantName(Order $order, int $pickupLocationId): string
    {
        $pickup = OrderLocation::query()
            ->with('restaurant')
            ->where('order_id', $order->id)
            ->find($pickupLocationId);
        if (! $pickup instanceof OrderLocation) {
            return 'Toko/resto';
        }

        $label = trim((string) $pickup->label);
        if ($label !== '') {
            return $label;
        }

        $restaurantName = $pickup->restaurant_id !== null
            ? trim((string) $pickup->restaurant?->name)
            : '';

        return $restaurantName !== '' ? $restaurantName : 'Toko/resto';
    }

    private function formatCurrency(float $amount): string
    {
        return 'Rp '.number_format(round($amount), 0, ',', '.');
    }
}
