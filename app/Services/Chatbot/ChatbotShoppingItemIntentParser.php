<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Str;

class ChatbotShoppingItemIntentParser
{
    public const OP_ADD = 'add';

    public const OP_SET = 'set';

    public const OP_REMOVE = 'remove';

    /**
     * @return array<int, array{name: string, quantity: int, operation: string, notes: null, is_heavy: false}>
     */
    public function parse(string $message): array
    {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return [];
        }

        $operation = $this->detectOperation($normalized);
        $hasExplicitQuantity = preg_match('/\b\d+\s*x\b/u', $normalized) === 1;
        if ($operation === null && ! $hasExplicitQuantity) {
            return [];
        }

        $messageWithoutMerchant = $this->stripMerchantTail($normalized);
        $parts = preg_split('/(?:,|\+|\bdan\b)/iu', $messageWithoutMerchant) ?: [$messageWithoutMerchant];
        $items = [];

        foreach ($parts as $part) {
            $item = $this->parsePart((string) $part, $operation ?? self::OP_ADD);
            if ($item === null) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }

    public function normalizeOperation(mixed $operation): ?string
    {
        $normalized = strtolower(trim((string) ($operation ?? '')));

        return match ($normalized) {
            self::OP_ADD, 'increment', 'append' => self::OP_ADD,
            self::OP_SET, 'replace', 'update' => self::OP_SET,
            self::OP_REMOVE, 'delete' => self::OP_REMOVE,
            default => null,
        };
    }

    private function detectOperation(string $message): ?string
    {
        if (preg_match('/\b(?:hapus|hilangkan|batalkan(?:\s+item)?|nggak\s+jadi|gak\s+jadi|tidak\s+jadi)\b/iu', $message) === 1) {
            return self::OP_REMOVE;
        }

        if (preg_match('/\b(?:saja|aja|cukup|jadi|ubah|ganti(?:\s+jumlah)?)\b/iu', $message) === 1) {
            return self::OP_SET;
        }

        if (preg_match('/\b(?:tambah(?:kan)?|plus|sekalian)\b/iu', $message) === 1) {
            return self::OP_ADD;
        }

        return null;
    }

    /**
     * @return array{name: string, quantity: int, operation: string, notes: null, is_heavy: false}|null
     */
    private function parsePart(string $part, string $operation): ?array
    {
        $part = $this->stripMerchantTail($this->normalize($part));
        if ($part === '') {
            return null;
        }

        [$quantity, $withoutQuantity] = $this->extractQuantity($part, $operation);
        $name = $this->cleanItemName($withoutQuantity);
        if ($name === '' || in_array($name, ['halo', 'hai', 'test', 'tes'], true)) {
            return null;
        }

        return [
            'name' => $name,
            'quantity' => $quantity,
            'operation' => $operation,
            'notes' => null,
            'is_heavy' => false,
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function extractQuantity(string $value, string $operation): array
    {
        if (preg_match('/\b(\d+)\s*x\b/u', $value, $match) === 1) {
            $quantity = max(1, (int) $match[1]);
            $withoutQuantity = $this->regexReplace('/\b\d+\s*x\b/u', ' ', $value);

            return [$quantity, $withoutQuantity];
        }

        if ($operation === self::OP_SET && preg_match('/\b(\d+)\b/u', $value, $match) === 1) {
            $quantity = max(1, (int) $match[1]);
            $withoutQuantity = $this->regexReplace('/\b'.preg_quote($match[1], '/').'\b/u', ' ', $value);

            return [$quantity, $withoutQuantity];
        }

        return [1, $value];
    }

    private function cleanItemName(string $value): string
    {
        $value = $this->regexReplace('/\b(?:nggak|gak|tidak)\s+jadi\b/iu', ' ', $value);
        $value = $this->regexReplace('/\b(?:batalkan\s+item|ganti\s+jumlah)\b/iu', ' ', $value);
        $value = $this->regexReplace('/\b(?:tambah(?:kan)?|plus|sekalian|hapus|hilangkan|batalkan|cukup|jadi|ubah|ganti)\b/iu', ' ', $value);
        $value = $this->regexReplace('/^(?:titip|belikan|beli|pesan|mau|tolong)\s+/iu', ' ', $value);
        $value = $this->regexReplace('/\b([\pL\pN]+)nya\b/u', '$1', $value);
        $value = $this->regexReplace('/\b(?:saja|aja|item|menu|jumlah)\b/iu', ' ', $value);

        return $this->normalize($value);
    }

    private function stripMerchantTail(string $value): string
    {
        return $this->normalize(
            $this->regexReplace('/\b(?:di|dari)\s+[\pL\pN\s.&-]+$/iu', ' ', $value)
        );
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->squish()->toString();
    }

    private function regexReplace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);

        return is_string($result) ? $result : $subject;
    }
}
