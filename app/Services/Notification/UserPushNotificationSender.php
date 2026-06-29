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
                Log::debug('Notifikasi FCM tidak dikirim karena token aktif tidak ditemukan.', [
                    ...$context,
                    'recipient_user_id' => $recipient->id,
                    'device_type' => 'android',
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
                Log::warning($partialFailureMessage, [
                    ...$context,
                    'recipient_user_id' => $recipient->id,
                    'failed_count' => $report->failures()->count(),
                    'success_count' => $successCount,
                    'token_count' => count($tokens),
                ]);
            }

            if ($successCount > 0) {
                Log::debug('Notifikasi FCM terkirim.', [
                    ...$context,
                    'recipient_user_id' => $recipient->id,
                    'success_count' => $successCount,
                    'token_count' => count($tokens),
                ]);
            }

            return $successCount > 0;
        } catch (\Throwable $exception) {
            Log::warning($failureMessage, [
                ...$context,
                'recipient_user_id' => $recipient->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function messaging(): Messaging
    {
        return $this->container->make(Messaging::class);
    }
}
