<?php

namespace App\Services\Notification;

use App\Models\Order;
use App\Models\User;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class OrderPricingPushNotificationService
{
    public function __construct(private readonly UserPushNotificationSender $sender) {}

    public function sendPriceChanged(
        Order $order,
        string $recipientRole,
        string $changeType,
        float $amount,
        bool $requiresResponse,
        ?User $actor = null,
        ?int $priceEventId = null,
        ?float $oldTotalPrice = null,
        ?float $newTotalPrice = null,
    ): bool {
        $recipientRole = $this->normalizeRecipientRole($recipientRole);
        $order->loadMissing(['user', 'driver.user', 'statusRef', 'serviceType']);

        $recipient = $this->recipientForRole($order, $recipientRole);
        if ($recipient === null) {
            return false;
        }

        if ($actor !== null && (int) $actor->id === (int) $recipient->id) {
            return false;
        }

        return $this->sender->send(
            $recipient,
            $this->buildMessage(
                order: $order,
                recipientRole: $recipientRole,
                changeType: $changeType,
                amount: $amount,
                requiresResponse: $requiresResponse,
                priceEventId: $priceEventId,
                oldTotalPrice: $oldTotalPrice,
                newTotalPrice: $newTotalPrice,
            ),
            'Notifikasi perubahan harga FCM gagal dikirim.',
            'Sebagian notifikasi perubahan harga FCM gagal terkirim.',
            [
                'order_id' => $order->id,
                'change_type' => $changeType,
                'recipient_role' => $recipientRole,
            ],
        );
    }

    public function sendOrderTotalChanged(
        Order $order,
        int $actorUserId,
        string $changeType,
        float $oldTotalPrice,
        float $newTotalPrice,
        ?int $priceEventId = null,
    ): bool {
        if (! $this->shouldNotifyTotalChanged($order, $oldTotalPrice, $newTotalPrice)) {
            return false;
        }

        $recipientRole = (int) $order->user_id === $actorUserId ? 'driver' : 'customer';

        return $this->sendPriceChanged(
            order: $order,
            recipientRole: $recipientRole,
            changeType: $changeType,
            amount: $newTotalPrice,
            requiresResponse: false,
            actor: User::query()->find($actorUserId),
            priceEventId: $priceEventId,
            oldTotalPrice: $oldTotalPrice,
            newTotalPrice: $newTotalPrice,
        );
    }

    private function buildMessage(
        Order $order,
        string $recipientRole,
        string $changeType,
        float $amount,
        bool $requiresResponse,
        ?int $priceEventId,
        ?float $oldTotalPrice,
        ?float $newTotalPrice,
    ): CloudMessage {
        [$title, $body] = $this->notificationCopy($changeType, $recipientRole, $requiresResponse, $amount);
        $route = $recipientRole === 'driver'
            ? "/driver/orders/{$order->id}/active"
            : "/orders/{$order->id}/track";

        return CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData([
                'type' => 'order_price_changed',
                'order_id' => (string) $order->id,
                'change_type' => strtoupper($changeType),
                'recipient_role' => $recipientRole,
                'requires_response' => $requiresResponse ? '1' : '0',
                'amount' => $this->amountData($amount),
                'old_total_price' => $oldTotalPrice !== null ? $this->amountData($oldTotalPrice) : '',
                'new_total_price' => $newTotalPrice !== null ? $this->amountData($newTotalPrice) : '',
                'price_event_id' => $priceEventId !== null ? (string) $priceEventId : '',
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
    private function notificationCopy(
        string $changeType,
        string $recipientRole,
        bool $requiresResponse,
        float $amount,
    ): array {
        $type = strtoupper($changeType);
        $amountText = $this->formatCurrency($amount);

        if (str_contains($type, 'DELIVERY_FEE') || str_contains($type, 'FEE')) {
            if ($requiresResponse && $recipientRole === 'customer') {
                return ['Revisi ongkir perlu persetujuan', 'Driver mengirim revisi ongkir '.$amountText.'. Buka order untuk merespons.'];
            }
            if ($requiresResponse && $recipientRole === 'driver') {
                return ['Customer menawar ongkir', 'Tawaran ongkir '.$amountText.' perlu kamu tanggapi.'];
            }
        }

        if (str_contains($type, 'COUNTER')) {
            return ['Customer mengirim tawaran', 'Tawaran harga '.$amountText.' perlu kamu tanggapi.'];
        }

        if (str_contains($type, 'APPROVED')) {
            return ['Harga disetujui', 'Harga '.$amountText.' sudah disetujui. Silakan lanjutkan order.'];
        }

        if (str_contains($type, 'CANCEL')) {
            return ['Perubahan harga dibatalkan', 'Customer membatalkan bagian order terkait perubahan harga.'];
        }

        if ($requiresResponse && $recipientRole === 'customer') {
            return ['Harga perlu persetujuan', 'Driver mengirim harga '.$amountText.'. Buka order untuk OK, Tawar, atau Batal.'];
        }

        return ['Total order diperbarui', 'Total pembayaran sekarang '.$amountText.'.'];
    }

    private function recipientForRole(Order $order, string $recipientRole): ?User
    {
        return $recipientRole === 'driver'
            ? $order->driver?->user
            : $order->user;
    }

    private function shouldNotifyTotalChanged(Order $order, float $oldTotalPrice, float $newTotalPrice): bool
    {
        $order->loadMissing(['statusRef']);
        if ((int) ($order->driver_id ?? 0) <= 0) {
            return false;
        }

        $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));
        if ($statusCode === '' || $statusCode === 'PENDING') {
            return false;
        }

        return abs(round($oldTotalPrice, 2) - round($newTotalPrice, 2)) >= 0.01;
    }

    private function normalizeRecipientRole(string $role): string
    {
        return strtolower(trim($role)) === 'driver' ? 'driver' : 'customer';
    }

    private function formatCurrency(float $amount): string
    {
        return 'Rp '.number_format(round($amount), 0, ',', '.');
    }

    private function amountData(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }
}
