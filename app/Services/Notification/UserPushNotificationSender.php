<?php

namespace App\Services\Notification;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;

class UserPushNotificationSender
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function send(
        User $recipient,
        CloudMessage $message,
        string $failureMessage,
        string $partialFailureMessage,
        array $context = [],
    ): bool {
        try {
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
                $this->notificationLog()->info('Notifikasi FCM tidak dikirim karena token aktif tidak ditemukan.', [
                    ...$context,
                    'recipient_user_id' => $recipient->id,
                    'device_type' => 'android',
                    'sent' => false,
                    'reason' => 'no_active_android_token',
                ]);

                return false;
            }

            $report = $this->messaging()->sendMulticast($message, $tokens);
            $successCount = $report->successes()->count();

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
                $this->notificationLog()->warning($partialFailureMessage, [
                    ...$context,
                    'recipient_user_id' => $recipient->id,
                    'failed_count' => $report->failures()->count(),
                    'success_count' => $successCount,
                    'token_count' => count($tokens),
                    'invalid_token_count' => count($report->invalidTokens()),
                    'unknown_token_count' => count($report->unknownTokens()),
                    'sent' => $successCount > 0,
                ]);
            }

            if ($successCount > 0) {
                $this->notificationLog()->info('Notifikasi FCM terkirim.', [
                    ...$context,
                    'recipient_user_id' => $recipient->id,
                    'success_count' => $successCount,
                    'token_count' => count($tokens),
                    'sent' => true,
                ]);
            }

            return $successCount > 0;
        } catch (\Throwable $exception) {
            $this->notificationLog()->error($failureMessage, [
                ...$context,
                'recipient_user_id' => $recipient->id,
                'error' => $exception->getMessage(),
                'sent' => false,
            ]);

            return false;
        }
    }

    private function messaging(): Messaging
    {
        return $this->container->make(Messaging::class);
    }

    private function notificationLog(): \Psr\Log\LoggerInterface
    {
        return Log::channel('notifications');
    }
}
