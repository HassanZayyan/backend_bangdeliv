@extends('layouts.admin')

@section('title', 'Kelola Menu - Admin BangDeliv')
@section('page-title', 'Kelola Menu '.$restaurant->name)

@section('content')
@if(session('success'))
    <div class="panel" style="margin-bottom: 12px; padding: 12px 16px; color: var(--color-success); font-weight: 600;">
        {{ session('success') }}
    </div>
@endif

<div class="dashboard-grid" style="grid-template-columns: 360px 1fr;">
    <div class="panel">
        <div class="panel-header">
            <div class="panel-title">Tambah Menu Baru</div>
        </div>
        <form action="{{ route('admin.restaurants.menus.store', $restaurant) }}" method="POST" style="padding:20px; display:flex; flex-direction:column; gap:12px;">
            @csrf
            <div>
                <label>Nama Menu</label>
                <input type="text" name="name" value="{{ old('name') }}" class="input" required>
            </div>
            <div>
                <label>Harga</label>
                <input type="number" step="0.01" min="0" name="price" value="{{ old('price') }}" class="input" required>
            </div>
            <div>
                <label>Kategori</label>
                <select name="menu_category_id" class="input">
                    <option value="">Tanpa Kategori</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected(old('menu_category_id') == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Kategori Baru (opsional)</label>
                <input type="text" name="new_category_name" value="{{ old('new_category_name') }}" class="input" placeholder="Contoh: Paket Hemat">
            </div>
            <div>
                <label>Urutan</label>
                <input type="number" min="0" name="sort_order" value="{{ old('sort_order', 0) }}" class="input">
            </div>
            <div>
                <label>Status</label>
                <select name="is_available" class="input" required>
                    <option value="1" @selected(old('is_available', '1') == '1')>Tersedia</option>
                    <option value="0" @selected(old('is_available') === '0')>Tidak Tersedia</option>
                </select>
            </div>
            <div>
                <label>Deskripsi</label>
                <textarea name="description" rows="3" class="input">{{ old('description') }}</textarea>
            </div>
            <div style="display:flex; justify-content:flex-end;">
                <button type="submit" class="btn btn-primary">Tambah Menu</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
            <div class="panel-title">Daftar Menu</div>
            <a href="{{ route('admin.restaurants.index') }}" class="btn" style="text-decoration:none;">Kembali</a>
        </div>

        <div class="table-responsive">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Menu</th>
                        <th>Kategori</th>
                        <th>Harga</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($menus as $menu)
                        <tr>
                            <td>
                                <div class="td-user">
                                    <span class="td-strong">{{ $menu->name }}</span>
                                    <span class="td-sub">{{ $menu->description ?: '-' }}</span>
                                </div>
                            </td>
                            <td>{{ $menu->category?->name ?? '-' }}</td>
                            <td>Rp {{ number_format((float) $menu->price, 0, ',', '.') }}</td>
                            <td>
                                <span class="badge {{ $menu->is_available ? 'badge-success' : 'badge-danger' }}">{{ $menu->is_available ? 'Tersedia' : 'Tidak Tersedia' }}</span>
                            </td>
                            <td>
                                <details>
                                    <summary class="btn-action detail" style="display:inline-flex; align-items:center; cursor:pointer;">Edit</summary>
                                    <form action="{{ route('admin.restaurants.menus.update', [$restaurant, $menu]) }}" method="POST" style="margin-top:10px; display:grid; gap:8px; min-width:260px;">
                                        @csrf
                                        @method('PUT')
                                        <input type="text" name="name" class="input" value="{{ $menu->name }}" required>
                                        <input type="number" step="0.01" min="0" name="price" class="input" value="{{ $menu->price }}" required>
                                        <select name="menu_category_id" class="input">
                                            <option value="">Tanpa Kategori</option>
                                            @foreach($categories as $category)
                                                <option value="{{ $category->id }}" @selected($menu->menu_category_id == $category->id)>{{ $category->name }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" name="new_category_name" class="input" placeholder="Kategori baru (opsional)">
                                        <input type="number" min="0" name="sort_order" class="input" value="{{ $menu->sort_order }}">
                                        <select name="is_available" class="input" required>
                                            <option value="1" @selected((int)$menu->is_available === 1)>Tersedia</option>
                                            <option value="0" @selected((int)$menu->is_available === 0)>Tidak Tersedia</option>
                                        </select>
                                        <textarea name="description" class="input" rows="2">{{ $menu->description }}</textarea>
                                        <button type="submit" class="btn btn-primary">Simpan</button>
                                    </form>
                                </details>
                                <form action="{{ route('admin.restaurants.menus.destroy', [$restaurant, $menu]) }}" method="POST" onsubmit="return confirm('Hapus menu ini?');" style="margin-top:8px;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-action danger"><i class='bx bx-trash'></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="td-sub" style="text-align:center; padding:24px;">Belum ada menu untuk restoran ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
