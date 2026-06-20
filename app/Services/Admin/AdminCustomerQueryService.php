<?php

namespace App\Services\Admin;

use App\Models\OrderStatus;
use App\Models\User;
use Illuminate\Http\Request;

class AdminCustomerQueryService
{
    public function __construct(private readonly AdminOrderStatusPresenter $statuses) {}

    /**
     * @return array<string, mixed>
     */
    public function indexData(Request $request): array
    {
        $statusFilters = $this->statusFilters();
        $selectedStatus = $this->normalizeStatus((string) $request->query('status', 'semua'));
        $search = trim((string) $request->query('q', ''));
        $statusCodeToId = OrderStatus::query()->pluck('id', 'code');
        $successStatusIds = $this->statuses->idsForCodes($statusCodeToId, $this->statuses->doneCodes());
        $cancelledStatusIds = $this->statuses->idsForCodes($statusCodeToId, $this->statuses->cancelledCodes());

        $query = User::query()
            ->where('role', 'customer')
            ->withCount([
                'orders as success_orders_count' => fn ($query) => $query->whereIn('status_id', $successStatusIds),
                'orders as cancelled_orders_count' => fn ($query) => $query->whereIn('status_id', $cancelledStatusIds),
            ])
            ->latest('created_at');

        $this->applySearch($query, $search);
        $this->applyStatusFilter($query, $selectedStatus);

        $customers = $query
            ->paginate(AdminPagination::PER_PAGE)
            ->withQueryString();

        $customers->getCollection()->each(function (User $customer): void {
            $customer->setAttribute('admin_initial', strtoupper(substr($customer->name, 0, 2)));
            $customer->setAttribute('admin_status', $this->statusConfig($customer));
        });

        return [
            'customers' => $customers,
            'statusFilters' => $statusFilters,
            'statusCounts' => $this->statusCounts(),
            'selectedStatus' => $selectedStatus,
            'search' => $search,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function statusFilters(): array
    {
        return [
            'semua' => ['label' => 'Semua', 'badge_class' => null],
            'aktif' => ['label' => 'Aktif', 'badge_class' => 'badge-success'],
            'baru' => ['label' => 'Pelanggan Baru', 'badge_class' => 'badge-info'],
            'blacklisted' => ['label' => 'Blacklisted', 'badge_class' => 'badge-danger'],
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return array_key_exists($status, $this->statusFilters()) ? $status : 'semua';
    }

    private function applySearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function ($query) use ($search): void {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%");
        });
    }

    private function applyStatusFilter($query, string $status): void
    {
        match ($status) {
            'aktif' => $query->where('is_active', true)->where('is_blacklisted', false),
            'baru' => $query->whereDate('created_at', now()->toDateString()),
            'blacklisted' => $query->where('is_blacklisted', true),
            default => null,
        };
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        return [
            'aktif' => User::query()->where('role', 'customer')->where('is_active', true)->where('is_blacklisted', false)->count(),
            'baru' => User::query()->where('role', 'customer')->whereDate('created_at', now()->toDateString())->count(),
            'blacklisted' => User::query()->where('role', 'customer')->where('is_blacklisted', true)->count(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusConfig(User $customer): array
    {
        if ((bool) $customer->is_blacklisted) {
            return ['label' => 'Blacklisted', 'class' => 'badge-danger'];
        }

        if ($customer->created_at?->isToday()) {
            return ['label' => 'Pelanggan Baru', 'class' => 'badge-info'];
        }

        return ['label' => 'Aktif', 'class' => 'badge-success'];
    }
}
