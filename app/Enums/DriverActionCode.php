<?php

namespace App\Enums;

enum DriverActionCode: string
{
    case ArrivePickup = 'ARRIVE_PICKUP';
    case BoardPassenger = 'BOARD_PASSENGER';
    case ConfirmPickedUp = 'CONFIRM_PICKED_UP';
    case StartDelivery = 'START_DELIVERY';
    case ArriveDropoff = 'ARRIVE_DROPOFF';
    case ConfirmDelivered = 'CONFIRM_DELIVERED';
    case CompleteOrder = 'COMPLETE_ORDER';
    case CollectCod = 'COLLECT_COD';
    case CancelWithFee = 'CANCEL_WITH_FEE';
}
