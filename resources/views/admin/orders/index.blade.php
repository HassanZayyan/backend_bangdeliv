@extends('layouts.admin')

@section('title', 'Pesanan - Admin BangDeliv')
@section('page-title', 'Manajemen Pesanan')

@section('content')
@php
    $orders = \App\Models\Order::query()
        ->with(['user', 'restaurant', 'driver.user'])
        ->latest('created_at')
        ->limit(20)
        ->get();

    $statusCounts = [
        'all' => \App\Models\Order::count(),
        'confirmed' => \App\Models\Order::where('status', 'confirmed')->count(),
        'driver_assigned' => \App\Models\Order::where('status', 'driver_assigned')->count(),
        'on_delivery' => \App\Models\Order::where('status', 'on_delivery')->count(),
        'completed' => \App\Models\Order::where('status', 'completed')->count(),
        'cancelled' => \App\Models\Order::where('status', 'cancelled')->count(),
    ];

    $statusMap = [
        'confirmed' => ['label' => 'Menunggu Resto', 'class' => 'badge-warning'],
        'driver_assigned' => ['label' => 'Mencari Driver', 'class' => 'badge-info'],
        'item_unavailable' => ['label' => 'Item Tidak Tersedia', 'class' => 'badge-danger'],
        'picking_up' => ['label' => 'Pickup', 'class' => 'badge-info'],
        'on_delivery' => ['label' => 'Diantar', 'class' => 'badge-info'],
        'delivered' => ['label' => 'Terkirim', 'class' => 'badge-success'],
        'completed' => ['label' => 'Selesai', 'class' => 'badge-success'],
        'cancelled' => ['label' => 'Dibatalkan', 'class' => 'badge-danger'],
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
            <button class="tab-btn">Menunggu Resto <span class="badge badge-warning" style="margin-left:5px;">{{ $statusCounts['confirmed'] }}</span></button>
            <button class="tab-btn">Mencari Driver <span class="badge badge-info" style="margin-left:5px;">{{ $statusCounts['driver_assigned'] }}</span></button>
            <button class="tab-btn">Diantar <span class="badge badge-info" style="margin-left:5px;">{{ $statusCounts['on_delivery'] }}</span></button>
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
                        $statusConfig = $statusMap[$order->status] ?? ['label' => ucfirst($order->status), 'class' => 'badge-info'];
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
                                @if($order->status === 'on_delivery' && $order->driver?->user)
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
