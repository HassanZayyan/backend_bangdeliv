<?php

namespace App\Enums;

enum OrderStatusCode: string
{
    case Pending = 'PENDING';
    case DriverAssigned = 'DRIVER_ASSIGNED';
    case ArrivedMerchant = 'ARRIVED_MERCHANT';
    case ArrivedPickup = 'ARRIVED_PICKUP';
    case PickedUp = 'PICKED_UP';
    case OnTheWay = 'ON_THE_WAY';
    case ArrivedDropoff = 'ARRIVED_DROPOFF';
    case Delivered = 'DELIVERED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case CancelledWithFee = 'CANCELLED_WITH_FEE';

    public static function normalize(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }

    /**
     * @return array<int, string>
     */
    public static function runningDriverStatuses(): array
    {
        return [
            self::DriverAssigned->value,
            self::ArrivedMerchant->value,
            self::ArrivedPickup->value,
            self::PickedUp->value,
            self::OnTheWay->value,
            self::ArrivedDropoff->value,
            self::Delivered->value,
            self::CancelledWithFee->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function driverLocationTrackableStatuses(): array
    {
        return [
            self::DriverAssigned->value,
            self::ArrivedMerchant->value,
            self::ArrivedPickup->value,
            self::PickedUp->value,
            self::OnTheWay->value,
        ];
    }
}
