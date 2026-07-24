@extends('layouts.admin')

@section('title', 'Dashboard - Admin Pelanggan 15')
@section('page-title', 'Dashboard Utama')

@inject('driverStatuses', 'App\Services\Admin\AdminDriverStatusPresenter')

@section('content')
{{-- KPI Cards Row --}}
<div class="stat-cards-wrapper">
    <x-stat-card title="Pendapatan Ongkir (Bulan Ini)" value="Rp {{ number_format((float) $revenueMonth, 0, ',', '.') }}" icon="bx-money" color="primary" change="Data terkini" change-type="positive" />
    <x-stat-card title="Total Pesanan (Bulan Ini)" value="{{ number_format($totalOrdersMonth, 0, ',', '.') }}" icon="bx-receipt" color="info" change="Data terkini" change-type="positive" />
    <x-stat-card title="Pesanan Batal (Bulan Ini)" value="{{ number_format($cancelledOrdersMonth, 0, ',', '.') }}" icon="bx-x-circle" color="danger" change="Data terkini" change-type="negative" />
    <x-stat-card title="Pengguna Baru (Bulan Ini)" value="{{ number_format($newUsersMonth, 0, ',', '.') }}" icon="bx-user-plus" color="success" change="Data terkini" change-type="positive" />
</div>

{{-- Charts Row --}}
<div class="dashboard-grid dashboard-chart-grid">
    <div class="panel">
        <div class="panel-header dashboard-panel-header">
            <div class="panel-title" id="revenueChartTitle">Tren Pendapatan Ongkir & Pesanan (7 Hari Terakhir)</div>
            <div class="dashboard-chart-controls" aria-label="Rentang grafik pendapatan">
                <button class="tab-btn active" type="button" data-period="daily">Harian</button>
                <button class="tab-btn" type="button" data-period="weekly">Mingguan</button>
                <button class="tab-btn" type="button" data-period="monthly">Bulanan</button>
            </div>
        </div>
        <div class="dashboard-chart-frame dashboard-line-chart-frame">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div class="panel-title">Distribusi Status Pesanan</div>
        </div>
        <div class="dashboard-status-panel">
            <div class="dashboard-donut-frame">
                <canvas id="orderStatusChart"></canvas>
            </div>
            <div class="dashboard-status-legend">
                @php
                    $totalStatus = max($statusDone + $statusActive + $statusCancelled, 1);
                    $statusItems = [
                        ['label' => 'Selesai', 'color' => 'success', 'count' => $statusDone],
                        ['label' => 'Sedang Berjalan', 'color' => 'info', 'count' => $statusActive],
                        ['label' => 'Dibatalkan', 'color' => 'danger', 'count' => $statusCancelled],
                    ];
                @endphp
                @foreach($statusItems as $item)
                <div class="dashboard-status-row">
                    <div class="dashboard-status-label">
                        <span class="dashboard-status-dot" style="background: var(--color-{{ $item['color'] }});"></span>
                        <span class="dashboard-status-text">{{ $item['label'] }}</span>
                    </div>
                    <span class="dashboard-status-value">{{ $item['count'] }} ({{ number_format(($item['count'] / $totalStatus) * 100, 1) }}%)</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

{{-- Top Performance Row --}}
<div class="dashboard-grid">
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title dashboard-title-with-icon"><i class='bx bx-trophy text-warning' aria-hidden="true"></i> Top 5 Restoran Terlaris</div>
            <span class="dashboard-panel-meta">Bulan ini</span>
        </div>
        <div class="dashboard-performance-list">
            @foreach($topRestaurants as $i => $resto)
            @php
                $restoRevenue = (float) ($resto->orders_total_price_sum ?? 0);
                $pct = (int) round(($resto->orders_count / $maxRestOrders) * 100);
            @endphp
            <div class="dashboard-performance-item">
                <span class="dashboard-rank {{ $i === 0 ? 'is-top' : '' }}">{{ $i+1 }}</span>
                <div class="dashboard-performance-content">
                    <div class="dashboard-performance-main">
                        <span class="dashboard-performance-name">{{ $resto->name }}</span>
                        <span class="dashboard-performance-metric">Rp {{ number_format((float) $restoRevenue, 0, ',', '.') }}</span>
                    </div>
                    <div class="dashboard-performance-sub">
                        <div class="dashboard-progress-track">
                            <div class="dashboard-progress-fill" style="width:{{ $pct }}%; opacity:{{ 1-($i*0.12) }};"></div>
                        </div>
                        <span class="dashboard-performance-count">{{ $resto->orders_count }} pesanan</span>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div class="panel-title dashboard-title-with-icon"><i class='bx bx-cycling text-success' aria-hidden="true"></i> Top 5 Driver Paling Aktif</div>
            <span class="dashboard-panel-meta">Bulan ini</span>
        </div>
        <div class="dashboard-performance-list">
            @foreach($topDrivers as $driver)
            @php
                $init = strtoupper(substr($driver->user->name ?? 'D', 0, 2));
            @endphp
            <div class="dashboard-performance-item">
                <div class="dashboard-driver-avatar">{{ $init }}</div>
                <div class="dashboard-performance-content">
                    <div class="dashboard-performance-main">
                        <span class="dashboard-performance-name">{{ $driver->user->name ?? '-' }}</span>
                        <span class="dashboard-performance-metric">{{ $driverStatuses->operationalLabel($driver->status) }}</span>
                    </div>
                    <span class="dashboard-performance-count">{{ $driver->orders_count ?? 0 }} pesanan terkait</span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridColor = isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)';
    const labelColor = isDark ? '#92929f' : '#6b7280';
    const isCompactChart = window.matchMedia('(max-width: 640px)').matches;
    const revenueCanvas = document.getElementById('revenueChart');
    const revenueContext = revenueCanvas?.getContext('2d');
    const revenueTrends = @json($revenueTrends);
    const revenueTitles = {
        daily: 'Tren Pendapatan Ongkir & Pesanan (7 Hari Terakhir)',
        weekly: 'Tren Pendapatan Ongkir & Pesanan (8 Minggu Terakhir)',
        monthly: 'Tren Pendapatan Ongkir & Pesanan (6 Bulan Terakhir)',
    };
    let revenueChart = null;

    if (revenueContext) {
        const gradient = revenueContext.createLinearGradient(0, 0, 0, 270);
        gradient.addColorStop(0, 'rgba(240,91,36,0.24)');
        gradient.addColorStop(1, 'rgba(240,91,36,0)');

        revenueChart = new Chart(revenueContext, {
            type: 'line',
            data: {
                labels: revenueTrends.daily.labels,
                datasets: [
                    {
                        label: 'Pendapatan Ongkir (Rp)',
                        data: revenueTrends.daily.revenue,
                        borderColor: '#F05B24',
                        backgroundColor: gradient,
                        borderWidth: isCompactChart ? 2 : 2.5,
                        pointBackgroundColor: '#F05B24',
                        pointHitRadius: 14,
                        pointRadius: isCompactChart ? 2.5 : 4,
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Jumlah Pesanan',
                        data: revenueTrends.daily.orders,
                        borderColor: '#F59E0B',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        pointBackgroundColor: '#F59E0B',
                        pointHitRadius: 14,
                        pointRadius: isCompactChart ? 2.5 : 4,
                        fill: false,
                        tension: 0.4,
                        borderDash: [5, 5],
                        yAxisID: 'y1',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 80,
                layout: {
                    padding: isCompactChart
                        ? { top: 4, right: 0, bottom: 0, left: 0 }
                        : { top: 0, right: 0, bottom: 0, left: 0 },
                },
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: {
                        grid: { color: gridColor },
                        ticks: {
                            autoSkip: true,
                            color: labelColor,
                            maxRotation: 0,
                            maxTicksLimit: isCompactChart ? 4 : 7,
                        },
                    },
                    y: {
                        grid: { color: gridColor },
                        ticks: {
                            color: labelColor,
                            maxTicksLimit: isCompactChart ? 4 : 6,
                            callback: value => {
                                const n = Number(value);
                                if (Math.abs(n) >= 1000000) return 'Rp ' + (n / 1000000).toFixed(1) + 'jt';
                                if (Math.abs(n) >= 1000) return 'Rp ' + Math.round(n / 1000) + 'rb';
                                return 'Rp ' + n;
                            },
                        },
                        position: 'left',
                    },
                    y1: {
                        display: !isCompactChart,
                        grid: { drawOnChartArea: false },
                        ticks: { color: labelColor, maxTicksLimit: 5 },
                        position: 'right',
                    },
                },
                plugins: {
                    legend: {
                        display: !isCompactChart,
                        position: 'bottom',
                        labels: {
                            color: labelColor,
                            boxWidth: 12,
                            padding: 14,
                        },
                    },
                    tooltip: {
                        callbacks: {
                            label: context => context.datasetIndex === 0
                                ? ' Rp ' + Number(context.raw).toLocaleString('id-ID')
                                : ' ' + context.raw + ' Pesanan',
                        },
                    },
                },
            },
        });
    }

    if (revenueChart) {
        const applyPeriod = (period) => {
            const series = revenueTrends[period];
            if (!series) return;
            revenueChart.data.labels = series.labels;
            revenueChart.data.datasets[0].data = series.revenue;
            revenueChart.data.datasets[1].data = series.orders;
            revenueChart.update();
            const titleEl = document.getElementById('revenueChartTitle');
            if (titleEl && revenueTitles[period]) titleEl.textContent = revenueTitles[period];
            document.querySelectorAll('.dashboard-chart-controls .tab-btn')
                .forEach((btn) => btn.classList.toggle('active', btn.dataset.period === period));
        };
        document.querySelectorAll('.dashboard-chart-controls .tab-btn')
            .forEach((btn) => btn.addEventListener('click', () => applyPeriod(btn.dataset.period)));
    }

    const statusCanvas = document.getElementById('orderStatusChart');
    const statusContext = statusCanvas?.getContext('2d');

    if (statusContext) {
        new Chart(statusContext, {
            type: 'doughnut',
            data: {
                labels: ['Selesai', 'Berjalan', 'Dibatalkan'],
                datasets: [
                    {
                        data: [{{ $statusDone }}, {{ $statusActive }}, {{ $statusCancelled }}],
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                        borderColor: isDark ? '#1d1d1d' : '#ffffff',
                        borderWidth: isCompactChart ? 2 : 3,
                        hoverOffset: isCompactChart ? 4 : 8,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 80,
                cutout: isCompactChart ? '68%' : '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: context => ' ' + context.label + ': ' + context.raw.toLocaleString('id-ID'),
                        },
                    },
                },
            },
        });
    }
});
</script>
@endpush
