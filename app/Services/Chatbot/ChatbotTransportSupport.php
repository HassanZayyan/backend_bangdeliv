<?php

namespace App\Services\Chatbot;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Order\OrderPaymentService;

final class ChatbotTransportSupport
{
    public static function ensureActiveCustomer(User $user, string $serviceLabel): void
    {
        if ($user->role !== 'customer') {
            throw new ApiException("Hanya customer yang dapat membuat order {$serviceLabel} dari chatbot.", 403);
        }

        if (! $user->is_active || $user->is_blacklisted) {
            throw new ApiException("Akun tidak memenuhi syarat untuk membuat order {$serviceLabel}.", 403);
        }
    }

    public static function normalizePaymentMethodOrNull(mixed $value): ?string
    {
        $normalized = strtoupper(trim((string) ($value ?? '')));
        if ($normalized === OrderPaymentService::METHOD_TRANSFER) {
            return OrderPaymentService::METHOD_TRANSFER;
        }
        if ($normalized === OrderPaymentService::METHOD_COD) {
            return OrderPaymentService::METHOD_COD;
        }

        return null;
    }

    public static function paymentMethodLabel(mixed $value): string
    {
        return self::normalizePaymentMethodOrNull($value) === OrderPaymentService::METHOD_TRANSFER
            ? 'QRIS'
            : 'COD';
    }

    public static function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    public static function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Deteksi metode pembayaran dari teks yang SUDAH dinormalisasi pemanggil
     * (tiap service punya pre-normalisasi berbeda yang harus dipertahankan).
     */
    public static function paymentMethodFromNormalizedText(string $normalized): ?string
    {
        if (preg_match('/\b(?:transfer|tf|bank|qris|non tunai|nontunai)\b/u', $normalized) === 1) {
            return OrderPaymentService::METHOD_TRANSFER;
        }

        if (preg_match('/\b(?:cod|cash|tunai)\b/u', $normalized) === 1) {
            return OrderPaymentService::METHOD_COD;
        }

        return null;
    }

    public static function isPaymentMethodOnlyMessage(string $message, ?string $paymentMethod): bool
    {
        if ($paymentMethod === null) {
            return false;
        }

        $normalized = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $message)));
        $normalized = self::normalizeWhitespace($normalized);

        return in_array($normalized, [
            'cod',
            'cash',
            'tunai',
            'transfer',
            'tf',
            'bank',
            'qris',
            'non tunai',
            'nontunai',
        ], true);
    }

    public static function nullableCoordinate(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public static function hasCoordinatePair(array $source, string $prefix): bool
    {
        return self::nullableCoordinate($source[$prefix.'_latitude'] ?? null) !== null
            && self::nullableCoordinate($source[$prefix.'_longitude'] ?? null) !== null;
    }

    /**
     * @return array<string, array{label: string, message: string}>
     */
    public static function paymentDraftActionPayloads(bool $includeConfirm): array
    {
        $payloads = [
            'RESET_DESTINATION' => [
                'label' => 'Ubah Tujuan',
                'message' => 'Ubah Tujuan',
            ],
            'SET_PAYMENT_COD' => [
                'label' => 'COD',
                'message' => 'COD',
            ],
            'SET_PAYMENT_TRANSFER' => [
                'label' => 'QRIS',
                'message' => 'QRIS',
            ],
        ];

        if ($includeConfirm) {
            $payloads['CONFIRM_DRAFT'] = [
                'label' => 'Buat Pesanan',
                'message' => 'Konfirmasi',
            ];
        }

        return $payloads;
    }
}
