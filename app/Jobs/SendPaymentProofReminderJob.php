<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Notification\PaymentProofReminderNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPaymentProofReminderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly int $startedAtTimestamp,
    ) {}

    public function handle(PaymentProofReminderNotificationService $service): void
    {
        $order = Order::query()
            ->with(['user', 'payments', 'evidences', 'statusRef'])
            ->find($this->orderId);

        if (! $order instanceof Order || ! $service->shouldRemind($order)) {
            return;
        }

        $service->sendIfNeeded($order);

        if ($this->isWithinReminderWindow()) {
            self::dispatch($this->orderId, $this->startedAtTimestamp)
                ->delay(now()->addSeconds(PaymentProofReminderNotificationService::THROTTLE_SECONDS));
        }
    }

    private function isWithinReminderWindow(): bool
    {
        $nextElapsedSeconds = now()->timestamp
            - $this->startedAtTimestamp
            + PaymentProofReminderNotificationService::THROTTLE_SECONDS;

        return $nextElapsedSeconds <= PaymentProofReminderNotificationService::MAX_REMINDER_SECONDS;
    }
}
