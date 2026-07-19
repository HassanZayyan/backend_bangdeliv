<?php

namespace App\Services\Admin;

use App\Models\Driver;
use App\Models\Order;
use App\Models\ServiceType;
use Illuminate\Support\Collection;

class AdminNotificationService
{
    public function __construct(
        private readonly AdminPaymentProofStatusService $proofStatuses,
        private readonly AdminServiceTypePresenter $serviceTypes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function layoutData(): array
    {
        $summary = $this->summary();

        return [
            'summary' => $summary,
            'navigation' => $this->navigationCounts($summary),
            'order_service_filters' => $this->serviceTypes->filters(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $pendingDriverCount = Driver::query()
            ->where('registration_status', 'pending')
            ->count();
        $pendingProofs = $this->proofStatuses->pendingPaymentProofs();
        $pendingProofCount = $pendingProofs->count();
        $items = $this->pendingItems($pendingProofs);

        return [
            'pending_drivers' => $pendingDriverCount,
            'pending_payment_proofs' => $pendingProofCount,
            'total_pending' => $pendingDriverCount + $pendingProofCount,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function navigationCounts(array $summary = []): array
    {
        $serviceTypeIdMap = ServiceType::query()->pluck('id', 'code');
        $counts = [
            'orders' => Order::query()->count(),
            'drivers' => Driver::query()->count(),
            'verification' => (int) ($summary['pending_drivers'] ?? Driver::query()->where('registration_status', 'pending')->count()),
        ];

        foreach ($this->serviceTypes->filters() as $key => $filter) {
            $code = $filter['code'] ?? null;
            if ($code === null) {
                continue;
            }

            $counts[$key] = isset($serviceTypeIdMap[$code])
                ? Order::query()->where('service_type_id', $serviceTypeIdMap[$code])->count()
                : 0;
        }

        return $counts;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function pendingItems(Collection $pendingProofs): array
    {
        $items = [];

        $pendingDrivers = Driver::query()
            ->with('user:id,name,phone')
            ->where('registration_status', 'pending')
            ->latest('updated_at')
            ->limit(4)
            ->get();

        foreach ($pendingDrivers as $driver) {
            $items[] = [
                'type' => 'driver',
                'label' => 'Driver Menunggu Verifikasi',
                'title' => $driver->user?->name ?? 'Driver baru',
                'description' => $driver->vehicle_plate
                    ? 'Dokumen menunggu verifikasi - '.$driver->vehicle_plate
                    : 'Dokumen menunggu verifikasi',
                'url' => route('admin.verification.show', ['driverId' => $driver->id]),
                'icon' => 'bx-check-shield',
            ];
        }

        foreach ($pendingProofs->take(4) as $proof) {
            $items[] = [
                'type' => 'payment',
                'label' => 'Bukti QRIS Menunggu Verifikasi',
                'title' => '#'.($proof->order?->order_number ?? $proof->order_id),
                'description' => ($proof->order?->user?->name ?? 'Pelanggan').' - Rp '.number_format((float) ($proof->order?->total_price ?? 0), 0, ',', '.'),
                'url' => $proof->order
                    ? route('admin.orders.show', ['order' => $proof->order_id, 'focus' => 'payment-proof'])
                    : route('admin.orders.index'),
                'icon' => 'bx-receipt',
            ];
        }

        return array_slice($items, 0, 6);
    }
}
