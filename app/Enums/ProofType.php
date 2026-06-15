<?php

namespace App\Enums;

enum ProofType: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
    case Receipt = 'receipt';
    case StoreClosed = 'store_closed';
    case PaymentTransfer = 'payment_transfer';

    public static function fromEvidenceType(string $evidenceType): string
    {
        return match (strtoupper($evidenceType)) {
            'PICKUP_PHOTO' => self::Pickup->value,
            'DELIVERY_PHOTO', 'COURIER_DELIVERY_PHOTO', 'COURIER_RECEIVER_PHOTO' => self::Delivery->value,
            'SHOPPING_RECEIPT' => self::Receipt->value,
            'STORE_CLOSED_PHOTO' => self::StoreClosed->value,
            'PAYMENT_TRANSFER_PHOTO' => self::PaymentTransfer->value,
            default => strtolower($evidenceType),
        };
    }
}
