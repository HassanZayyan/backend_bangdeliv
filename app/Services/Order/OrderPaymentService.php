<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderPayment;

class OrderPaymentService
{
    public const METHOD_COD = 'COD';

    public const METHOD_TRANSFER = 'TRANSFER';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PAID = 'PAID';

    public function ensurePendingPayment(Order $order, string $method = self::METHOD_COD): OrderPayment
    {
        $method = $this->normalizePaymentMethod($method);
        $amount = $this->normalizedOrderAmount($order);

        $payment = OrderPayment::query()
            ->where('order_id', $order->id)
            ->first();

        if ($payment instanceof OrderPayment) {
            if (strtoupper((string) $payment->payment_status) !== self::STATUS_PAID) {
                $payment->update([
                    'payment_method' => $method,
                    'payment_status' => self::STATUS_PENDING,
                    'amount' => $amount,
                ]);
            }

            return $payment->refresh();
        }

        return OrderPayment::query()->create([
            'order_id' => $order->id,
            'payment_method' => $method,
            'payment_status' => self::STATUS_PENDING,
            'amount' => $amount,
            'metadata' => [
                'source' => 'ORDER_CREATED',
            ],
        ]);
    }

    public function ensurePendingCodPayment(Order $order): OrderPayment
    {
        return $this->ensurePendingPayment($order, self::METHOD_COD);
    }

    public function setPendingTransferPayment(Order $order, float $amount, string $source = 'TRANSFER_REQUIRED'): OrderPayment
    {
        return OrderPayment::query()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'payment_method' => self::METHOD_TRANSFER,
                'payment_status' => self::STATUS_PENDING,
                'amount' => round(max(0.0, $amount), 2),
                'paid_at' => null,
                'metadata' => [
                    'source' => $source,
                ],
            ],
        );
    }

    public function syncPendingCodAmount(Order $order): void
    {
        $this->syncPendingAmount($order);
    }

    public function syncPendingAmount(Order $order): void
    {
        OrderPayment::query()
            ->where('order_id', $order->id)
            ->where('payment_status', self::STATUS_PENDING)
            ->update([
                'amount' => $this->normalizedOrderAmount($order),
            ]);
    }

    public function normalizePaymentMethod(?string $method): string
    {
        $normalized = strtoupper(trim((string) $method));

        return $normalized === self::METHOD_TRANSFER
            ? self::METHOD_TRANSFER
            : self::METHOD_COD;
    }

    private function normalizedOrderAmount(Order $order): float
    {
        return round((float) $order->total_price, 2);
    }
}
