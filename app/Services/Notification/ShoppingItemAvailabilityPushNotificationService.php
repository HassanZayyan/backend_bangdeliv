<?php

namespace App\Services\Notification;

use App\Models\Order;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class ShoppingItemAvailabilityPushNotificationService
{
    public function __construct(private readonly UserPushNotificationSender $sender) {}

    /**
     * @param  list<string>  $itemNames
     */
    public function sendUnavailableItems(
        Order $order,
        int $pickupLocationId,
        array $itemNames,
        int $eventId,
    ): bool {
        $order->loadMissing(['user', 'orderLocations.restaurant']);
        $recipient = $order->user;
        $normalizedItemNames = $this->normalizeItemNames($itemNames);

        if ($recipient === null || $normalizedItemNames === []) {
            return false;
        }

        return $this->sender->send(
            $recipient,
            $this->buildMessage($order, $pickupLocationId, $normalizedItemNames, $eventId),
            'Notifikasi item Nitip tidak tersedia FCM gagal dikirim.',
            'Sebagian notifikasi item Nitip tidak tersedia FCM gagal terkirim.',
            [
                'order_id' => $order->id,
                'pickup_location_id' => $pickupLocationId,
                'event_id' => $eventId,
            ],
        );
    }

    /**
     * @param  list<string>  $itemNames
     */
    private function buildMessage(
        Order $order,
        int $pickupLocationId,
        array $itemNames,
        int $eventId,
    ): CloudMessage {
        $route = $this->customerRoute($order, $pickupLocationId);
        $merchantName = $this->merchantName($order, $pickupLocationId);
        $itemSummary = $this->itemSummary($itemNames);

        return CloudMessage::new()
            ->withNotification(Notification::create(
                'Item Nitip tidak tersedia',
                $itemSummary.' tidak tersedia di '.$merchantName.'. Buka order untuk lanjut, edit, atau batal merchant.'
            ))
            ->withData([
                'type' => 'shopping_item_unavailable',
                'order_id' => (string) $order->id,
                'recipient_role' => 'customer',
                'pickup_location_id' => (string) $pickupLocationId,
                'focus' => 'shopping_price',
                'event_id' => (string) $eventId,
                'unavailable_item_count' => (string) count($itemNames),
                'unavailable_item_names' => implode(', ', $itemNames),
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

    private function customerRoute(Order $order, int $pickupLocationId): string
    {
        return "/orders/{$order->id}/track?".http_build_query([
            'focus' => 'shopping_price',
            'pickup_location_id' => (string) $pickupLocationId,
        ]);
    }

    private function merchantName(Order $order, int $pickupLocationId): string
    {
        $pickup = $order->orderLocations->firstWhere('id', $pickupLocationId);
        $label = trim((string) ($pickup?->label ?? ''));
        if ($label !== '') {
            return $label;
        }

        $restaurantName = trim((string) ($pickup?->restaurant?->name ?? ''));

        return $restaurantName !== '' ? $restaurantName : 'merchant';
    }

    /**
     * @param  list<string>  $itemNames
     */
    private function itemSummary(array $itemNames): string
    {
        $count = count($itemNames);
        if ($count === 1) {
            return $itemNames[0];
        }
        if ($count === 2) {
            return $itemNames[0].' dan '.$itemNames[1];
        }

        return $itemNames[0].', '.$itemNames[1].', dan '.($count - 2).' item lain';
    }

    /**
     * @param  list<string>  $itemNames
     * @return list<string>
     */
    private function normalizeItemNames(array $itemNames): array
    {
        $normalized = [];
        foreach ($itemNames as $itemName) {
            $name = trim($itemName);
            if ($name === '') {
                continue;
            }
            $normalized[$name] = $name;
        }

        return array_values($normalized);
    }
}
