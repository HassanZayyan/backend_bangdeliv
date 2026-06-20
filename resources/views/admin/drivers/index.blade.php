@extends('layouts.admin')

@section('title', 'Driver - Admin BangDeliv')
@section('page-title', 'Manajemen Driver')

@section('content')
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Daftar Mitra Driver</div>
            
            <div style="display: flex; gap: 10px;">
                <form class="search-bar" style="width: 280px;" method="GET" action="{{ route('admin.drivers.index') }}">
                    <input type="hidden" name="status" value="{{ $selectedStatus }}">
                    <i class='bx bx-search'></i>
                    <input type="text" name="q" value="{{ $search }}" placeholder="Cari Nama, Plat Nomor, atau HP..." style="width: 100%;">
                </form>
            </div>
        </div>

        <div class="tabs">
            @foreach($statusFilters as $statusKey => $statusConfig)
                <a href="{{ route('admin.drivers.index', ['status' => $statusKey, 'q' => $search]) }}" class="tab-btn {{ $selectedStatus === $statusKey ? 'active' : '' }}" style="text-decoration:none;">
                    {{ $statusConfig['label'] }}
                    @if($statusKey !== 'semua')
                        <span class="badge {{ $statusConfig['badge_class'] }}" style="margin-left:5px;">{{ $statusCounts[$statusKey] ?? 0 }}</span>
                    @endif
                </a>
            @endforeach
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
                        $status = $driver->admin_status ?? ['label' => 'Offline', 'class' => 'badge-info'];
                        $avatarUrl = $driver->admin_avatar_url;
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
                                    <span class="driver-avatar-fallback">{{ $driver->admin_initial }}</span>
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
                        <td><span class="badge {{ $status['class'] }}">{{ $status['label'] }}</span></td>
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
    
    <x-admin-pagination :paginator="$drivers" label="driver" />
</div>
@endsection
