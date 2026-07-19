@extends('layouts.admin')

@section('title', 'Kelola Menu - Admin Pelanggan 15')
@section('page-title', 'Kelola Menu '.$restaurant->name)

@section('content')
@if(session('success'))
    <div class="panel" style="margin-bottom: 12px; padding: 12px 16px; color: var(--color-success); font-weight: 600;">
        {{ session('success') }}
    </div>
@endif

@if($errors->any())
    <div class="panel" style="margin-bottom: 12px; padding: 12px 16px; color: var(--color-danger); font-weight: 600; border-left:4px solid var(--color-danger);">
        <div style="margin-bottom:8px;"><i class='bx bx-error-circle'></i> Terdapat kesalahan pada data menu yang diisi</div>
        <ul style="margin:0; padding-left:18px; color:var(--text-muted); font-weight:500; font-size:13px;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="panel" style="margin-bottom: 16px;">
    <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
        <div>
            <div class="panel-title">Kelola Menu Restoran</div>
            <div style="font-size:13px; color:var(--text-muted); margin-top:4px;">{{ $restaurant->name }} | {{ $menus->count() }} menu ditampilkan</div>
        </div>
        <a href="{{ route('admin.restaurants.index') }}" class="btn" style="background:var(--bg-hover); color:var(--text-muted); text-decoration:none;">
            <i class='bx bx-arrow-back'></i> Kembali
        </a>
    </div>
</div>

<div class="dashboard-grid" style="grid-template-columns: minmax(320px, 360px) minmax(0, 1fr); align-items:start;">
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title">Tambah Menu Baru</div>
        </div>
        <form action="{{ route('admin.restaurants.menus.store', $restaurant) }}" method="POST" style="padding:20px;">
            @csrf
            <div class="form-group" style="margin-bottom:14px;">
                <label>Nama Menu</label>
                <input type="text" name="name" value="{{ old('name') }}" class="form-control" required>
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label>Harga</label>
                <input type="number" step="0.01" min="0" name="price" value="{{ old('price') }}" class="form-control">
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label>Urutan</label>
                <input type="number" min="0" name="sort_order" value="{{ old('sort_order', 0) }}" class="form-control">
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label>Status</label>
                <select name="is_available" class="form-control" required>
                    <option value="1" @selected(old('is_available', '1') == '1')>Tersedia</option>
                    <option value="0" @selected(old('is_available') === '0')>Tidak Tersedia</option>
                </select>
            </div>
            <div style="display:flex; justify-content:flex-end;">
                <button type="submit" class="btn btn-primary">Tambah Menu</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div class="panel-title">Daftar Menu</div>
            <span class="badge badge-info">Total {{ $menus->count() }}</span>
        </div>

        <div class="table-responsive">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Menu</th>
                        <th>Harga</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($menus as $menu)
                        <tr>
                            <td data-label="Menu" class="mobile-card-primary">
                                <div class="td-user">
                                    <span class="td-strong">{{ $menu->name }}</span>
                                    <span class="td-sub">Urutan {{ (int) $menu->sort_order }}</span>
                                </div>
                            </td>
                            <td data-label="Harga">
                                @if($menu->price === null)
                                    <span class="td-sub">Harga belum diisi</span>
                                @else
                                    <span class="td-strong" style="color:var(--color-primary);">Rp {{ number_format((float) $menu->price, 0, ',', '.') }}</span>
                                @endif
                            </td>
                            <td data-label="Status">
                                <span class="badge {{ $menu->is_available ? 'badge-success' : 'badge-danger' }}">{{ $menu->is_available ? 'Tersedia' : 'Tidak Tersedia' }}</span>
                            </td>
                            <td class="td-action" data-label="Aksi">
                                <div style="display:flex; align-items:flex-start; gap:8px; flex-wrap:wrap;">
                                    <button
                                        type="button"
                                        class="btn-action detail js-edit-menu-btn"
                                        title="Ubah Menu"
                                        data-update-url="{{ route('admin.restaurants.menus.update', [$restaurant, $menu]) }}"
                                        data-name="{{ $menu->name }}"
                                        data-price="{{ $menu->price === null ? '' : $menu->price }}"
                                        data-sort-order="{{ $menu->sort_order }}"
                                        data-is-available="{{ (int) $menu->is_available }}"
                                    >
                                        <i class='bx bx-edit'></i>
                                    </button>
                                    <form action="{{ route('admin.restaurants.menus.destroy', [$restaurant, $menu]) }}" method="POST" onsubmit="return confirm('Hapus menu ini?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-action danger" title="Hapus Menu"><i class='bx bx-trash'></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="td-sub" style="text-align:center; padding:24px;">Belum ada menu untuk restoran ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<x-menu-edit-modal title="Ubah Menu" />
@endsection
