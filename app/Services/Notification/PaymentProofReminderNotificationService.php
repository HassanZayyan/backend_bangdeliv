<?php

namespace App\Services\Notification;

use App\Models\Order;
use App\Models\OrderEvidence;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\Order\OrderPaymentService;
use Illuminate\Support\Facades\Cache;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class PaymentProofReminderNotificationService
{
    private const THROTTLE_SECONDS = 600;

    public function __construct(private readonly UserPushNotificationSender $sender) {}

    public function sendIfNeeded(Order $order, ?User $actor = null): bool
    {
        $order->loadMissing(['user', 'payments', 'evidences']);
        $recipient = $order->user;
        if (! $recipient instanceof User) {
            return false;
        }

        if ($actor instanceof User && (int) $actor->id === (int) $recipient->id) {
            return false;
        }

        if (! $this->needsTransferProof($order)) {
            return false;
        }

        $cacheKey = 'payment-proof-reminder:'.$order->id.':'.$recipient->id;
        if (Cache::has($cacheKey)) {
            return false;
        }
        Cache::put($cacheKey, true, self::THROTTLE_SECONDS);

        return $this->sender->send(
            $recipient,
            $this->buildMessage($order),
            'Notifikasi reminder bukti QRIS gagal dikirim.',
            'Sebagian notifikasi reminder bukti QRIS gagal terkirim.',
            [
                'order_id' => $order->id,
                'type' => 'payment_proof_required',
            ],
        );
    }

    private function needsTransferProof(Order $order): bool
    {
        $payment = $this->latestPayment($order);
        if (! $payment instanceof OrderPayment) {
            return false;
        }

        if (strtoupper((string) $payment->payment_method) !== OrderPaymentService::METHOD_TRANSFER) {
            return false;
        }

        if (strtoupper((string) $payment->payment_status) === OrderPaymentService::STATUS_PAID) {
            return false;
        }

        return ! $this->hasPaymentTransferEvidence($order);
    }

    private function latestPayment(Order $order): ?OrderPayment
    {
        if ($order->relationLoaded('payments')) {
            return $order->payments
                ->sortByDesc(fn (OrderPayment $payment): int => (int) $payment->id)
                ->first();
        }

        return $order->payments()->latest('id')->first();
    }

    private function hasPaymentTransferEvidence(Order $order): bool
    {
        if ($order->relationLoaded('evidences')) {
            return $order->evidences->contains(
                fn (OrderEvidence $evidence): bool => strtoupper((string) $evidence->evidence_type) === 'PAYMENT_TRANSFER_PHOTO'
            );
        }

        return $order->evidences()
            ->where('evidence_type', 'PAYMENT_TRANSFER_PHOTO')
            ->exists();
    }

    private function buildMessage(Order $order): CloudMessage
    {
        $route = "/orders/{$order->id}/track?focus=payment";

        return CloudMessage::new()
            ->withNotification(Notification::create(
                'Upload bukti QRIS',
                'Driver belum bisa menyelesaikan order sebelum bukti pembayaran diupload.'
            ))
            ->withData([
                'type' => 'payment_proof_required',
                'order_id' => (string) $order->id,
                'recipient_role' => 'customer',
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
}
