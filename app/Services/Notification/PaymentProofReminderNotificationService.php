<?php

namespace App\Services\Notification;

use App\Jobs\SendPaymentProofReminderJob;
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
    public const THROTTLE_SECONDS = 60;

    public const MAX_REMINDER_SECONDS = 900;

    public const INITIAL_DELAY_SECONDS = 12;

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

        if (! $this->shouldRemind($order, $actor)) {
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

    public function scheduleAfterDelivered(Order $order): void
    {
        $order->loadMissing(['statusRef', 'payments', 'evidences', 'user']);
        if (strtoupper((string) ($order->statusRef?->code ?? '')) !== 'DELIVERED') {
            return;
        }

        if (! $this->shouldRemind($order)) {
            return;
        }

        $cacheKey = 'payment-proof-reminder-chain:'.$order->id;
        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, self::MAX_REMINDER_SECONDS + self::INITIAL_DELAY_SECONDS + self::THROTTLE_SECONDS);

        SendPaymentProofReminderJob::dispatch($order->id, now()->timestamp)
            ->delay(now()->addSeconds(self::INITIAL_DELAY_SECONDS));
    }

    public function shouldRemind(Order $order, ?User $actor = null): bool
    {
        $order->loadMissing(['statusRef', 'payments', 'evidences']);

        if ($actor instanceof User && (int) $actor->id === (int) $order->user_id) {
            return false;
        }

        $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));
        if (in_array($statusCode, ['COMPLETED', 'CANCELLED', 'CANCELLED_WITH_FEE'], true)) {
            return false;
        }

        return $this->needsTransferProof($order);
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
