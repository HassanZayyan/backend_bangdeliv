<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Str;

final class ChatbotShoppingItemNormalizer
{
    public static function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    public static function operation(mixed $operation): ?string
    {
        $normalized = strtolower(trim((string) ($operation ?? '')));

        return match ($normalized) {
            ChatbotShoppingItemIntentParser::OP_ADD, 'increment', 'append' => ChatbotShoppingItemIntentParser::OP_ADD,
            ChatbotShoppingItemIntentParser::OP_SET, 'replace', 'update' => ChatbotShoppingItemIntentParser::OP_SET,
            ChatbotShoppingItemIntentParser::OP_REMOVE, 'delete' => ChatbotShoppingItemIntentParser::OP_REMOVE,
            ChatbotShoppingItemIntentParser::OP_DECREMENT, 'decrease', 'subtract', 'reduce', 'kurangi', 'kurang' => ChatbotShoppingItemIntentParser::OP_DECREMENT,
            default => null,
        };
    }

    /**
     * @return array<int, array{name: string, quantity: int, operation: string, notes: string|null}>
     */
    public static function incomingItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $normalizedItem = self::draftItem($item, ['name', 'menu'], true);
            if ($normalizedItem !== null) {
                $normalized[] = $normalizedItem;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, array{name: string, menu: string, quantity: int, qty: int, notes: string|null, operation?: string}>
     */
    public static function geminiItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? $item['menu'] ?? ''));
            if ($name === '') {
                continue;
            }

            $quantity = self::quantity($item);
            $normalizedItem = [
                'name' => $name,
                'menu' => $name,
                'quantity' => $quantity,
                'qty' => $quantity,
                'notes' => self::optionalString($item['notes'] ?? null),
            ];

            $operation = self::operation($item['operation'] ?? null);
            if ($operation !== null) {
                $normalizedItem['operation'] = $operation;
            }

            $normalized[] = $normalizedItem;
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $nameKeys
     * @return array{name: string, quantity: int, notes: string|null, operation?: string}|null
     */
    public static function draftItem(mixed $item, array $nameKeys = ['name', 'menu_name'], bool $includeOperation = false): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $name = self::firstString($item, $nameKeys);
        if ($name === null) {
            return null;
        }

        $normalized = [
            'name' => $name,
            'quantity' => self::quantity($item),
        ];

        if ($includeOperation) {
            $normalized['operation'] = self::operation($item['operation'] ?? null)
                ?? ChatbotShoppingItemIntentParser::OP_ADD;
        }

        $normalized['notes'] = self::optionalString($item['notes'] ?? null);

        return $normalized;
    }

    public static function itemKey(string $name): string
    {
        return Str::of($name)->lower()->squish()->toString();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function quantity(array $item): int
    {
        return max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $keys
     */
    private static function firstString(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = self::optionalString($item[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
