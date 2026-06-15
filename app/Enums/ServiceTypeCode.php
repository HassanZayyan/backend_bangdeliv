<?php

namespace App\Enums;

enum ServiceTypeCode: string
{
    case Ride = 'RIDE';
    case Courier = 'COURIER';
    case Shopping = 'SHOPPING';
    case Unknown = 'UNKNOWN';

    public static function normalize(?string $value): string
    {
        $normalized = strtoupper(trim((string) $value));

        return match ($normalized) {
            self::Ride->value, 'ANTAR_JEMPUT', 'ANTAR_JEMPUT_ORANG' => self::Ride->value,
            self::Courier->value, 'KURIR', 'ANTAR_BARANG' => self::Courier->value,
            self::Shopping->value, 'NITIP', 'TITIP_BELANJA' => self::Shopping->value,
            '' => self::Unknown->value,
            default => $normalized,
        };
    }
}
