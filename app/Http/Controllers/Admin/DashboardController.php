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

        // Pendapatan platform = ONGKIR (delivery_fee) order SELESAI saja. Harga
        // makanan (bagian dari total_price) adalah pass-through milik Toko/Resto,
        // BUKAN pendapatan BangDeliv, sehingga tidak dihitung di sini.
        $revenueTrends = [
            'daily' => $this->trendSeries('day', 7, 'D', $doneStatusIds),
            'weekly' => $this->trendSeries('week', 8, 'd M', $doneStatusIds),
            'monthly' => $this->trendSeries('month', 6, 'M Y', $doneStatusIds),
        ];

        return view('admin.dashboard.index', [
            'revenueMonth' => (float) Order::query()
                ->whereBetween('created_at', [$monthStart, $now])
                ->whereIn('status_id', $doneStatusIds)
                ->sum('delivery_fee'),
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
            'revenueTrends' => $revenueTrends,
            'maxRestOrders' => max((int) ($topRestaurants->max('orders_count') ?? 1), 1),
        ]);
    }

    /**
     * Satu seri tren (label + pendapatan ongkir + jumlah pesanan) untuk sejumlah
     * periode ke belakang. Pendapatan hanya dari order SELESAI (delivery_fee).
     *
     * @param  array<int, int>  $doneStatusIds
     * @return array{labels: array<int, string>, revenue: array<int, float>, orders: array<int, int>}
     */
    private function trendSeries(string $unit, int $count, string $labelFormat, array $doneStatusIds): array
    {
        $labels = [];
        $revenue = [];
        $orders = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            [$start, $end] = $this->periodBounds($unit, $i);
            $labels[] = $start->translatedFormat($labelFormat);
            $revenue[] = (float) Order::query()
                ->whereBetween('created_at', [$start, $end])
                ->whereIn('status_id', $doneStatusIds)
                ->sum('delivery_fee');
            $orders[] = Order::query()
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        return ['labels' => $labels, 'revenue' => $revenue, 'orders' => $orders];
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    private function periodBounds(string $unit, int $offset): array
    {
        return match ($unit) {
            'week' => [now()->subWeeks($offset)->startOfWeek(), now()->subWeeks($offset)->endOfWeek()],
            'month' => [now()->subMonths($offset)->startOfMonth(), now()->subMonths($offset)->endOfMonth()],
            default => [now()->subDays($offset)->startOfDay(), now()->subDays($offset)->endOfDay()],
        };
    }
}
