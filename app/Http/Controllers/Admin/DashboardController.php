<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Admin\AdminOrderStatusPresenter;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly AdminOrderStatusPresenter $statuses) {}

    public function __invoke(): View
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $statusCodeToId = OrderStatus::query()->pluck('id', 'code');
        $doneStatusIds = $this->statuses->idsForCodes($statusCodeToId, $this->statuses->doneCodes());
        $activeStatusIds = $this->statuses->idsForCodes($statusCodeToId, $this->statuses->dashboardActiveCodes());
        $cancelledStatusIds = $this->statuses->idsForCodes($statusCodeToId, $this->statuses->cancelledCodes());

        $topRestaurants = Restaurant::query()
            ->withCount('orders')
            ->withSum('orders as orders_total_price_sum', 'total_price')
            ->orderByDesc('orders_count')
            ->limit(5)
            ->get();

        $topDrivers = Driver::query()
            ->with('user:id,name')
            ->withCount('orders')
            ->orderByDesc('orders_count')
            ->limit(5)
            ->get();

        $dailyData = [];
        $dailyLabels = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStart = now()->subDays($i)->startOfDay();
            $dayEnd = now()->subDays($i)->endOfDay();
            $dailyLabels[] = $dayStart->translatedFormat('D');
            $dailyData[] = [
                'revenue' => (float) Order::query()->whereBetween('created_at', [$dayStart, $dayEnd])->sum('total_price'),
                'orders' => Order::query()->whereBetween('created_at', [$dayStart, $dayEnd])->count(),
            ];
        }

        return view('admin.dashboard.index', [
            'gmvMonth' => Order::query()->whereBetween('created_at', [$monthStart, $now])->sum('total_price'),
            'totalOrdersMonth' => Order::query()->whereBetween('created_at', [$monthStart, $now])->count(),
            'cancelledOrdersMonth' => Order::query()
                ->whereBetween('created_at', [$monthStart, $now])
                ->whereIn('status_id', $cancelledStatusIds)
                ->count(),
            'newUsersMonth' => User::query()->whereBetween('created_at', [$monthStart, $now])->count(),
            'statusDone' => Order::query()->whereIn('status_id', $doneStatusIds)->count(),
            'statusActive' => Order::query()->whereIn('status_id', $activeStatusIds)->count(),
            'statusCancelled' => Order::query()->whereIn('status_id', $cancelledStatusIds)->count(),
            'topRestaurants' => $topRestaurants,
            'topDrivers' => $topDrivers,
            'dailyData' => $dailyData,
            'dailyLabels' => $dailyLabels,
            'maxRestOrders' => max((int) ($topRestaurants->max('orders_count') ?? 1), 1),
        ]);
    }
}
