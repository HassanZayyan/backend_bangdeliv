@extends('layouts.admin')

@section('title', 'Edit Restoran - Admin BangDeliv')
@section('page-title', 'Edit Mitra Restoran')

@section('content')
<div class="panel" style="max-width: 980px;">
    <div class="panel-header">
        <div class="panel-title">Form Edit Restoran</div>
    </div>

    <form action="{{ route('admin.restaurants.update', $restaurant) }}" method="POST" style="padding:20px; display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px;">
        @csrf
        @method('PUT')

        <div>
            <label>Nama Restoran</label>
            <input type="text" name="name" value="{{ old('name', $restaurant->name) }}" class="input" required>
            @error('name') <div style="color:#ef4444; font-size:12px;">{{ $message }}</div> @enderror
        </div>

        <div>
            <label>Slug (opsional)</label>
            <input type="text" name="slug" value="{{ old('slug', $restaurant->slug) }}" class="input">
            @error('slug') <div style="color:#ef4444; font-size:12px;">{{ $message }}</div> @enderror
        </div>

        <div style="grid-column: span 2;">
            <label>Alamat</label>
            <textarea name="address" rows="2" class="input" required>{{ old('address', $restaurant->address) }}</textarea>
            @error('address') <div style="color:#ef4444; font-size:12px;">{{ $message }}</div> @enderror
        </div>

        <div>
            <label>Telepon</label>
            <input type="text" name="phone" value="{{ old('phone', $restaurant->phone) }}" class="input" required>
            @error('phone') <div style="color:#ef4444; font-size:12px;">{{ $message }}</div> @enderror
        </div>

        <div>
            <label>Estimasi Prep (menit)</label>
            <input type="number" min="1" max="240" name="estimated_prep_time" value="{{ old('estimated_prep_time', $restaurant->estimated_prep_time) }}" class="input" required>
            @error('estimated_prep_time') <div style="color:#ef4444; font-size:12px;">{{ $message }}</div> @enderror
        </div>

        <div>
            <label>Latitude (-90 s/d 90)</label>
            <input id="latitude" type="text" name="latitude" value="{{ old('latitude', $restaurant->latitude) }}" class="input" placeholder="Contoh: -7.33158552" required>
            @error('latitude') <div style="color:#ef4444; font-size:12px;">{{ $message }}</div> @enderror
        </div>

        <div>
            <label>Longitude (-180 s/d 180)</label>
            <input id="longitude" type="text" name="longitude" value="{{ old('longitude', $restaurant->longitude) }}" class="input" placeholder="Contoh: 110.50229678" required>
            @error('longitude') <div style="color:#ef4444; font-size:12px;">{{ $message }}</div> @enderror
        </div>

        <div style="grid-column: span 2; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <button type="button" id="btn-detect-location" class="btn" style="background: var(--bg-hover);">Gunakan Lokasi Saat Ini</button>
            <button type="button" id="btn-swap-coordinates" class="btn" style="background: var(--bg-hover);">Tukar Latitude/Longitude</button>
            <span style="font-size:12px; color: var(--text-muted);">Tip: Sistem akan auto normalisasi format koma dan deteksi jika koordinat tertukar.</span>
        </div>

        <div>
            <label>Status</label>
            <select name="status" class="input" required>
                <option value="active" @selected(old('status', $restaurant->status) === 'active')>Active</option>
                <option value="inactive" @selected(old('status', $restaurant->status) === 'inactive')>Inactive</option>
            </select>
            @error('status') <div style="color:#ef4444; font-size:12px;">{{ $message }}</div> @enderror
        </div>

        <div style="grid-column: span 2;">
            <label>Deskripsi</label>
            <textarea name="description" rows="3" class="input">{{ old('description', $restaurant->description) }}</textarea>
            @error('description') <div style="color:#ef4444; font-size:12px;">{{ $message }}</div> @enderror
        </div>

        <div style="grid-column: span 2; display:flex; justify-content:flex-end; gap:10px;">
            <a href="{{ route('admin.restaurants.index') }}" class="btn" style="text-decoration:none;">Batal</a>
            <button type="submit" class="btn btn-primary">Update</button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const detectBtn = document.getElementById('btn-detect-location');
    const swapBtn = document.getElementById('btn-swap-coordinates');

    const normalize = (value) => (value || '').toString().trim().replace(',', '.');

    const normalizeInputs = () => {
        latInput.value = normalize(latInput.value);
        lngInput.value = normalize(lngInput.value);
    };

    latInput.addEventListener('blur', normalizeInputs);
    lngInput.addEventListener('blur', normalizeInputs);

    swapBtn.addEventListener('click', function () {
        normalizeInputs();
        const tmp = latInput.value;
        latInput.value = lngInput.value;
        lngInput.value = tmp;
    });

    detectBtn.addEventListener('click', function () {
        if (!navigator.geolocation) {
            alert('Browser tidak mendukung geolocation.');
            return;
        }

        navigator.geolocation.getCurrentPosition(function (position) {
            latInput.value = position.coords.latitude.toFixed(8);
            lngInput.value = position.coords.longitude.toFixed(8);
        }, function () {
            alert('Lokasi gagal diambil. Pastikan izin lokasi diaktifkan.');
        }, {
            enableHighAccuracy: true,
            timeout: 10000,
        });
    });
});
</script>
@endpush
