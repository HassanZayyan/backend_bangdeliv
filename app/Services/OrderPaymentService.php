<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPayment;

class OrderPaymentService
{
    public function ensurePendingCodPayment(Order $order): OrderPayment
    {
        $amount = $this->normalizedOrderAmount($order);

        $payment = OrderPayment::query()
            ->where('order_id', $order->id)
            ->first();

        if ($payment instanceof OrderPayment) {
            if (
                strtoupper((string) $payment->payment_method) === 'COD' &&
                strtoupper((string) $payment->payment_status) === 'PENDING'
            ) {
                $payment->update(['amount' => $amount]);
            }

            return $payment->refresh();
        }

        return OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => 'COD',
            'payment_status' => 'PENDING',
            'amount' => $amount,
            'metadata' => [
                'source' => 'ORDER_CREATED',
            ],
        ]);
    }

    public function syncPendingCodAmount(Order $order): void
    {
        OrderPayment::query()
            ->where('order_id', $order->id)
            ->where('payment_method', 'COD')
            ->where('payment_status', 'PENDING')
            ->update([
                'amount' => $this->normalizedOrderAmount($order),
            ]);
    }

    private function normalizedOrderAmount(Order $order): float
    {
        return round((float) $order->total_price, 2);
    }
}
