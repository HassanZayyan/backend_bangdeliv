@extends('layouts.admin')

@section('title', 'Pelanggan - Admin BangDeliv')
@section('page-title', 'Manajemen Pelanggan')

@section('content')
@php
    $statusCodeToId = \App\Models\OrderStatus::query()->pluck('id', 'code');
    $successStatusIds = collect(['DELIVERED', 'COMPLETED'])->map(fn ($code) => $statusCodeToId[$code] ?? null)->filter()->values()->all();
    $cancelledStatusIds = collect(['CANCELLED', 'CANCELLED_WITH_FEE'])->map(fn ($code) => $statusCodeToId[$code] ?? null)->filter()->values()->all();

    $statusFilter = request()->query('status', 'semua');

    $query = \App\Models\User::query()
        ->where('role', 'customer')
        ->withCount([
            'orders as success_orders_count' => fn ($query) => $query->whereIn('status_id', $successStatusIds),
            'orders as cancelled_orders_count' => fn ($query) => $query->whereIn('status_id', $cancelledStatusIds),
        ])
        ->latest('created_at');

    $searchQuery = request()->query('q');
    if ($searchQuery) {
        $query->where(function ($q) use ($searchQuery) {
            $q->where('name', 'like', "%{$searchQuery}%")
              ->orWhere('email', 'like', "%{$searchQuery}%")
              ->orWhere('phone', 'like', "%{$searchQuery}%");
        });
    }

    if ($statusFilter === 'aktif') {
        $query->where('is_active', true)->where('is_blacklisted', false);
    } elseif ($statusFilter === 'baru') {
        $query->whereDate('created_at', now()->toDateString());
    } elseif ($statusFilter === 'blacklisted') {
        $query->where('is_blacklisted', true);
    }

    $customers = $query->get();

    $activeCount = \App\Models\User::where('role', 'customer')->where('is_active', true)->where('is_blacklisted', false)->count();
    $newCount = \App\Models\User::where('role', 'customer')->whereDate('created_at', now()->toDateString())->count();
    $blacklistedCount = \App\Models\User::where('role', 'customer')->where('is_blacklisted', true)->count();
@endphp
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Daftar Pengguna App (Customer)</div>
            
            <div style="display: flex; gap: 10px;">
                <button class="btn" style="background: var(--bg-hover); color: var(--text-main); border: 1px solid var(--border-color);">
                    <i class='bx bx-filter-alt'></i> Filter
                </button>
                <form class="search-bar" style="width: 280px;" method="GET" action="{{ route('admin.customers.index') }}">
                    <input type="hidden" name="status" value="{{ $statusFilter }}">
                    <i class='bx bx-search'></i>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari Nama, Email, atau HP..." style="width: 100%;">
                </form>
            </div>
        </div>

        <div class="tabs">
            <button onclick="window.location.href='{{ route('admin.customers.index', ['status' => 'semua', 'q' => request('q')]) }}'" class="tab-btn {{ $statusFilter === 'semua' ? 'active' : '' }}">Semua</button>
            <button onclick="window.location.href='{{ route('admin.customers.index', ['status' => 'aktif', 'q' => request('q')]) }}'" class="tab-btn {{ $statusFilter === 'aktif' ? 'active' : '' }}">Aktif <span class="badge badge-success" style="margin-left:5px;">{{ $activeCount }}</span></button>
            <button onclick="window.location.href='{{ route('admin.customers.index', ['status' => 'baru', 'q' => request('q')]) }}'" class="tab-btn {{ $statusFilter === 'baru' ? 'active' : '' }}">Pelanggan Baru <span class="badge badge-info" style="margin-left:5px;">{{ $newCount }}</span></button>
            <button onclick="window.location.href='{{ route('admin.customers.index', ['status' => 'blacklisted', 'q' => request('q')]) }}'" class="tab-btn {{ $statusFilter === 'blacklisted' ? 'active' : '' }}">Blacklisted <span class="badge badge-danger" style="margin-left:5px;">{{ $blacklistedCount }}</span></button>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Profil Pelanggan</th>
                    <th>Kontak & Lokasi Terakhir</th>
                    <th>Riwayat Pesanan</th>
                    <th>Status Akun</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $customer)
                    @php
                        $isBlacklisted = (bool) $customer->is_blacklisted;
                        $isNew = optional($customer->created_at)->isToday();
                        $initial = strtoupper(substr($customer->name, 0, 2));
                    @endphp
                    <tr @if($isBlacklisted) style="background-color: rgba(239, 68, 68, 0.02);" @endif>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div class="driver-avatar" style="width: 45px; height: 45px; flex-shrink: 0; {{ $isBlacklisted ? 'background-color: var(--color-danger);' : '' }}">{{ $initial }}</div>
                                <div class="td-user">
                                    <span class="td-strong">{{ $customer->name }}</span>
                                    <span class="td-sub">Bergabung: {{ $customer->created_at?->format('d M Y') }}</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="td-strong"><i class='bx bx-envelope'></i> {{ $customer->email }}</span>
                            <span class="td-sub" style="display:block;"><i class='bx bx-phone'></i> {{ $customer->phone ?? '-' }}</span>
                        </td>
                        <td>
                            <span class="td-strong" style="color:var(--color-success);">{{ $customer->success_orders_count }} Sukses</span>
                            <span class="td-sub" style="display:block; {{ $customer->cancelled_orders_count > 0 ? 'color:var(--color-danger); font-weight:600;' : '' }}">{{ $customer->cancelled_orders_count }} Dibatalkan</span>
                        </td>
                        <td>
                            @if($isBlacklisted)
                                <span class="badge badge-danger">Blacklisted</span>
                            @elseif($isNew)
                                <span class="badge badge-info">Pelanggan Baru</span>
                            @else
                                <span class="badge badge-success">Aktif</span>
                            @endif
                        </td>
                        <td class="td-action">
                            <div style="display:flex; gap: 8px;">
                                <button onclick="window.location.href='{{ route('admin.orders.index', ['q' => $customer->phone ?? $customer->name]) }}'" class="btn-action detail" title="Lihat History & Detail"><i class='bx bx-show'></i></button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="td-sub" style="text-align:center; padding:24px;">Belum ada data pelanggan.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    
    <div class="panel-pagination" style="padding: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">Menampilkan {{ $customers->count() }} Pelanggan</span>
        <div class="pagination-controls" style="display: flex; gap: 6px;">
            <button class="btn-page active">1</button>
        </div>
    </div>
</div>
@endsection
