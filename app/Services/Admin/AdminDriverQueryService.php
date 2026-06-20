<?php

namespace App\Services\Admin;

use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminDriverQueryService
{
    /**
     * @return array<string, mixed>
     */
    public function indexData(Request $request): array
    {
        $statusFilters = $this->statusFilters();
        $selectedStatus = $this->normalizeStatus((string) $request->query('status', 'semua'));
        $search = trim((string) $request->query('q', ''));

        $query = Driver::query()
            ->with('user')
            ->withCount('orders')
            ->latest('created_at');

        $this->applySearch($query, $search);
        $this->applyStatusFilter($query, $selectedStatus);

        $drivers = $query
            ->paginate(AdminPagination::PER_PAGE)
            ->withQueryString();

        $drivers->getCollection()->each(function (Driver $driver): void {
            $driver->setAttribute('admin_status', $this->statusConfig($driver));
            $driver->setAttribute('admin_initial', strtoupper(substr($driver->user?->name ?? 'D', 0, 2)));
            $driver->setAttribute('admin_avatar_url', $this->avatarUrl((string) ($driver->user?->avatar ?? '')));
        });

        return [
            'drivers' => $drivers,
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
            'aktif' => ['label' => 'Aktif (Online)', 'badge_class' => 'badge-success'],
            'offline' => ['label' => 'Offline', 'badge_class' => 'badge-info'],
            'suspended' => ['label' => 'Suspended', 'badge_class' => 'badge-danger'],
            'pending' => ['label' => 'Pending Verifikasi', 'badge_class' => 'badge-warning'],
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
            return ['label' => 'Suspended', 'class' => 'badge-danger'];
        }

        if ($driver->registration_status === 'pending') {
            return ['label' => 'Pending Verifikasi', 'class' => 'badge-warning'];
        }

        if ($driver->status === 'available') {
            return ['label' => 'Aktif (Online)', 'class' => 'badge-success'];
        }

        return ['label' => 'Offline', 'class' => 'badge-info'];
    }

    private function avatarUrl(string $avatarPath): ?string
    {
        $avatarPath = trim($avatarPath);

        return $avatarPath !== '' && Storage::disk('public')->exists($avatarPath)
            ? asset('storage/'.$avatarPath)
            : null;
    }
}
