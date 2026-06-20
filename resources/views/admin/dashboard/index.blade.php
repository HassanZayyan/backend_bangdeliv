@extends('layouts.admin')

@section('title', 'Dashboard - Admin BangDeliv')
@section('page-title', 'Dashboard Utama')

@section('content')
{{-- KPI Cards Row --}}
<div class="stat-cards-wrapper">
    <x-stat-card title="GMV Bulan Ini" value="Rp {{ number_format((float) $gmvMonth, 0, ',', '.') }}" icon="bx-money" color="primary" change="Data realtime" change-type="positive" />
    <x-stat-card title="Total Pesanan (Bulan Ini)" value="{{ number_format($totalOrdersMonth, 0, ',', '.') }}" icon="bx-receipt" color="info" change="Data realtime" change-type="positive" />
    <x-stat-card title="Pesanan Batal (Bulan Ini)" value="{{ number_format($cancelledOrdersMonth, 0, ',', '.') }}" icon="bx-x-circle" color="danger" change="Data realtime" change-type="negative" />
    <x-stat-card title="Pengguna Baru (Bulan Ini)" value="{{ number_format($newUsersMonth, 0, ',', '.') }}" icon="bx-user-plus" color="success" change="Data realtime" change-type="positive" />
</div>

{{-- Charts Row --}}
<div class="dashboard-grid" style="margin-bottom: 20px;">
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

    <div class="panel">
        <div class="panel-header">
            <div class="panel-title">Distribusi Status Pesanan</div>
        </div>
        <div style="padding: 20px; display: flex; flex-direction: column; align-items: center; gap: 20px;">
            <div style="height: 200px; width: 200px; position: relative;">
                <canvas id="orderStatusChart"></canvas>
            </div>
            <div style="display: flex; flex-direction: column; gap: 10px; width: 100%;">
                @php
                    $totalStatus = max($statusDone + $statusActive + $statusCancelled, 1);
                    $statusItems = [
                        ['label' => 'Selesai', 'color' => 'success', 'count' => $statusDone],
                        ['label' => 'Sedang Berjalan', 'color' => 'info', 'count' => $statusActive],
                        ['label' => 'Dibatalkan', 'color' => 'danger', 'count' => $statusCancelled],
                    ];
                @endphp
                @foreach($statusItems as $item)
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="width: 10px; height: 10px; border-radius: 50%; background: var(--color-{{ $item['color'] }});"></div>
                        <span style="font-size: 13px; color: var(--text-muted);">{{ $item['label'] }}</span>
                    </div>
                    <span style="font-size: 13px; font-weight: 700;">{{ $item['count'] }} ({{ number_format(($item['count'] / $totalStatus) * 100, 1) }}%)</span>
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
            <div class="panel-title"><i class='bx bx-trophy' style="color:var(--color-warning);"></i> Top 5 Restoran Terlaris</div>
            <span style="font-size: 12px; color: var(--text-muted);">Bulan ini</span>
        </div>
        <div style="padding: 0;">
            @foreach($topRestaurants as $i => $resto)
            @php
                $restoRevenue = (float) ($resto->orders_total_price_sum ?? 0);
                $pct = (int) round(($resto->orders_count / $maxRestOrders) * 100);
            @endphp
            <div style="padding: 14px 20px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 15px;">
                <span style="font-size: 16px; font-weight:800; color: {{ $i===0 ? 'var(--color-warning)' : 'var(--text-muted)' }}; width:20px; text-align:center;">{{ $i+1 }}</span>
                <div style="flex: 1;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                        <span style="font-size:14px; font-weight:600;">{{ $resto->name }}</span>
                        <span style="font-size:13px; color:var(--color-primary); font-weight:700;">Rp {{ number_format((float) $restoRevenue, 0, ',', '.') }}</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="flex:1; height:5px; background:var(--bg-body); border-radius:4px;">
                            <div style="width:{{ $pct }}%; height:100%; background:var(--color-primary); border-radius:4px; opacity:{{ 1-($i*0.12) }};"></div>
                        </div>
                        <span style="font-size:12px; color:var(--text-muted);">{{ $resto->orders_count }} orders</span>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div class="panel-title"><i class='bx bx-cycling' style="color:var(--color-success);"></i> Top 5 Driver Paling Aktif</div>
            <span style="font-size: 12px; color: var(--text-muted);">Bulan ini</span>
        </div>
        <div style="padding: 0;">
            @foreach($topDrivers as $driver)
            @php
                $init = strtoupper(substr($driver->user->name ?? 'D', 0, 2));
            @endphp
            <div style="padding:14px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:15px;">
                <div style="width:38px; height:38px; border-radius:50%; background:var(--color-primary); display:flex; align-items:center; justify-content:center; color:white; font-weight:700; font-size:13px; flex-shrink:0;">{{ $init }}</div>
                <div style="flex:1;">
                    <div style="display:flex; justify-content:space-between;">
                        <span style="font-size:14px; font-weight:600;">{{ $driver->user->name ?? '-' }}</span>
                        <span style="font-size:13px; color:var(--color-primary); font-weight:700;">{{ $driver->status }}</span>
                    </div>
                    <span style="font-size:12px; color:var(--text-muted);">{{ $driver->orders_count ?? 0 }} order terkait</span>
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
    const revCtx = document.getElementById('revenueChart').getContext('2d');
    const grad = revCtx.createLinearGradient(0,0,0,270);
    grad.addColorStop(0,'rgba(240,91,36,0.24)'); grad.addColorStop(1,'rgba(240,91,36,0)');
    new Chart(revCtx, { type:'line', data:{ labels:@json($dailyLabels), datasets:[{label:'Pendapatan (Rp)',data:@json(collect($dailyData)->pluck('revenue')->values()),borderColor:'#F05B24',backgroundColor:grad,borderWidth:2.5,pointBackgroundColor:'#F05B24',pointRadius:4,fill:true,tension:0.4,yAxisID:'y'},{label:'Jumlah Pesanan',data:@json(collect($dailyData)->pluck('orders')->values()),borderColor:'#F59E0B',backgroundColor:'transparent',borderWidth:2,pointBackgroundColor:'#F59E0B',pointRadius:4,fill:false,tension:0.4,borderDash:[5,5],yAxisID:'y1'}]}, options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},scales:{x:{grid:{color:gridColor},ticks:{color:labelColor}},y:{grid:{color:gridColor},ticks:{color:labelColor,callback:v=>'Rp '+(v/1000000).toFixed(1)+'M'},position:'left'},y1:{grid:{drawOnChartArea:false},ticks:{color:labelColor},position:'right'}},plugins:{legend:{labels:{color:labelColor,boxWidth:12,padding:20}},tooltip:{callbacks:{label:ctx=>ctx.datasetIndex===0?' Rp '+Number(ctx.raw).toLocaleString('id-ID'):' '+ctx.raw+' Pesanan'}}}}});
    new Chart(document.getElementById('orderStatusChart').getContext('2d'), { type:'doughnut', data:{labels:['Selesai','Berjalan','Dibatalkan'],datasets:[{data:[{{ $statusDone }},{{ $statusActive }},{{ $statusCancelled }}],backgroundColor:['#10b981','#f59e0b','#ef4444'],borderColor:isDark?'#1d1d1d':'#ffffff',borderWidth:3,hoverOffset:8}]}, options:{responsive:true,maintainAspectRatio:false,cutout:'72%',plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>' '+ctx.label+': '+ctx.raw.toLocaleString('id-ID')}}}}});
});
</script>
@endpush
