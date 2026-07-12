<?php

namespace App\Services\Notification;

use App\Models\Order;
use App\Models\OrderLocation;
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
    public function sendUnavailableItemsBypassed(
        Order $order,
        int $pickupLocationId,
        array $itemNames,
        int $eventId,
        bool $merchantCancelled,
    ): bool {
        $order->loadMissing(['user', 'orderLocations.restaurant']);
        $recipient = $order->user;
        $normalizedItemNames = $this->normalizeItemNames($itemNames);

        if ($recipient === null || $normalizedItemNames === []) {
            return false;
        }

        return $this->sender->send(
            $recipient,
            $this->buildBypassedMessage(
                $order,
                $pickupLocationId,
                $normalizedItemNames,
                $eventId,
                $merchantCancelled,
            ),
            'Notifikasi bypass item Nitip FCM gagal dikirim.',
            'Sebagian notifikasi bypass item Nitip FCM gagal terkirim.',
            [
                'order_id' => $order->id,
                'pickup_location_id' => $pickupLocationId,
                'event_id' => $eventId,
                'merchant_cancelled' => $merchantCancelled,
            ],
        );
    }

    /**
     * @param  list<string>  $oldItemNames
     * @param  list<string>  $newItemNames
     */
    public function sendUnavailableItemsReplaced(
        Order $order,
        int $pickupLocationId,
        array $oldItemNames,
        array $newItemNames,
        int $eventId,
    ): bool {
        $order->loadMissing(['user', 'orderLocations.restaurant']);
        $recipient = $order->user;
        $oldItems = $this->normalizeItemNames($oldItemNames);
        $newItems = $this->normalizeItemNames($newItemNames);
        if ($recipient === null || $newItems === []) {
            return false;
        }

        $storeName = $this->merchantName($order, $pickupLocationId);
        $oldSummary = $oldItems === [] ? 'Item tidak tersedia' : $this->itemSummary($oldItems);
        $newSummary = $this->itemSummary($newItems);

        return $this->sender->send(
            $recipient,
            CloudMessage::new()
                ->withNotification(Notification::create(
                    'Driver mengganti item Nitip',
                    $oldSummary.' diganti menjadi '.$newSummary.' di '.$storeName.'. Buka order untuk melihat total terbaru.',
                ))
                ->withData([
                    'type' => 'shopping_unavailable_items_replaced',
                    'order_id' => (string) $order->id,
                    'recipient_role' => 'customer',
                    'pickup_location_id' => (string) $pickupLocationId,
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
                ]),
            'Notifikasi penggantian item Nitip FCM gagal dikirim.',
            'Sebagian notifikasi penggantian item Nitip FCM gagal terkirim.',
            [
                'order_id' => $order->id,
                'pickup_location_id' => $pickupLocationId,
                'event_id' => $eventId,
            ],
        );
    }

    public function sendMerchantReplaced(
        Order $order,
        int $sourcePickupLocationId,
        string $oldMerchantName,
        string $newMerchantName,
        int $eventId,
    ): bool {
        $order->loadMissing('user');
        if ($order->user === null) {
            return false;
        }

        return $this->sender->send(
            $order->user,
            CloudMessage::new()
                ->withNotification(Notification::create(
                    'Toko/resto Nitip diganti driver',
                    $oldMerchantName.' diganti ke '.$newMerchantName.'. Buka order untuk meninjau rute dan ongkir terbaru.',
                ))
                ->withData([
                    'type' => 'shopping_merchant_replaced',
                    'order_id' => (string) $order->id,
                    'recipient_role' => 'customer',
                    'pickup_location_id' => (string) $sourcePickupLocationId,
                    'event_id' => (string) $eventId,
                    'focus' => 'shopping_price',
                    'route' => $this->customerRoute($order, $sourcePickupLocationId),
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
                ]),
            'Notifikasi penggantian toko/resto Nitip FCM gagal dikirim.',
            'Sebagian notifikasi penggantian toko/resto Nitip FCM gagal terkirim.',
            [
                'order_id' => $order->id,
                'pickup_location_id' => $sourcePickupLocationId,
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

    /**
     * @param  list<string>  $itemNames
     */
    private function buildBypassedMessage(
        Order $order,
        int $pickupLocationId,
        array $itemNames,
        int $eventId,
        bool $merchantCancelled,
    ): CloudMessage {
        $route = $this->customerRoute($order, $pickupLocationId);
        $merchantName = $this->merchantName($order, $pickupLocationId);
        $itemSummary = $this->itemSummary($itemNames);
        $body = $merchantCancelled
            ? 'Semua item di '.$merchantName.' tidak tersedia. Driver membatalkan merchant sesuai aturan order.'
            : 'Driver melanjutkan tanpa '.$itemSummary.' dari '.$merchantName.'. Buka order untuk melihat total terbaru.';

        return CloudMessage::new()
            ->withNotification(Notification::create('Driver melanjutkan order Nitip', $body))
            ->withData([
                'type' => 'shopping_unavailable_items_bypassed',
                'order_id' => (string) $order->id,
                'recipient_role' => 'customer',
                'pickup_location_id' => (string) $pickupLocationId,
                'focus' => 'shopping_price',
                'event_id' => (string) $eventId,
                'merchant_cancelled' => $merchantCancelled ? '1' : '0',
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
        $pickup = OrderLocation::query()
            ->with('restaurant')
            ->where('order_id', $order->id)
            ->find($pickupLocationId);
        if (! $pickup instanceof OrderLocation) {
            return 'merchant';
        }
        $label = trim((string) $pickup->label);
        if ($label !== '') {
            return $label;
        }

        $restaurantName = $pickup->restaurant_id !== null
            ? trim((string) $pickup->restaurant->name)
            : '';

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
