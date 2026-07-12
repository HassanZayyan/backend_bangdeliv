<?php

namespace App\Services\Shopping;

use App\Models\Order;
use App\Models\OrderLog;
use App\Services\Order\DeliveryFeeNegotiationService;

class ShoppingDeliveryFeeLockResolver
{
    /**
     * @return array{is_locked: bool, amount: float|null, source: string|null, event_id: int|null}
     */
    public function resolve(Order $order): array
    {
        $latest = OrderLog::query()
            ->where('order_id', $order->id)
            ->where('event_type', DeliveryFeeNegotiationService::EVENT_TYPE)
            ->whereIn('trigger_type', [
                DeliveryFeeNegotiationService::CUSTOMER_FEE_APPROVED,
                DeliveryFeeNegotiationService::DRIVER_COUNTER_APPROVED,
                DeliveryFeeNegotiationService::DRIVER_FEE_APPROVED_BY_DRIVER_BYPASS,
            ])
            ->latest('id')
            ->first();

        if ($latest instanceof OrderLog) {
            $trigger = strtoupper((string) $latest->trigger_type);
            if (in_array($trigger, [
                DeliveryFeeNegotiationService::CUSTOMER_FEE_APPROVED,
                DeliveryFeeNegotiationService::DRIVER_COUNTER_APPROVED,
                DeliveryFeeNegotiationService::DRIVER_FEE_APPROVED_BY_DRIVER_BYPASS,
            ], true)) {
                $metadata = is_array($latest->metadata) ? $latest->metadata : [];
                $amount = $this->amountFromMetadata($metadata) ?? $this->positiveAmount($order->delivery_fee);

                return [
                    'is_locked' => $amount !== null,
                    'amount' => $amount,
                    'source' => $trigger,
                    'event_id' => (int) $latest->id,
                ];
            }
        }

        return [
            'is_locked' => false,
            'amount' => null,
            'source' => null,
            'event_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function amountFromMetadata(array $metadata): ?float
    {
        foreach (['approved_amount', 'final_amount', 'counter_amount', 'quoted_amount', 'amount'] as $key) {
            $amount = $this->positiveAmount($metadata[$key] ?? null);
            if ($amount !== null) {
                return $amount;
            }
        }

        return null;
    }

    private function positiveAmount(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $amount = round((float) $value, 2);

        return $amount > 0 ? $amount : null;
    }
}
