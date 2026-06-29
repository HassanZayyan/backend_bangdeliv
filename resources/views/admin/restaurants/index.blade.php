@extends('layouts.admin')

@section('title', 'Restoran / Warung - Admin Pelanggan 15')
@section('page-title', 'Mitra Restoran')

@section('content')
@if(session('success'))
    <div class="panel" style="margin-bottom: 10px; padding: 12px 14px; color: var(--color-success); font-weight: 600;">
        {{ session('success') }}
    </div>
@endif

<section class="panel">
    <div class="panel-header stack">
        <div class="toolbar-row">
            <div>
                <div class="panel-title">Daftar Mitra Restoran & Warung</div>
                <div class="panel-description">Kelola data merchant dan katalog menu.</div>
            </div>

            <div class="page-actions">
                <a href="{{ route('admin.restaurants.create') }}" class="btn btn-primary" style="text-decoration:none;">
                    <i class="bx bx-plus" aria-hidden="true"></i> Tambah Mitra
                </a>
                <form method="GET" action="{{ route('admin.restaurants.index') }}" class="search-bar">
                    <i class="bx bx-search" aria-hidden="true"></i>
                    <input type="text" name="q" value="{{ $search }}" placeholder="Cari resto, alamat, HP..." aria-label="Cari restoran">
                </form>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Info Restoran</th>
                    <th>Detail Menu</th>
                    <th>Rating & Omset</th>
                    <th class="td-action">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($restaurants as $restaurant)
                    @php
                        $bannerUrl = $restaurant->admin_banner_url;
                    @endphp
                    <tr>
                        <td data-label="Info Restoran" class="mobile-card-primary">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div class="admin-thumbnail restaurant-thumbnail {{ $bannerUrl ? 'has-image' : 'is-fallback' }}">
                                    @if($bannerUrl)
                                        <img
                                            src="{{ $bannerUrl }}"
                                            alt="Foto {{ $restaurant->name }}"
                                            class="admin-thumbnail-image"
                                            loading="lazy"
                                            onerror="this.parentElement.classList.remove('has-image'); this.parentElement.classList.add('is-fallback'); this.remove();"
                                        >
                                    @endif
                                    <span class="admin-thumbnail-fallback">
                                        <i class="bx bx-restaurant" aria-hidden="true"></i>
                                    </span>
                                </div>
                                <div class="td-user">
                                    <span class="td-strong">{{ $restaurant->name }}</span>
                                    <span class="td-sub"><i class="bx bx-phone" aria-hidden="true"></i> {{ $restaurant->phone ?: '-' }}</span>
                                    <span class="td-sub"><i class="bx bx-map-pin" aria-hidden="true"></i> {{ $restaurant->address }}</span>
                                </div>
                            </div>
                        </td>
                        <td data-label="Detail Menu">
                            <span class="td-strong">{{ $restaurant->menus_count }} Item Menu</span>
                            <span class="td-sub" style="display:block;"><i class="bx bx-category" aria-hidden="true"></i> {{ ucfirst(str_replace('_', ' ', $restaurant->merchant_type)) }}</span>
                        </td>
                        <td data-label="Rating & Omset">
                            <span class="td-strong">{{ $restaurant->orders_count }} pesanan</span>
                            <span class="td-sub" style="display:block;">Total order terkait merchant</span>
                        </td>
                        <td class="td-action" data-label="Aksi">
                            <div style="display:flex; gap: 8px; justify-content:flex-end;">
                                <a href="{{ route('admin.restaurants.menus.index', $restaurant) }}" class="btn-action detail" title="Kelola menu" aria-label="Kelola menu {{ $restaurant->name }}">
                                    <i class="bx bx-food-menu" aria-hidden="true"></i>
                                </a>
                                <a href="{{ route('admin.restaurants.edit', $restaurant) }}" class="btn-action warning" style="text-decoration:none;" title="Edit restoran" aria-label="Edit restoran {{ $restaurant->name }}">
                                    <i class="bx bx-edit" aria-hidden="true"></i>
                                </a>
                                <form action="{{ route('admin.restaurants.destroy', $restaurant) }}" method="POST" onsubmit="return confirm('Hapus restoran ini?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-action danger" title="Hapus restoran" aria-label="Hapus restoran {{ $restaurant->name }}">
                                        <i class="bx bx-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="td-sub" style="text-align:center; padding:24px;">Belum ada data restoran.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-admin-pagination :paginator="$restaurants" label="mitra restoran" />
</section>
@endsection
