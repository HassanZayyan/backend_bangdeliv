<?php

namespace App\Services\Admin;

use App\Enums\OrderStatusCode;
use Illuminate\Support\Str;

class AdminOrderStatusPresenter
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function filters(): array
    {
        return [
            'all' => ['label' => 'Semua', 'codes' => null, 'badge_class' => null],
            'pending' => ['label' => 'Menunggu Driver', 'codes' => $this->pendingCodes(), 'badge_class' => 'badge-warning'],
            'active' => ['label' => 'Berjalan', 'codes' => $this->runningCodes(), 'badge_class' => 'badge-info'],
            'done' => ['label' => 'Selesai', 'codes' => $this->doneCodes(), 'badge_class' => 'badge-success'],
            'cancelled' => ['label' => 'Batal', 'codes' => $this->cancelledCodes(), 'badge_class' => 'badge-danger'],
        ];
    }

    public function normalizeFilter(?string $filter): string
    {
        $filter = trim((string) $filter);

        return array_key_exists($filter, $this->filters()) ? $filter : 'all';
    }

    /**
     * @return array<int, string>
     */
    public function pendingCodes(): array
    {
        return [OrderStatusCode::Pending->value];
    }

    /**
     * @return array<int, string>
     */
    public function runningCodes(): array
    {
        return [
            OrderStatusCode::DriverAssigned->value,
            OrderStatusCode::ArrivedMerchant->value,
            OrderStatusCode::ArrivedPickup->value,
            OrderStatusCode::PickedUp->value,
            OrderStatusCode::OnTheWay->value,
            OrderStatusCode::ArrivedDropoff->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function dashboardActiveCodes(): array
    {
        return [
            ...$this->pendingCodes(),
            ...$this->runningCodes(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function doneCodes(): array
    {
        return [
            OrderStatusCode::Delivered->value,
            OrderStatusCode::Completed->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function cancelledCodes(): array
    {
        return [
            OrderStatusCode::Cancelled->value,
            OrderStatusCode::CancelledWithFee->value,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $statusCodeToId
     * @param  array<int, string>  $codes
     * @return array<int, int>
     */
    public function idsForCodes($statusCodeToId, array $codes): array
    {
        return collect($codes)
            ->map(fn (string $code): ?int => isset($statusCodeToId[$code]) ? (int) $statusCodeToId[$code] : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $statusCodeToId
     * @return array<string, array<int, int>>
     */
    public function idsByFilter($statusCodeToId): array
    {
        $ids = [];

        foreach ($this->filters() as $key => $config) {
            $ids[$key] = $this->idsForCodes($statusCodeToId, $config['codes'] ?? []);
        }

        return $ids;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function badgeMap(): array
    {
        return [
            OrderStatusCode::Pending->value => ['label' => 'Menunggu Driver', 'class' => 'badge-warning'],
            OrderStatusCode::DriverAssigned->value => ['label' => 'Driver Ditugaskan', 'class' => 'badge-info'],
            OrderStatusCode::ArrivedMerchant->value => ['label' => 'Tiba Merchant', 'class' => 'badge-info'],
            OrderStatusCode::ArrivedPickup->value => ['label' => 'Tiba Pickup', 'class' => 'badge-info'],
            OrderStatusCode::PickedUp->value => ['label' => 'Pickup', 'class' => 'badge-info'],
            OrderStatusCode::OnTheWay->value => ['label' => 'Diantar', 'class' => 'badge-info'],
            OrderStatusCode::ArrivedDropoff->value => ['label' => 'Tiba Tujuan', 'class' => 'badge-info'],
            OrderStatusCode::Delivered->value => ['label' => 'Terkirim', 'class' => 'badge-success'],
            OrderStatusCode::Completed->value => ['label' => 'Selesai', 'class' => 'badge-success'],
            OrderStatusCode::Cancelled->value => ['label' => 'Dibatalkan', 'class' => 'badge-danger'],
            OrderStatusCode::CancelledWithFee->value => ['label' => 'Batal Dengan Biaya', 'class' => 'badge-danger'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function badgeConfig(?string $statusCode): array
    {
        $statusCode = OrderStatusCode::normalize($statusCode);

        return $this->badgeMap()[$statusCode] ?? [
            'label' => Str::headline(strtolower($statusCode)),
            'class' => 'badge-info',
        ];
    }
}
