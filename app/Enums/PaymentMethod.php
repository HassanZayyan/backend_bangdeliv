<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cod = 'COD';
    case Transfer = 'TRANSFER';

    public static function normalize(?string $value): string
    {
        return strtoupper(trim((string) $value)) === self::Transfer->value
            ? self::Transfer->value
            : self::Cod->value;
    }
}
