@extends('layouts.admin')

@section('title', 'Pengaturan - Admin BangDeliv')
@section('page-title', 'Pengaturan Sistem')

@section('content')
<div style="display: flex; flex-direction: column; gap: 24px;">

    {{-- =====================================================
         SEKSI 1: KONFIGURASI TARIF ONGKOS KIRIM
         ===================================================== --}}
    <div class="panel">
        <div class="panel-header">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(240,91,36,0.1); color: var(--color-primary); display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    <i class='bx bx-money-withdraw'></i>
                </div>
                <div>
                    <div class="panel-title">Konfigurasi Tarif Ongkos Kirim</div>
                    <div style="font-size: 12px; color: var(--text-muted);">Tarif dihitung otomatis: <code style="background:var(--bg-body); padding: 2px 6px; border-radius:4px; font-size:11px;">Ongkir = Tarif Dasar + (Jarak km × Tarif/km)</code></div>
                </div>
            </div>
            <button class="btn btn-primary" id="saveTarifBtn" onclick="showSaved('tarif')">
                <i class='bx bx-save'></i> Simpan Tarif
            </button>
        </div>
        <div style="padding: 24px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
            {{-- Tarif Dasar --}}
            <div class="form-group" style="margin: 0;">
                <label for="tarif_dasar">
                    <i class='bx bx-flag' style="color:var(--color-primary);"></i> Tarif Dasar (Biaya Minimum)
                </label>
                <div style="position: relative; display: flex; align-items: center;">
                    <span style="position: absolute; left: 14px; font-size: 14px; color: var(--text-muted); font-weight: 600; pointer-events:none;">Rp</span>
                    <input id="tarif_dasar" type="number" class="form-control" value="5000" style="padding-left: 40px;" placeholder="Contoh: 5000">
                </div>
                <p style="font-size: 11px; color: var(--text-muted); margin-top: 5px;">Dikenakan untuk jarak ≤ 2 km pertama</p>
            </div>

            {{-- Tarif Per KM --}}
            <div class="form-group" style="margin: 0;">
                <label for="tarif_per_km">
                    <i class='bx bx-cycling' style="color:var(--color-info);"></i> Tarif per Kilometer
                </label>
                <div style="position: relative; display: flex; align-items: center;">
                    <span style="position: absolute; left: 14px; font-size: 14px; color: var(--text-muted); font-weight: 600; pointer-events:none;">Rp</span>
                    <input id="tarif_per_km" type="number" class="form-control" value="2500" style="padding-left: 40px;" placeholder="Contoh: 2500">
                </div>
                <p style="font-size: 11px; color: var(--text-muted); margin-top: 5px;">Dikenakan per km tambahan setelah 2 km pertama</p>
            </div>

            {{-- Batas Jarak --}}
            <div class="form-group" style="margin: 0;">
                <label for="batas_jarak">
                    <i class='bx bx-map-alt' style="color:var(--color-danger);"></i> Batas Radius Maksimum
                </label>
                <div style="position: relative; display: flex; align-items: center;">
                    <input id="batas_jarak" type="number" class="form-control" value="15" style="padding-right: 50px;" placeholder="Contoh: 15">
                    <span style="position: absolute; right: 14px; font-size: 14px; color: var(--text-muted); font-weight: 600; pointer-events:none;">km</span>
                </div>
                <p style="font-size: 11px; color: var(--text-muted); margin-top: 5px;">Pesanan di luar radius ini akan ditolak otomatis</p>
            </div>
        </div>

        {{-- Estimator Preview --}}
        <div style="margin: 0 24px 24px; padding: 16px; background: var(--bg-body); border-radius: 10px; border: 1px dashed var(--border-color);">
            <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Kalkulator Tarif Estimasi</div>
            <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 13px; color: var(--text-muted);">Jarak contoh:</span>
                    <input id="sim_jarak" type="number" value="5" style="width: 70px; padding: 6px 10px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-size: 13px;" oninput="hitungEstimasi()">
                    <span style="font-size: 13px; color: var(--text-muted);">km</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 13px; color: var(--text-muted);">Estimasi ongkir:</span>
                    <span id="hasil_estimasi" style="font-size: 18px; font-weight: 800; color: var(--color-primary);">Rp 12.500</span>
                </div>
            </div>
        </div>
    </div>

    {{-- =====================================================
         SEKSI 2: BANNER & PENGUMUMAN DARURAT
         ===================================================== --}}
    <div class="panel">
        <div class="panel-header">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(245,158,11,0.1); color: var(--color-warning); display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    <i class='bx bx-bell'></i>
                </div>
                <div>
                    <div class="panel-title">Banner & Pengumuman Darurat</div>
                    <div style="font-size: 12px; color: var(--text-muted);">Banner driver sibuk berjalan secara otomatis. Override manual untuk kondisi darurat.</div>
                </div>
            </div>
            <button class="btn btn-primary" onclick="showSaved('banner')">
                <i class='bx bx-save'></i> Simpan Pengaturan
            </button>
        </div>
        <div style="padding: 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            {{-- Auto Banner Status --}}
            <div>
                <div style="font-size: 14px; font-weight: 600; margin-bottom: 16px; color: var(--text-main);">Status Otomatis Sistem</div>
                
                <div style="padding: 16px; border-radius: 10px; border: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <div>
                        <div style="font-size: 14px; font-weight: 600;">Deteksi Driver Sibuk Otomatis</div>
                        <div style="font-size: 12px; color: var(--text-muted);">Banner tampil di app Flutter jika 100% driver offline/busy</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" checked>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div style="padding: 12px 16px; background: rgba(16,185,129,0.08); border-radius: 8px; border-left: 3px solid var(--color-success); display: flex; align-items: center; gap: 8px;">
                    <i class='bx bx-check-circle' style="color: var(--color-success); font-size: 18px;"></i>
                    <div>
                        <div style="font-size: 13px; font-weight: 600; color: var(--color-success);">Sistem Berjalan Normal</div>
                        <div style="font-size: 11px; color: var(--text-muted);">8 driver aktif — banner tidak terpicu</div>
                    </div>
                </div>
            </div>

            {{-- Manual Override --}}
            <div>
                <div style="font-size: 14px; font-weight: 600; margin-bottom: 16px; color: var(--text-main);">Manual Override Darurat</div>
                
                <div style="padding: 16px; border-radius: 10px; border: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <div>
                        <div style="font-size: 14px; font-weight: 600;">Paksa Tampilkan Banner</div>
                        <div style="font-size: 12px; color: var(--text-muted);">Override manual — banner tampil meski ada driver tersedia</div>
                    </div>
                    <label class="toggle-switch">
                        <input type="checkbox" id="overrideToggle" onchange="toggleBannerWarning(this)">
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="form-group" style="margin: 0;">
                    <label for="banner_text">Teks Banner yang Ditampilkan di App</label>
                    <textarea id="banner_text" class="form-control" rows="3" placeholder="Contoh: Mohon maaf, seluruh driver BangDeliv sedang sangat sibuk. Silakan coba beberapa menit lagi.">Mohon maaf, seluruh driver BangDeliv sedang sangat sibuk. Silakan coba beberapa menit lagi.</textarea>
                </div>
            </div>
        </div>

        {{-- Warning saat override aktif --}}
        <div id="bannerWarning" style="display:none; margin: 0 24px 24px; padding: 14px 16px; background: rgba(239,68,68,0.08); border-radius: 10px; border-left: 3px solid var(--color-danger); display: none; align-items: center; gap: 10px;">
            <i class='bx bx-error' style="color: var(--color-danger); font-size: 20px; flex-shrink:0;"></i>
            <span style="font-size: 13px; color: var(--color-danger); font-weight: 500;">Override manual aktif! Banner darurat sedang ditampilkan di aplikasi Flutter meskipun ada driver yang tersedia.</span>
        </div>
    </div>

    {{-- =====================================================
         SEKSI 3: PROFIL & KEAMANAN ADMIN
         ===================================================== --}}
    <div class="panel">
        <div class="panel-header">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(59,130,246,0.1); color: var(--color-info); display: flex; align-items: center; justify-content: center; font-size: 20px;">
                    <i class='bx bx-user-circle'></i>
                </div>
                <div>
                    <div class="panel-title">Profil & Keamanan Akun Admin</div>
                    <div style="font-size: 12px; color: var(--text-muted);">Kelola identitas dan keamanan akun Anda</div>
                </div>
            </div>
        </div>
        <div style="padding: 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            {{-- Profile Info --}}
            <div>
                <div style="font-size: 14px; font-weight: 600; margin-bottom: 16px; color: var(--text-main);">Informasi Profil</div>
                <div class="form-group">
                    <label for="admin_name">Nama Tampilan</label>
                    <input id="admin_name" type="text" class="form-control" value="{{ Auth::user()->name ?? 'Super Admin' }}" placeholder="Nama Anda">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="admin_email">Alamat Email</label>
                    <input id="admin_email" type="email" class="form-control" value="{{ Auth::user()->email ?? 'admin@bangdeliv.com' }}" placeholder="email@bangdeliv.com">
                </div>
                <button class="btn btn-primary" style="margin-top: 20px;" onclick="showSaved('profil')">
                    <i class='bx bx-save'></i> Simpan Profil
                </button>
            </div>

            {{-- Change Password --}}
            <div>
                <div style="font-size: 14px; font-weight: 600; margin-bottom: 16px; color: var(--text-main);">Ganti Password</div>
                <div class="form-group">
                    <label for="old_password">Password Saat Ini</label>
                    <div style="position: relative;">
                        <input id="old_password" type="password" class="form-control" placeholder="Masukkan password lama" style="padding-right: 40px;">
                        <button onclick="togglePwd('old_password', this)" type="button" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:18px;">
                            <i class='bx bx-hide'></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="new_password">Password Baru</label>
                    <div style="position: relative;">
                        <input id="new_password" type="password" class="form-control" placeholder="Minimal 8 karakter" style="padding-right: 40px;">
                        <button onclick="togglePwd('new_password', this)" type="button" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:18px;">
                            <i class='bx bx-hide'></i>
                        </button>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="confirm_password">Konfirmasi Password Baru</label>
                    <div style="position: relative;">
                        <input id="confirm_password" type="password" class="form-control" placeholder="Ulangi password baru" style="padding-right: 40px;">
                        <button onclick="togglePwd('confirm_password', this)" type="button" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:18px;">
                            <i class='bx bx-hide'></i>
                        </button>
                    </div>
                </div>
                <button class="btn" style="margin-top: 20px; background: rgba(239,68,68,0.1); color: var(--color-danger); border: 1px solid rgba(239,68,68,0.2);" onclick="showSaved('password')">
                    <i class='bx bx-lock-open-alt'></i> Perbarui Password
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Kalkulator Tarif Estimasi (real-time)
function hitungEstimasi() {
    const tarifDasar = parseInt(document.getElementById('tarif_dasar').value) || 0;
    const tarifPerKm = parseInt(document.getElementById('tarif_per_km').value) || 0;
    const jarak = parseFloat(document.getElementById('sim_jarak').value) || 0;

    let total;
    if (jarak <= 2) {
        total = tarifDasar;
    } else {
        total = tarifDasar + ((jarak - 2) * tarifPerKm);
    }
    document.getElementById('hasil_estimasi').textContent = 'Rp ' + total.toLocaleString('id-ID');
}

// Update estimasi saat tarif berubah
['tarif_dasar', 'tarif_per_km'].forEach(id => {
    document.getElementById(id).addEventListener('input', hitungEstimasi);
});
hitungEstimasi(); // init

// Toggle password visibility
function togglePwd(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bx bx-show';
    } else {
        input.type = 'password';
        icon.className = 'bx bx-hide';
    }
}

// Banner override warning
function toggleBannerWarning(checkbox) {
    const warning = document.getElementById('bannerWarning');
    warning.style.display = checkbox.checked ? 'flex' : 'none';
}

// Save feedback toast
function showSaved(section) {
    const names = {
        tarif: 'Konfigurasi Tarif',
        banner: 'Pengaturan Banner',
        profil: 'Profil Admin',
        password: 'Password',
    };
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed; bottom: 30px; right: 30px; z-index: 9999;
        background: var(--color-success); color: white;
        padding: 14px 22px; border-radius: 10px; font-weight: 600; font-size: 14px;
        display: flex; align-items: center; gap: 10px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        animation: slideIn 0.3s ease;
    `;
    toast.innerHTML = `<i class='bx bx-check-circle' style="font-size:20px;"></i> ${names[section]} berhasil disimpan!`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.3s'; setTimeout(() => toast.remove(), 300); }, 2500);
}
</script>
<style>
@keyframes slideIn {
    from { transform: translateX(40px); opacity: 0; }
    to   { transform: translateX(0);    opacity: 1; }
}

/* Toggle Switch */
.toggle-switch {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 26px;
    flex-shrink: 0;
}
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: var(--border-color);
    border-radius: 26px;
    transition: 0.3s;
}
.toggle-slider:before {
    position: absolute;
    content: "";
    height: 20px; width: 20px;
    left: 3px; bottom: 3px;
    background-color: white;
    border-radius: 50%;
    transition: 0.3s;
}
.toggle-switch input:checked + .toggle-slider {
    background-color: var(--color-primary);
}
.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(22px);
}
</style>
@endpush
