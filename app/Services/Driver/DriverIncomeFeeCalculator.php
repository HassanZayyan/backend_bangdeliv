<?php

namespace App\Services\Driver;

use App\Enums\ServiceTypeCode;
use App\Models\Order;
use App\Services\Pricing\ShoppingPricingService;

class DriverIncomeFeeCalculator
{
    public function __construct(
        private readonly ShoppingPricingService $shoppingPricingService,
    ) {}

    public function defaultAdminFeePercent(): float
    {
        return $this->normalizePercent(config('bangdeliv.driver_admin_fee_percent', 10));
    }

    public function normalizePercent(mixed $percent): float
    {
        $value = is_numeric($percent) ? (float) $percent : $this->defaultPercentFallback();

        return max(0.0, min(100.0, round($value, 2)));
    }

    /**
     * @return array{gross_income: float, admin_fee_percent: float, admin_fee: float, net_income: float}
     */
    public function breakdown(float|int $grossIncome, mixed $percent = null): array
    {
        $gross = max(0.0, round((float) $grossIncome, 2));
        $adminFeePercent = $percent === null
            ? $this->defaultAdminFeePercent()
            : $this->normalizePercent($percent);
        $adminFee = round($gross * $adminFeePercent / 100);

        return [
            'gross_income' => $gross,
            'admin_fee_percent' => $adminFeePercent,
            'admin_fee' => $adminFee,
            'net_income' => max(0.0, round($gross - $adminFee, 2)),
        ];
    }

    public function grossIncomeForOrder(Order $order): float
    {
        $order->loadMissing(['serviceType', 'statusRef']);
        $serviceCode = ServiceTypeCode::normalize((string) ($order->serviceType?->code ?? ''));
        $statusCode = strtoupper((string) ($order->statusRef?->code ?? ''));

        if ($serviceCode === ServiceTypeCode::Shopping->value && $statusCode === 'CANCELLED_WITH_FEE') {
            $driverFee = $this->shoppingPricingService->cancellationDriverFeeAmount($order);
            if ($driverFee > 0) {
                return round($driverFee, 2);
            }
        }

        $deliveryFee = round((float) $order->delivery_fee, 2);
        if ($deliveryFee > 0) {
            return $deliveryFee;
        }

        $paidAmount = round((float) ($order->paid_amount ?? 0), 2);
        if ($paidAmount > 0) {
            return $paidAmount;
        }

        return round((float) $order->total_price, 2);
    }

    private function defaultPercentFallback(): float
    {
        $configured = config('bangdeliv.driver_admin_fee_percent', 10);

        return is_numeric($configured) ? (float) $configured : 10.0;
    }
}
