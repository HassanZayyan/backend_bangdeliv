@extends('layouts.admin')

@section('title', 'Pesanan - Admin BangDeliv')
@section('page-title', 'Manajemen Pesanan')

@section('content')
@php
    $statusCodeToId = \App\Models\OrderStatus::query()->pluck('id', 'code');
    $pendingId = $statusCodeToId['PENDING'] ?? null;
    $driverAssignedId = $statusCodeToId['DRIVER_ASSIGNED'] ?? null;
    $onTheWayId = $statusCodeToId['ON_THE_WAY'] ?? null;
    $completedId = $statusCodeToId['COMPLETED'] ?? null;
    $cancelledIds = collect(['CANCELLED', 'CANCELLED_WITH_FEE'])->map(fn ($code) => $statusCodeToId[$code] ?? null)->filter()->values()->all();

    $orders = \App\Models\Order::query()
        ->with(['user', 'restaurant', 'driver.user', 'statusRef'])
        ->latest('created_at')
        ->limit(20)
        ->get();

    $statusCounts = [
        'all' => \App\Models\Order::count(),
        'pending' => $pendingId ? \App\Models\Order::where('status_id', $pendingId)->count() : 0,
        'driver_assigned' => $driverAssignedId ? \App\Models\Order::where('status_id', $driverAssignedId)->count() : 0,
        'on_the_way' => $onTheWayId ? \App\Models\Order::where('status_id', $onTheWayId)->count() : 0,
        'completed' => $completedId ? \App\Models\Order::where('status_id', $completedId)->count() : 0,
        'cancelled' => \App\Models\Order::whereIn('status_id', $cancelledIds)->count(),
    ];

    $statusMap = [
        'PENDING' => ['label' => 'Menunggu Driver', 'class' => 'badge-warning'],
        'DRIVER_ASSIGNED' => ['label' => 'Driver Ditugaskan', 'class' => 'badge-info'],
        'PICKED_UP' => ['label' => 'Pickup', 'class' => 'badge-info'],
        'ON_THE_WAY' => ['label' => 'Diantar', 'class' => 'badge-info'],
        'DELIVERED' => ['label' => 'Terkirim', 'class' => 'badge-success'],
        'COMPLETED' => ['label' => 'Selesai', 'class' => 'badge-success'],
        'CANCELLED' => ['label' => 'Dibatalkan', 'class' => 'badge-danger'],
        'CANCELLED_WITH_FEE' => ['label' => 'Batal Dengan Biaya', 'class' => 'badge-danger'],
        'COMPLAINT' => ['label' => 'Komplain', 'class' => 'badge-danger'],
    ];
@endphp
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Daftar Seluruh Pesanan</div>
            
            <div class="search-bar" style="width: 320px;">
                <i class='bx bx-search'></i>
                <input type="text" placeholder="Cari ID Pesanan, Pelanggan, Restoran..." style="width: 100%;">
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active">Semua</button>
            <button class="tab-btn">Menunggu Driver <span class="badge badge-warning" style="margin-left:5px;">{{ $statusCounts['pending'] }}</span></button>
            <button class="tab-btn">Mencari Driver <span class="badge badge-info" style="margin-left:5px;">{{ $statusCounts['driver_assigned'] }}</span></button>
            <button class="tab-btn">Diantar <span class="badge badge-info" style="margin-left:5px;">{{ $statusCounts['on_the_way'] }}</span></button>
            <button class="tab-btn">Selesai <span class="badge badge-success" style="margin-left:5px;">{{ $statusCounts['completed'] }}</span></button>
            <button class="tab-btn">Batal <span class="badge badge-danger" style="margin-left:5px;">{{ $statusCounts['cancelled'] }}</span></button>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>ID Pesanan</th>
                    <th>Waktu</th>
                    <th>Pelanggan</th>
                    <th>Restoran</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Aksi Darurat</th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                    @php
                        $statusCode = $order->statusRef?->code ?? 'UNKNOWN';
                        $statusConfig = $statusMap[$statusCode] ?? ['label' => $statusCode, 'class' => 'badge-info'];
                    @endphp
                    <tr>
                        <td class="td-id">#{{ $order->order_number }}</td>
                        <td class="td-sub">{{ $order->created_at?->format('d M Y, H:i') }}</td>
                        <td class="td-user">
                            <span class="td-strong">{{ $order->user->name ?? '-' }}</span>
                            <span class="td-sub"><i class='bx bx-phone'></i> {{ $order->user->phone ?? '-' }}</span>
                        </td>
                        <td class="td-resto">
                            <span class="td-strong">{{ $order->restaurant->name ?? '-' }}</span>
                            <span class="td-sub">{{ \Illuminate\Support\Str::limit($order->delivery_address, 45) }}</span>
                        </td>
                        <td class="td-price">Rp {{ number_format((float) $order->total_amount, 0, ',', '.') }}</td>
                        <td>
                            <span class="badge {{ $statusConfig['class'] }}">
                                {{ $statusConfig['label'] }}
                                @if($statusCode === 'ON_THE_WAY' && $order->driver?->user)
                                    ({{ $order->driver->user->name }})
                                @endif
                            </span>
                        </td>
                        <td class="td-action">
                            <div style="display:flex; gap: 8px;">
                                <button class="btn-action detail" title="Lihat Detail Log"><i class='bx bx-show'></i></button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="td-sub" style="text-align:center; padding:24px;">Belum ada data pesanan.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    
    <div class="panel-pagination" style="padding: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">Menampilkan {{ $orders->count() }} dari {{ $statusCounts['all'] }} Pesanan</span>
        <div class="pagination-controls" style="display: flex; gap: 6px;">
            <button class="btn-page active">1</button>
        </div>
    </div>
</div>
@endsection
