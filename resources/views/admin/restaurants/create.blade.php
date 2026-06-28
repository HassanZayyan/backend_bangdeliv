@extends('layouts.admin')

@section('title', 'Tambah Restoran - Admin BangDeliv')
@section('page-title', 'Tambah Mitra Restoran')

@section('content')

{{-- Flash Error --}}
@if ($errors->any())
    <div class="panel" style="margin-bottom: 16px; padding: 14px 20px; border-left: 4px solid var(--color-danger);">
        <div style="display:flex; align-items:center; gap:10px; color:var(--color-danger); font-weight:600; margin-bottom:8px;">
            <i class='bx bx-error-circle' style="font-size:20px;"></i> Terdapat kesalahan pada form
        </div>
        <ul style="list-style:none; display:flex; flex-direction:column; gap:4px;">
            @foreach ($errors->all() as $error)
                <li style="font-size:13px; color:var(--text-muted);"><i class='bx bx-chevron-right' style="color:var(--color-danger);"></i> {{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="panel">
    <div class="panel-header">
        <div style="display:flex; align-items:center; gap:12px;">
            <div style="width:36px; height:36px; background:rgba(240,91,36,0.1); border-radius:8px; display:flex; align-items:center; justify-content:center; color:var(--color-primary); font-size:20px;">
                <i class='bx bx-store-alt'></i>
            </div>
            <div>
                <div class="panel-title">Form Tambah Restoran</div>
                <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">Isi data lengkap mitra restoran / warung baru</div>
            </div>
        </div>
        <a href="{{ route('admin.restaurants.index') }}" class="btn" style="background:var(--bg-hover); color:var(--text-muted); text-decoration:none;">
            <i class='bx bx-arrow-back'></i> Kembali
        </a>
    </div>

    <form action="{{ route('admin.restaurants.store') }}" method="POST" enctype="multipart/form-data" style="padding: 24px;">
        @csrf

        {{-- SECTION: Informasi Dasar --}}
        <div style="margin-bottom: 24px;">
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:1px; font-weight:600; color:var(--text-muted); margin-bottom:16px; padding-bottom:8px; border-bottom:1px solid var(--border-color);">
                <i class='bx bx-info-circle'></i> Informasi Dasar
            </div>
            <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Nama Restoran <span style="color:var(--color-danger);">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}" class="form-control" placeholder="Contoh: Warung Makan Pak Budi" required>
                    @error('name') <div style="color:var(--color-danger); font-size:12px; margin-top:4px;"><i class='bx bx-error-circle'></i> {{ $message }}</div> @enderror
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label>Tipe Merchant <span style="color:var(--color-danger);">*</span></label>
                    <select name="merchant_type" class="form-control" required>
                        <option value="restaurant" @selected(old('merchant_type', 'restaurant') === 'restaurant')>Restoran</option>
                        <option value="warung" @selected(old('merchant_type') === 'warung')>Warung</option>
                        <option value="convenience_store" @selected(old('merchant_type') === 'convenience_store')>Minimarket</option>
                        <option value="other" @selected(old('merchant_type') === 'other')>Lainnya</option>
                    </select>
                    @error('merchant_type') <div style="color:var(--color-danger); font-size:12px; margin-top:4px;"><i class='bx bx-error-circle'></i> {{ $message }}</div> @enderror
                </div>

                <div class="form-group" style="margin-bottom:0; grid-column: span 2;">
                    <label>Alamat Lengkap <span style="color:var(--color-danger);">*</span></label>
                    <textarea name="address" rows="2" class="form-control" placeholder="Jl. Contoh No. 1, Kelurahan, Kecamatan, Kota" required>{{ old('address') }}</textarea>
                    @error('address') <div style="color:var(--color-danger); font-size:12px; margin-top:4px;"><i class='bx bx-error-circle'></i> {{ $message }}</div> @enderror
                </div>

                <div class="form-group" style="margin-bottom:0; grid-column: span 2;">
                    <label>Nomor Telepon <span style="color:var(--color-danger);">*</span></label>
                    <input type="text" name="phone" value="{{ old('phone') }}" class="form-control" placeholder="Contoh: 081234567890" required>
                    @error('phone') <div style="color:var(--color-danger); font-size:12px; margin-top:4px;"><i class='bx bx-error-circle'></i> {{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        {{-- SECTION: Gambar Utama --}}
        <div style="margin-bottom: 24px;">
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:1px; font-weight:600; color:var(--text-muted); margin-bottom:16px; padding-bottom:8px; border-bottom:1px solid var(--border-color);">
                <i class='bx bx-image'></i> Gambar Utama
            </div>
            <div style="display:grid; grid-template-columns:minmax(0, 180px) minmax(0, 1fr); gap:16px; align-items:start;">
                <div class="restaurant-banner-preview is-empty" data-banner-preview>
                    <div class="restaurant-banner-placeholder">
                        <i class='bx bx-image'></i>
                        <span data-banner-placeholder-text>Belum ada gambar</span>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label>Banner Restoran</label>
                    <input type="file" name="banner_image" class="form-control" accept="image/jpeg,image/png,image/webp" data-banner-input>
                    <small class="form-help">Format JPG, PNG, atau WEBP. Maksimal 2 MB.</small>
                    @error('banner_image') <div style="color:var(--color-danger); font-size:12px; margin-top:4px;"><i class='bx bx-error-circle'></i> {{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        {{-- SECTION: Koordinat Lokasi --}}
        <div style="margin-bottom: 24px;">
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:1px; font-weight:600; color:var(--text-muted); margin-bottom:16px; padding-bottom:8px; border-bottom:1px solid var(--border-color);">
                <i class='bx bx-map-pin'></i> Koordinat Lokasi
            </div>
            <div style="display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Latitude <span style="font-size:12px; font-weight:400; color:var(--text-muted);">(-90 s/d 90)</span> <span style="color:var(--color-danger);">*</span></label>
                    <input id="latitude" type="text" name="latitude" value="{{ old('latitude') }}" class="form-control" placeholder="Contoh: -7.33158552" required>
                    @error('latitude') <div style="color:var(--color-danger); font-size:12px; margin-top:4px;"><i class='bx bx-error-circle'></i> {{ $message }}</div> @enderror
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label>Longitude <span style="font-size:12px; font-weight:400; color:var(--text-muted);">(-180 s/d 180)</span> <span style="color:var(--color-danger);">*</span></label>
                    <input id="longitude" type="text" name="longitude" value="{{ old('longitude') }}" class="form-control" placeholder="Contoh: 110.50229678" required>
                    @error('longitude') <div style="color:var(--color-danger); font-size:12px; margin-top:4px;"><i class='bx bx-error-circle'></i> {{ $message }}</div> @enderror
                </div>

                <div style="grid-column: span 2; display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:var(--bg-body); border:1px solid var(--border-color); border-radius:8px; padding:12px 16px;">
                    <button type="button" id="btn-detect-location" class="btn btn-primary" style="font-size:13px;">
                        <i class='bx bx-current-location'></i> Gunakan Lokasi Saat Ini
                    </button>
                    <button type="button" id="btn-swap-coordinates" class="btn" style="background:var(--bg-card); border:1px solid var(--border-color); font-size:13px;">
                        <i class='bx bx-transfer'></i> Tukar Lat/Lng
                    </button>
                    <span style="font-size:12px; color:var(--text-muted);">
                        <i class='bx bx-info-circle'></i> Paste dari Google Maps boleh pakai koma—sistem akan normalisasi otomatis.
                    </span>
                </div>
            </div>
        </div>

        {{-- ACTION BUTTONS --}}
        <div style="display:flex; justify-content:flex-end; gap:10px; padding-top:20px; border-top:1px solid var(--border-color);">
            <a href="{{ route('admin.restaurants.index') }}" class="btn" style="background:var(--bg-hover); color:var(--text-muted); text-decoration:none;">
                Batal
            </a>
            <button type="submit" class="btn btn-primary">
                <i class='bx bx-save'></i> Simpan Restoran
            </button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
@include('admin.restaurants.partials.banner-preview-script')
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

        const originalText = detectBtn.innerHTML;
        detectBtn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Mendeteksi...";
        detectBtn.disabled = true;

        navigator.geolocation.getCurrentPosition(function (position) {
            latInput.value = position.coords.latitude.toFixed(8);
            lngInput.value = position.coords.longitude.toFixed(8);
            detectBtn.innerHTML = "<i class='bx bx-check'></i> Lokasi Terdeteksi";
            setTimeout(() => {
                detectBtn.innerHTML = originalText;
                detectBtn.disabled = false;
            }, 2000);
        }, function () {
            alert('Lokasi gagal diambil. Pastikan izin lokasi diaktifkan.');
            detectBtn.innerHTML = originalText;
            detectBtn.disabled = false;
        }, {
            enableHighAccuracy: true,
            timeout: 10000,
        });
    });
});
</script>
@endpush
