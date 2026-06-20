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
                'label' => 'Konfirmasi',
                'message' => 'Konfirmasi',
            ];
        }

        return $payloads;
    }
}
