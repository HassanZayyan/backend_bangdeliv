@extends('layouts.admin')

@section('title', 'Dashboard - Admin BangDeliv')
@section('page-title', 'Dashboard Utama')

@section('content')

{{-- KPI Cards Row --}}
<div class="stat-cards-wrapper">
    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-title">GMV Bulan Ini</span>
            <div class="stat-icon" style="background: rgba(255,119,0,0.1); color: var(--color-primary);">
                <i class='bx bx-money'></i>
            </div>
        </div>
        <div class="stat-value text-primary">Rp 32.4M</div>
        <div class="stat-change positive"><i class='bx bx-up-arrow-alt'></i> +18% dari bulan lalu</div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-title">Total Pesanan (Bulan Ini)</span>
            <div class="stat-icon" style="background: rgba(59,130,246,0.1); color: var(--color-info);">
                <i class='bx bx-receipt'></i>
            </div>
        </div>
        <div class="stat-value">1,847</div>
        <div class="stat-change positive"><i class='bx bx-up-arrow-alt'></i> +12% dari bulan lalu</div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-title">Pesanan Batal (Bulan Ini)</span>
            <div class="stat-icon" style="background: rgba(239,68,68,0.1); color: var(--color-danger);">
                <i class='bx bx-x-circle'></i>
            </div>
        </div>
        <div class="stat-value">58</div>
        <div class="stat-change negative"><i class='bx bx-down-arrow-alt'></i> -5% (membaik) vs bulan lalu</div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <span class="stat-title">Pengguna Baru (Bulan Ini)</span>
            <div class="stat-icon" style="background: rgba(16,185,129,0.1); color: var(--color-success);">
                <i class='bx bx-user-plus'></i>
            </div>
        </div>
        <div class="stat-value text-success">+124</div>
        <div class="stat-change positive"><i class='bx bx-up-arrow-alt'></i> +31% dari bulan lalu</div>
    </div>
</div>

{{-- Charts Row --}}
<div class="dashboard-grid" style="margin-bottom: 20px;">
    {{-- Revenue Trend Chart --}}
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title">Tren Pendapatan & Pesanan (7 Hari Terakhir)</div>
            <div style="display: flex; gap: 8px;">
                <button class="tab-btn active" style="padding: 5px 12px; font-size: 12px;">Harian</button>
                <button class="tab-btn" style="padding: 5px 12px; font-size: 12px;">Mingguan</button>
                <button class="tab-btn" style="padding: 5px 12px; font-size: 12px;">Bulanan</button>
            </div>
        </div>
        <div style="padding: 20px; height: 280px; position: relative;">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>

    {{-- Order Status Donut --}}
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title">Distribusi Status Pesanan</div>
        </div>
        <div style="padding: 20px; display: flex; flex-direction: column; align-items: center; gap: 20px;">
            <div style="height: 200px; width: 200px; position: relative;">
                <canvas id="orderStatusChart"></canvas>
            </div>
            <div style="display: flex; flex-direction: column; gap: 10px; width: 100%;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="width: 10px; height: 10px; border-radius: 50%; background: var(--color-success);"></div>
                        <span style="font-size: 13px; color: var(--text-muted);">Selesai</span>
                    </div>
                    <span style="font-size: 13px; font-weight: 700;">1,789 (96.9%)</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="width: 10px; height: 10px; border-radius: 50%; background: var(--color-info);"></div>
                        <span style="font-size: 13px; color: var(--text-muted);">Sedang Berjalan</span>
                    </div>
                    <span style="font-size: 13px; font-weight: 700;">24 (1.3%)</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="width: 10px; height: 10px; border-radius: 50%; background: var(--color-danger);"></div>
                        <span style="font-size: 13px; color: var(--text-muted);">Dibatalkan</span>
                    </div>
                    <span style="font-size: 13px; font-weight: 700;">58 (3.1%)</span>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Top Performance Row --}}
<div class="dashboard-grid">
    {{-- Top Restaurants --}}
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title"><i class='bx bx-trophy' style="color:var(--color-warning);"></i>  Top 5 Restoran Terlaris</div>
            <span style="font-size: 12px; color: var(--text-muted);">Bulan ini</span>
        </div>
        <div style="padding: 0;">
            @php
                $restaurants = [
                    ['name' => 'Ayam Geprek Juara', 'orders' => 520, 'rev' => 'Rp 14.5M', 'pct' => 100],
                    ['name' => 'Kopi Senja Masa', 'orders' => 410, 'rev' => 'Rp 8.2M', 'pct' => 78],
                    ['name' => 'Warung Bu Sri', 'orders' => 340, 'rev' => 'Rp 6.1M', 'pct' => 65],
                    ['name' => 'Sate Madura Asli', 'orders' => 285, 'rev' => 'Rp 5.5M', 'pct' => 55],
                    ['name' => 'Nasi Goreng Gila', 'orders' => 185, 'rev' => 'Rp 3.2M', 'pct' => 36],
                ];
            @endphp
            @foreach($restaurants as $i => $resto)
            <div style="padding: 14px 20px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 15px;">
                <span style="font-size: 16px; font-weight:800; color: {{ $i === 0 ? 'var(--color-warning)' : 'var(--text-muted)' }}; width: 20px; text-align:center;">{{ $i + 1 }}</span>
                <div style="flex: 1;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                        <span style="font-size: 14px; font-weight: 600;">{{ $resto['name'] }}</span>
                        <span style="font-size: 13px; color: var(--color-primary); font-weight: 700;">{{ $resto['rev'] }}</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="flex:1; height: 5px; background: var(--bg-body); border-radius:4px;">
                            <div style="width: {{ $resto['pct'] }}%; height: 100%; background: var(--color-primary); border-radius:4px; opacity: {{ 1 - ($i * 0.12) }};"></div>
                        </div>
                        <span style="font-size: 12px; color: var(--text-muted);">{{ $resto['orders'] }} orders</span>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Top Drivers --}}
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title"><i class='bx bx-cycling' style="color:var(--color-success);"></i>  Top 5 Driver Paling Aktif</div>
            <span style="font-size: 12px; color: var(--text-muted);">Bulan ini</span>
        </div>
        <div style="padding: 0;">
            @php
                $drivers = [
                    ['name' => 'Budi Santoso', 'init' => 'BS', 'color' => '#3b82f6', 'trips' => 248, 'rating' => '4.9'],
                    ['name' => 'Sari Wulandari', 'init' => 'SW', 'color' => '#a855f7', 'trips' => 210, 'rating' => '4.8'],
                    ['name' => 'Agus Prasetyo', 'init' => 'AP', 'color' => '#10b981', 'trips' => 187, 'rating' => '4.7'],
                    ['name' => 'Fajar R.', 'init' => 'FR', 'color' => '#f59e0b', 'trips' => 155, 'rating' => '4.8'],
                    ['name' => 'Teguh W.', 'init' => 'TW', 'color' => '#64748b', 'trips' => 130, 'rating' => '4.5'],
                ];
            @endphp
            @foreach($drivers as $i => $driver)
            <div style="padding: 14px 20px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 15px;">
                <div style="width: 38px; height: 38px; border-radius: 50%; background: {{ $driver['color'] }}; display:flex; align-items:center; justify-content:center; color:white; font-weight:700; font-size:13px; flex-shrink:0;">{{ $driver['init'] }}</div>
                <div style="flex: 1;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="font-size: 14px; font-weight: 600;">{{ $driver['name'] }}</span>
                        <span style="font-size: 13px; color: var(--color-warning); font-weight: 700;"><i class='bx bxs-star'></i> {{ $driver['rating'] }}</span>
                    </div>
                    <span style="font-size: 12px; color: var(--text-muted);">{{ $driver['trips'] }} trip selesai bulan ini</span>
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
    const isDark = document.body.getAttribute('data-theme') === 'dark';
    const gridColor = isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)';
    const labelColor = isDark ? '#92929f' : '#6b7280';

    // Revenue Trend Chart
    const revCtx = document.getElementById('revenueChart').getContext('2d');
    const revenueGradient = revCtx.createLinearGradient(0, 0, 0, 270);
    revenueGradient.addColorStop(0, 'rgba(255, 119, 0, 0.3)');
    revenueGradient.addColorStop(1, 'rgba(255, 119, 0, 0)');

    new Chart(revCtx, {
        type: 'line',
        data: {
            labels: ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'],
            datasets: [
                {
                    label: 'Pendapatan (Rp)',
                    data: [3200000, 4100000, 3800000, 5100000, 4700000, 7200000, 6800000],
                    borderColor: '#ff7700',
                    backgroundColor: revenueGradient,
                    borderWidth: 2.5,
                    pointBackgroundColor: '#ff7700',
                    pointRadius: 4,
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y',
                },
                {
                    label: 'Jumlah Pesanan',
                    data: [65, 82, 75, 110, 95, 142, 130],
                    borderColor: '#3b82f6',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointBackgroundColor: '#3b82f6',
                    pointRadius: 4,
                    fill: false,
                    tension: 0.4,
                    yAxisID: 'y1',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { grid: { color: gridColor }, ticks: { color: labelColor } },
                y: {
                    grid: { color: gridColor },
                    ticks: {
                        color: labelColor,
                        callback: val => 'Rp ' + (val/1000000).toFixed(1) + 'M'
                    },
                    position: 'left'
                },
                y1: {
                    grid: { drawOnChartArea: false },
                    ticks: { color: labelColor },
                    position: 'right'
                }
            },
            plugins: {
                legend: { labels: { color: labelColor, boxWidth: 12, padding: 20 } },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.datasetIndex === 0
                            ? ' Rp ' + ctx.raw.toLocaleString('id-ID')
                            : ' ' + ctx.raw + ' Pesanan'
                    }
                }
            }
        }
    });

    // Donut Chart
    const donutCtx = document.getElementById('orderStatusChart').getContext('2d');
    new Chart(donutCtx, {
        type: 'doughnut',
        data: {
            labels: ['Selesai', 'Berjalan', 'Dibatalkan'],
            datasets: [{
                data: [1789, 24, 58],
                backgroundColor: ['#10b981', '#3b82f6', '#ef4444'],
                borderColor: isDark ? '#1e1e2d' : '#ffffff',
                borderWidth: 3,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': ' + ctx.raw.toLocaleString('id-ID') } }
            }
        }
    });
});
</script>
@endpush
