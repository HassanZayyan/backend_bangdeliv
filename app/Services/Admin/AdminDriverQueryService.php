<?php

namespace App\Services\Admin;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Driver\DriverIncomeFeeCalculator;
use Illuminate\Http\Request;

class AdminDriverQueryService
{
    public function __construct(
        private readonly DriverIncomeFeeCalculator $driverIncomeFeeCalculator,
        private readonly AdminMediaUrlResolver $media,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function indexData(Request $request): array
    {
        $statusFilters = $this->statusFilters();
        $selectedStatus = $this->normalizeStatus((string) $request->query('status', 'semua'));
        $selectedAdminFeePercent = $this->driverIncomeFeeCalculator->defaultAdminFeePercent();
        $search = trim((string) $request->query('q', ''));

        $query = Driver::query()
            ->with('user')
            ->withCount('orders')
            // Tiebreaker unik agar pagination deterministik (created_at bisa seri).
            ->latest('created_at')
            ->orderByDesc('id');

        $this->applySearch($query, $search);
        $this->applyStatusFilter($query, $selectedStatus);

        $drivers = $query
            ->paginate(AdminPagination::PER_PAGE)
            ->withQueryString();

        $driverCollection = $drivers->getCollection();
        $driverCollection->load([
            'orders' => function ($query): void {
                $query
                    ->with([
                        'serviceType:id,code',
                        'statusRef:id,code,display_name',
                        'orderLocations:id,order_id,location_role,failed_attempt_count',
                    ])
                    ->whereIn('status_id', $this->incomeStatusIds());
            },
        ]);

        $driverCollection->each(function (Driver $driver) use ($selectedAdminFeePercent): void {
            $grossIncome = $driver->orders
                ->sum(fn (Order $order): float => $this->driverIncomeFeeCalculator->grossIncomeForOrder($order));
            $breakdown = $this->driverIncomeFeeCalculator->breakdown($grossIncome, $selectedAdminFeePercent);

            $driver->setAttribute('admin_status', $this->statusConfig($driver));
            $driver->setAttribute('admin_initial', strtoupper(substr($driver->user?->name ?? 'D', 0, 2)));
            $driver->setAttribute('admin_avatar_url', $this->media->publicUrl($driver->user?->avatar));
            $driver->setAttribute('admin_income_summary', [
                ...$breakdown,
                'order_count' => $driver->orders->count(),
            ]);
        });

        return [
            'drivers' => $drivers,
            'statusFilters' => $statusFilters,
            'statusCounts' => $this->statusCounts(),
            'selectedStatus' => $selectedStatus,
            'selectedAdminFeePercent' => $selectedAdminFeePercent,
            'search' => $search,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function showData(Driver $driver, Request $request): array
    {
        $selectedIncomeMode = $this->normalizeIncomeMode((string) $request->query('income_mode', 'system'));
        $selectedAdminFeePercent = $this->selectedAdminFeePercent($request, $selectedIncomeMode);

        $driver->load('user');
        $driver->setAttribute('admin_status', $this->statusConfig($driver));
        $driver->setAttribute('admin_initial', strtoupper(substr($driver->user?->name ?? 'D', 0, 2)));
        $driver->setAttribute('admin_avatar_url', $this->media->publicUrl($driver->user?->avatar));

        $incomeOrders = $driver->orders()
            ->with([
                'user:id,name',
                'serviceType:id,code,display_name',
                'statusRef:id,code,display_name',
                'orderLocations:id,order_id,location_role,failed_attempt_count',
            ])
            ->whereIn('status_id', $this->incomeStatusIds())
            ->latest('id')
            ->limit(100)
            ->get();

        $incomeRows = $incomeOrders
            ->map(function (Order $order) use ($selectedAdminFeePercent): array {
                $grossIncome = $this->driverIncomeFeeCalculator->grossIncomeForOrder($order);
                $breakdown = $this->driverIncomeFeeCalculator->breakdown($grossIncome, $selectedAdminFeePercent);

                return [
                    'order_number' => $order->order_number ?: (string) $order->id,
                    'customer_name' => $order->user?->name ?? '-',
                    'service_label' => $order->serviceType?->display_name ?? $order->serviceType?->code ?? '-',
                    'status_label' => $order->statusRef?->display_name ?? '-',
                    'date' => $order->delivered_at ?? $order->updated_at ?? $order->created_at,
                    ...$breakdown,
                ];
            })
            ->values();

        $grossTotal = $incomeRows->sum(fn (array $row): float => (float) $row['gross_income']);
        $incomeSummary = [
            ...$this->driverIncomeFeeCalculator->breakdown($grossTotal, $selectedAdminFeePercent),
            'order_count' => $incomeRows->count(),
        ];

        return [
            'driver' => $driver,
            'incomeRows' => $incomeRows,
            'incomeSummary' => $incomeSummary,
            'selectedIncomeMode' => $selectedIncomeMode,
            'selectedAdminFeePercent' => $selectedAdminFeePercent,
            'systemAdminFeePercent' => $this->driverIncomeFeeCalculator->defaultAdminFeePercent(),
            'backUrl' => route('admin.drivers.index'),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function statusFilters(): array
    {
        return [
            // Kunci array dipakai sebagai nilai query string ?status=..., jangan diubah.
            'semua' => ['label' => 'Semua', 'badge_class' => null],
            'aktif' => ['label' => 'Aktif (Online)', 'badge_class' => 'badge-success'],
            'offline' => ['label' => 'Tidak Aktif', 'badge_class' => 'badge-info'],
            'suspended' => ['label' => 'Ditangguhkan', 'badge_class' => 'badge-danger'],
            'pending' => ['label' => 'Menunggu Verifikasi', 'badge_class' => 'badge-warning'],
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return array_key_exists($status, $this->statusFilters()) ? $status : 'semua';
    }

    private function normalizeIncomeMode(string $mode): string
    {
        return in_array($mode, ['system', 'manual'], true) ? $mode : 'system';
    }

    private function selectedAdminFeePercent(Request $request, string $incomeMode): float
    {
        if ($incomeMode === 'manual') {
            return $this->driverIncomeFeeCalculator->normalizePercent(
                $request->query('admin_fee_percent', $this->driverIncomeFeeCalculator->defaultAdminFeePercent())
            );
        }

        return $this->driverIncomeFeeCalculator->defaultAdminFeePercent();
    }

    private function applySearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function ($query) use ($search): void {
            $query->where('vehicle_plate', 'like', "%{$search}%")
                ->orWhereHas('user', function ($userQuery) use ($search): void {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
        });
    }

    private function applyStatusFilter($query, string $status): void
    {
        match ($status) {
            'aktif' => $query->where('status', 'available')->where('registration_status', 'active'),
            'offline' => $query->where('status', 'offline'),
            'suspended' => $query->where('registration_status', 'suspended'),
            'pending' => $query->where('registration_status', 'pending'),
            default => null,
        };
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        return [
            'aktif' => Driver::query()->where('status', 'available')->where('registration_status', 'active')->count(),
            'offline' => Driver::query()->where('status', 'offline')->count(),
            'suspended' => Driver::query()->where('registration_status', 'suspended')->count(),
            'pending' => Driver::query()->where('registration_status', 'pending')->count(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusConfig(Driver $driver): array
    {
        if ($driver->registration_status === 'suspended') {
            return ['label' => 'Ditangguhkan', 'class' => 'badge-danger'];
        }

        if ($driver->registration_status === 'pending') {
            return ['label' => 'Menunggu Verifikasi', 'class' => 'badge-warning'];
        }

        if ($driver->status === 'available') {
            return ['label' => 'Aktif (Online)', 'class' => 'badge-success'];
        }

        return ['label' => 'Tidak Aktif', 'class' => 'badge-info'];
    }

    /**
     * @return array<int, int>
     */
    private function incomeStatusIds(): array
    {
        return OrderStatus::query()
            ->whereIn('code', ['COMPLETED', 'CANCELLED'])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
