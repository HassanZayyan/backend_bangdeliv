@extends('layouts.admin')

@section('title', 'Restoran / Warung - Admin BangDeliv')
@section('page-title', 'Mitra Restoran')

@section('content')
@if(session('success'))
    <div class="panel" style="margin-bottom: 12px; padding: 12px 16px; color: var(--color-success); font-weight: 600;">
        {{ session('success') }}
    </div>
@endif
<div class="panel">
    <div class="panel-header" style="flex-direction: column; align-items: stretch; gap: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div class="panel-title">Daftar Mitra Restoran & Warung</div>
            
            <div style="display: flex; gap: 10px;">
                <a href="{{ route('admin.restaurants.create') }}" class="btn btn-primary" style="text-decoration:none;">
                    <i class='bx bx-plus'></i> Tambah Mitra
                </a>
                <form method="GET" action="{{ route('admin.restaurants.index') }}" class="search-bar" style="width: 280px;">
                    <input type="hidden" name="status" value="{{ $statusFilter }}">
                    <i class='bx bx-search'></i>
                    <input type="text" name="q" value="{{ $search }}" placeholder="Cari Resto, Owner, ID..." style="width: 100%;">
                </form>
            </div>
        </div>

        <div class="tabs">
            <a href="{{ route('admin.restaurants.index', ['status' => 'all', 'q' => $search]) }}" class="tab-btn {{ $statusFilter === 'all' ? 'active' : '' }}" style="text-decoration:none;">Semua</a>
            <a href="{{ route('admin.restaurants.index', ['status' => 'active', 'q' => $search]) }}" class="tab-btn {{ $statusFilter === 'active' ? 'active' : '' }}" style="text-decoration:none;">Buka <span class="badge badge-success" style="margin-left:5px;">{{ $openCount }}</span></a>
            <a href="{{ route('admin.restaurants.index', ['status' => 'closed', 'q' => $search]) }}" class="tab-btn {{ $statusFilter === 'closed' ? 'active' : '' }}" style="text-decoration:none;">Tutup (Luar Jam) <span class="badge badge-info" style="margin-left:5px;">{{ $closedCount }}</span></a>
            <a href="{{ route('admin.restaurants.index', ['status' => 'inactive', 'q' => $search]) }}" class="tab-btn {{ $statusFilter === 'inactive' ? 'active' : '' }}" style="text-decoration:none;">Suspended <span class="badge badge-danger" style="margin-left:5px;">{{ $suspendedCount }}</span></a>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Info Restoran</th>
                    <th>Detail Menu</th>
                    <th>Rating & Omset</th>
                    <th>Status Live</th>
                    <th>Aksi & Kelola Menu</th>
                </tr>
            </thead>
            <tbody>
                @forelse($restaurants as $restaurant)
                    @php
                        $isActive = $restaurant->status === 'active';
                    @endphp
                    <tr @if(!$isActive) style="background-color: rgba(239, 68, 68, 0.02);" @endif>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="width: 50px; height: 50px; border-radius: 10px; background-color: {{ $isActive ? 'var(--color-primary)' : 'var(--color-danger)' }}; display: flex; align-items:center; justify-content:center; color:white; font-size:24px; flex-shrink:0;">
                                    <i class='bx bx-restaurant'></i>
                                </div>
                                <div class="td-user">
                                    <span class="td-strong">{{ $restaurant->name }}</span>
                                    <span class="td-sub"><i class='bx bx-phone'></i> {{ $restaurant->phone }}</span>
                                    <span class="td-sub"><i class='bx bx-map-pin'></i> {{ $restaurant->address }}</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="td-strong">{{ $restaurant->menus_count }} Item Menu</span>
                            <span class="td-sub" style="display:block;"><i class='bx bx-time'></i> Est. Prep {{ $restaurant->estimated_prep_time }} menit</span>
                        </td>
                        <td>
                            <span class="td-strong" style="color:var(--color-warning);"><i class='bx bxs-star'></i> {{ number_format((float) $restaurant->avg_rating, 1) }}</span>
                            <span class="td-sub" style="display:block;">{{ $restaurant->orders_count }} pesanan</span>
                        </td>
                        <td>
                            <span class="badge {{ $isActive ? 'badge-success' : 'badge-danger' }}">{{ $isActive ? 'Buka' : 'Suspended' }}</span>
                        </td>
                        <td class="td-action">
                            <div style="display:flex; gap: 8px;">
                                <a href="{{ route('admin.restaurants.menus.index', $restaurant) }}" class="btn-action detail" style="width:auto; padding:0 12px; font-size:13px; font-weight:600; color:var(--color-primary); background:rgba(255,119,0,0.1); text-decoration:none; display:inline-flex; align-items:center;" title="Kelola Katalog Menu"><i class='bx bx-food-menu' style="margin-right:5px;"></i> Kelola Menu</a>
                                <a href="{{ route('admin.restaurants.edit', $restaurant) }}" class="btn-action" style="background: rgba(59,130,246,.1); color: #3b82f6; text-decoration:none;" title="Edit Restoran"><i class='bx bx-edit'></i></a>
                                <form action="{{ route('admin.restaurants.toggle-status', $restaurant) }}" method="POST">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-action" style="background: rgba(16,185,129,.1); color: var(--color-success);" title="Ubah Status"><i class='bx bx-refresh'></i></button>
                                </form>
                                <form action="{{ route('admin.restaurants.destroy', $restaurant) }}" method="POST" onsubmit="return confirm('Hapus restoran ini?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-action danger" title="Hapus Restoran"><i class='bx bx-trash'></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="td-sub" style="text-align:center; padding:24px;">Belum ada data restoran.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    
    <div class="panel-pagination" style="padding: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <span style="font-size: 13px; color: var(--text-muted); font-weight: 500;">Menampilkan {{ $restaurants->count() }} dari {{ $restaurants->total() }} Mitra Restoran</span>
        <div class="pagination-controls" style="display: flex; gap: 6px;">
            {{ $restaurants->links() }}
        </div>
    </div>
</div>
@endsection
