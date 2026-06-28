@extends('layouts.admin')

@section('title', 'Driver - Admin BangDeliv')
@section('page-title', 'Manajemen Driver')

@section('content')
@php
    $formatCurrency = fn ($amount): string => 'Rp '.number_format((float) $amount, 0, ',', '.');
    $formatPercent = function ($percent): string {
        $formatted = number_format((float) $percent, 2, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',').'%';
    };
    $buildQuery = function (array $overrides): array {
        $query = array_merge(request()->except('page'), $overrides);
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            }
        }

        return $query;
    };
@endphp
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
            <div class="panel-title">Daftar Mitra Driver</div>
            
            <form class="search-bar" style="width: 280px;" method="GET" action="{{ route('admin.drivers.index') }}">
                <input type="hidden" name="status" value="{{ $selectedStatus }}">
                <i class='bx bx-search'></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="Cari Nama, Plat Nomor, atau HP..." style="width: 100%;">
            </form>
        </div>

        <div class="tabs">
            @foreach($statusFilters as $statusKey => $statusConfig)
                <a href="{{ route('admin.drivers.index', $buildQuery(['status' => $statusKey])) }}" class="tab-btn {{ $selectedStatus === $statusKey ? 'active' : '' }}" style="text-decoration:none;">
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
                    <th>Pendapatan & Potongan</th>
                    <th>Status Akun</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($drivers as $driver)
                    @php
                        $status = $driver->admin_status ?? ['label' => 'Offline', 'class' => 'badge-info'];
                        $avatarUrl = $driver->admin_avatar_url;
                        $income = $driver->admin_income_summary ?? [
                            'gross_income' => 0,
                            'admin_fee_percent' => $selectedAdminFeePercent,
                            'admin_fee' => 0,
                            'net_income' => 0,
                            'order_count' => 0,
                        ];
                    @endphp
                    <tr>
                        <td data-label="Profil Driver" class="mobile-card-primary">
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
                        <td data-label="Kendaraan">
                            <span class="td-strong">{{ $driver->vehicle_plate }}</span>
                        </td>
                        <td data-label="Performa">
                            <span class="td-strong">{{ $driver->orders_count ?? 0 }} order terkait</span>
                            <span class="td-sub" style="display:block;">Rating driver tidak digunakan</span>
                        </td>
                        <td data-label="Pendapatan">
                            <span class="td-price">{{ $formatCurrency($income['net_income'] ?? 0) }}</span>
                            <span class="td-sub" style="display:block;">Bruto {{ $formatCurrency($income['gross_income'] ?? 0) }}</span>
                            <span class="td-sub" style="display:block;">Potongan {{ $formatPercent($income['admin_fee_percent'] ?? 0) }}: {{ $formatCurrency($income['admin_fee'] ?? 0) }}</span>
                        </td>
                        <td data-label="Status Akun"><span class="badge {{ $status['class'] }}">{{ $status['label'] }}</span></td>
                        <td class="td-action" data-label="Aksi">
                            <div style="display:flex; gap: 8px;">
                                <a href="{{ route('admin.drivers.show', ['driver' => $driver->id]) }}" class="btn-action detail" title="Lihat Profil Lengkap" aria-label="Lihat profil lengkap {{ $driver->user->name ?? 'driver' }}"><i class='bx bx-id-card'></i></a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="td-sub" style="text-align:center; padding:24px;">Belum ada data driver.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    
    <x-admin-pagination :paginator="$drivers" label="driver" />
</div>
@endsection
