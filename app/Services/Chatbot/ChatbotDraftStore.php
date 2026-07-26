<?php

namespace App\Services\Chatbot;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class ChatbotDraftStore
{
    private const MAX_CONTEXT_TURNS = 8;

    /**
     * Batas nama menu yang dikirim ke model. Cukup untuk toko biasa, dan
     * menahan prompt tetap kecil untuk toko dengan katalog panjang.
     */
    private const MAX_CONTEXT_MENUS = 60;

    /**
     * @return array<string, mixed>|null
     */
    public function latestPayload(User $user, string $sessionId): ?array
    {
        $payload = $this->state($user, $sessionId)['last_payload'] ?? null;

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function savePayload(User $user, string $sessionId, array $payload): void
    {
        $state = $this->state($user, $sessionId);
        $state['last_payload'] = $payload;

        $this->putState($user, $sessionId, $state);
    }

    /**
     * @return array<string, mixed>
     */
    public function shoppingAssistantState(User $user, string $sessionId): array
    {
        $assistantState = $this->state($user, $sessionId)['shopping_assistant'] ?? [];

        return is_array($assistantState) ? $assistantState : [];
    }

    /**
     * @param  array<string, mixed>  $assistantState
     */
    public function saveShoppingAssistantState(User $user, string $sessionId, array $assistantState): void
    {
        $state = $this->state($user, $sessionId);
        $state['shopping_assistant'] = $assistantState;

        $this->putState($user, $sessionId, $state);
    }

    public function forgetShoppingAssistantState(User $user, string $sessionId): void
    {
        $state = $this->state($user, $sessionId);
        unset($state['shopping_assistant']);

        $this->putState($user, $sessionId, $state);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(User $user, string $sessionId, string $serviceType): array
    {
        $state = $this->state($user, $sessionId);
        $turns = is_array($state['turns'] ?? null) ? $state['turns'] : [];

        return [
            'service_type' => $serviceType,
            'session_id' => $sessionId,
            'current_time' => now()->toIso8601String(),
            'timezone' => (string) config('app.timezone', 'Asia/Jakarta'),
            'recent_turns' => array_values(array_slice($turns, -self::MAX_CONTEXT_TURNS)),
            'draft_state' => $this->extractDraftStateSummary(
                is_array($state['last_payload'] ?? null) ? $state['last_payload'] : null
            ),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function appendTurn(
        User $user,
        string $sessionId,
        string $role,
        string $message,
        ?array $payload = null
    ): void {
        $state = $this->state($user, $sessionId);
        $turns = is_array($state['turns'] ?? null) ? $state['turns'] : [];

        $turn = [
            'role' => $role,
            'message' => $this->truncateContextText($message, 240),
            'created_at' => now()->toIso8601String(),
        ];

        if ($payload !== null) {
            $intent = $this->normalizeOptionalContextString($payload['intent'] ?? null);
            if ($intent !== null) {
                $turn['intent'] = $intent;
            }
            $state['last_payload'] = $payload;
        }

        $turns[] = $turn;
        $state['turns'] = array_values(array_slice($turns, -self::MAX_CONTEXT_TURNS));

        $this->putState($user, $sessionId, $state);
    }

    public function forget(User $user, string $sessionId): void
    {
        Cache::forget($this->key($user, $sessionId));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(User $user, string $sessionId): array
    {
        $state = Cache::get($this->key($user, $sessionId), []);

        return is_array($state) ? $state : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function putState(User $user, string $sessionId, array $state): void
    {
        Cache::put(
            $this->key($user, $sessionId),
            $state,
            now()->addMinutes($this->ttlMinutes())
        );
    }

    private function key(User $user, string $sessionId): string
    {
        return 'chatbot:draft:v1:'.$user->id.':'.sha1(trim($sessionId));
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('bangdeliv.chatbot.draft_ttl_minutes', 120));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function extractDraftStateSummary(?array $payload): array
    {
        if ($payload === null) {
            return [];
        }

        $validation = is_array($payload['validation'] ?? null)
            ? $payload['validation']
            : [];
        $summary = [
            'intent' => (string) ($payload['intent'] ?? 'unknown'),
            'service_type' => (string) ($payload['service_type'] ?? ''),
            'validation' => [
                'is_valid_order' => ($validation['is_valid_order'] ?? false) === true,
                'missing_fields' => $this->normalizeStringList($validation['missing_fields'] ?? []),
                'next_actions' => $this->normalizeStringList($validation['next_actions'] ?? []),
            ],
        ];

        if (is_array($payload['ride'] ?? null)) {
            $ride = $payload['ride'];
            $summary['ride'] = [
                'pickup_address' => $this->normalizeOptionalContextString($ride['pickup_address'] ?? null),
                'destination_address' => $this->normalizeOptionalContextString($ride['destination_address'] ?? null),
            ];
        }

        if (is_array($payload['courier'] ?? null)) {
            $courier = $payload['courier'];
            $summary['courier'] = [
                'pickup_address' => $this->normalizeOptionalContextString($courier['pickup_address'] ?? null),
                'dropoff_address' => $this->normalizeOptionalContextString($courier['dropoff_address'] ?? null),
                'package_description' => $this->normalizeOptionalContextString($courier['package_description'] ?? null),
            ];
        }

        if (is_array($payload['shopping'] ?? null)) {
            $shopping = $payload['shopping'];
            $merchant = is_array($shopping['merchant'] ?? null) ? $shopping['merchant'] : [];
            $delivery = is_array($shopping['delivery'] ?? null) ? $shopping['delivery'] : [];
            $items = is_array($shopping['items'] ?? null) ? $shopping['items'] : [];
            $stops = is_array($shopping['stops'] ?? null) ? $shopping['stops'] : [];
            $activeStop = collect($stops)
                ->first(static fn ($stop): bool => is_array($stop) && ($stop['is_active'] ?? false) === true);

            $summary['shopping'] = [
                'merchant_name' => $this->normalizeOptionalContextString($merchant['name'] ?? null),
                'merchant_count' => count(array_filter(
                    $stops,
                    static fn ($stop): bool => is_array($stop)
                        && trim((string) data_get($stop, 'merchant.name', '')) !== ''
                )),
                'active_merchant_name' => $this->normalizeOptionalContextString(
                    is_array($activeStop) ? data_get($activeStop, 'merchant.name') : null
                ),
                'available_menus' => $this->activeMerchantMenuNames($activeStop, $merchant),
                'delivery_address' => $this->normalizeOptionalContextString($delivery['address'] ?? null),
                'item_count' => count($items),
                'stops' => collect($stops)
                    ->filter(static fn ($stop): bool => is_array($stop))
                    ->map(static fn (array $stop): array => [
                        'merchant_name' => data_get($stop, 'merchant.name'),
                        'item_count' => is_array($stop['items'] ?? null) ? count($stop['items']) : 0,
                        'is_active' => ($stop['is_active'] ?? false) === true,
                    ])
                    ->values()
                    ->all(),
                'ready_to_confirm' => (bool) ($shopping['ready_to_confirm'] ?? false),
                'payment_method' => $this->normalizeOptionalContextString(
                    $shopping['payment_method'] ?? data_get($payload, 'order.payment_method')
                ),
            ];
        }

        if (is_array($payload['delivery'] ?? null)) {
            $delivery = $payload['delivery'];
            $summary['delivery'] = [
                'address' => $this->normalizeOptionalContextString($delivery['address'] ?? null),
            ];
        }

        return $summary;
    }

    /**
     * Nama menu toko aktif untuk disertakan ke CONTEXT_JSON. Tanpa ini model
     * harus menebak nama menu tanpa pernah melihat katalognya, sehingga item
     * nyaris selalu jatuh ke MANUAL walau menunya ada di database.
     *
     * Hanya toko resmi (punya merchant.id) yang punya katalog; toko dari Google
     * Place mengembalikan array kosong dan perilakunya tidak berubah.
     *
     * @param  array<string, mixed>  $merchant
     * @return array<int, string>
     */
    private function activeMerchantMenuNames(mixed $activeStop, array $merchant): array
    {
        $merchantId = is_array($activeStop) ? data_get($activeStop, 'merchant.id') : null;
        if (! is_numeric($merchantId)) {
            $merchantId = $merchant['id'] ?? null;
        }

        if (! is_numeric($merchantId) || (int) $merchantId <= 0) {
            return [];
        }

        return Menu::query()
            ->where('restaurant_id', (int) $merchantId)
            ->where('is_available', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(self::MAX_CONTEXT_MENUS)
            ->pluck('name')
            ->map(static fn (mixed $name): string => trim((string) $name))
            ->filter(static fn (string $name): bool => $name !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $valueString = trim((string) $value);
            if ($valueString !== '') {
                $normalized[] = $valueString;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeOptionalContextString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $this->truncateContextText($normalized, 240);
    }

    private function truncateContextText(string $text, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(1, $maxLength - 3))).'...';
    }
}
