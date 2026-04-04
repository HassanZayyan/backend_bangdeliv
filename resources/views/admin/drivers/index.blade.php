@extends('layouts.admin')

@section('title', 'Driver - Admin BangDeliv')
@section('page-title', 'Manajemen Driver')

@section('content')
@php
    $drivers = \App\Models\Driver::query()
        ->with('user')
        ->latest('created_at')
        ->get();

    $onlineCount = \App\Models\Driver::where('status', 'available')->where('registration_status', 'active')->count();
    $offlineCount = \App\Models\Driver::where('status', 'offline')->count();
    $suspendedCount = \App\Models\Driver::where('registration_status', 'suspended')->count();
    $pendingCount = \App\Models\Driver::where('registration_status', 'pending')->count();
@endphp
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Daftar Mitra Driver</div>
            
            <div style="display: flex; gap: 10px;">
                <button class="btn" style="background: var(--bg-hover); color: var(--text-main); border: 1px solid var(--border-color);">
                    <i class='bx bx-filter-alt'></i> Filter
                </button>
                <div class="search-bar" style="width: 280px;">
                    <i class='bx bx-search'></i>
                    <input type="text" placeholder="Cari Nama, Plat Nomor, atau HP..." style="width: 100%;">
                </div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active">Semua</button>
            <button class="tab-btn">Aktif (Online) <span class="badge badge-success" style="margin-left:5px;">{{ $onlineCount }}</span></button>
            <button class="tab-btn">Offline <span class="badge badge-info" style="margin-left:5px;">{{ $offlineCount }}</span></button>
            <button class="tab-btn">Suspended <span class="badge badge-danger" style="margin-left:5px;">{{ $suspendedCount }}</span></button>
            <button class="tab-btn">Pending Verifikasi <span class="badge badge-warning" style="margin-left:5px;">{{ $pendingCount }}</span></button>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Profil Driver</th>
                    <th>Kendaraan</th>
                    <th>Performa & Trip</th>
                    <th>Status Akun</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($drivers as $driver)
                    @php
                        $initial = strtoupper(substr($driver->user->name ?? 'D', 0, 2));
                        $statusText = 'Offline';
                        $statusClass = 'badge-info';

                        if ($driver->registration_status === 'suspended') {
                            $statusText = 'Suspended';
                            $statusClass = 'badge-danger';
                        } elseif ($driver->registration_status === 'pending') {
                            $statusText = 'Pending Verifikasi';
                            $statusClass = 'badge-warning';
                        } elseif ($driver->status === 'available') {
                            $statusText = 'Aktif (Online)';
                            $statusClass = 'badge-success';
                        }
                    @endphp
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div class="driver-avatar" style="width: 45px; height: 45px; flex-shrink: 0;">{{ $initial }}</div>
                                <div class="td-user">
                                    <span class="td-strong">{{ $driver->user->name ?? '-' }}</span>
                                    <span class="td-sub"><i class='bx bx-phone'></i> {{ $driver->user->phone ?? '-' }}</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="td-strong">{{ $driver->vehicle_plate }}</span>
                            <span class="td-sub" style="display:block;">{{ $driver->license_number }}</span>
                        </td>
                        <td>
                            <span class="td-strong" style="color:var(--color-warning);"><i class='bx bxs-star'></i> {{ number_format((float) $driver->avg_rating, 1) }}</span>
                            <span class="td-sub" style="display:block;">{{ $driver->total_deliveries }} Trip Selesai</span>
                        </td>
                        <td><span class="badge {{ $statusClass }}">{{ $statusText }}</span></td>
                        <td class="td-action">
                            <div style="display:flex; gap: 8px;">
                                <button class="btn-action detail" title="Lihat Profil Lengkap"><i class='bx bx-id-card'></i></button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="td-sub" style="text-align:center; padding:24px;">Belum ada data driver.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    
    <div class="panel-pagination" style="padding: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">Menampilkan {{ $drivers->count() }} Driver</span>
        <div class="pagination-controls" style="display: flex; gap: 6px;">
            <button class="btn-page active">1</button>
        </div>
    </div>
</div>
@endsection
