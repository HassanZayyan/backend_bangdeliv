<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Str;

class ChatbotShoppingItemIntentParser
{
    public const OP_ADD = 'add';

    public const OP_SET = 'set';

    public const OP_REMOVE = 'remove';

    public const OP_DECREMENT = 'decrement';

    /**
     * @return array<int, array{name: string, quantity: int, operation: string, notes: null}>
     */
    public function parse(
        string $message,
        bool $allowImplicitSingleItem = false,
        bool $allowBareTrailingQuantity = false
    ): array {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return [];
        }

        $operation = $this->detectOperation($normalized);
        $hasExplicitQuantity = $this->hasExplicitQuantity($normalized, $allowBareTrailingQuantity);
        if ($operation === null && ! $hasExplicitQuantity && ! $allowImplicitSingleItem) {
            return [];
        }

        $parts = $this->splitParts($message);
        $items = [];

        foreach ($parts as $part) {
            $item = $this->parsePart((string) $part, $operation ?? self::OP_ADD, $allowBareTrailingQuantity);
            if ($item === null) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }

    public function requiresItemClarification(string $message, bool $allowBareTrailingQuantity = false): bool
    {
        $normalized = $this->normalize($message);
        $operation = $this->detectOperation($normalized);
        if ($operation === null) {
            return false;
        }

        foreach ($this->splitParts($message) as $part) {
            if ($this->parsePart($part, $operation, $allowBareTrailingQuantity) !== null) {
                return false;
            }
        }

        return true;
    }

    public function normalizeOperation(mixed $operation): ?string
    {
        return ChatbotShoppingItemNormalizer::operation($operation);
    }

    private function detectOperation(string $message): ?string
    {
        if (preg_match('/\b(?:kurangi|kurangin|kurang(?:kan)?)\b/iu', $message) === 1) {
            return self::OP_DECREMENT;
        }

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

    private function hasExplicitQuantity(string $message, bool $allowBareTrailingQuantity): bool
    {
        $withoutMerchantTail = $this->stripMerchantTail($message);

        return preg_match($this->quantityPattern(), $message) === 1
            || preg_match($this->quantityPattern(), $withoutMerchantTail) === 1
            || ($allowBareTrailingQuantity && (
                preg_match($this->bareTrailingQuantityPattern(), $message) === 1
                || preg_match($this->bareTrailingQuantityPattern(), $withoutMerchantTail) === 1
            ));
    }

    /**
     * @return array<int, string>
     */
    private function splitParts(string $message): array
    {
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $lines = preg_split('/\n+/u', $message) ?: [$message];
        $parts = [];

        foreach ($lines as $line) {
            $line = $this->stripListMarker((string) $line);
            if (trim($line) === '') {
                continue;
            }

            if ($this->isMerchantHeaderLine($line)) {
                continue;
            }

            $line = $this->mergeQuantityOnlyCommaSegments($line);
            $segments = preg_split('/(?:,|\+|\bdan\b)/iu', $line) ?: [$line];
            foreach ($segments as $segment) {
                $segment = trim((string) $segment);
                if ($segment !== '') {
                    $parts[] = $segment;
                }
            }
        }

        return $parts === [] ? [$message] : $parts;
    }

    /**
     * @return array{name: string, quantity: int, operation: string, notes: null}|null
     */
    private function parsePart(string $part, string $operation, bool $allowBareTrailingQuantity): ?array
    {
        $part = $this->stripMerchantTail($this->normalize($part));
        if ($part === '') {
            return null;
        }

        [$quantity, $withoutQuantity] = $this->extractQuantity($part, $operation, $allowBareTrailingQuantity);
        $name = $this->cleanItemName($withoutQuantity);
        if ($name === '' || in_array($name, ['halo', 'hai', 'test', 'tes'], true)) {
            return null;
        }

        return [
            'name' => $name,
            'quantity' => $quantity,
            'operation' => $operation,
            'notes' => null,
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function extractQuantity(string $value, string $operation, bool $allowBareTrailingQuantity): array
    {
        if (preg_match($this->quantityPattern(), $value, $match, PREG_OFFSET_CAPTURE) === 1) {
            $quantity = max(1, (int) $match[1][0]);
            $unit = strtolower((string) $match[2][0]);
            $matchedText = (string) $match[0][0];
            $matchedOffset = (int) $match[0][1];
            $afterMatch = trim(substr($value, $matchedOffset + strlen($matchedText)));
            $replacement = $unit === 'paket' && $afterMatch !== '' ? ' paket ' : ' ';
            $withoutQuantity = preg_replace($this->quantityPattern(), $replacement, $value, 1);
            if (! is_string($withoutQuantity)) {
                $withoutQuantity = $value;
            }

            return [$quantity, $withoutQuantity];
        }

        if ($allowBareTrailingQuantity && preg_match($this->bareTrailingQuantityPattern(), $value, $match, PREG_OFFSET_CAPTURE) === 1) {
            $quantity = max(1, (int) $match[1][0]);
            $withoutQuantity = preg_replace($this->bareTrailingQuantityPattern(), ' ', $value, 1);
            if (! is_string($withoutQuantity)) {
                $withoutQuantity = $value;
            }

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
        $value = $this->stripListMarker($value);
        $value = $this->regexReplace('/\b(?:nggak|gak|tidak)\s+jadi\b/iu', ' ', $value);
        $value = $this->regexReplace('/\b(?:batalkan\s+item|ganti\s+jumlah)\b/iu', ' ', $value);
        $value = $this->regexReplace('/\b(?:tambah(?:kan)?|plus|sekalian|kurangi|kurangin|kurang(?:kan)?|hapus|hilangkan|batalkan|cukup|jadi|ubah|ganti)\b/iu', ' ', $value);
        $value = $this->regexReplace('/^(?:aku|saya|gue|gua)\s+(?:mau|ingin|pengen|pingin)\s+(?:beli|belikan|pesan|titip)\s+/iu', ' ', $value);
        $value = $this->regexReplace('/^(?:mau|ingin|pengen|pingin)\s+(?:beli|belikan|pesan|titip)\s+/iu', ' ', $value);
        $value = $this->regexReplace('/^(?:tolong|coba)\s+(?:beli|belikan|pesan|titip)\s+/iu', ' ', $value);
        $value = $this->regexReplace('/^(?:titip|belikan|beli|pesan|mau|tolong)\s+/iu', ' ', $value);
        $value = $this->regexReplace('/\b([\pL\pN]+)nya\b/u', '$1', $value);
        $value = $this->regexReplace('/\b(?:eh|dong|lagi|saja|aja|item|menu|jumlah)\b/iu', ' ', $value);

        return $this->normalize($value);
    }

    private function stripListMarker(string $value): string
    {
        return trim($this->regexReplace('/^\s*(?:[-*]|\x{2022}|\d+[\.)])\s*/u', ' ', $value));
    }

    private function isMerchantHeaderLine(string $value): bool
    {
        return preg_match('/^(?:beli|belikan|pesan|titip)\s+(?:di|dari)\s+[\pL\pN\s.&\'\x{2019}-]+:\s*$/iu', trim($value)) === 1;
    }

    private function mergeQuantityOnlyCommaSegments(string $value): string
    {
        return $this->regexReplace(
            '/,\s*(\d+\s*(?:x|porsi|pcs?|buah|paket|bungkus|bks|botol|gelas|cup|kotak|pack|biji)?)(?=\s*(?:,|\+|\bdan\b|$))/iu',
            ' $1',
            $value
        );
    }

    private function quantityPattern(): string
    {
        return '/\b(\d+)\s*(x|porsi|pcs?|buah|paket|bungkus|bks|botol|gelas|cup|kotak|pack|biji)\b/iu';
    }

    private function bareTrailingQuantityPattern(): string
    {
        return '/(?<!\blevel\s)\b(\d+)\s*(?:dong)?\s*$/iu';
    }

    private function stripMerchantTail(string $value): string
    {
        return $this->normalize(
            $this->regexReplace('/\b(?:di|dari)\s+[\pL\pN\s.&\'\x{2019}-]+$/iu', ' ', $value)
        );
    }

    /**
     * Kebalikan stripMerchantTail: mengembalikan nama tempat pada ekor
     * "di/dari <tempat>" (span yang sama yang dibuang stripMerchantTail),
     * ternormalisasi lowercase. Dipakai untuk routing toko/stop yang benar
     * (buka/edit/hapus tempat) tanpa bergantung kapitalisasi. null bila tak ada.
     */
    public function extractMerchantTail(string $value): ?string
    {
        $normalized = $this->normalize($value);
        if (preg_match('/\b(?:di|dari)\s+([\pL\pN\s.&\'\x{2019}-]+)$/iu', $normalized, $match) !== 1) {
            return null;
        }

        $tail = $this->normalize((string) $match[1]);

        return $tail === '' ? null : $tail;
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
