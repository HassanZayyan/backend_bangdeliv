<?php

namespace App\Services\Order;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderNumberGenerator
{
    private const ORDER_NUMBER_PATTERN = '/^BD-\d{6}-(\d+)$/';

    public function next(): string
    {
        $timezone = (string) config('app.timezone', 'Asia/Jakarta');
        $now = now($timezone);
        $dateSegment = $now->format('dmy');
        $prefix = 'BD-'.$dateSegment.'-';

        return DB::transaction(function () use ($prefix): string {
            $sequence = $this->lastSequenceForPrefix($prefix) + 1;

            do {
                $candidate = $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
                $sequence++;
            } while (Order::query()->where('order_number', $candidate)->exists());

            return $candidate;
        });
    }

    private function lastSequenceForPrefix(string $prefix): int
    {
        return Order::query()
            ->where('order_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('order_number')
            ->reduce(function (int $max, string $orderNumber): int {
                if (preg_match(self::ORDER_NUMBER_PATTERN, $orderNumber, $matches) !== 1) {
                    return $max;
                }

                return max($max, (int) $matches[1]);
            }, 0);
    }
}
