@extends('layouts.admin')

@section('title', 'Pelanggan - Admin BangDeliv')
@section('page-title', 'Manajemen Pelanggan')

@section('content')
@php
    $customers = \App\Models\User::query()
        ->where('role', 'customer')
        ->withCount([
            'orders as success_orders_count' => fn ($query) => $query->whereIn('status', ['delivered', 'completed']),
            'orders as cancelled_orders_count' => fn ($query) => $query->where('status', 'cancelled'),
        ])
        ->latest('created_at')
        ->get();

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
                <div class="search-bar" style="width: 280px;">
                    <i class='bx bx-search'></i>
                    <input type="text" placeholder="Cari Nama, Email, atau HP..." style="width: 100%;">
                </div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active">Semua</button>
            <button class="tab-btn">Aktif <span class="badge badge-success" style="margin-left:5px;">{{ $activeCount }}</span></button>
            <button class="tab-btn">Pelanggan Baru <span class="badge badge-info" style="margin-left:5px;">{{ $newCount }}</span></button>
            <button class="tab-btn">Blacklisted <span class="badge badge-danger" style="margin-left:5px;">{{ $blacklistedCount }}</span></button>
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
                                <button class="btn-action detail" title="Lihat History & Detail"><i class='bx bx-show'></i></button>
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
