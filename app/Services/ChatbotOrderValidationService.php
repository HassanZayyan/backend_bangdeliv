<?php

namespace App\Services;

use App\Models\Menu;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Builder;

class ChatbotOrderValidationService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $intent = strtolower((string) ($payload['intent'] ?? 'unknown'));
        $restoInput = $this->normalizeOptionalString($payload['resto'] ?? null);
        $itemsInput = $this->normalizeItems($payload['items'] ?? []);

        if ($intent !== 'pesan_makanan') {
            return [
                'intent' => 'out_of_domain',
                'resto' => $restoInput,
                'items' => $itemsInput,
                'validation' => [
                    'is_valid_order' => false,
                    'rejection_reasons' => [
                        'Pesan di luar domain pemesanan makanan.',
                    ],
                    'matched_restaurant' => null,
                    'matched_items' => [],
                    'unmatched_items' => $itemsInput,
                ],
            ];
        }

        $reasons = [];
        $matchedItems = [];
        $unmatchedItems = [];

        $restaurant = null;
        if ($restoInput !== null) {
            $restaurant = $this->findRestaurantByName($restoInput);

            if ($restaurant === null) {
                $reasons[] = "Restoran '{$restoInput}' tidak ditemukan.";
            }
        }

        if ($itemsInput === []) {
            $reasons[] = 'Belum ada item menu yang bisa diproses.';
        }

        foreach ($itemsInput as $item) {
            $menu = $this->findMenuByName($item['menu'], $restaurant?->id);

            if ($menu === null) {
                $unmatchedItems[] = $item;

                continue;
            }

            $matchedItems[] = [
                'menu_id' => $menu->id,
                'menu_name' => $menu->name,
                'qty' => (int) $item['qty'],
                'restaurant_id' => $menu->restaurant->id,
                'restaurant_name' => $menu->restaurant->name,
            ];
        }

        if ($unmatchedItems !== []) {
            $reasons[] = 'Sebagian menu tidak ditemukan atau sedang tidak tersedia.';
        }

        if ($matchedItems !== []) {
            $restaurantIds = array_values(array_unique(array_map(
                static fn (array $item): int => (int) $item['restaurant_id'],
                $matchedItems
            )));

            if (count($restaurantIds) > 1) {
                $reasons[] = 'Menu yang dipilih berasal dari restoran berbeda. Mohon pesan dari satu restoran saja.';
            }

            if ($restaurant === null && count($restaurantIds) === 1) {
                $restaurant = Restaurant::query()->find($restaurantIds[0]);
            }
        }

        $isValidOrder = $reasons === [] && $matchedItems !== [];

        return [
            'intent' => 'pesan_makanan',
            'resto' => $restoInput,
            'items' => $itemsInput,
            'validation' => [
                'is_valid_order' => $isValidOrder,
                'rejection_reasons' => $reasons,
                'matched_restaurant' => $restaurant ? [
                    'id' => $restaurant->id,
                    'name' => $restaurant->name,
                    'slug' => $restaurant->slug,
                ] : null,
                'matched_items' => $matchedItems,
                'unmatched_items' => $unmatchedItems,
            ],
        ];
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '' || strtolower($normalized) === 'null') {
            return null;
        }

        return $normalized;
    }

    /**
     * @return array<int, array{menu: string, qty: int}>
     */
    private function normalizeItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $menu = trim((string) ($item['menu'] ?? ''));
            $qty = max(1, (int) ($item['qty'] ?? 1));

            if ($menu === '') {
                continue;
            }

            $normalized[] = [
                'menu' => $menu,
                'qty' => $qty,
            ];
        }

        return $normalized;
    }

    private function findRestaurantByName(string $input): ?Restaurant
    {
        return Restaurant::query()
            ->where('status', 'active')
            ->where(function (Builder $query) use ($input): void {
                $query->whereRaw('LOWER(name) = ?', [strtolower($input)])
                    ->orWhere('name', 'like', "%{$input}%")
                    ->orWhereRaw('LOWER(slug) = ?', [strtolower($input)])
                    ->orWhere('slug', 'like', "%{$input}%");
            })
            ->orderBy('name')
            ->orderBy('id')
            ->first();
    }

    private function findMenuByName(string $input, ?int $restaurantId = null): ?Menu
    {
        return Menu::query()
            ->where('is_available', true)
            ->whereHas('restaurant', function (Builder $query): void {
                $query->where('status', 'active');
            })
            ->when($restaurantId !== null, function (Builder $query) use ($restaurantId): void {
                $query->where('restaurant_id', $restaurantId);
            })
            ->where(function (Builder $query) use ($input): void {
                $query->whereRaw('LOWER(name) = ?', [strtolower($input)])
                    ->orWhere('name', 'like', "%{$input}%");
            })
            ->with('restaurant')
            ->orderBy('restaurant_id')
            ->orderBy('sort_order')
            ->first();
    }
}
