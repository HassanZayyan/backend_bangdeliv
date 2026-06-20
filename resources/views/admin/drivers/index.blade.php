@extends('layouts.admin')

@section('title', 'Driver - Admin BangDeliv')
@section('page-title', 'Manajemen Driver')

@section('content')
@php
    $statusFilter = request()->query('status', 'semua');

    $query = \App\Models\Driver::query()
        ->with('user')
        ->withCount('orders')
        ->latest('created_at');

    $searchQuery = request()->query('q');
    if ($searchQuery) {
        $query->where(function ($q) use ($searchQuery) {
            $q->where('vehicle_plate', 'like', "%{$searchQuery}%")
              ->orWhereHas('user', function ($uq) use ($searchQuery) {
                  $uq->where('name', 'like', "%{$searchQuery}%")
                     ->orWhere('phone', 'like', "%{$searchQuery}%");
              });
        });
    }

    if ($statusFilter === 'aktif') {
        $query->where('status', 'available')->where('registration_status', 'active');
    } elseif ($statusFilter === 'offline') {
        $query->where('status', 'offline');
    } elseif ($statusFilter === 'suspended') {
        $query->where('registration_status', 'suspended');
    } elseif ($statusFilter === 'pending') {
        $query->where('registration_status', 'pending');
    }

    $drivers = $query->get();

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
                <form class="search-bar" style="width: 280px;" method="GET" action="{{ route('admin.drivers.index') }}">
                    <input type="hidden" name="status" value="{{ $statusFilter }}">
                    <i class='bx bx-search'></i>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari Nama, Plat Nomor, atau HP..." style="width: 100%;">
                </form>
            </div>
        </div>

        <div class="tabs">
            <button onclick="window.location.href='{{ route('admin.drivers.index', ['status' => 'semua', 'q' => request('q')]) }}'" class="tab-btn {{ $statusFilter === 'semua' ? 'active' : '' }}">Semua</button>
            <button onclick="window.location.href='{{ route('admin.drivers.index', ['status' => 'aktif', 'q' => request('q')]) }}'" class="tab-btn {{ $statusFilter === 'aktif' ? 'active' : '' }}">Aktif (Online) <span class="badge badge-success" style="margin-left:5px;">{{ $onlineCount }}</span></button>
            <button onclick="window.location.href='{{ route('admin.drivers.index', ['status' => 'offline', 'q' => request('q')]) }}'" class="tab-btn {{ $statusFilter === 'offline' ? 'active' : '' }}">Offline <span class="badge badge-info" style="margin-left:5px;">{{ $offlineCount }}</span></button>
            <button onclick="window.location.href='{{ route('admin.drivers.index', ['status' => 'suspended', 'q' => request('q')]) }}'" class="tab-btn {{ $statusFilter === 'suspended' ? 'active' : '' }}">Suspended <span class="badge badge-danger" style="margin-left:5px;">{{ $suspendedCount }}</span></button>
            <button onclick="window.location.href='{{ route('admin.drivers.index', ['status' => 'pending', 'q' => request('q')]) }}'" class="tab-btn {{ $statusFilter === 'pending' ? 'active' : '' }}">Pending Verifikasi <span class="badge badge-warning" style="margin-left:5px;">{{ $pendingCount }}</span></button>
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
                        $avatarPath = trim((string) ($driver->user->avatar ?? ''));
                        $avatarUrl = null;
                        if ($avatarPath !== '' && \Illuminate\Support\Facades\Storage::disk('public')->exists($avatarPath)) {
                            $avatarUrl = asset('storage/'.$avatarPath);
                        }
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
                                <div class="driver-avatar {{ $avatarUrl ? 'has-image' : 'is-fallback' }}" style="width: 45px; height: 45px; flex-shrink: 0;">
                                    @if($avatarUrl)
                                        <img
                                            src="{{ $avatarUrl }}"
                                            alt="Avatar {{ $driver->user->name ?? 'Driver' }}"
                                            class="driver-avatar-image"
                                            loading="lazy"
                                            onerror="this.parentElement.classList.remove('has-image'); this.parentElement.classList.add('is-fallback'); this.remove();"
                                        >
                                    @endif
                                    <span class="driver-avatar-fallback">{{ $initial }}</span>
                                </div>
                                <div class="td-user">
                                    <span class="td-strong">{{ $driver->user->name ?? '-' }}</span>
                                    <span class="td-sub"><i class='bx bx-phone'></i> {{ $driver->user->phone ?? '-' }}</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="td-strong">{{ $driver->vehicle_plate }}</span>
                        </td>
                        <td>
                            <span class="td-strong">{{ $driver->orders_count ?? 0 }} order terkait</span>
                            <span class="td-sub" style="display:block;">Rating driver tidak digunakan</span>
                        </td>
                        <td><span class="badge {{ $statusClass }}">{{ $statusText }}</span></td>
                        <td class="td-action">
                            <div style="display:flex; gap: 8px;">
                                <button onclick="window.location.href='{{ route('admin.verification.show', ['driverId' => $driver->id]) }}'" class="btn-action detail" title="Lihat Profil Lengkap"><i class='bx bx-id-card'></i></button>
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
