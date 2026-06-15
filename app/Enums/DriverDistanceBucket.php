<?php

namespace App\Enums;

enum DriverDistanceBucket: string
{
    case Near = 'NEAR';
    case Medium = 'MEDIUM';
    case Far = 'FAR';
    case Unknown = 'UNKNOWN';
}
