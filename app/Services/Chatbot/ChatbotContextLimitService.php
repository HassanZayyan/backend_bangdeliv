<?php

namespace App\Services\Chatbot;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class ChatbotContextLimitService
{
    public function ensureNotBlocked(User $user, string $serviceType): void
    {
        if (! Cache::has($this->blockKey($user, $serviceType))) {
            return;
        }

        throw new ApiException($this->blockedMessage(), 429);
    }

    /**
     * @param  array<string, mixed>|null  $nluPayload
     */
    public function isOutOfContext(?array $nluPayload, string $expectedIntent): bool
    {
        if ($nluPayload === null) {
            return false;
        }

        $intent = strtolower(trim((string) ($nluPayload['intent'] ?? '')));
        if ($intent === '') {
            return false;
        }

        return $intent === 'out_of_domain' || $intent !== strtolower($expectedIntent);
    }

    public function recordOutOfContext(User $user, string $serviceType): void
    {
        $key = $this->counterKey($user, $serviceType);
        $state = Cache::get($key, []);
        $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;
        $count++;

        Cache::put($key, ['count' => $count], now()->addMinutes($this->windowMinutes()));

        if ($count < $this->limit()) {
            return;
        }

        Cache::put($this->blockKey($user, $serviceType), true, now()->addMinutes($this->blockMinutes()));

        throw new ApiException($this->blockedMessage(), 429);
    }

    public function clear(User $user, string $serviceType): void
    {
        Cache::forget($this->counterKey($user, $serviceType));
        Cache::forget($this->blockKey($user, $serviceType));
    }

    /**
     * @return array<string, mixed>
     */
    public function responsePayload(string $serviceType): array
    {
        return [
            'intent' => 'out_of_domain',
            'service_type' => $serviceType,
            'assistant_text' => 'BangBot hanya membantu membuat pesanan di BangDeliv. Tulis detail pesanan seperti tujuan, tempat, barang, atau metode pembayaran.',
            'validation' => [
                'is_valid_order' => false,
                'rejection_reasons' => ['Pesan tidak sesuai konteks pemesanan.'],
                'missing_fields' => [],
                'next_actions' => [],
            ],
            'order' => [
                'created' => false,
                'id' => null,
                'order_number' => null,
                'delivery_fee' => null,
            ],
        ];
    }

    private function counterKey(User $user, string $serviceType): string
    {
        return 'chatbot:context-limit:v1:'.$user->id.':'.$this->normalizeServiceType($serviceType);
    }

    private function blockKey(User $user, string $serviceType): string
    {
        return 'chatbot:context-block:v1:'.$user->id.':'.$this->normalizeServiceType($serviceType);
    }

    private function normalizeServiceType(string $serviceType): string
    {
        return strtolower(trim($serviceType));
    }

    private function limit(): int
    {
        return max(1, (int) config('bangdeliv.chatbot.out_of_context_limit', 3));
    }

    private function windowMinutes(): int
    {
        return max(1, (int) config('bangdeliv.chatbot.out_of_context_window_minutes', 30));
    }

    private function blockMinutes(): int
    {
        return max(1, (int) config('bangdeliv.chatbot.out_of_context_block_minutes', 15));
    }

    private function blockedMessage(): string
    {
        return 'Chatbot BangDeliv hanya untuk membuat pesanan. Kamu bisa coba lagi beberapa menit lagi.';
    }
}
